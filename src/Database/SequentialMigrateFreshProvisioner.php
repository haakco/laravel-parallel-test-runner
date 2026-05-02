<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Database;

use Haakco\ParallelTestRunner\Contracts\DatabaseProvisionerInterface;
use Haakco\ParallelTestRunner\Data\ProvisionContext;
use Haakco\ParallelTestRunner\Data\SeedContext;
use Haakco\ParallelTestRunner\Database\Concerns\HandlesProvisionedDatabases;
use Override;
use RuntimeException;

/**
 * Provisions worker databases sequentially: for each worker, create DB -> load schema -> migrate:fresh -> seed.
 *
 * Ported from CB's ParallelDatabaseManager with config-driven naming and drop strategies.
 */
final class SequentialMigrateFreshProvisioner implements DatabaseProvisionerInterface
{
    use HandlesProvisionedDatabases;

    #[Override]
    public function provision(int $workerCount, ProvisionContext $context): array
    {
        if ($workerCount <= 0) {
            throw new RuntimeException('Worker count must be at least 1');
        }

        $databases = $this->workerDatabases($workerCount, $context);
        $noRefreshDb = (bool) ($context->extraOptions['no_refresh_db'] ?? false);

        $this->reportProgress(
            $context,
            sprintf(
                'Preparing %d worker %s sequentially',
                $workerCount,
                $workerCount === 1 ? 'database' : 'databases',
            ),
        );

        foreach ($databases as $i => $dbName) {
            $this->reportProgress(
                $context,
                sprintf('Provisioning database %s (worker %d/%d)', $dbName, $i, $workerCount),
            );

            if ($noRefreshDb) {
                $this->reportProgress($context, sprintf('Checking database %s', $dbName));
                $this->ensureDatabaseExists($dbName, $context);
            } else {
                $this->resetDatabase($dbName, $context);
            }

            $this->createdDatabases[] = $dbName;

            $this->seedDatabase($dbName, $context);
            $this->reportProgress($context, sprintf('Finished provisioning database %s', $dbName));
        }

        return $databases;
    }

    /**
     * Drop and recreate a database, then run migrate:fresh and seed.
     */
    private function resetDatabase(string $dbName, ProvisionContext $context): void
    {
        $this->reportProgress($context, sprintf('Dropping database %s', $dbName));
        $this->dropDatabase($dbName);
        $this->reportProgress($context, sprintf('Creating database %s', $dbName));
        $this->createDatabase($dbName);
        $this->migrateFreshDatabase($dbName, $context);
    }

    /**
     * Seed the provisioned database.
     */
    private function seedDatabase(string $dbName, ProvisionContext $context): void
    {
        $workerId = (int) array_search($dbName, $this->createdDatabases, true) + 1;

        $this->reportProgress($context, sprintf('Seeding database %s', $dbName));

        $seedContext = new SeedContext(
            connection: $context->connection,
            databaseName: $dbName,
            workerId: $workerId,
            extraOptions: $context->extraOptions,
        );

        $this->seeder->seed($seedContext);
    }
}
