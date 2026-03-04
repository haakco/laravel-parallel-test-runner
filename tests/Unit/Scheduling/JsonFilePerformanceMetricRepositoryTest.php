<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Scheduling;

use Haakco\ParallelTestRunner\Scheduling\JsonFilePerformanceMetricRepository;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Override;

final class JsonFilePerformanceMetricRepositoryTest extends TestCase
{
    private string $tempDir;

    private string $weightsFile;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/parallel-test-runner-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->weightsFile = $this->tempDir . '/section-weights.json';
    }

    #[Override]
    protected function tearDown(): void
    {
        if (file_exists($this->weightsFile)) {
            unlink($this->weightsFile);
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_returns_empty_when_file_does_not_exist(): void
    {
        $repo = new JsonFilePerformanceMetricRepository($this->weightsFile);

        $weights = $repo->getHistoricalWeights();

        $this->assertSame([], $weights);
    }

    public function test_returns_empty_when_file_is_empty(): void
    {
        file_put_contents($this->weightsFile, '');

        $repo = new JsonFilePerformanceMetricRepository($this->weightsFile);

        $weights = $repo->getHistoricalWeights();

        $this->assertSame([], $weights);
    }

    public function test_reads_weights_from_json_file(): void
    {
        file_put_contents($this->weightsFile, json_encode([
            'Unit/Models' => 42.5,
            'Feature/Api' => 88.3,
        ]));

        $repo = new JsonFilePerformanceMetricRepository($this->weightsFile);
        $weights = $repo->getHistoricalWeights();

        $this->assertSame(42.5, $weights['Unit/Models']);
        $this->assertSame(88.3, $weights['Feature/Api']);
    }

    public function test_record_weight_creates_file_and_writes(): void
    {
        $repo = new JsonFilePerformanceMetricRepository($this->weightsFile);

        $repo->recordWeight('Unit/Models', 55.0);

        $this->assertFileExists($this->weightsFile);

        $content = json_decode(file_get_contents($this->weightsFile), true);
        // JSON encodes 55.0 as integer 55; verify value not type from raw decode
        $this->assertEquals(55.0, $content['Unit/Models']);
    }

    public function test_record_weight_updates_existing_entry(): void
    {
        file_put_contents($this->weightsFile, json_encode([
            'Unit/Models' => 42.5,
            'Feature/Api' => 88.3,
        ]));

        $repo = new JsonFilePerformanceMetricRepository($this->weightsFile);
        $repo->recordWeight('Unit/Models', 100.0);

        $weights = $repo->getHistoricalWeights();

        $this->assertSame(100.0, $weights['Unit/Models']);
        $this->assertSame(88.3, $weights['Feature/Api']);
    }

    public function test_roundtrip_write_and_read(): void
    {
        $repo = new JsonFilePerformanceMetricRepository($this->weightsFile);

        $repo->recordWeight('Section/A', 10.0);
        $repo->recordWeight('Section/B', 20.0);

        // Create fresh repo to verify file persistence
        $freshRepo = new JsonFilePerformanceMetricRepository($this->weightsFile);
        $weights = $freshRepo->getHistoricalWeights();

        $this->assertSame(10.0, $weights['Section/A']);
        $this->assertSame(20.0, $weights['Section/B']);
    }

    public function test_creates_directory_if_needed(): void
    {
        $nestedPath = $this->tempDir . '/nested/dir/weights.json';

        $repo = new JsonFilePerformanceMetricRepository($nestedPath);
        $repo->recordWeight('Test', 5.0);

        $this->assertFileExists($nestedPath);

        // Clean up nested dirs
        unlink($nestedPath);
        rmdir($this->tempDir . '/nested/dir');
        rmdir($this->tempDir . '/nested');
    }

    public function test_caches_results_in_memory(): void
    {
        file_put_contents($this->weightsFile, json_encode(['A' => 1.0]));

        $repo = new JsonFilePerformanceMetricRepository($this->weightsFile);

        $first = $repo->getHistoricalWeights();

        // Modify file externally
        file_put_contents($this->weightsFile, json_encode(['B' => 2.0]));

        // Should return cached value
        $second = $repo->getHistoricalWeights();

        $this->assertSame($first, $second);
    }
}
