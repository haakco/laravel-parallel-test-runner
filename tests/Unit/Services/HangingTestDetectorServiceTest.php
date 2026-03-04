<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Services;

use Haakco\ParallelTestRunner\Data\TestSectionData;
use Haakco\ParallelTestRunner\Services\HangingTestDetectorService;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Override;
use RuntimeException;

final class HangingTestDetectorServiceTest extends TestCase
{
    private HangingTestDetectorService $service;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new HangingTestDetectorService();
    }

    public function test_no_hanging_tests_when_all_pass(): void
    {
        $sections = [
            $this->makeSection('Unit/FooTest'),
            $this->makeSection('Unit/BarTest'),
        ];

        $result = $this->service->findHangingTests(
            sections: $sections,
            runSections: static fn(array $names): bool => true,
            setTimeout: static fn(int $t): null => null,
            restoreTimeout: static fn(int $t): null => null,
            shortTimeout: 10,
        );

        $this->assertFalse($result->found);
        $this->assertSame([], $result->hangingSections);
        $this->assertSame(['Unit/FooTest', 'Unit/BarTest'], $result->passedSections);
        $this->assertSame(10, $result->threshold);
    }

    public function test_detects_hanging_tests_on_failure(): void
    {
        $sections = [
            $this->makeSection('Unit/FooTest'),
            $this->makeSection('Unit/HangingTest'),
        ];

        $result = $this->service->findHangingTests(
            sections: $sections,
            runSections: static fn(array $names): bool => $names[0] !== 'Unit/HangingTest',
            setTimeout: static fn(int $t): null => null,
            restoreTimeout: static fn(int $t): null => null,
            shortTimeout: 5,
        );

        $this->assertTrue($result->found);
        $this->assertSame(['Unit/HangingTest'], $result->hangingSections);
        $this->assertSame(['Unit/FooTest'], $result->passedSections);
        $this->assertSame(5, $result->threshold);
    }

    public function test_detects_hanging_on_timeout_exception(): void
    {
        $sections = [$this->makeSection('Unit/SlowTest')];

        $result = $this->service->findHangingTests(
            sections: $sections,
            runSections: static function (array $names): never {
                throw new RuntimeException('Process timeout exceeded');
            },
            setTimeout: static fn(int $t): null => null,
            restoreTimeout: static fn(int $t): null => null,
            shortTimeout: 3,
        );

        $this->assertTrue($result->found);
        $this->assertSame(['Unit/SlowTest'], $result->hangingSections);
    }

    public function test_non_timeout_exception_does_not_mark_as_hanging(): void
    {
        $sections = [$this->makeSection('Unit/ErrorTest')];

        $result = $this->service->findHangingTests(
            sections: $sections,
            runSections: static function (): never {
                throw new RuntimeException('Some other error');
            },
            setTimeout: static fn(int $t): null => null,
            restoreTimeout: static fn(int $t): null => null,
            shortTimeout: 10,
        );

        // Non-timeout errors don't mark as hanging
        $this->assertFalse($result->found);
        $this->assertSame([], $result->hangingSections);
    }

    public function test_sets_and_restores_timeout(): void
    {
        $timeoutHistory = [];
        $restoreHistory = [];

        $sections = [$this->makeSection('Unit/Test')];

        $this->service->findHangingTests(
            sections: $sections,
            runSections: static fn(array $names): bool => true,
            setTimeout: static function (int $t) use (&$timeoutHistory): void {
                $timeoutHistory[] = $t;
            },
            restoreTimeout: static function (int $t) use (&$restoreHistory): void {
                $restoreHistory[] = $t;
            },
            shortTimeout: 7,
        );

        $this->assertSame([7], $timeoutHistory);
        $this->assertSame([7], $restoreHistory);
    }

    public function test_progress_callback_is_called(): void
    {
        $progressCalls = [];

        $sections = [
            $this->makeSection('Unit/FooTest'),
            $this->makeSection('Unit/BarTest'),
        ];

        $this->service->findHangingTests(
            sections: $sections,
            runSections: static fn(array $names): bool => true,
            setTimeout: static fn(int $t): null => null,
            restoreTimeout: static fn(int $t): null => null,
            shortTimeout: 10,
            onProgress: static function (string $section, int $current, int $total, string $status) use (&$progressCalls): void {
                $progressCalls[] = ['section' => $section, 'current' => $current, 'total' => $total, 'status' => $status];
            },
        );

        $this->assertCount(2, $progressCalls);
        $this->assertSame('Unit/FooTest', $progressCalls[0]['section']);
        $this->assertSame(1, $progressCalls[0]['current']);
        $this->assertSame(2, $progressCalls[0]['total']);
        $this->assertSame('OK', $progressCalls[0]['status']);
    }

    public function test_empty_sections_returns_no_hanging(): void
    {
        $result = $this->service->findHangingTests(
            sections: [],
            runSections: static fn(array $names): bool => true,
            setTimeout: static fn(int $t): null => null,
            restoreTimeout: static fn(int $t): null => null,
        );

        $this->assertFalse($result->found);
        $this->assertSame([], $result->hangingSections);
        $this->assertSame([], $result->passedSections);
    }

    private function makeSection(string $name): TestSectionData
    {
        return new TestSectionData(
            name: $name,
            type: 'file',
            path: $name . '.php',
            files: [$name . '.php'],
            fileCount: 1,
        );
    }
}
