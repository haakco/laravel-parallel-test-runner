<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Contracts\Hooks;

use Haakco\ParallelTestRunner\Data\ProvisionContext;

interface BeforeProvisionHook
{
    /**
     * Fires before database provisioning.
     */
    public function handle(ProvisionContext $context): void;
}
