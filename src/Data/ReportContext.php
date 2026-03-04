<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class ReportContext extends Data
{
    /**
     * @param array<string, mixed> $extraOptions
     */
    public function __construct(
        public string $logDirectory,
        public bool $successful,
        public string $command,
        public string $summaryFile,
        public array $extraOptions,
    ) {}
}
