<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\TestingSupport\Console;

use Haakco\ParallelTestRunner\TestingSupport\Console\InteractsWithConsoleCommands;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InteractsWithConsoleCommandsTest extends TestCase
{
    #[Test]
    public function it_normalizes_console_output_into_trimmed_non_empty_lines(): void
    {
        $helper = new class ('helper') extends TestCase {
            use InteractsWithConsoleCommands;

            public function normalize(string $output): array
            {
                return $this->consoleOutputLines($output);
            }
        };

        $this->assertSame(
            ['First line', 'Second line', 'Third line'],
            $helper->normalize("  First line \n\nSecond line\r\n   \r\n Third line  ")
        );
    }
}
