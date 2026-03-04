<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Services;

use Haakco\ParallelTestRunner\Data\Parallel\SectionResultData;
use Haakco\ParallelTestRunner\Services\TestExecutionTracker;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Support\Facades\File;

final class TestExecutionTrackerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/ptr-tracker-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_records_section_result(): void
    {
        $tracker = new TestExecutionTracker($this->tempDir);

        $result = new SectionResultData(
            success: true,
            tests: 10,
            assertions: 25,
            errors: 0,
            failures: 0,
            skipped: 1,
            incomplete: 0,
            risky: 0,
            duration: 5.5,
            exitCode: 0,
            timedOut: false,
            logFile: '/tmp/test.log',
            startedAt: 1000.0,
            completedAt: 1005.5,
        );

        $tracker->recordSectionResult('Feature/Auth', $result);

        $sectionData = $tracker->getSectionResult('Feature/Auth');
        $this->assertNotNull($sectionData);
        $this->assertSame('passed', $sectionData['status']);
        $this->assertSame(5.5, $sectionData['duration']);
    }

    public function test_persists_to_json_file(): void
    {
        $tracker = new TestExecutionTracker($this->tempDir);
        $tracker->recordSectionResult('Unit/Models', new SectionResultData(
            success: true,
            tests: 5,
            assertions: 10,
            errors: 0,
            failures: 0,
            skipped: 0,
            incomplete: 0,
            risky: 0,
            duration: 1.0,
            exitCode: 0,
            timedOut: false,
            logFile: '',
            startedAt: 0.0,
            completedAt: 1.0,
        ));

        $trackingFile = $this->tempDir . '/execution_tracking.json';
        $this->assertFileExists($trackingFile);

        $data = json_decode(file_get_contents($trackingFile), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('sections', $data);
        $this->assertArrayHasKey('Unit/Models', $data['sections']);
    }

    public function test_loads_from_json_file(): void
    {
        // Write tracking data first
        $tracker1 = new TestExecutionTracker($this->tempDir);
        $tracker1->recordSectionResult('Feature/Api', new SectionResultData(
            success: true,
            tests: 20,
            assertions: 50,
            errors: 0,
            failures: 0,
            skipped: 0,
            incomplete: 0,
            risky: 0,
            duration: 3.0,
            exitCode: 0,
            timedOut: false,
            logFile: '',
            startedAt: 0.0,
            completedAt: 3.0,
        ));

        // Load from same directory
        $tracker2 = new TestExecutionTracker($this->tempDir);
        $sectionData = $tracker2->getSectionResult('Feature/Api');
        $this->assertNotNull($sectionData);
        $this->assertSame('passed', $sectionData['status']);
    }

    public function test_aggregates_totals_across_sections(): void
    {
        $tracker = new TestExecutionTracker($this->tempDir);

        $tracker->recordSectionResult('section-1', new SectionResultData(
            success: true,
            tests: 10,
            assertions: 20,
            errors: 0,
            failures: 0,
            skipped: 1,
            incomplete: 0,
            risky: 0,
            duration: 2.0,
            exitCode: 0,
            timedOut: false,
            logFile: '',
            startedAt: 0.0,
            completedAt: 2.0,
        ));

        $tracker->recordSectionResult('section-2', new SectionResultData(
            success: false,
            tests: 5,
            assertions: 8,
            errors: 1,
            failures: 0,
            skipped: 0,
            incomplete: 0,
            risky: 0,
            duration: 1.0,
            exitCode: 1,
            timedOut: false,
            logFile: '',
            startedAt: 0.0,
            completedAt: 1.0,
        ));

        $totals = $tracker->getTotals();
        $this->assertSame(15, $totals['tests']);
        $this->assertSame(28, $totals['assertions']);
        $this->assertSame(1, $totals['errors']);
        $this->assertSame(1, $totals['skipped']);
    }

    public function test_update_from_aggregated_metrics(): void
    {
        $tracker = new TestExecutionTracker($this->tempDir);
        $tracker->updateFromAggregatedMetrics([
            'tests' => 100,
            'assertions' => 250,
            'errors' => 2,
            'failures' => 1,
            'warnings' => 0,
            'skipped' => 5,
            'incomplete' => 0,
            'risky' => 0,
            'duration' => 30.0,
        ]);

        $totals = $tracker->getTotals();
        $this->assertSame(100, $totals['tests']);
        $this->assertSame(250, $totals['assertions']);
        $this->assertSame(2, $totals['errors']);
    }
}
