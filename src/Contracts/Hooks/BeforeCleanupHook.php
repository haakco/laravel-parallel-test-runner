<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Contracts\Hooks;

use Haakco\ParallelTestRunner\Data\CleanupContext;

interface BeforeCleanupHook
{
    /**
     * Fires before database cleanup.
     */
    public function handle(CleanupContext $context): void;
}
