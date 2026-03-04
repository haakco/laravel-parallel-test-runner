<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Results;

use Spatie\LaravelData\Data;

final class DatabaseRefreshResultData extends Data
{
    public function __construct(
        public bool $success,
        public string $message,
        public float $duration,
    ) {}

    public static function success(float $duration): self
    {
        return new self(true, 'Database refreshed successfully', $duration);
    }

    public static function failure(string $message, float $duration = 0.0): self
    {
        return new self(false, $message, $duration);
    }
}
