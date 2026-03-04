<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class RunnerConfiguration extends Data
{
    /**
     * @param list<string> $forceSplitDirectories
     * @param array<string, string> $phpunitConfigFiles
     * @param array<string, float> $weightMultipliers
     * @param list<string> $scanPaths
     */
    public function __construct(
        public array $forceSplitDirectories,
        public array $phpunitConfigFiles,
        public array $weightMultipliers,
        public array $scanPaths,
        public int $maxFilesPerSection,
        public float $baseWeightPerFile,
        public int $defaultTimeoutSeconds,
        public int $defaultParallelProcesses,
        public string $dbConnection,
        public string $dbBaseName,
        public string $dropStrategy,
        public bool $useSchemaLoad,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            forceSplitDirectories: config('parallel-test-runner.sections.force_split_directories', []),
            phpunitConfigFiles: config('parallel-test-runner.phpunit', ['standard' => 'phpunit.xml']),
            weightMultipliers: config('parallel-test-runner.sections.weight_multipliers', []),
            scanPaths: config('parallel-test-runner.sections.scan_paths', ['tests/Unit', 'tests/Feature']),
            maxFilesPerSection: (int) config('parallel-test-runner.sections.max_files_per_section', 10),
            baseWeightPerFile: (float) config('parallel-test-runner.sections.base_weight_per_file', 10.0),
            defaultTimeoutSeconds: (int) config('parallel-test-runner.timeouts.default', 600),
            defaultParallelProcesses: (int) config('parallel-test-runner.parallel.default_processes', 1),
            dbConnection: config('parallel-test-runner.database.connection', 'pgsql_testing'),
            dbBaseName: config('parallel-test-runner.database.base_name', 'app_test'),
            dropStrategy: config('parallel-test-runner.database.drop_strategy', 'with_force'),
            useSchemaLoad: (bool) config('parallel-test-runner.database.use_schema_dump', true),
        );
    }

    public function getPhpunitConfigFile(string $suite = 'standard'): string
    {
        return $this->phpunitConfigFiles[$suite] ?? $this->phpunitConfigFiles['standard'] ?? 'phpunit.xml';
    }

    public function shouldForceSplit(string $directory): bool
    {
        return in_array($directory, $this->forceSplitDirectories, true);
    }
}
