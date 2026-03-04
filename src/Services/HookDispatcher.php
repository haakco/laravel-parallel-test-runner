<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Services;

use Closure;
use Illuminate\Contracts\Container\Container;

final readonly class HookDispatcher
{
    public function __construct(
        private Container $container,
    ) {}

    /**
     * Fire all hooks registered for the given event name.
     *
     * Hooks are resolved in registration order. Class-based hooks are resolved
     * from the container. Closure hooks are called directly. Exceptions propagate
     * to the caller.
     *
     * @param array<int, mixed> $arguments Positional arguments passed to each hook's handle() method.
     */
    public function fire(string $hookName, array $arguments = []): void
    {
        /** @var list<class-string|Closure> $hooks */
        $hooks = config("parallel-test-runner.hooks.{$hookName}", []);

        foreach ($hooks as $hook) {
            if ($hook instanceof Closure) {
                $hook(...$arguments);

                continue;
            }

            if (is_string($hook) && class_exists($hook)) {
                $instance = $this->container->make($hook);
                $instance->handle(...$arguments);
            }
        }
    }
}
