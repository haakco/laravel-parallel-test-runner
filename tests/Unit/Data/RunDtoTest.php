<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Data;

use Haakco\ParallelTestRunner\Data\ErrorFileContentData;
use Haakco\ParallelTestRunner\Data\ParsedErrorData;
use Haakco\ParallelTestRunner\Data\ParsedTestOutputData;
use Haakco\ParallelTestRunner\Data\Run\TestRunCountersData;
use Haakco\ParallelTestRunner\Data\Run\TestRunReportData;
use Haakco\ParallelTestRunner\Data\Run\TestSectionResultData;
use Haakco\ParallelTestRunner\Data\Run\TestSectionStatusCountData;
use Haakco\ParallelTestRunner\Data\Run\TestSectionSummaryData;
use Haakco\ParallelTestRunner\Data\SectionMetadataData;
use Haakco\ParallelTestRunner\Data\SectionStatusData;
use Haakco\ParallelTestRunner\Data\SlowestSectionData;
use Haakco\ParallelTestRunner\Data\TestEtaData;
use Haakco\ParallelTestRunner\Data\TestProgressData;
use Haakco\ParallelTestRunner\Data\TestRunnerStateData;
use Haakco\ParallelTestRunner\Data\TestRunReportContextData;
use Haakco\ParallelTestRunner\Tests\TestCase;

final class RunDtoTest extends TestCase
{
    public function test_run_counters_zero(): void
    {
        $counters = TestRunCountersData::zero();

        $this->assertSame(0, $counters->tests);
        $this->assertSame(0, $counters->failures);
        $this->assertFalse($counters->hasFailures());
    }

    public function test_run_counters_has_failures(): void
    {
        $counters = new TestRunCountersData(10, 20, 1, 0, 0, 0, 0, 0);

        $this->assertTrue($counters->hasFailures());
    }

    public function test_section_result(): void
    {
        $result = new TestSectionResultData(
            name: 'Unit/Models',
            status: 'passed',
            tests: 10,
            assertions: 20,
            errors: 0,
            failures: 0,
            duration: 1.5,
            exitCode: 0,
            timedOut: false,
        );

        $this->assertSame('Unit/Models', $result->name);
        $this->assertSame('passed', $result->status);
    }

    public function test_section_summary(): void
    {
        $summary = new TestSectionSummaryData(
            name: 'Unit/Models',
            status: 'passed',
            fileCount: 5,
            duration: 2.5,
        );

        $this->assertSame('Unit/Models', $summary->name);
        $this->assertSame(5, $summary->fileCount);
    }

    public function test_section_status_count(): void
    {
        $count = new TestSectionStatusCountData(
            passed: 10,
            failed: 2,
            skipped: 1,
            pending: 3,
            total: 16,
        );

        $this->assertSame(16, $count->total);
        $this->assertSame(2, $count->failed);
    }

    public function test_run_report(): void
    {
        $counters = TestRunCountersData::zero();
        $report = new TestRunReportData(
            success: true,
            counters: $counters,
            totalDuration: 10.0,
            sectionResults: [],
            failedSections: [],
            sectionsCompleted: 5,
            sectionsTotal: 5,
        );

        $this->assertTrue($report->success);
        $this->assertSame(10.0, $report->totalDuration);
    }

    public function test_section_metadata_from_array(): void
    {
        $metadata = SectionMetadataData::fromArray([
            'name' => 'Unit/Models',
            'type' => 'directory',
            'path' => 'tests/Unit/Models',
            'files' => ['UserTest.php', 'OrderTest.php'],
            'estimated_weight' => 25.0,
        ]);

        $this->assertSame('Unit/Models', $metadata->name);
        $this->assertSame(2, $metadata->fileCount);
        $this->assertSame(25.0, $metadata->estimatedWeight);
    }

    public function test_section_metadata_with_files(): void
    {
        $metadata = SectionMetadataData::fromArray([
            'name' => 'Unit',
            'path' => 'tests/Unit',
            'files' => ['A.php'],
        ]);

        $updated = $metadata->withFiles(['A.php', 'B.php', 'C.php']);

        $this->assertCount(3, $updated->files);
        $this->assertSame(3, $updated->fileCount);
    }

    public function test_section_status(): void
    {
        $status = new SectionStatusData(
            name: 'Unit/Models',
            status: 'running',
            duration: 1.5,
            exitCode: null,
        );

        $this->assertSame('running', $status->status);
        $this->assertNull($status->exitCode);
    }

    public function test_runner_state_initial(): void
    {
        $state = TestRunnerStateData::initial(['Unit', 'Feature']);

        $this->assertSame(['Unit', 'Feature'], $state->pending);
        $this->assertEmpty($state->running);
        $this->assertEmpty($state->completed);
        $this->assertEmpty($state->failed);
    }

    public function test_parsed_test_output(): void
    {
        $output = new ParsedTestOutputData(
            tests: 10,
            assertions: 20,
            errors: 0,
            failures: 0,
            skipped: 1,
            incomplete: 0,
            risky: 0,
            warnings: 0,
            duration: 1.5,
            success: true,
        );

        $this->assertFalse($output->isEmpty());
        $this->assertTrue($output->success);
    }

    public function test_parsed_test_output_empty(): void
    {
        $output = new ParsedTestOutputData(
            tests: 0,
            assertions: 0,
            errors: 0,
            failures: 0,
            skipped: 0,
            incomplete: 0,
            risky: 0,
            warnings: 0,
            duration: 0.0,
            success: false,
        );

        $this->assertTrue($output->isEmpty());
    }

    public function test_parsed_error(): void
    {
        $error = new ParsedErrorData(
            type: 'Error',
            message: 'Something failed',
            file: 'tests/Unit/UserTest.php',
            line: 42,
            trace: 'at UserTest->testSomething()',
        );

        $this->assertSame('Error', $error->type);
        $this->assertSame(42, $error->line);
    }

    public function test_error_file_content(): void
    {
        $content = new ErrorFileContentData(
            filePath: '/tmp/error.log',
            lines: ['line 1', 'line 2'],
            errorLine: 1,
            contextStart: 0,
            contextEnd: 1,
        );

        $this->assertSame('/tmp/error.log', $content->filePath);
        $this->assertCount(2, $content->lines);
    }

    public function test_slowest_section(): void
    {
        $section = new SlowestSectionData(
            name: 'Feature/Api',
            duration: 42.5,
            tests: 10,
            fileCount: 3,
        );

        $this->assertSame(42.5, $section->duration);
        $this->assertSame(10, $section->tests);
    }

    public function test_progress_data(): void
    {
        $progress = new TestProgressData(
            completed: 5,
            total: 10,
            percentage: 50.0,
            elapsedSeconds: 30.0,
            estimatedRemainingSeconds: 30.0,
        );

        $this->assertSame(50.0, $progress->percentage);
    }

    public function test_eta_data(): void
    {
        $eta = new TestEtaData(
            estimatedTotalSeconds: 120.0,
            elapsedSeconds: 60.0,
            remainingSeconds: 60.0,
            percentComplete: 50.0,
        );

        $this->assertSame(50.0, $eta->percentComplete);
    }

    public function test_report_context(): void
    {
        $context = new TestRunReportContextData(
            logDirectory: '/tmp/logs',
            command: 'test:run-sections',
            parallel: true,
            workerCount: 4,
            splitTotal: null,
            splitGroup: null,
            extraData: [],
        );

        $this->assertTrue($context->parallel);
        $this->assertSame(4, $context->workerCount);
    }
}
