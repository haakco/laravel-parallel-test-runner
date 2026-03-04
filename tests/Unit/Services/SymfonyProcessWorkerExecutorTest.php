<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Services;

use Haakco\ParallelTestRunner\Contracts\WorkerExecutorInterface;
use Haakco\ParallelTestRunner\Data\Parallel\SectionAssignmentData;
use Haakco\ParallelTestRunner\Data\Parallel\WorkerPlanData;
use Haakco\ParallelTestRunner\Services\SymfonyProcessWorkerExecutor;
use Haakco\ParallelTestRunner\Tests\TestCase;

final class SymfonyProcessWorkerExecutorTest extends TestCase
{
    public function test_implements_interface(): void
    {
        $executor = new SymfonyProcessWorkerExecutor();

        $this->assertInstanceOf(WorkerExecutorInterface::class, $executor);
    }

    public function test_builds_correct_artisan_command(): void
    {
        $executor = new SymfonyProcessWorkerExecutor();
        $plan = $this->makePlan();

        $command = $executor->buildCommand($plan);

        $this->assertSame('php', $command[0]);
        $this->assertSame('artisan', $command[1]);
        $this->assertSame('test:run-worker', $command[2]);
        $this->assertContains('--worker-plan-file=/tmp/worker01/worker_plan.json', $command);
        $this->assertContains('--log-dir=/tmp/worker01', $command);
        $this->assertContains('--skip-env-checks', $command);
    }

    public function test_includes_configured_environment_vars(): void
    {
        $executor = new SymfonyProcessWorkerExecutor();
        $plan = $this->makePlan();

        $env = $executor->buildEnvironment($plan);

        $this->assertArrayHasKey('DB_CONNECTION', $env);
        $this->assertArrayHasKey('DB_DATABASE', $env);
        $this->assertSame('test_db_w1', $env['DB_DATABASE']);
    }

    public function test_includes_worker_specific_env_vars(): void
    {
        $executor = new SymfonyProcessWorkerExecutor();
        $plan = $this->makePlan();

        $env = $executor->buildEnvironment($plan);

        $this->assertArrayHasKey('TEST_WORKER_ID', $env);
        $this->assertSame('1', $env['TEST_WORKER_ID']);
        $this->assertArrayHasKey('WORKER_SECTIONS', $env);
    }

    public function test_includes_test_log_dir_by_default(): void
    {
        config()->set('parallel-test-runner.worker_environment.set_test_log_dir', true);

        $executor = new SymfonyProcessWorkerExecutor();
        $plan = $this->makePlan();

        $env = $executor->buildEnvironment($plan);

        $this->assertArrayHasKey('TEST_LOG_DIR', $env);
        $this->assertSame('/tmp/worker01', $env['TEST_LOG_DIR']);
    }

    public function test_optional_db_database_test_env_var(): void
    {
        config()->set('parallel-test-runner.worker_environment.set_db_database_test', true);

        $executor = new SymfonyProcessWorkerExecutor();
        $plan = $this->makePlan();

        $env = $executor->buildEnvironment($plan);

        $this->assertArrayHasKey('DB_DATABASE_TEST', $env);
        $this->assertSame('test_db_w1', $env['DB_DATABASE_TEST']);
    }

    public function test_debug_flag_adds_option(): void
    {
        $executor = new SymfonyProcessWorkerExecutor();
        $executor->setDebug(true);

        $command = $executor->buildCommand($this->makePlan());

        $this->assertContains('--debug', $command);
    }

    public function test_fail_fast_flag_adds_option(): void
    {
        $executor = new SymfonyProcessWorkerExecutor();
        $executor->setFailFast(true);

        $command = $executor->buildCommand($this->makePlan());

        $this->assertContains('--fail-fast', $command);
    }

    public function test_individual_flag_adds_option(): void
    {
        $executor = new SymfonyProcessWorkerExecutor();
        $plan = $this->makePlan(individual: true);

        $command = $executor->buildCommand($plan);

        $this->assertContains('--individual', $command);
    }

    public function test_timeout_included_in_command(): void
    {
        $executor = new SymfonyProcessWorkerExecutor();
        $executor->setTimeoutSeconds(300);

        $command = $executor->buildCommand($this->makePlan());

        $this->assertContains('--timeout=300', $command);
    }

    public function test_worker_sections_json_in_environment(): void
    {
        $executor = new SymfonyProcessWorkerExecutor();
        $plan = $this->makePlan();

        $env = $executor->buildEnvironment($plan);

        $sections = json_decode($env['WORKER_SECTIONS'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['Feature/Auth', 'Unit/Models'], $sections);
    }

    private function makePlan(bool $individual = false): WorkerPlanData
    {
        return new WorkerPlanData(
            workerId: 1,
            sections: [
                SectionAssignmentData::fromName('Feature/Auth'),
                SectionAssignmentData::fromName('Unit/Models'),
            ],
            database: 'test_db_w1',
            logDirectory: '/tmp/worker01',
            suite: 'default',
            estimatedWeight: 10.0,
            individual: $individual,
        );
    }
}
