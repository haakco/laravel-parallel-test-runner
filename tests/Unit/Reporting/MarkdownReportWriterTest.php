<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Reporting;

use Haakco\ParallelTestRunner\Data\ReportContext;
use Haakco\ParallelTestRunner\Reporting\MarkdownReportWriter;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Support\Facades\File;

final class MarkdownReportWriterTest extends TestCase
{
    private string $logDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logDirectory = sys_get_temp_dir() . '/ptr-md-report-test-' . uniqid();
        mkdir($this->logDirectory, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logDirectory);
        parent::tearDown();
    }

    public function test_writes_all_report_files(): void
    {
        $this->writeTrackingFile();

        $writer = $this->app->make(MarkdownReportWriter::class);
        $writer->write($this->makeContext());

        // JSON report
        $this->assertFileExists($this->logDirectory . '/run_report.json');

        // Performance report
        $this->assertFileExists($this->logDirectory . '/performance-report.md');

        // Runtime baselines
        $this->assertFileExists($this->logDirectory . '/runtime-baselines.md');

        // Skip audit
        $this->assertFileExists($this->logDirectory . '/skip-audit.md');
    }

    public function test_performance_report_contains_section_table(): void
    {
        $this->writeTrackingFile();

        $writer = $this->app->make(MarkdownReportWriter::class);
        $writer->write($this->makeContext());

        $content = file_get_contents($this->logDirectory . '/performance-report.md');
        $this->assertStringContainsString('Top 10 Slowest Sections', $content);
        $this->assertStringContainsString('section-a', $content);
        $this->assertStringContainsString('| Section |', $content);
    }

    public function test_runtime_baselines_contains_section_data(): void
    {
        $this->writeTrackingFile();

        $writer = $this->app->make(MarkdownReportWriter::class);
        $writer->write($this->makeContext());

        $content = file_get_contents($this->logDirectory . '/runtime-baselines.md');
        $this->assertStringContainsString('Test Runtime Baselines', $content);
        $this->assertStringContainsString('section-a', $content);
    }

    public function test_skip_audit_reports_skipped_tests(): void
    {
        $trackingData = [
            'sections' => [
                'section-a' => [
                    'status' => 'passed',
                    'duration' => 5.0,
                    'results' => [
                        'tests' => 10, 'assertions' => 20,
                        'errors' => 0, 'failures' => 0,
                        'skipped' => 3, 'incomplete' => 1,
                    ],
                ],
            ],
            'totals' => [
                'tests' => 10, 'assertions' => 20, 'errors' => 0, 'failures' => 0,
                'warnings' => 0, 'skipped' => 3, 'incomplete' => 1, 'risky' => 0,
            ],
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'duration' => 5.0,
        ];

        file_put_contents(
            $this->logDirectory . '/execution_tracking.json',
            json_encode($trackingData, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        $writer = $this->app->make(MarkdownReportWriter::class);
        $writer->write($this->makeContext());

        $content = file_get_contents($this->logDirectory . '/skip-audit.md');
        $this->assertStringContainsString('Skip / Flake Audit', $content);
        $this->assertStringContainsString('section-a', $content);
    }

    public function test_respects_reports_disabled_config(): void
    {
        $this->writeTrackingFile();
        config()->set('parallel-test-runner.reports.enabled', false);

        $writer = $this->app->make(MarkdownReportWriter::class);
        $writer->write($this->makeContext());

        $this->assertFileDoesNotExist($this->logDirectory . '/run_report.json');
    }

    private function makeContext(): ReportContext
    {
        return new ReportContext(
            logDirectory: $this->logDirectory,
            successful: true,
            command: 'php artisan test:run-sections --parallel=2',
            summaryFile: $this->logDirectory . '/summary.json',
            extraOptions: [
                'workers_requested' => 2,
                'workers_started' => 2,
                'provision_mode' => 'sequential-migrate-fresh',
            ],
        );
    }

    private function writeTrackingFile(): void
    {
        $trackingData = [
            'sections' => [
                'section-a' => [
                    'status' => 'passed',
                    'duration' => 5.0,
                    'results' => [
                        'tests' => 10, 'assertions' => 20,
                        'errors' => 0, 'failures' => 0,
                        'skipped' => 0, 'incomplete' => 0,
                    ],
                ],
                'section-b' => [
                    'status' => 'passed',
                    'duration' => 3.0,
                    'results' => [
                        'tests' => 8, 'assertions' => 15,
                        'errors' => 0, 'failures' => 0,
                        'skipped' => 0, 'incomplete' => 0,
                    ],
                ],
            ],
            'totals' => [
                'tests' => 18, 'assertions' => 35, 'errors' => 0, 'failures' => 0,
                'warnings' => 0, 'skipped' => 0, 'incomplete' => 0, 'risky' => 0,
            ],
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'duration' => 8.0,
        ];

        file_put_contents(
            $this->logDirectory . '/execution_tracking.json',
            json_encode($trackingData, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }
}
