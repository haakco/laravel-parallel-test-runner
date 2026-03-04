<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Reporting;

use Haakco\ParallelTestRunner\Data\ReportContext;
use Illuminate\Support\Facades\File;

final readonly class PerformanceReportWriter
{
    public function __construct(
        private ReportFormatter $formatter,
        private TrackingLoader $trackingLoader,
    ) {}

    public function write(ReportContext $context): void
    {
        $tracking = $this->trackingLoader->load($context->logDirectory);
        if ($tracking === null) {
            return;
        }

        $topSections = $this->trackingLoader->extractSectionMetrics($tracking);
        $startedAt = $tracking['started_at'] ?? now()->toIso8601String();
        $duration = (float) ($tracking['duration'] ?? 0.0);

        $outputPath = $this->resolveOutputPath($context);
        File::ensureDirectoryExists(dirname($outputPath));

        $lines = [];
        $lines[] = sprintf('# Test Performance Report - %s', $startedAt);
        $lines[] = '';
        $lines[] = sprintf('- Command: `%s`', $context->command);
        $lines[] = sprintf('- Outcome: %s', $context->successful ? 'Passed' : 'Failed');
        $lines[] = sprintf('- Log Directory: `%s`', $this->formatter->relativePath($context->logDirectory));
        $lines[] = sprintf('- Total Runtime: %s', $this->formatter->formatDuration($duration));
        $lines[] = '';
        $lines[] = '## Top 10 Slowest Sections';
        $lines[] = '';
        $lines[] = '| Section | Duration (s) | Tests | Assertions | Errors | Failures |';
        $lines[] = '| --- | ---: | ---: | ---: | ---: | ---: |';

        if ($topSections === []) {
            $lines[] = '| _No section data available_ | 0 | 0 | 0 | 0 | 0 |';
        } else {
            foreach ($topSections as $section) {
                $lines[] = sprintf(
                    '| %s | %.2f | %d | %d | %d | %d |',
                    $section['name'],
                    $section['duration'],
                    $section['tests'],
                    $section['assertions'],
                    $section['errors'],
                    $section['failures'],
                );
            }
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';

        File::append($outputPath, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    private function resolveOutputPath(ReportContext $context): string
    {
        $configPath = config('parallel-test-runner.reports.performance_path');
        if (is_string($configPath) && $configPath !== '') {
            return $configPath;
        }

        return rtrim($context->logDirectory, DIRECTORY_SEPARATOR) . '/performance-report.md';
    }
}
