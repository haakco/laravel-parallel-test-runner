<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Data\Parallel;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

final class WorkerMetricsData extends Data
{
    /** @param array<string, SectionResultData> $sections */
    public function __construct(
        public MetricsTotalsData $totals,
        #[DataCollectionOf(SectionResultData::class)]
        public array $sections,
        public float $duration,
    ) {}

    public static function createEmpty(): self
    {
        return new self(
            totals: new MetricsTotalsData(0, 0, 0, 0, 0, 0, 0, 0),
            sections: [],
            duration: 0.0,
        );
    }

    /** @param array<string, mixed> $payload */
    public static function fromExecutionTracking(array $payload): self
    {
        $totalsPayload = $payload['totals'] ?? $payload;
        $sectionsPayload = $payload['sections'] ?? [];
        $sections = [];
        if (is_array($sectionsPayload)) {
            foreach ($sectionsPayload as $name => $sectionData) {
                if (is_string($name) && is_array($sectionData)) {
                    $sections[$name] = SectionResultData::fromTracking($name, $sectionData);
                }
            }
        }

        return new self(
            totals: MetricsTotalsData::fromArray($totalsPayload),
            sections: $sections,
            duration: (float) ($payload['duration'] ?? 0.0),
        );
    }

    public function accumulate(self $other): self
    {
        $sections = $this->sections;
        foreach ($other->sections as $name => $section) {
            $sections[$name] = $section;
        }

        return new self(
            totals: $this->totals->accumulate($other->totals),
            sections: $sections,
            duration: $this->duration + $other->duration,
        );
    }
}
