<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Haakco\ParallelTestRunner\Data\Concerns\SerializesTestSectionShape;
use Spatie\LaravelData\Data;

final class TestSectionData extends Data
{
    use SerializesTestSectionShape;

    /**
     * @param list<string> $files
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $path,
        public array $files,
        public int $fileCount,
    ) {}
}
