<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Data;

use Haakco\ParallelTestRunner\Data\TestRunOptionsData;
use Haakco\ParallelTestRunner\Data\TestSectionData;
use Haakco\ParallelTestRunner\Tests\TestCase;

final class CoreDtoTest extends TestCase
{
    public function test_section_data_from_array(): void
    {
        $data = TestSectionData::fromArray([
            'name' => 'Unit/Models',
            'type' => 'directory',
            'path' => 'tests/Unit/Models',
            'files' => ['UserTest.php', 'OrderTest.php'],
        ]);

        $this->assertSame('Unit/Models', $data->name);
        $this->assertSame('directory', $data->type);
        $this->assertSame(2, $data->fileCount);
    }

    public function test_section_data_from_array_defaults_type_to_file(): void
    {
        $data = TestSectionData::fromArray([
            'name' => 'Unit/Models',
            'path' => 'tests/Unit/Models',
        ]);

        $this->assertSame('file', $data->type);
        $this->assertSame(1, $data->fileCount);
    }

    public function test_section_data_to_array(): void
    {
        $data = new TestSectionData(
            name: 'Feature/Api',
            type: 'directory',
            path: 'tests/Feature/Api',
            files: ['LoginTest.php'],
            fileCount: 1,
        );

        $array = $data->toArray();
        $this->assertSame('Feature/Api', $array['name']);
        $this->assertSame(1, $array['file_count']);
    }

    public function test_run_options_data_instantiates(): void
    {
        $data = new TestRunOptionsData(
            debug: false,
            debugNative: false,
            timeoutSeconds: 600,
            maxFilesPerRun: 10,
            failFast: false,
            individual: false,
            parallelProcesses: 1,
            runAll: false,
            keepParallelDatabases: false,
            preventRefreshDatabase: false,
            skipEnvironmentChecksRequested: false,
            sections: [],
            tests: [],
            splitTotal: null,
            splitGroup: null,
            filter: null,
            testSuite: null,
            logDirectory: null,
            emitMetrics: true,
            extraOptions: [],
        );

        $this->assertSame(600, $data->timeoutSeconds);
        $this->assertSame(1, $data->parallelProcesses);
        $this->assertEmpty($data->extraOptions);
    }

    public function test_run_options_from_command_input(): void
    {
        $data = TestRunOptionsData::fromCommandInput(
            options: [
                'debug' => false,
                'parallel' => 4,
                'fail-fast' => true,
                'section' => ['Unit/Models'],
            ],
            arguments: ['tests' => []],
            splitTotal: 3,
            splitGroup: 1,
            logDirectory: '/tmp/logs',
            extraOptions: ['stripeOnly' => true],
        );

        $this->assertSame(4, $data->parallelProcesses);
        $this->assertTrue($data->failFast);
        $this->assertSame(3, $data->splitTotal);
        $this->assertSame(1, $data->splitGroup);
        $this->assertSame(['Unit/Models'], $data->sections);
        $this->assertTrue($data->extraOptions['stripeOnly']);
    }
}
