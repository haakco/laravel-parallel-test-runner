<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Contracts;

interface SchemaLoaderInterface
{
    /**
     * Load schema dump into the specified database.
     */
    public function loadSchema(string $connection, string $databaseName): void;
}
