<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\TestingSupport\Performance;

use Haakco\ParallelTestRunner\TestingSupport\Performance\OptimizesHashingForTests;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;

final class OptimizesHashingForTestsTest extends TestCase
{
    #[Test]
    public function it_reduces_hash_rounds_for_tests(): void
    {
        $helper = new class ('helper') extends TestCase {
            use OptimizesHashingForTests;

            public function optimize(int $rounds): void
            {
                $this->optimizeTestHashing($rounds);
            }
        };

        $helper->optimize(5);

        $hashInfo = Hash::driver('bcrypt')->info(Hash::make('password'));

        $this->assertSame(5, $hashInfo['options']['cost'] ?? null);
    }
}
