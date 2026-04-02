<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Services {
    function shell_exec(string $command): string|false|null
    {
        $override = &$GLOBALS['ptr_test_execution_orchestrator_overrides'];

        if (is_array($override) && ($override['enabled'] ?? false)) {
            $override['shell_exec_commands'][] = $command;

            if (isset($override['before_shell_exec']) && is_callable($override['before_shell_exec'])) {
                $override['before_shell_exec']($command);
            }

            return $override['shell_exec_result'] ?? '12345';
        }

        return \shell_exec($command);
    }
}

namespace Haakco\ParallelTestRunner\Tests\Unit\Services {

    use Haakco\ParallelTestRunner\Contracts\DatabaseProvisionerInterface;
    use Haakco\ParallelTestRunner\Contracts\SectionResolverInterface;
    use Haakco\ParallelTestRunner\Contracts\TestRunReportWriterInterface;
    use Haakco\ParallelTestRunner\Data\RunnerConfiguration;
    use Haakco\ParallelTestRunner\Data\TestSectionData;
    use Haakco\ParallelTestRunner\Scheduling\ParallelSectionScheduler;
    use Haakco\ParallelTestRunner\Sections\SectionResolutionWorkflow;
    use Haakco\ParallelTestRunner\Services\HookDispatcher;
    use Haakco\ParallelTestRunner\Services\ParallelTestCoordinatorService;
    use Haakco\ParallelTestRunner\Services\TestExecutionOrchestratorService;
    use Haakco\ParallelTestRunner\Services\TestExecutionTracker;
    use Haakco\ParallelTestRunner\Services\TestOutputParserService;
    use Haakco\ParallelTestRunner\Services\TestRunnerConfigurationService;
    use Haakco\ParallelTestRunner\Services\TestRunnerState;
    use Haakco\ParallelTestRunner\Tests\TestCase;
    use Illuminate\Console\OutputStyle;
    use Illuminate\Support\Facades\File;
    use Illuminate\Support\Facades\Process;
    use Override;
    use RecursiveDirectoryIterator;
    use RecursiveIteratorIterator;
    use Symfony\Component\Console\Input\ArrayInput;
    use Symfony\Component\Console\Output\NullOutput;

    final class TestExecutionOrchestratorServiceTest extends TestCase
    {
        private string $logDir;

        #[Override]
        protected function setUp(): void
        {
            parent::setUp();

            $this->logDir = sys_get_temp_dir() . '/ptr-exec-test-' . uniqid();
            mkdir($this->logDir, 0755, true);
        }

        #[Override]
        protected function tearDown(): void
        {
            unset($GLOBALS['ptr_test_execution_orchestrator_overrides']);
            $this->cleanupDir($this->logDir);
            parent::tearDown();
        }

        public function test_constructor_accepts_dependencies(): void
        {
            $service = $this->createService();

            $this->assertInstanceOf(TestExecutionOrchestratorService::class, $service);
        }

        public function test_list_sections_returns_collection(): void
        {
            $sections = [
                new TestSectionData('Unit/Foo', 'directory', 'tests/Unit/Foo', ['FooTest.php'], 1),
                new TestSectionData('Unit/Bar', 'directory', 'tests/Unit/Bar', ['BarTest.php'], 1),
            ];

            $service = $this->createService(sections: $sections);

            $result = $service->listSections();

            $this->assertCount(2, $result);
            $this->assertSame('Unit/Foo', $result->first()->name);
        }

        public function test_list_sections_with_groups_returns_section_list_result(): void
        {
            $sections = [
                new TestSectionData('Unit/Foo', 'directory', 'tests/Unit/Foo', ['FooTest.php'], 1),
                new TestSectionData('Unit/Bar', 'directory', 'tests/Unit/Bar', ['BarTest.php'], 1),
            ];

            $service = $this->createService(sections: $sections);

            $result = $service->listSectionsWithGroups();

            $this->assertSame(2, $result->totalSections);
            $this->assertSame(2, $result->totalFiles);
            $this->assertCount(2, $result->sections);
        }

