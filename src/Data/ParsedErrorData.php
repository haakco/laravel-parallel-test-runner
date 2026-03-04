<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class ParsedErrorData extends Data
{
    public function __construct(
        public string $type,
        public string $message,
        public string $file,
        public int $line,
        public string $trace,
    ) {}
}
