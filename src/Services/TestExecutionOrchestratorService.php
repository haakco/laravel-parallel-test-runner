<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Services;

use Exception;
use Haakco\ParallelTestRunner\Contracts\TestRunReportWriterInterface;
use Haakco\ParallelTestRunner\Data\Parallel\SectionResultData;
use Haakco\ParallelTestRunner\Data\ReportContext;
use Haakco\ParallelTestRunner\Data\Results\BackgroundRunStartResultData;
use Haakco\ParallelTestRunner\Data\Results\BackgroundRunStatusData;
use Haakco\ParallelTestRunner\Data\Results\SectionListResultData;
use Haakco\ParallelTestRunner\Data\Results\TestRunResultData;
use Haakco\ParallelTestRunner\Data\RunContext;
use Haakco\ParallelTestRunner\Data\TestRunOptionsData;
use Haakco\ParallelTestRunner\Data\TestSectionData;
use Haakco\ParallelTestRunner\Sections\SectionResolutionWorkflow;
use Illuminate\Console\OutputStyle;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class TestExecutionOrchestratorService
{
    public function __construct(
        private readonly TestRunnerConfigurationService $config,
        private readonly TestRunnerState $state,
        private readonly TestExecutionTracker $tracker,
        private readonly TestOutputParserService $outputParser,
        private readonly TestRunReportWriterInterface $reportWriter,
        private readonly ParallelTestCoordinatorService $parallelCoordinator,
        private readonly SectionResolutionWorkflow $sectionResolutionWorkflow,
        private readonly HookDispatcher $hookDispatcher,
    ) {}

    public function runConfigured(string $logDirectory): TestRunResultData
    {
        $start = microtime(true);
        $successful = $this->run(logDirectory: $logDirectory);
        $finished = microtime(true);
        $duration = $finished - $start;

        $this->emitRunReport($logDirectory, $successful, $start, $finished, $duration);

        $summary = $successful ? 'All tests passed' : 'Some tests failed';

        return $successful
            ? TestRunResultData::success($summary, $duration)
            : TestRunResultData::failure($summary, $duration);
    }

    /** @param array<string, mixed> $options */
    public function run(array $options = [], string $logDirectory = ''): bool
    {
        $this->config->options = array_merge($this->config->options, $options);

        return $this->runInternal($logDirectory);
    }

    /** @return Collection<int, TestSectionData> */
    public function listSections(): Collection
    {
        $context = $this->config->createSectionResolutionContext();

        return collect($this->sectionResolutionWorkflow->listSections($context));
    }

    public function listSectionsWithGroups(?int $splitTotal = null, ?int $splitGroup = null): SectionListResultData
    {
        $sections = $this->listSections();

        if ($sections->isEmpty()) {
            return new SectionListResultData(
                sections: [],
                totalFiles: 0,
                totalSections: 0,
            );
        }

        $allSections = $sections->all();

        if ($splitTotal !== null && $splitGroup !== null) {
            $groups = $this->sectionResolutionWorkflow->getSplitGroups($allSections, $splitTotal);
            $groupIndex = $splitGroup - 1;
            $filteredSections = $groups[$groupIndex] ?? [];
            $totalFiles = array_sum(array_map(static fn(TestSectionData $s): int => $s->fileCount, $filteredSections));

            return new SectionListResultData(
                sections: $filteredSections,
                totalFiles: $totalFiles,
                totalSections: count($filteredSections),
            );
        }

        $totalFiles = $sections->sum(static fn(TestSectionData $s): int => $s->fileCount);

        return new SectionListResultData(
            sections: $allSections,
            totalFiles: $totalFiles,
            totalSections: $sections->count(),
        );
    }

    public function checkBackgroundStatus(): BackgroundRunStatusData
    {
        $pidFile = (string) config(
            'parallel-test-runner.background.pid_file',
            storage_path('app/test-runner.pid'),
        );

        if (! file_exists($pidFile)) {
            return BackgroundRunStatusData::notRunning();
        }

        $pid = (int) file_get_contents($pidFile);

        if (! function_exists('posix_kill') || ! posix_kill($pid, 0)) {
            unlink($pidFile);

            return BackgroundRunStatusData::notRunning();
        }

        $lockFile = (string) config(
            'parallel-test-runner.background.lock_file',
            storage_path('test-runner.lock'),
        );
        $logDirectory = file_exists($lockFile) ? (string) file_get_contents($lockFile) : '';

        return BackgroundRunStatusData::running($pid, $logDirectory);
    }

    /** @param array<string, mixed> $commandOptions */
    public function startBackgroundRun(TestRunOptionsData $options, array $commandOptions): BackgroundRunStartResultData
    {
        $pidFile = (string) config(
            'parallel-test-runner.background.pid_file',
            storage_path('app/test-runner.pid'),
        );

        if (file_exists($pidFile)) {
            $pid = (int) file_get_contents($pidFile);
            if (function_exists('posix_kill') && posix_kill($pid, 0)) {
                return BackgroundRunStartResultData::failure(
                    sprintf('Test runner is already running (PID: %d)', $pid),
                );
            }

            unlink($pidFile);
        }

        $logDir = $options->logDirectory ?? base_path('test-logs/' . date('Ymd_His'));
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $mainCommand = (string) config('parallel-test-runner.commands.main', 'test:run-sections');

        $commandParts = ['php', 'artisan', $mainCommand];
        foreach ($commandOptions as $key => $value) {
            if ($key !== 'background' && $key !== 'log-dir' && $value !== false && $value !== null) {
                $commandParts[] = $value === true ? "--{$key}" : "--{$key}=" . escapeshellarg((string) $value);
            }
        }

        $commandParts[] = '--log-dir=' . escapeshellarg($logDir);
        $commandString = implode(' ', $commandParts);

        $fullCommand = sprintf(
            'cd %s && nohup env APP_ENV=testing %s > %s/runner.log 2>&1 & echo $!',
            escapeshellarg(base_path()),
            $commandString,
            escapeshellarg($logDir),
        );
        $pid = (int) shell_exec($fullCommand);

        file_put_contents($pidFile, (string) $pid);

        $lockFile = (string) config(
            'parallel-test-runner.background.lock_file',
            storage_path('test-runner.lock'),
        );
        file_put_contents($lockFile, $logDir);

        return BackgroundRunStartResultData::success($pid, $logDir);
    }

    private function runInternal(string $logDirectory): bool
    {
        $this->tracker->setLogDirectory($logDirectory);
        $this->config->commandStartTime = microtime(true);

        $sections = $this->getTestSections();
        if ($sections === []) {
            return false;
        }

        $runContext = new RunContext(
            logDirectory: $logDirectory,
            command: (string) config('parallel-test-runner.commands.main', 'test:run-sections'),
            commandArgs: [],
            parallel: $this->config->parallelProcesses > 1,
            workerCount: $this->config->parallelProcesses,
            splitTotal: $this->config->splitTotal,
            splitGroup: $this->config->splitGroup,
            extraOptions: [],
        );

        $this->hookDispatcher->fire('before_run', [$runContext]);

        if ($this->config->parallelProcesses > 1 && $this->config->output instanceof OutputStyle) {
            $success = $this->parallelCoordinator->runParallelSections(
                $sections,
                $logDirectory,
                $this->config->output(),
            );

            $this->hookDispatcher->fire('after_run', [$runContext, $success]);

            return $success;
        }

        $this->initializeSectionRun($sections);
        microtime(true);
        $this->processSections($sections, $logDirectory);
        microtime(true);

        $success = $this->finalizeSectionRun();

        $this->hookDispatcher->fire('after_run', [$runContext, $success]);

        return $success;
    }

    /** @return list<TestSectionData> */
    private function getTestSections(): array
    {
        $context = $this->config->createSectionResolutionContext();

        return $this->sectionResolutionWorkflow->sectionsForRun($context);
    }

    /** @param list<TestSectionData> $sections */
    private function initializeSectionRun(array $sections): void
    {
        $sectionNames = array_map(static fn(TestSectionData $s): string => $s->name, $sections);
        $this->state->initialize($sectionNames);

    }

    /** @param list<TestSectionData> $sections */
    private function processSections(array $sections, string $logDirectory): void
    {
        foreach ($sections as $section) {
            $this->state->markRunning($section->name);

            $result = $this->runTestSection($section, $logDirectory);

            if ($result->success) {
                $this->state->markCompleted($section->name);
            } else {
                $this->state->markFailed($section->name);
            }

            $this->tracker->recordSectionResult($section->name, $result);

            if (! $result->success && $this->config->failFast) {
                break;
            }
        }
    }

    private function runTestSection(TestSectionData $section, string $logDirectory): SectionResultData
    {
        $startTime = microtime(true);

        $files = $section->type === 'file' ? [] : $section->files;
        $command = $this->config->buildWrappedCommand(
            $section->path,
            $logDirectory,
            $section->name,
            $files,
        )->all();

        $logFile = $this->getLogFileName($section->name, $logDirectory);
        $timeoutSeconds = max(1, $this->config->timeoutSeconds);
        $timedOut = false;
        $exitCode = 0;

        try {
            $logHandle = fopen($logFile, 'w');

            $processBuilder = Process::command($command)
                ->timeout($timeoutSeconds + 5)
                ->tty(false)
                ->env($this->config->getProcessEnvironment()->all());

            $processResult = $processBuilder->run(null, function ($type, $data) use ($logHandle): void {
                if ($data && $logHandle) {
                    fwrite($logHandle, $data);
                }
            });

            $exitCode = $processResult->exitCode();
            if ($exitCode === 124) {
                $timedOut = true;
            }
        } catch (ProcessTimedOutException) {
            $timedOut = true;
            $exitCode = 124;
        } finally {
            if (isset($logHandle) && $logHandle) {
                fclose($logHandle);
            }
        }

        $duration = microtime(true) - $startTime;

        $tests = 0;
        $assertions = 0;
        $errors = 0;
        $failures = 0;
        $skipped = 0;

        // Parse log output for test results
        if (file_exists($logFile)) {
            $logContent = file_get_contents($logFile);
            if ($logContent !== false && $logContent !== '') {
                $parsed = $this->outputParser->parseOutput($logContent);
                $tests = $parsed->tests;
                $assertions = $parsed->assertions;
                $errors = $parsed->errors;
                $failures = $parsed->failures;
                $skipped = $parsed->skipped;
            }
        }

        return new SectionResultData(
            success: $exitCode === 0,
            tests: $tests,
            assertions: $assertions,
            errors: $errors,
            failures: $failures,
            skipped: $skipped,
            incomplete: 0,
            risky: 0,
            duration: $duration,
            exitCode: $exitCode,
            timedOut: $timedOut,
            logFile: $logFile,
            startedAt: $startTime,
            completedAt: $startTime + $duration,
        );
    }

    private function finalizeSectionRun(): bool
    {
        $totals = $this->tracker->getTotals();
        $hasFailures = ($totals['errors'] ?? 0) > 0 || ($totals['failures'] ?? 0) > 0;

        return ! $hasFailures && $this->state->getFailed() === [];
    }

    private function getLogFileName(string $sectionName, string $logDirectory): string
    {
        $name = Str::slug(str_replace(['/', '\\'], '-', strtolower($sectionName)));

        return $logDirectory . '/' . $name . '.log';
    }

    private function emitRunReport(
        string $logDirectory,
        bool $successful,
        float $runStartedAt,
        float $runFinishedAt,
        float $runDurationSeconds,
    ): void {
        try {
            $this->reportWriter->write(new ReportContext(
                logDirectory: $logDirectory,
                successful: $successful,
                command: (string) config('parallel-test-runner.commands.main', 'test:run-sections'),
                summaryFile: $logDirectory . '/00_SUMMARY.txt',
                extraOptions: [
                    'run_started_at' => date(DATE_ATOM, (int) $runStartedAt),
                    'run_finished_at' => date(DATE_ATOM, (int) $runFinishedAt),
                    'run_duration_seconds' => round($runDurationSeconds, 6),
                    'workers_requested' => $this->config->parallelProcesses,
                    'workers_started' => $this->config->parallelProcesses,
                    'provision_mode' => $this->config->parallelProcesses > 1 ? 'parallel' : 'sequential',
                    'split' => $this->config->splitTotal !== null
                        ? ['total' => $this->config->splitTotal, 'group' => $this->config->splitGroup]
                        : null,
                ],
            ));
        } catch (Exception $exception) {
            Log::warning('Failed to write run report', [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
