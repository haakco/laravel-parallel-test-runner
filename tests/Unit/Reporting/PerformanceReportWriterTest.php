<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Reporting;

use Haakco\ParallelTestRunner\Data\ReportContext;
use Haakco\ParallelTestRunner\Reporting\PerformanceReportWriter;
use Haakco\ParallelTestRunner\Reporting\ReportFormatter;
use Haakco\ParallelTestRunner\Reporting\TrackingLoader;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Support\Facades\File;

final class PerformanceReportWriterTest extends TestCase
{
    private string $logDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDirectory = sys_get_temp_dir() . '/ptr-performance-report-' . uniqid();
        mkdir($this->logDirectory, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logDirectory);

        parent::tearDown();
    }

    public function test_write_skips_when_tracking_file_is_missing(): void
    {
        $writer = $this->makeWriter();

        $writer->write($this->makeContext());

        $this->assertFileDoesNotExist($this->logDirectory . '/performance-report.md');
    }

    public function test_write_generates_performance_report_with_section_rows(): void
    {
        $this->writeTrackingFile([
            'started_at' => '2026-04-02T12:00:00Z',
            'duration' => 8.25,
            'sections' => [
                'section-a' => [
                    'duration' => 5.2,
                    'results' => ['tests' => 10, 'assertions' => 20, 'errors' => 1, 'failures' => 0],
                ],
                'section-b' => [
                    'duration' => 1.1,
                    'results' => ['tests' => 4, 'assertions' => 8, 'errors' => 0, 'failures' => 1],
                ],
            ],
        ]);

        $writer = $this->makeWriter();
        $writer->write($this->makeContext());

        $content = file_get_contents($this->logDirectory . '/performance-report.md');

        $this->assertStringContainsString('# Test Performance Report - 2026-04-02T12:00:00Z', $content);
        $this->assertStringContainsString('## Top 10 Slowest Sections', $content);
        $this->assertStringContainsString('| section-a | 5.20 | 10 | 20 | 1 | 0 |', $content);
        $this->assertStringContainsString('| section-b | 1.10 | 4 | 8 | 0 | 1 |', $content);
    }

    public function test_write_uses_placeholder_row_when_no_section_metrics_exist(): void
    {
        $this->writeTrackingFile([
            'started_at' => '2026-04-02T12:00:00Z',
            'duration' => 0.0,
            'sections' => [],
        ]);

        $writer = $this->makeWriter();
        $writer->write($this->makeContext());

        $content = file_get_contents($this->logDirectory . '/performance-report.md');

        $this->assertStringContainsString('| _No section data available_ | 0 | 0 | 0 | 0 | 0 |', $content);
    }

    private function makeWriter(): PerformanceReportWriter
    {
        return new PerformanceReportWriter(
            formatter: new ReportFormatter(),
            trackingLoader: new TrackingLoader(),
        );
    }

    private function makeContext(): ReportContext
    {
        return new ReportContext(
            logDirectory: $this->logDirectory,
            successful: true,
            command: 'php artisan test:run-sections --parallel=2',
            summaryFile: $this->logDirectory . '/summary.json',
            extraOptions: [],
        );
    }

    /** @param array<string, mixed> $tracking */
    private function writeTrackingFile(array $tracking): void
    {
        file_put_contents(
            $this->logDirectory . '/execution_tracking.json',
            json_encode($tracking, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }
}
