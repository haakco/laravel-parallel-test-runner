<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Parallel;

use Override;
use Spatie\LaravelData\Data;

final class MetricsTotalsData extends Data
{
    public function __construct(
        public int $tests,
        public int $assertions,
        public int $errors,
        public int $failures,
        public int $warnings,
        public int $skipped,
        public int $incomplete,
        public int $risky,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            tests: (int) ($payload['tests'] ?? 0),
            assertions: (int) ($payload['assertions'] ?? 0),
            errors: (int) ($payload['errors'] ?? 0),
            failures: (int) ($payload['failures'] ?? 0),
            warnings: (int) ($payload['warnings'] ?? 0),
            skipped: (int) ($payload['skipped'] ?? 0),
            incomplete: (int) ($payload['incomplete'] ?? 0),
            risky: (int) ($payload['risky'] ?? 0),
        );
    }

    public function accumulate(self $other): self
    {
        return new self(
            tests: $this->tests + $other->tests,
            assertions: $this->assertions + $other->assertions,
            errors: $this->errors + $other->errors,
            failures: $this->failures + $other->failures,
            warnings: $this->warnings + $other->warnings,
            skipped: $this->skipped + $other->skipped,
            incomplete: $this->incomplete + $other->incomplete,
            risky: $this->risky + $other->risky,
        );
    }

    /** @return array<string, int> */
    #[Override]
    public function toArray(): array
    {
        return [
            'tests' => $this->tests, 'assertions' => $this->assertions,
            'errors' => $this->errors, 'failures' => $this->failures,
            'warnings' => $this->warnings, 'skipped' => $this->skipped,
            'incomplete' => $this->incomplete, 'risky' => $this->risky,
        ];
    }
}
