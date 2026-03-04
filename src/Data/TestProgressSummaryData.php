<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class TestProgressSummaryData extends Data
{
    /**
     * @param list<SectionStatusData> $sectionStatuses
     */
    public function __construct(
        public TestProgressData $progress,
        public array $sectionStatuses,
        public int $passedSections,
        public int $failedSections,
    ) {}
}
