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

        foreach ($this->forwardOptionRules() as $rule) {
            $value = $this->option($rule['name']);

            if ($this->shouldForwardOption($value, $rule['default'])) {
                $options[$rule['target']] = $rule['flag'] ? true : $value;
            }
        }

        return $options;
    }

    /**
     * @return list<array{name: string, target: string, default: mixed, flag: bool}>
     */
    private function forwardOptionRules(): array
    {
        return [
            ['name' => 'list', 'target' => '--list', 'default' => false, 'flag' => true],
            ['name' => 'section', 'target' => '--section', 'default' => [], 'flag' => false],
            ['name' => 'individual', 'target' => '--individual', 'default' => false, 'flag' => true],
            ['name' => 'parallel', 'target' => '--parallel', 'default' => '1', 'flag' => false],
            ['name' => 'split-total', 'target' => '--split-total', 'default' => null, 'flag' => false],
            ['name' => 'split-group', 'target' => '--split-group', 'default' => null, 'flag' => false],
            ['name' => 'fail-fast', 'target' => '--fail-fast', 'default' => false, 'flag' => true],
            ['name' => 'timeout', 'target' => '--timeout', 'default' => '600', 'flag' => false],
            ['name' => 'debug', 'target' => '--debug', 'default' => false, 'flag' => true],
            ['name' => 'emit-metrics', 'target' => '--emit-metrics', 'default' => '1', 'flag' => false],
        ];
    }

    private function shouldForwardOption(mixed $value, mixed $default): bool
    {
        return $value !== $default;
    }
}
