<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class SeedContext extends Data
{
    /**
     * @param array<string, mixed> $extraOptions
     */
    public function __construct(
        public string $connection,
        public string $databaseName,
        public int $workerId,
        public array $extraOptions,
    ) {}
}
