<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Services;

use Haakco\ParallelTestRunner\Data\RunnerConfiguration;
use Haakco\ParallelTestRunner\Data\TestRunOptionsData;
use Haakco\ParallelTestRunner\Data\TestSectionData;
use Haakco\ParallelTestRunner\Services\TestRunnerConfigurationService;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Console\OutputStyle;
use Override;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class TestRunnerConfigurationServiceTest extends TestCase
{
    private TestRunnerConfigurationService $service;

    private RunnerConfiguration $config;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new RunnerConfiguration(
            forceSplitDirectories: [],
            phpunitConfigFiles: ['standard' => 'phpunit.xml'],
            weightMultipliers: [],
            scanPaths: ['tests/Unit', 'tests/Feature'],
            maxFilesPerSection: 10,
            baseWeightPerFile: 10.0,
            defaultTimeoutSeconds: 600,
            defaultParallelProcesses: 1,
            dbConnection: 'pgsql_testing',
            dbBaseName: 'app_test',
            dropStrategy: 'with_force',
            useSchemaLoad: true,
        );

        $this->service = new TestRunnerConfigurationService($this->config);
    }

    public function test_defaults_from_config(): void
    {
        $this->assertSame(600, $this->service->timeoutSeconds);
        $this->assertSame(10, $this->service->maxFilesPerRun);
        $this->assertSame(1, $this->service->parallelProcesses);
        $this->assertFalse($this->service->debug);
        $this->assertFalse($this->service->failFast);
        $this->assertFalse($this->service->individual);
    }

    public function test_set_debug(): void
    {
        $result = $this->service->setDebug(true);

        $this->assertTrue($this->service->debug);
        $this->assertSame($this->service, $result);
    }

    public function test_set_timeout_clamps_minimum(): void
    {
        $this->service->setTimeout(0);

        $this->assertSame(1, $this->service->timeoutSeconds);
    }

    public function test_set_timeout_accepts_valid_value(): void
    {
        $this->service->setTimeout(120);

        $this->assertSame(120, $this->service->timeoutSeconds);
    }

    public function test_set_parallel_processes_clamps_minimum(): void
    {
        $this->service->setParallelProcesses(0);

        $this->assertSame(1, $this->service->parallelProcesses);
    }

    public function test_set_specific_sections_trims_and_filters(): void
    {
        $this->service->setSpecificSections(['  Unit  ', '', '  Feature  ']);

        $this->assertSame(['Unit', 'Feature'], $this->service->specificSections);
    }

    public function test_set_specific_sections_null_clears(): void
    {
        $this->service->setSpecificSections(['Unit']);
        $this->service->setSpecificSections(null);

        $this->assertSame([], $this->service->specificSections);
    }

    public function test_get_process_environment_includes_config(): void
    {
        config()->set('parallel-test-runner.environment', ['APP_ENV' => 'testing']);
        $service = new TestRunnerConfigurationService($this->config);

        $env = $service->getProcessEnvironment();

        $this->assertSame('testing', $env['APP_ENV']);
    }

    public function test_get_process_environment_includes_debug(): void
    {
        config()->set('parallel-test-runner.environment', []);
        $service = new TestRunnerConfigurationService($this->config);
        $service->setDebug(true);

        $env = $service->getProcessEnvironment();

        $this->assertSame('1', $env['DEBUG']);
    }

    public function test_get_process_environment_includes_ignore_lock(): void
    {
        config()->set('parallel-test-runner.environment', []);
        $service = new TestRunnerConfigurationService($this->config);
        $service->setIgnoreLock(true);

        $env = $service->getProcessEnvironment();

        $this->assertSame('1', $env['TEST_IGNORE_MIGRATION_LOCK']);
    }

    public function test_configure_from_options(): void
    {
        $options = new TestRunOptionsData(
            debug: true,
            debugNative: false,
            timeoutSeconds: 120,
            maxFilesPerRun: 5,
            failFast: true,
            individual: true,
            parallelProcesses: 4,
            runAll: false,
            keepParallelDatabases: false,
            preventRefreshDatabase: false,
            skipEnvironmentChecksRequested: false,
            sections: ['Unit'],
            tests: [],
            splitTotal: 3,
            splitGroup: 1,
            filter: null,
            testSuite: null,
            logDirectory: null,
            emitMetrics: true,
        );

        $output = new OutputStyle(new ArrayInput([]), new BufferedOutput());
        $feedback = $this->service->configure($options, $output);

        $this->assertTrue($this->service->debug);
        $this->assertSame(120, $this->service->timeoutSeconds);
        $this->assertSame(5, $this->service->maxFilesPerRun);
        $this->assertTrue($this->service->failFast);
        $this->assertTrue($this->service->individual);
        $this->assertSame(4, $this->service->parallelProcesses);
        $this->assertSame(3, $this->service->splitTotal);
        $this->assertSame(1, $this->service->splitGroup);
        $this->assertSame(['Unit'], $this->service->specificSections);
        $this->assertIsString($feedback->message);
        $this->assertIsArray($feedback->settings);
    }

    public function test_create_section_resolution_context(): void
    {
        config()->set('parallel-test-runner.sections.additional_suites', []);
        $this->service->setSpecificSections(['Unit']);
        $this->service->setSplitTotal(3);
        $this->service->setSplitGroup(1);
        $this->service->setIndividual(true);
        $this->service->setOptions(['filter' => 'testFoo', 'testsuite' => 'unit']);

        $context = $this->service->createSectionResolutionContext();

        $this->assertSame(['tests/Unit', 'tests/Feature'], $context->scanPaths);
        $this->assertSame(['Unit'], $context->sections);
        $this->assertSame(3, $context->splitTotal);
        $this->assertSame(1, $context->splitGroup);
        $this->assertTrue($context->individual);
        $this->assertSame('testFoo', $context->filter);
        $this->assertSame('unit', $context->testSuite);
    }

    public function test_build_phpunit_command_for_file_section(): void
    {
        $section = new TestSectionData(
            name: 'tests/Unit/FooTest.php',
            type: 'file',
            path: base_path('tests/Unit/FooTest.php'),
            files: [],
            fileCount: 1,
        );

        $command = $this->service->buildPhpunitCommand(
            $section->path,
            '/tmp/logs',
            $section->name,
        );

        $parts = $command->all();

        $this->assertSame('php', $parts[0]);
        $this->assertContains('--no-coverage', $parts);
        $this->assertContains('--colors=never', $parts);
        $this->assertContains(base_path('tests/Unit/FooTest.php'), $parts);
    }

    public function test_build_phpunit_command_with_files(): void
    {
        $files = [
            base_path('tests/Unit/FooTest.php'),
            base_path('tests/Unit/BarTest.php'),
        ];

        $command = $this->service->buildPhpunitCommand(
            base_path('tests/Unit'),
            '/tmp/logs',
            'tests/Unit',
            $files,
        );

        $parts = $command->all();

        $this->assertContains(base_path('tests/Unit/FooTest.php'), $parts);
        $this->assertContains(base_path('tests/Unit/BarTest.php'), $parts);
    }

    public function test_build_phpunit_command_with_filter(): void
    {
        $this->service->setOptions(['filter' => 'testSomething']);

        $command = $this->service->buildPhpunitCommand(
            base_path('tests/Unit'),
            '/tmp/logs',
            'tests/Unit',
        );

        $this->assertContains('--filter=testSomething', $command->all());
    }

    public function test_build_phpunit_command_with_fail_fast(): void
    {
        $this->service->setFailFast(true);

        $command = $this->service->buildPhpunitCommand(
            base_path('tests/Unit'),
            '/tmp/logs',
            'tests/Unit',
        );

        $this->assertContains('--stop-on-failure', $command->all());
    }

    public function test_build_wrapped_command_includes_phpunit_parts(): void
    {
        $this->service->setTimeout(60);

        $command = $this->service->buildWrappedCommand(
            base_path('tests/Unit'),
            '/tmp/logs',
            'tests/Unit',
        );

        $parts = $command->all();

        // Should contain phpunit at some point
        $this->assertTrue(
            collect($parts)->contains(static fn(string $part): bool => str_contains($part, 'phpunit')),
        );
    }

    public function test_build_environment_parts(): void
    {
        config()->set('parallel-test-runner.environment', ['APP_ENV' => 'testing']);
        $service = new TestRunnerConfigurationService($this->config);

        $parts = $service->buildEnvironmentParts();

        $this->assertTrue($parts->contains('APP_ENV=testing'));
    }

    public function test_build_environment_prefix(): void
    {
        config()->set('parallel-test-runner.environment', ['APP_ENV' => 'testing']);
        $service = new TestRunnerConfigurationService($this->config);

        $prefix = $service->buildEnvironmentPrefix();

        $this->assertStringContainsString('APP_ENV=testing', $prefix);
    }

    public function test_get_base_config(): void
    {
        $this->assertSame($this->config, $this->service->getBaseConfig());
    }

    public function test_set_native_debug_sets_env_overrides(): void
    {
        $this->service->setNativeDebug(true);

        $this->assertTrue($this->service->nativeDebug);
    }

    public function test_set_native_debug_false_removes_overrides(): void
    {
        $this->service->setNativeDebug(true);
        $this->service->setNativeDebug(false);

        $this->assertFalse($this->service->nativeDebug);
    }

    public function test_setters_are_chainable(): void
    {
        $result = $this->service
            ->setDebug(true)
            ->setTimeout(120)
            ->setMaxFilesPerRun(5)
            ->setFailFast(true)
            ->setParallelProcesses(4)
            ->setIndividual(true)
            ->setSplitTotal(3)
            ->setSplitGroup(1)
            ->setIgnoreLock(true)
            ->setSkipEnvironmentChecks(true)
            ->setOptions([]);

        $this->assertSame($this->service, $result);
    }

    public function test_log_debug_does_nothing_when_not_debug(): void
    {
        $output = new OutputStyle(new ArrayInput([]), new BufferedOutput());
        $this->service->setOutput($output);
        $this->service->setDebug(false);

        // Should not throw
        $this->service->logDebug('test', 'data');
        $this->assertFalse($this->service->debug);
    }

    public function test_log_debug_writes_when_debug(): void
    {
        $bufferedOutput = new BufferedOutput();
        $output = new OutputStyle(new ArrayInput([]), $bufferedOutput);
        $this->service->setOutput($output);
        $this->service->setDebug(true);

        $this->service->logDebug('Context', 'test data');

        $content = $bufferedOutput->fetch();
        $this->assertStringContainsString('[DEBUG] Context:', $content);
        $this->assertStringContainsString('test data', $content);
    }

    public function test_output_throws_when_not_set(): void
    {
        $service = new TestRunnerConfigurationService($this->config);

        $this->expectException(RuntimeException::class);
        $service->output();
    }

    public function test_has_exceeded_global_timeout_returns_false_when_null(): void
    {
        $this->service->globalTimeoutSeconds = null;

        $this->assertFalse($this->service->hasExceededGlobalTimeout());
    }

    public function test_has_exceeded_global_timeout_returns_false_when_not_exceeded(): void
    {
        $this->service->globalTimeoutSeconds = 9999;
        $this->service->commandStartTime = microtime(true);

        $this->assertFalse($this->service->hasExceededGlobalTimeout());
    }
}
