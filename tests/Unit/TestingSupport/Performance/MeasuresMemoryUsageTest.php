<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\TestingSupport\Performance;

use Haakco\ParallelTestRunner\TestingSupport\Performance\MeasuresMemoryUsage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MeasuresMemoryUsageTest extends TestCase
{
    #[Test]
    public function it_captures_memory_usage_metrics(): void
    {
        $helper = new class ('helper') extends TestCase {
            use MeasuresMemoryUsage;

            public function capture(callable $callback): array
            {
                return $this->captureMemoryUsage($callback(...));
            }
        };

        $result = $helper->capture(static fn(): array => range(1, 1000));

        $this->assertArrayHasKey('before', $result);
        $this->assertArrayHasKey('after', $result);
        $this->assertArrayHasKey('delta', $result);
        $this->assertArrayHasKey('peak_delta', $result);
    }
}
