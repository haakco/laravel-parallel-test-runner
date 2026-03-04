<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Services;

use Exception;
use Haakco\ParallelTestRunner\Data\Parallel\MetricsTotalsData;
use Haakco\ParallelTestRunner\Data\Parallel\SectionResultData;
use Haakco\ParallelTestRunner\Data\Parallel\WorkerMetricsData;
use Haakco\ParallelTestRunner\Data\Parallel\WorkerPlanData;
use Haakco\ParallelTestRunner\Data\Parallel\WorkerPlanFileData;
use Illuminate\Console\OutputStyle;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use RuntimeException;

final class ParallelTestOrchestrator
{
    /**
     * @var array<int, array{process: InvokedProcess, plan: WorkerPlanData, status: string, completed_sections: int, total_sections: int, output_buffer: string, exit_code?: int, metrics?: WorkerMetricsData}>
     */
    private array $workerProcesses = [];

    /** @var array<string, SectionResultData> */
    private array $sectionResults = [];

    /** @var array{tests: int, assertions: int, errors: int, failures: int, warnings: int, skipped: int, incomplete: int, risky: int, duration: float} */
    private array $aggregatedMetrics = [
        'tests' => 0,
        'assertions' => 0,
        'errors' => 0,
        'failures' => 0,
        'warnings' => 0,
        'skipped' => 0,
        'incomplete' => 0,
        'risky' => 0,
        'duration' => 0,
    ];

    private readonly string $lockFile;

    public function __construct(
        private readonly OutputStyle $output,
        private readonly string $logDirectory,
        private readonly int $timeoutSeconds = 600,
        private readonly bool $debug = false,
        private readonly bool $failFast = false,
    ) {
        $this->lockFile = (string) config(
            'parallel-test-runner.background.lock_file',
            storage_path('test-runner.lock'),
        );
    }

    /** @param list<WorkerPlanData> $workerPlans */
    public function executeWorkerPlans(array $workerPlans): bool
    {
        if ($workerPlans === []) {
            $this->output->warning('No worker plans to execute');

            return true;
        }

        if (! $this->acquireLock()) {
            $this->output->error('Another test run is in progress. Use --status to check or remove ' . $this->lockFile);

            return false;
        }

        try {
            $this->output->info('Starting parallel test execution with ' . count($workerPlans) . ' workers');

            foreach ($workerPlans as $plan) {
                if (! is_dir($plan->logDirectory)) {
                    mkdir($plan->logDirectory, 0755, true);
                }
            }

            $this->startWorkers($workerPlans);
            $success = $this->monitorWorkers();
            $this->aggregateResults();

            return $success;
        } finally {
            $this->releaseLock();
        }
    }

    /** @return array<string, SectionResultData> */
    public function getSectionResults(): array
    {
        return $this->sectionResults;
    }

    /** @return array{tests: int, assertions: int, errors: int, failures: int, warnings: int, skipped: int, incomplete: int, risky: int, duration: float} */
    public function getAggregatedMetrics(): array
    {
        return $this->aggregatedMetrics;
    }

    private function acquireLock(): bool
    {
        if (file_exists($this->lockFile)) {
            $pid = (int) file_get_contents($this->lockFile);

            if ($pid > 0 && function_exists('posix_kill') && posix_kill($pid, 0)) {
                return false;
            }

            unlink($this->lockFile);
        }

        file_put_contents($this->lockFile, (string) getmypid());

        return true;
    }

    private function releaseLock(): void
    {
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
    }

