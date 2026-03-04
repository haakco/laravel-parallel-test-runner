<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class SectionResolutionContext extends Data
{
    /**
     * @param list<string> $scanPaths
     * @param list<string> $forceSplitDirectories
     * @param list<string> $sections
     * @param list<string> $tests
     * @param array<string, array<string, mixed>> $additionalSuites
     * @param array<string, mixed> $extraOptions
     */
    public function __construct(
        public array $scanPaths,
        public array $forceSplitDirectories,
        public bool $individual,
        public array $sections,
        public array $tests,
        public ?string $filter,
        public ?string $testSuite,
        public ?int $splitTotal,
        public ?int $splitGroup,
        public array $additionalSuites,
        public array $extraOptions,
    ) {}
}
