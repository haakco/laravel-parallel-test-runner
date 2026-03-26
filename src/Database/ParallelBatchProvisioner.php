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

                $noRefreshDb = (bool) ($context->extraOptions['no_refresh_db'] ?? false);

                // Drop databases before retry only when not in no-refresh mode
                if (! $noRefreshDb) {
                    foreach ($pendingWorkers as $workerId) {
                        $dbName = $databases[$workerId];
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
            $this->ensureDatabaseExists($dbName, $context);
        } else {
            $this->dropDatabase($dbName);
            $this->createDatabase($dbName);
            $this->migrateFreshDatabase($dbName, $context);
        }

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

            $this->runMigrationCommand(
                'migrate:fresh',
                [
                    '--database' => $context->connection,
                    '--force' => true,
                    '--step' => true,
                ],
                $context,
            );
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

    /**
     * Check if a database exists on the admin connection.
     */
    private function databaseExists(string $dbName): bool
    {
        $adminConnection = (string) config('parallel-test-runner.database.admin_connection', 'pgsql');
        $pdo = DB::connection($adminConnection)->getPdo();
        $statement = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :dbname');
        $statement->execute(['dbname' => $dbName]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Ensure a database exists. If it does, apply pending migrations; if not, create and migrate:fresh.
     */
    private function ensureDatabaseExists(string $dbName, ProvisionContext $context): void
    {
        if ($this->databaseExists($dbName)) {
            $this->ensureMigrationsUpToDate($dbName, $context);
        } else {
            $this->createDatabase($dbName);
            $this->migrateFreshDatabase($dbName, $context);
        }
    }

    /**
     * Apply only pending migrations to an existing database (no drop/recreate).
     */
    private function ensureMigrationsUpToDate(string $dbName, ProvisionContext $context): void
    {
        $originalDb = config("database.connections.{$context->connection}.database");
        $originalDefault = config('database.default');

        try {
            config(["database.connections.{$context->connection}.database" => $dbName]);
            config(['database.default' => $context->connection]);

            DB::purge($context->connection);
            DB::reconnect($context->connection);

            $this->runMigrationCommand(
                'migrate',
                [
                    '--database' => $context->connection,
                    '--force' => true,
                    '--step' => true,
                ],
                $context,
            );
        } finally {
            DB::purge($context->connection);
            config(["database.connections.{$context->connection}.database" => $originalDb]);
            config(['database.default' => $originalDefault]);
        }
    }

    private function buildNamingStrategy(ProvisionContext $context): DatabaseNamingStrategy
    {
        return new DatabaseNamingStrategy(
            baseName: $context->baseName,
            workerPattern: (string) config('parallel-test-runner.db_naming.pattern', '{base}_w{worker}'),
            splitPattern: (string) config('parallel-test-runner.db_naming.split_pattern', '{base}_s{total}g{group}_w{worker}'),
        );
    }

    /**
     * @param array<string, bool|string> $arguments
     */
    private function runMigrationCommand(string $command, array $arguments, ProvisionContext $context): void
    {
        $this->withMigrationLockOverride(
            $context,
            static fn(): int => Artisan::call($command, $arguments),
        );
    }

    private function withMigrationLockOverride(ProvisionContext $context, callable $callback): void
    {
        $ignoreLock = (bool) ($context->extraOptions['ignore_lock'] ?? false);

        if (! $ignoreLock) {
            $callback();

            return;
        }

        $originalEnv = getenv('TEST_IGNORE_MIGRATION_LOCK');
        $originalServer = $_SERVER['TEST_IGNORE_MIGRATION_LOCK'] ?? null;
        $originalGlobal = $_ENV['TEST_IGNORE_MIGRATION_LOCK'] ?? null;

        putenv('TEST_IGNORE_MIGRATION_LOCK=1');
        $_SERVER['TEST_IGNORE_MIGRATION_LOCK'] = '1';
        $_ENV['TEST_IGNORE_MIGRATION_LOCK'] = '1';

        try {
            $callback();
        } finally {
            $this->restoreEnvValue('TEST_IGNORE_MIGRATION_LOCK', $originalEnv, $originalServer, $originalGlobal);
        }
    }

    private function restoreEnvValue(
        string $key,
        string|false $originalEnv,
        mixed $originalServer,
        mixed $originalGlobal,
    ): void {
        if ($originalEnv === false) {
            putenv($key);
        } else {
            putenv(sprintf('%s=%s', $key, $originalEnv));
        }

        if ($originalServer === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $originalServer;
        }

        if ($originalGlobal === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $originalGlobal;
        }
    }

    private function reportProgress(ProvisionContext $context, string $message): void
    {
        $callback = $context->extraOptions['on_progress'] ?? null;

        if (is_callable($callback)) {
            $callback($message);
        }
    }
}
