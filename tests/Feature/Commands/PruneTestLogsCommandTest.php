<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Feature\Commands;

use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Support\Facades\File;

final class PruneTestLogsCommandTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . '/ptr-cmd-' . uniqid('', true);
        File::makeDirectory($this->baseDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            File::deleteDirectory($this->baseDir);
        }
        parent::tearDown();
    }

    public function test_command_prunes_aged_directories_with_explicit_overrides(): void
    {
        $aged = $this->makeRun(daysAgo: 30);
        $fresh = $this->makeRun(daysAgo: 1);

        $this->artisan('test:logs-prune', [
            '--base-dir' => $this->baseDir,
            '--max-runs' => 'null',
            '--max-age-days' => 7,
        ])
            ->expectsOutputToContain('[deleted] ' . $aged)
            ->expectsOutputToContain('Pruned 1 directory(ies).')
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist($aged);
        $this->assertDirectoryExists($fresh);
    }

    public function test_dry_run_lists_candidates_without_deleting(): void
    {
        $aged = $this->makeRun(daysAgo: 30);

        $this->artisan('test:logs-prune', [
            '--base-dir' => $this->baseDir,
            '--max-runs' => 'null',
            '--max-age-days' => 7,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('[dry-run] ' . $aged)
            ->expectsOutputToContain('Would prune 1 directory(ies).')
            ->assertSuccessful();

        $this->assertDirectoryExists($aged);
    }

    public function test_command_reports_success_when_nothing_to_prune(): void
    {
        $this->makeRun(daysAgo: 1);

        $this->artisan('test:logs-prune', [
            '--base-dir' => $this->baseDir,
            '--max-runs' => 10,
            '--max-age-days' => 7,
        ])
            ->expectsOutputToContain('Nothing to prune — retention policy satisfied.')
            ->assertSuccessful();
    }

    private function makeRun(int $daysAgo, int $secondsOffset = 0): string
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
