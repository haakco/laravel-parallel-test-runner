<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Scheduling;

use Haakco\ParallelTestRunner\Contracts\PerformanceMetricRepositoryInterface;
use Haakco\ParallelTestRunner\Data\Parallel\SectionAssignmentData;
use Haakco\ParallelTestRunner\Data\Parallel\WorkerPlanData;
use Haakco\ParallelTestRunner\Data\TestSectionData;
use RuntimeException;

class ParallelSectionScheduler
{
    /** @var array<string, float> */
    private array $historicalWeights = [];

    /** @var array<string, float> */
    private readonly array $weightMultipliers;

    private readonly float $baseWeightPerFile;

    public function __construct(
        private readonly PerformanceMetricRepositoryInterface $metricRepository,
    ) {
        $this->historicalWeights = $this->metricRepository->getHistoricalWeights();

        /** @var array<string, float> $multipliers */
        $multipliers = config('parallel-test-runner.sections.weight_multipliers', [
            'Integration' => 2.0,
            'Feature' => 1.5,
            'Unit' => 0.8,
        ]);
        $this->weightMultipliers = $multipliers;

        $this->baseWeightPerFile = (float) config('parallel-test-runner.sections.base_weight_per_file', 10.0);
    }

    /**
     * @param list<TestSectionData> $sections
     * @param array<int, string> $databases
     * @return list<WorkerPlanData>
     */
    public function createWorkerPlans(
        array $sections,
        int $workerCount,
        array $databases,
        string $logDirectory,
        string $suite = 'standard',
        bool $individual = false,
    ): array {
        if ($sections === []) {
            return [];
        }

        if ($workerCount <= 0) {
            throw new RuntimeException('Worker count must be at least 1');
        }

        $weightedSections = $this->calculateSectionWeights($sections);
        $workerBuckets = $this->distributeToWorkers($weightedSections, $workerCount);

        $plans = [];
        foreach ($workerBuckets as $workerId => $assignedSections) {
            if ($assignedSections === []) {
                continue;
            }

            $sectionData = array_map(
                static fn(array $bucket): SectionAssignmentData => $bucket['section'],
                $assignedSections,
            );

            $plans[] = new WorkerPlanData(
                workerId: $workerId,
                sections: $sectionData,
                database: $databases[$workerId] ?? throw new RuntimeException("No database for worker {$workerId}"),
                logDirectory: $logDirectory . '/worker' . str_pad((string) $workerId, 2, '0', STR_PAD_LEFT),
                suite: $suite,
                estimatedWeight: array_sum(array_column($assignedSections, 'weight')),
                individual: $individual,
            );
        }

        return $plans;
    }

    /**
     * @param list<TestSectionData> $sections
     * @return list<array{section: SectionAssignmentData, weight: float}>
     */
    private function calculateSectionWeights(array $sections): array
    {
        $weighted = [];

        foreach ($sections as $section) {
            $sectionAssignment = new SectionAssignmentData(
                name: $section->name,
                type: $section->type,
                path: $section->path,
                files: $section->files,
                fileCount: $section->fileCount,
            );

            $weight = $this->historicalWeights[$sectionAssignment->name]
                ?? $this->estimateWeight($sectionAssignment);

            $weighted[] = [
                'section' => $sectionAssignment,
                'weight' => $weight,
            ];
        }

        usort($weighted, static fn(array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return $weighted;
    }

    private function estimateWeight(SectionAssignmentData $section): float
    {
        $fileCount = $section->fileCount > 0 ? $section->fileCount : max(1, count($section->files));
        $weight = $fileCount * $this->baseWeightPerFile;

        foreach ($this->weightMultipliers as $keyword => $multiplier) {
            if (str_contains($section->name, $keyword)) {
                $weight *= $multiplier;
            }
        }

        return max(1.0, $weight);
    }

    /**
     * @param list<array{section: SectionAssignmentData, weight: float}> $weightedSections
     * @return array<int, list<array{section: SectionAssignmentData, weight: float}>>
     */
    private function distributeToWorkers(array $weightedSections, int $workerCount): array
    {
        $buckets = [];
        $bucketWeights = [];

        for ($i = 1; $i <= $workerCount; $i++) {
            $buckets[$i] = [];
            $bucketWeights[$i] = 0.0;
        }

        foreach ($weightedSections as $section) {
            $minWorkerId = array_keys($bucketWeights, min($bucketWeights))[0];
            $buckets[$minWorkerId][] = $section;
            $bucketWeights[$minWorkerId] += $section['weight'];
        }

        return $buckets;
    }
}
