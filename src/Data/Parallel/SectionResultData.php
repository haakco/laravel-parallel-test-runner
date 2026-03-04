<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Parallel;

use Spatie\LaravelData\Data;

final class SectionResultData extends Data
{
    public function __construct(
        public bool $success,
        public int $tests,
        public int $assertions,
        public int $errors,
        public int $failures,
        public int $skipped,
        public int $incomplete,
        public int $risky,
        public float $duration,
        public int $exitCode,
        public bool $timedOut,
        public string $logFile,
        public float $startedAt,
        public float $completedAt,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromTracking(string $sectionName, array $payload): self
    {
        $results = $payload['results'] ?? $payload;

        return new self(
            success: (bool) ($results['success'] ?? (($payload['status'] ?? '') === 'passed')),
            tests: (int) ($results['tests'] ?? 0),
            assertions: (int) ($results['assertions'] ?? 0),
            errors: (int) ($results['errors'] ?? 0),
            failures: (int) ($results['failures'] ?? 0),
            skipped: (int) ($results['skipped'] ?? 0),
            incomplete: (int) ($results['incomplete'] ?? 0),
            risky: (int) ($results['risky'] ?? 0),
            duration: (float) ($results['duration'] ?? $payload['duration'] ?? 0.0),
            exitCode: (int) ($results['exit_code'] ?? $payload['exit_code'] ?? 0),
            timedOut: (bool) ($results['timed_out'] ?? $payload['timed_out'] ?? false),
            logFile: (string) ($results['log_file'] ?? $payload['log_file'] ?? ''),
            startedAt: (float) ($payload['started_at'] ?? ($payload['startedAt'] ?? 0.0)),
            completedAt: (float) ($payload['completed_at'] ?? ($payload['completedAt'] ?? 0.0)),
        );
    }

    public static function createEmpty(): self
    {
        return new self(
            success: false,
            tests: 0,
            assertions: 0,
            errors: 0,
            failures: 0,
            skipped: 0,
            incomplete: 0,
            risky: 0,
            duration: 0.0,
            exitCode: 1,
            timedOut: false,
            logFile: '',
            startedAt: 0.0,
            completedAt: 0.0,
        );
    }

    /** @return array<string, mixed> */
    public function toTrackerArray(): array
    {
        return [
            'success' => $this->success, 'tests' => $this->tests,
            'assertions' => $this->assertions, 'errors' => $this->errors,
            'failures' => $this->failures, 'skipped' => $this->skipped,
            'incomplete' => $this->incomplete, 'risky' => $this->risky,
            'duration' => $this->duration, 'exit_code' => $this->exitCode,
            'timed_out' => $this->timedOut, 'log_file' => $this->logFile,
            'started_at' => $this->startedAt, 'completed_at' => $this->completedAt,
        ];
    }
}
