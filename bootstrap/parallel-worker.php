<?php

declare(strict_types=1);

/**
 * Bootstrap file for parallel test worker processes.
 *
 * This file is designed to be required by worker processes before
 * Laravel boots. It returns a callable that sets up the worker
 * environment when invoked.
 *
 * Usage in phpunit.xml or worker startup:
 *   $bootstrap = require __DIR__ . '/vendor/haakco/laravel-parallel-test-runner/bootstrap/parallel-worker.php';
 *   $bootstrap();
 *
 * Or include it directly in a custom bootstrap.php:
 *   require __DIR__ . '/vendor/haakco/laravel-parallel-test-runner/bootstrap/parallel-worker.php';
 *   // Returns callable — invoke if you want immediate setup
 */
return static function (): void {
    // Force testing environment
    if (getenv('APP_ENV') === false) {
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
    }

    // Disable OpenTelemetry SDK to avoid noise in test output
    if (getenv('OTEL_SDK_DISABLED') === false) {
        putenv('OTEL_SDK_DISABLED=true');
        $_ENV['OTEL_SDK_DISABLED'] = 'true';
        $_SERVER['OTEL_SDK_DISABLED'] = 'true';
    }

    // Mark this process as a parallel test worker for framework detection
    $workerId = getenv('TEST_WORKER_ID');
    if ($workerId !== false && $workerId !== '') {
        putenv("PARALLEL_TESTS=true");
        $_ENV['PARALLEL_TESTS'] = 'true';
        $_SERVER['PARALLEL_TESTS'] = 'true';
    }
};
