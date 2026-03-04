<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Services;

use Haakco\ParallelTestRunner\Data\Results\TestRunnerConfigurationFeedbackData;
use Haakco\ParallelTestRunner\Data\RunnerConfiguration;
use Haakco\ParallelTestRunner\Data\SectionResolutionContext;
use Haakco\ParallelTestRunner\Data\TestRunOptionsData;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Collection;
use RuntimeException;

class TestRunnerConfigurationService
{
    public ?OutputStyle $output = null;

    public bool $debug = false;

    public int $timeoutSeconds;

    public int $maxFilesPerRun;

    public bool $failFast = false;

    /** @var list<string> */
    public array $specificSections = [];

    /** @var array<string, mixed> */
    public array $options = [];

    public int $parallelProcesses;

    public bool $skipEnvironmentChecks = false;

    public ?int $splitTotal = null;

    public ?int $splitGroup = null;

    public bool $individual = false;

    public bool $ignoreLock = false;

    public ?int $globalTimeoutSeconds = null;

    /** @var list<string> */
    public array $runtimeIniOverrides = [];

    /** @var array<string, string> */
    public array $runtimeEnvOverrides = [];

    public bool $nativeDebug = false;

    public float $commandStartTime = 0.0;

    private readonly RunnerConfiguration $baseConfig;

    public function __construct(?RunnerConfiguration $config = null)
    {
        $this->baseConfig = $config ?? RunnerConfiguration::fromConfig();
        $this->timeoutSeconds = $this->baseConfig->defaultTimeoutSeconds;
        $this->maxFilesPerRun = $this->baseConfig->maxFilesPerSection;
        $this->parallelProcesses = $this->baseConfig->defaultParallelProcesses;
        $this->runtimeIniOverrides = $this->detectRuntimeIniOverrides();
    }

    public function getBaseConfig(): RunnerConfiguration
    {
        return $this->baseConfig;
    }

    public function output(): OutputStyle
    {
        throw_unless($this->output instanceof OutputStyle, RuntimeException::class, 'Test runner output not set.');

        return $this->output;
    }

    public function setOutput(OutputStyle $output): self
    {
        $this->output = $output;

        return $this;
    }

    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;

