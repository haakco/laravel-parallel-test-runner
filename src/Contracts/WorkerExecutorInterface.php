<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Contracts;

use Haakco\ParallelTestRunner\Data\Parallel\WorkerPlanData;
use Haakco\ParallelTestRunner\Data\Results\WorkerResultData;

interface WorkerExecutorInterface
{
    /**
     * Execute a worker plan and return the result.
     */
    public function execute(WorkerPlanData $plan): WorkerResultData;
}
