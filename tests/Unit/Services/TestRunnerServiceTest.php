<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Services {
    function is_link(string $filename): bool
    {
        $override = &$GLOBALS['ptr_test_runner_service_overrides'];

        if (is_array($override) && ($override['enabled'] ?? false) && $filename === ($override['path'] ?? null)) {
            $callCount = ($override['is_link_call_count'] ?? 0) + 1;
            $override['is_link_call_count'] = $callCount;

            return $callCount === 1
                ? (bool) ($override['first_is_link_result'] ?? false)
                : (bool) ($override['subsequent_is_link_result'] ?? false);
        }

        return \is_link($filename);
    }

    function unlink(string $filename): bool
    {
        $override = &$GLOBALS['ptr_test_runner_service_overrides'];

        if (is_array($override) && ($override['enabled'] ?? false) && $filename === ($override['path'] ?? null)) {
            $override['unlink_call_count'] = ($override['unlink_call_count'] ?? 0) + 1;

            return (bool) ($override['unlink_result'] ?? false);
        }

        return \unlink($filename);
    }
}

namespace Haakco\ParallelTestRunner\Tests\Unit\Services {

    use Haakco\ParallelTestRunner\Data\Results\BackgroundRunStatusData;
    use Haakco\ParallelTestRunner\Data\Results\DatabaseRefreshResultData;
    use Haakco\ParallelTestRunner\Data\Results\TestRunnerConfigurationFeedbackData;
    use Haakco\ParallelTestRunner\Data\Results\TestRunResultData;
    use Haakco\ParallelTestRunner\Data\TestRunOptionsData;
    use Haakco\ParallelTestRunner\Services\HangingTestDetectorService;
    use Haakco\ParallelTestRunner\Services\TestDatabaseManagerService;
    use Haakco\ParallelTestRunner\Services\TestExecutionOrchestratorService;
    use Haakco\ParallelTestRunner\Services\TestRunnerConfigurationService;
    use Haakco\ParallelTestRunner\Services\TestRunnerService;
    use Haakco\ParallelTestRunner\Tests\TestCase;
    use Illuminate\Console\OutputStyle;
    use Illuminate\Support\Collection;
    use Symfony\Component\Console\Input\ArrayInput;
    use Symfony\Component\Console\Output\NullOutput;

    final class TestRunnerServiceTest extends TestCase
    {
        protected function tearDown(): void
        {
            unset($GLOBALS['ptr_test_runner_service_overrides']);

            parent::tearDown();
        }

        public function test_constructor_accepts_dependencies(): void
        {
            $service = $this->createService();

            $this->assertInstanceOf(TestRunnerService::class, $service);
        }

        public function test_get_log_directory(): void
        {
            $service = $this->createService();

            $logDir = $service->getLogDirectory();

            $this->assertNotEmpty($logDir);
            $this->assertStringContainsString('test-logs', $logDir);
        }

        public function test_latest_symlink_uses_relative_target(): void
        {
            $this->createService();

            $latest = base_path('test-logs/latest');
            if (! is_link($latest)) {
                $this->markTestSkipped('latest is not a symlink in this environment');
            }

            $target = readlink($latest);
            $this->assertIsString($target);
            $this->assertNotSame('', $target);
            $this->assertFalse(str_starts_with($target, '/'));
        }

        public function test_create_log_directory_ignores_missing_symlink_race(): void
        {
            $latest = base_path('test-logs/latest');

            $GLOBALS['ptr_test_runner_service_overrides'] = [
                'enabled' => true,
                'path' => $latest,
                'first_is_link_result' => true,
                'subsequent_is_link_result' => false,
                'unlink_result' => false,
            ];

            $service = $this->createService();

            unset($GLOBALS['ptr_test_runner_service_overrides']);

            $this->assertNotEmpty($service->getLogDirectory());
            $this->assertTrue(is_link($latest));
        }

        public function test_set_log_directory(): void
        {
            $service = $this->createService();
            $newDir = sys_get_temp_dir() . '/ptr-test-logdir-' . uniqid();

            $result = $service->setLogDirectory($newDir);

            $this->assertSame($service, $result);
            $this->assertSame($newDir, $service->getLogDirectory());

            if (is_dir($newDir)) {
                rmdir($newDir);
            }
        }

