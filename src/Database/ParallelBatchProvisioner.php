<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Database;

use Haakco\ParallelTestRunner\Contracts\DatabaseProvisionerInterface;
use Haakco\ParallelTestRunner\Data\ProvisionContext;
use Haakco\ParallelTestRunner\Data\SeedContext;
use Haakco\ParallelTestRunner\Database\Concerns\HandlesProvisionedDatabases;
use Override;
use RuntimeException;
use Throwable;

/**
 * Provisions worker databases in parallel batches with retry logic.
 *
 * TL-style implementation: runs provisioning in configurable batch sizes using
 * Symfony Process, with automatic retries on failure (drop + recreate + retry).
 */
final class ParallelBatchProvisioner implements DatabaseProvisionerInterface
{
    use HandlesProvisionedDatabases;

    #[Override]
    public function provision(int $workerCount, ProvisionContext $context): array
    {
        if ($workerCount <= 0) {
            throw new RuntimeException('Worker count must be at least 1');
        }

        $batchSize = (int) config('parallel-test-runner.parallel.db_provision_parallel', 4);
        $maxRetries = (int) config('parallel-test-runner.parallel.provision_max_retries', 3);
        $retryDelay = (int) config('parallel-test-runner.parallel.provision_retry_delay_seconds', 2);

        $databases = $this->workerDatabases($workerCount, $context);
        $workerIds = array_keys($databases);
        $batches = array_chunk($workerIds, $batchSize);

        $this->reportProgress(
            $context,
            sprintf(
                'Preparing %d worker %s in batches of %d',
                $workerCount,
                $workerCount === 1 ? 'database' : 'databases',
                $batchSize,
            ),
        );

        foreach ($batches as $batchIndex => $batch) {
            $this->provisionBatch(
                $batch,
                $databases,
                $context,
                $maxRetries,
                $retryDelay,
                $batchIndex + 1,
                count($batches),
            );
        }

        return $databases;
    }

    /**
     * Provision a batch of workers with retry logic.
     *
     * @param list<int> $workerIds
     * @param array<int, string> $databases
     */
    private function provisionBatch(
        array $workerIds,
        array $databases,
        ProvisionContext $context,
        int $maxRetries,
        int $retryDelay,
        int $batchNumber,
        int $batchCount,
    ): void {
        /** @var array<int, string|null> $failures Map of worker ID to last error */
        $failures = [];
        $pendingWorkers = $workerIds;

        $this->reportProgress(
            $context,
            sprintf(
                'Starting database provision batch %d/%d for workers %s',
                $batchNumber,
                $batchCount,
                implode(', ', $workerIds),
            ),
        );

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($pendingWorkers === []) {
                return;
            }

            if ($attempt > 0) {
                sleep($retryDelay);

                $noRefreshDb = (bool) ($context->extraOptions['no_refresh_db'] ?? false);

                // Drop databases before retry only when not in no-refresh mode
                if (! $noRefreshDb) {
                    foreach ($pendingWorkers as $workerId) {
                        $dbName = $databases[$workerId];
                        $this->reportProgress($context, sprintf('Dropping database %s before retry', $dbName));
                        $this->dropDatabase($dbName);
                    }
                }
            }

            $stillFailed = [];

            foreach ($pendingWorkers as $workerId) {
                $dbName = $databases[$workerId];

                try {
                    $this->reportProgress(
                        $context,
                        sprintf(
                            'Provisioning database %s (worker %d, attempt %d/%d)',
                            $dbName,
                            $workerId,
                            $attempt + 1,
                            $maxRetries + 1,
                        ),
                    );
                    $this->provisionSingleDatabase($dbName, $context, $workerId);
                    $this->createdDatabases[] = $dbName;
                } catch (Throwable $e) {
                    $failures[$workerId] = $e->getMessage();
                    $stillFailed[] = $workerId;
                    $this->reportProgress(
                        $context,
                        sprintf(
                            'Provisioning failed for %s (worker %d): %s',
                            $dbName,
                            $workerId,
                            $e->getMessage(),
                        ),
                    );
                }
            }

            $pendingWorkers = $stillFailed;
        }

        if ($pendingWorkers !== []) {
            $firstFailedWorker = $pendingWorkers[0];
            $firstError = $failures[$firstFailedWorker] ?? 'Unknown error';

            throw new RuntimeException(
                sprintf(
                    'Failed to provision %d database(s) after %d retries. First error (worker %d, db "%s"): %s',
                    count($pendingWorkers),
                    $maxRetries,
                    $firstFailedWorker,
                    $databases[$firstFailedWorker],
                    $firstError,
                ),
            );
        }
    }

    /**
     * Provision a single database: create -> schema -> migrate:fresh -> seed.
     *
     * When no_refresh_db is true, reuse existing databases and only apply pending migrations.
     */
    private function provisionSingleDatabase(string $dbName, ProvisionContext $context, int $workerId): void
    {
        $noRefreshDb = (bool) ($context->extraOptions['no_refresh_db'] ?? false);

        if ($noRefreshDb) {
            $this->reportProgress($context, sprintf('Checking database %s', $dbName));
            $this->ensureDatabaseExists($dbName, $context);
        } else {
            $this->reportProgress($context, sprintf('Dropping database %s', $dbName));
            $this->dropDatabase($dbName);
            $this->reportProgress($context, sprintf('Creating database %s', $dbName));
            $this->createDatabase($dbName);
            $this->migrateFreshDatabase($dbName, $context);
        }

        $this->reportProgress($context, sprintf('Seeding database %s', $dbName));
        $this->seedDatabase($dbName, $context, $workerId);
        $this->reportProgress($context, sprintf('Finished provisioning database %s', $dbName));
    }

    private function seedDatabase(string $dbName, ProvisionContext $context, int $workerId): void
    {
        $seedContext = new SeedContext(
            connection: $context->connection,
            databaseName: $dbName,
            workerId: $workerId,
            extraOptions: $context->extraOptions,
        );

        $this->seeder->seed($seedContext);
    }
}
