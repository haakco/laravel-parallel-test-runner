<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Run;

use Spatie\LaravelData\Data;

final class TestRunCountersData extends Data
{
    public function __construct(
        public int $tests,
        public int $assertions,
        public int $errors,
        public int $failures,
        public int $warnings,
        public int $skipped,
        public int $incomplete,
        public int $risky,
    ) {}

    public static function zero(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0, 0);
    }

    public function hasFailures(): bool
    {
        return $this->errors > 0 || $this->failures > 0;
    }
}
