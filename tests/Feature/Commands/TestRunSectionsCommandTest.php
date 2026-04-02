<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Feature\Commands;

use Haakco\ParallelTestRunner\Data\Results\BackgroundRunStartResultData;
use Haakco\ParallelTestRunner\Data\Results\BackgroundRunStatusData;
use Haakco\ParallelTestRunner\Data\Results\DatabaseRefreshResultData;
use Haakco\ParallelTestRunner\Data\Results\HangingTestsResultData;
use Haakco\ParallelTestRunner\Data\Results\SectionListResultData;
use Haakco\ParallelTestRunner\Data\Results\TestRunnerConfigurationFeedbackData;
use Haakco\ParallelTestRunner\Data\Results\TestRunResultData;
use Haakco\ParallelTestRunner\Data\TestRunOptionsData;
use Haakco\ParallelTestRunner\Data\TestSectionData;
use Haakco\ParallelTestRunner\Services\TestRunnerService;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;

final class TestRunSectionsCommandTest extends TestCase
{
    private MockInterface&TestRunnerService $testRunner;

    private string $originalBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBasePath = $this->app->basePath();
        $this->testRunner = Mockery::mock(TestRunnerService::class);
        $this->app->instance(TestRunnerService::class, $this->testRunner);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBasePath);

        parent::tearDown();
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('list')
            ->expectsOutputToContain('test:run-sections');
    }

    public function test_split_total_must_be_at_least_two(): void
    {
        $this->artisan('test:run-sections', ['--split-total' => 1])
            ->expectsOutputToContain('--split-total must be at least 2')
            ->assertExitCode(1);
    }

    public function test_split_group_requires_split_total(): void
    {
        $this->artisan('test:run-sections', ['--split-group' => 1])
            ->expectsOutputToContain('--split-group requires --split-total')
            ->assertExitCode(1);
    }

    public function test_split_group_must_be_within_range(): void
    {
        $this->artisan('test:run-sections', ['--split-total' => 3, '--split-group' => 5])
            ->expectsOutputToContain('--split-group must be between 1 and 3')
            ->assertExitCode(1);
    }

    public function test_status_flag_shows_not_running(): void
    {
        $this->testRunner
            ->shouldReceive('checkBackgroundStatus')
            ->once()
            ->andReturn(BackgroundRunStatusData::notRunning());

        $this->artisan('test:run-sections', ['--status' => true])
            ->expectsOutputToContain('not currently running')
            ->assertSuccessful();
    }

    public function test_status_flag_shows_running_process(): void
    {
        $this->testRunner
            ->shouldReceive('checkBackgroundStatus')
            ->once()
            ->andReturn(BackgroundRunStatusData::running(12345, '/tmp/test-logs'));

        $this->artisan('test:run-sections', ['--status' => true])
            ->expectsOutputToContain('PID')
            ->assertSuccessful();
    }

    public function test_list_flag_outputs_sections(): void
    {
        $sections = [
            new TestSectionData(
                name: 'Unit/Models',
                type: 'directory',
                path: '/app/tests/Unit/Models',
                files: ['UserTest.php', 'OrderTest.php'],
                fileCount: 2,
            ),
            new TestSectionData(
                name: 'Feature/Api',
                type: 'directory',
                path: '/app/tests/Feature/Api',
                files: ['LoginTest.php'],
                fileCount: 1,
            ),
        ];

        $this->testRunner
            ->shouldReceive('configure')
            ->once()
            ->andReturn(new TestRunnerConfigurationFeedbackData(
                message: 'Configuration applied',
                settings: [],
            ));

        $this->testRunner
            ->shouldReceive('listSectionsWithGroups')
            ->with(null, null)
            ->once()
            ->andReturn(new SectionListResultData(
                sections: $sections,
                totalFiles: 3,
                totalSections: 2,
            ));

        $this->artisan('test:run-sections', ['--list' => true])
            ->expectsOutputToContain('Unit/Models')
            ->expectsOutputToContain('Feature/Api')
            ->expectsOutputToContain('Total sections: 2')
            ->expectsOutputToContain('Total test files: 3')
            ->assertSuccessful();
    }

    public function test_list_with_split_shows_subset(): void
    {
        $sections = [
            new TestSectionData(
                name: 'Unit/Models',
                type: 'directory',
                path: '/app/tests/Unit/Models',
                files: ['UserTest.php'],
                fileCount: 1,
            ),
        ];

        $this->testRunner
            ->shouldReceive('configure')
            ->once()
            ->andReturn(new TestRunnerConfigurationFeedbackData(
                message: 'Configuration applied',
                settings: [],
            ));

        $this->testRunner
            ->shouldReceive('listSectionsWithGroups')
            ->with(2, 1)
            ->once()
            ->andReturn(new SectionListResultData(
                sections: $sections,
                totalFiles: 1,
                totalSections: 1,
            ));

        $this->artisan('test:run-sections', [
            '--list' => true,
            '--split-total' => 2,
            '--split-group' => 1,
        ])
            ->expectsOutputToContain('Showing group 1 of 2')
            ->expectsOutputToContain('Unit/Models')
            ->assertSuccessful();
    }

    public function test_list_with_no_sections_shows_warning(): void
    {
        $this->testRunner
            ->shouldReceive('configure')
            ->once()
            ->andReturn(new TestRunnerConfigurationFeedbackData(
                message: 'Configuration applied',
                settings: [],
            ));

        $this->testRunner
            ->shouldReceive('listSectionsWithGroups')
            ->once()
            ->andReturn(new SectionListResultData(
                sections: [],
                totalFiles: 0,
                totalSections: 0,
            ));

        $this->artisan('test:run-sections', ['--list' => true])
            ->expectsOutputToContain('No test sections found')
            ->assertSuccessful();
    }

    public function test_find_hanging_with_no_hanging_tests(): void
    {
        $this->testRunner
            ->shouldReceive('configure')
            ->once()
            ->andReturn(new TestRunnerConfigurationFeedbackData(
                message: 'Configuration applied',
                settings: [],
            ));

        $this->testRunner
            ->shouldReceive('findHangingTests')
            ->once()
            ->andReturn(new HangingTestsResultData(
                found: false,
                hangingSections: [],
                passedSections: ['Unit/Models'],
                threshold: 10,
            ));

        $this->artisan('test:run-sections', ['--find-hanging' => true])
            ->expectsOutputToContain('No hanging tests found')
            ->assertSuccessful();
    }

    public function test_find_hanging_with_hanging_tests_returns_failure(): void
    {
        $this->testRunner
            ->shouldReceive('configure')
            ->once()
            ->andReturn(new TestRunnerConfigurationFeedbackData(
                message: 'Configuration applied',
                settings: [],
            ));

        $this->testRunner
            ->shouldReceive('findHangingTests')
            ->once()
            ->andReturn(new HangingTestsResultData(
                found: true,
                hangingSections: ['Feature/SlowTest'],
                passedSections: [],
                threshold: 10,
            ));

        $this->artisan('test:run-sections', ['--find-hanging' => true])
            ->expectsOutputToContain('Feature/SlowTest')
            ->assertExitCode(1);
    }

    public function test_successful_test_run(): void
    {
        $this->testRunner
            ->shouldReceive('configure')
            ->once()
            ->andReturn(new TestRunnerConfigurationFeedbackData(
                message: 'Configuration applied',
                settings: [],
            ));

        $this->testRunner
            ->shouldReceive('runConfigured')
            ->once()
            ->andReturn(TestRunResultData::success('All tests passed', 12.5, 42, 100));

        $this->artisan('test:run-sections')
            ->expectsOutputToContain('Tests passed')
            ->assertSuccessful();
    }

    public function test_test_file_alias_is_forwarded_as_specific_test(): void
    {
        $this->testRunner
            ->shouldReceive('configure')
            ->once()
            ->withArgs(fn(TestRunOptionsData $options): bool => $options->tests === ['tests/Unit/FooTest.php'])
            ->andReturn(new TestRunnerConfigurationFeedbackData(
                message: 'Configuration applied',
                settings: [],
            ));

        $this->testRunner
            ->shouldReceive('runConfigured')
            ->once()
            ->andReturn(TestRunResultData::success('All tests passed', 1.0, 1, 1));

        $this->artisan('test:run-sections', ['--test-file' => ['tests/Unit/FooTest.php']])
            ->assertSuccessful();
    }

    public function test_failed_test_run(): void
    {
        $this->testRunner
            ->shouldReceive('configure')
            ->once()
            ->andReturn(new TestRunnerConfigurationFeedbackData(
                message: 'Configuration applied',
                settings: [],
            ));

        $this->testRunner
            ->shouldReceive('runConfigured')
            ->once()
            ->andReturn(TestRunResultData::failure('Some tests failed', 8.3, 1, 2, 1));

        $this->artisan('test:run-sections')
            ->expectsOutputToContain('Tests failed')
            ->assertExitCode(1);
    }

    public function test_test_run_exception_returns_failure(): void
    {
        $this->testRunner
            ->shouldReceive('configure')
            ->once()
            ->andReturn(new TestRunnerConfigurationFeedbackData(
                message: 'Configuration applied',
                settings: [],
            ));

        $this->testRunner
            ->shouldReceive('runConfigured')
            ->once()
            ->andThrow(new RuntimeException('PHPUnit binary not found'));

        $this->artisan('test:run-sections')
            ->expectsOutputToContain('Test execution failed')
            ->assertExitCode(1);
    }

    public function test_background_run_success(): void
    {
        $this->testRunner
            ->shouldReceive('startBackgroundRun')
            ->once()
            ->andReturn(BackgroundRunStartResultData::success(99999, '/tmp/test-logs'));

        $this->artisan('test:run-sections', ['--background' => true])
            ->expectsOutputToContain('started in background')
            ->assertSuccessful();
    }

    public function test_background_run_failure(): void
    {
        $this->testRunner
            ->shouldReceive('startBackgroundRun')
            ->once()
            ->andReturn(BackgroundRunStartResultData::failure('Already running'));

        $this->artisan('test:run-sections', ['--background' => true])
            ->expectsOutputToContain('Already running')
            ->assertExitCode(1);
    }

    public function test_refresh_db_before_run(): void
    {
        $this->testRunner
            ->shouldReceive('refreshTestDatabase')
            ->once()
            ->andReturn(DatabaseRefreshResultData::success(2.5));

        $this->testRunner
            ->shouldReceive('configure')
            ->once()
            ->andReturn(new TestRunnerConfigurationFeedbackData(
                message: 'Configuration applied',
                settings: [],
            ));

        $this->testRunner
            ->shouldReceive('runConfigured')
            ->once()
            ->andReturn(TestRunResultData::success('All passed', 5.0));

        $this->artisan('test:run-sections', ['--refresh-db' => true])
            ->expectsOutputToContain('Database refreshed successfully')
            ->assertSuccessful();
    }

    public function test_refresh_db_failure_stops_the_command(): void
    {
        $this->testRunner
            ->shouldReceive('refreshTestDatabase')
            ->once()
            ->andReturn(DatabaseRefreshResultData::failure('boom'));

        $this->testRunner
            ->shouldNotReceive('configure');

        $this->testRunner
            ->shouldNotReceive('runConfigured');

        $this->artisan('test:run-sections', ['--refresh-db' => true])
            ->expectsOutputToContain('Failed to refresh database: boom')
            ->assertExitCode(1);
    }

    public function test_list_output_renders_relative_paths_with_windows_separators(): void
    {
        $windowsSectionPath = str_replace('/', '\\', base_path('tests/Unit/Models'));

        $sections = [
            new TestSectionData(
                name: 'Unit/Models',
                type: 'directory',
                path: $windowsSectionPath,
                files: ['UserTest.php', 'OrderTest.php'],
                fileCount: 2,
            ),
        ];

        $this->testRunner
            ->shouldReceive('configure')
            ->once()
            ->andReturn(new TestRunnerConfigurationFeedbackData(
                message: 'Configuration applied',
                settings: [],
            ));

        $this->testRunner
            ->shouldReceive('listSectionsWithGroups')
            ->with(null, null)
            ->once()
            ->andReturn(new SectionListResultData(
                sections: $sections,
                totalFiles: 2,
                totalSections: 1,
            ));

        $this->artisan('test:run-sections', ['--list' => true])
            ->expectsOutputToContain('tests/Unit/Models')
            ->assertSuccessful();
    }

    public function test_test_run_renders_relative_log_directory_with_windows_separators(): void
    {
        $windowsLogDirectory = str_replace('/', '\\', base_path('tests/Unit/Logs'));

        $this->testRunner
            ->shouldReceive('configure')
            ->once()
            ->andReturn(new TestRunnerConfigurationFeedbackData(
                message: 'Configuration applied',
                settings: [],
            ));

        $this->testRunner
            ->shouldReceive('runConfigured')
            ->once()
            ->andReturn(TestRunResultData::success('All tests passed', 2.5, 4, 8, $windowsLogDirectory));

        $this->artisan('test:run-sections')
            ->expectsOutputToContain('Tests passed')
            ->expectsOutputToContain('Logs: tests/Unit/Logs')
            ->assertSuccessful();
    }

    public function test_list_output_renders_relative_paths_when_base_path_is_a_symlink(): void
    {
        $baseDirectory = sys_get_temp_dir() . '/ptr-base-' . uniqid();
        $realBasePath = $baseDirectory . '-real';
        $linkedBasePath = $baseDirectory . '-link';

        mkdir($realBasePath . '/tests/Unit', 0777, true);
        symlink($realBasePath, $linkedBasePath);
        $this->app->setBasePath($linkedBasePath);

        $sections = [
            new TestSectionData(
                name: 'Unit/Models',
                type: 'directory',
                path: $realBasePath . '/tests/Unit',
                files: ['UserTest.php'],
                fileCount: 1,
            ),
        ];

        $this->testRunner
            ->shouldReceive('configure')
            ->once()
            ->andReturn(new TestRunnerConfigurationFeedbackData(
                message: 'Configuration applied',
                settings: [],
            ));

        $this->testRunner
            ->shouldReceive('listSectionsWithGroups')
            ->with(null, null)
            ->once()
            ->andReturn(new SectionListResultData(
                sections: $sections,
                totalFiles: 1,
                totalSections: 1,
            ));

        try {
            $exitCode = Artisan::call('test:run-sections', ['--list' => true]);
            $output = Artisan::output();

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('tests/Unit', $output);
            $this->assertMatchesRegularExpression('/\|\s*Unit\/Models\s*\|\s*directory\s*\|\s*1\s*\|\s*tests\/Unit\s*\|/', $output);
            $this->assertStringNotContainsString($realBasePath, $output);
            $this->assertStringNotContainsString($linkedBasePath, $output);
        } finally {
            unlink($linkedBasePath);
            @rmdir($realBasePath . '/tests/Unit');
            @rmdir($realBasePath . '/tests');
            @rmdir($realBasePath);
        }
    }
}
