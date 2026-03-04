<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Services;

use Haakco\ParallelTestRunner\Contracts\WorkerExecutorInterface;
use Haakco\ParallelTestRunner\Data\Parallel\WorkerPlanData;
use Haakco\ParallelTestRunner\Data\Results\WorkerResultData;
use Override;
use RuntimeException;

/**
 * Worker executor that builds and runs Symfony Process commands.
 *
 * Phase 1d implements command construction and environment building.
 * Actual process execution (start/monitor/collect) is deferred to Phase 1e.
 */
final class SymfonyProcessWorkerExecutor implements WorkerExecutorInterface
{
    private bool $debug = false;

    private bool $failFast = false;

    private int $timeoutSeconds = 600;

    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;

        return $this;
    }

    public function setFailFast(bool $failFast): self
    {
        $this->failFast = $failFast;

        return $this;
    }

    public function setTimeoutSeconds(int $timeoutSeconds): self
    {
        $this->timeoutSeconds = $timeoutSeconds;

        return $this;
    }

    /**
     * Execute a worker plan.
     *
     * Stub implementation — returns a failed result.
     * Full process execution will be implemented in Phase 1e.
     */
    #[Override]
    public function execute(WorkerPlanData $plan): WorkerResultData
    {
        throw new RuntimeException(
            'SymfonyProcessWorkerExecutor::execute() is a stub. Full implementation in Phase 1e.'
        );
    }

    /**
     * Build the artisan command array for a worker plan.
     *
     * @return list<string>
     */
    public function buildCommand(WorkerPlanData $plan): array
    {
        $workerPlanFile = $plan->logDirectory . '/worker_plan.json';

        $command = [
            'php',
            'artisan',
            (string) config('parallel-test-runner.commands.worker', 'test:run-worker'),
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

    /**
     * Build the environment variables for a worker process.
     *
     * @return array<string, string>
     */
    public function buildEnvironment(WorkerPlanData $plan): array
    {
        $env = $plan->environment();
        $env['WORKER_SECTIONS'] = json_encode($plan->sectionNames(), JSON_THROW_ON_ERROR);

        return $env;
    }
}