        public function test_list_sections_with_groups_and_split(): void
        {
            $sections = [
                new TestSectionData('Unit/Foo', 'directory', 'tests/Unit/Foo', ['FooTest.php'], 1),
                new TestSectionData('Unit/Bar', 'directory', 'tests/Unit/Bar', ['BarTest.php'], 1),
                new TestSectionData('Unit/Baz', 'directory', 'tests/Unit/Baz', ['BazTest.php'], 1),
            ];

            $service = $this->createService(sections: $sections);

            $result = $service->listSectionsWithGroups(splitTotal: 2, splitGroup: 1);

            $this->assertLessThanOrEqual(3, $result->totalSections);
            $this->assertGreaterThan(0, $result->totalSections);
        }

        public function test_list_sections_empty_returns_empty_result(): void
        {
            $service = $this->createService(sections: []);

            $result = $service->listSectionsWithGroups();

            $this->assertSame(0, $result->totalSections);
            $this->assertSame(0, $result->totalFiles);
            $this->assertSame([], $result->sections);
        }

        public function test_check_background_status_returns_not_running(): void
        {
            config()->set('parallel-test-runner.background.pid_file', sys_get_temp_dir() . '/nonexistent-' . uniqid() . '.pid');

            $service = $this->createService();

            $status = $service->checkBackgroundStatus();

            $this->assertFalse($status->running);
            $this->assertNull($status->pid);
        }

        public function test_check_background_status_cleans_stale_pid(): void
        {
            $pidFile = sys_get_temp_dir() . '/ptr-test-stale-' . uniqid() . '.pid';
            file_put_contents($pidFile, '999999999');

            config()->set('parallel-test-runner.background.pid_file', $pidFile);

            $service = $this->createService();
            $status = $service->checkBackgroundStatus();

            $this->assertFalse($status->running);
            $this->assertFileDoesNotExist($pidFile);
        }

        public function test_run_configured_returns_result_for_no_sections(): void
        {
            $service = $this->createService(sections: []);

            $result = $service->runConfigured($this->logDir);

            $this->assertFalse($result->success);
        }

        public function test_run_configured_passes_command_args_to_report_context(): void
        {
            $reportWriter = $this->createMock(TestRunReportWriterInterface::class);
            $reportWriter->expects($this->once())
                ->method('write')
                ->with($this->callback(static fn($context): bool => $context instanceof \Haakco\ParallelTestRunner\Data\ReportContext
                    && ($context->extraOptions['command_args'] ?? null) === ['--parallel=3', '--fail-fast', '--filter=Name with spaces']));

            $service = $this->createService(
                sections: [],
                reportWriter: $reportWriter,
                configureConfigService: static function (TestRunnerConfigurationService $configService): void {
                    $configService->setParallelProcesses(3);
                    $configService->setFailFast(true);
                    $configService->setOptions(['filter' => 'Name with spaces']);
                },
            );

            $service->runConfigured($this->logDir);
        }

        public function test_run_uses_process_environment_array_for_worker_execution(): void
        {
            config()->set('parallel-test-runner.environment', [
                'APP_NAME' => 'My App',
                'SPECIAL' => 'value$with!chars',
            ]);

            Process::fake();

            $service = $this->createService(sections: [
                new TestSectionData('Unit/Foo', 'directory', base_path('tests/Unit/FooTest.php'), [base_path('tests/Unit/FooTest.php')], 1),
            ]);

            $service->runConfigured($this->logDir);

            Process::assertRan(static function ($process): bool {
                $environment = $process->environment;

                return ($environment['APP_NAME'] ?? null) === 'My App'
                    && ($environment['SPECIAL'] ?? null) === 'value$with!chars';
            });
        }

