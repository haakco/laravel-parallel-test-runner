<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Contracts\Hooks;

use Haakco\ParallelTestRunner\Data\ProvisionContext;

interface AfterProvisionHook
{
    /**
     * Fires after database provisioning completes.
     *
     * @param array<int, string> $databases Map of worker index to database name
     */
    public function handle(ProvisionContext $context, array $databases): void;
}
