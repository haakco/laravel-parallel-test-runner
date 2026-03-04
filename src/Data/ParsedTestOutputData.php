<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class ParsedTestOutputData extends Data
{
    public function __construct(
        public int $tests,
        public int $assertions,
        public int $errors,
        public int $failures,
        public int $skipped,
        public int $incomplete,
        public int $risky,
        public int $warnings,
        public float $duration,
        public bool $success,
    ) {}

    public function isEmpty(): bool
    {
        return $this->tests === 0 && $this->assertions === 0;
    }
}
