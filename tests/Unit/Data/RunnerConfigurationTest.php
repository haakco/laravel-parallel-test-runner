<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Data;

use Haakco\ParallelTestRunner\Data\RunnerConfiguration;
use Haakco\ParallelTestRunner\Tests\TestCase;

final class RunnerConfigurationTest extends TestCase
{
    public function test_from_config_reads_defaults(): void
    {
        $config = RunnerConfiguration::fromConfig();

        $this->assertIsArray($config->forceSplitDirectories);
        $this->assertIsArray($config->phpunitConfigFiles);
        $this->assertIsArray($config->weightMultipliers);
        $this->assertIsArray($config->scanPaths);
        $this->assertIsInt($config->maxFilesPerSection);
        $this->assertIsFloat($config->baseWeightPerFile);
        $this->assertIsInt($config->defaultTimeoutSeconds);
        $this->assertIsInt($config->defaultParallelProcesses);
        $this->assertIsString($config->dbConnection);
        $this->assertIsString($config->dbBaseName);
        $this->assertIsString($config->dropStrategy);
        $this->assertIsBool($config->useSchemaLoad);
    }

    public function test_from_config_uses_config_values(): void
    {
        config()->set('parallel-test-runner.sections.force_split_directories', ['tests/Feature/Heavy']);
        config()->set('parallel-test-runner.phpunit', ['standard' => 'phpunit.xml', 'custom' => 'phpunit-custom.xml']);
        config()->set('parallel-test-runner.sections.scan_paths', ['tests/Unit']);
        config()->set('parallel-test-runner.sections.max_files_per_section', 25);
        config()->set('parallel-test-runner.sections.base_weight_per_file', 5.0);
        config()->set('parallel-test-runner.timeouts.default', 300);
        config()->set('parallel-test-runner.parallel.default_processes', 4);
        config()->set('parallel-test-runner.database.connection', 'mysql_testing');
        config()->set('parallel-test-runner.database.base_name', 'my_test');
        config()->set('parallel-test-runner.database.drop_strategy', 'cascade');
        config()->set('parallel-test-runner.database.use_schema_dump', false);

        $config = RunnerConfiguration::fromConfig();

        $this->assertSame(['tests/Feature/Heavy'], $config->forceSplitDirectories);
        $this->assertSame(['standard' => 'phpunit.xml', 'custom' => 'phpunit-custom.xml'], $config->phpunitConfigFiles);
        $this->assertSame(['tests/Unit'], $config->scanPaths);
        $this->assertSame(25, $config->maxFilesPerSection);
        $this->assertSame(5.0, $config->baseWeightPerFile);
        $this->assertSame(300, $config->defaultTimeoutSeconds);
        $this->assertSame(4, $config->defaultParallelProcesses);
        $this->assertSame('mysql_testing', $config->dbConnection);
        $this->assertSame('my_test', $config->dbBaseName);
        $this->assertSame('cascade', $config->dropStrategy);
        $this->assertFalse($config->useSchemaLoad);
    }

    public function test_get_phpunit_config_file_returns_suite_config(): void
    {
        $config = new RunnerConfiguration(
            forceSplitDirectories: [],
            phpunitConfigFiles: ['standard' => 'phpunit.xml', 'integration' => 'phpunit-integration.xml'],
            weightMultipliers: [],
            scanPaths: [],
            maxFilesPerSection: 10,
            baseWeightPerFile: 10.0,
            defaultTimeoutSeconds: 600,
            defaultParallelProcesses: 1,
            dbConnection: 'pgsql_testing',
            dbBaseName: 'app_test',
            dropStrategy: 'with_force',
            useSchemaLoad: true,
        );

        $this->assertSame('phpunit.xml', $config->getPhpunitConfigFile());
        $this->assertSame('phpunit.xml', $config->getPhpunitConfigFile('standard'));
        $this->assertSame('phpunit-integration.xml', $config->getPhpunitConfigFile('integration'));
        $this->assertSame('phpunit.xml', $config->getPhpunitConfigFile('nonexistent'));
    }

    public function test_should_force_split(): void
    {
        $config = new RunnerConfiguration(
            forceSplitDirectories: ['tests/Feature/Heavy', 'tests/Feature/Slow'],
            phpunitConfigFiles: ['standard' => 'phpunit.xml'],
            weightMultipliers: [],
            scanPaths: [],
            maxFilesPerSection: 10,
            baseWeightPerFile: 10.0,
            defaultTimeoutSeconds: 600,
            defaultParallelProcesses: 1,
            dbConnection: 'pgsql_testing',
            dbBaseName: 'app_test',
            dropStrategy: 'with_force',
            useSchemaLoad: true,
        );

        $this->assertTrue($config->shouldForceSplit('tests/Feature/Heavy'));
        $this->assertTrue($config->shouldForceSplit('tests/Feature/Slow'));
        $this->assertFalse($config->shouldForceSplit('tests/Unit'));
        $this->assertFalse($config->shouldForceSplit('tests/Feature/Fast'));
    }
}
