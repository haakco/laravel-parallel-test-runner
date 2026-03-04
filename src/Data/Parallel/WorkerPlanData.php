<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Parallel;

use Spatie\LaravelData\Data;

final class WorkerPlanData extends Data
{
    /** @param list<SectionAssignmentData> $sections */
    public function __construct(
        public int $workerId,
        public array $sections,
        public string $database,
        public string $logDirectory,
        public string $suite,
        public float $estimatedWeight,
        public bool $individual = false,
    ) {}

    /** @return list<string> */
    public function sectionNames(): array
    {
        return array_map(static fn(SectionAssignmentData $section): string => $section->name, $this->sections);
    }

    /** @return array<string, string> */
    public function environment(): array
    {
        $connection = config('parallel-test-runner.database.connection', 'pgsql_testing');
        $env = [
            'DB_CONNECTION' => $connection,
            'DB_DATABASE' => $this->database,
            'TEST_WORKER_ID' => (string) $this->workerId,
        ];

        $workerEnv = config('parallel-test-runner.worker_environment', []);
        if ($workerEnv['set_db_database_test'] ?? false) {
            $env['DB_DATABASE_TEST'] = $this->database;
        }
        if ($workerEnv['set_test_token'] ?? false) {
            $env['TEST_TOKEN'] = (string) $this->workerId;
        }
        if ($workerEnv['set_laravel_parallel_testing'] ?? false) {
            $env['LARAVEL_PARALLEL_TESTING'] = '1';
        }
        if ($workerEnv['set_test_log_dir'] ?? true) {
            $env['TEST_LOG_DIR'] = $this->logDirectory;
        }
        if ($workerEnv['set_test_suite'] ?? true) {
            $env['TEST_SUITE'] = $this->suite;
        }
        if ($workerEnv['set_test_individual_mode'] ?? true) {
            $env['TEST_INDIVIDUAL_MODE'] = $this->individual ? '1' : '0';
        }

        // Merge static environment overrides from config
        $staticEnv = config('parallel-test-runner.environment', []);
        foreach ($staticEnv as $key => $value) {
            $env[$key] = (string) $value;
        }

        return $env;
    }

    public function toPlanFileData(): WorkerPlanFileData
    {
        return WorkerPlanFileData::fromWorkerPlan($this);
    }
}
