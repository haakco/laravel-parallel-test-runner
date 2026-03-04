<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Reporting;

use Haakco\ParallelTestRunner\Data\ReportContext;
use Haakco\ParallelTestRunner\Reporting\JsonRunReportWriter;
use Haakco\ParallelTestRunner\Reporting\ReportFormatter;
use Haakco\ParallelTestRunner\Reporting\TrackingLoader;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Support\Facades\File;

final class JsonRunReportWriterTest extends TestCase
{
    private string $logDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logDirectory = sys_get_temp_dir() . '/ptr-report-test-' . uniqid();
        mkdir($this->logDirectory, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logDirectory);
        parent::tearDown();
    }

    public function test_generates_valid_json(): void
    {
        $this->writeTrackingFile($this->successTrackingData());

        $writer = $this->makeWriter();
        $context = $this->makeContext(successful: true);

        $writer->write($context);

        $reportPath = $this->logDirectory . '/run_report.json';
        $this->assertFileExists($reportPath);

        $report = json_decode(file_get_contents($reportPath), true);
        $this->assertIsArray($report);
    }

    public function test_includes_required_fields(): void
    {
        $this->writeTrackingFile($this->successTrackingData());

        $writer = $this->makeWriter();
        $writer->write($this->makeContext(successful: true));

        $report = $this->readReport();

        $requiredFields = [
            'schema_version',
            'runner_version',
            'run_id',
            'started_at',
            'finished_at',
            'duration_seconds',
            'command',
            'command_args',
            'options',
            'split',
            'parallel',
            'sections',
            'counters',
            'workers',
            'failures',
            'artifacts',
            'success',
        ];

        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $report, "Missing required field: {$field}");
        }
    }

    public function test_success_run_has_empty_failures(): void
    {
        $this->writeTrackingFile($this->successTrackingData());

        $writer = $this->makeWriter();
        $writer->write($this->makeContext(successful: true));

        $report = $this->readReport();

        $this->assertTrue($report['success']);
        $this->assertSame([], $report['failures']);
    }

    public function test_failed_run_includes_rerun_commands(): void
    {
        $trackingData = [
            'sections' => [
                'section-a' => [
                    'status' => 'passed',
                    'duration' => 5.0,
                    'results' => ['tests' => 10, 'assertions' => 20, 'errors' => 0, 'failures' => 0],
                ],
                'section-b' => [
                    'status' => 'failed',
                    'duration' => 3.0,
                    'worker_id' => 1,
                    'results' => ['tests' => 5, 'assertions' => 10, 'errors' => 1, 'failures' => 2],
                ],
            ],
            'totals' => [
                'tests' => 15, 'assertions' => 30, 'errors' => 1, 'failures' => 2,
                'warnings' => 0, 'skipped' => 0, 'incomplete' => 0, 'risky' => 0,
            ],
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'duration' => 8.0,
        ];

        $this->writeTrackingFile($trackingData);

        $writer = $this->makeWriter();
        $writer->write($this->makeContext(successful: false));

        $report = $this->readReport();

        $this->assertFalse($report['success']);
        $this->assertNotEmpty($report['failures']);
        $this->assertSame('section-b', $report['failures'][0]['section']);
        $this->assertArrayHasKey('rerun_command', $report['failures'][0]);
        $this->assertStringContainsString('section-b', $report['failures'][0]['rerun_command']);
    }

    public function test_no_secrets_in_output(): void
    {
        $this->writeTrackingFile($this->successTrackingData());

        $writer = $this->makeWriter();
        $writer->write($this->makeContext(successful: true));

        $reportContent = file_get_contents($this->logDirectory . '/run_report.json');

        // The env values for APP_KEY etc. should not appear in the report
        // In testing environment these may be empty, so this test verifies
        // the report doesn't contain any secrets
        $this->assertStringNotContainsString('APP_KEY=', $reportContent);
        $this->assertStringNotContainsString('DB_PASSWORD=', $reportContent);
    }

    public function test_worker_artifacts_include_log_directory(): void
    {
        $this->writeTrackingFile($this->successTrackingData());

        $context = $this->makeContext(
            successful: true,
            extraOptions: [
                'workers' => [
                    [
                        'worker_id' => 1,
                        'status' => 'passed',
                        'exit_code' => 0,
                        'database' => 'test_db_1',
                        'sections' => ['section-a'],
                        'duration_seconds' => 10.0,
                        'log_directory' => '/tmp/worker-1-logs',
                    ],
                ],
            ],
        );

        $writer = $this->makeWriter();
        $writer->write($context);

        $report = $this->readReport();

        $this->assertNotEmpty($report['workers']);
        $this->assertArrayHasKey('artifacts', $report['workers'][0]);
        $this->assertArrayHasKey('log_directory', $report['workers'][0]['artifacts']);
    }

    public function test_schema_version_is_v1(): void
    {
        $this->writeTrackingFile($this->successTrackingData());

        $writer = $this->makeWriter();
        $writer->write($this->makeContext(successful: true));

        $report = $this->readReport();
        $this->assertSame('v1', $report['schema_version']);
    }

    public function test_counters_include_all_fields(): void
    {
        $this->writeTrackingFile($this->successTrackingData());

        $writer = $this->makeWriter();
        $writer->write($this->makeContext(successful: true));

        $report = $this->readReport();
        $counters = $report['counters'];

        $this->assertArrayHasKey('tests', $counters);
        $this->assertArrayHasKey('assertions', $counters);
        $this->assertArrayHasKey('errors', $counters);
        $this->assertArrayHasKey('failures', $counters);
        $this->assertArrayHasKey('warnings', $counters);
        $this->assertArrayHasKey('skipped', $counters);
        $this->assertArrayHasKey('incomplete', $counters);
        $this->assertArrayHasKey('risky', $counters);
    }

    private function makeWriter(): JsonRunReportWriter
    {
        return new JsonRunReportWriter(
            formatter: new ReportFormatter(),
            trackingLoader: new TrackingLoader(),
        );
    }

    private function makeContext(bool $successful, array $extraOptions = []): ReportContext
    {
        return new ReportContext(
            logDirectory: $this->logDirectory,
            successful: $successful,
            command: 'php artisan test:run-sections --parallel=2',
            summaryFile: $this->logDirectory . '/summary.json',
            extraOptions: array_merge([
                'workers_requested' => 2,
                'workers_started' => 2,
                'provision_mode' => 'sequential-migrate-fresh',
            ], $extraOptions),
        );
    }

    private function writeTrackingFile(array $data): void
    {
        file_put_contents(
            $this->logDirectory . '/execution_tracking.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readReport(): array
    {
        return json_decode(
            file_get_contents($this->logDirectory . '/run_report.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function successTrackingData(): array
    {
        return [
            'sections' => [
                'section-a' => [
                    'status' => 'passed',
                    'duration' => 5.0,
                    'results' => [
                        'tests' => 10,
                        'assertions' => 20,
                        'errors' => 0,
                        'failures' => 0,
                    ],
                ],
            ],
            'totals' => [
                'tests' => 10,
                'assertions' => 20,
                'errors' => 0,
                'failures' => 0,
                'warnings' => 0,
                'skipped' => 0,
                'incomplete' => 0,
                'risky' => 0,
            ],
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'duration' => 5.0,
        ];
    }
}
