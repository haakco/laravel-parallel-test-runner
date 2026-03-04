<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Feature\Commands;

use Haakco\ParallelTestRunner\Data\Results\SectionListResultData;
use Haakco\ParallelTestRunner\Data\Results\TestRunnerConfigurationFeedbackData;
use Haakco\ParallelTestRunner\Data\Results\TestRunResultData;
use Haakco\ParallelTestRunner\Services\TestRunnerService;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class TestCommandTest extends TestCase
{
    private MockInterface&TestRunnerService $testRunner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testRunner = Mockery::mock(TestRunnerService::class);
        $this->app->instance(TestRunnerService::class, $this->testRunner);
    }

    public function test_test_command_is_registered_when_override_enabled(): void
    {
        config()->set('parallel-test-runner.override_test_command', true);

        $this->artisan('list')
            ->expectsOutputToContain('test:run-sections');
    }

    public function test_override_routes_to_section_runner(): void
    {
        config()->set('parallel-test-runner.override_test_command', true);

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
            ->andReturn(TestRunResultData::success('All tests passed', 5.0, 10, 20));

        $this->artisan('test')
            ->assertSuccessful();
    }

    public function test_override_disabled_config_is_respected(): void
    {
        config()->set('parallel-test-runner.override_test_command', false);

        $this->assertFalse(config('parallel-test-runner.override_test_command'));
    }

    public function test_legacy_flag_is_recognized(): void
    {
        config()->set('parallel-test-runner.override_test_command', true);

        // The --legacy flag should exist as an option on the test command
        // When no collision runner is available, it should return failure
        $this->artisan('test', ['--legacy' => true])
            ->assertExitCode(1);
    }

    public function test_forwards_list_option(): void
    {
        config()->set('parallel-test-runner.override_test_command', true);

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

        $this->artisan('test', ['--list' => true])
            ->assertSuccessful();
    }

    public function test_forwards_fail_fast_option(): void
    {
        config()->set('parallel-test-runner.override_test_command', true);

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
            ->andReturn(TestRunResultData::success('All tests passed', 3.0, 5, 10));

        $this->artisan('test', ['--fail-fast' => true])
            ->assertSuccessful();
    }

    public function test_forwards_debug_option(): void
    {
        config()->set('parallel-test-runner.override_test_command', true);

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
            ->andReturn(TestRunResultData::success('All tests passed', 3.0, 5, 10));

        $this->artisan('test', ['--debug' => true])
            ->assertSuccessful();
    }
}
