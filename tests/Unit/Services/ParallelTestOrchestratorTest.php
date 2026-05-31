<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Services;

use Haakco\ParallelTestRunner\Data\Parallel\SectionAssignmentData;
use Haakco\ParallelTestRunner\Data\Parallel\WorkerPlanData;
use Haakco\ParallelTestRunner\Data\Parallel\WorkerProcessStateData;
use Haakco\ParallelTestRunner\Data\Parallel\WorkerProcessStatus;
use Haakco\ParallelTestRunner\Services\ParallelTestOrchestrator;
use Haakco\ParallelTestRunner\Services\SymfonyProcessWorkerExecutor;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Console\OutputStyle;
use Illuminate\Process\InvokedProcess;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Process as SymfonyProcess;

final class ParallelTestOrchestratorTest extends TestCase
{
    public function test_constructor_sets_properties(): void
    {
        $orchestrator = $this->createOrchestrator();

        $this->assertInstanceOf(ParallelTestOrchestrator::class, $orchestrator);
    }

    public function test_execute_empty_plans_returns_true(): void
    {
        $orchestrator = $this->createOrchestrator();

        $result = $orchestrator->executeWorkerPlans([]);

        $this->assertTrue($result);
    }

    public function test_get_section_results_initially_empty(): void
    {
        $orchestrator = $this->createOrchestrator();

        $this->assertSame([], $orchestrator->getSectionResults());
    }

    public function test_get_aggregated_metrics_initially_zeroed(): void
    {
        $orchestrator = $this->createOrchestrator();

        $metrics = $orchestrator->getAggregatedMetrics();

        $this->assertSame(0, $metrics['tests']);
        $this->assertSame(0, $metrics['assertions']);
        $this->assertSame(0, $metrics['errors']);
        $this->assertSame(0, $metrics['failures']);
        $this->assertSame(0, $metrics['warnings']);
        $this->assertSame(0, $metrics['skipped']);
    }

    public function test_section_worker_map_initially_empty(): void
    {
        $orchestrator = $this->createOrchestrator();

        $this->assertSame([], $orchestrator->getSectionWorkerMap());
    }

