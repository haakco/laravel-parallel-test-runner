<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Results;

use Spatie\LaravelData\Data;

final class TestRunResultData extends Data
{
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
    ) {}

    public static function success(string $summary, float $duration, int $tests = 0, int $assertions = 0): self
    {
        return new self(true, 0, $summary, $duration, $tests, $assertions, 0, 0, 0);
    }

    public static function failure(string $summary, float $duration, int $exitCode = 1, int $errors = 0, int $failures = 0): self
    {
        return new self(false, $exitCode, $summary, $duration, 0, 0, $errors, $failures, 0);
    }
}
