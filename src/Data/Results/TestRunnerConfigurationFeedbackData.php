<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Results;

use Spatie\LaravelData\Data;

final class TestRunnerConfigurationFeedbackData extends Data
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        public string $message,
        public array $settings,
    ) {}
}
