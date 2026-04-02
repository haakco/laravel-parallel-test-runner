<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Reporting;

use Haakco\ParallelTestRunner\Data\ReportContext;
use Haakco\ParallelTestRunner\Reporting\ReportFormatter;
use Haakco\ParallelTestRunner\Reporting\RuntimeBaselineWriter;
use Haakco\ParallelTestRunner\Reporting\TrackingLoader;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Support\Facades\File;

final class RuntimeBaselineWriterTest extends TestCase
{
    private string $logDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDirectory = sys_get_temp_dir() . '/ptr-runtime-baseline-' . uniqid();
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

        $this->assertFileDoesNotExist($this->logDirectory . '/runtime-baselines.md');
    }

    public function test_write_generates_runtime_baseline_report(): void
    {
        $this->writeTrackingFile([
            'started_at' => '2026-04-02T13:00:00Z',
            'duration' => 12.4,
            'sections' => [
                'section-a' => [
                    'duration' => 7.3,
                    'results' => ['tests' => 15, 'assertions' => 30],
                ],
            ],
        ]);

        $writer = $this->makeWriter();
        $writer->write($this->makeContext());

        $content = file_get_contents($this->logDirectory . '/runtime-baselines.md');

        $this->assertStringContainsString('# Test Runtime Baselines', $content);
        $this->assertStringContainsString('_Last updated: 2026-04-02T13:00:00Z_', $content);
        $this->assertStringContainsString('| section-a | 7.30 | 15 | 30 |', $content);
    }

    public function test_write_uses_placeholder_row_when_no_sections_exist(): void
    {
        $this->writeTrackingFile([
            'started_at' => '2026-04-02T13:00:00Z',
            'duration' => 0.0,
            'sections' => [],
        ]);

        $writer = $this->makeWriter();
        $writer->write($this->makeContext());

        $content = file_get_contents($this->logDirectory . '/runtime-baselines.md');

        $this->assertStringContainsString('| _No section data available_ | 0 | 0 | 0 |', $content);
    }

    private function makeWriter(): RuntimeBaselineWriter
    {
        return new RuntimeBaselineWriter(
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
