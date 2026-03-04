<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class RunContext extends Data
{
    /**
     * @param list<string> $commandArgs
     * @param array<string, mixed> $extraOptions
     */
    public function __construct(
        public string $logDirectory,
        public string $command,
        public array $commandArgs,
        public bool $parallel,
        public int $workerCount,
        public ?int $splitTotal,
        public ?int $splitGroup,
        public array $extraOptions,
    ) {}
}
