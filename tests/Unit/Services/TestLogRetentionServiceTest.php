<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Services;

use Haakco\ParallelTestRunner\Services\TestLogRetentionService;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Support\Facades\File;

final class TestLogRetentionServiceTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . '/ptr-retention-' . uniqid('', true);
        File::makeDirectory($this->baseDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            File::deleteDirectory($this->baseDir);
        }
        parent::tearDown();
    }

    public function test_returns_no_deletions_when_base_directory_missing(): void
    {
        File::deleteDirectory($this->baseDir);

        $service = new TestLogRetentionService($this->baseDir, 5, 1);

        $this->assertSame([], $service->prune());
    }

    public function test_returns_no_deletions_when_under_both_limits(): void
    {
        $this->createRunDir(daysAgo: 0);
        $this->createRunDir(daysAgo: 1);
        $this->createRunDir(daysAgo: 2);

        $service = new TestLogRetentionService($this->baseDir, maxRuns: 5, maxAgeDays: 7);

        $this->assertSame([], $service->prune());
        $this->assertCount(3, File::directories($this->baseDir));
    }

    public function test_prunes_by_max_runs_keeping_most_recent(): void
    {
        $oldest = $this->createRunDir(daysAgo: 5);
        $middle = $this->createRunDir(daysAgo: 3);
        $newest = $this->createRunDir(daysAgo: 1);

        $service = new TestLogRetentionService($this->baseDir, maxRuns: 1, maxAgeDays: null);

        $deleted = $service->prune();

        $this->assertCount(2, $deleted);
        $this->assertContains($oldest, $deleted);
        $this->assertContains($middle, $deleted);
        $this->assertDirectoryExists($newest);
        $this->assertDirectoryDoesNotExist($oldest);
        $this->assertDirectoryDoesNotExist($middle);
    }

    public function test_prunes_by_max_age_days_only(): void
    {
        $aged = $this->createRunDir(daysAgo: 30);
        $fresh = $this->createRunDir(daysAgo: 1);

        $service = new TestLogRetentionService($this->baseDir, maxRuns: null, maxAgeDays: 7);

        $deleted = $service->prune();

        $this->assertSame([$aged], $deleted);
        $this->assertDirectoryExists($fresh);
    }

    public function test_axes_combine_conjunctively_whichever_hits_first(): void
    {
        // 25 runs, all within max_age_days (1 day old).
        // max_runs=20 should prune the 5 oldest.
        $created = [];
        for ($i = 24; $i >= 0; $i--) {
            $created[] = $this->createRunDir(daysAgo: 1, secondsOffset: $i * 60);
        }

        $service = new TestLogRetentionService($this->baseDir, maxRuns: 20, maxAgeDays: 7);
        $deleted = $service->prune();

        $this->assertCount(5, $deleted);
        // The 5 oldest were created first → they have the smallest timestamps
        // and end up at indexes 20..24 after the "most-recent-first" sort.
        $this->assertCount(20, File::directories($this->baseDir));
    }

    public function test_null_limits_disable_pruning_completely(): void
    {
        $this->createRunDir(daysAgo: 365);
        $this->createRunDir(daysAgo: 1000);

        $service = new TestLogRetentionService($this->baseDir, maxRuns: null, maxAgeDays: null);

        $this->assertSame([], $service->prune());
        $this->assertCount(2, File::directories($this->baseDir));
    }

    public function test_ignores_non_run_dir_entries(): void
    {
        $latest = $this->baseDir . '/latest';
        File::makeDirectory($latest);

        // A run-shaped dir older than the retention window
        $oldRun = $this->createRunDir(daysAgo: 30);

        $service = new TestLogRetentionService($this->baseDir, maxRuns: null, maxAgeDays: 7);
        $deleted = $service->prune();

        $this->assertSame([$oldRun], $deleted);
        $this->assertDirectoryExists($latest, '`latest` symlink/dir must not be pruned');
    }

    public function test_dry_run_does_not_delete_but_reports_candidates(): void
    {
        $old = $this->createRunDir(daysAgo: 30);
        $young = $this->createRunDir(daysAgo: 1);

        $service = new TestLogRetentionService($this->baseDir, maxRuns: null, maxAgeDays: 7);
        $candidates = $service->dryRun();

        $this->assertSame([$old], $candidates);
        $this->assertDirectoryExists($old, 'dry-run must not delete');
        $this->assertDirectoryExists($young);
    }

    /**
     * Create a run-dir whose name encodes a timestamp `daysAgo` in the past.
     * `secondsOffset` allows multiple dirs on the same day to sort deterministically.
     */
    private function createRunDir(int $daysAgo, int $secondsOffset = 0): string
    {
        $timestamp = time() - ($daysAgo * 86_400) + $secondsOffset;
        $name = sprintf(
            '%s_%s_%s',
            date('Ymd_His', $timestamp),
            str_pad((string) ($secondsOffset % 1_000_000), 6, '0', STR_PAD_LEFT),
            bin2hex(random_bytes(3)),
        );
        $path = $this->baseDir . '/' . $name;
        File::makeDirectory($path, 0o755, true);

        return $path;
    }
}
