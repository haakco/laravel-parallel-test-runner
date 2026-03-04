<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Data;

use Haakco\ParallelTestRunner\Data\Parallel\MetricsTotalsData;
use Haakco\ParallelTestRunner\Data\Results\AggregatedResultData;
use Haakco\ParallelTestRunner\Data\Results\BackgroundRunStartResultData;
use Haakco\ParallelTestRunner\Data\Results\BackgroundRunStatusData;
use Haakco\ParallelTestRunner\Data\Results\DatabaseRefreshResultData;
use Haakco\ParallelTestRunner\Data\Results\HangingTestsResultData;
use Haakco\ParallelTestRunner\Data\Results\SectionListResultData;
use Haakco\ParallelTestRunner\Data\Results\TestRunnerConfigurationFeedbackData;
use Haakco\ParallelTestRunner\Data\Results\TestRunResultData;
use Haakco\ParallelTestRunner\Data\Results\WorkerResultData;
use Haakco\ParallelTestRunner\Data\TestSectionData;
use Haakco\ParallelTestRunner\Tests\TestCase;

final class ResultsDtoTest extends TestCase
{
    public function test_test_run_result_success(): void
    {
        $result = TestRunResultData::success('All tests passed', 10.5, 100, 200);

        $this->assertTrue($result->success);
        $this->assertSame(0, $result->exitCode);
        $this->assertSame(10.5, $result->duration);
        $this->assertSame(100, $result->totalTests);
        $this->assertSame(200, $result->totalAssertions);
    }

    public function test_test_run_result_failure(): void
    {
        $result = TestRunResultData::failure('Tests failed', 5.0, 1, 2, 3);

        $this->assertFalse($result->success);
        $this->assertSame(1, $result->exitCode);
        $this->assertSame(2, $result->totalErrors);
        $this->assertSame(3, $result->totalFailures);
    }

    public function test_background_run_start_success(): void
    {
        $result = BackgroundRunStartResultData::success(1234, '/tmp/logs/run1.log');

        $this->assertTrue($result->started);
        $this->assertSame(1234, $result->pid);
        $this->assertSame('/tmp/logs/run1.log', $result->logFile);
    }

    public function test_background_run_start_failure(): void
    {
        $result = BackgroundRunStartResultData::failure('Already running');

        $this->assertFalse($result->started);
        $this->assertNull($result->pid);
    }

    public function test_background_run_status_running(): void
    {
        $status = BackgroundRunStatusData::running(5678, '/tmp/logs/test.log');

        $this->assertTrue($status->running);
        $this->assertSame(5678, $status->pid);
    }

    public function test_background_run_status_not_running(): void
    {
        $status = BackgroundRunStatusData::notRunning();

        $this->assertFalse($status->running);
        $this->assertNull($status->pid);
    }

    public function test_database_refresh_result_success(): void
    {
        $result = DatabaseRefreshResultData::success(1.5);

        $this->assertTrue($result->success);
        $this->assertSame(1.5, $result->duration);
    }

    public function test_database_refresh_result_failure(): void
    {
        $result = DatabaseRefreshResultData::failure('Connection refused');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection refused', $result->message);
    }

    public function test_hanging_tests_result(): void
    {
        $result = new HangingTestsResultData(
            found: true,
            hangingSections: ['Unit/Models', 'Feature/Api'],
            passedSections: ['Unit/Services'],
            threshold: 10,
        );

        $this->assertTrue($result->found);
        $this->assertCount(2, $result->hangingSections);
        $this->assertSame(10, $result->threshold);
    }

    public function test_section_list_result(): void
    {
        $sections = [
            new TestSectionData('Unit/Models', 'directory', 'tests/Unit/Models', ['UserTest.php'], 1),
        ];

        $result = new SectionListResultData(
            sections: $sections,
            totalFiles: 1,
            totalSections: 1,
        );

        $this->assertCount(1, $result->sections);
        $this->assertSame(1, $result->totalFiles);
    }

    public function test_aggregated_result(): void
    {
        $totals = new MetricsTotalsData(10, 20, 0, 0, 0, 0, 0, 0);
        $result = new AggregatedResultData(
            success: true,
            totals: $totals,
            totalDuration: 15.0,
            workerResults: [],
            failedSections: [],
            sectionsCompleted: 5,
            sectionsTotal: 5,
        );

        $this->assertTrue($result->success);
        $this->assertSame(15.0, $result->totalDuration);
        $this->assertSame(5, $result->sectionsCompleted);
    }

    public function test_worker_result(): void
    {
        $totals = new MetricsTotalsData(10, 20, 0, 0, 0, 0, 0, 0);
        $result = new WorkerResultData(
            workerId: 1,
            success: true,
            exitCode: 0,
            status: 'completed',
            duration: 5.0,
            totals: $totals,
            sections: ['Unit/Models'],
            logDirectory: '/tmp/logs/w1',
        );

        $this->assertSame(1, $result->workerId);
        $this->assertTrue($result->success);
    }

    public function test_configuration_feedback(): void
    {
        $feedback = new TestRunnerConfigurationFeedbackData(
            message: 'Config loaded',
            settings: ['parallel' => 4],
        );

        $this->assertSame('Config loaded', $feedback->message);
        $this->assertArrayHasKey('parallel', $feedback->settings);
    }
}
