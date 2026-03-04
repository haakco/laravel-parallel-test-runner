<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Run;

use Spatie\LaravelData\Data;

final class TestSectionStatusCountData extends Data
{
    public function __construct(
        public int $passed,
        public int $failed,
        public int $skipped,
        public int $pending,
        public int $total,
    ) {}
}
