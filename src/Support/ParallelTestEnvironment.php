<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Support;

/**
 * Detects whether the current process is running as a parallel test worker
 * and provides access to worker-specific environment variables.
 */
final class ParallelTestEnvironment
{
    private static ?int $cachedWorkerId = null;

    private static bool $resolved = false;

    /**
     * Whether the current process is a worker spawned by the parallel runner.
     */
    public static function isWorkerProcess(): bool
    {
        return self::workerId() !== null;
    }

    /**
     * The numeric worker ID, or null if not running in parallel.
     *
     * Resolution order:
     * 1. TEST_WORKER_ID environment variable
     * 2. config('testing.worker_id')
     */
    public static function workerId(): ?int
    {
        if (self::$resolved) {
            return self::$cachedWorkerId;
        }

        self::$resolved = true;

        $envValue = getenv('TEST_WORKER_ID');
        if ($envValue !== false && $envValue !== '') {
            self::$cachedWorkerId = (int) $envValue;

            return self::$cachedWorkerId;
        }

        /** @var int|null $configValue */
        $configValue = config('testing.worker_id');
        if ($configValue !== null) {
            self::$cachedWorkerId = (int) $configValue;

            return self::$cachedWorkerId;
        }

        return null;
    }

    /**
     * Human-readable worker identifier: "worker_N" or "default".
     */
    public static function workerIdString(): string
    {
        $id = self::workerId();

        return $id !== null ? "worker_{$id}" : 'default';
    }

    /**
     * The worker-specific database name, or null if not set.
     */
    public static function database(): ?string
    {
        $value = getenv('DB_DATABASE');

        return ($value !== false && $value !== '') ? $value : null;
    }

    /**
     * The worker-specific log directory, or null if not set.
     */
    public static function logDirectory(): ?string
    {
        $value = getenv('TEST_LOG_DIR');

        return ($value !== false && $value !== '') ? $value : null;
    }

    /**
     * The test suite being run, or null if not set.
     */
    public static function suite(): ?string
    {
        $value = getenv('TEST_SUITE');

        return ($value !== false && $value !== '') ? $value : null;
    }

    /**
     * Whether individual-file mode is enabled for this worker.
     */
    public static function isIndividualMode(): bool
    {
        return getenv('TEST_INDIVIDUAL_MODE') === '1';
    }

    /**
     * Clear cached state. Call after changing environment variables in tests.
     */
    public static function reset(): void
    {
        self::$cachedWorkerId = null;
        self::$resolved = false;
    }
}
