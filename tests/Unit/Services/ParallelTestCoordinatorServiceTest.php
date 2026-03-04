<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Services;

use Haakco\ParallelTestRunner\Contracts\DatabaseProvisionerInterface;
use Haakco\ParallelTestRunner\Data\CleanupContext;
use Haakco\ParallelTestRunner\Data\ProvisionContext;
use Haakco\ParallelTestRunner\Data\RunnerConfiguration;
use Haakco\ParallelTestRunner\Data\TestSectionData;
use Haakco\ParallelTestRunner\Scheduling\ParallelSectionScheduler;
use Haakco\ParallelTestRunner\Services\HookDispatcher;
use Haakco\ParallelTestRunner\Services\ParallelTestCoordinatorService;
use Haakco\ParallelTestRunner\Services\TestExecutionTracker;
use Haakco\ParallelTestRunner\Services\TestRunnerConfigurationService;
use Haakco\ParallelTestRunner\Services\TestRunnerState;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Console\OutputStyle;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class ParallelTestCoordinatorServiceTest extends TestCase
{
    public function test_constructor_accepts_dependencies(): void
    {
        $service = $this->createCoordinator();

        $this->assertInstanceOf(ParallelTestCoordinatorService::class, $service);
    }

    public function test_coordinator_creates_provision_context_from_config(): void
    {
        $logDir = sys_get_temp_dir() . '/ptr-test-' . uniqid();
        mkdir($logDir, 0755, true);

        try {
            $configService = $this->createConfigService();
            $configService->setParallelProcesses(4);

            $provisionCalled = false;
            $provisioner = $this->createMock(DatabaseProvisionerInterface::class);
            $provisioner->expects($this->once())
                ->method('provision')
                ->willReturnCallback(function (int $workerCount, ProvisionContext $context) use (&$provisionCalled): array {
                    $provisionCalled = true;
                    $this->assertSame(4, $workerCount);
                    $this->assertSame('pgsql_testing', $context->connection);
                    $this->assertSame('app_test', $context->baseName);

                    return [1 => 'db_w1', 2 => 'db_w2', 3 => 'db_w3', 4 => 'db_w4'];
                });

            $provisioner->expects($this->once())
                ->method('cleanup')
                ->willReturnCallback(function (CleanupContext $context): void {
                    $this->assertFalse($context->keepDatabases);
                });

            $scheduler = $this->createStub(ParallelSectionScheduler::class);
            $scheduler->method('createWorkerPlans')
                ->willReturn([]);

            $state = new TestRunnerState();
            $tracker = new TestExecutionTracker($logDir);
            $hookDispatcher = $this->app->make(HookDispatcher::class);

            $service = new ParallelTestCoordinatorService(
                $configService,
                $state,
                $tracker,
                $provisioner,
                $scheduler,
                $hookDispatcher,
            );

            $output = new OutputStyle(
                new ArrayInput([]),
                new NullOutput(),
            );

            $service->runParallelSections(
                [$this->makeSection('tests/Unit/FooTest')],
                $logDir,
                $output,
            );

            $this->assertTrue($provisionCalled);
        } finally {
            $this->cleanupDir($logDir);
        }
    }

    public function test_coordinator_fires_hooks(): void
    {
        $hooksFired = [];
        config()->set('parallel-test-runner.hooks.before_provision', [
            function () use (&$hooksFired): void {
                $hooksFired[] = 'before_provision';
            },
        ]);
        config()->set('parallel-test-runner.hooks.after_provision', [
            function () use (&$hooksFired): void {
                $hooksFired[] = 'after_provision';
            },
        ]);
        config()->set('parallel-test-runner.hooks.before_cleanup', [
            function () use (&$hooksFired): void {
                $hooksFired[] = 'before_cleanup';
            },
        ]);

        $logDir = sys_get_temp_dir() . '/ptr-test-' . uniqid();
        mkdir($logDir, 0755, true);

        try {
            $service = $this->createCoordinator(logDir: $logDir);
            $output = new OutputStyle(
                new ArrayInput([]),
                new NullOutput(),
            );

            $service->runParallelSections(
                [$this->makeSection('tests/Unit/FooTest')],
                $logDir,
                $output,
            );

            $this->assertContains('before_provision', $hooksFired);
            $this->assertContains('after_provision', $hooksFired);
            $this->assertContains('before_cleanup', $hooksFired);
        } finally {
            $this->cleanupDir($logDir);
        }
    }

    private function createCoordinator(?string $logDir = null): ParallelTestCoordinatorService
    {
        $logDir ??= sys_get_temp_dir() . '/ptr-test-' . uniqid();
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $configService = $this->createConfigService();

        $provisioner = $this->createStub(DatabaseProvisionerInterface::class);
        $provisioner->method('provision')
            ->willReturn([1 => 'test_db_w1']);
        $provisioner->method('cleanup')
            ->willReturnCallback(static fn(): null => null);

        $scheduler = $this->createStub(ParallelSectionScheduler::class);
        $scheduler->method('createWorkerPlans')
            ->willReturn([]);

        return new ParallelTestCoordinatorService(
            $configService,
            new TestRunnerState(),
            new TestExecutionTracker($logDir),
            $provisioner,
            $scheduler,
            $this->app->make(HookDispatcher::class),
        );
    }

    private function createConfigService(): TestRunnerConfigurationService
    {
        $config = new RunnerConfiguration(
            forceSplitDirectories: [],
            phpunitConfigFiles: ['standard' => 'phpunit.xml'],
            weightMultipliers: [],
            scanPaths: ['tests/Unit'],
            maxFilesPerSection: 10,
            baseWeightPerFile: 10.0,
            defaultTimeoutSeconds: 600,
            defaultParallelProcesses: 1,
            dbConnection: 'pgsql_testing',
            dbBaseName: 'app_test',
            dropStrategy: 'with_force',
            useSchemaLoad: true,
        );

        return new TestRunnerConfigurationService($config);
    }

    private function makeSection(string $name): TestSectionData
    {
        return new TestSectionData(
            name: $name,
            type: 'directory',
            path: $name,
            files: [$name . '/FooTest.php'],
            fileCount: 1,
        );
    }

    private function cleanupDir(string $dir): void
    {
        if (is_dir($dir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
            }

            rmdir($dir);
        }
    }
}
