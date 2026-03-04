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
use PDO;
use RuntimeException;

/**
 * Provisions worker databases sequentially: for each worker, create DB -> load schema -> migrate:fresh -> seed.
 *
 * Ported from CB's ParallelDatabaseManager with config-driven naming and drop strategies.
 */
final class SequentialMigrateFreshProvisioner implements DatabaseProvisionerInterface
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

        $splitTotal = $context->extraOptions['split_total'] ?? null;
        $splitGroup = $context->extraOptions['split_group'] ?? null;

        $databases = [];

        for ($i = 1; $i <= $workerCount; $i++) {
            $dbName = $splitTotal !== null && $splitGroup !== null
                ? $namingStrategy->forWorkerWithSplit($i, (int) $splitTotal, (int) $splitGroup)
                : $namingStrategy->forWorker($i);

            $this->resetDatabase($dbName, $context);

            $databases[$i] = $dbName;
            $this->createdDatabases[] = $dbName;
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
     * Drop and recreate a database, then run migrate:fresh and seed.
     */
    private function resetDatabase(string $dbName, ProvisionContext $context): void
    {
        $this->dropDatabase($dbName);
        $this->createDatabase($dbName);
        $this->migrateFreshDatabase($dbName, $context);
        $this->seedDatabase($dbName, $context);
    }

    /**
     * Create a database on the admin connection.
     */
    private function createDatabase(string $dbName): void
    {
        $adminConnection = (string) config('parallel-test-runner.database.admin_connection', 'pgsql');
        $pdo = DB::connection($adminConnection)->getPdo();
        $pdo->exec(sprintf('CREATE DATABASE "%s"', str_replace('"', '""', $dbName)));
    }

    /**
     * Drop a database using the configured drop strategy.
     */
    private function dropDatabase(string $dbName): void
    {
        $adminConnection = (string) config('parallel-test-runner.database.admin_connection', 'pgsql');
        $dropStrategy = (string) config('parallel-test-runner.database.drop_strategy', 'with_force');
        $pdo = DB::connection($adminConnection)->getPdo();

        if ($dropStrategy === 'terminate_then_drop') {
            $this->terminateConnections($pdo, $dbName);
            $pdo->exec(sprintf('DROP DATABASE IF EXISTS "%s"', str_replace('"', '""', $dbName)));
        } else {
            // with_force strategy
            $pdo->exec(sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', str_replace('"', '""', $dbName)));
        }
    }

    /**
     * Terminate all active connections to a database.
     *
     * @param PDO $pdo
     */
    private function terminateConnections($pdo, string $dbName): void
    {
        $statement = $pdo->prepare(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :dbname AND pid <> pg_backend_pid()',
        );
        $statement->execute(['dbname' => $dbName]);
    }

    /**
     * Run migrate:fresh on the provisioned database.
     */
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

    /**
     * Seed the provisioned database.
     */
    private function seedDatabase(string $dbName, ProvisionContext $context): void
    {
        $workerId = (int) array_search($dbName, $this->createdDatabases, true) + 1;

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
