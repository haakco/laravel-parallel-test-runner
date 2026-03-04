<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class CleanupContext extends Data
{
    /**
     * @param list<string> $databases
     * @param array<string, mixed> $extraOptions
     */
    public function __construct(
        public array $databases,
        public string $connection,
        public bool $keepDatabases,
        public array $extraOptions,
    ) {}
}
