<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Parallel;

use Illuminate\Process\InvokedProcess;

final class WorkerProcessStateData
{
    public ?int $exitCode = null;

    public ?WorkerMetricsData $metrics = null;

    public string $outputBuffer = '';

    public function __construct(
        public InvokedProcess $process,
        public WorkerPlanData $plan,
        public WorkerProcessStatus $status,
        public int $completedSections,
        public int $totalSections,
    ) {}

    public static function running(InvokedProcess $process, WorkerPlanData $plan): self
    {
        return new self(
            process: $process,
            plan: $plan,
            status: WorkerProcessStatus::Running,
            completedSections: 0,
            totalSections: count($plan->sections),
        );
    }

    public function markFinished(WorkerProcessStatus $status, int $exitCode): void
    {
        $this->status = $status;
        $this->exitCode = $exitCode;
    }
}
