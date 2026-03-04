<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Services;

use Haakco\ParallelTestRunner\Contracts\ResultAggregatorInterface;
use Haakco\ParallelTestRunner\Data\Parallel\MetricsTotalsData;
use Haakco\ParallelTestRunner\Data\Results\WorkerResultData;
use Haakco\ParallelTestRunner\Services\JsonFileResultAggregator;
use Haakco\ParallelTestRunner\Tests\TestCase;

final class JsonFileResultAggregatorTest extends TestCase
{
    public function test_implements_interface(): void
    {
        $aggregator = new JsonFileResultAggregator();
        $this->assertInstanceOf(ResultAggregatorInterface::class, $aggregator);
    }

    public function test_aggregates_totals_across_workers(): void
    {
        $aggregator = new JsonFileResultAggregator();

        $worker1 = new WorkerResultData(
            workerId: 1,
            success: true,
            exitCode: 0,
            status: 'completed',
            duration: 10.0,
            totals: new MetricsTotalsData(
                tests: 20,
                assertions: 40,
                errors: 0,
                failures: 0,
                warnings: 0,
                skipped: 1,
                incomplete: 0,
                risky: 0,
            ),
            sections: ['section-a', 'section-b'],
            logDirectory: '/tmp/worker-1',
        );

        $worker2 = new WorkerResultData(
            workerId: 2,
            success: true,
            exitCode: 0,
            status: 'completed',
            duration: 15.0,
            totals: new MetricsTotalsData(
                tests: 30,
                assertions: 60,
                errors: 0,
                failures: 0,
                warnings: 1,
                skipped: 2,
                incomplete: 0,
                risky: 0,
            ),
            sections: ['section-c'],
            logDirectory: '/tmp/worker-2',
        );

        $result = $aggregator->aggregate([$worker1, $worker2]);

        $this->assertTrue($result->success);
        $this->assertSame(50, $result->totals->tests);
        $this->assertSame(100, $result->totals->assertions);
        $this->assertSame(0, $result->totals->errors);
        $this->assertSame(3, $result->totals->skipped);
        $this->assertSame(1, $result->totals->warnings);
        $this->assertSame(15.0, $result->totalDuration);
        $this->assertSame(3, $result->sectionsTotal);
        $this->assertSame(3, $result->sectionsCompleted);
        $this->assertSame([], $result->failedSections);
    }

    public function test_handles_empty_worker_results(): void
    {
        $aggregator = new JsonFileResultAggregator();
        $result = $aggregator->aggregate([]);

        $this->assertTrue($result->success);
        $this->assertSame(0, $result->totals->tests);
        $this->assertSame(0.0, $result->totalDuration);
        $this->assertSame(0, $result->sectionsTotal);
    }

    public function test_handles_failed_workers(): void
    {
        $aggregator = new JsonFileResultAggregator();

        $worker1 = new WorkerResultData(
            workerId: 1,
            success: true,
            exitCode: 0,
            status: 'completed',
            duration: 10.0,
            totals: new MetricsTotalsData(
                tests: 20,
                assertions: 40,
                errors: 0,
                failures: 0,
                warnings: 0,
                skipped: 0,
                incomplete: 0,
                risky: 0,
            ),
            sections: ['section-a'],
            logDirectory: '/tmp/worker-1',
        );

        $worker2 = new WorkerResultData(
            workerId: 2,
            success: false,
            exitCode: 1,
            status: 'failed',
            duration: 5.0,
            totals: new MetricsTotalsData(
                tests: 10,
                assertions: 20,
                errors: 1,
                failures: 2,
                warnings: 0,
                skipped: 0,
                incomplete: 0,
                risky: 0,
            ),
            sections: ['section-b'],
            logDirectory: '/tmp/worker-2',
        );

        $result = $aggregator->aggregate([$worker1, $worker2]);

        $this->assertFalse($result->success);
        $this->assertSame(30, $result->totals->tests);
        $this->assertSame(1, $result->totals->errors);
        $this->assertSame(2, $result->totals->failures);
        $this->assertSame(['section-b'], $result->failedSections);
        $this->assertSame(1, $result->sectionsCompleted);
        $this->assertSame(2, $result->sectionsTotal);
    }
}
