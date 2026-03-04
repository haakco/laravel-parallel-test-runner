<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Run;

use Spatie\LaravelData\Data;

final class TestSectionSummaryData extends Data
{
    public function __construct(
        public string $name,
        public string $status,
        public int $fileCount,
        public float $duration,
    ) {}
}
