<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Services;

use Haakco\ParallelTestRunner\Contracts\ResultAggregatorInterface;
use Haakco\ParallelTestRunner\Data\Parallel\MetricsTotalsData;
use Haakco\ParallelTestRunner\Data\Results\AggregatedResultData;
use Haakco\ParallelTestRunner\Data\Results\WorkerResultData;
use Override;

final class JsonFileResultAggregator implements ResultAggregatorInterface
{
    /**
     * Aggregate results from multiple workers into a single summary.
     *
     * @param list<WorkerResultData> $workerResults
     */
    #[Override]
    public function aggregate(array $workerResults): AggregatedResultData
    {
        if ($workerResults === []) {
            return new AggregatedResultData(
                success: true,
                totals: MetricsTotalsData::fromArray([]),
                totalDuration: 0.0,
                workerResults: [],
                failedSections: [],
                sectionsCompleted: 0,
                sectionsTotal: 0,
            );
        }

        $combinedTotals = MetricsTotalsData::fromArray([]);
        $totalDuration = 0.0;
        $allSuccess = true;
        $failedSections = [];
        $sectionsCompleted = 0;
        $sectionsTotal = 0;

        foreach ($workerResults as $result) {
            $combinedTotals = $combinedTotals->accumulate($result->totals);
            $totalDuration = max($totalDuration, $result->duration);
            $sectionsTotal += count($result->sections);

            if ($result->success) {
                $sectionsCompleted += count($result->sections);
            } else {
                $allSuccess = false;

                foreach ($result->sections as $section) {
                    $failedSections[] = $section;
                }
            }
        }

        return new AggregatedResultData(
            success: $allSuccess,
            totals: $combinedTotals,
            totalDuration: $totalDuration,
            workerResults: $workerResults,
            failedSections: $failedSections,
            sectionsCompleted: $sectionsCompleted,
            sectionsTotal: $sectionsTotal,
        );
    }
}
