<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Feature\Integration;

use Haakco\ParallelTestRunner\Services\HookDispatcher;
use Haakco\ParallelTestRunner\Tests\TestCase;
use RuntimeException;

final class HookLifecycleTest extends TestCase
{
    public function test_hooks_fire_in_registration_order(): void
    {
        $order = [];

        config()->set('parallel-test-runner.hooks.before_run', [
            function () use (&$order): void {
                $order[] = 'before_run_1';
            },
            function () use (&$order): void {
                $order[] = 'before_run_2';
            },
        ]);

        /** @var HookDispatcher $dispatcher */
        $dispatcher = $this->app->make(HookDispatcher::class);
        $dispatcher->fire('before_run');

        $this->assertSame(['before_run_1', 'before_run_2'], $order);
    }

    public function test_hooks_fire_in_correct_lifecycle_order(): void
    {
        $order = [];

        config()->set('parallel-test-runner.hooks.before_run', [
            function () use (&$order): void {
                $order[] = 'before_run';
            },
        ]);
        config()->set('parallel-test-runner.hooks.before_provision', [
            function () use (&$order): void {
                $order[] = 'before_provision';
            },
        ]);
        config()->set('parallel-test-runner.hooks.after_provision', [
            function () use (&$order): void {
                $order[] = 'after_provision';
            },
        ]);
        config()->set('parallel-test-runner.hooks.before_worker_run', [
            function () use (&$order): void {
                $order[] = 'before_worker_run';
            },
        ]);
        config()->set('parallel-test-runner.hooks.after_worker_run', [
            function () use (&$order): void {
                $order[] = 'after_worker_run';
            },
        ]);
        config()->set('parallel-test-runner.hooks.before_cleanup', [
            function () use (&$order): void {
                $order[] = 'before_cleanup';
            },
        ]);
        config()->set('parallel-test-runner.hooks.after_run', [
            function () use (&$order): void {
                $order[] = 'after_run';
            },
        ]);

        /** @var HookDispatcher $dispatcher */
        $dispatcher = $this->app->make(HookDispatcher::class);

        // Simulate the lifecycle in the expected order
        $dispatcher->fire('before_run');
        $dispatcher->fire('before_provision');
        $dispatcher->fire('after_provision');
        $dispatcher->fire('before_worker_run');
        $dispatcher->fire('after_worker_run');
        $dispatcher->fire('before_cleanup');
        $dispatcher->fire('after_run');

        $this->assertSame([
            'before_run',
            'before_provision',
            'after_provision',
            'before_worker_run',
            'after_worker_run',
            'before_cleanup',
            'after_run',
        ], $order);
    }

    public function test_hooks_receive_arguments(): void
    {
        $receivedArgs = [];

        config()->set('parallel-test-runner.hooks.before_provision', [
            function (mixed ...$args) use (&$receivedArgs): void {
                $receivedArgs = $args;
            },
        ]);

        /** @var HookDispatcher $dispatcher */
        $dispatcher = $this->app->make(HookDispatcher::class);
        $dispatcher->fire('before_provision', ['context-value', 42]);

        $this->assertSame(['context-value', 42], $receivedArgs);
    }

    public function test_empty_hooks_do_not_fail(): void
    {
        config()->set('parallel-test-runner.hooks.before_run', []);

        /** @var HookDispatcher $dispatcher */
        $dispatcher = $this->app->make(HookDispatcher::class);
        $dispatcher->fire('before_run');

        $this->assertTrue(true); // No exception thrown
    }

    public function test_class_based_hooks_are_resolved_from_container(): void
    {
        $invoked = false;

        // Register a test hook class in the container
        $this->app->bind(TestableHook::class, function () use (&$invoked): TestableHook {
            return new TestableHook($invoked);
        });

        config()->set('parallel-test-runner.hooks.after_run', [
            TestableHook::class,
        ]);

        /** @var HookDispatcher $dispatcher */
        $dispatcher = $this->app->make(HookDispatcher::class);
        $dispatcher->fire('after_run');

        $this->assertTrue($invoked);
    }

    public function test_hook_exceptions_propagate_to_caller(): void
    {
        config()->set('parallel-test-runner.hooks.before_run', [
            function (): never {
                throw new RuntimeException('Hook failure');
            },
        ]);

        /** @var HookDispatcher $dispatcher */
        $dispatcher = $this->app->make(HookDispatcher::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Hook failure');

        $dispatcher->fire('before_run');
    }

    public function test_parallel_testing_setup_hook_can_be_registered(): void
    {
        $fired = false;

        config()->set('parallel-test-runner.hooks.after_provision', [
            function () use (&$fired): void {
                $fired = true;
            },
        ]);

        /** @var HookDispatcher $dispatcher */
        $dispatcher = $this->app->make(HookDispatcher::class);
        $dispatcher->fire('after_provision', ['test_db', 'token_1']);

        $this->assertTrue($fired);
    }
}

/**
 * Test fixture for class-based hooks.
 */
class TestableHook
{
    private bool $invoked;

    public function __construct(bool &$invoked)
    {
        $this->invoked = &$invoked;
    }

    public function handle(): void
    {
        $this->invoked = true;
    }
}
