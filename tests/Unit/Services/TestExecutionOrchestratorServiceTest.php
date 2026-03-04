<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Services;

use Haakco\ParallelTestRunner\Contracts\DatabaseProvisionerInterface;
use Haakco\ParallelTestRunner\Contracts\SectionResolverInterface;
use Haakco\ParallelTestRunner\Contracts\TestRunReportWriterInterface;
use Haakco\ParallelTestRunner\Data\RunnerConfiguration;
use Haakco\ParallelTestRunner\Data\TestSectionData;
use Haakco\ParallelTestRunner\Scheduling\ParallelSectionScheduler;
use Haakco\ParallelTestRunner\Sections\SectionResolutionWorkflow;
use Haakco\ParallelTestRunner\Services\HookDispatcher;
use Haakco\ParallelTestRunner\Services\ParallelTestCoordinatorService;
use Haakco\ParallelTestRunner\Services\TestExecutionOrchestratorService;
use Haakco\ParallelTestRunner\Services\TestExecutionTracker;
use Haakco\ParallelTestRunner\Services\TestOutputParserService;
use Haakco\ParallelTestRunner\Services\TestRunnerConfigurationService;
use Haakco\ParallelTestRunner\Services\TestRunnerState;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Console\OutputStyle;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class TestExecutionOrchestratorServiceTest extends TestCase
{
    private string $logDir;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = sys_get_temp_dir() . '/ptr-exec-test-' . uniqid();
        mkdir($this->logDir, 0755, true);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->cleanupDir($this->logDir);
        parent::tearDown();
    }

    public function test_constructor_accepts_dependencies(): void
    {
        $service = $this->createService();

        $this->assertInstanceOf(TestExecutionOrchestratorService::class, $service);
    }

    public function test_list_sections_returns_collection(): void
    {
        $sections = [
            new TestSectionData('Unit/Foo', 'directory', 'tests/Unit/Foo', ['FooTest.php'], 1),
            new TestSectionData('Unit/Bar', 'directory', 'tests/Unit/Bar', ['BarTest.php'], 1),
        ];

        $service = $this->createService(sections: $sections);

        $result = $service->listSections();

        $this->assertCount(2, $result);
        $this->assertSame('Unit/Foo', $result->first()->name);
    }

    public function test_list_sections_with_groups_returns_section_list_result(): void
    {
        $sections = [
            new TestSectionData('Unit/Foo', 'directory', 'tests/Unit/Foo', ['FooTest.php'], 1),
            new TestSectionData('Unit/Bar', 'directory', 'tests/Unit/Bar', ['BarTest.php'], 1),
        ];

        $service = $this->createService(sections: $sections);

        $result = $service->listSectionsWithGroups();

        $this->assertSame(2, $result->totalSections);
        $this->assertSame(2, $result->totalFiles);
        $this->assertCount(2, $result->sections);
    }

    public function test_list_sections_with_groups_and_split(): void
    {
        $sections = [
            new TestSectionData('Unit/Foo', 'directory', 'tests/Unit/Foo', ['FooTest.php'], 1),
            new TestSectionData('Unit/Bar', 'directory', 'tests/Unit/Bar', ['BarTest.php'], 1),
            new TestSectionData('Unit/Baz', 'directory', 'tests/Unit/Baz', ['BazTest.php'], 1),
        ];

        $service = $this->createService(sections: $sections);

        $result = $service->listSectionsWithGroups(splitTotal: 2, splitGroup: 1);

        $this->assertLessThanOrEqual(3, $result->totalSections);
        $this->assertGreaterThan(0, $result->totalSections);
    }

    public function test_list_sections_empty_returns_empty_result(): void
    {
        $service = $this->createService(sections: []);

        $result = $service->listSectionsWithGroups();

        $this->assertSame(0, $result->totalSections);
        $this->assertSame(0, $result->totalFiles);
        $this->assertSame([], $result->sections);
    }

    public function test_check_background_status_returns_not_running(): void
    {
        config()->set('parallel-test-runner.background.pid_file', sys_get_temp_dir() . '/nonexistent-' . uniqid() . '.pid');

        $service = $this->createService();

        $status = $service->checkBackgroundStatus();

        $this->assertFalse($status->running);
        $this->assertNull($status->pid);
    }

    public function test_check_background_status_cleans_stale_pid(): void
    {
        $pidFile = sys_get_temp_dir() . '/ptr-test-stale-' . uniqid() . '.pid';
        file_put_contents($pidFile, '999999999');

        config()->set('parallel-test-runner.background.pid_file', $pidFile);

        $service = $this->createService();
        $status = $service->checkBackgroundStatus();

        $this->assertFalse($status->running);
        $this->assertFileDoesNotExist($pidFile);
    }

    public function test_run_configured_returns_result_for_no_sections(): void
    {
        $service = $this->createService(sections: []);

        $result = $service->runConfigured($this->logDir);

        $this->assertFalse($result->success);
    }

    /**
     * @param list<TestSectionData> $sections
     */
    private function createService(array $sections = []): TestExecutionOrchestratorService
    {
        $config = new RunnerConfiguration(
            forceSplitDirectories: [],
            phpunitConfigFiles: ['standard' => 'phpunit.xml'],
            weightMultipliers: [],
            scanPaths: ['tests/Unit'],
            maxFilesPerSection: 10,
            baseWeightPerFile: 10.0,
            defaultTimeoutSeconds: 60,
            defaultParallelProcesses: 1,
            dbConnection: 'pgsql_testing',
            dbBaseName: 'app_test',
            dropStrategy: 'with_force',
            useSchemaLoad: true,
        );

        $configService = new TestRunnerConfigurationService($config);
        $output = new OutputStyle(new ArrayInput([]), new NullOutput());
        $configService->setOutput($output);

        $state = new TestRunnerState();
        $tracker = new TestExecutionTracker($this->logDir);
        $outputParser = new TestOutputParserService();

        $reportWriter = $this->createStub(TestRunReportWriterInterface::class);

        // Build real coordinator with stubbed provisioner
        $provisioner = $this->createStub(DatabaseProvisionerInterface::class);
        $provisioner->method('provision')->willReturn([]);
        $provisioner->method('cleanup')->willReturnCallback(static fn(): null => null);

        $scheduler = $this->createStub(ParallelSectionScheduler::class);
        $scheduler->method('createWorkerPlans')->willReturn([]);

        $parallelCoordinator = new ParallelTestCoordinatorService(
            $configService,
            $state,
            $tracker,
            $provisioner,
            $scheduler,
            $this->app->make(HookDispatcher::class),
        );

        // Create SectionResolver stub
        $resolver = $this->createStub(SectionResolverInterface::class);
        $resolver->method('resolve')->willReturn($sections);

        $sectionWorkflow = new SectionResolutionWorkflow($resolver);

        $hookDispatcher = $this->app->make(HookDispatcher::class);

        return new TestExecutionOrchestratorService(
            $configService,
            $state,
            $tracker,
            $outputParser,
            $reportWriter,
            $parallelCoordinator,
            $sectionWorkflow,
            $hookDispatcher,
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
