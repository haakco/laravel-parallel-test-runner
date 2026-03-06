<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Commands;

use Exception;
use Haakco\ParallelTestRunner\Data\Results\TestRunResultData;
use Haakco\ParallelTestRunner\Data\TestRunOptionsData;
use Haakco\ParallelTestRunner\Data\TestSectionData;
use Haakco\ParallelTestRunner\Services\TestRunnerService;
use Illuminate\Console\Command;

final class TestRunSectionsCommand extends Command
{
    protected $signature = 'test:run-sections
                            {tests?* : Specific test files or directories to run}
                            {--test-file=* : Specific test file(s) to run (legacy alias)}
                            {--debug : Enable debug output with detailed information}
                            {--timeout=600 : Timeout per section in seconds}
                            {--max-files=10 : Maximum files per test run}
                            {--section=* : Run only specific section(s)}
                            {--filter= : PHPUnit filter pattern for test methods}
                            {--testsuite= : PHPUnit test suite to run}
                            {--list : List all test sections without running}
                            {--fail-fast : Stop on first failure}
                            {--find-hanging : Find tests that hang (uses short timeout)}
                            {--background : Run tests in background}
                            {--status : Check status of background run}
                            {--parallel=1 : Number of parallel processes}
                            {--refresh-db : Refresh test database before running}
                            {--no-refresh-db : Skip per-worker database refresh}
                            {--keep-parallel-dbs : Keep parallel databases after run}
                            {--log-dir= : Use specific log directory}
                            {--all : Run all tests including additional suites}
                            {--split-total= : Split tests into N groups}
                            {--split-group= : Run only group X (1-based, requires --split-total)}
                            {--individual : Run each test file individually}
                            {--skip-env-checks : Skip environment validation}
                            {--debug-native : Enable native crash diagnostics}
                            {--emit-metrics=1 : Write runtime metrics (set to 0 to disable)}
                            {--ignore-lock : Skip migration lock}';

    public function __construct(
        private readonly TestRunnerService $testRunner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $splitTotal = $this->resolveSplitTotal();
        $splitGroup = $this->resolveSplitGroup($splitTotal);

        if ($splitGroup === false || $splitTotal === false) {
            return Command::FAILURE;
        }

        $optionsData = $this->buildOptionsData($splitTotal, $splitGroup);

        if ($this->option('status')) {
            return $this->handleBackgroundStatus();
        }

        $dbConnection = (string) config('parallel-test-runner.database.connection', 'pgsql_testing');
        config(['database.default' => $dbConnection]);

        if ($this->option('background')) {
            return $this->handleBackgroundRun($optionsData);
        }

        if ($this->option('refresh-db')) {
            $this->handleDatabaseRefresh();
        }

        $this->testRunner->configure($optionsData, $this->output);

        if ($this->option('find-hanging')) {
            return $this->handleFindHanging();
        }

        if ($this->option('list')) {
            return $this->handleListSections($splitTotal, $splitGroup);
        }

        return $this->handleTestRun($optionsData);
    }

    private function resolveSplitTotal(): false|int|null
    {
        $value = $this->option('split-total');
        if ($value === null) {
            return null;
        }

        $splitTotal = (int) $value;
        if ($splitTotal < 2) {
            $this->error('--split-total must be at least 2');

            return false;
        }

        return $splitTotal;
    }

    private function resolveSplitGroup(false|int|null $splitTotal): false|int|null
    {
        if ($splitTotal === false) {
            return false;
        }

        $value = $this->option('split-group');
        if ($value === null) {
            return null;
        }

        if ($splitTotal === null) {
            $this->error('--split-group requires --split-total to be specified');

            return false;
        }

        $splitGroup = (int) $value;
        if ($splitGroup < 1 || $splitGroup > $splitTotal) {
            $this->error("--split-group must be between 1 and {$splitTotal}");

            return false;
        }

        return $splitGroup;
    }

    private function buildOptionsData(?int $splitTotal, ?int $splitGroup): TestRunOptionsData
    {
        return TestRunOptionsData::fromCommandInput(
            $this->options(),
            ['tests' => $this->argument('tests') ?? []],
            $splitTotal,
            $splitGroup,
            $this->option('log-dir') ? (string) $this->option('log-dir') : null,
        );
    }