        public function test_start_background_run_shell_command_escapes_option_values_and_log_directory(): void
        {
            $pidFile = sys_get_temp_dir() . '/ptr-bg-pid-' . uniqid() . '.pid';
            $lockFile = sys_get_temp_dir() . '/ptr-bg-lock-' . uniqid() . '.lock';
            $logDirectory = sys_get_temp_dir() . '/ptr logs/' . uniqid();

            config()->set('parallel-test-runner.background.pid_file', $pidFile);
            config()->set('parallel-test-runner.background.lock_file', $lockFile);

            $GLOBALS['ptr_test_execution_orchestrator_overrides'] = [
                'enabled' => true,
                'shell_exec_result' => '43210',
                'shell_exec_commands' => [],
            ];

            $service = $this->createService();
            $options = new \Haakco\ParallelTestRunner\Data\TestRunOptionsData(
                debug: false,
                debugNative: false,
                timeoutSeconds: 600,
                maxFilesPerRun: 10,
                failFast: false,
                individual: false,
                ignoreLock: false,
                parallelProcesses: 1,
                runAll: false,
                keepParallelDatabases: false,
                preventRefreshDatabase: false,
                skipEnvironmentChecksRequested: false,
                sections: [],
                tests: [],
                splitTotal: null,
                splitGroup: null,
                filter: null,
                testSuite: null,
                logDirectory: $logDirectory,
                emitMetrics: true,
            );

            $result = $service->startBackgroundRun($options, [
                'background' => true,
                'filter' => 'Name with spaces$and!chars',
                'parallel' => 2,
                'section' => 'Unit/Models',
            ]);

            $shellCommand = $GLOBALS['ptr_test_execution_orchestrator_overrides']['shell_exec_commands'][0] ?? null;

            $this->assertTrue($result->started);
            $this->assertSame(43210, $result->pid);
            $this->assertIsString($shellCommand);
            $this->assertStringContainsString("nohup env APP_ENV='testing' php artisan test:run-sections", $shellCommand);
            $this->assertStringContainsString("--filter='Name with spaces\$and!chars'", $shellCommand);
            $this->assertStringContainsString('--parallel=\'2\'', $shellCommand);
            $this->assertStringContainsString("--section='Unit/Models'", $shellCommand);
            $this->assertStringContainsString('--log-dir=', $shellCommand);
            $this->assertStringContainsString(escapeshellarg($logDirectory), $shellCommand);
            $this->assertFileExists($pidFile);
            $this->assertFileExists($lockFile);
            $this->assertSame($logDirectory, file_get_contents($lockFile));

            @unlink($pidFile);
            @unlink($lockFile);
            File::deleteDirectory($logDirectory);
        }

        public function test_start_background_run_includes_configured_environment_and_runtime_flags_in_shell_command(): void
        {
            $pidFile = sys_get_temp_dir() . '/ptr-bg-pid-' . uniqid() . '.pid';
            $lockFile = sys_get_temp_dir() . '/ptr-bg-lock-' . uniqid() . '.lock';
            $logDirectory = sys_get_temp_dir() . '/ptr-bg-log-' . uniqid();

            config()->set('parallel-test-runner.environment', [
                'APP_NAME' => 'My App',
                'SPECIAL' => 'value$with!chars',
            ]);
            config()->set('parallel-test-runner.background.pid_file', $pidFile);
            config()->set('parallel-test-runner.background.lock_file', $lockFile);

            $GLOBALS['ptr_test_execution_orchestrator_overrides'] = [
                'enabled' => true,
                'shell_exec_result' => '98765',
                'shell_exec_commands' => [],
            ];

            $service = $this->createService();
            $options = new \Haakco\ParallelTestRunner\Data\TestRunOptionsData(
                debug: true,
                debugNative: true,
                timeoutSeconds: 600,
                maxFilesPerRun: 10,
                failFast: false,
                individual: false,
                ignoreLock: true,
                parallelProcesses: 1,
                runAll: false,
                keepParallelDatabases: false,
                preventRefreshDatabase: false,
                skipEnvironmentChecksRequested: false,
                sections: [],
                tests: [],
                splitTotal: null,
                splitGroup: null,
                filter: null,
                testSuite: null,
                logDirectory: $logDirectory,
                emitMetrics: true,
            );

            try {
                $result = $service->startBackgroundRun($options, [
                    'background' => true,
                    'debug' => true,
                    'debug-native' => true,
                    'ignore-lock' => true,
                ]);

                $shellCommand = $GLOBALS['ptr_test_execution_orchestrator_overrides']['shell_exec_commands'][0] ?? null;

                $this->assertTrue($result->started);
                $this->assertSame(98765, $result->pid);
                $this->assertIsString($shellCommand);
                $this->assertStringContainsString("APP_NAME='My App'", $shellCommand);
                $this->assertStringContainsString("SPECIAL='value\$with!chars'", $shellCommand);
                $this->assertStringContainsString("DEBUG='1'", $shellCommand);
                $this->assertStringContainsString("NATIVE_DEBUG='1'", $shellCommand);
                $this->assertStringContainsString("USE_ZEND_ALLOC='0'", $shellCommand);
                $this->assertStringContainsString("TEST_IGNORE_MIGRATION_LOCK='1'", $shellCommand);
                $this->assertStringContainsString("APP_ENV='testing'", $shellCommand);
            } finally {
                @unlink($pidFile);
                @unlink($lockFile);
                File::deleteDirectory($logDirectory);
            }
        }

