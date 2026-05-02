<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bus\PendingDispatch;
use RuntimeException;

final class FakeConsoleKernel implements Kernel
{
    /**
     * @param list<array{command:string,parameters:array<string, mixed>}> $calls
     */
    public function __construct(private array &$calls) {}

    public function bootstrap(): void {}

    public function handle($input, $output = null): int
    {
        return 0;
    }

    public function call($command, array $parameters = [], $outputBuffer = null): int
    {
        $this->calls[] = [
            'command' => $command,
            'parameters' => $parameters,
        ];

        return 0;
    }

    public function queue($command, array $parameters = []): PendingDispatch
    {
        throw new RuntimeException('Queue should not be used in this test.');
    }

    public function all(): array
    {
        return [];
    }

    public function output(): string
    {
        return '';
    }

    public function terminate($input, $status): void {}
}
