<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Services;

use Closure;
use Exception;
use Haakco\ParallelTestRunner\Data\Results\DatabaseRefreshResultData;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class TestDatabaseManagerService
{
    /**
     * @param Closure(string $message): void|null $onProgress
     */
    public function refreshTestDatabase(
        ?string $connection = null,
        ?string $database = null,
        ?Closure $onProgress = null
    ): DatabaseRefreshResultData {
        $dbConnection = $connection ?? (string) config('parallel-test-runner.database.connection', 'pgsql_testing');
        $dbName = $database ?? (string) config('parallel-test-runner.database.base_name', 'app_test');

        $onProgress ??= static fn(string $message): null => null;
        $onProgress(sprintf('Refreshing database: %s...', $dbName));

        $originalConnection = config('database.default');
        $originalDatabase = config("database.connections.{$dbConnection}.database");

        config(['database.default' => $dbConnection]);
        config(["database.connections.{$dbConnection}.database" => $dbName]);

        $startTime = microtime(true);

        try {
            Artisan::call('migrate:fresh', [
                '--database' => $dbConnection,
                '--step' => true,
            ]);

            $duration = microtime(true) - $startTime;
            $onProgress(sprintf('Database %s refreshed in %.2fs', $dbName, $duration));

            return DatabaseRefreshResultData::success($duration);
        } catch (Exception $exception) {
            Log::error('Failed to refresh test database', [
                'database' => $dbName,
                'connection' => $dbConnection,
                'error' => $exception->getMessage(),
            ]);

            $duration = microtime(true) - $startTime;

            return DatabaseRefreshResultData::failure($exception->getMessage(), $duration);
        } finally {
            config(['database.default' => $originalConnection]);
            if ($originalDatabase !== null) {
                config(["database.connections.{$dbConnection}.database" => $originalDatabase]);
            }
        }
    }

    public function checkMigrationStatus(?string $connection = null): bool
    {
        $dbConnection = $connection ?? (string) config('parallel-test-runner.database.connection', 'pgsql_testing');

        try {
            $exitCode = Artisan::call('migrate:status', [
                '--database' => $dbConnection,
            ]);

            return $exitCode === 0;
        } catch (Exception) {
            return false;
        }
    }
}
