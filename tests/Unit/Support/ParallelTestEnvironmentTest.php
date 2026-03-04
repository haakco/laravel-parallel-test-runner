<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Support;

use Haakco\ParallelTestRunner\Support\ParallelTestEnvironment;
use Haakco\ParallelTestRunner\Tests\TestCase;

final class ParallelTestEnvironmentTest extends TestCase
{
    protected function tearDown(): void
    {
        ParallelTestEnvironment::reset();
        putenv('TEST_WORKER_ID');
        putenv('DB_DATABASE');
        putenv('TEST_LOG_DIR');
        putenv('TEST_SUITE');
        putenv('TEST_INDIVIDUAL_MODE');
        putenv('PARALLEL_TESTS');
        parent::tearDown();
    }

    public function test_is_not_parallel_by_default(): void
    {
        $this->assertFalse(ParallelTestEnvironment::isWorkerProcess());
    }

    public function test_detects_worker_process_from_env(): void
    {
        putenv('TEST_WORKER_ID=3');

        $this->assertTrue(ParallelTestEnvironment::isWorkerProcess());
    }

    public function test_returns_worker_id_from_env(): void
    {
        putenv('TEST_WORKER_ID=5');

        $this->assertSame(5, ParallelTestEnvironment::workerId());
    }

    public function test_returns_null_worker_id_when_not_parallel(): void
    {
        $this->assertNull(ParallelTestEnvironment::workerId());
    }

    public function test_returns_worker_id_from_config(): void
    {
        config()->set('testing.worker_id', 7);

        $this->assertSame(7, ParallelTestEnvironment::workerId());
    }

    public function test_env_takes_precedence_over_config(): void
    {
        config()->set('testing.worker_id', 7);
        putenv('TEST_WORKER_ID=3');

        $this->assertSame(3, ParallelTestEnvironment::workerId());
    }

    public function test_returns_database_from_env(): void
    {
        putenv('DB_DATABASE=app_test_w3');

        $this->assertSame('app_test_w3', ParallelTestEnvironment::database());
    }

    public function test_returns_null_database_when_not_set(): void
    {
        $this->assertNull(ParallelTestEnvironment::database());
    }

    public function test_returns_log_directory_from_env(): void
    {
        putenv('TEST_LOG_DIR=/tmp/worker_03');

        $this->assertSame('/tmp/worker_03', ParallelTestEnvironment::logDirectory());
    }

    public function test_returns_suite_from_env(): void
    {
        putenv('TEST_SUITE=stripe');

        $this->assertSame('stripe', ParallelTestEnvironment::suite());
    }

    public function test_returns_null_suite_when_not_set(): void
    {
        $this->assertNull(ParallelTestEnvironment::suite());
    }

    public function test_detects_individual_mode(): void
    {
        putenv('TEST_INDIVIDUAL_MODE=1');

        $this->assertTrue(ParallelTestEnvironment::isIndividualMode());
    }

    public function test_individual_mode_false_by_default(): void
    {
        $this->assertFalse(ParallelTestEnvironment::isIndividualMode());
    }

    public function test_worker_id_string_returns_formatted_id(): void
    {
        putenv('TEST_WORKER_ID=3');

        $this->assertSame('worker_3', ParallelTestEnvironment::workerIdString());
    }

    public function test_worker_id_string_returns_default_when_not_parallel(): void
    {
        $this->assertSame('default', ParallelTestEnvironment::workerIdString());
    }

    public function test_reset_clears_cached_state(): void
    {
        putenv('TEST_WORKER_ID=5');
        $this->assertSame(5, ParallelTestEnvironment::workerId());

        putenv('TEST_WORKER_ID=9');
        ParallelTestEnvironment::reset();

        $this->assertSame(9, ParallelTestEnvironment::workerId());
    }
}
