<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data;

use Spatie\LaravelData\Data;

final class TestRunnerStateData extends Data
{
    /**
     * @param list<string> $pending
     * @param list<string> $running
     * @param list<string> $completed
     * @param list<string> $failed
     */
    public function __construct(
        public array $pending,
        public array $running,
        public array $completed,
        public array $failed,
    ) {}

    /**
     * @param list<string> $sections
     */
    public static function initial(array $sections): self
    {
        return new self(pending: $sections, running: [], completed: [], failed: []);
    }
}