        public function test_start_background_run_fails_when_shell_exec_returns_false_and_does_not_write_state(): void
        {
            $pidFile = sys_get_temp_dir() . '/ptr-bg-pid-' . uniqid() . '.pid';
            $lockFile = sys_get_temp_dir() . '/ptr-bg-lock-' . uniqid() . '.lock';
            $logDirectory = sys_get_temp_dir() . '/ptr-bg-log-' . uniqid();

            config()->set('parallel-test-runner.background.pid_file', $pidFile);
            config()->set('parallel-test-runner.background.lock_file', $lockFile);

            $GLOBALS['ptr_test_execution_orchestrator_overrides'] = [
                'enabled' => true,
                'shell_exec_result' => false,
                'shell_exec_commands' => [],
            ];

            $service = $this->createService();
            $options = new \Haakco\ParallelTestRunner\Data\TestRunOptionsData(
                debug: false,
                debugNative: false,
                timeoutSeconds: 600,
                maxFilesPerRun: 10,
                failFast: false,
                individual: false,
                ignoreLock: false,
                parallelProcesses: 1,
                runAll: false,
                keepParallelDatabases: false,
                preventRefreshDatabase: false,
                skipEnvironmentChecksRequested: false,
                sections: [],
                tests: [],
                splitTotal: null,
                splitGroup: null,
                filter: null,
                testSuite: null,
                logDirectory: null,
                emitMetrics: true,
            );

            try {
                $result = $service->startBackgroundRun($options, ['background' => true]);

                $this->assertFalse($result->started);
                $this->assertSame('Unable to start background test run', $result->message);
                $this->assertFileDoesNotExist($pidFile);
                $this->assertFileDoesNotExist($lockFile);
            } finally {
                File::deleteDirectory($logDirectory);
            }
        }

        public function test_start_background_run_fails_when_shell_exec_returns_invalid_pid_and_does_not_write_state(): void
        {
            $pidFile = sys_get_temp_dir() . '/ptr-bg-pid-' . uniqid() . '.pid';
            $lockFile = sys_get_temp_dir() . '/ptr-bg-lock-' . uniqid() . '.lock';
            $logDirectory = sys_get_temp_dir() . '/ptr-bg-log-' . uniqid();

            config()->set('parallel-test-runner.background.pid_file', $pidFile);
            config()->set('parallel-test-runner.background.lock_file', $lockFile);

            $GLOBALS['ptr_test_execution_orchestrator_overrides'] = [
                'enabled' => true,
                'shell_exec_result' => 'not-a-pid',
                'shell_exec_commands' => [],
            ];

            $service = $this->createService();
            $options = new \Haakco\ParallelTestRunner\Data\TestRunOptionsData(
                debug: false,
                debugNative: false,
                timeoutSeconds: 600,
                maxFilesPerRun: 10,
                failFast: false,
                individual: false,
                ignoreLock: false,
                parallelProcesses: 1,
                runAll: false,
                keepParallelDatabases: false,
                preventRefreshDatabase: false,
                skipEnvironmentChecksRequested: false,
                sections: [],
                tests: [],
                splitTotal: null,
                splitGroup: null,
                filter: null,
                testSuite: null,
                logDirectory: $logDirectory,
                emitMetrics: true,
            );

            try {
                $result = $service->startBackgroundRun($options, ['background' => true]);

                $this->assertFalse($result->started);
                $this->assertSame('Unable to start background test run', $result->message);
                $this->assertFileDoesNotExist($pidFile);
                $this->assertFileDoesNotExist($lockFile);
            } finally {
                File::deleteDirectory($logDirectory);
            }
        }

