<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'test', description: 'Run tests using the section-based parallel runner')]
class TestCommand extends Command
{
    protected $signature = 'test
        {--legacy : Fall back to the standard Laravel test runner}
        {--list : List discovered sections without running}
        {--section=* : Run specific section(s)}
        {--individual : Run each test file individually}
        {--parallel=1 : Number of parallel workers}
        {--split-total= : Total number of CI split groups}
        {--split-group= : Which split group to run}
        {--fail-fast : Stop on first failure}
        {--timeout=600 : Timeout per section in seconds}
        {--debug : Enable debug output}
        {--emit-metrics=1 : Generate performance reports}';

    protected $description = 'Run tests using the section-based parallel runner (overrides default test command)';

    public function handle(): int
    {
        if ($this->option('legacy')) {
            return $this->runLegacy();
        }

        /** @var string $mainCommand */
        $mainCommand = config('parallel-test-runner.commands.main', 'test:run-sections');

        $forwardOptions = $this->buildForwardOptions();

        return $this->call($mainCommand, $forwardOptions);
    }

    private function runLegacy(): int
    {
        $this->warn('Running in legacy mode (standard Laravel test runner)...');

        // Check if NunoMaduro\Collision's test command is available
        if (class_exists(\NunoMaduro\Collision\Adapters\Laravel\Commands\TestCommand::class)) {
            $collisionCommand = $this->getApplication()?->find('test');

            if ($collisionCommand instanceof \Symfony\Component\Console\Command\Command && $collisionCommand !== $this) {
                return $collisionCommand->run($this->input, $this->output);
            }
        }

        $this->error('No legacy test runner available. Remove --legacy flag to use the section runner.');

        return self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildForwardOptions(): array
    {
        $options = [];

        if ($this->option('list')) {
            $options['--list'] = true;
        }

        /** @var array<int, string> $sections */
        $sections = $this->option('section');
        if ($sections !== []) {
            $options['--section'] = $sections;
        }

        if ($this->option('individual')) {
            $options['--individual'] = true;
        }

        /** @var string $parallel */
        $parallel = $this->option('parallel');
        if ($parallel !== '1') {
            $options['--parallel'] = $parallel;
        }

        if ($this->option('split-total') !== null) {
            $options['--split-total'] = $this->option('split-total');
        }

        if ($this->option('split-group') !== null) {
            $options['--split-group'] = $this->option('split-group');
        }

        if ($this->option('fail-fast')) {
            $options['--fail-fast'] = true;
        }

        /** @var string $timeout */
        $timeout = $this->option('timeout');
        if ($timeout !== '600') {
            $options['--timeout'] = $timeout;
        }

        if ($this->option('debug')) {
            $options['--debug'] = true;
        }

        /** @var string $emitMetrics */
        $emitMetrics = $this->option('emit-metrics');
        if ($emitMetrics !== '1') {
            $options['--emit-metrics'] = $emitMetrics;
        }

        return $options;
    }
}
