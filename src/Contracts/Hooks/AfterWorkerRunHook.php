<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Contracts\Hooks;

use Haakco\ParallelTestRunner\Data\Results\WorkerResultData;
use Haakco\ParallelTestRunner\Data\WorkerContext;

interface AfterWorkerRunHook
{
    /**
     * Fires after a worker process completes.
     */
    public function handle(WorkerContext $context, WorkerResultData $result): void;
}
