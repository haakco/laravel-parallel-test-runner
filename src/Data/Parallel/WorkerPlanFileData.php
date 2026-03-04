<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Parallel;

use Spatie\LaravelData\Data;

final class WorkerPlanFileData extends Data
{
    /** @param list<array<string, mixed>|SectionAssignmentData> $sections */
    public function __construct(
        public array $sections,
        public string $database,
        public string $suite,
        public int $workerId,
        public float $estimatedWeight,
        public string $logDirectory,
        public bool $individual = false,
    ) {}

    public static function fromWorkerPlan(WorkerPlanData $plan): self
    {
        return new self(
            sections: $plan->sections,
            database: $plan->database,
            suite: $plan->suite,
            workerId: $plan->workerId,
            estimatedWeight: $plan->estimatedWeight,
            logDirectory: $plan->logDirectory,
            individual: $plan->individual,
        );
    }

    public function toWorkerPlan(?string $overrideLogDirectory = null): WorkerPlanData
    {
        $resolvedSections = [];
        foreach ($this->sections as $section) {
            $resolvedSections[] = $section instanceof SectionAssignmentData
                ? $section
                : SectionAssignmentData::fromArray((array) $section);
        }

        return new WorkerPlanData(
            workerId: $this->workerId,
            sections: $resolvedSections,
            database: $this->database,
            logDirectory: $overrideLogDirectory ?? $this->logDirectory,
            suite: $this->suite,
            estimatedWeight: $this->estimatedWeight,
            individual: $this->individual,
        );
    }
}
