<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Commands;

use Haakco\ParallelTestRunner\Services\TestLogRetentionService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'test:logs-prune',
    description: 'Prune old per-run directories under test-logs/ per the configured retention policy.',
)]
class PruneTestLogsCommand extends Command
{
    protected $signature = 'test:logs-prune
        {--dry-run : List directories that would be deleted without removing them}
        {--max-runs= : Override config max_runs for this invocation (integer or "null")}
        {--max-age-days= : Override config max_age_days for this invocation (integer or "null")}
        {--base-dir= : Override the test-logs directory (defaults to base_path("test-logs"))}';

    protected $description = 'Prune old per-run directories under test-logs/ per the configured retention policy.';

    public function handle(): int
    {
        $baseDir = $this->resolveBaseDirectory();
        $maxRuns = $this->resolveLimitOption('max-runs', 'parallel-test-runner.retention.max_runs');
        $maxAgeDays = $this->resolveLimitOption('max-age-days', 'parallel-test-runner.retention.max_age_days');

        $this->line(sprintf(
            'Pruning %s (max_runs=%s, max_age_days=%s)',
            $baseDir,
            $maxRuns === null ? 'unlimited' : (string) $maxRuns,
            $maxAgeDays === null ? 'unlimited' : (string) $maxAgeDays,
        ));

        $service = new TestLogRetentionService($baseDir, $maxRuns, $maxAgeDays);

        $targets = $this->option('dry-run') ? $service->dryRun() : $service->prune();

        if ($targets === []) {
            $this->info('Nothing to prune — retention policy satisfied.');

            return self::SUCCESS;
        }

        foreach ($targets as $path) {
            $this->line(($this->option('dry-run') ? '[dry-run] ' : '[deleted] ') . $path);
        }

        $this->info(sprintf(
            '%s %d directory(ies).',
            $this->option('dry-run') ? 'Would prune' : 'Pruned',
            count($targets),
        ));

        return self::SUCCESS;
    }

    private function resolveBaseDirectory(): string
    {
        $option = $this->option('base-dir');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        return base_path('test-logs');
    }

    /**
     * Resolve a CLI override or fall back to config.
     *
     * Allows `--max-runs=null` to explicitly disable an axis even if config sets a value.
     */
    private function resolveLimitOption(string $option, string $configKey): ?int
    {
        $value = $this->option($option);

        if ($value === null || $value === '') {
            $configValue = config($configKey);
            if ($configValue === null) {
                return null;
            }

            return (int) $configValue;
        }

        if (strtolower((string) $value) === 'null') {
            return null;
        }

        return (int) $value;
    }
}
