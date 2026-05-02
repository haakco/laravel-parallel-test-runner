<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HeredocFixtureTest extends TestCase
{
    public function test_heredoc_fixture(): void
    {
        $fixture = <<<'TEXT'
abstract class ParentConstants
{
    public const STATUS = 'parent';
}
TEXT;

        $this->assertStringContainsString('abstract class ParentConstants', $fixture);
    }
}
