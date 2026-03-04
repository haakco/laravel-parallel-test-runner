<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Contracts;

use Haakco\ParallelTestRunner\Data\SeedContext;

interface DatabaseSeederInterface
{
    /**
     * Seed a provisioned database. Called after migration for each worker DB.
     * Default implementation is a no-op.
     */
    public function seed(SeedContext $context): void;
}
