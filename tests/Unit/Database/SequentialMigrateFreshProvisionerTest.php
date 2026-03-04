<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Database;

use Haakco\ParallelTestRunner\Contracts\DatabaseProvisionerInterface;
use Haakco\ParallelTestRunner\Contracts\DatabaseSeederInterface;
use Haakco\ParallelTestRunner\Contracts\SchemaLoaderInterface;
use Haakco\ParallelTestRunner\Data\CleanupContext;
use Haakco\ParallelTestRunner\Data\ProvisionContext;
use Haakco\ParallelTestRunner\Database\SequentialMigrateFreshProvisioner;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;

final class SequentialMigrateFreshProvisionerTest extends TestCase
{
    private MockInterface&SchemaLoaderInterface $schemaLoader;

    private DatabaseSeederInterface&MockInterface $seeder;

    private SequentialMigrateFreshProvisioner $provisioner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaLoader = Mockery::mock(SchemaLoaderInterface::class);
        $this->seeder = Mockery::mock(DatabaseSeederInterface::class);
        $this->provisioner = new SequentialMigrateFreshProvisioner(
            $this->schemaLoader,
            $this->seeder,
        );
    }

    public function test_implements_interface(): void
    {
        $this->assertInstanceOf(DatabaseProvisionerInterface::class, $this->provisioner);
    }

    public function test_throws_on_zero_workers(): void
    {
        $context = $this->makeProvisionContext();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Worker count must be at least 1');

        $this->provisioner->provision(0, $context);
    }

    public function test_throws_on_negative_workers(): void
    {
        $context = $this->makeProvisionContext();

        $this->expectException(RuntimeException::class);

        $this->provisioner->provision(-1, $context);
    }

    public function test_keeps_databases_when_configured(): void
    {
        $cleanupContext = new CleanupContext(
            databases: ['app_test_w1', 'app_test_w2'],
            connection: 'pgsql_testing',
            keepDatabases: true,
            extraOptions: [],
        );

        // Should not call any DB operations — just returns early
        $this->provisioner->cleanup($cleanupContext);

        $this->assertTrue(true);
    }

    public function test_cleanup_with_empty_databases_and_no_provisioned(): void
    {
        $cleanupContext = new CleanupContext(
            databases: [],
            connection: 'pgsql_testing',
            keepDatabases: false,
            extraOptions: [],
        );

        // No databases to clean, should be a no-op
        $this->provisioner->cleanup($cleanupContext);

        $this->assertTrue(true);
    }

    public function test_can_be_resolved_from_container(): void
    {
        $provisioner = $this->app->make(DatabaseProvisionerInterface::class);
        $this->assertInstanceOf(SequentialMigrateFreshProvisioner::class, $provisioner);
    }

    private function makeProvisionContext(array $extraOptions = []): ProvisionContext
    {
        return new ProvisionContext(
            connection: 'pgsql_testing',
            baseName: 'app_test',
            workerCount: 1,
            useSchemaLoad: true,
            dropStrategy: (string) config('parallel-test-runner.database.drop_strategy', 'with_force'),
            extraOptions: $extraOptions,
        );
    }
}
