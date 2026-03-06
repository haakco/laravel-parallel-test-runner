<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\TestingSupport\Console;

use Illuminate\Testing\PendingCommand;

trait InteractsWithConsoleCommands
{
    protected function runCommand(string $command, array $arguments = []): PendingCommand
    {
        return $this->artisan($command, $arguments);
    }

    protected function assertCommandSuccessful(string $command, array $arguments = []): void
    {
        $this->runCommand($command, $arguments)->assertExitCode(0);
    }

    protected function assertCommandFails(string $command, array $arguments = [], int $expectedExitCode = 1): void
    {
        $this->runCommand($command, $arguments)->assertExitCode($expectedExitCode);
    }

    /**
     * @return array<int, string>
     */
    protected function consoleOutputLines(string $output): array
    {
        $lines = preg_split('/\r?\n/', $output) ?: [];

        return array_values(array_filter(array_map(trim(...), $lines), static fn(string $line): bool => $line !== ''));
    }
}
