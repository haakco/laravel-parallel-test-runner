<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Reporting;

final class ReportFormatter
{
    /**
     * Convert an absolute path to a path relative to base_path().
     */
    public function relativePath(string $path): string
    {
        $basePath = base_path();
        $resolvedPath = realpath($path);
        $resolvedBasePath = realpath($basePath);

        if ($resolvedPath !== false) {
            $path = $resolvedPath;
        }

        if ($resolvedBasePath !== false) {
            $basePath = $resolvedBasePath;
        }

        $normalizedPath = $this->normalizePath($path);
        $normalizedBasePath = $this->normalizePath($basePath);
        $basePrefix = rtrim($normalizedBasePath, '/') . '/';

        if ($normalizedPath === rtrim($normalizedBasePath, '/')) {
            return '.';
        }

        if (str_starts_with($normalizedPath, $basePrefix)) {
            return ltrim(substr($normalizedPath, strlen($basePrefix)), '/');
        }

        return $normalizedPath;
    }

    /**
     * Format seconds into human-readable duration.
     */
    public function formatDuration(float $seconds): string
    {
        if ($seconds <= 0.0) {
            return '0s';
        }

        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $remaining = fmod($seconds, 60);

        if ($hours > 0) {
            return sprintf('%02d:%02d:%04.1f', $hours, $minutes, $remaining);
        }

        if ($minutes > 0) {
            return sprintf('%02d:%04.1f', $minutes, $remaining);
        }

        return sprintf('%.2fs', $remaining);
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
