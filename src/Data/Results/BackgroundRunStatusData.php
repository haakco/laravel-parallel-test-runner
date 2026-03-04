<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Results;

use Spatie\LaravelData\Data;

final class BackgroundRunStatusData extends Data
{
    public function __construct(
        public bool $running,
        public string $message,
        public ?int $pid,
        public ?string $logFile,
    ) {}

    public static function running(int $pid, string $logFile): self
    {
        return new self(true, "Test run in progress (PID: {$pid})", $pid, $logFile);
    }

    public static function notRunning(): self
    {
        return new self(false, 'No background test run found', null, null);
    }
}
