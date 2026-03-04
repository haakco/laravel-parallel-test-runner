<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Services;

use Haakco\ParallelTestRunner\Data\Parallel\MetricsTotalsData;
use Haakco\ParallelTestRunner\Data\Parallel\SectionResultData;
use Illuminate\Support\Facades\File;

final class TestExecutionTracker
{
    /** @var array<string, mixed> */
    private array $executionData = [];

    private readonly string $trackingFile;

    private readonly float $startTime;

    public function __construct(string $logDirectory)
    {
        $this->trackingFile = rtrim($logDirectory, DIRECTORY_SEPARATOR) . '/execution_tracking.json';
        $this->startTime = microtime(true);
        $this->load();
    }

    public function load(): void
    {
        if (File::exists($this->trackingFile)) {
            $this->executionData = json_decode(File::get($this->trackingFile), true, 512, JSON_THROW_ON_ERROR) ?? [];
        } else {
            $this->executionData = [
                'sections' => [],
                'totals' => MetricsTotalsData::fromArray([])->toArray(),
                'started_at' => now()->toIso8601String(),
            ];
        }
    }

    public function save(): void
    {
        $this->executionData['updated_at'] = now()->toIso8601String();
        $this->executionData['duration'] = microtime(true) - $this->startTime;

        $dir = dirname($this->trackingFile);
        if (! File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put($this->trackingFile, json_encode($this->executionData, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * Record a section result and update totals.
     */
    public function recordSectionResult(string $section, SectionResultData $result): void
    {
        $startedAt = $result->startedAt > 0 ? $result->startedAt : microtime(true) - $result->duration;
        $completedAt = $result->completedAt > 0 ? $result->completedAt : $startedAt + $result->duration;

        $this->executionData['sections'][$section] = [
            'status' => $result->success ? 'passed' : 'failed',
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'duration' => $result->duration,
            'results' => $result->toTrackerArray(),
        ];

        $this->updateTotalsFromResult($result);
        $this->save();
    }

    /**
     * Update totals from aggregated metrics.
     *
     * @param array<string, mixed> $metrics
     */
    public function updateFromAggregatedMetrics(array $metrics): void
    {
        $this->executionData['totals'] = [
            'tests' => (int) ($metrics['tests'] ?? 0),
            'assertions' => (int) ($metrics['assertions'] ?? 0),
            'errors' => (int) ($metrics['errors'] ?? 0),
            'failures' => (int) ($metrics['failures'] ?? 0),
            'warnings' => (int) ($metrics['warnings'] ?? 0),
            'skipped' => (int) ($metrics['skipped'] ?? 0),
            'incomplete' => (int) ($metrics['incomplete'] ?? 0),
            'risky' => (int) ($metrics['risky'] ?? 0),
        ];

        if (isset($metrics['duration'])) {
            $this->executionData['totals']['duration'] = $metrics['duration'];
        }

        $this->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function getExecutionData(): array
    {
        return $this->executionData;
    }

    /**
     * @return array<string, int>
     */
    public function getTotals(): array
    {
        return $this->executionData['totals'] ?? MetricsTotalsData::fromArray([])->toArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSectionResult(string $section): ?array
    {
        return $this->executionData['sections'][$section] ?? null;
    }

    public function getTrackingFile(): string
    {
        return $this->trackingFile;
    }

    private function updateTotalsFromResult(SectionResultData $result): void
    {
        $this->executionData['totals']['tests'] = ($this->executionData['totals']['tests'] ?? 0) + $result->tests;
        $this->executionData['totals']['assertions'] = ($this->executionData['totals']['assertions'] ?? 0) + $result->assertions;
        $this->executionData['totals']['errors'] = ($this->executionData['totals']['errors'] ?? 0) + $result->errors;
        $this->executionData['totals']['failures'] = ($this->executionData['totals']['failures'] ?? 0) + $result->failures;
        $this->executionData['totals']['skipped'] = ($this->executionData['totals']['skipped'] ?? 0) + $result->skipped;
        $this->executionData['totals']['incomplete'] = ($this->executionData['totals']['incomplete'] ?? 0) + $result->incomplete;
        $this->executionData['totals']['risky'] = ($this->executionData['totals']['risky'] ?? 0) + $result->risky;
    }
}
