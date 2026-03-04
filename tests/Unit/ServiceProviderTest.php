<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit;

use Haakco\ParallelTestRunner\Contracts\DatabaseProvisionerInterface;
use Haakco\ParallelTestRunner\Contracts\DatabaseSeederInterface;
use Haakco\ParallelTestRunner\Contracts\ResultAggregatorInterface;
use Haakco\ParallelTestRunner\Contracts\SchemaLoaderInterface;
use Haakco\ParallelTestRunner\Contracts\TestRunReportWriterInterface;
use Haakco\ParallelTestRunner\Contracts\WorkerExecutorInterface;
use Haakco\ParallelTestRunner\Database\NullDatabaseSeeder;
use Haakco\ParallelTestRunner\Database\ParallelBatchProvisioner;
use Haakco\ParallelTestRunner\Database\SchemaDumpLoader;
use Haakco\ParallelTestRunner\Database\SequentialMigrateFreshProvisioner;
use Haakco\ParallelTestRunner\ParallelTestRunnerServiceProvider;
use Haakco\ParallelTestRunner\Reporting\MarkdownReportWriter;
use Haakco\ParallelTestRunner\Services\HookDispatcher;
use Haakco\ParallelTestRunner\Services\JsonFileResultAggregator;
use Haakco\ParallelTestRunner\Services\SymfonyProcessWorkerExecutor;
use Haakco\ParallelTestRunner\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_service_provider_is_registered(): void
    {
        $providers = $this->app->getLoadedProviders();

        $this->assertArrayHasKey(ParallelTestRunnerServiceProvider::class, $providers);
    }

    public function test_database_provisioner_is_bound(): void
    {
        $provisioner = $this->app->make(DatabaseProvisionerInterface::class);
        $this->assertInstanceOf(SequentialMigrateFreshProvisioner::class, $provisioner);
    }

    public function test_schema_loader_is_bound(): void
    {
        $loader = $this->app->make(SchemaLoaderInterface::class);
        $this->assertInstanceOf(SchemaDumpLoader::class, $loader);
    }

    public function test_database_seeder_is_bound(): void
    {
        $seeder = $this->app->make(DatabaseSeederInterface::class);
        $this->assertInstanceOf(NullDatabaseSeeder::class, $seeder);
    }

    public function test_custom_provisioner_from_config(): void
    {
        config()->set('parallel-test-runner.parallel.provisioner', ParallelBatchProvisioner::class);

        // Clear the cached singleton so the provider re-resolves
        $this->app->forgetInstance(DatabaseProvisionerInterface::class);

        $provisioner = $this->app->make(DatabaseProvisionerInterface::class);
        $this->assertInstanceOf(ParallelBatchProvisioner::class, $provisioner);
    }

    public function test_custom_seeder_from_config(): void
    {
        // NullDatabaseSeeder is the default, verify it works through config
        config()->set('parallel-test-runner.parallel.seeder', NullDatabaseSeeder::class);

        $this->app->forgetInstance(DatabaseSeederInterface::class);

        $seeder = $this->app->make(DatabaseSeederInterface::class);
        $this->assertInstanceOf(NullDatabaseSeeder::class, $seeder);
    }

    public function test_worker_executor_is_bound(): void
    {
        $executor = $this->app->make(WorkerExecutorInterface::class);
        $this->assertInstanceOf(SymfonyProcessWorkerExecutor::class, $executor);
    }

    public function test_result_aggregator_is_bound(): void
    {
        $aggregator = $this->app->make(ResultAggregatorInterface::class);
        $this->assertInstanceOf(JsonFileResultAggregator::class, $aggregator);
    }

    public function test_report_writer_is_bound(): void
    {
        $writer = $this->app->make(TestRunReportWriterInterface::class);
        $this->assertInstanceOf(MarkdownReportWriter::class, $writer);
    }

    public function test_hook_dispatcher_is_singleton(): void
    {
        $dispatcher1 = $this->app->make(HookDispatcher::class);
        $dispatcher2 = $this->app->make(HookDispatcher::class);
        $this->assertSame($dispatcher1, $dispatcher2);
    }
}
