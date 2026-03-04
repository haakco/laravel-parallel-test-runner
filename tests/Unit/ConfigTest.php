<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit;

use Haakco\ParallelTestRunner\Tests\TestCase;

final class ConfigTest extends TestCase
{
    public function test_config_is_loaded(): void
    {
        $config = config('parallel-test-runner');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('override_test_command', $config);
        $this->assertArrayHasKey('commands', $config);
        $this->assertArrayHasKey('phpunit', $config);
        $this->assertArrayHasKey('database', $config);
        $this->assertArrayHasKey('parallel', $config);
        $this->assertArrayHasKey('sections', $config);
        $this->assertArrayHasKey('environment', $config);
        $this->assertArrayHasKey('worker_environment', $config);
        $this->assertArrayHasKey('hooks', $config);
        $this->assertArrayHasKey('reports', $config);
        $this->assertArrayHasKey('db_naming', $config);
        $this->assertArrayHasKey('metrics', $config);
        $this->assertArrayHasKey('timeouts', $config);
        $this->assertArrayHasKey('background', $config);
        $this->assertArrayHasKey('extra_options', $config);
    }

    public function test_config_defaults(): void
    {
        $this->assertTrue(config('parallel-test-runner.override_test_command'));
        $this->assertSame('test:run-sections', config('parallel-test-runner.commands.main'));
        $this->assertSame('test:run-worker', config('parallel-test-runner.commands.worker'));
        $this->assertSame(1, config('parallel-test-runner.parallel.default_processes'));
    }
}
