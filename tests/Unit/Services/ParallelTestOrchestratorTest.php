<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Services;

use Haakco\ParallelTestRunner\Data\Parallel\SectionAssignmentData;
use Haakco\ParallelTestRunner\Data\Parallel\WorkerPlanData;
use Haakco\ParallelTestRunner\Services\ParallelTestOrchestrator;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

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