    private function handleBackgroundStatus(): int
    {
        $status = $this->testRunner->checkBackgroundStatus();

        if (! $status->running) {
            $this->info('Test runner is not currently running');

            return Command::SUCCESS;
        }

        $this->info(sprintf('Test runner is running (PID: %d)', $status->pid));
        if ($status->logFile !== null) {
            $this->comment(sprintf('Log directory: %s', $status->logFile));
        }

        return Command::SUCCESS;
    }

    private function handleBackgroundRun(TestRunOptionsData $options): int
    {
        $result = $this->testRunner->startBackgroundRun($options, $this->options());

        if (! $result->started) {
            $this->error($result->message);

            return Command::FAILURE;
        }

        $this->info(sprintf('Test runner started in background (PID: %d)', $result->pid));
        $this->comment(sprintf('Log directory: %s', $result->logFile));
        $this->comment("Use 'php artisan test:run-sections --status' to check progress");

        return Command::SUCCESS;
    }

    private function handleDatabaseRefresh(): void
    {
        $result = $this->testRunner->refreshTestDatabase(
            onProgress: fn(string $message) => $this->info($message),
        );

        if ($result->success) {
            $this->info('Database refreshed successfully!');
        } else {
            $this->error(sprintf('Failed to refresh database: %s', $result->message));
        }

        $this->newLine();
    }

    private function handleFindHanging(): int
    {
        $hangingThreshold = (int) config('parallel-test-runner.timeouts.hanging_test_threshold', 10);

        $this->info('Finding hanging tests...');
        $this->comment(sprintf('This will test each section with a %d-second timeout.', $hangingThreshold));
        $this->newLine();

        $result = $this->testRunner->findHangingTests(
            shortTimeout: $hangingThreshold,
            onProgress: function (string $section, int $current, int $total, string $status): void {
                $color = match ($status) {
                    'OK' => 'green',
                    'HANGING' => 'red',
                    default => 'yellow',
                };
                $this->output->write("[{$current}/{$total}] Testing {$section} ... ");
                $this->output->writeln("<fg={$color}>{$status}</>");
            },
        );

        $this->newLine();

        if ($result->found) {
            $this->error(sprintf('Found %d hanging test section(s):', count($result->hangingSections)));
            foreach ($result->hangingSections as $section) {
                $this->line("  - {$section}");
            }

            return Command::FAILURE;
        }

        $this->info('No hanging tests found!');

        return Command::SUCCESS;
    }

    private function handleListSections(?int $splitTotal, ?int $splitGroup): int
    {
        $this->info('Discovering test sections...');

        $result = $this->testRunner->listSectionsWithGroups($splitTotal, $splitGroup);

        if ($result->sections === []) {
            $this->warn('No test sections found.');

            return Command::SUCCESS;
        }

        if ($splitGroup !== null) {
            $this->info(sprintf('Showing group %d of %d:', $splitGroup, $splitTotal));
        }

        $this->table(
            ['Section', 'Type', 'Files', 'Path'],
            array_map(fn(TestSectionData $section): array => [
                $section->name,
                $section->type,
                $section->fileCount,
                str_replace(base_path() . '/', '', $section->path),
            ], $result->sections),
        );

        $this->newLine();
        $this->info(sprintf('Total sections: %d', $result->totalSections));
        $this->info(sprintf('Total test files: %d', $result->totalFiles));

        return Command::SUCCESS;
    }

    private function handleTestRun(TestRunOptionsData $options): int
    {
        $this->info('Starting test execution...');

        try {
            $result = $this->testRunner->runConfigured();

            return $this->renderTestResult($result);
        } catch (Exception $exception) {
            $this->error('Test execution failed: ' . $exception->getMessage());
            if ($options->debug) {
                $this->error($exception->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    private function renderTestResult(TestRunResultData $result): int
    {
        $this->newLine();

        if ($result->success) {
            $this->info('Tests passed!');
        } else {
            $this->error('Tests failed.');
        }

        if ($result->duration > 0) {
            $this->line('Total time: ' . gmdate('H:i:s', (int) $result->duration));
        }

        $this->line($result->summary);

        return $result->exitCode;
    }
}
