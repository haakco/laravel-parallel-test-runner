<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\TestingSupport\Performance;

use Haakco\ParallelTestRunner\TestingSupport\Performance\PerformanceBenchmarking;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PerformanceBenchmarkingTest extends TestCase
{
    #[Test]
    public function it_benchmarks_multiple_iterations(): void
    {
        $helper = new class ('helper') extends TestCase {
            use PerformanceBenchmarking;

            public function runBenchmark(string $label, callable $callback, int $iterations): array
            {
                return $this->benchmark($label, $callback(...), $iterations);
            }
        };

        $result = $helper->runBenchmark('noop', static function (): void {}, 3);

        $this->assertSame('noop', $result['label']);
        $this->assertSame(3, $result['iterations']);
        $this->assertCount(3, $result['durations']);
        $this->assertIsFloat($result['avg']);
    }
}
