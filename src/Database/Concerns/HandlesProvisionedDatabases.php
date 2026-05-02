<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Database\Concerns;

use Haakco\ParallelTestRunner\Contracts\DatabaseSeederInterface;
use Haakco\ParallelTestRunner\Contracts\SchemaLoaderInterface;
use Haakco\ParallelTestRunner\Data\CleanupContext;
use Haakco\ParallelTestRunner\Data\ProvisionContext;
use Haakco\ParallelTestRunner\Support\DatabaseNamingStrategy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

trait HandlesProvisionedDatabases
{
    /** @var list<string> */
    private array $createdDatabases = [];

    public function __construct(
        private readonly SchemaLoaderInterface $schemaLoader,
        private readonly DatabaseSeederInterface $seeder,
    ) {}

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
     * @return array<int, string>
     */
    private function workerDatabases(int $workerCount, ProvisionContext $context): array
    {
        $namingStrategy = $this->buildNamingStrategy($context);
        $splitTotal = $context->extraOptions['split_total'] ?? null;
        $splitGroup = $context->extraOptions['split_group'] ?? null;
        $databases = [];

        for ($workerId = 1; $workerId <= $workerCount; $workerId++) {
            $databases[$workerId] = $splitTotal !== null && $splitGroup !== null
                ? $namingStrategy->forWorkerWithSplit($workerId, (int) $splitTotal, (int) $splitGroup)
                : $namingStrategy->forWorker($workerId);
        }

        return $databases;
    }

    private function createDatabase(string $dbName): void
    {
        $this->adminPdo()->exec(sprintf('CREATE DATABASE "%s"', $this->quoteDatabaseName($dbName)));
    }

    private function dropDatabase(string $dbName): void
    {
        $pdo = $this->adminPdo();

        if ((string) config('parallel-test-runner.database.drop_strategy', 'with_force') === 'terminate_then_drop') {
            $this->terminateConnections($pdo, $dbName);
            $pdo->exec(sprintf('DROP DATABASE IF EXISTS "%s"', $this->quoteDatabaseName($dbName)));

            return;
        }

        $pdo->exec(sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', $this->quoteDatabaseName($dbName)));
    }

    private function terminateConnections(mixed $pdo, string $dbName): void
    {
        $statement = $pdo->prepare(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :dbname AND pid <> pg_backend_pid()',
        );
        $statement->execute(['dbname' => $dbName]);
    }

    private function databaseExists(string $dbName): bool
    {
        $statement = $this->adminPdo()->prepare('SELECT 1 FROM pg_database WHERE datname = :dbname');
        $statement->execute(['dbname' => $dbName]);

        return $statement->fetchColumn() !== false;
    }

    private function migrateFreshDatabase(string $dbName, ProvisionContext $context): void
    {
        $this->withDatabaseConnection(
            $dbName,
            $context,
            function () use ($dbName, $context): void {
                if ($context->useSchemaLoad) {
                    $this->reportProgress($context, sprintf('Loading schema into %s', $dbName));
                    $this->schemaLoader->loadSchema($context->connection, $dbName);
                }

                $this->reportProgress($context, sprintf('Running migrate:fresh on %s', $dbName));
                $this->runMigrationCommand('migrate:fresh', $this->migrationArguments($context), $context);
            },
        );
    }

    private function ensureDatabaseExists(string $dbName, ProvisionContext $context): void
    {
        if ($this->databaseExists($dbName)) {
            $this->reportProgress($context, sprintf('Applying pending migrations to %s', $dbName));
            $this->ensureMigrationsUpToDate($dbName, $context);

            return;
        }

        $this->reportProgress($context, sprintf('Creating database %s', $dbName));
        $this->createDatabase($dbName);
        $this->migrateFreshDatabase($dbName, $context);
    }

    private function ensureMigrationsUpToDate(string $dbName, ProvisionContext $context): void
    {
        $this->withDatabaseConnection(
            $dbName,
            $context,
            function () use ($dbName, $context): void {
                $this->reportProgress($context, sprintf('Running migrate on %s', $dbName));
                $this->runMigrationCommand('migrate', $this->migrationArguments($context), $context);
            },
        );
    }

    private function withDatabaseConnection(string $dbName, ProvisionContext $context, callable $callback): void
    {
        $originalDb = config("database.connections.{$context->connection}.database");
        $originalDefault = config('database.default');

        try {
            config(["database.connections.{$context->connection}.database" => $dbName]);
            config(['database.default' => $context->connection]);

            DB::purge($context->connection);
            DB::reconnect($context->connection);

            $callback();
        } finally {
            DB::purge($context->connection);
            config(["database.connections.{$context->connection}.database" => $originalDb]);
            config(['database.default' => $originalDefault]);
        }
    }

    /**
     * @return array<string, bool|string>
     */
    private function migrationArguments(ProvisionContext $context): array
    {
        return [
            '--database' => $context->connection,
            '--force' => true,
            '--step' => true,
        ];
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
        if (! (bool) ($context->extraOptions['ignore_lock'] ?? false)) {
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
        $originalEnv === false ? putenv($key) : putenv(sprintf('%s=%s', $key, $originalEnv));

        $this->restoreSuperglobalValue($_SERVER, $key, $originalServer);
        $this->restoreSuperglobalValue($_ENV, $key, $originalGlobal);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function restoreSuperglobalValue(array &$values, string $key, mixed $originalValue): void
    {
        if ($originalValue === null) {
            unset($values[$key]);

            return;
        }

        $values[$key] = $originalValue;
    }

    private function buildNamingStrategy(ProvisionContext $context): DatabaseNamingStrategy
    {
        return new DatabaseNamingStrategy(
            baseName: $context->baseName,
            workerPattern: (string) config('parallel-test-runner.db_naming.pattern', '{base}_w{worker}'),
            splitPattern: (string) config('parallel-test-runner.db_naming.split_pattern', '{base}_s{total}g{group}_w{worker}'),
        );
    }

    private function reportProgress(ProvisionContext $context, string $message): void
    {
        $callback = $context->extraOptions['on_progress'] ?? null;

        if (is_callable($callback)) {
            $callback($message);
        }
    }

    private function adminPdo(): mixed
    {
        $adminConnection = (string) config('parallel-test-runner.database.admin_connection', 'pgsql');

        return DB::connection($adminConnection)->getPdo();
    }

    private function quoteDatabaseName(string $dbName): string
    {
        return str_replace('"', '""', $dbName);
    }
}
