<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Reporting;

use Illuminate\Support\Facades\File;

final class TrackingLoader
{
    /**
     * Load execution tracking data from a log directory.
     *
     * @return array<string, mixed>|null Returns null if the tracking file does not exist.
     */
    public function load(string $logDirectory): ?array
    {
        $trackingPath = rtrim($logDirectory, DIRECTORY_SEPARATOR) . '/execution_tracking.json';

        if (! File::exists($trackingPath)) {
            return null;
        }

        return json_decode(File::get($trackingPath), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Extract section metrics sorted by duration descending.
     *
     * @param array<string, mixed> $tracking
     * @return list<array{name: string, duration: float, tests: int, assertions: int, errors: int, failures: int, skipped: int, incomplete: int}>
     */
    public function extractSectionMetrics(array $tracking, int $limit = 10): array
    {
        $sections = $tracking['sections'] ?? [];
        $metrics = [];

        foreach ($sections as $name => $section) {
            if (! is_string($name)) {
                continue;
            }
            if (! is_array($section)) {
                continue;
            }
            $results = $section['results'] ?? [];
            $live = $section['live_results'] ?? [];

            $metrics[] = [
                'name' => $name,
                'duration' => (float) ($section['duration'] ?? 0.0),
                'tests' => (int) ($results['tests'] ?? $live['tests'] ?? 0),
                'assertions' => (int) ($results['assertions'] ?? $live['assertions'] ?? 0),
                'errors' => (int) ($results['errors'] ?? 0),
                'failures' => (int) ($results['failures'] ?? 0),
                'skipped' => (int) ($results['skipped'] ?? $live['skipped'] ?? 0),
                'incomplete' => (int) ($results['incomplete'] ?? $live['incomplete'] ?? 0),
            ];
        }

        usort($metrics, static fn(array $a, array $b): int => $b['duration'] <=> $a['duration']);

        return array_slice($metrics, 0, $limit);
    }

    /**
     * Extract method-level metrics from JUnit XML files.
     *
     * @return list<array{class: string, method: string, duration: float, suite: string}>
     */
    public function extractMethodMetrics(string $logDirectory, int $limit = 10): array
    {
        $metrics = [];
        $directory = rtrim($logDirectory, DIRECTORY_SEPARATOR);
        if (! is_dir($directory)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (! $fileInfo instanceof \SplFileInfo) {
                continue;
            }

            $file = $fileInfo->getPathname();
            if (! str_ends_with($file, '_junit.xml')) {
                continue;
            }

            $xml = @simplexml_load_file($file);
            if ($xml === false) {
                continue;
            }

            $testSuite = $xml->testsuite;
            if ($testSuite === null) {
                continue;
            }

            foreach ($testSuite->testcase as $testCase) {
                $class = (string) ($testCase['class'] ?? '');
                $method = (string) ($testCase['name'] ?? '');
                $duration = (float) ($testCase['time'] ?? 0.0);
                if ($class === '') {
                    continue;
                }
                if ($method === '') {
                    continue;
                }

                $metrics[] = [
                    'class' => $class,
                    'method' => $method,
                    'duration' => $duration,
                    'suite' => basename($file),
                ];
            }
        }

        usort($metrics, static fn(array $a, array $b): int => $b['duration'] <=> $a['duration']);

        return array_slice($metrics, 0, $limit);
    }

    /**
     * Estimate the execution window covered by section start/end timestamps.
     *
     * @param array<string, mixed> $tracking
     */
    public function sectionExecutionWindowSeconds(array $tracking): float
    {
        $sections = $tracking['sections'] ?? [];
        $starts = [];
        $ends = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $startedAt = $section['started_at'] ?? null;
            $completedAt = $section['completed_at'] ?? null;

            if (is_numeric($startedAt)) {
                $starts[] = (float) $startedAt;
            }

            if (is_numeric($completedAt)) {
                $ends[] = (float) $completedAt;
            }
        }

        if ($starts === [] || $ends === []) {
            return 0.0;
        }

        return max(0.0, max($ends) - min($starts));
    }
}
