<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\TestingSupport\Performance;

use Closure;
use Illuminate\Support\Facades\DB;

trait DetectsNPlusOneQueries
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function detectNPlusOne(Closure $callback, int $threshold = 50): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $queries = DB::getQueryLog();

        DB::flushQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            $threshold,
            count($queries),
            sprintf('Detected potential N+1 issue: %d queries executed.', count($queries))
        );

        return $queries;
    }

    protected function measureQueryCount(Closure $callback): int
    {
        return count($this->detectNPlusOne($callback, PHP_INT_MAX));
    }
}
