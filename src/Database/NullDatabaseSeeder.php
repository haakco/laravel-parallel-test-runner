<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Database;

use Haakco\ParallelTestRunner\Contracts\DatabaseSeederInterface;
use Haakco\ParallelTestRunner\Data\SeedContext;

final class NullDatabaseSeeder implements DatabaseSeederInterface
{
    public function seed(SeedContext $context): void
    {
        // No-op. Projects override this to seed worker databases.
    }
}
