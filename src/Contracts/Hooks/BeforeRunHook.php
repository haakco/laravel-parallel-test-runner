<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Contracts\Hooks;

use Haakco\ParallelTestRunner\Data\RunContext;

interface BeforeRunHook
{
    /**
     * Fires after env validation, before section execution.
     */
    public function handle(RunContext $context): void;
}
