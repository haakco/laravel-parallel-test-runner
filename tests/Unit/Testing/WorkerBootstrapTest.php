<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;

final class WorkerBootstrapTest extends TestCase
{
    public function test_bootstrap_file_exists(): void
    {
        $bootstrapPath = dirname(__DIR__, 3) . '/bootstrap/parallel-worker.php';

        $this->assertFileExists($bootstrapPath);
    }

    public function test_bootstrap_file_is_valid_php(): void
    {
        $bootstrapPath = dirname(__DIR__, 3) . '/bootstrap/parallel-worker.php';

        $output = [];
        $exitCode = 0;
        exec('php -l ' . escapeshellarg($bootstrapPath) . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, 'Bootstrap file has syntax errors: ' . implode("\n", $output));
    }

    public function test_bootstrap_file_returns_callable(): void
    {
        $bootstrapPath = dirname(__DIR__, 3) . '/bootstrap/parallel-worker.php';

        $result = require $bootstrapPath;

        $this->assertIsCallable($result);
    }
}
