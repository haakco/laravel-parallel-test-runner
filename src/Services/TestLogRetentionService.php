<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Services;

use Illuminate\Support\Facades\File;

/**
 * Prunes old per-run directories under `test-logs/`.
 *
 * Each run created by the test runner produces a directory named
 * `YYYYMMDD_HHMMSS_uuuuuu_<hex>` (see `TestExecutionOrchestratorService` and
 * `TestRunnerService::generateLogDirectoryName`). Left unpruned these
 * accumulate indefinitely; on busy CI hosts or local dev workstations the
 * directory count can pass 100k entries and start poisoning tooling that
 * walks the project tree (Metro/watchman in pnpm monorepos is the canonical
 * symptom).
 *
 * Two retention axes are applied conjunctively — a dir is kept only if it
 * is *both* among the `maxRuns` most-recent and *younger than* `maxAgeDays`.
 * Either axis can be disabled by passing `null`.
 *
 * The `latest` symlink (and any non-run-shaped entries) is left untouched.
 */
class TestLogRetentionService
{
    /**
     * Regex matching the timestamped run-dir naming convention.
     *
     * Format: `YYYYMMDD_HHMMSS_uuuuuu_<6-hex>` (see
     * `TestRunnerService::generateLogDirectoryName`). Anchored to start to
     * avoid matching files whose names happen to contain a timestamp.
     */
    private const string RUN_DIR_REGEX = '/^\d{8}_\d{6}_\d{6}_[0-9a-f]{6}$/';

    public function __construct(
        private readonly string $baseDirectory,
        private readonly ?int $maxRuns,
        private readonly ?int $maxAgeDays,
    ) {}

    public function getBaseDirectory(): string
    {
        return $this->baseDirectory;
    }

    public function getMaxRuns(): ?int
    {
        return $this->maxRuns;
    }

    public function getMaxAgeDays(): ?int
    {
        return $this->maxAgeDays;
    }

    /**
     * Compute which dirs would be pruned without deleting anything.
     *
     * @return array<int, string> Absolute paths of dirs that prune() would remove.
     */
    public function dryRun(): array
    {
        return $this->resolveDeletions();
    }

    /**
     * Delete eligible run-dirs and return the list of dirs removed.
     *
     * @return array<int, string> Absolute paths of dirs that were deleted.
     */
    public function prune(): array
    {
        $deleted = [];
        foreach ($this->resolveDeletions() as $path) {
            if (File::deleteDirectory($path)) {
                $deleted[] = $path;
            }
        }

        return $deleted;
    }

    /**
     * @return array<int, string>
     */
    private function resolveDeletions(): array
    {
        if (! File::isDirectory($this->baseDirectory)) {
            return [];
        }

        $runDirs = $this->collectRunDirs();
        if ($runDirs === []) {
            return [];
        }

        // Most recent first (dirnames sort lexicographically == chronologically).
        usort($runDirs, static fn(array $a, array $b): int => strcmp($b['name'], $a['name']));

        $cutoffTimestamp = $this->maxAgeDays !== null
            ? time() - ($this->maxAgeDays * 86_400)
            : null;

        $deletions = [];
        foreach ($runDirs as $index => $entry) {
            $exceedsCount = $this->maxRuns !== null && $index >= $this->maxRuns;
            $exceedsAge = $cutoffTimestamp !== null
                && $this->parseTimestamp($entry['name']) !== null
                && $this->parseTimestamp($entry['name']) < $cutoffTimestamp;

            if ($exceedsCount || $exceedsAge) {
                $deletions[] = $entry['path'];
            }
        }

        return $deletions;
    }

    /**
     * @return array<int, array{name: string, path: string}>
     */
    private function collectRunDirs(): array
    {
        $entries = [];
        foreach (File::directories($this->baseDirectory) as $path) {
            $name = basename((string) $path);
            if (preg_match(self::RUN_DIR_REGEX, $name) === 1) {
                $entries[] = ['name' => $name, 'path' => $path];
            }
        }

        return $entries;
    }

    /**
     * Extract the unix timestamp from a `YYYYMMDD_HHMMSS_…` dirname.
     */
    private function parseTimestamp(string $dirName): ?int
    {
        if (preg_match('/^(\d{8})_(\d{6})_/', $dirName, $matches) !== 1) {
            return null;
        }

        $datetime = \DateTimeImmutable::createFromFormat('Ymd_His', $matches[1] . '_' . $matches[2]);

        return $datetime instanceof \DateTimeImmutable ? $datetime->getTimestamp() : null;
    }
}
