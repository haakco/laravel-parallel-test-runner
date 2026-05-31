<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Services;

use Closure;
use Haakco\ParallelTestRunner\Data\Results\BackgroundRunStartResultData;
use Haakco\ParallelTestRunner\Data\Results\BackgroundRunStatusData;
use Haakco\ParallelTestRunner\Data\Results\DatabaseRefreshResultData;
use Haakco\ParallelTestRunner\Data\Results\HangingTestsResultData;
use Haakco\ParallelTestRunner\Data\Results\SectionListResultData;
use Haakco\ParallelTestRunner\Data\Results\TestRunnerConfigurationFeedbackData;
use Haakco\ParallelTestRunner\Data\Results\TestRunResultData;
use Haakco\ParallelTestRunner\Data\TestRunOptionsData;
use Haakco\ParallelTestRunner\Data\TestSectionData;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class TestRunnerService
{
    private static ?string $processLogDirectory = null;

    private ?string $logDirectory = null;

    public function __construct(
        private readonly TestRunnerConfigurationService $configService,
        private readonly TestExecutionOrchestratorService $executionService,
        private readonly TestDatabaseManagerService $databaseService,
        private readonly HangingTestDetectorService $hangingTestService,
    ) {
        $this->logDirectory = $this->ambientLogDirectory() ?? self::$processLogDirectory;

        if ($this->logDirectory !== null) {
            $this->configService->setLogDirectoryEnvironment($this->logDirectory);
        }
    }

    public function configure(TestRunOptionsData $options, OutputStyle $output): TestRunnerConfigurationFeedbackData
    {
        if ($options->logDirectory !== null && $options->logDirectory !== $this->logDirectory) {
            $this->applyExistingLogDirectory($options->logDirectory);
        }

        return $this->configService->configure($options, $output);
    }

    public function runConfigured(): TestRunResultData
    {
        return $this->executionService->runConfigured($this->getLogDirectory());
    }

    /** @param array<string, mixed> $options */
    public function run(array $options = []): bool
    {
        return $this->executionService->run($options, $this->getLogDirectory());
    }

    /** @return Collection<int, TestSectionData> */
    public function listSections(): Collection
    {
        return $this->executionService->listSections();
    }

    public function listSectionsWithGroups(?int $splitTotal = null, ?int $splitGroup = null): SectionListResultData
    {
        return $this->executionService->listSectionsWithGroups($splitTotal, $splitGroup);
    }

    public function checkBackgroundStatus(): BackgroundRunStatusData
    {
        return $this->executionService->checkBackgroundStatus();
    }

    /** @param array<string, mixed> $commandOptions */
    public function startBackgroundRun(TestRunOptionsData $options, array $commandOptions): BackgroundRunStartResultData
    {
        return $this->executionService->startBackgroundRun($options, $commandOptions);
    }

    /** @param Closure(string $message): void|null $onProgress */
    public function refreshTestDatabase(
        ?string $connection = null,
        ?string $database = null,
        ?Closure $onProgress = null,
    ): DatabaseRefreshResultData {
        return $this->databaseService->refreshTestDatabase($connection, $database, $onProgress);
    }

    /**
     * @param Closure(string $section, int $current, int $total, string $status): void|null $onProgress
     */
    public function findHangingTests(int $shortTimeout = 10, ?Closure $onProgress = null): HangingTestsResultData
    {
        $originalTimeout = $this->configService->timeoutSeconds;

        $sections = $this->listSections()->all();

        return $this->hangingTestService->findHangingTests(
            sections: $sections,
            runSections: function (array $sectionNames): bool {
                $this->configService->setSpecificSections($sectionNames);

                return $this->run(['quiet' => true]);
            },
            setTimeout: function (int $timeout): void {
                $this->configService->setTimeout($timeout);
            },
            restoreTimeout: function (int $_) use ($originalTimeout): void {
                $this->configService->setTimeout($originalTimeout);
            },
            shortTimeout: $shortTimeout,
            onProgress: $onProgress,
        );
    }

    public function getLogDirectory(): string
    {
        return $this->logDirectory ??= $this->createLogDirectory();
    }

    public function setLogDirectory(string $logDirectory): self
    {
        $this->applyExistingLogDirectory($logDirectory);

        return $this;
    }

    public function getConfigService(): TestRunnerConfigurationService
    {
        return $this->configService;
    }

    public static function resetProcessLogDirectoryForTesting(): void
    {
        self::$processLogDirectory = null;
    }

    private function createLogDirectory(): string
    {
        if (! $this->shouldPublishTopLevelRunDirectory()) {
            $dir = $this->createAuxiliaryLogDirectory();
            self::$processLogDirectory = $dir;
            $this->configService->setLogDirectoryEnvironment($dir);

            return $dir;
        }

        $this->autoPruneIfEnabled();

        $dir = $this->createUniqueLogDirectory();
        self::$processLogDirectory = $dir;
        $this->configService->setLogDirectoryEnvironment($dir);

        $latest = base_path('test-logs/latest');
        $this->removeExistingLatestPath($latest);

        if (File::exists(dirname($latest))) {
            // Use a relative symlink so host/container base-path differences
            // do not break `test-logs/latest`.
            @symlink(basename($dir), $latest);
        }

        return $dir;
    }

    private function shouldPublishTopLevelRunDirectory(): bool
    {
        $argv = $_SERVER['argv'] ?? [];

        return in_array('test:run-sections', $argv, true) || in_array('test', $argv, true);
    }

    private function createAuxiliaryLogDirectory(): string
    {
        $baseDirectory = base_path('test-logs/auxiliary');
        $this->ensureDirectoryExists($baseDirectory);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $directory = $baseDirectory . '/' . $this->generateLogDirectoryName();

            if (@mkdir($directory, 0755, true) || is_dir($directory)) {
                return $directory;
            }
        }

        throw new \RuntimeException(sprintf('Unable to create auxiliary test log directory under [%s].', $baseDirectory));
    }

    private function applyExistingLogDirectory(string $logDirectory): void
    {
        $this->ensureDirectoryExists($logDirectory);
        $this->logDirectory = $logDirectory;
        self::$processLogDirectory = $logDirectory;
        $this->configService->setLogDirectoryEnvironment($logDirectory);
    }

    private function ambientLogDirectory(): ?string
    {
        foreach (['PARALLEL_TEST_RUNNER_LOG_DIR', 'TEST_LOG_DIR'] as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Apply the configured retention policy to test-logs/ before creating a
     * new run directory. Failures here must not block a test run — the
     * pruner is a hygiene step, not a correctness gate.
     */
    private function autoPruneIfEnabled(): void
    {
        if (! (bool) config('parallel-test-runner.retention.auto_prune', true)) {
            return;
        }

        try {
            $service = new TestLogRetentionService(
                base_path('test-logs'),
                $this->configToNullableInt(config('parallel-test-runner.retention.max_runs')),
                $this->configToNullableInt(config('parallel-test-runner.retention.max_age_days')),
            );

            $service->prune();
        } catch (\Throwable) {
            // Swallow — never block a test run because retention failed.
        }
    }

    private function configToNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function removeExistingLatestPath(string $latest): void
    {
        if (is_link($latest)) {
            // `latest` is only a convenience pointer. Under concurrent starts
            // another process may delete or recreate it between these calls, so
            // cleanup must remain best-effort.
            @unlink($latest);

            return;
        }

        if (File::exists($latest)) {
            @File::deleteDirectory($latest);
        }
    }

    private function createUniqueLogDirectory(): string
    {
        $baseDirectory = base_path('test-logs');
        $this->ensureDirectoryExists($baseDirectory);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $directory = $baseDirectory . '/' . $this->generateLogDirectoryName();

            if (@mkdir($directory, 0755, true) || is_dir($directory)) {
                return $directory;
            }
        }

        throw new \RuntimeException(sprintf('Unable to create test log directory under [%s].', $baseDirectory));
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create directory [%s].', $directory));
        }
    }

    private function generateLogDirectoryName(): string
    {
        return sprintf('%s_%s', date('Ymd_His_u'), bin2hex(random_bytes(3)));
    }
}
