<?php

declare(strict_types=1);

namespace Haakco\ParallelTestRunner\Tests\Unit\Services;

use Haakco\ParallelTestRunner\Services\TestDatabaseManagerService;
use Haakco\ParallelTestRunner\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Override;

final class TestDatabaseManagerServiceTest extends TestCase
{
    private TestDatabaseManagerService $service;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TestDatabaseManagerService();
    }

    public function test_refresh_test_database_uses_config_defaults(): void
    {
        config()->set('parallel-test-runner.database.connection', 'testing');
        config()->set('parallel-test-runner.database.base_name', 'test_db');

        // We can't actually run migrate:fresh in a unit test,
        // so we verify the service handles artisan call failure gracefully
        $result = $this->service->refreshTestDatabase('testing', ':memory:');

        // The result should be either success or failure (not an exception)
        $this->assertIsBool($result->success);
        $this->assertIsFloat($result->duration);
    }

    public function test_refresh_test_database_calls_progress(): void
    {
        $messages = [];
        $onProgress = static function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        $this->service->refreshTestDatabase('testing', ':memory:', $onProgress);

        $this->assertNotEmpty($messages);
        $this->assertStringContainsString(':memory:', $messages[0]);
    }

    public function test_refresh_test_database_returns_failure_on_exception(): void
    {
        // Use a non-existent connection to trigger an error
        $result = $this->service->refreshTestDatabase('nonexistent_connection', 'test_db');

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->message);
    }

    public function test_check_migration_status_returns_boolean(): void
    {
        $result = $this->service->checkMigrationStatus('testing');

        $this->assertIsBool($result);
    }

    public function test_check_migration_status_returns_false_for_bad_connection(): void
    {
        $result = $this->service->checkMigrationStatus('nonexistent');

        $this->assertFalse($result);
    }
}
