<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Override;
use Spatie\LaravelData\Data;

final class TestSectionData extends Data
{
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

    /**
     * @param array<string, mixed> $section
     */
    public static function fromArray(array $section): self
    {
        $name = (string) ($section['name'] ?? $section['path'] ?? '');
        $path = (string) ($section['path'] ?? $name);
        $files = array_values(array_map(static fn(mixed $file): string => (string) $file, $section['files'] ?? []));
        $fileCount = (int) ($section['file_count'] ?? count($files));

        return new self(
            name: $name,
            type: (string) ($section['type'] ?? 'file'),
            path: $path,
            files: $files,
            fileCount: $fileCount > 0 ? $fileCount : max(1, count($files)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'path' => $this->path,
            'files' => $this->files,
            'file_count' => $this->fileCount,
        ];
    }
}
