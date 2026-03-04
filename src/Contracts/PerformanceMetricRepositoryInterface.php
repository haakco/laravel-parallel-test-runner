<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Contracts;

interface PerformanceMetricRepositoryInterface
{
    /**
     * Get historical section weights.
     *
     * @return array<string, float> Section name to weight mapping
     */
    public function getHistoricalWeights(): array;

    /**
     * Record a section's execution weight.
     */
    public function recordWeight(string $sectionName, float $duration): void;
}
