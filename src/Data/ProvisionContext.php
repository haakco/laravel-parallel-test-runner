<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class ProvisionContext extends Data
{
    /**
     * @param array<string, mixed> $extraOptions
     */
    public function __construct(
        public string $connection,
        public string $baseName,
        public int $workerCount,
        public bool $useSchemaLoad,
        public string $dropStrategy,
        public array $extraOptions,
    ) {}
}
