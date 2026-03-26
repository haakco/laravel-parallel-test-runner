<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Results;

use Spatie\LaravelData\Data;

final class TestRunResultData extends Data
{
    /**
     * @param list<array{section: string, summary: string, rerun_command: string}> $failures
     */
    public function __construct(
        public bool $success,
        public int $exitCode,
        public string $summary,
        public float $duration,
        public int $totalTests,
        public int $totalAssertions,
        public int $totalErrors,
        public int $totalFailures,
        public int $totalSkipped,
        public string $logDirectory = '',
        public array $failures = [],
    ) {}

    public static function success(string $summary, float $duration, int $tests = 0, int $assertions = 0, string $logDirectory = ''): self
    {
        return new self(true, 0, $summary, $duration, $tests, $assertions, 0, 0, 0, $logDirectory);
    }

    /** @param list<array{section: string, summary: string, rerun_command: string}> $failureDetails */
    public static function failure(
        string $summary,
        float $duration,
        int $exitCode = 1,
        int $errors = 0,
        int $failures = 0,
        int $tests = 0,
        int $assertions = 0,
        int $skipped = 0,
        string $logDirectory = '',
        array $failureDetails = [],
    ): self {
        return new self(false, $exitCode, $summary, $duration, $tests, $assertions, $errors, $failures, $skipped, $logDirectory, $failureDetails);
    }
}
