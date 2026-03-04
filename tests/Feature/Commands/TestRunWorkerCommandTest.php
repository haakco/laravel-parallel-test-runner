<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Feature\Commands;

use Haakco\ParallelTestRunner\Services\TestRunnerConfigurationService;
use Haakco\ParallelTestRunner\Services\TestRunnerService;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;
use Override;

final class TestRunWorkerCommandTest extends TestCase
{
    private MockInterface&TestRunnerService $testRunner;

    private MockInterface&TestRunnerConfigurationService $configService;

    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.pgsql_testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->configService = Mockery::mock(TestRunnerConfigurationService::class);
        $this->configService->shouldReceive('setOutput')->andReturnSelf()->byDefault();
        $this->configService->shouldReceive('setDebug')->andReturnSelf()->byDefault();
        $this->configService->shouldReceive('setTimeout')->andReturnSelf()->byDefault();
        $this->configService->shouldReceive('setFailFast')->andReturnSelf()->byDefault();
        $this->configService->shouldReceive('setSkipEnvironmentChecks')->andReturnSelf()->byDefault();
        $this->configService->shouldReceive('setIndividual')->andReturnSelf()->byDefault();
        $this->configService->shouldReceive('setSpecificSections')->andReturnSelf()->byDefault();

        $this->testRunner = Mockery::mock(TestRunnerService::class);
        $this->testRunner->shouldReceive('getConfigService')->andReturn($this->configService)->byDefault();
        $this->testRunner->shouldReceive('setLogDirectory')->andReturnSelf()->byDefault();

        $this->app->instance(TestRunnerService::class, $this->testRunner);
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('list')
            ->expectsOutputToContain('test:run-worker');
    }

    public function test_missing_plan_file_returns_failure(): void
    {
        $this->artisan('test:run-worker')
            ->expectsOutputToContain('Worker plan file not found')
            ->assertExitCode(1);
    }

    public function test_nonexistent_plan_file_returns_failure(): void
    {
        $this->artisan('test:run-worker', ['--worker-plan-file' => '/tmp/does-not-exist.json'])
            ->expectsOutputToContain('Worker plan file not found')
            ->assertExitCode(1);
    }

    public function test_invalid_plan_file_returns_failure(): void
    {
        $planFile = tempnam(sys_get_temp_dir(), 'worker_plan_');
        file_put_contents($planFile, json_encode(['invalid' => 'data']));

        try {
            $this->artisan('test:run-worker', ['--worker-plan-file' => $planFile])
                ->expectsOutputToContain('Invalid worker plan file')
                ->assertExitCode(1);
        } finally {
            unlink($planFile);
        }
    }

    public function test_reads_worker_plan_from_file(): void
    {
        $planFile = $this->createValidPlanFile();

        $this->configService->shouldReceive('setSpecificSections')
            ->with(['Unit/Models'])
            ->once()
            ->andReturnSelf();

        $this->testRunner->shouldReceive('run')
            ->once()
            ->andReturn(true);

        try {
            $this->artisan('test:run-worker', ['--worker-plan-file' => $planFile])
                ->assertSuccessful();
        } finally {
            unlink($planFile);
        }
    }

    public function test_failed_run_returns_failure_exit_code(): void
    {
        $planFile = $this->createValidPlanFile();

        $this->testRunner->shouldReceive('run')
            ->once()
            ->andReturn(false);

        try {
            $this->artisan('test:run-worker', ['--worker-plan-file' => $planFile])
                ->assertExitCode(1);
        } finally {
            unlink($planFile);
        }
    }

    public function test_configures_debug_and_timeout_from_options(): void
    {
        $planFile = $this->createValidPlanFile();

        $this->configService->shouldReceive('setDebug')
            ->with(true)
            ->once()
            ->andReturnSelf();

        $this->configService->shouldReceive('setTimeout')
            ->with(120)
            ->once()
            ->andReturnSelf();

        $this->configService->shouldReceive('setFailFast')
            ->with(true)
            ->once()
            ->andReturnSelf();

        $this->testRunner->shouldReceive('run')
            ->once()
            ->andReturn(true);

        try {
            $this->artisan('test:run-worker', [
                '--worker-plan-file' => $planFile,
                '--debug' => true,
                '--timeout' => 120,
                '--fail-fast' => true,
            ])->assertSuccessful();
        } finally {
            unlink($planFile);
        }
    }

    public function test_sets_log_directory_from_option(): void
    {
        $planFile = $this->createValidPlanFile();
        $logDir = sys_get_temp_dir() . '/test-worker-logs-' . uniqid();

        $this->testRunner->shouldReceive('setLogDirectory')
            ->with($logDir)
            ->once()
            ->andReturnSelf();

        $this->testRunner->shouldReceive('run')
            ->once()
            ->andReturn(true);

        try {
            $this->artisan('test:run-worker', [
                '--worker-plan-file' => $planFile,
                '--log-dir' => $logDir,
            ])->assertSuccessful();
        } finally {
            unlink($planFile);
        }
    }

    public function test_configures_database_connection(): void
    {
        $planFile = $this->createValidPlanFile('worker_test_db');

        $this->testRunner->shouldReceive('run')
            ->once()
            ->andReturn(true);

        try {
            $this->artisan('test:run-worker', ['--worker-plan-file' => $planFile])
                ->assertSuccessful();

            $dbConnection = config('parallel-test-runner.database.connection', 'pgsql_testing');
            $this->assertSame('worker_test_db', config("database.connections.{$dbConnection}.database"));
        } finally {
            unlink($planFile);
        }
    }

    private function createValidPlanFile(string $database = 'test_db_w1'): string
    {
        $planFile = tempnam(sys_get_temp_dir(), 'worker_plan_');
        file_put_contents($planFile, json_encode([
            'sections' => [
                [
                    'name' => 'Unit/Models',
                    'type' => 'directory',
                    'path' => '/app/tests/Unit/Models',
                    'files' => ['UserTest.php'],
                    'fileCount' => 1,
                ],
            ],
            'database' => $database,
            'suite' => 'Unit',
            'workerId' => 1,
            'estimatedWeight' => 10.0,
            'logDirectory' => '/tmp/worker-1',
            'individual' => false,
        ]));

        return $planFile;
    }
}
