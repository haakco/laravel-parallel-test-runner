<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Commands;

use Haakco\ParallelTestRunner\Data\Parallel\WorkerPlanFileData;
use Haakco\ParallelTestRunner\Services\TestRunnerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class TestRunWorkerCommand extends Command
{
    protected $signature = 'test:run-worker
                            {--worker-plan-file= : Path to worker plan JSON file}
                            {--debug : Enable debug output}
                            {--timeout=600 : Timeout per section in seconds}
                            {--fail-fast : Stop on first failure}
                            {--log-dir= : Log directory for this worker}
                            {--skip-env-checks : Skip environment validation}
                            {--individual : Run each test file individually}';

    public function __construct(
        private readonly TestRunnerService $testRunner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $planFile = $this->option('worker-plan-file');
        if (! $planFile || ! File::exists($planFile)) {
            $this->error('Worker plan file not found: ' . $planFile);

            return Command::FAILURE;
        }

        $planPayload = json_decode(File::get($planFile), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($planPayload) || ! isset($planPayload['sections'])) {
            $this->error('Invalid worker plan file');

            return Command::FAILURE;
        }

        $planData = WorkerPlanFileData::from($planPayload);
        $workerPlan = $planData->toWorkerPlan($this->option('log-dir') ? (string) $this->option('log-dir') : null);

        $dbConnection = (string) config('parallel-test-runner.database.connection', 'pgsql_testing');
        config(["database.connections.{$dbConnection}.database" => $workerPlan->database]);
        config(['database.default' => $dbConnection]);

        $configService = $this->testRunner->getConfigService();

        if ($this->option('log-dir')) {
            $this->testRunner->setLogDirectory((string) $this->option('log-dir'));
        }

        $configService
            ->setDebug((bool) $this->option('debug'))
            ->setTimeout((int) $this->option('timeout'))
            ->setFailFast((bool) $this->option('fail-fast'))
            ->setSkipEnvironmentChecks((bool) $this->option('skip-env-checks'))
            ->setIndividual($this->option('individual') || $workerPlan->individual)
            ->setSpecificSections($workerPlan->sectionNames());

        $configService->setOutput($this->output);

        $success = $this->testRunner->run();

        return $success ? Command::SUCCESS : Command::FAILURE;
    }
}
