<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Testing;

use Haakco\ParallelTestRunner\Support\ParallelTestEnvironment;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * PHPUnit extension that configures the database connection for parallel workers.
 *
 * Register in phpunit.xml:
 *   <extensions>
 *     <bootstrap class="Haakco\ParallelTestRunner\Testing\ParallelTestRunnerExtension"/>
 *   </extensions>
 *
 * When running as a worker process (TEST_WORKER_ID is set), this extension
 * switches the configured database connection to the worker-specific database
 * before any tests execute.
 */
final class ParallelTestRunnerExtension implements Extension
{
    public function bootstrap(
        Configuration $configuration,
        Facade $facade,
        ParameterCollection $parameters,
    ): void {
        $this->configureWorkerDatabase();
    }

    /**
     * Switch the database connection to the worker-specific database.
     *
     * Only acts when running inside a parallel worker process (TEST_WORKER_ID set).
     */
    public function configureWorkerDatabase(): void
    {
        if (! ParallelTestEnvironment::isWorkerProcess()) {
            return;
        }

        $database = ParallelTestEnvironment::database();
        if ($database === null) {
            return;
        }

        $connection = (string) config(
            'parallel-test-runner.database.connection',
            'pgsql_testing',
        );

        config(["database.connections.{$connection}.database" => $database]);
    }
}
