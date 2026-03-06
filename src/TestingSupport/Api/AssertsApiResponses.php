<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\TestingSupport\Api;

use Illuminate\Testing\TestResponse;

trait AssertsApiResponses
{
    protected function assertPaginatedResponse(TestResponse $response): void
    {
        $response->assertJsonStructure([
            'data',
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => $this->paginationMetaKeys(),
        ]);
    }

    protected function assertStandardApiResponse(TestResponse $response, array $expectedDataStructure = []): void
    {
        $structure = ['data'];

        if ($expectedDataStructure !== []) {
            $structure['data'] = $expectedDataStructure;
        }

        $response->assertJsonStructure($structure);
    }

    protected function assertValidationErrorResponse(TestResponse $response, array $expectedErrors): void
    {
        $response->assertStatus(422);
        $response->assertJsonValidationErrors($expectedErrors);
    }

    protected function assertSuccessResponse(TestResponse $response, int $status = 200): void
    {
        $this->assertContains(
            $response->status(),
            [$status, 200, 201, 204],
            'Response status code should be either ' . $status . ', 201, or 204. Got: ' . $response->status()
        );
    }

    protected function assertCreatedResponse(TestResponse $response): void
    {
        $response->assertStatus(201);
    }

    protected function assertRedirectResponse(TestResponse $response, string $redirectTo): void
    {
        $response->assertStatus(302)
            ->assertRedirect($redirectTo);
    }

    /**
     * @return array<int, string>
     */
    protected function paginationMetaKeys(): array
    {
        return [
            'current_page',
            'from',
            'last_page',
            'links',
            'path',
            'per_page',
            'to',
            'total',
        ];
    }
}
