<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Services;

use Haakco\ParallelTestRunner\Data\TestRunnerStateData;

final class TestRunnerState
{
    /** @var list<string> */
    private array $pending = [];

    /** @var list<string> */
    private array $running = [];

    /** @var list<string> */
    private array $completed = [];

    /** @var list<string> */
    private array $failed = [];

    /**
     * Initialize the state with a set of section names.
     *
     * @param list<string> $sectionNames
     */
    public function initialize(array $sectionNames): void
    {
        $this->pending = array_values($sectionNames);
        $this->running = [];
        $this->completed = [];
        $this->failed = [];
    }

    /**
     * Mark a pending section as running.
     */
    public function markRunning(string $section): void
    {
        $this->removeFrom($this->pending, $section);

        if (! in_array($section, $this->running, true)) {
            $this->running[] = $section;
        }
    }

    /**
     * Mark a running section as completed (passed).
     */
    public function markCompleted(string $section): void
    {
        $this->removeFrom($this->running, $section);
        $this->removeFrom($this->pending, $section);

        if (! in_array($section, $this->completed, true)) {
            $this->completed[] = $section;
        }
    }

    /**
     * Mark a running section as failed.
     */
    public function markFailed(string $section): void
    {
        $this->removeFrom($this->running, $section);
        $this->removeFrom($this->pending, $section);

        if (! in_array($section, $this->failed, true)) {
            $this->failed[] = $section;
        }
    }

    /**
     * Whether there are still pending sections.
     */
    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    /**
     * Return the next pending section name, or null if none remain.
     */
    public function nextPending(): ?string
    {
        return $this->pending[0] ?? null;
    }

    /**
     * Serialize current state to a DTO.
     */
    public function toStateData(): TestRunnerStateData
    {
        return new TestRunnerStateData(
            pending: $this->pending,
            running: $this->running,
            completed: $this->completed,
            failed: $this->failed,
        );
    }

    /**
     * @return list<string>
     */
    public function getPending(): array
    {
        return $this->pending;
    }

    /**
     * @return list<string>
     */
    public function getRunning(): array
    {
        return $this->running;
    }

    /**
     * @return list<string>
     */
    public function getCompleted(): array
    {
        return $this->completed;
    }

    /**
     * @return list<string>
     */
    public function getFailed(): array
    {
        return $this->failed;
    }

    /**
     * @param list<string> $list
     */
    private function removeFrom(array &$list, string $value): void
    {
        $list = array_values(array_filter(
            $list,
            static fn(string $item): bool => $item !== $value,
        ));
    }
}
