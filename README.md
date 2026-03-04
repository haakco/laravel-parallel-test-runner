# Parallel Test Runner for Laravel

Section-based parallel test runner for Laravel with weighted scheduling, CI split groups, hook lifecycle, and schema dump provisioning.

## Requirements

- PHP 8.4+
- Laravel 11 or 12
- PostgreSQL (for parallel database provisioning)
- PHPUnit 11 or 12

## Installation

```bash
composer require haakco/laravel-parallel-test-runner --dev
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=parallel-test-runner-config
```

## Quick Start

Run all tests with automatic section discovery:

```bash
php artisan test:run-sections
```

Run tests in parallel with 4 workers:

```bash
php artisan test:run-sections --parallel=4
```

Run each test file individually (recommended for isolation):

```bash
php artisan test:run-sections --individual --parallel=4
```

List discovered test sections:

```bash
php artisan test:run-sections --list
```

## How It Works

The runner scans your test directories and groups test files into **sections** (by directory structure). Each section is assigned a weight based on file count and configurable multipliers (e.g., Feature tests weighted higher than Unit tests). Sections are then distributed across workers using a balanced scheduling algorithm.

### Workflow

1. **Section Discovery** -- Scans configured paths, groups files into sections
2. **Scheduling** -- Distributes sections across workers based on weights
3. **Database Provisioning** -- Creates per-worker databases (parallel mode)
4. **Execution** -- Runs PHPUnit processes per worker
5. **Aggregation** -- Collects results from all workers
6. **Reporting** -- Generates summary and optional performance reports

## Artisan Commands

### `test:run-sections`

The primary command for running tests.

```
php artisan test:run-sections [options] [-- tests...]
```

| Option | Description |
|---|---|
| `--parallel=N` | Number of parallel worker processes (default: 1) |
| `--individual` | Run each test file as a separate PHPUnit invocation |
| `--section=NAME` | Run only specific section(s) (repeatable) |
| `--fail-fast` | Stop on first failure |
| `--timeout=600` | Timeout per section in seconds |
| `--list` | List discovered sections without running |
| `--filter=PATTERN` | PHPUnit filter pattern for test methods |
| `--testsuite=NAME` | PHPUnit test suite to run |
| `--split-total=N` | Split sections into N groups (for CI matrix) |
| `--split-group=N` | Run only group N (1-based, requires `--split-total`) |
| `--refresh-db` | Refresh test database before running |
| `--no-refresh-db` | Skip per-worker database refresh |
| `--keep-parallel-dbs` | Keep parallel databases after the run |
| `--find-hanging` | Detect tests that hang (uses short timeout) |
| `--background` | Run tests in background |
| `--status` | Check status of a background run |
| `--all` | Run all tests including additional suites |
| `--debug` | Enable debug output |
| `--emit-metrics=1` | Write runtime metrics (set to 0 to disable) |
| `--ignore-lock` | Skip migration lock |
| `--skip-env-checks` | Skip environment validation |

### `test:run-worker`

Internal command used by the coordinator to run a worker process. Not typically invoked directly.

```
php artisan test:run-worker --worker-plan-file=path/to/plan.json [options]
```

### `test` (override)

When `override_test_command` is enabled (default), this package replaces Laravel's built-in `test` command. Use `--legacy` to fall back to the standard runner.

```bash
php artisan test                # uses section runner
php artisan test --legacy       # falls back to standard runner
```

## CI Split Groups

Split your test suite across CI matrix jobs:

```yaml
# GitHub Actions example
strategy:
  matrix:
    group: [1, 2, 3]

steps:
  - run: php artisan test:run-sections --split-total=3 --split-group=${{ matrix.group }} --parallel=4
```

## Hook System

The runner provides 7 lifecycle hooks for extending behavior. Register hooks as class names in the configuration file or bind them at runtime.

### Available Hooks

| Hook | Interface | Context | When |
|---|---|---|---|
| `before_run` | `BeforeRunHook` | `RunContext` | After env validation, before section execution |
| `before_provision` | `BeforeProvisionHook` | `ProvisionContext` | Before database provisioning |
| `after_provision` | `AfterProvisionHook` | `ProvisionContext` | After database provisioning completes |
| `before_worker_run` | `BeforeWorkerRunHook` | `WorkerContext` | Before a worker process starts |
| `after_worker_run` | `AfterWorkerRunHook` | `WorkerContext` | After a worker process completes |
| `before_cleanup` | `BeforeCleanupHook` | `CleanupContext` | Before database cleanup |
| `after_run` | `AfterRunHook` | `RunContext` | After all results aggregated |

