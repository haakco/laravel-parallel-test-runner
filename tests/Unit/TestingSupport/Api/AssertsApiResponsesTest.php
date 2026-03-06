<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\TestingSupport\Api;

use Haakco\ParallelTestRunner\TestingSupport\Api\AssertsApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AssertsApiResponsesTest extends TestCase
{
    #[Test]
    public function it_accepts_default_snake_case_pagination_meta(): void
    {
        $helper = new class ('helper') extends TestCase {
            use AssertsApiResponses;

            public function assertPaginated(TestResponse $response): void
            {
                $this->assertPaginatedResponse($response);
            }
        };

        $helper->assertPaginated($this->makePaginatedResponse([
            'current_page' => 1,
            'from' => 1,
            'last_page' => 5,
            'links' => [],
            'path' => '/api/test',
            'per_page' => 15,
            'to' => 15,
            'total' => 75,
        ]));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_allows_apps_to_override_pagination_meta_keys(): void
    {
        $helper = new class ('helper') extends TestCase {
            use AssertsApiResponses;

            public function assertPaginated(TestResponse $response): void
            {
                $this->assertPaginatedResponse($response);
            }

            protected function paginationMetaKeys(): array
            {
                return ['currentPage', 'from', 'lastPage', 'links', 'path', 'perPage', 'to', 'total'];
            }
        };

        $helper->assertPaginated($this->makePaginatedResponse([
            'currentPage' => 1,
            'from' => 1,
            'lastPage' => 5,
            'links' => [],
            'path' => '/api/test',
            'perPage' => 15,
            'to' => 15,
            'total' => 75,
        ]));

        $this->addToAssertionCount(1);
    }

    private function makePaginatedResponse(array $meta): TestResponse
    {
        return TestResponse::fromBaseResponse(new JsonResponse([
            'data' => [],
            'links' => [
                'first' => 'http://example.com?page=1',
                'last' => 'http://example.com?page=5',
                'prev' => null,
                'next' => 'http://example.com?page=2',
            ],
            'meta' => $meta,
        ]));
    }
}
