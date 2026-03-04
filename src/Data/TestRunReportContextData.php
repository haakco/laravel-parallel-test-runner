<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class TestRunReportContextData extends Data
{
    /**
     * @param array<string, mixed> $extraData
     */
    public function __construct(
        public string $logDirectory,
        public string $command,
        public bool $parallel,
        public int $workerCount,
        public ?int $splitTotal,
        public ?int $splitGroup,
        public array $extraData,
    ) {}
}
