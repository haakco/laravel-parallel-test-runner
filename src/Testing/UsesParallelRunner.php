<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Testing;

use Haakco\ParallelTestRunner\Support\ParallelTestEnvironment;

/**
 * Trait for test cases that need to integrate with the parallel test runner.
 *
 * Provides worker-aware helpers for database connections, storage isolation,
 * and migration lock control.
 */
trait UsesParallelRunner
{
    /**
     * Resolve the current test worker ID as a string.
     *
     * Returns "default" when not running in parallel mode.
     */
    protected function resolveTestWorkerId(): string
    {
        $id = ParallelTestEnvironment::workerId();

        return $id !== null ? (string) $id : 'default';
    }

    /**
     * Whether this test is running inside a parallel worker process.
     */
    protected function isParallelTestRun(): bool
    {
        return ParallelTestEnvironment::isWorkerProcess();
    }

    /**
     * Whether the migration lock should be skipped.
     *
     * In parallel mode each worker has its own database, so file-based
     * migration locks are unnecessary and would cause contention.
     */
    protected function shouldIgnoreMigrationLock(): bool
    {
        if (getenv('TEST_IGNORE_MIGRATION_LOCK') === '1') {
            return true;
        }

        return ParallelTestEnvironment::isWorkerProcess();
    }

    /**
     * Append a worker-specific suffix to a path for storage isolation.
     *
     * In parallel mode: "disks/local" becomes "disks/local_worker_3"
     * In standard mode: returns the path unchanged.
     */
    protected function workerScopedPath(string $basePath): string
    {
        $id = ParallelTestEnvironment::workerId();

        if ($id === null) {
            return $basePath;
        }

        return "{$basePath}_worker_{$id}";
    }
}
