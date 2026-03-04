<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Results;

use Spatie\LaravelData\Data;

final class HangingTestsResultData extends Data
{
    /**
     * @param list<string> $hangingSections
     * @param list<string> $passedSections
     */
    public function __construct(
        public bool $found,
        public array $hangingSections,
        public array $passedSections,
        public int $threshold,
    ) {}
}
