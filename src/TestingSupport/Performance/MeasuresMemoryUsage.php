<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\TestingSupport\Performance;

use Closure;

trait MeasuresMemoryUsage
{
    /**
     * @return array{before:int,after:int,delta:int,peak_delta:int}
     */
    protected function captureMemoryUsage(Closure $callback): array
    {
        gc_collect_cycles();

        $before = memory_get_usage(true);
        $peakBefore = memory_get_peak_usage(true);

        $callback();

        $after = memory_get_usage(true);
        $peakAfter = memory_get_peak_usage(true);

        return [
            'before' => $before,
            'after' => $after,
            'delta' => $after - $before,
            'peak_delta' => $peakAfter - $peakBefore,
        ];
    }

    protected function assertMemoryUsageBelow(Closure $callback, int $bytes): void
    {
        $usage = $this->captureMemoryUsage($callback);

        $this->assertLessThan(
            $bytes,
            $usage['delta'],
            sprintf('Expected memory delta < %s bytes, got %s.', number_format($bytes), number_format($usage['delta']))
        );
    }
}
