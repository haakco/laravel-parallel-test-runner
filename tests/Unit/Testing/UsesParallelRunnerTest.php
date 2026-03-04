<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Testing;

use Haakco\ParallelTestRunner\Support\ParallelTestEnvironment;
use Haakco\ParallelTestRunner\Testing\UsesParallelRunner;
use Haakco\ParallelTestRunner\Tests\TestCase;

final class UsesParallelRunnerTest extends TestCase
{
    use UsesParallelRunner;

    protected function tearDown(): void
    {
        ParallelTestEnvironment::reset();
        putenv('TEST_WORKER_ID');
        putenv('DB_DATABASE');
        putenv('TEST_INDIVIDUAL_MODE');
        parent::tearDown();
    }

    public function test_resolve_worker_id_returns_default_when_not_parallel(): void
    {
        $this->assertSame('default', $this->resolveTestWorkerId());
    }

    public function test_resolve_worker_id_from_env(): void
    {
        putenv('TEST_WORKER_ID=4');
        ParallelTestEnvironment::reset();

        $this->assertSame('4', $this->resolveTestWorkerId());
    }

    public function test_resolve_worker_id_from_config(): void
    {
        config()->set('testing.worker_id', 8);
        ParallelTestEnvironment::reset();

        $this->assertSame('8', $this->resolveTestWorkerId());
    }

    public function test_should_ignore_migration_lock_when_worker(): void
    {
        putenv('TEST_WORKER_ID=2');
        ParallelTestEnvironment::reset();

        $this->assertTrue($this->shouldIgnoreMigrationLock());
    }

    public function test_should_not_ignore_migration_lock_by_default(): void
    {
        $this->assertFalse($this->shouldIgnoreMigrationLock());
    }

    public function test_worker_scoped_path_adds_worker_suffix(): void
    {
        putenv('TEST_WORKER_ID=3');
        ParallelTestEnvironment::reset();

        $path = $this->workerScopedPath('disks/local');

        $this->assertSame('disks/local_worker_3', $path);
    }

    public function test_worker_scoped_path_unchanged_when_not_parallel(): void
    {
        $path = $this->workerScopedPath('disks/local');

        $this->assertSame('disks/local', $path);
    }

    public function test_is_parallel_test_run(): void
    {
        $this->assertFalse($this->isParallelTestRun());

        putenv('TEST_WORKER_ID=1');
        ParallelTestEnvironment::reset();

        $this->assertTrue($this->isParallelTestRun());
    }
}
