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
}
