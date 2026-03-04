<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class TestProgressData extends Data
{
    public function __construct(
        public int $completed,
        public int $total,
        public float $percentage,
        public float $elapsedSeconds,
        public ?float $estimatedRemainingSeconds,
    ) {}
}
