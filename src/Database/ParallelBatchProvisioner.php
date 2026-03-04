<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Database;

use Haakco\ParallelTestRunner\Contracts\DatabaseProvisionerInterface;
use Haakco\ParallelTestRunner\Contracts\DatabaseSeederInterface;
use Haakco\ParallelTestRunner\Contracts\SchemaLoaderInterface;
use Haakco\ParallelTestRunner\Data\CleanupContext;
use Haakco\ParallelTestRunner\Data\ProvisionContext;
use Haakco\ParallelTestRunner\Data\SeedContext;
use Haakco\ParallelTestRunner\Support\DatabaseNamingStrategy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
    /** @var list<string> */
    private array $createdDatabases = [];

    public function __construct(
        private readonly SchemaLoaderInterface $schemaLoader,
        private readonly DatabaseSeederInterface $seeder,
    ) {}

    #[Override]
    public function provision(int $workerCount, ProvisionContext $context): array
    {
        if ($workerCount <= 0) {
            throw new RuntimeException('Worker count must be at least 1');
        }

        $namingStrategy = $this->buildNamingStrategy($context);
        $batchSize = (int) config('parallel-test-runner.parallel.db_provision_parallel', 4);
        $maxRetries = (int) config('parallel-test-runner.parallel.provision_max_retries', 3);
        $retryDelay = (int) config('parallel-test-runner.parallel.provision_retry_delay_seconds', 2);

        $splitTotal = $context->extraOptions['split_total'] ?? null;
        $splitGroup = $context->extraOptions['split_group'] ?? null;

        // Build the full list of database names
        $databases = [];
        for ($i = 1; $i <= $workerCount; $i++) {
            $dbName = $splitTotal !== null && $splitGroup !== null
                ? $namingStrategy->forWorkerWithSplit($i, (int) $splitTotal, (int) $splitGroup)
                : $namingStrategy->forWorker($i);

            $databases[$i] = $dbName;
        }

        // Provision in batches
        $workerIds = array_keys($databases);
        $batches = array_chunk($workerIds, $batchSize);

        foreach ($batches as $batch) {
            $this->provisionBatch($batch, $databases, $context, $maxRetries, $retryDelay);
        }

        return $databases;
    }

    #[Override]
    public function cleanup(CleanupContext $context): void
    {
        if ($context->keepDatabases) {
            return;
        }

        $databasesToClean = $context->databases !== [] ? $context->databases : $this->createdDatabases;

        foreach ($databasesToClean as $dbName) {
            $this->dropDatabase($dbName);
        }

        $this->createdDatabases = [];
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
    ): void {
        /** @var array<int, string|null> $failures Map of worker ID to last error */
        $failures = [];
        $pendingWorkers = $workerIds;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($pendingWorkers === []) {
                return;
            }

            if ($attempt > 0) {
                sleep($retryDelay);

                // Drop and recreate databases before retry
                foreach ($pendingWorkers as $workerId) {
                    $dbName = $databases[$workerId];
                    $this->dropDatabase($dbName);
                }
            }

            $stillFailed = [];

            foreach ($pendingWorkers as $workerId) {
                $dbName = $databases[$workerId];

                try {
                    $this->provisionSingleDatabase($dbName, $context, $workerId);
                    $this->createdDatabases[] = $dbName;
                } catch (Throwable $e) {
                    $failures[$workerId] = $e->getMessage();
                    $stillFailed[] = $workerId;
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
     */
    private function provisionSingleDatabase(string $dbName, ProvisionContext $context, int $workerId): void
    {
        $this->dropDatabase($dbName);
        $this->createDatabase($dbName);
        $this->migrateFreshDatabase($dbName, $context);
        $this->seedDatabase($dbName, $context, $workerId);
    }

    private function createDatabase(string $dbName): void
    {
        $adminConnection = (string) config('parallel-test-runner.database.admin_connection', 'pgsql');
        $pdo = DB::connection($adminConnection)->getPdo();
        $pdo->exec(sprintf('CREATE DATABASE "%s"', str_replace('"', '""', $dbName)));
    }

    private function dropDatabase(string $dbName): void
    {
        $adminConnection = (string) config('parallel-test-runner.database.admin_connection', 'pgsql');
        $dropStrategy = (string) config('parallel-test-runner.database.drop_strategy', 'with_force');
        $pdo = DB::connection($adminConnection)->getPdo();

        if ($dropStrategy === 'terminate_then_drop') {
            $statement = $pdo->prepare(
                'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :dbname AND pid <> pg_backend_pid()',
            );
            $statement->execute(['dbname' => $dbName]);
            $pdo->exec(sprintf('DROP DATABASE IF EXISTS "%s"', str_replace('"', '""', $dbName)));
        } else {
            $pdo->exec(sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', str_replace('"', '""', $dbName)));
        }
    }

    private function migrateFreshDatabase(string $dbName, ProvisionContext $context): void
    {
        $originalDb = config("database.connections.{$context->connection}.database");
        $originalDefault = config('database.default');

        try {
            config(["database.connections.{$context->connection}.database" => $dbName]);
            config(['database.default' => $context->connection]);

            DB::purge($context->connection);
            DB::reconnect($context->connection);

            if ($context->useSchemaLoad) {
                $this->schemaLoader->loadSchema($context->connection, $dbName);
            }

            Artisan::call('migrate:fresh', [
                '--database' => $context->connection,
                '--force' => true,
                '--step' => true,
            ]);
        } finally {
            DB::purge($context->connection);
            config(["database.connections.{$context->connection}.database" => $originalDb]);
            config(['database.default' => $originalDefault]);
        }
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

    private function buildNamingStrategy(ProvisionContext $context): DatabaseNamingStrategy
    {
        return new DatabaseNamingStrategy(
            baseName: $context->baseName,
            workerPattern: (string) config('parallel-test-runner.db_naming.pattern', '{base}_w{worker}'),
            splitPattern: (string) config('parallel-test-runner.db_naming.split_pattern', '{base}_s{total}g{group}_w{worker}'),
        );
    }
}
