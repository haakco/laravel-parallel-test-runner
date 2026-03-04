<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Data;

use Haakco\ParallelTestRunner\Data\Parallel\MetricsTotalsData;
use Haakco\ParallelTestRunner\Data\Parallel\SectionAssignmentData;
use Haakco\ParallelTestRunner\Data\Parallel\SectionResultData;
use Haakco\ParallelTestRunner\Data\Parallel\WorkerMetricsData;
use Haakco\ParallelTestRunner\Data\Parallel\WorkerPlanData;
use Haakco\ParallelTestRunner\Data\Parallel\WorkerPlanFileData;
use Haakco\ParallelTestRunner\Tests\TestCase;

final class ParallelDtoTest extends TestCase
{
    public function test_section_assignment_from_array(): void
    {
        $data = SectionAssignmentData::fromArray([
            'name' => 'Unit/Models',
            'type' => 'directory',
            'path' => 'tests/Unit/Models',
            'files' => ['UserTest.php'],
            'file_count' => 1,
        ]);

        $this->assertSame('Unit/Models', $data->name);
        $this->assertSame('directory', $data->type);
        $this->assertSame(1, $data->fileCount);
    }

    public function test_section_assignment_from_name(): void
    {
        $data = SectionAssignmentData::fromName('Feature/Api', ['LoginTest.php', 'LogoutTest.php']);

        $this->assertSame('Feature/Api', $data->name);
        $this->assertSame(2, $data->fileCount);
        $this->assertSame('Feature/Api', $data->path);
    }

    public function test_worker_plan_section_names(): void
    {
        $plan = new WorkerPlanData(
            workerId: 1,
            sections: [
                SectionAssignmentData::fromName('Unit/Models'),
                SectionAssignmentData::fromName('Feature/Api'),
            ],
            database: 'test_w1',
            logDirectory: '/tmp/logs/w1',
            suite: 'standard',
            estimatedWeight: 100.0,
        );

        $this->assertSame(['Unit/Models', 'Feature/Api'], $plan->sectionNames());
    }

    public function test_worker_plan_environment_uses_config(): void
    {
        config()->set('parallel-test-runner.database.connection', 'pgsql_testing');
        config()->set('parallel-test-runner.worker_environment.set_db_database_test', false);
        config()->set('parallel-test-runner.worker_environment.set_test_token', false);
        config()->set('parallel-test-runner.worker_environment.set_laravel_parallel_testing', false);

        $plan = new WorkerPlanData(
            workerId: 2,
            sections: [],
            database: 'test_w2',
            logDirectory: '/tmp/logs/w2',
            suite: 'standard',
            estimatedWeight: 50.0,
            individual: true,
        );

        $env = $plan->environment();

        $this->assertSame('pgsql_testing', $env['DB_CONNECTION']);
        $this->assertSame('test_w2', $env['DB_DATABASE']);
        $this->assertSame('2', $env['TEST_WORKER_ID']);
        $this->assertArrayNotHasKey('DB_DATABASE_TEST', $env);
        $this->assertArrayNotHasKey('TEST_TOKEN', $env);
        $this->assertSame('1', $env['TEST_INDIVIDUAL_MODE']);
    }

    public function test_worker_plan_environment_with_tl_options(): void
    {
        config()->set('parallel-test-runner.database.connection', 'pgsql_tl');
        config()->set('parallel-test-runner.worker_environment.set_db_database_test', true);
        config()->set('parallel-test-runner.worker_environment.set_test_token', true);
        config()->set('parallel-test-runner.worker_environment.set_laravel_parallel_testing', true);

        $plan = new WorkerPlanData(
            workerId: 3,
            sections: [],
            database: 'tl_test_w3',
            logDirectory: '/tmp/logs/w3',
            suite: 'standard',
            estimatedWeight: 50.0,
        );

        $env = $plan->environment();

        $this->assertSame('pgsql_tl', $env['DB_CONNECTION']);
        $this->assertSame('tl_test_w3', $env['DB_DATABASE_TEST']);
        $this->assertSame('3', $env['TEST_TOKEN']);
        $this->assertSame('1', $env['LARAVEL_PARALLEL_TESTING']);
    }

    public function test_worker_plan_file_round_trip(): void
    {
        $section = SectionAssignmentData::fromName('Unit/Models', ['UserTest.php']);

        $original = new WorkerPlanData(
            workerId: 1,
            sections: [$section],
            database: 'test_w1',
            logDirectory: '/tmp/logs/w1',
            suite: 'standard',
            estimatedWeight: 42.5,
            individual: true,
        );

        $fileData = WorkerPlanFileData::fromWorkerPlan($original);
        $restored = $fileData->toWorkerPlan();

        $this->assertSame($original->workerId, $restored->workerId);
        $this->assertSame($original->database, $restored->database);
        $this->assertSame($original->suite, $restored->suite);
        $this->assertSame($original->estimatedWeight, $restored->estimatedWeight);
        $this->assertSame($original->individual, $restored->individual);
        $this->assertCount(1, $restored->sections);
        $this->assertSame('Unit/Models', $restored->sections[0]->name);
    }

