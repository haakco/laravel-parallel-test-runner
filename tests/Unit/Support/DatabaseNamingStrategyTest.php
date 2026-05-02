<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Support;

use Haakco\ParallelTestRunner\Support\DatabaseNamingStrategy;
use Haakco\ParallelTestRunner\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DatabaseNamingStrategyTest extends TestCase
{
    private DatabaseNamingStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->strategy = new DatabaseNamingStrategy(
            baseName: 'app_test',
            workerPattern: '{base}_w{worker}',
            splitPattern: '{base}_s{total}g{group}_w{worker}',
        );
    }

    public function test_worker_only_naming(): void
    {
        $this->assertSame('app_test_w1', $this->strategy->forWorker(1));
        $this->assertSame('app_test_w3', $this->strategy->forWorker(3));
    }

    public function test_split_group_naming(): void
    {
        $this->assertSame(
            'app_test_s3g2_w1',
            $this->strategy->forWorkerWithSplit(1, splitTotal: 3, splitGroup: 2),
        );
    }

    public function test_split_group_naming_various_combinations(): void
    {
        $this->assertSame(
            'app_test_s5g1_w4',
            $this->strategy->forWorkerWithSplit(4, splitTotal: 5, splitGroup: 1),
        );
    }

    public function test_custom_patterns(): void
    {
        $custom = new DatabaseNamingStrategy(
            baseName: 'my_db',
            workerPattern: '{base}_{worker}',
            splitPattern: '{base}_split{total}_{group}_{worker}',
        );

        $this->assertSame('my_db_2', $custom->forWorker(2));
        $this->assertSame('my_db_split3_1_2', $custom->forWorkerWithSplit(2, splitTotal: 3, splitGroup: 1));
    }

    public function test_from_config_uses_defaults(): void
    {
        config()->set('parallel-test-runner.database.base_name', 'app_test');
        config()->set('parallel-test-runner.db_naming.pattern', '{base}_w{worker}');
        config()->set('parallel-test-runner.db_naming.split_pattern', '{base}_s{total}g{group}_w{worker}');

        $strategy = DatabaseNamingStrategy::fromConfig();

        $this->assertSame('app_test_w1', $strategy->forWorker(1));
    }

    #[DataProvider('workerIndexProvider')]
    public function test_generates_unique_names_per_worker(int $workerIndex, string $expected): void
    {
        $this->assertSame($expected, $this->strategy->forWorker($workerIndex));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function workerIndexProvider(): array
    {
        return [
            'worker 1' => [1, 'app_test_w1'],
            'worker 5' => [5, 'app_test_w5'],
            'worker 10' => [10, 'app_test_w10'],
        ];
    }
}