### Registering Hooks

```php
// config/parallel-test-runner.php
'hooks' => [
    'before_run' => [
        \App\Testing\Hooks\SetupTestEnvironment::class,
    ],
    'after_run' => [
        \App\Testing\Hooks\NotifySlack::class,
    ],
],
```

### Implementing a Hook

```php
<?php

declare(strict_types=1);

namespace App\Testing\Hooks;

use Haakco\ParallelTestRunner\Contracts\Hooks\BeforeRunHook;
use Haakco\ParallelTestRunner\Data\RunContext;

class SetupTestEnvironment implements BeforeRunHook
{
    public function handle(RunContext $context): void
    {
        // Custom setup logic before tests run
    }
}
```

## Customization Interfaces

All major components can be replaced via configuration or container bindings.

| Interface | Default Implementation | Purpose |
|---|---|---|
| `DatabaseProvisionerInterface` | `SequentialMigrateFreshProvisioner` | Database creation strategy |
| `DatabaseSeederInterface` | `NullDatabaseSeeder` | Post-provisioning seeding |
| `SchemaLoaderInterface` | `SchemaDumpLoader` | Schema dump loading |
| `SectionResolverInterface` | `ConfigurableSectionResolver` | Test section discovery |
| `WorkerExecutorInterface` | `SymfonyProcessWorkerExecutor` | Worker process execution |
| `ResultAggregatorInterface` | `JsonFileResultAggregator` | Result collection |
| `TestRunReportWriterInterface` | `MarkdownReportWriter` | Report generation |
| `PerformanceMetricRepositoryInterface` | `JsonFilePerformanceMetricRepository` | Runtime metrics storage |

### Example: Custom Database Provisioner

```php
// config/parallel-test-runner.php
'parallel' => [
    'provisioner' => \App\Testing\CustomProvisioner::class,
],
```

```php
<?php

declare(strict_types=1);

namespace App\Testing;

use Haakco\ParallelTestRunner\Contracts\DatabaseProvisionerInterface;

class CustomProvisioner implements DatabaseProvisionerInterface
{
    // Implement the interface methods
}
```

## Configuration Reference

The configuration file is published to `config/parallel-test-runner.php`.

### Command Configuration

| Key | Default | Description |
|---|---|---|
| `override_test_command` | `true` | Replace Laravel's built-in `test` command |
| `commands.main` | `test:run-sections` | Main command name |
| `commands.worker` | `test:run-worker` | Worker command name |

### PHPUnit

| Key | Default | Description |
|---|---|---|
| `phpunit.standard` | `phpunit.xml` | PHPUnit configuration file path |

### Database

| Key | Default | Description |
|---|---|---|
| `database.connection` | `pgsql_testing` | Database connection name for tests |
| `database.base_name` | `app_test` | Base database name |
| `database.admin_connection` | `pgsql` | Admin connection for DB creation/dropping |
| `database.use_schema_dump` | `true` | Use schema dump for faster provisioning |
| `database.drop_strategy` | `with_force` | Database drop strategy |

### Parallel Execution

| Key | Default | Description |
|---|---|---|
| `parallel.default_processes` | `1` | Default number of parallel workers |
| `parallel.db_provision_parallel` | `4` | Parallel DB provisioning concurrency |
| `parallel.keep_parallel_dbs` | `false` | Keep databases after test run |
| `parallel.provisioner` | `SequentialMigrateFreshProvisioner` | Database provisioner class |
| `parallel.seeder` | `NullDatabaseSeeder` | Database seeder class |
| `parallel.provision_max_retries` | `3` | Max retries for provisioning |
| `parallel.provision_retry_delay_seconds` | `2` | Delay between retries |

### Section Discovery

