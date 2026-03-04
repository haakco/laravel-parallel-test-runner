<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Contracts;

use Haakco\ParallelTestRunner\Contracts\DatabaseProvisionerInterface;
use Haakco\ParallelTestRunner\Contracts\DatabaseSeederInterface;
use Haakco\ParallelTestRunner\Contracts\PerformanceMetricRepositoryInterface;
use Haakco\ParallelTestRunner\Contracts\ResultAggregatorInterface;
use Haakco\ParallelTestRunner\Contracts\SchemaLoaderInterface;
use Haakco\ParallelTestRunner\Contracts\SectionResolverInterface;
use Haakco\ParallelTestRunner\Contracts\TestRunReportWriterInterface;
use Haakco\ParallelTestRunner\Contracts\WorkerExecutorInterface;
use Haakco\ParallelTestRunner\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class InterfaceExistenceTest extends TestCase
{
    #[DataProvider('interfaceProvider')]
    public function test_interface_exists(string $interfaceFqn): void
    {
        $this->assertTrue(interface_exists($interfaceFqn), "Interface {$interfaceFqn} should exist");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function interfaceProvider(): array
    {
        return [
            'SectionResolverInterface' => [SectionResolverInterface::class],
            'DatabaseProvisionerInterface' => [DatabaseProvisionerInterface::class],
            'DatabaseSeederInterface' => [DatabaseSeederInterface::class],
            'SchemaLoaderInterface' => [SchemaLoaderInterface::class],
            'WorkerExecutorInterface' => [WorkerExecutorInterface::class],
            'ResultAggregatorInterface' => [ResultAggregatorInterface::class],
            'PerformanceMetricRepositoryInterface' => [PerformanceMetricRepositoryInterface::class],
            'TestRunReportWriterInterface' => [TestRunReportWriterInterface::class],
        ];
    }
}
