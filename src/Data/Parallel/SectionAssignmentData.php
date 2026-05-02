<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Parallel;

use Haakco\ParallelTestRunner\Data\Concerns\SerializesTestSectionShape;
use Spatie\LaravelData\Data;

final class SectionAssignmentData extends Data
{
    use SerializesTestSectionShape;

    /** @param list<string> $files */
    public function __construct(
        public string $name,
        public string $type,
        public string $path,
        public array $files,
        public int $fileCount,
    ) {}

    /** @param list<string>|null $files */
    public static function fromName(string $name, ?array $files = null, string $type = 'file', ?string $path = null): self
    {
        $files ??= [];

        return new self(
            name: $name,
            type: $type,
            path: $path ?? $name,
            files: $files,
            fileCount: $files !== [] ? count($files) : 1,
        );
    }
}
