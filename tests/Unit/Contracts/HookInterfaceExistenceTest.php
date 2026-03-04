<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Contracts;

use Haakco\ParallelTestRunner\Contracts\Hooks\AfterProvisionHook;
use Haakco\ParallelTestRunner\Contracts\Hooks\AfterRunHook;
use Haakco\ParallelTestRunner\Contracts\Hooks\AfterWorkerRunHook;
use Haakco\ParallelTestRunner\Contracts\Hooks\BeforeCleanupHook;
use Haakco\ParallelTestRunner\Contracts\Hooks\BeforeProvisionHook;
use Haakco\ParallelTestRunner\Contracts\Hooks\BeforeRunHook;
use Haakco\ParallelTestRunner\Contracts\Hooks\BeforeWorkerRunHook;
use Haakco\ParallelTestRunner\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class HookInterfaceExistenceTest extends TestCase
{
    #[DataProvider('hookInterfaceProvider')]
    public function test_hook_interface_exists(string $interfaceFqn): void
    {
        $this->assertTrue(interface_exists($interfaceFqn), "Hook interface {$interfaceFqn} should exist");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hookInterfaceProvider(): array
    {
        return [
            'BeforeRunHook' => [BeforeRunHook::class],
            'BeforeProvisionHook' => [BeforeProvisionHook::class],
            'AfterProvisionHook' => [AfterProvisionHook::class],
            'BeforeWorkerRunHook' => [BeforeWorkerRunHook::class],
            'AfterWorkerRunHook' => [AfterWorkerRunHook::class],
            'BeforeCleanupHook' => [BeforeCleanupHook::class],
            'AfterRunHook' => [AfterRunHook::class],
        ];
    }
}
