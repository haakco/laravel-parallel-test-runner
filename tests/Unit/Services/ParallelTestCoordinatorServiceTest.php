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
use Symfony\Component\Console\Output\BufferedOutput;
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

    public function test_coordinator_passes_ignore_lock_and_keep_databases_into_contexts(): void
    {
        $logDir = sys_get_temp_dir() . '/ptr-test-' . uniqid();
        mkdir($logDir, 0755, true);

        try {
            $configService = $this->createConfigService();
            $configService->setParallelProcesses(2)
                ->setSplitTotal(6)
                ->setSplitGroup(3)
                ->setIgnoreLock(true)
                ->setOptions([
                    'no_refresh_db' => true,
                    'keep_parallel_dbs' => true,
                ]);

            $provisioner = $this->createMock(DatabaseProvisionerInterface::class);
            $provisioner->expects($this->once())
                ->method('provision')
                ->willReturnCallback(function (int $workerCount, ProvisionContext $context): array {
                    $this->assertSame(2, $workerCount);
                    $this->assertTrue($context->extraOptions['no_refresh_db']);
                    $this->assertSame(6, $context->extraOptions['split_total']);
                    $this->assertSame(3, $context->extraOptions['split_group']);
                    $this->assertTrue($context->extraOptions['ignore_lock']);
                    $this->assertIsCallable($context->extraOptions['on_progress']);

                    return [1 => 'db_w1', 2 => 'db_w2'];
                });

            $provisioner->expects($this->once())
                ->method('cleanup')
                ->willReturnCallback(function (CleanupContext $context): void {
                    $this->assertTrue($context->keepDatabases);
                    $this->assertSame(['db_w1', 'db_w2'], $context->databases);
                });

            $scheduler = $this->createStub(ParallelSectionScheduler::class);
            $scheduler->method('createWorkerPlans')
                ->willReturn([]);

            $service = new ParallelTestCoordinatorService(
                $configService,
                new TestRunnerState(),
                new TestExecutionTracker($logDir),
                $provisioner,
                $scheduler,
                $this->app->make(HookDispatcher::class),
            );

            $output = new OutputStyle(new ArrayInput([]), new NullOutput());

            $service->runParallelSections(
                [$this->makeSection('tests/Unit/FooTest')],
                $logDir,
                $output,
            );
        } finally {
            $this->cleanupDir($logDir);
        }
    }

    public function test_coordinator_cleans_up_when_scheduler_throws(): void
    {
        $logDir = sys_get_temp_dir() . '/ptr-test-' . uniqid();
        mkdir($logDir, 0755, true);

        try {
            $configService = $this->createConfigService();
            $configService->setParallelProcesses(1);

            $provisioner = $this->createMock(DatabaseProvisionerInterface::class);
            $provisioner->expects($this->once())
                ->method('provision')
                ->willReturn([1 => 'db_w1']);

            $provisioner->expects($this->once())
                ->method('cleanup')
                ->willReturnCallback(function (CleanupContext $context): void {
                    $this->assertSame(['db_w1'], $context->databases);
                });

            $scheduler = $this->createMock(ParallelSectionScheduler::class);
            $scheduler->expects($this->once())
                ->method('createWorkerPlans')
                ->willThrowException(new \RuntimeException('scheduler failed'));

            $service = new ParallelTestCoordinatorService(
                $configService,
                new TestRunnerState(),
                new TestExecutionTracker($logDir),
                $provisioner,
                $scheduler,
                $this->app->make(HookDispatcher::class),
            );

            $output = new OutputStyle(new ArrayInput([]), new NullOutput());

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('scheduler failed');

            $service->runParallelSections(
                [$this->makeSection('tests/Unit/FooTest')],
                $logDir,
                $output,
            );
        } finally {
            $this->cleanupDir($logDir);
        }
    }

    public function test_coordinator_reports_provisioning_progress_to_output(): void
    {
        $logDir = sys_get_temp_dir() . '/ptr-test-' . uniqid();
        mkdir($logDir, 0755, true);

        try {
            $configService = $this->createConfigService();
            $configService->setParallelProcesses(1);

            $provisioner = $this->createMock(DatabaseProvisionerInterface::class);
            $provisioner->expects($this->once())
                ->method('provision')
                ->willReturnCallback(function (int $workerCount, ProvisionContext $context): array {
                    $callback = $context->extraOptions['on_progress'];
                    $callback('Provisioning database db_w1');

                    return [1 => 'db_w1'];
                });

            $provisioner->expects($this->once())
                ->method('cleanup');

            $scheduler = $this->createStub(ParallelSectionScheduler::class);
            $scheduler->method('createWorkerPlans')
                ->willReturn([]);

            $service = new ParallelTestCoordinatorService(
                $configService,
                new TestRunnerState(),
                new TestExecutionTracker($logDir),
                $provisioner,
                $scheduler,
                $this->app->make(HookDispatcher::class),
            );

            $buffer = new BufferedOutput();
            $output = new OutputStyle(new ArrayInput([]), $buffer);

            $service->runParallelSections(
                [$this->makeSection('tests/Unit/FooTest')],
                $logDir,
                $output,
            );

            $this->assertStringContainsString('Provisioning database db_w1', $buffer->fetch());
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
