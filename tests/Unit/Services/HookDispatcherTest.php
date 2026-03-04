<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Services;

use Haakco\ParallelTestRunner\Contracts\Hooks\BeforeRunHook;
use Haakco\ParallelTestRunner\Data\RunContext;
use Haakco\ParallelTestRunner\Services\HookDispatcher;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Override;
use RuntimeException;

final class HookDispatcherTest extends TestCase
{
    public function test_fires_class_based_hooks(): void
    {
        TestBeforeRunHook::$called = false;
        config()->set('parallel-test-runner.hooks.before_run', [TestBeforeRunHook::class]);

        $dispatcher = $this->app->make(HookDispatcher::class);
        $dispatcher->fire('before_run', [$this->makeRunContext()]);

        $this->assertTrue(TestBeforeRunHook::$called);
    }

    public function test_fires_closure_hooks(): void
    {
        $called = false;
        config()->set('parallel-test-runner.hooks.before_run', [
            function (RunContext $context) use (&$called): void {
                $called = true;
            },
        ]);

        $dispatcher = $this->app->make(HookDispatcher::class);
        $dispatcher->fire('before_run', [$this->makeRunContext()]);

        $this->assertTrue($called);
    }

    public function test_fires_hooks_in_order(): void
    {
        $order = [];
        config()->set('parallel-test-runner.hooks.before_run', [
            function () use (&$order): void {
                $order[] = 'first';
            },
            function () use (&$order): void {
                $order[] = 'second';
            },
        ]);

        $dispatcher = $this->app->make(HookDispatcher::class);
        $dispatcher->fire('before_run', [$this->makeRunContext()]);

        $this->assertSame(['first', 'second'], $order);
    }

    public function test_hook_exception_propagates(): void
    {
        config()->set('parallel-test-runner.hooks.before_run', [
            function (): never {
                throw new RuntimeException('hook error');
            },
        ]);

        $dispatcher = $this->app->make(HookDispatcher::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hook error');
        $dispatcher->fire('before_run', [$this->makeRunContext()]);
    }

    public function test_no_hooks_configured_is_noop(): void
    {
        config()->set('parallel-test-runner.hooks.before_run', []);
        $dispatcher = $this->app->make(HookDispatcher::class);

        // Should not throw
        $dispatcher->fire('before_run', [$this->makeRunContext()]);
        $this->assertTrue(true);
    }

    public function test_fires_with_no_arguments(): void
    {
        $called = false;
        config()->set('parallel-test-runner.hooks.before_run', [
            function () use (&$called): void {
                $called = true;
            },
        ]);

        $dispatcher = $this->app->make(HookDispatcher::class);
        $dispatcher->fire('before_run');

        $this->assertTrue($called);
    }

    private function makeRunContext(): RunContext
    {
        return new RunContext(
            logDirectory: '/tmp/test-logs',
            command: 'test:run-sections',
            commandArgs: [],
            parallel: false,
            workerCount: 1,
            splitTotal: null,
            splitGroup: null,
            extraOptions: [],
        );
    }
}

/**
 * Test hook class — must be outside the test class for container resolution.
 */
class TestBeforeRunHook implements BeforeRunHook
{
    public static bool $called = false;

    #[Override]
    public function handle(RunContext $context): void
    {
        self::$called = true;
    }
}
