<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Testing;

use Haakco\ParallelTestRunner\Support\ParallelTestEnvironment;
use Haakco\ParallelTestRunner\Testing\ParallelTestRunnerExtension;
use Haakco\ParallelTestRunner\Tests\TestCase;

final class ParallelTestRunnerExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        ParallelTestEnvironment::reset();
        putenv('TEST_WORKER_ID');
        putenv('DB_DATABASE');
        parent::tearDown();
    }

    public function test_extension_can_be_instantiated(): void
    {
        $extension = new ParallelTestRunnerExtension();

        $this->assertInstanceOf(ParallelTestRunnerExtension::class, $extension);
    }

    public function test_configure_worker_database_sets_config(): void
    {
        putenv('TEST_WORKER_ID=2');
        putenv('DB_DATABASE=app_test_w2');
        ParallelTestEnvironment::reset();

        $extension = new ParallelTestRunnerExtension();
        $extension->configureWorkerDatabase();

        $this->assertSame('app_test_w2', config('database.connections.pgsql_testing.database'));
    }

    public function test_configure_worker_database_noop_when_not_parallel(): void
    {
        $extension = new ParallelTestRunnerExtension();

        // Should not throw or change anything
        $extension->configureWorkerDatabase();

        $this->assertNull(ParallelTestEnvironment::workerId());
    }

    public function test_configure_worker_database_uses_custom_connection(): void
    {
        putenv('TEST_WORKER_ID=1');
        putenv('DB_DATABASE=custom_test_w1');
        ParallelTestEnvironment::reset();

        config()->set('parallel-test-runner.database.connection', 'mysql_testing');

        $extension = new ParallelTestRunnerExtension();
        $extension->configureWorkerDatabase();

        $this->assertSame('custom_test_w1', config('database.connections.mysql_testing.database'));
    }
}
