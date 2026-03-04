<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class TestRunOptionsData extends Data
{
    /**
     * @param list<string> $sections
     * @param list<string> $tests
     * @param array<string, mixed> $extraOptions
     */
    public function __construct(
        public bool $debug,
        public bool $debugNative,
        public int $timeoutSeconds,
        public int $maxFilesPerRun,
        public bool $failFast,
        public bool $individual,
        public int $parallelProcesses,
        public bool $runAll,
        public bool $keepParallelDatabases,
        public bool $preventRefreshDatabase,
        public bool $skipEnvironmentChecksRequested,
        public array $sections,
        public array $tests,
        public ?int $splitTotal,
        public ?int $splitGroup,
        public ?string $filter,
        public ?string $testSuite,
        public ?string $logDirectory,
        public bool $emitMetrics,
        public array $extraOptions = [],
    ) {}

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $extraOptions
     */
    public static function fromCommandInput(
        array $options,
        array $arguments,
        ?int $splitTotal,
        ?int $splitGroup,
        ?string $logDirectory,
        array $extraOptions = [],
    ): self {
        $sections = array_values(array_filter(
            array_map(static fn(mixed $value): string => (string) $value, $options['section'] ?? []),
            static fn(string $section): bool => $section !== ''
        ));

        $tests = array_values(array_filter(
            array_map(static fn(mixed $value): string => (string) $value, $arguments['tests'] ?? []),
            static fn(string $test): bool => $test !== ''
        ));

        return new self(
            debug: (bool) ($options['debug'] ?? false),
            debugNative: (bool) ($options['debug-native'] ?? false),
            timeoutSeconds: (int) ($options['timeout'] ?? 600),
            maxFilesPerRun: (int) ($options['max-files'] ?? 10),
            failFast: (bool) ($options['fail-fast'] ?? false),
            individual: (bool) ($options['individual'] ?? false),
            parallelProcesses: (int) ($options['parallel'] ?? 1),
            runAll: (bool) ($options['all'] ?? false),
            keepParallelDatabases: (bool) ($options['keep-parallel-dbs'] ?? false),
            preventRefreshDatabase: (bool) ($options['no-refresh-db'] ?? false),
            skipEnvironmentChecksRequested: (bool) ($options['skip-env-checks'] ?? false),
            sections: $sections,
            tests: $tests,
            splitTotal: $splitTotal,
            splitGroup: $splitGroup,
            filter: isset($options['filter']) ? (string) $options['filter'] : null,
            testSuite: isset($options['testsuite']) ? (string) $options['testsuite'] : null,
            logDirectory: $logDirectory,
            emitMetrics: self::resolveBooleanOption($options, 'emit-metrics', true),
            extraOptions: $extraOptions,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function resolveBooleanOption(array $options, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $options)) {
            return $default;
        }

        $value = $options[$key];

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) (int) $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes'], true);
        }

        return $default;
    }
}
