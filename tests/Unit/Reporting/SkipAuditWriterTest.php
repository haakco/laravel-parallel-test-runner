<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Reporting;

use Haakco\ParallelTestRunner\Data\ReportContext;
use Haakco\ParallelTestRunner\Reporting\ReportFormatter;
use Haakco\ParallelTestRunner\Reporting\SkipAuditWriter;
use Haakco\ParallelTestRunner\Reporting\TrackingLoader;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Support\Facades\File;

final class SkipAuditWriterTest extends TestCase
{
    private string $logDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDirectory = sys_get_temp_dir() . '/ptr-skip-audit-' . uniqid();
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

        $this->assertFileDoesNotExist($this->logDirectory . '/skip-audit.md');
    }

    public function test_write_generates_skip_rows_for_skipped_and_incomplete_sections(): void
    {
        $this->writeTrackingFile([
            'started_at' => '2026-04-02T14:00:00Z',
            'sections' => [
                'section-a' => [
                    'duration' => 4.0,
                    'results' => ['skipped' => 2, 'incomplete' => 1],
                ],
                'section-b' => [
                    'duration' => 2.0,
                    'results' => ['skipped' => 0, 'incomplete' => 0],
                ],
            ],
        ]);

        $writer = $this->makeWriter();
        $writer->write($this->makeContext());

        $content = file_get_contents($this->logDirectory . '/skip-audit.md');

        $this->assertStringContainsString('| section-a | 2 | 1 |', $content);
        $this->assertStringNotContainsString('| section-b |', $content);
        $this->assertStringContainsString('Source: `', $content);
    }

    public function test_write_reports_clean_run_when_no_skips_exist(): void
    {
        $this->writeTrackingFile([
            'started_at' => '2026-04-02T14:00:00Z',
            'sections' => [
                'section-a' => [
                    'duration' => 4.0,
                    'results' => ['skipped' => 0, 'incomplete' => 0],
                ],
            ],
        ]);

        $writer = $this->makeWriter();
        $writer->write($this->makeContext());

        $content = file_get_contents($this->logDirectory . '/skip-audit.md');

        $this->assertStringContainsString('Latest run reported zero skipped or incomplete tests.', $content);
    }

    private function makeWriter(): SkipAuditWriter
    {
        return new SkipAuditWriter(
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
