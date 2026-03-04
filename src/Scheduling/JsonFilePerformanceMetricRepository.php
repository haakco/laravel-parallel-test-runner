<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Scheduling;

use Haakco\ParallelTestRunner\Contracts\PerformanceMetricRepositoryInterface;
use Override;
use RuntimeException;

final class JsonFilePerformanceMetricRepository implements PerformanceMetricRepositoryInterface
{
    /** @var array<string, float>|null */
    private ?array $weights = null;

    public function __construct(
        private readonly string $weightsFilePath,
    ) {}

    /**
     * @return array<string, float>
     */
    #[Override]
    public function getHistoricalWeights(): array
    {
        if ($this->weights !== null) {
            return $this->weights;
        }

        if (! file_exists($this->weightsFilePath)) {
            $this->weights = [];

            return [];
        }

        $content = file_get_contents($this->weightsFilePath);

        if ($content === false || $content === '') {
            $this->weights = [];

            return [];
        }

        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            $this->weights = [];

            return [];
        }

        $this->weights = array_map(static fn(mixed $v): float => (float) $v, $decoded);

        return $this->weights;
    }

    #[Override]
    public function recordWeight(string $sectionName, float $duration): void
    {
        $weights = $this->getHistoricalWeights();
        $weights[$sectionName] = $duration;
        $this->weights = $weights;

        $directory = dirname($this->weightsFilePath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory: {$directory}");
        }

        $written = file_put_contents(
            $this->weightsFilePath,
            json_encode($this->weights, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        if ($written === false) {
            throw new RuntimeException("Failed to write weights file: {$this->weightsFilePath}");
        }
    }
}
