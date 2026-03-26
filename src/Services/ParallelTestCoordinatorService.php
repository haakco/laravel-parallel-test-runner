<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Services;

use Haakco\ParallelTestRunner\Contracts\DatabaseProvisionerInterface;
use Haakco\ParallelTestRunner\Data\CleanupContext;
use Haakco\ParallelTestRunner\Data\Parallel\SectionResultData;
use Haakco\ParallelTestRunner\Data\Parallel\WorkerPlanData;
use Haakco\ParallelTestRunner\Data\ProvisionContext;
use Haakco\ParallelTestRunner\Data\TestSectionData;
use Haakco\ParallelTestRunner\Scheduling\ParallelSectionScheduler;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;

class ParallelTestCoordinatorService
{
    public function __construct(
        private readonly TestRunnerConfigurationService $config,
        private readonly TestRunnerState $state,
        private readonly TestExecutionTracker $tracker,
        private readonly DatabaseProvisionerInterface $provisioner,
        private readonly ParallelSectionScheduler $scheduler,
        private readonly HookDispatcher $hookDispatcher,
    ) {}

    /**
     * @param list<TestSectionData> $sections
     */
    public function runParallelSections(
        array $sections,
        string $logDirectory,
        OutputStyle $output,
    ): bool {
        $output->info('Preparing parallel test execution...');

        $noRefreshDb = (bool) ($this->config->options['no_refresh_db'] ?? false);
        $keepDbs = (bool) ($this->config->options['keep_parallel_dbs'] ?? false);
        $databases = [];

        $provisionContext = new ProvisionContext(
            connection: $this->config->getBaseConfig()->dbConnection,
            baseName: $this->config->getBaseConfig()->dbBaseName,
            workerCount: $this->config->parallelProcesses,
            useSchemaLoad: $this->config->getBaseConfig()->useSchemaLoad,
            dropStrategy: $this->config->getBaseConfig()->dropStrategy,
            extraOptions: [
                'no_refresh_db' => $noRefreshDb,
                'split_total' => $this->config->splitTotal,
                'split_group' => $this->config->splitGroup,
                'ignore_lock' => $this->config->ignoreLock,
                'on_progress' => static function (string $message) use ($output): void {
                    $output->writeln('  ' . $message);
                },
            ],
        );

        try {
            $this->hookDispatcher->fire('before_provision', [$provisionContext]);

            $databases = $this->provisioner->provision(
                $this->config->parallelProcesses,
                $provisionContext,
            );

            $this->hookDispatcher->fire('after_provision', [$provisionContext, $databases]);

            $workerPlans = $this->scheduler->createWorkerPlans(
                $sections,
                $this->config->parallelProcesses,
                $databases,
                $logDirectory,
                'standard',
                $this->config->individual,
            );

            $this->saveExecutionPlan($workerPlans, $logDirectory);

            $orchestrator = new ParallelTestOrchestrator(
                output: $output,
                logDirectory: $logDirectory,
                timeoutSeconds: $this->config->timeoutSeconds,
                debug: $this->config->debug,
                failFast: $this->config->failFast,
            );

            $success = $orchestrator->executeWorkerPlans($workerPlans);

            $sectionResults = $orchestrator->getSectionResults();

            foreach ($sectionResults as $sectionName => $resultData) {
                $result = $resultData ?? SectionResultData::createEmpty();

                if ($result->success) {
                    $this->state->markCompleted($sectionName);
                } else {
                    $this->state->markFailed($sectionName);
                }

                $this->tracker->recordSectionResult($sectionName, $result);
            }

            $metrics = $orchestrator->getAggregatedMetrics();
            $this->tracker->updateFromAggregatedMetrics($metrics);

            return $success;
        } finally {
            if ($databases !== []) {
                $cleanupContext = new CleanupContext(
                    databases: array_values($databases),
                    connection: $this->config->getBaseConfig()->dbConnection,
                    keepDatabases: $keepDbs,
                    extraOptions: [],
                );

                $this->hookDispatcher->fire('before_cleanup', [$cleanupContext]);
                $this->provisioner->cleanup($cleanupContext);
            }
        }
    }

    /**
     * @param list<WorkerPlanData> $workerPlans
     */
    private function saveExecutionPlan(array $workerPlans, string $logDirectory): void
    {
        $planData = [];
        foreach ($workerPlans as $plan) {
            $planData[] = [
                'worker_id' => $plan->workerId,
                'database' => $plan->database,
                'sections' => $plan->sectionNames(),
                'log_directory' => $plan->logDirectory,
                'estimated_weight' => $plan->estimatedWeight,
            ];
        }

        File::ensureDirectoryExists($logDirectory);
        File::put(
            $logDirectory . '/parallel_plan.json',
            json_encode($planData, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }
}
