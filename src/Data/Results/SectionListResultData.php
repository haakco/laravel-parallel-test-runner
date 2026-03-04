<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Results;

use Haakco\ParallelTestRunner\Data\TestSectionData;
use Spatie\LaravelData\Data;

final class SectionListResultData extends Data
{
    /**
     * @param list<TestSectionData> $sections
     */
    public function __construct(
        public array $sections,
        public int $totalFiles,
        public int $totalSections,
    ) {}
}
