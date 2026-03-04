<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Database;

use Haakco\ParallelTestRunner\Contracts\SchemaLoaderInterface;
use Illuminate\Support\Facades\File;
use Override;

/**
 * Ensures connection-specific schema dump symlinks exist and sanitizes
 * PostgreSQL metacommands that break migration playback.
 */
final class SchemaDumpLoader implements SchemaLoaderInterface
{
    #[Override]
    public function loadSchema(string $connection, string $databaseName): void
    {
        $this->ensureForConnection($connection);
    }

    /**
     * Create a schema dump symlink for the given connection name
     * (e.g. "pgsql_testing" -> "pgsql_testing-schema.sql" symlinked to "pgsql-schema.sql").
     */
    public function ensureForConnection(string $connection): void
    {
        $schemaDirectory = database_path('schema');
        File::ensureDirectoryExists($schemaDirectory);

        $targetPath = $schemaDirectory . '/' . $connection . '-schema.sql';
        $genericPath = $schemaDirectory . '/pgsql-schema.sql';

        if (! File::exists($genericPath)) {
            return;
        }

        $this->sanitizeSchemaFile($genericPath);

        if (is_link($targetPath)) {
            return;
        }

        if (File::exists($targetPath)) {
            File::delete($targetPath);
        }

        if (function_exists('symlink')) {
            @symlink('pgsql-schema.sql', $targetPath);

            return;
        }

        File::copy($genericPath, $targetPath);
    }

    /**
     * Remove PostgreSQL \restrict / \unrestrict metacommands from a schema file.
     */
    public function sanitizeSchemaFile(string $filePath): void
    {
        if (! File::exists($filePath)) {
            return;
        }

        $content = File::get($filePath);
        $sanitized = $this->sanitizeSchemaContent($content);

        if ($content !== $sanitized) {
            File::put($filePath, $sanitized);
        }
    }

    /**
     * Filter out \restrict and \unrestrict metacommands from schema content.
     */
    public function sanitizeSchemaContent(string $content): string
    {
        $lines = explode("\n", $content);
        $filteredLines = array_filter(
            $lines,
            static fn(string $line): bool => ! preg_match('/^\\\\(un)?restrict(\\s|$)/', $line),
        );

        return implode("\n", $filteredLines);
    }
}
