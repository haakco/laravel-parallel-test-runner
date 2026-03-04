<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Database;

use Haakco\ParallelTestRunner\Contracts\DatabaseSeederInterface;
use Haakco\ParallelTestRunner\Data\SeedContext;
use Haakco\ParallelTestRunner\Database\NullDatabaseSeeder;
use Haakco\ParallelTestRunner\Tests\TestCase;

final class NullDatabaseSeederTest extends TestCase
{
    public function test_implements_interface(): void
    {
        $seeder = new NullDatabaseSeeder();
        $this->assertInstanceOf(DatabaseSeederInterface::class, $seeder);
    }

    public function test_seed_is_noop(): void
    {
        $seeder = new NullDatabaseSeeder();
        $context = new SeedContext(
            connection: 'pgsql_testing',
            databaseName: 'test_w1',
            workerId: 1,
            extraOptions: [],
        );

        $seeder->seed($context);
        $this->assertTrue(true);
    }
}
