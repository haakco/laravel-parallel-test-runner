<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class TestEtaData extends Data
{
    public function __construct(
        public float $estimatedTotalSeconds,
        public float $elapsedSeconds,
        public float $remainingSeconds,
        public float $percentComplete,
    ) {}
}
