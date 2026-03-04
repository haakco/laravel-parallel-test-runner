<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Contracts\Hooks;

use Haakco\ParallelTestRunner\Data\Results\AggregatedResultData;
use Haakco\ParallelTestRunner\Data\RunContext;

interface AfterRunHook
{
    /**
     * Fires after all results aggregated, before report writing.
     */
    public function handle(RunContext $context, AggregatedResultData $result): void;
}
