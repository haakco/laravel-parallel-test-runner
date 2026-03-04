<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Run;

use Spatie\LaravelData\Data;

final class TestSectionResultData extends Data
{
    public function __construct(
        public string $name,
        public string $status,
        public int $tests,
        public int $assertions,
        public int $errors,
        public int $failures,
        public float $duration,
        public int $exitCode,
        public bool $timedOut,
    ) {}
}
