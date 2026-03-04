<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Scheduling;

use Haakco\ParallelTestRunner\Contracts\PerformanceMetricRepositoryInterface;
use Haakco\ParallelTestRunner\Data\Parallel\WorkerPlanData;
use Haakco\ParallelTestRunner\Data\TestSectionData;
use Haakco\ParallelTestRunner\Scheduling\ParallelSectionScheduler;
use Haakco\ParallelTestRunner\Tests\TestCase;
use RuntimeException;

final class ParallelSectionSchedulerTest extends TestCase
{
    private function makeScheduler(array $historicalWeights = []): ParallelSectionScheduler
    {
        $metricRepo = $this->createStub(PerformanceMetricRepositoryInterface::class);
        $metricRepo->method('getHistoricalWeights')->willReturn($historicalWeights);

        return new ParallelSectionScheduler($metricRepo);
    }

    private function makeSection(string $name, int $fileCount): TestSectionData
    {
        $files = array_map(
            static fn(int $i): string => "tests/{$name}/File{$i}Test.php",
            range(1, max(1, $fileCount)),
        );

        return new TestSectionData(
            name: $name,
            type: 'directory',
            path: "tests/{$name}",
            files: $files,
            fileCount: $fileCount,
        );
    }

    public function test_creates_worker_plans_for_sections(): void
    {
        $scheduler = $this->makeScheduler();

        $sections = [
            $this->makeSection('Unit/Models', 5),
            $this->makeSection('Feature/Api', 3),
        ];

        $plans = $scheduler->createWorkerPlans(
            sections: $sections,
            workerCount: 2,
            databases: [1 => 'test_db_w1', 2 => 'test_db_w2'],
            logDirectory: '/tmp/test-logs',
        );

        $this->assertNotEmpty($plans);
        $this->assertLessThanOrEqual(2, count($plans));

        // All sections should be assigned
        $allSectionNames = [];
        foreach ($plans as $plan) {
            foreach ($plan->sections as $section) {
                $allSectionNames[] = $section->name;
            }
        }

        $this->assertContains('Unit/Models', $allSectionNames);
        $this->assertContains('Feature/Api', $allSectionNames);
    }

    public function test_empty_sections_returns_empty(): void
    {
        $scheduler = $this->makeScheduler();

        $plans = $scheduler->createWorkerPlans(
            sections: [],
            workerCount: 2,
            databases: [1 => 'db1', 2 => 'db2'],
            logDirectory: '/tmp/logs',
        );

        $this->assertSame([], $plans);
    }

    public function test_throws_for_zero_workers(): void
    {
        $scheduler = $this->makeScheduler();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Worker count must be at least 1');

        $scheduler->createWorkerPlans(
            sections: [$this->makeSection('Unit', 1)],
            workerCount: 0,
            databases: [],
            logDirectory: '/tmp/logs',
        );
    }

    public function test_uses_historical_weights_when_available(): void
    {
        $scheduler = $this->makeScheduler([
            'Unit/Models' => 100.0,
            'Feature/Api' => 50.0,
        ]);

        $sections = [
            $this->makeSection('Unit/Models', 2),
            $this->makeSection('Feature/Api', 2),
        ];

        $plans = $scheduler->createWorkerPlans(
            sections: $sections,
            workerCount: 2,
            databases: [1 => 'db1', 2 => 'db2'],
            logDirectory: '/tmp/logs',
        );

        // With historical weights, heavier section goes to worker 1
        $this->assertCount(2, $plans);

        // Worker plans should have different estimated weights
        $weights = array_map(static fn(WorkerPlanData $p): float => $p->estimatedWeight, $plans);
        $this->assertContains(100.0, $weights);
        $this->assertContains(50.0, $weights);
    }

    public function test_weight_multipliers_applied(): void
    {
        $scheduler = $this->makeScheduler();

        $unitSection = $this->makeSection('Unit/Models', 5);
        $featureSection = $this->makeSection('Feature/Api', 5);

        $plans = $scheduler->createWorkerPlans(
            sections: [$unitSection, $featureSection],
            workerCount: 2,
            databases: [1 => 'db1', 2 => 'db2'],
            logDirectory: '/tmp/logs',
        );

        // Feature sections should have higher estimated weight than Unit
        // Unit: 5 * 10 * 0.8 = 40, Feature: 5 * 10 * 1.5 = 75
        $weights = [];
        foreach ($plans as $plan) {
            foreach ($plan->sections as $section) {
                $weights[$section->name] = $plan->estimatedWeight;
            }
        }

        // The plan with Feature/Api should have higher weight
        $this->assertGreaterThan($weights['Unit/Models'], $weights['Feature/Api']);
    }

    public function test_balanced_distribution_across_workers(): void
    {
        $scheduler = $this->makeScheduler();

        $sections = [
            $this->makeSection('Unit/A', 10),
            $this->makeSection('Unit/B', 8),
            $this->makeSection('Unit/C', 6),
            $this->makeSection('Unit/D', 4),
        ];

        $plans = $scheduler->createWorkerPlans(
            sections: $sections,
            workerCount: 2,
            databases: [1 => 'db1', 2 => 'db2'],
            logDirectory: '/tmp/logs',
        );

        $this->assertCount(2, $plans);

        // LPT: worker1 gets [10, 4]=14*10*0.8=112, worker2 gets [8, 6]=14*10*0.8=112
        // Both workers should get sections
        foreach ($plans as $plan) {
            $this->assertNotEmpty($plan->sections);
        }
    }

    public function test_worker_plan_has_correct_metadata(): void
    {
        $scheduler = $this->makeScheduler();

        $plans = $scheduler->createWorkerPlans(
            sections: [$this->makeSection('Unit/Models', 3)],
            workerCount: 1,
            databases: [1 => 'test_db_w01'],
            logDirectory: '/tmp/test-logs',
            suite: 'standard',
            individual: true,
        );

        $this->assertCount(1, $plans);

        $plan = $plans[0];
        $this->assertSame(1, $plan->workerId);
        $this->assertSame('test_db_w01', $plan->database);
        $this->assertSame('/tmp/test-logs/worker01', $plan->logDirectory);
        $this->assertSame('standard', $plan->suite);
        $this->assertTrue($plan->individual);
        $this->assertGreaterThan(0, $plan->estimatedWeight);
    }

    public function test_throws_when_database_missing_for_worker(): void
    {
        $scheduler = $this->makeScheduler();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No database for worker');

        $scheduler->createWorkerPlans(
            sections: [$this->makeSection('Unit', 1)],
            workerCount: 1,
            databases: [], // No databases
            logDirectory: '/tmp/logs',
        );
    }
}
