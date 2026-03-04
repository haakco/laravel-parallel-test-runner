<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Results;

use Haakco\ParallelTestRunner\Data\Parallel\MetricsTotalsData;
use Spatie\LaravelData\Data;

final class AggregatedResultData extends Data
{
    /**
     * @param list<WorkerResultData> $workerResults
     * @param list<string> $failedSections
     */
    public function __construct(
        public bool $success,
        public MetricsTotalsData $totals,
        public float $totalDuration,
        public array $workerResults,
        public array $failedSections,
        public int $sectionsCompleted,
        public int $sectionsTotal,
    ) {}
}
