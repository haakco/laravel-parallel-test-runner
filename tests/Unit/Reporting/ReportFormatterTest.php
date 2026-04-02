<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Reporting;

use Haakco\ParallelTestRunner\Reporting\ReportFormatter;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Support\Facades\File;

final class ReportFormatterTest extends TestCase
{
    private string $originalBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBasePath = $this->app->basePath();
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBasePath);

        parent::tearDown();
    }

    public function test_relative_path_uses_the_canonical_base_path_when_the_checkout_is_symlinked(): void
    {
        $root = sys_get_temp_dir() . '/ptr-report-formatter-' . uniqid();
        $realBasePath = $root . '-real';
        $linkedBasePath = $root . '-link';

        mkdir($realBasePath . '/reports', 0755, true);
        file_put_contents($realBasePath . '/reports/performance.md', 'report');
        symlink($realBasePath, $linkedBasePath);
        $this->app->setBasePath($linkedBasePath);

        try {
            $formatter = new ReportFormatter();

            $this->assertSame(
                'reports/performance.md',
                $formatter->relativePath($realBasePath . '/reports/performance.md'),
            );
        } finally {
            $this->app->setBasePath($this->originalBasePath);
            File::deleteDirectory($realBasePath);
            @unlink($linkedBasePath);
        }
    }

    public function test_relative_path_normalizes_windows_separators_before_comparing(): void
    {
        $formatter = new ReportFormatter();

        $this->assertSame(
            'reports/performance.md',
            $formatter->relativePath(str_replace('/', '\\', base_path('reports/performance.md'))),
        );
    }
}
