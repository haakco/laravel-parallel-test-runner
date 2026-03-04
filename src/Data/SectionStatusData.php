<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class SectionStatusData extends Data
{
    public function __construct(
        public string $name,
        public string $status,
        public ?float $duration,
        public ?int $exitCode,
    ) {}
}
