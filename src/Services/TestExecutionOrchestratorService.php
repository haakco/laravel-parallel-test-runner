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
use Illuminate\Support\Facades\File;
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

        $totals = $this->tracker->getTotals();
        $tests = (int) ($totals['tests'] ?? 0);
        $assertions = (int) ($totals['assertions'] ?? 0);
        $errors = (int) ($totals['errors'] ?? 0);
        $failures = (int) ($totals['failures'] ?? 0);
        $skipped = (int) ($totals['skipped'] ?? 0);

        $failureDetails = $this->buildFailureDetails();

        $summary = $successful ? 'All tests passed' : 'Some tests failed';

        return $successful
            ? TestRunResultData::success($summary, $duration, $tests, $assertions, $logDirectory)
            : TestRunResultData::failure($summary, $duration, 1, $errors, $failures, $tests, $assertions, $skipped, $logDirectory, $failureDetails);
    }

    /** @return list<array{section: string, summary: string, rerun_command: string}> */
    private function buildFailureDetails(): array
    {
        $executionData = $this->tracker->getExecutionData();
        $sections = $executionData['sections'] ?? [];
        $failures = [];

        foreach ($sections as $name => $section) {
            if (($section['status'] ?? '') !== 'failed') {
                continue;
            }

            $results = $section['results'] ?? [];
            $errors = (int) ($results['errors'] ?? 0);
            $failureCount = (int) ($results['failures'] ?? 0);

            $failures[] = [
                'section' => (string) $name,
                'summary' => sprintf('E:%d F:%d', $errors, $failureCount),
                'rerun_command' => sprintf(
                    "php artisan test:run-sections --section='%s' --individual --parallel=1 --fail-fast",
                    (string) $name,
                ),
            ];
        }

        return $failures;
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

        $runningResult = $this->existingBackgroundRunResult($pidFile);

        if ($runningResult instanceof \Haakco\ParallelTestRunner\Data\Results\BackgroundRunStartResultData) {
            return $runningResult;
        }

        [$logDir, $createdLogDir] = $this->createBackgroundLogDirectory($options);

        $fullCommand = sprintf(
            'cd %s && nohup env %s %s > %s/runner.log 2>&1 & echo $!',
            escapeshellarg(base_path()),
            $this->buildBackgroundEnvironmentPrefix($options),
            $this->buildBackgroundCommand($commandOptions, $logDir),
            escapeshellarg($logDir),
        );
        $pid = $this->resolveBackgroundProcessId(shell_exec($fullCommand));

        if ($pid === null) {
            if ($createdLogDir) {
                File::deleteDirectory($logDir);
            }

            return BackgroundRunStartResultData::failure('Unable to start background test run');
        }

        file_put_contents($pidFile, (string) $pid);

        $lockFile = (string) config(
            'parallel-test-runner.background.lock_file',
            storage_path('test-runner.lock'),
        );
        file_put_contents($lockFile, $logDir);

        return BackgroundRunStartResultData::success($pid, $logDir);
    }

    private function existingBackgroundRunResult(string $pidFile): ?BackgroundRunStartResultData
    {
        if (! file_exists($pidFile)) {
            return null;
        }

        $pid = (int) file_get_contents($pidFile);

        if (function_exists('posix_kill') && posix_kill($pid, 0)) {
            return BackgroundRunStartResultData::failure(
                sprintf('Test runner is already running (PID: %d)', $pid),
            );
        }

        unlink($pidFile);

        return null;
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function createBackgroundLogDirectory(TestRunOptionsData $options): array
    {
        $logDir = $options->logDirectory ?? $this->newBackgroundLogDirectory();
        $createdLogDir = ! is_dir($logDir);

        if ($createdLogDir && ! @mkdir($logDir, 0755, true) && ! is_dir($logDir)) {
            throw new \RuntimeException(sprintf('Unable to create test log directory [%s].', $logDir));
        }

        return [$logDir, $createdLogDir];
    }

    private function newBackgroundLogDirectory(): string
    {
        return base_path('test-logs/' . sprintf('%s_%s', date('Ymd_His_u'), bin2hex(random_bytes(3))));
    }

    /**
     * @param array<string, mixed> $commandOptions
     */
    private function buildBackgroundCommand(array $commandOptions, string $logDir): string
    {
        $mainCommand = (string) config('parallel-test-runner.commands.main', 'test:run-sections');
        $commandParts = ['php', 'artisan', $mainCommand];

        foreach ($commandOptions as $key => $value) {
            $argument = $this->backgroundCommandArgument((string) $key, $value);

            if ($argument !== null) {
                $commandParts[] = $argument;
            }
        }

        $commandParts[] = '--log-dir=' . escapeshellarg($logDir);

        return implode(' ', $commandParts);
    }

    private function backgroundCommandArgument(string $key, mixed $value): ?string
    {
        if (in_array($key, ['background', 'log-dir'], true) || $value === false || $value === null) {
            return null;
        }

        return $value === true ? "--{$key}" : "--{$key}=" . escapeshellarg((string) $value);
    }

    private function buildBackgroundEnvironmentPrefix(TestRunOptionsData $options): string
    {
        $environment = $this->config->getProcessEnvironment()->all();

        if ($options->debug) {
            $environment['DEBUG'] = '1';
        }

        if ($options->debugNative) {
            $environment['USE_ZEND_ALLOC'] = '0';
            $environment['NATIVE_DEBUG'] = '1';
        }

        if ($options->ignoreLock) {
            $environment['TEST_IGNORE_MIGRATION_LOCK'] = '1';
        }

        return collect($environment)
            ->map(static fn(string $value, string $key): string => sprintf('%s=%s', $key, escapeshellarg($value)))
            ->values()
            ->implode(' ');
    }

    private function resolveBackgroundProcessId(string|false|null $pidOutput): ?int
    {
        if (! is_string($pidOutput)) {
            return null;
        }

        $pid = filter_var(trim($pidOutput), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $pid === false ? null : (int) $pid;
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
            $exitCode = $this->runSectionCommand($command, $logFile, $timeoutSeconds);
            $timedOut = $exitCode === 124;
        } catch (ProcessTimedOutException) {
            $timedOut = true;
            $exitCode = 124;
        }

        $duration = microtime(true) - $startTime;
        $parsed = $this->parseSectionLog($logFile);

        return new SectionResultData(
            success: $exitCode === 0,
            tests: $parsed->tests,
            assertions: $parsed->assertions,
            errors: $parsed->errors,
            failures: $parsed->failures,
            skipped: $parsed->skipped,
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

    /**
     * @param list<string> $command
     */
    private function runSectionCommand(array $command, string $logFile, int $timeoutSeconds): int
    {
        $logHandle = fopen($logFile, 'w');

        try {
            return Process::command($command)
                ->timeout($timeoutSeconds + 5)
                ->tty(false)
                ->env($this->config->getProcessEnvironment()->all())
                ->run(null, function ($type, $data) use ($logHandle): void {
                    if ($data && $logHandle) {
                        fwrite($logHandle, $data);
                    }
                })
                ->exitCode();
        } finally {
            if ($logHandle) {
                fclose($logHandle);
            }
        }
    }

    private function parseSectionLog(string $logFile): \Haakco\ParallelTestRunner\Data\ParsedTestOutputData
    {
        if (! file_exists($logFile)) {
            return $this->outputParser->parseOutput('');
        }

        $logContent = file_get_contents($logFile);

        return $this->outputParser->parseOutput($logContent === false ? '' : $logContent);
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
                    'command_args' => $this->buildReportCommandArgs(),
                    'run_started_at' => date(DATE_ATOM, (int) $runStartedAt),
                    'run_finished_at' => date(DATE_ATOM, (int) $runFinishedAt),
                    'run_duration_seconds' => round($runDurationSeconds, 6),
                    'workers_requested' => $this->config->parallelProcesses,
                    'workers_started' => $this->config->parallelProcesses,
                    'provision_mode' => $this->config->parallelProcesses > 1
                        ? 'parallel-migrate-and-seed'
                        : 'sequential-migrate-fresh',
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

    /**
     * @return list<string>
     */
    private function buildReportCommandArgs(): array
    {
        $arguments = [
            '--parallel=' . $this->config->parallelProcesses,
        ];

        if ($this->config->failFast) {
            $arguments[] = '--fail-fast';
        }

        $filter = $this->config->options['filter'] ?? null;
        if (is_string($filter) && $filter !== '') {
            $arguments[] = '--filter=' . $filter;
        }

        $testSuite = $this->config->options['testsuite'] ?? null;
        if (is_string($testSuite) && $testSuite !== '') {
            $arguments[] = '--testsuite=' . $testSuite;
        }

        return $arguments;
    }
}
