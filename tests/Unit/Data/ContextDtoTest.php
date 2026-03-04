<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Data;

use Haakco\ParallelTestRunner\Data\CleanupContext;
use Haakco\ParallelTestRunner\Data\ProvisionContext;
use Haakco\ParallelTestRunner\Data\ReportContext;
use Haakco\ParallelTestRunner\Data\RunContext;
use Haakco\ParallelTestRunner\Data\SectionResolutionContext;
use Haakco\ParallelTestRunner\Data\SeedContext;
use Haakco\ParallelTestRunner\Data\WorkerContext;
use Haakco\ParallelTestRunner\Tests\TestCase;

final class ContextDtoTest extends TestCase
{
    public function test_section_resolution_context_instantiates(): void
    {
        $context = new SectionResolutionContext(
            scanPaths: ['tests/Unit', 'tests/Feature'],
            forceSplitDirectories: ['tests/Unit/Models'],
            individual: false,
            sections: [],
            tests: [],
            filter: null,
            testSuite: null,
            splitTotal: null,
            splitGroup: null,
            additionalSuites: [],
            extraOptions: [],
        );

        $this->assertSame(['tests/Unit', 'tests/Feature'], $context->scanPaths);
        $this->assertFalse($context->individual);
    }

    public function test_provision_context_instantiates(): void
    {
        $context = new ProvisionContext(
            connection: 'pgsql_testing',
            baseName: 'test_db',
            workerCount: 4,
            useSchemaLoad: true,
            dropStrategy: 'with_force',
            extraOptions: [],
        );

        $this->assertSame('pgsql_testing', $context->connection);
        $this->assertSame(4, $context->workerCount);
    }

    public function test_seed_context_instantiates(): void
    {
        $context = new SeedContext(
            connection: 'pgsql_testing',
            databaseName: 'test_db_w1',
            workerId: 1,
            extraOptions: [],
        );

        $this->assertSame('test_db_w1', $context->databaseName);
    }

    public function test_worker_context_instantiates(): void
    {
        $context = new WorkerContext(
            workerId: 1,
            database: 'test_db_w1',
            logDirectory: '/tmp/logs/w1',
            sections: ['Unit/Models'],
            suite: 'standard',
            individual: true,
            extraOptions: [],
        );

        $this->assertSame(1, $context->workerId);
        $this->assertTrue($context->individual);
    }

    public function test_cleanup_context_instantiates(): void
    {
        $context = new CleanupContext(
            databases: ['test_db_w1', 'test_db_w2'],
            connection: 'pgsql_testing',
            keepDatabases: false,
            extraOptions: [],
        );

        $this->assertCount(2, $context->databases);
        $this->assertFalse($context->keepDatabases);
    }

    public function test_run_context_instantiates(): void
    {
        $context = new RunContext(
            logDirectory: '/tmp/logs',
            command: 'test:run-sections',
            commandArgs: ['--parallel=4'],
            parallel: true,
            workerCount: 4,
            splitTotal: null,
            splitGroup: null,
            extraOptions: [],
        );

        $this->assertTrue($context->parallel);
        $this->assertNull($context->splitTotal);
    }

    public function test_report_context_instantiates(): void
    {
        $context = new ReportContext(
            logDirectory: '/tmp/logs',
            successful: true,
            command: 'test:run-sections',
            summaryFile: '/tmp/logs/summary.md',
            extraOptions: [],
        );

        $this->assertTrue($context->successful);
    }
}
