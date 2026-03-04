<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;

abstract class AbstractModelTest extends TestCase
{
    abstract public function test_abstract(): void;
}
