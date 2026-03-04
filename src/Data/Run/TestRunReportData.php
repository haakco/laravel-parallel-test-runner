<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Run;

use Spatie\LaravelData\Data;

final class TestRunReportData extends Data
{
    /**
     * @param list<TestSectionResultData> $sectionResults
     * @param list<string> $failedSections
     */
    public function __construct(
        public bool $success,
        public TestRunCountersData $counters,
        public float $totalDuration,
        public array $sectionResults,
        public array $failedSections,
        public int $sectionsCompleted,
        public int $sectionsTotal,
    ) {}
}
