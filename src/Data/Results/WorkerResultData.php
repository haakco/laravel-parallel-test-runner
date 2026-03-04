<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Results;

use Haakco\ParallelTestRunner\Data\Parallel\MetricsTotalsData;
use Spatie\LaravelData\Data;

final class WorkerResultData extends Data
{
    /**
     * @param list<string> $sections
     */
    public function __construct(
        public int $workerId,
        public bool $success,
        public int $exitCode,
        public string $status,
        public float $duration,
        public MetricsTotalsData $totals,
        public array $sections,
        public string $logDirectory,
    ) {}
}
