<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner;

use Haakco\ParallelTestRunner\Commands\TestCommand;
use Haakco\ParallelTestRunner\Commands\TestReportValidateCommand;
use Haakco\ParallelTestRunner\Commands\TestRunSectionsCommand;
use Haakco\ParallelTestRunner\Commands\TestRunWorkerCommand;
use Haakco\ParallelTestRunner\Contracts\DatabaseProvisionerInterface;
use Haakco\ParallelTestRunner\Contracts\DatabaseSeederInterface;
use Haakco\ParallelTestRunner\Contracts\PerformanceMetricRepositoryInterface;
use Haakco\ParallelTestRunner\Contracts\ResultAggregatorInterface;
use Haakco\ParallelTestRunner\Contracts\SchemaLoaderInterface;
use Haakco\ParallelTestRunner\Contracts\SectionResolverInterface;
use Haakco\ParallelTestRunner\Contracts\TestRunReportWriterInterface;
use Haakco\ParallelTestRunner\Contracts\WorkerExecutorInterface;
use Haakco\ParallelTestRunner\Database\NullDatabaseSeeder;
use Haakco\ParallelTestRunner\Database\SchemaDumpLoader;
use Haakco\ParallelTestRunner\Database\SequentialMigrateFreshProvisioner;
use Haakco\ParallelTestRunner\Reporting\MarkdownReportWriter;
use Haakco\ParallelTestRunner\Scheduling\JsonFilePerformanceMetricRepository;
use Haakco\ParallelTestRunner\Scheduling\ParallelSectionScheduler;
use Haakco\ParallelTestRunner\Sections\ConfigurableSectionResolver;
use Haakco\ParallelTestRunner\Services\HangingTestDetectorService;
use Haakco\ParallelTestRunner\Services\HookDispatcher;
use Haakco\ParallelTestRunner\Services\JsonFileResultAggregator;
use Haakco\ParallelTestRunner\Services\ParallelTestCoordinatorService;
use Haakco\ParallelTestRunner\Services\SymfonyProcessWorkerExecutor;
use Haakco\ParallelTestRunner\Services\TestDatabaseManagerService;
use Haakco\ParallelTestRunner\Services\TestExecutionOrchestratorService;
use Haakco\ParallelTestRunner\Services\TestExecutionTracker;
use Haakco\ParallelTestRunner\Services\TestRunnerConfigurationService;
use Haakco\ParallelTestRunner\Services\TestRunnerService;
use Illuminate\Support\ServiceProvider;
use Override;

class ParallelTestRunnerServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/parallel-test-runner.php',
            'parallel-test-runner',
        );

        $this->registerSchemaLoader();
        $this->registerDatabaseSeeder();
        $this->registerDatabaseProvisioner();
        $this->registerExecutorAndAggregator();
        $this->registerHookDispatcher();
        $this->registerSectionResolver();
        $this->registerPerformanceMetrics();
        $this->registerOrchestrationServices();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/parallel-test-runner.php' => config_path('parallel-test-runner.php'),
            ], 'parallel-test-runner-config');

            $commands = [
                TestRunSectionsCommand::class,
                TestRunWorkerCommand::class,
                TestReportValidateCommand::class,
            ];

            if (config('parallel-test-runner.override_test_command', true)) {
                $commands[] = TestCommand::class;
            }

            $this->commands($commands);
        }
    }

    private function registerSchemaLoader(): void
    {
        $this->app->singleton(SchemaLoaderInterface::class, SchemaDumpLoader::class);
    }

    private function registerDatabaseSeeder(): void
    {
        $this->app->singleton(DatabaseSeederInterface::class, function (): DatabaseSeederInterface {
            /** @var class-string<DatabaseSeederInterface>|null $seederClass */
            $seederClass = config('parallel-test-runner.parallel.seeder');

            return $this->app->make($seederClass ?? NullDatabaseSeeder::class);
        });
    }

    private function registerDatabaseProvisioner(): void
    {
        $this->app->singleton(DatabaseProvisionerInterface::class, function (): DatabaseProvisionerInterface {
            /** @var class-string<DatabaseProvisionerInterface>|null $provisionerClass */
            $provisionerClass = config('parallel-test-runner.parallel.provisioner');

            return $this->app->make($provisionerClass ?? SequentialMigrateFreshProvisioner::class);
        });
    }

    private function registerExecutorAndAggregator(): void
    {
        $this->app->singleton(WorkerExecutorInterface::class, SymfonyProcessWorkerExecutor::class);
        $this->app->singleton(ResultAggregatorInterface::class, JsonFileResultAggregator::class);
        $this->app->singleton(TestRunReportWriterInterface::class, MarkdownReportWriter::class);
    }

    private function registerHookDispatcher(): void
    {
        $this->app->singleton(HookDispatcher::class);
    }

    private function registerSectionResolver(): void
    {
        $this->app->singleton(SectionResolverInterface::class, function (): SectionResolverInterface {
            /** @var class-string<SectionResolverInterface>|null $resolverClass */
            $resolverClass = config('parallel-test-runner.sections.resolver');

            return $this->app->make($resolverClass ?? ConfigurableSectionResolver::class);
        });
    }

    private function registerPerformanceMetrics(): void
    {
        $this->app->singleton(PerformanceMetricRepositoryInterface::class, function (): PerformanceMetricRepositoryInterface {
            /** @var class-string<PerformanceMetricRepositoryInterface>|null $repoClass */
            $repoClass = config('parallel-test-runner.metrics.repository');

            if ($repoClass !== null && $repoClass !== '') {
                return $this->app->make($repoClass);
            }

            /** @var string $weightsFile */
            $weightsFile = config('parallel-test-runner.metrics.weights_file')
                ?? storage_path('test-metadata/section-weights.json');

            return new JsonFilePerformanceMetricRepository($weightsFile);
        });

        $this->app->singleton(ParallelSectionScheduler::class);
    }

    private function registerOrchestrationServices(): void
    {
        $this->app->singleton(TestExecutionTracker::class, function (): TestExecutionTracker {
            /** @var string $logDir */
            $logDir = config('parallel-test-runner.log_directory', storage_path('test-logs'));

            return new TestExecutionTracker($logDir);
        });

        $this->app->singleton(TestRunnerConfigurationService::class);
        $this->app->singleton(TestDatabaseManagerService::class);
        $this->app->singleton(HangingTestDetectorService::class);
        $this->app->singleton(ParallelTestCoordinatorService::class);
        $this->app->singleton(TestExecutionOrchestratorService::class);
        $this->app->singleton(TestRunnerService::class);
    }
}
