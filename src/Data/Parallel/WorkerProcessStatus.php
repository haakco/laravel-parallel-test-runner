<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Parallel;

enum WorkerProcessStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Crashed = 'crashed';
    case Terminated = 'terminated';

    public function isRunning(): bool
    {
        return $this === self::Running;
    }

    public function isSuccessful(): bool
    {
        return $this === self::Completed;
    }

    public function isFailed(): bool
    {
        return $this === self::Failed || $this === self::Crashed;
    }
}
