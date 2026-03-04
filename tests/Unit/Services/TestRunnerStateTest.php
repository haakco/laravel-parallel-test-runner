<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Services;

use Haakco\ParallelTestRunner\Services\TestRunnerState;
use Haakco\ParallelTestRunner\Tests\TestCase;

final class TestRunnerStateTest extends TestCase
{
    public function test_initializes_with_sections(): void
    {
        $state = new TestRunnerState();
        $state->initialize(['section-a', 'section-b', 'section-c']);

        $data = $state->toStateData();
        $this->assertSame(['section-a', 'section-b', 'section-c'], $data->pending);
        $this->assertSame([], $data->running);
        $this->assertSame([], $data->completed);
        $this->assertSame([], $data->failed);
    }

    public function test_marks_section_running(): void
    {
        $state = new TestRunnerState();
        $state->initialize(['a', 'b']);
        $state->markRunning('a');

        $this->assertSame(['b'], $state->getPending());
        $this->assertSame(['a'], $state->getRunning());
    }

    public function test_marks_section_completed(): void
    {
        $state = new TestRunnerState();
        $state->initialize(['a', 'b']);
        $state->markRunning('a');
        $state->markCompleted('a');

        $this->assertSame([], $state->getRunning());
        $this->assertSame(['a'], $state->getCompleted());
    }

    public function test_marks_section_failed(): void
    {
        $state = new TestRunnerState();
        $state->initialize(['a', 'b']);
        $state->markRunning('a');
        $state->markFailed('a');

        $this->assertSame([], $state->getRunning());
        $this->assertSame(['a'], $state->getFailed());
    }

    public function test_has_pending_returns_true_when_sections_remain(): void
    {
        $state = new TestRunnerState();
        $state->initialize(['a']);

        $this->assertTrue($state->hasPending());
    }

    public function test_has_pending_returns_false_when_all_done(): void
    {
        $state = new TestRunnerState();
        $state->initialize(['a']);
        $state->markCompleted('a');

        $this->assertFalse($state->hasPending());
    }

    public function test_next_section_returns_first_pending(): void
    {
        $state = new TestRunnerState();
        $state->initialize(['x', 'y', 'z']);

        $this->assertSame('x', $state->nextPending());

        $state->markRunning('x');
        $this->assertSame('y', $state->nextPending());
    }

    public function test_next_section_returns_null_when_empty(): void
    {
        $state = new TestRunnerState();
        $state->initialize([]);

        $this->assertNull($state->nextPending());
    }

    public function test_to_state_data_serializes_correctly(): void
    {
        $state = new TestRunnerState();
        $state->initialize(['a', 'b', 'c']);
        $state->markRunning('a');
        $state->markCompleted('a');
        $state->markRunning('b');
        $state->markFailed('b');

        $data = $state->toStateData();
        $this->assertSame(['c'], $data->pending);
        $this->assertSame([], $data->running);
        $this->assertSame(['a'], $data->completed);
        $this->assertSame(['b'], $data->failed);
    }

    public function test_duplicate_marks_are_idempotent(): void
    {
        $state = new TestRunnerState();
        $state->initialize(['a']);
        $state->markCompleted('a');
        $state->markCompleted('a');

        $this->assertSame(['a'], $state->getCompleted());
        $this->assertCount(1, $state->getCompleted());
    }
}
