<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Contracts\Hooks;

use Haakco\ParallelTestRunner\Data\WorkerContext;

interface BeforeWorkerRunHook
{
    /**
     * Fires before a worker process starts.
     */
    public function handle(WorkerContext $context): void;
}
