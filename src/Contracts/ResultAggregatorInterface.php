<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Contracts;

use Haakco\ParallelTestRunner\Data\Results\AggregatedResultData;
use Haakco\ParallelTestRunner\Data\Results\WorkerResultData;

interface ResultAggregatorInterface
{
    /**
     * Aggregate results from multiple workers.
     *
     * @param list<WorkerResultData> $workerResults
     */
    public function aggregate(array $workerResults): AggregatedResultData;
}
