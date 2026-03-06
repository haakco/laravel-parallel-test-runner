<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\TestingSupport\Performance;

use Haakco\ParallelTestRunner\TestingSupport\Performance\DetectsNPlusOneQueries;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

final class DetectsNPlusOneQueriesTest extends TestCase
{
    #[Test]
    public function it_measures_query_count(): void
    {
        $helper = new class ('helper') extends TestCase {
            use DetectsNPlusOneQueries;

            public function countQueries(callable $callback): int
            {
                return $this->measureQueryCount($callback(...));
            }
        };

        $queryCount = $helper->countQueries(static function (): void {
            DB::select('select 1');
            DB::select('select 2');
        });

        $this->assertSame(2, $queryCount);
    }
}