        public function test_start_background_run_removes_created_log_directory_even_if_runner_log_exists_on_failure(): void
        {
            $pidFile = sys_get_temp_dir() . '/ptr-bg-pid-' . uniqid() . '.pid';
            $lockFile = sys_get_temp_dir() . '/ptr-bg-lock-' . uniqid() . '.lock';
            $logDirectory = sys_get_temp_dir() . '/ptr-bg-log-' . uniqid();

            config()->set('parallel-test-runner.background.pid_file', $pidFile);
            config()->set('parallel-test-runner.background.lock_file', $lockFile);

            $GLOBALS['ptr_test_execution_orchestrator_overrides'] = [
                'enabled' => true,
                'shell_exec_result' => false,
                'shell_exec_commands' => [],
                'before_shell_exec' => static function () use ($logDirectory): void {
                    file_put_contents($logDirectory . '/runner.log', 'launch failed');
                },
            ];

            $service = $this->createService();
            $options = new \Haakco\ParallelTestRunner\Data\TestRunOptionsData(
                debug: false,
                debugNative: false,
                timeoutSeconds: 600,
                maxFilesPerRun: 10,
                failFast: false,
                individual: false,
                ignoreLock: false,
                parallelProcesses: 1,
                runAll: false,
                keepParallelDatabases: false,
                preventRefreshDatabase: false,
                skipEnvironmentChecksRequested: false,
                sections: [],
                tests: [],
                splitTotal: null,
                splitGroup: null,
                filter: null,
                testSuite: null,
                logDirectory: $logDirectory,
                emitMetrics: true,
            );

            $result = $service->startBackgroundRun($options, ['background' => true]);

            $this->assertFalse($result->started);
            $this->assertFileDoesNotExist($pidFile);
            $this->assertFileDoesNotExist($lockFile);
            $this->assertDirectoryDoesNotExist($logDirectory);
        }

        /**
         * @param list<TestSectionData> $sections
         */
        private function createService(
            array $sections = [],
            ?TestRunReportWriterInterface $reportWriter = null,
            ?callable $configureConfigService = null,
        ): TestExecutionOrchestratorService {
            $config = new RunnerConfiguration(
                forceSplitDirectories: [],
                phpunitConfigFiles: ['standard' => 'phpunit.xml'],
                weightMultipliers: [],
                scanPaths: ['tests/Unit'],
                maxFilesPerSection: 10,
                baseWeightPerFile: 10.0,
                defaultTimeoutSeconds: 60,
                defaultParallelProcesses: 1,
                dbConnection: 'pgsql_testing',
                dbBaseName: 'app_test',
                dropStrategy: 'with_force',
                useSchemaLoad: true,
            );

            $configService = new TestRunnerConfigurationService($config);
            $output = new OutputStyle(new ArrayInput([]), new NullOutput());
            $configService->setOutput($output);

            if ($configureConfigService !== null) {
                $configureConfigService($configService);
            }

            $state = new TestRunnerState();
            $tracker = new TestExecutionTracker($this->logDir);
            $outputParser = new TestOutputParserService();

            $reportWriter ??= $this->createStub(TestRunReportWriterInterface::class);

            // Build real coordinator with stubbed provisioner
            $provisioner = $this->createStub(DatabaseProvisionerInterface::class);
            $provisioner->method('provision')->willReturn([]);
            $provisioner->method('cleanup')->willReturnCallback(static fn(): null => null);

            $scheduler = $this->createStub(ParallelSectionScheduler::class);
            $scheduler->method('createWorkerPlans')->willReturn([]);

            $parallelCoordinator = new ParallelTestCoordinatorService(
                $configService,
                $state,
                $tracker,
                $provisioner,
                $scheduler,
                $this->app->make(HookDispatcher::class),
            );

            // Create SectionResolver stub
            $resolver = $this->createStub(SectionResolverInterface::class);
            $resolver->method('resolve')->willReturn($sections);

            $sectionWorkflow = new SectionResolutionWorkflow($resolver);

            $hookDispatcher = $this->app->make(HookDispatcher::class);

            return new TestExecutionOrchestratorService(
                $configService,
                $state,
                $tracker,
                $outputParser,
                $reportWriter,
                $parallelCoordinator,
                $sectionWorkflow,
                $hookDispatcher,
            );
        }

        private function cleanupDir(string $dir): void
        {
            if (is_dir($dir)) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST,
                );

                foreach ($files as $file) {
                    $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
                }

                rmdir($dir);
            }
        }
    }
}
