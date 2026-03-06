<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\TestingSupport\Performance;

use Illuminate\Support\Facades\Hash;

trait OptimizesHashingForTests
{
    protected function optimizeTestHashing(int $rounds = 4): void
    {
        Hash::setRounds($rounds);
    }
}