    /** @param list<WorkerPlanData> $workerPlans */
    private function startWorkers(array $workerPlans): void
    {
        $commandLog = [];

        foreach ($workerPlans as $plan) {
            $command = $this->buildWorkerCommand($plan);

            if ($this->debug) {
                $this->output->comment("Worker {$plan->workerId} command: " . implode(' ', $command));
            }

            $environment = $plan->environment();
            $environment['WORKER_SECTIONS'] = json_encode($plan->sectionNames(), JSON_THROW_ON_ERROR);

            /** @var InvokedProcess $process */
            $process = Process::timeout($this->timeoutSeconds)
                ->env($environment)
                ->command($command)
                ->start();

            $this->workerProcesses[$plan->workerId] = [
                'process' => $process,
                'plan' => $plan,
                'status' => 'running',
                'completed_sections' => 0,
                'total_sections' => count($plan->sections),
                'output_buffer' => '',
            ];

            $commandLog[] = [
                'worker_id' => $plan->workerId,
                'command' => $command,
                'log_directory' => $plan->logDirectory,
                'database' => $plan->database,
            ];
        }

        if ($commandLog !== []) {
            file_put_contents(
                $this->logDirectory . '/worker_commands.json',
                json_encode($commandLog, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            );
        }
    }

    /** @return list<string> */
    private function buildWorkerCommand(WorkerPlanData $plan): array
    {
        $workerPlanFile = $plan->logDirectory . '/worker_plan.json';

        if (! is_dir($plan->logDirectory) && ! mkdir($plan->logDirectory, 0755, true) && ! is_dir($plan->logDirectory)) {
            throw new RuntimeException("Unable to create worker log directory: {$plan->logDirectory}");
        }

        $planFileData = WorkerPlanFileData::fromWorkerPlan($plan);
        $written = file_put_contents(
            $workerPlanFile,
            json_encode($planFileData->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
        throw_if(
            $written === false || ! file_exists($workerPlanFile),
            RuntimeException::class,
            "Failed to write worker plan file: {$workerPlanFile}",
        );

        $workerCommand = (string) config('parallel-test-runner.commands.worker', 'test:run-worker');

        $command = [
            'php',
            'artisan',
            $workerCommand,
            '--worker-plan-file=' . $workerPlanFile,
            '--log-dir=' . $plan->logDirectory,
            '--skip-env-checks',
        ];

        if ($this->debug) {
            $command[] = '--debug';
        }

        if ($this->failFast) {
            $command[] = '--fail-fast';
        }

        if ($plan->individual) {
            $command[] = '--individual';
        }

        $command[] = '--timeout=' . $this->timeoutSeconds;

        return $command;
    }

    private function monitorWorkers(): bool
    {
        $allSuccess = true;
        $checkInterval = 100_000; // 0.1 seconds

        while ($this->hasRunningWorkers()) {
            Sleep::usleep($checkInterval);

            foreach ($this->workerProcesses as $workerId => &$worker) {
                if ($worker['status'] !== 'running') {
                    continue;
                }

                $process = $worker['process'];

                if (! $process->running()) {
                    $result = $process->wait();
                    $exitCode = $result->exitCode();

                    $metricsFile = $worker['plan']->logDirectory . '/execution_tracking.json';
                    $hasMetrics = file_exists($metricsFile);

                    if ($hasMetrics) {
                        try {
                            $payload = json_decode(file_get_contents($metricsFile), true, 512, JSON_THROW_ON_ERROR);
                            $worker['metrics'] = WorkerMetricsData::fromExecutionTracking($payload);
                        } catch (Exception) {
                            $hasMetrics = false;
                        }
                    }

                    if ($exitCode !== 0) {
                        $allSuccess = false;

                        if (! $hasMetrics) {
                            $this->output->error("Worker {$workerId} crashed with exit code: {$exitCode}");
                            $worker['status'] = 'crashed';
                        } else {
                            $worker['status'] = 'failed';
                        }

                        if ($this->failFast) {
                            $this->terminateAllWorkers();

                            return false;
                        }
                    } else {
                        $worker['status'] = 'completed';
                    }

                    $worker['exit_code'] = $exitCode;
                }

                $latestOutput = $process->latestOutput();
                if ($latestOutput !== '' && $latestOutput !== '0') {
                    $this->processWorkerOutput($workerId, $latestOutput);
                }
            }
        }

        return $allSuccess;
    }

    private function hasRunningWorkers(): bool
    {
        return array_any(
            $this->workerProcesses,
            static fn(array $worker): bool => $worker['status'] === 'running',
        );
    }

    private function terminateAllWorkers(): void
    {
        $this->output->warning('Terminating all workers...');

        foreach ($this->workerProcesses as &$worker) {
            if ($worker['status'] === 'running') {
                $worker['process']->stop();
                $worker['status'] = 'terminated';
            }
        }
    }

    private function processWorkerOutput(int $workerId, string $outputText): void
    {
        $this->workerProcesses[$workerId]['output_buffer'] .= $outputText;

        if (preg_match('/\[(\d+)\/(\d+)\]/', $outputText, $matches)) {
            $this->workerProcesses[$workerId]['completed_sections'] = (int) $matches[1];
            $this->workerProcesses[$workerId]['total_sections'] = (int) $matches[2];
        }

        if ($this->debug) {
            file_put_contents(
                $this->logDirectory . "/worker{$workerId}_output.log",
                $outputText,
                FILE_APPEND,
            );
        }
    }

    private function aggregateResults(): void
    {
        $this->output->newLine(count($this->workerProcesses) + 2);
        $this->output->info('Aggregating results from all workers...');

        $metricsAggregate = WorkerMetricsData::createEmpty();

        foreach ($this->workerProcesses as $worker) {
            $plan = $worker['plan'];
            $metricsFile = $this->resolveMetricsFile($plan->logDirectory);

            if ($metricsFile === null) {
                $metricsAggregate = $metricsAggregate->accumulate(
                    $this->buildFailureMetricsFromPlan($plan, $worker['exit_code'] ?? 1),
                );

                continue;
            }

            $payload = json_decode(file_get_contents($metricsFile), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                $metricsAggregate = $metricsAggregate->accumulate(
                    $this->buildFailureMetricsFromPlan($plan, $worker['exit_code'] ?? 1),
                );

                continue;
            }

            $metricsAggregate = $metricsAggregate->accumulate(WorkerMetricsData::fromExecutionTracking($payload));
        }

        $this->aggregatedMetrics = array_merge(
            $metricsAggregate->totals->toArray(),
            ['duration' => $metricsAggregate->duration],
        );

        $this->sectionResults = $metricsAggregate->sections;

        $aggregatedFile = $this->logDirectory . '/aggregated_summary.json';
        file_put_contents($aggregatedFile, json_encode([
            'totals' => $metricsAggregate->totals->toArray(),
            'duration' => $metricsAggregate->duration,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function resolveMetricsFile(string $logDir): ?string
    {
        $candidates = [
            $logDir . '/execution_tracking.json',
            $logDir . '/00_TRACKING.json',
            $logDir . '/summary.json',
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function buildFailureMetricsFromPlan(WorkerPlanData $plan, int $exitCode): WorkerMetricsData
    {
        $sections = [];

        foreach ($plan->sections as $section) {
            $sections[$section->name] = new SectionResultData(
                success: false,
                tests: 0,
                assertions: 0,
                errors: 0,
                failures: 1,
                skipped: 0,
                incomplete: 0,
                risky: 0,
                duration: 0.0,
                exitCode: $exitCode,
                timedOut: false,
                logFile: '',
                startedAt: 0.0,
                completedAt: 0.0,
            );
        }

        return new WorkerMetricsData(
            totals: new MetricsTotalsData(
                tests: 0,
                assertions: 0,
                errors: 0,
                failures: count($sections),
                warnings: 0,
                skipped: 0,
                incomplete: 0,
                risky: 0,
            ),
            sections: $sections,
            duration: 0.0,
        );
    }
}
