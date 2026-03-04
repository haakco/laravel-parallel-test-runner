<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class ErrorFileContentData extends Data
{
    /**
     * @param list<string> $lines
     */
    public function __construct(
        public string $filePath,
        public array $lines,
        public int $errorLine,
        public int $contextStart,
        public int $contextEnd,
    ) {}
}
