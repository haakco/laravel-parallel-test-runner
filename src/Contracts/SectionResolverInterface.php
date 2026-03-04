<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Contracts;

use Haakco\ParallelTestRunner\Data\SectionResolutionContext;
use Haakco\ParallelTestRunner\Data\TestSectionData;

interface SectionResolverInterface
{
    /**
     * Resolve test sections from configured paths.
     *
     * @return list<TestSectionData>
     */
    public function resolve(SectionResolutionContext $context): array;
}
