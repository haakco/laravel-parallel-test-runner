<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Contracts;

use Haakco\ParallelTestRunner\Data\CleanupContext;
use Haakco\ParallelTestRunner\Data\ProvisionContext;

interface DatabaseProvisionerInterface
{
    /**
     * Provision worker databases.
     *
     * @return array<int, string> Map of worker index to database name
     */
    public function provision(int $workerCount, ProvisionContext $context): array;

    /**
     * Clean up provisioned databases.
     */
    public function cleanup(CleanupContext $context): void;
}
