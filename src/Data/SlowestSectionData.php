<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class SlowestSectionData extends Data
{
    public function __construct(
        public string $name,
        public float $duration,
        public int $tests,
        public int $fileCount,
    ) {}
}