    public function test_worker_plan_file_override_log_directory(): void
    {
        $original = new WorkerPlanData(
            workerId: 1,
            sections: [],
            database: 'test_w1',
            logDirectory: '/tmp/original',
            suite: 'standard',
            estimatedWeight: 10.0,
        );

        $fileData = WorkerPlanFileData::fromWorkerPlan($original);
        $restored = $fileData->toWorkerPlan('/tmp/override');

        $this->assertSame('/tmp/override', $restored->logDirectory);
    }

    public function test_metrics_totals_accumulate(): void
    {
        $a = new MetricsTotalsData(tests: 10, assertions: 20, errors: 1, failures: 2, warnings: 0, skipped: 3, incomplete: 0, risky: 1);
        $b = new MetricsTotalsData(tests: 5, assertions: 10, errors: 0, failures: 1, warnings: 1, skipped: 0, incomplete: 1, risky: 0);

        $result = $a->accumulate($b);

        $this->assertSame(15, $result->tests);
        $this->assertSame(30, $result->assertions);
        $this->assertSame(1, $result->errors);
        $this->assertSame(3, $result->failures);
        $this->assertSame(1, $result->warnings);
        $this->assertSame(3, $result->skipped);
        $this->assertSame(1, $result->incomplete);
        $this->assertSame(1, $result->risky);
    }

    public function test_metrics_totals_from_array(): void
    {
        $data = MetricsTotalsData::fromArray([
            'tests' => 42,
            'assertions' => 100,
            'warnings' => 2,
        ]);

        $this->assertSame(42, $data->tests);
        $this->assertSame(100, $data->assertions);
        $this->assertSame(2, $data->warnings);
        $this->assertSame(0, $data->errors);
    }

    public function test_section_result_from_tracking_flat_payload(): void
    {
        $result = SectionResultData::fromTracking('Unit/Models', [
            'status' => 'passed',
            'tests' => 5,
            'assertions' => 10,
            'errors' => 0,
            'failures' => 0,
            'skipped' => 1,
            'incomplete' => 0,
            'risky' => 0,
            'duration' => 1.5,
            'exit_code' => 0,
            'timed_out' => false,
            'log_file' => '/tmp/log.txt',
            'started_at' => 100.0,
            'completed_at' => 101.5,
        ]);

        $this->assertTrue($result->success);
        $this->assertSame(5, $result->tests);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(1.5, $result->duration);
    }

    public function test_section_result_from_tracking_nested_payload(): void
    {
        $result = SectionResultData::fromTracking('Feature/Api', [
            'started_at' => 200.0,
            'completed_at' => 205.0,
            'results' => [
                'success' => false,
                'tests' => 3,
                'assertions' => 6,
                'errors' => 1,
                'failures' => 0,
                'skipped' => 0,
                'incomplete' => 0,
                'risky' => 0,
                'duration' => 5.0,
                'exit_code' => 1,
                'timed_out' => false,
                'log_file' => '/tmp/feature.log',
            ],
        ]);

        $this->assertFalse($result->success);
        $this->assertSame(1, $result->errors);
        $this->assertSame(200.0, $result->startedAt);
    }

    public function test_section_result_create_empty(): void
    {
        $empty = SectionResultData::createEmpty();

        $this->assertFalse($empty->success);
        $this->assertSame(0, $empty->tests);
        $this->assertSame(1, $empty->exitCode);
    }

    public function test_worker_metrics_accumulate(): void
    {
        $metrics1 = new WorkerMetricsData(
            totals: new MetricsTotalsData(10, 20, 0, 0, 0, 0, 0, 0),
            sections: ['Unit' => SectionResultData::createEmpty()],
            duration: 5.0,
        );

        $metrics2 = new WorkerMetricsData(
            totals: new MetricsTotalsData(5, 10, 1, 0, 0, 0, 0, 0),
            sections: ['Feature' => SectionResultData::createEmpty()],
            duration: 3.0,
        );

        $result = $metrics1->accumulate($metrics2);

        $this->assertSame(15, $result->totals->tests);
        $this->assertSame(8.0, $result->duration);
        $this->assertCount(2, $result->sections);
        $this->assertArrayHasKey('Unit', $result->sections);
        $this->assertArrayHasKey('Feature', $result->sections);
    }

    public function test_worker_metrics_from_execution_tracking(): void
    {
        $metrics = WorkerMetricsData::fromExecutionTracking([
            'totals' => ['tests' => 10, 'assertions' => 20],
            'sections' => [
                'Unit/Models' => [
                    'status' => 'passed',
                    'tests' => 5,
                    'assertions' => 10,
                    'started_at' => 100.0,
                    'completed_at' => 102.0,
                ],
            ],
            'duration' => 5.0,
        ]);

        $this->assertSame(10, $metrics->totals->tests);
        $this->assertCount(1, $metrics->sections);
        $this->assertArrayHasKey('Unit/Models', $metrics->sections);
    }
}
