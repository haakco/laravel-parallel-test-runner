<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Results;

use Spatie\LaravelData\Data;

final class BackgroundRunStartResultData extends Data
{
    public function __construct(
        public bool $started,
        public string $message,
        public ?int $pid,
        public ?string $logFile,
    ) {}

    public static function success(int $pid, string $logFile): self
    {
        return new self(true, "Background run started (PID: {$pid})", $pid, $logFile);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message, null, null);
    }
}
