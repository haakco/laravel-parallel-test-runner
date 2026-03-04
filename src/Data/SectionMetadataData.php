<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class SectionMetadataData extends Data
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
        public float $estimatedWeight,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $files = $data['files'] ?? [];

        return new self(
            name: (string) ($data['name'] ?? ''),
            type: (string) ($data['type'] ?? 'directory'),
            path: (string) ($data['path'] ?? ''),
            files: $files,
            fileCount: (int) ($data['file_count'] ?? count($files)),
            estimatedWeight: (float) ($data['estimated_weight'] ?? 0.0),
        );
    }

    /**
     * @param list<string> $files
     */
    public function withFiles(array $files): self
    {
        return new self($this->name, $this->type, $this->path, $files, count($files), $this->estimatedWeight);
    }
}
