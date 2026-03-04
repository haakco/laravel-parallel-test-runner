<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Support;

/**
 * Generates database names for parallel test workers using configurable patterns.
 *
 * Placeholders:
 *   {base}   — base database name
 *   {worker} — worker index (1-based)
 *   {total}  — split-total count
 *   {group}  — split-group index
 */
final readonly class DatabaseNamingStrategy
{
    public function __construct(
        private string $baseName,
        private string $workerPattern,
        private string $splitPattern,
    ) {}

    /**
     * Generate a database name for a worker (no split groups).
     */
    public function forWorker(int $workerIndex): string
    {
        return str_replace(
            ['{base}', '{worker}'],
            [$this->baseName, (string) $workerIndex],
            $this->workerPattern,
        );
    }

    /**
     * Generate a database name for a worker within a split group.
     */
    public function forWorkerWithSplit(int $workerIndex, int $splitTotal, int $splitGroup): string
    {
        return str_replace(
            ['{base}', '{worker}', '{total}', '{group}'],
            [$this->baseName, (string) $workerIndex, (string) $splitTotal, (string) $splitGroup],
            $this->splitPattern,
        );
    }

    /**
     * Create a naming strategy from package config values.
     */
    public static function fromConfig(): self
    {
        return new self(
            baseName: (string) config('parallel-test-runner.database.base_name', 'app_test'),
            workerPattern: (string) config('parallel-test-runner.db_naming.pattern', '{base}_w{worker}'),
            splitPattern: (string) config('parallel-test-runner.db_naming.split_pattern', '{base}_s{total}g{group}_w{worker}'),
        );
    }
}
