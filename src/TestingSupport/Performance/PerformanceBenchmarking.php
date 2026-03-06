<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\TestingSupport\Performance;

use Closure;

trait PerformanceBenchmarking
{
    /**
     * @return array{label:string,iterations:int,durations:array<int,float>,min:float,max:float,avg:float}
     */
    protected function benchmark(string $label, Closure $callback, int $iterations = 1): array
    {
        $durations = [];

        foreach (range(1, $iterations) as $iteration) {
            $start = microtime(true);
            $callback();
            $durations[] = microtime(true) - $start;
        }

        return [
            'label' => $label,
            'iterations' => $iterations,
            'durations' => $durations,
            'min' => min($durations),
            'max' => max($durations),
            'avg' => array_sum($durations) / count($durations),
        ];
    }

    protected function assertBenchmarkUnder(string $label, Closure $callback, float $thresholdSeconds, int $iterations = 1): void
    {
        $result = $this->benchmark($label, $callback, $iterations);

        $this->assertLessThan(
            $thresholdSeconds,
            $result['avg'],
            sprintf('Benchmark `%s` exceeded threshold of %.4fs (avg %.4fs).', $label, $thresholdSeconds, $result['avg'])
        );
    }
}
