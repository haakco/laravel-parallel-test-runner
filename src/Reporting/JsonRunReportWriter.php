<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Reporting;

use Haakco\ParallelTestRunner\Contracts\TestRunReportWriterInterface;
use Haakco\ParallelTestRunner\Data\ReportContext;
use Illuminate\Support\Facades\File;
use Override;
use RuntimeException;

final readonly class JsonRunReportWriter implements TestRunReportWriterInterface
{
    private const string SCHEMA_VERSION = 'v1';

    private const string RUNNER_VERSION = '1.0.0';

    /**
     * Sensitive environment variable names that must never appear in reports.
     *
     * @var list<string>
     */
    private const array SENSITIVE_KEYS = [
        'APP_KEY',
        'DB_PASSWORD',
        'DB_USERNAME',
        'REDIS_PASSWORD',
        'MAIL_PASSWORD',
        'AWS_SECRET_ACCESS_KEY',
        'PUSHER_APP_SECRET',
        'MIX_PUSHER_APP_KEY',
        'STRIPE_SECRET',
        'STRIPE_WEBHOOK_SECRET',
    ];

    public function __construct(
        private ReportFormatter $formatter,
        private TrackingLoader $trackingLoader,
    ) {}

    #[Override]
    public function write(ReportContext $context): void
    {
        $tracking = $this->trackingLoader->load($context->logDirectory);

        $report = $this->buildReport($context, $tracking);
        $this->assertNoSecrets($report);

        $reportPath = rtrim($context->logDirectory, DIRECTORY_SEPARATOR) . '/run_report.json';

        File::ensureDirectoryExists(dirname($reportPath));
        File::put($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed>|null $tracking
     * @return array<string, mixed>
     */
    private function buildReport(ReportContext $context, ?array $tracking): array
    {
        $startedAt = $tracking['started_at'] ?? now()->toIso8601String();
        $finishedAt = $tracking['updated_at'] ?? now()->toIso8601String();
        $duration = (float) ($tracking['duration'] ?? 0.0);
        $totals = $tracking['totals'] ?? [];
        $sections = $tracking['sections'] ?? [];

        $scheduledCount = count($sections);
        $completedCount = 0;
        $failedCount = 0;

        foreach ($sections as $section) {
            $status = $section['status'] ?? 'pending';
            if ($status === 'passed') {
                $completedCount++;
            } elseif ($status === 'failed') {
                $failedCount++;
                $completedCount++;
            }
        }

        $failures = $this->buildFailures($sections);
        $success = $failedCount === 0
            && ((int) ($totals['errors'] ?? 0)) === 0
            && ((int) ($totals['failures'] ?? 0)) === 0;

        $runId = date('Ymd_His');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'runner_version' => self::RUNNER_VERSION,
            'run_id' => $runId,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_seconds' => $duration,
            'command' => $context->command,
            'command_args' => $this->extractCommandArgs($context),
            'options' => $context->extraOptions,
            'split' => $context->extraOptions['split'] ?? null,
            'parallel' => [
                'workers_requested' => (int) ($context->extraOptions['workers_requested'] ?? 1),
                'workers_started' => (int) ($context->extraOptions['workers_started'] ?? 1),
                'provision_mode' => (string) ($context->extraOptions['provision_mode'] ?? 'sequential-migrate-fresh'),
            ],
            'sections' => [
                'scheduled' => $scheduledCount,
                'completed' => $completedCount,
                'failed' => $failedCount,
                'omitted' => max(0, $scheduledCount - $completedCount),
            ],
            'counters' => [
                'tests' => (int) ($totals['tests'] ?? 0),
                'assertions' => (int) ($totals['assertions'] ?? 0),
                'errors' => (int) ($totals['errors'] ?? 0),
                'failures' => (int) ($totals['failures'] ?? 0),
                'warnings' => (int) ($totals['warnings'] ?? 0),
                'skipped' => (int) ($totals['skipped'] ?? 0),
                'incomplete' => (int) ($totals['incomplete'] ?? 0),
                'risky' => (int) ($totals['risky'] ?? 0),
            ],
            'workers' => $this->buildWorkers($context),
            'failures' => $failures,
            'artifacts' => [
                'log_directory' => $this->formatter->relativePath($context->logDirectory),
            ],
            'success' => $success,
        ];
    }

    /**
     * @return list<string>
     */
    private function extractCommandArgs(ReportContext $context): array
    {
        $parts = explode(' ', $context->command);

        // Return everything after the command name
        return array_slice($parts, 3);
    }

    /**
     * @param array<string, mixed> $sections
     * @return list<array{section: string, worker_id: int, summary: string, rerun_command: string}>
     */
    private function buildFailures(array $sections): array
    {
        $failures = [];

        foreach ($sections as $name => $section) {
            if (($section['status'] ?? '') !== 'failed') {
                continue;
            }

            $results = $section['results'] ?? [];
            $errors = (int) ($results['errors'] ?? 0);
            $failureCount = (int) ($results['failures'] ?? 0);

            $failures[] = [
                'section' => (string) $name,
                'worker_id' => (int) ($section['worker_id'] ?? 1),
                'summary' => sprintf('E:%d F:%d', $errors, $failureCount),
                'rerun_command' => sprintf(
                    'php artisan test:run-sections --section=%s --individual --parallel=1 --fail-fast',
                    escapeshellarg((string) $name),
                ),
            ];
        }

        return $failures;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildWorkers(ReportContext $context): array
    {
        $workers = $context->extraOptions['workers'] ?? [];

        if (! is_array($workers)) {
            return [];
        }

        return array_values(array_map(fn(array $worker): array => [
            'worker_id' => (int) ($worker['worker_id'] ?? 1),
            'status' => (string) ($worker['status'] ?? 'passed'),
            'exit_code' => $worker['exit_code'] ?? 0,
            'database' => (string) ($worker['database'] ?? ''),
            'sections' => (array) ($worker['sections'] ?? []),
            'duration_seconds' => (float) ($worker['duration_seconds'] ?? 0.0),
            'error_summary' => $worker['error_summary'] ?? null,
            'artifacts' => [
                'log_directory' => (string) ($worker['log_directory'] ?? ''),
            ],
        ], $workers));
    }

    /**
     * Ensure no sensitive environment values leaked into the report.
     *
     * @param array<string, mixed> $report
     */
    private function assertNoSecrets(array $report): void
    {
        $json = json_encode($report, JSON_THROW_ON_ERROR);

        foreach (self::SENSITIVE_KEYS as $key) {
            $value = env($key);
            if (is_string($value) && $value !== '' && str_contains($json, $value)) {
                throw new RuntimeException(
                    "Report contains sensitive value for {$key}. Aborting report write."
                );
            }
        }
    }
}