        public function test_configure_delegates_to_config_service(): void
        {
            $configService = $this->createMock(TestRunnerConfigurationService::class);
            $configService->expects($this->once())
                ->method('configure')
                ->willReturn(new TestRunnerConfigurationFeedbackData(
                    message: 'OK',
                    settings: [],
                ));

            $service = new TestRunnerService(
                $configService,
                $this->createStub(TestExecutionOrchestratorService::class),
                new TestDatabaseManagerService(),
                new HangingTestDetectorService(),
            );

            $options = $this->createTestRunOptions();
            $output = new OutputStyle(new ArrayInput([]), new NullOutput());

            $service->configure($options, $output);
        }

        public function test_run_configured_delegates(): void
        {
            $executionService = $this->createMock(TestExecutionOrchestratorService::class);
            $executionService->expects($this->once())
                ->method('runConfigured')
                ->willReturn(TestRunResultData::success('All passed', 1.5));

            $service = new TestRunnerService(
                $this->createStub(TestRunnerConfigurationService::class),
                $executionService,
                new TestDatabaseManagerService(),
                new HangingTestDetectorService(),
            );

            $result = $service->runConfigured();

            $this->assertTrue($result->success);
        }

        public function test_list_sections_delegates(): void
        {
            $executionService = $this->createMock(TestExecutionOrchestratorService::class);
            $executionService->expects($this->once())
                ->method('listSections')
                ->willReturn(collect());

            $service = new TestRunnerService(
                $this->createStub(TestRunnerConfigurationService::class),
                $executionService,
                new TestDatabaseManagerService(),
                new HangingTestDetectorService(),
            );

            $result = $service->listSections();

            $this->assertInstanceOf(Collection::class, $result);
            $this->assertCount(0, $result);
        }

        public function test_check_background_status_delegates(): void
        {
            $executionService = $this->createMock(TestExecutionOrchestratorService::class);
            $executionService->expects($this->once())
                ->method('checkBackgroundStatus')
                ->willReturn(BackgroundRunStatusData::notRunning());

            $service = new TestRunnerService(
                $this->createStub(TestRunnerConfigurationService::class),
                $executionService,
                new TestDatabaseManagerService(),
                new HangingTestDetectorService(),
            );

            $result = $service->checkBackgroundStatus();

            $this->assertFalse($result->running);
        }

        public function test_refresh_test_database_delegates(): void
        {
            $dbService = $this->createMock(TestDatabaseManagerService::class);
            $dbService->expects($this->once())
                ->method('refreshTestDatabase')
                ->with('testing', ':memory:', null)
                ->willReturn(DatabaseRefreshResultData::success(0.5));

            $service = new TestRunnerService(
                $this->createStub(TestRunnerConfigurationService::class),
                $this->createStub(TestExecutionOrchestratorService::class),
                $dbService,
                new HangingTestDetectorService(),
            );

            $result = $service->refreshTestDatabase('testing', ':memory:');

            $this->assertTrue($result->success);
        }

        public function test_get_config_service(): void
        {
            $configService = $this->createStub(TestRunnerConfigurationService::class);
            $service = new TestRunnerService(
                $configService,
                $this->createStub(TestExecutionOrchestratorService::class),
                new TestDatabaseManagerService(),
                new HangingTestDetectorService(),
            );

            $this->assertSame($configService, $service->getConfigService());
        }

        public function test_configure_updates_log_directory_from_options(): void
        {
            $newLogDir = sys_get_temp_dir() . '/ptr-configure-logdir-' . uniqid();
            $configService = $this->createStub(TestRunnerConfigurationService::class);
            $configService->method('configure')
                ->willReturn(new TestRunnerConfigurationFeedbackData(
                    message: 'OK',
                    settings: [],
                ));

            $service = new TestRunnerService(
                $configService,
                $this->createStub(TestExecutionOrchestratorService::class),
                new TestDatabaseManagerService(),
                new HangingTestDetectorService(),
            );

            $options = $this->createTestRunOptions(logDirectory: $newLogDir);
            $output = new OutputStyle(new ArrayInput([]), new NullOutput());

            $service->configure($options, $output);

            // After configure with new log directory, getLogDirectory should return the new one
            $this->assertSame($newLogDir, $service->getLogDirectory());

            if (is_dir($newLogDir)) {
                rmdir($newLogDir);
            }
        }

        private function createService(): TestRunnerService
        {
            $configService = $this->createStub(TestRunnerConfigurationService::class);
            $executionService = $this->createStub(TestExecutionOrchestratorService::class);

            return new TestRunnerService(
                $configService,
                $executionService,
                new TestDatabaseManagerService(),
                new HangingTestDetectorService(),
            );
        }

        private function createTestRunOptions(?string $logDirectory = null): TestRunOptionsData
        {
            return new TestRunOptionsData(
                debug: false,
                debugNative: false,
                timeoutSeconds: 60,
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
        }
    }
}