| Key | Default | Description |
|---|---|---|
| `sections.scan_paths` | `['tests/Unit', 'tests/Feature', 'tests/Integration']` | Directories to scan for tests |
| `sections.force_split_directories` | `[]` | Directories to always split into individual sections |
| `sections.max_files_per_section` | `10` | Max files per section |
| `sections.resolver` | `null` | Custom section resolver class |
| `sections.additional_suites` | `[]` | Extra test suites for `--all` |
| `sections.weight_multipliers` | `{...}` | Weight multipliers by directory name |
| `sections.base_weight_per_file` | `10.0` | Base weight assigned per test file |

### Timeouts

| Key | Default | Description |
|---|---|---|
| `timeouts.default` | `600` | Default timeout per section (seconds) |
| `timeouts.worker_default` | `240` | Default worker timeout (seconds) |
| `timeouts.hanging_test_threshold` | `10` | Threshold for hanging test detection (seconds) |

### Hooks

| Key | Default | Description |
|---|---|---|
| `hooks.before_run` | `[]` | Classes implementing `BeforeRunHook` |
| `hooks.before_provision` | `[]` | Classes implementing `BeforeProvisionHook` |
| `hooks.after_provision` | `[]` | Classes implementing `AfterProvisionHook` |
| `hooks.before_worker_run` | `[]` | Classes implementing `BeforeWorkerRunHook` |
| `hooks.after_worker_run` | `[]` | Classes implementing `AfterWorkerRunHook` |
| `hooks.before_cleanup` | `[]` | Classes implementing `BeforeCleanupHook` |
| `hooks.after_run` | `[]` | Classes implementing `AfterRunHook` |

### Reporting

| Key | Default | Description |
|---|---|---|
| `reports.enabled` | `true` | Enable report generation |
| `reports.performance_path` | `null` | Path for performance reports |
| `reports.runtime_baseline_path` | `null` | Path for runtime baseline |
| `reports.skip_audit_path` | `null` | Path for skip audit report |
| `reports.writers` | `[]` | Additional report writer classes |

### Database Naming

| Key | Default | Description |
|---|---|---|
| `db_naming.pattern` | `{base}_w{worker}` | Database name pattern for parallel workers |
| `db_naming.split_pattern` | `{base}_s{total}g{group}_w{worker}` | Database name pattern for split groups |

### Performance Metrics

| Key | Default | Description |
|---|---|---|
| `metrics.weights_file` | `storage/test-metadata/section-weights.json` | Section weights file path |
| `metrics.repository` | `null` | Custom metrics repository class |

### Worker Environment

| Key | Default | Description |
|---|---|---|
| `worker_environment.set_db_database_test` | `false` | Set `DB_DATABASE_TEST` env var |
| `worker_environment.set_test_token` | `false` | Set `TEST_TOKEN` env var |
| `worker_environment.set_laravel_parallel_testing` | `false` | Set `LARAVEL_PARALLEL_TESTING` env var |
| `worker_environment.set_test_log_dir` | `true` | Set `TEST_LOG_DIR` env var |
| `worker_environment.set_test_suite` | `true` | Set `TEST_SUITE` env var |
| `worker_environment.set_test_individual_mode` | `true` | Set `TEST_INDIVIDUAL_MODE` env var |

## Migration Guide

If you are migrating from CourierBoost's internal test runner (`App\Services\Testing\*`):

1. **Install the package** via Composer and publish the config.

2. **Remove the old classes:**
   - `App\Console\Commands\Test\TestRunnerCommand`
   - `App\Console\Commands\Test\TestRunnerWorkerCommand`
   - `App\Services\Testing\*`
   - `App\Data\Testing\*`

3. **Update any references** from internal namespaces to `Haakco\ParallelTestRunner\*`:
   - `App\Services\Testing\TestRunnerService` becomes `Haakco\ParallelTestRunner\Services\TestRunnerService`
   - Data classes move from `App\Data\Testing\*` to `Haakco\ParallelTestRunner\Data\*`

4. **Move hook implementations** to use the new hook interfaces:
   - `Haakco\ParallelTestRunner\Contracts\Hooks\BeforeRunHook`
   - Register in `config/parallel-test-runner.php` under `hooks`

5. **Update CI scripts** to use the same commands (they are unchanged):
   ```bash
   php artisan test:run-sections --individual --parallel=4
   ```

6. **Review config** -- the configuration keys are the same but now live under the `parallel-test-runner` namespace.

## License

MIT. See [LICENSE](LICENSE) for details.