    /**
     * Regression: run_report.json failures used to label every failing
     * section as worker_id=1 because aggregateResults never built a
     * section→worker map. Drop two fake execution_tracking.json files
     * (one per worker), invoke aggregateResults via reflection, and
     * assert each section is attributed back to the worker that ran it.
     */
    public function test_aggregate_results_builds_section_worker_map(): void
    {
        $orchestrator = $this->createOrchestrator();

        $worker2Dir = sys_get_temp_dir() . '/ptr-w2-' . uniqid();
        $worker7Dir = sys_get_temp_dir() . '/ptr-w7-' . uniqid();
        mkdir($worker2Dir, 0755, true);
        mkdir($worker7Dir, 0755, true);

        file_put_contents(
            $worker2Dir . '/execution_tracking.json',
            json_encode([
                'totals' => ['tests' => 1, 'assertions' => 1, 'errors' => 0, 'failures' => 0],
                'sections' => [
                    'Feature/Alpha' => ['status' => 'passed', 'results' => ['tests' => 1, 'assertions' => 1]],
                ],
            ], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $worker7Dir . '/execution_tracking.json',
            json_encode([
                'totals' => ['tests' => 1, 'assertions' => 1, 'errors' => 0, 'failures' => 1],
                'sections' => [
                    'Feature/Beta' => ['status' => 'failed', 'results' => ['tests' => 1, 'assertions' => 1, 'failures' => 1]],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $plan2 = new WorkerPlanData(
            workerId: 2,
            sections: [SectionAssignmentData::fromName('Feature/Alpha')],
            database: 'db_w2',
            logDirectory: $worker2Dir,
            suite: 'standard',
            estimatedWeight: 1.0,
            individual: false,
        );
        $plan7 = new WorkerPlanData(
            workerId: 7,
            sections: [SectionAssignmentData::fromName('Feature/Beta')],
            database: 'db_w7',
            logDirectory: $worker7Dir,
            suite: 'standard',
            estimatedWeight: 1.0,
            individual: false,
        );

        $finishedProcess = new SymfonyProcess(['php', '-r', '']);
        $finishedProcess->start();
        $finishedProcess->wait();

        $class = new ReflectionClass($orchestrator);
        $workerProcesses = $class->getProperty('workerProcesses');
        $workerProcesses->setValue($orchestrator, [
            2 => WorkerProcessStateData::running(new InvokedProcess($finishedProcess), $plan2),
            7 => WorkerProcessStateData::running(new InvokedProcess($finishedProcess), $plan7),
        ]);

        $aggregate = new ReflectionMethod($orchestrator, 'aggregateResults');
        $aggregate->invoke($orchestrator);

        $map = $orchestrator->getSectionWorkerMap();
        $this->assertSame(2, $map['Feature/Alpha'] ?? null);
        $this->assertSame(7, $map['Feature/Beta'] ?? null);
    }

    public function test_worker_plan_data_can_be_created(): void
    {
        $plan = new WorkerPlanData(
            workerId: 1,
            sections: [
                SectionAssignmentData::fromName('tests/Unit/FooTest'),
            ],
            database: 'test_db_w1',
            logDirectory: '/tmp/test-logs/worker1',
            suite: 'standard',
            estimatedWeight: 10.0,
            individual: false,
        );

        $this->assertSame(1, $plan->workerId);
        $this->assertSame(['tests/Unit/FooTest'], $plan->sectionNames());
        $this->assertSame('test_db_w1', $plan->database);
    }

    public function test_start_workers_uses_executor_environment_with_forced_log_directory_refs(): void
    {
        config()->set('parallel-test-runner.worker_environment.set_test_log_dir', false);

        $orchestrator = $this->createOrchestrator();
        $workerLogDir = sys_get_temp_dir() . '/ptr-worker-env-' . uniqid();
        $plan = new WorkerPlanData(
            workerId: 1,
            sections: [
                SectionAssignmentData::fromName('tests/Unit/FooTest'),
            ],
            database: 'test_db_w1',
            logDirectory: $workerLogDir,
            suite: 'standard',
            estimatedWeight: 10.0,
            individual: false,
        );

        $buildWorkerEnvironment = new ReflectionMethod($orchestrator, 'buildWorkerEnvironment');
        $environment = $buildWorkerEnvironment->invoke($orchestrator, $plan, new SymfonyProcessWorkerExecutor());

        $this->assertSame($workerLogDir, $environment['PARALLEL_TEST_RUNNER_LOG_DIR'] ?? null);
        $this->assertSame($workerLogDir, $environment['TEST_LOG_DIR'] ?? null);
        $this->assertSame('["tests\/Unit\/FooTest"]', $environment['WORKER_SECTIONS'] ?? null);
    }

    public function test_polling_finished_worker_persists_completed_status(): void
    {
        $orchestrator = $this->createOrchestrator();
        $plan = new WorkerPlanData(
            workerId: 1,
            sections: [
                SectionAssignmentData::fromName('tests/Unit/FooTest'),
            ],
            database: 'test_db_w1',
            logDirectory: sys_get_temp_dir() . '/ptr-worker-' . uniqid(),
            suite: 'standard',
            estimatedWeight: 10.0,
            individual: false,
        );

        $process = new SymfonyProcess(['php', '-r', '']);
        $process->start();
        $process->wait();

        $class = new ReflectionClass($orchestrator);
        $workerProcesses = $class->getProperty('workerProcesses');
        $workerProcesses->setValue($orchestrator, [
            1 => WorkerProcessStateData::running(new InvokedProcess($process), $plan),
        ]);

        $allSuccess = true;
        $pollRunningWorkers = new ReflectionMethod($orchestrator, 'pollRunningWorkers');

        $this->assertTrue($pollRunningWorkers->invokeArgs($orchestrator, [&$allSuccess, false]));

        $workers = $workerProcesses->getValue($orchestrator);
        $this->assertSame(WorkerProcessStatus::Completed, $workers[1]->status);
    }

    /**
     * Regression: an arrow function in pollRunningWorkers captured $allSuccess
     * by value, so finishWorker's `$allSuccess = false` only wrote to a local
     * copy. Workers exiting with non-zero status were correctly marked Failed
     * but the run reported "Tests passed!" anyway. This test exercises a worker
     * that exits with code 1 and asserts the by-reference flag flips to false.
     */
    public function test_polling_failing_worker_flips_all_success_to_false(): void
    {
        $orchestrator = $this->createOrchestrator();
        $plan = new WorkerPlanData(
            workerId: 1,
            sections: [
                SectionAssignmentData::fromName('tests/Unit/FooTest'),
            ],
            database: 'test_db_w1',
            logDirectory: sys_get_temp_dir() . '/ptr-worker-' . uniqid(),
            suite: 'standard',
            estimatedWeight: 10.0,
            individual: false,
        );

        // Process exits with non-zero so finishWorker should set
        // $allSuccess = false. Use `exit(1)` to be portable.
        $process = new SymfonyProcess(['php', '-r', 'exit(1);']);
        $process->start();
        $process->wait();

        $class = new ReflectionClass($orchestrator);
        $workerProcesses = $class->getProperty('workerProcesses');
        $workerProcesses->setValue($orchestrator, [
            1 => WorkerProcessStateData::running(new InvokedProcess($process), $plan),
        ]);

        $allSuccess = true;
        $pollRunningWorkers = new ReflectionMethod($orchestrator, 'pollRunningWorkers');

        $pollRunningWorkers->invokeArgs($orchestrator, [&$allSuccess, false]);

        $this->assertFalse(
            $allSuccess,
            'pollRunningWorkers must propagate the failure to $allSuccess by reference.',
        );

        $workers = $workerProcesses->getValue($orchestrator);
        // Without a metrics file the status maps to Crashed; with one it would
        // be Failed. Both are non-success states that must flip $allSuccess.
        $this->assertContains(
            $workers[1]->status,
            [WorkerProcessStatus::Failed, WorkerProcessStatus::Crashed],
            'Worker with non-zero exit must be recorded as Failed or Crashed.',
        );
    }

    public function test_worker_progress_output_uses_latest_marker_without_moving_backwards(): void
    {
        $orchestrator = $this->createOrchestrator();
        $plan = new WorkerPlanData(
            workerId: 1,
            sections: [
                SectionAssignmentData::fromName('tests/Unit/FooTest'),
                SectionAssignmentData::fromName('tests/Unit/BarTest'),
                SectionAssignmentData::fromName('tests/Unit/BazTest'),
            ],
            database: 'test_db_w1',
            logDirectory: sys_get_temp_dir() . '/ptr-worker-' . uniqid(),
            suite: 'standard',
            estimatedWeight: 10.0,
            individual: false,
        );

        $process = new SymfonyProcess(['php', '-r', '']);
        $process->start();
        $process->wait();

        $class = new ReflectionClass($orchestrator);
        $workerProcesses = $class->getProperty('workerProcesses');
        $workerProcesses->setValue($orchestrator, [
            1 => WorkerProcessStateData::running(new InvokedProcess($process), $plan),
        ]);

        $processWorkerOutput = new ReflectionMethod($orchestrator, 'processWorkerOutput');
        $processWorkerOutput->invoke($orchestrator, 1, "[1/3] Testing Foo\n[2/3] Testing Bar\n");
        $processWorkerOutput->invoke($orchestrator, 1, "[0/3] Starting worker\n[3/3] Testing Baz\n");
        $processWorkerOutput->invoke($orchestrator, 1, "[1/3] Replayed buffered output\n");

        $workers = $workerProcesses->getValue($orchestrator);
        $this->assertSame(3, $workers[1]->completedSections);
        $this->assertSame(3, $workers[1]->totalSections);
    }

    public function test_tracking_file_completed_count_does_not_move_progress_backwards(): void
    {
        $orchestrator = $this->createOrchestrator();
        $workerLogDir = sys_get_temp_dir() . '/ptr-worker-progress-' . uniqid();
        mkdir($workerLogDir, 0755, true);
        file_put_contents(
            $workerLogDir . '/execution_tracking.json',
            json_encode([
                'sections' => [
                    'tests/Unit/FooTest' => ['completed_at' => 100],
                    'tests/Unit/BarTest' => ['completed_at' => null],
                    'tests/Unit/BazTest' => ['completed_at' => null],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $plan = new WorkerPlanData(
            workerId: 1,
            sections: [
                SectionAssignmentData::fromName('tests/Unit/FooTest'),
                SectionAssignmentData::fromName('tests/Unit/BarTest'),
                SectionAssignmentData::fromName('tests/Unit/BazTest'),
            ],
            database: 'test_db_w1',
            logDirectory: $workerLogDir,
            suite: 'standard',
            estimatedWeight: 10.0,
            individual: false,
        );

        $process = new SymfonyProcess(['php', '-r', '']);
        $process->start();
        $process->wait();

        $class = new ReflectionClass($orchestrator);
        $workerProcesses = $class->getProperty('workerProcesses');
        $worker = WorkerProcessStateData::running(new InvokedProcess($process), $plan);
        $worker->completedSections = 3;
        $worker->totalSections = 3;
        $workerProcesses->setValue($orchestrator, [
            1 => $worker,
        ]);

        $countCompletedSectionsFromTracking = new ReflectionMethod($orchestrator, 'countCompletedSectionsFromTracking');

        $this->assertSame(
            3,
            $countCompletedSectionsFromTracking->invoke($orchestrator, $workerProcesses->getValue($orchestrator)[1]),
        );
    }

    private function createOrchestrator(): ParallelTestOrchestrator
    {
        $output = new OutputStyle(new ArrayInput([]), new NullOutput());
        $logDir = sys_get_temp_dir() . '/ptr-orchestrator-test-' . uniqid();
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        return new ParallelTestOrchestrator(
            output: $output,
            logDirectory: $logDir,
            timeoutSeconds: 60,
            debug: false,
            failFast: false,
        );
    }
}
