<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class WorkerContext extends Data
{
    /**
     * @param list<string> $sections
     * @param array<string, mixed> $extraOptions
     */
    public function __construct(
        public int $workerId,
        public string $database,
        public string $logDirectory,
        public array $sections,
        public string $suite,
        public bool $individual,
        public array $extraOptions,
    ) {}
}
