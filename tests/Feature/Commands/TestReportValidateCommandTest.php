<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Feature\Commands;

use Haakco\ParallelTestRunner\Services\TestRunnerService;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Mockery;

final class TestReportValidateCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(TestRunnerService::class, Mockery::mock(TestRunnerService::class));

        $this->tempDir = sys_get_temp_dir() . '/parallel-test-runner-validate-' . uniqid();
        File::ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('list')
            ->expectsOutputToContain('test:report:validate');
    }

    public function test_validates_good_report(): void
    {
        $report = $this->createValidReport();
        $reportPath = $this->tempDir . '/run_report.json';
        File::put($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->artisan('test:report:validate', ['--report' => $reportPath])
            ->assertExitCode(0);
    }

    public function test_validates_good_report_with_pretty_output(): void
    {
        $report = $this->createValidReport();
        $reportPath = $this->tempDir . '/run_report.json';
        File::put($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->artisan('test:report:validate', ['--report' => $reportPath, '--pretty' => true])
            ->expectsOutputToContain('valid against schema')
            ->expectsOutputToContain('Run ID')
            ->assertExitCode(0);
    }

    public function test_rejects_missing_report_file(): void
    {
        $this->artisan('test:report:validate', ['--report' => '/nonexistent/path/report.json'])
            ->expectsOutputToContain('Report file not found')
            ->assertExitCode(1);
    }

    public function test_rejects_no_report_path(): void
    {
        $this->artisan('test:report:validate')
            ->assertExitCode(1);
    }

    public function test_rejects_malformed_json(): void
    {
        $reportPath = $this->tempDir . '/bad.json';
        File::put($reportPath, '{ not valid json }}}');

        $this->artisan('test:report:validate', ['--report' => $reportPath])
            ->expectsOutputToContain('Invalid JSON')
            ->assertExitCode(1);
    }

    public function test_rejects_missing_required_fields(): void
    {
        $reportPath = $this->tempDir . '/incomplete.json';
        File::put($reportPath, json_encode(['schema_version' => 'v1'], JSON_THROW_ON_ERROR));

        $this->artisan('test:report:validate', ['--report' => $reportPath])
            ->expectsOutputToContain('Missing required property')
            ->assertExitCode(1);
    }

    public function test_rejects_wrong_schema_version(): void
    {
        $report = $this->createValidReport();
        $report['schema_version'] = 'v99';
        $reportPath = $this->tempDir . '/wrong_version.json';
        File::put($reportPath, json_encode($report, JSON_THROW_ON_ERROR));

        $this->artisan('test:report:validate', ['--report' => $reportPath])
            ->expectsOutputToContain('Validation FAILED')
            ->assertExitCode(1);
    }

    public function test_rejects_invalid_schema_version_flag(): void
    {
        $report = $this->createValidReport();
        $reportPath = $this->tempDir . '/report.json';
        File::put($reportPath, json_encode($report, JSON_THROW_ON_ERROR));

        $this->artisan('test:report:validate', ['--report' => $reportPath, '--schema' => 'v99'])
            ->expectsOutputToContain('Schema not found')
            ->assertExitCode(1);
    }

    public function test_strict_artifacts_fails_when_paths_missing(): void
    {
        $report = $this->createValidReport();
        $report['artifacts']['log_directory'] = '/nonexistent/path/logs';
        $reportPath = $this->tempDir . '/report.json';
        File::put($reportPath, json_encode($report, JSON_THROW_ON_ERROR));

        $this->artisan('test:report:validate', [
            '--report' => $reportPath,
            '--strict-artifacts' => true,
        ])
            ->expectsOutputToContain('Artifact path does not exist')
            ->assertExitCode(1);
    }

    public function test_strict_artifacts_passes_with_existing_paths(): void
    {
        $logDir = $this->tempDir . '/test-logs';
        $workerLogDir = $this->tempDir . '/test-logs/worker01';
        File::ensureDirectoryExists($workerLogDir);

        $report = $this->createValidReport();
        $report['artifacts']['log_directory'] = $logDir;
        $report['workers'][0]['artifacts']['log_directory'] = $workerLogDir;

        $reportPath = $this->tempDir . '/report.json';
        File::put($reportPath, json_encode($report, JSON_THROW_ON_ERROR));

        $this->artisan('test:report:validate', [
            '--report' => $reportPath,
            '--strict-artifacts' => true,
        ])
            ->assertExitCode(0);
    }

    public function test_strict_artifacts_accepts_project_relative_paths(): void
    {
        $relativeLogDir = 'tmp/parallel-test-runner-relative-' . uniqid();
        $absoluteLogDir = base_path($relativeLogDir);
        $absoluteWorkerLogDir = $absoluteLogDir . '/worker01';
        File::ensureDirectoryExists($absoluteLogDir);
        File::ensureDirectoryExists($absoluteWorkerLogDir);

        $report = $this->createValidReport();
        $report['artifacts']['log_directory'] = $relativeLogDir;
        $report['workers'][0]['artifacts']['log_directory'] = $absoluteWorkerLogDir;

        $reportPath = $this->tempDir . '/relative-report.json';
        File::put($reportPath, json_encode($report, JSON_THROW_ON_ERROR));

        $this->artisan('test:report:validate', [
            '--report' => $reportPath,
            '--strict-artifacts' => true,
        ])
            ->assertExitCode(0);

        File::deleteDirectory($absoluteLogDir);
    }

    public function test_rejects_unexpected_properties(): void
    {
        $report = $this->createValidReport();
        $report['unexpected_field'] = 'should not be here';
        $reportPath = $this->tempDir . '/report.json';
        File::put($reportPath, json_encode($report, JSON_THROW_ON_ERROR));

        $this->artisan('test:report:validate', ['--report' => $reportPath])
            ->expectsOutputToContain('Unexpected property')
            ->assertExitCode(1);
    }

    /**
     * @return array<string, mixed>
     */
    private function createValidReport(): array
    {
        return [
            'schema_version' => 'v1',
            'runner_version' => '1.0.0',
            'run_id' => '20260304_114500',
            'started_at' => '2026-03-04T11:45:00Z',
            'finished_at' => '2026-03-04T11:48:25Z',
            'duration_seconds' => 205.41,
            'command' => 'php artisan test:run-sections',
            'command_args' => ['--individual', '--parallel=8'],
            'options' => ['individual' => true, 'parallel' => 8],
            'split' => null,
            'parallel' => [
                'workers_requested' => 1,
                'workers_started' => 1,
                'provision_mode' => 'sequential-migrate-fresh',
            ],
            'sections' => [
                'scheduled' => 2,
                'completed' => 2,
                'failed' => 0,
                'omitted' => 0,
            ],
            'counters' => [
                'tests' => 10,
                'assertions' => 20,
                'errors' => 0,
                'failures' => 0,
                'warnings' => 0,
                'skipped' => 0,
                'incomplete' => 0,
                'risky' => 0,
            ],
            'workers' => [
                [
                    'worker_id' => 1,
                    'status' => 'passed',
                    'exit_code' => 0,
                    'database' => 'test_db_w1',
                    'sections' => ['Unit/Models'],
                    'duration_seconds' => 5.0,
                    'artifacts' => [
                        'log_directory' => '/tmp/test-logs/worker01',
                    ],
                ],
            ],
            'performance' => [
                'section_duration_seconds' => 5.0,
                'wall_duration_seconds' => 5.5,
                'section_execution_window_seconds' => 5.0,
                'startup_overhead_seconds' => 0.5,
                'startup_overhead_ratio' => 0.0909,
                'top_sections' => [],
                'top_test_methods' => [],
            ],
            'failures' => [],
            'artifacts' => [
                'log_directory' => '/tmp/test-logs',
            ],
            'success' => true,
        ];
    }
}