        return $this;
    }

    public function setTimeout(int $seconds): self
    {
        $this->timeoutSeconds = max(1, $seconds);

        return $this;
    }

    public function setMaxFilesPerRun(int $max): self
    {
        $this->maxFilesPerRun = $max;

        return $this;
    }

    public function setFailFast(bool $failFast): self
    {
        $this->failFast = $failFast;

        return $this;
    }

    /** @param list<string>|null $sections */
    public function setSpecificSections(?array $sections): self
    {
        $sections ??= [];

        $this->specificSections = array_values(array_filter(array_map(
            trim(...),
            $sections
        ), static fn(string $section): bool => $section !== ''));

        return $this;
    }

    public function setParallelProcesses(int $processes): self
    {
        $this->parallelProcesses = max(1, $processes);

        return $this;
    }

    public function setSkipEnvironmentChecks(bool $skip): self
    {
        $this->skipEnvironmentChecks = $skip;

        return $this;
    }

    public function setNativeDebug(bool $enabled): self
    {
        $this->nativeDebug = $enabled;

        if ($enabled) {
            $this->runtimeEnvOverrides['USE_ZEND_ALLOC'] = '0';
            $this->runtimeEnvOverrides['NATIVE_DEBUG'] = '1';
        } else {
            unset($this->runtimeEnvOverrides['USE_ZEND_ALLOC'], $this->runtimeEnvOverrides['NATIVE_DEBUG']);
        }

        return $this;
    }

    public function setSplitTotal(?int $splitTotal): self
    {
        $this->splitTotal = $splitTotal;

        return $this;
    }

    public function setSplitGroup(?int $splitGroup): self
    {
        $this->splitGroup = $splitGroup;

        return $this;
    }

    public function setIndividual(bool $individual): self
    {
        $this->individual = $individual;

        return $this;
    }

    public function setIgnoreLock(bool $ignoreLock): self
    {
        $this->ignoreLock = $ignoreLock;

        return $this;
    }

    /** @param array<string, mixed> $options */
    public function setOptions(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    /** @return Collection<int, string> */
    public function getRuntimeIniOverrides(): Collection
    {
        return collect($this->runtimeIniOverrides)->unique()->values();
    }

    /** @return Collection<string, string> */
    public function getProcessEnvironment(): Collection
    {
        /** @var array<string, string> $staticEnv */
        $staticEnv = config('parallel-test-runner.environment', []);

        $env = array_merge($staticEnv, $this->runtimeEnvOverrides);

        if (! array_key_exists('APP_ENV', $env)) {
            $env['APP_ENV'] = 'testing';
        }

        if ($this->debug) {
            $env['DEBUG'] = '1';
        }

        if ($this->ignoreLock) {
            $env['TEST_IGNORE_MIGRATION_LOCK'] = '1';
        }

        return collect($env);
    }

    /** @return Collection<int, string> */
    public function buildEnvironmentParts(): Collection
    {
        /** @var Collection<int, string> */
        return $this->getProcessEnvironment()->map(
            static fn(string $value, string $key): string => sprintf('%s=%s', $key, $value)
        )->values();
    }

    public function buildEnvironmentPrefix(): string
    {
        return $this->buildEnvironmentParts()->implode(' ');
    }

    public function configure(TestRunOptionsData $options, OutputStyle $output): TestRunnerConfigurationFeedbackData
    {
        $this->setOutput($output)
            ->setDebug($options->debug)
            ->setNativeDebug($options->debugNative)
            ->setTimeout($options->timeoutSeconds)
            ->setMaxFilesPerRun($options->maxFilesPerRun)
            ->setFailFast($options->failFast)
            ->setSpecificSections($options->sections)
            ->setParallelProcesses($options->parallelProcesses)
            ->setSplitTotal($options->splitTotal)
            ->setSplitGroup($options->splitGroup)
            ->setIndividual($options->individual)
            ->setIgnoreLock(false)
            ->setOptions([
                'no_refresh_db' => $options->preventRefreshDatabase,
                'keep_parallel_dbs' => $options->keepParallelDatabases,
                'filter' => $options->filter,
                'testsuite' => $options->testSuite,
                'specific_tests' => $options->tests,
            ]);

        $settings = [
            'timeout' => $this->timeoutSeconds,
            'parallel' => $this->parallelProcesses,
            'individual' => $this->individual,
            'fail_fast' => $this->failFast,
            'debug' => $this->debug,
        ];

        return new TestRunnerConfigurationFeedbackData(
            message: 'Configuration applied',
            settings: $settings,
        );
    }

    public function createSectionResolutionContext(): SectionResolutionContext
    {
        return new SectionResolutionContext(
            scanPaths: $this->baseConfig->scanPaths,
            forceSplitDirectories: $this->baseConfig->forceSplitDirectories,
            individual: $this->individual,
            sections: $this->specificSections,
            tests: $this->options['specific_tests'] ?? [],
            filter: $this->options['filter'] ?? null,
            testSuite: $this->options['testsuite'] ?? null,
            splitTotal: $this->splitTotal,
            splitGroup: $this->splitGroup,
            additionalSuites: config('parallel-test-runner.sections.additional_suites', []),
            extraOptions: [],
        );
    }

    /**
     * @param list<string> $files
     * @return Collection<int, string>
     */
    public function buildPhpunitCommand(string $sectionPath, string $logDirectory, string $sectionName, array $files = []): Collection
    {
        $phpunit = base_path('vendor/bin/phpunit');
        $junitFile = $logDirectory . '/' . str_replace('/', '_', $sectionName) . '_junit.xml';
        $configFile = $this->baseConfig->getPhpunitConfigFile();
        $memoryLimit = config('parallel-test-runner.phpunit.memory_limit', '512M');

        $parts = [
            'php',
            '-d',
            'memory_limit=' . $memoryLimit,
        ];

        foreach ($this->getRuntimeIniOverrides() as $directive) {
            $parts[] = '-d';
            $parts[] = $directive;
        }

        $parts[] = $phpunit;
        $parts[] = '--configuration=' . base_path($configFile);
        $parts[] = '--no-coverage';
        $parts[] = '--log-junit=' . $junitFile;
        $parts[] = '--colors=never';

        if ($this->failFast) {
            $parts[] = '--stop-on-failure';
        }

        if (filled($this->options['filter'] ?? null)) {
            $parts[] = '--filter=' . $this->options['filter'];
        }

        if (filled($this->options['testsuite'] ?? null)) {
            $parts[] = '--testsuite=' . $this->options['testsuite'];
        }

        if ($files !== []) {
            foreach ($files as $file) {
                $parts[] = $file;
            }
        } else {
            $parts[] = $sectionPath;
        }

        return collect($parts);
    }

    /**
     * @param list<string> $files
     * @return Collection<int, string>
     */
    public function buildWrappedCommand(string $sectionPath, string $logDirectory, string $sectionName, array $files = []): Collection
    {
        $timeoutSeconds = max(1, $this->timeoutSeconds);
        $phpunitCommand = $this->buildPhpunitCommand($sectionPath, $logDirectory, $sectionName, $files);

        $timeoutBinary = $this->resolveTimeoutBinary();
        if ($timeoutBinary !== null) {
            return collect([$timeoutBinary, '--signal=KILL', sprintf('%ss', $timeoutSeconds)])
                ->merge($phpunitCommand);
        }

        return $phpunitCommand;
    }

    public function logDebug(string $context, mixed $data): void
    {
        if (! $this->debug) {
            return;
        }

        $formatted = is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->output()->writeln('');
        $this->output()->writeln("<comment>[DEBUG] {$context}:</comment>");

        foreach (explode("\n", $formatted) as $line) {
            $this->output()->writeln("<comment>  {$line}</comment>");
        }
    }

    public function hasExceededGlobalTimeout(): bool
    {
        if ($this->globalTimeoutSeconds === null || $this->globalTimeoutSeconds <= 0) {
            return false;
        }

        return microtime(true) - $this->commandStartTime >= $this->globalTimeoutSeconds;
    }

    /** @return list<string> */
    private function detectRuntimeIniOverrides(): array
    {
        $overrides = [];

        if (extension_loaded('pcov')) {
            $overrides[] = 'pcov.enabled=0';
        }

        if (extension_loaded('xdebug')) {
            $overrides[] = 'xdebug.mode=off';
        }

        return $overrides;
    }

    private function resolveTimeoutBinary(): ?string
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $gtimeout = trim((string) shell_exec('which gtimeout 2>/dev/null'));
            if ($gtimeout !== '') {
                return $gtimeout;
            }
        }

        $timeout = trim((string) shell_exec('which timeout 2>/dev/null'));

        return $timeout !== '' ? $timeout : null;
    }
}
