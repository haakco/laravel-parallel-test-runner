<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Database;

use Haakco\ParallelTestRunner\Contracts\SchemaLoaderInterface;
use Haakco\ParallelTestRunner\Database\SchemaDumpLoader;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Support\Facades\File;

final class SchemaDumpLoaderTest extends TestCase
{
    private SchemaDumpLoader $loader;

    private string $tempSchemaDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = new SchemaDumpLoader();
        $this->tempSchemaDir = sys_get_temp_dir() . '/schema-dump-loader-test-' . uniqid();
        mkdir($this->tempSchemaDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if (is_dir($this->tempSchemaDir)) {
            $files = glob($this->tempSchemaDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_link($file) || is_file($file)) {
                        unlink($file);
                    }
                }
            }

            rmdir($this->tempSchemaDir);
        }

        parent::tearDown();
    }

    public function test_implements_interface(): void
    {
        $this->assertInstanceOf(SchemaLoaderInterface::class, $this->loader);
    }

    public function test_sanitizes_restrict_metacommands(): void
    {
        $input = implode("\n", [
            '\\restrict',
            'CREATE TABLE users (id serial);',
            '\\unrestrict all',
            'CREATE TABLE posts (id serial);',
        ]);

        $expected = implode("\n", [
            'CREATE TABLE users (id serial);',
            'CREATE TABLE posts (id serial);',
        ]);

        $this->assertSame($expected, $this->loader->sanitizeSchemaContent($input));
    }

    public function test_sanitize_preserves_content_without_metacommands(): void
    {
        $input = implode("\n", [
            'CREATE TABLE users (id serial);',
            'CREATE TABLE posts (id serial);',
        ]);

        $this->assertSame($input, $this->loader->sanitizeSchemaContent($input));
    }

    public function test_creates_symlink_for_connection(): void
    {
        $schemaDirectory = database_path('schema');
        $genericPath = $schemaDirectory . '/pgsql-schema.sql';
        $targetPath = $schemaDirectory . '/pgsql_testing-schema.sql';

        File::shouldReceive('ensureDirectoryExists')
            ->with($schemaDirectory)
            ->once();
        File::shouldReceive('exists')
            ->with($genericPath)
            ->once()
            ->andReturn(true);

        // sanitizeSchemaFile calls
        File::shouldReceive('exists')
            ->with($genericPath)
            ->once()
            ->andReturn(true);
        File::shouldReceive('get')
            ->with($genericPath)
            ->once()
            ->andReturn('CREATE TABLE test (id serial);');

        // No put needed since content is unchanged after sanitize.
        // is_link returns false (native PHP), so code checks File::exists for targetPath
        File::shouldReceive('exists')
            ->with($targetPath)
            ->once()
            ->andReturn(false);

        // symlink() is native PHP — will attempt to create, suppress errors
        File::shouldReceive('copy')->never();

        $this->loader->ensureForConnection('pgsql_testing');

        $this->assertTrue(true);
    }

    public function test_noop_when_no_schema_file_exists(): void
    {
        File::shouldReceive('ensureDirectoryExists')->once();
        File::shouldReceive('exists')
            ->once()
            ->andReturn(false);

        $this->loader->ensureForConnection('pgsql_testing');

        // Should return early without error
        $this->assertTrue(true);
    }

    public function test_sanitize_schema_file_removes_restrict_from_file(): void
    {
        $filePath = $this->tempSchemaDir . '/test-schema.sql';
        $content = "\\restrict\nCREATE TABLE t (id serial);\n\\unrestrict all\n";
        file_put_contents($filePath, $content);

        File::shouldReceive('exists')
            ->with($filePath)
            ->once()
            ->andReturn(true);
        File::shouldReceive('get')
            ->with($filePath)
            ->once()
            ->andReturn($content);
        File::shouldReceive('put')
            ->once()
            ->withArgs(fn(string $path, string $sanitized): bool => $path === $filePath
                && ! str_contains($sanitized, '\\restrict')
                && str_contains($sanitized, 'CREATE TABLE'));

        $this->loader->sanitizeSchemaFile($filePath);
    }

    public function test_sanitize_schema_file_skips_nonexistent_file(): void
    {
        File::shouldReceive('exists')
            ->once()
            ->andReturn(false);

        $this->loader->sanitizeSchemaFile('/nonexistent/path.sql');

        // Should not throw
        $this->assertTrue(true);
    }

    public function test_load_schema_delegates_to_ensure_for_connection(): void
    {
        // loadSchema should call ensureForConnection
        File::shouldReceive('ensureDirectoryExists')->once();
        File::shouldReceive('exists')
            ->once()
            ->andReturn(false);

        $this->loader->loadSchema('pgsql_testing', 'some_database');

        $this->assertTrue(true);
    }
}
