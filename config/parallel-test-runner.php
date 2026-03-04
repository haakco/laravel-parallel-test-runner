<?php

declare(strict_types=1);

use Haakco\ParallelTestRunner\Database\NullDatabaseSeeder;
use Haakco\ParallelTestRunner\Database\SequentialMigrateFreshProvisioner;

return [
    /*
    |--------------------------------------------------------------------------
    | Command Configuration
    |--------------------------------------------------------------------------
    */
    'override_test_command' => true,

    'commands' => [
        'main' => 'test:run-sections',
        'worker' => 'test:run-worker',
    ],

    /*
    |--------------------------------------------------------------------------
    | PHPUnit Configuration Files
    |--------------------------------------------------------------------------
    */
    'phpunit' => [
        'standard' => env('PHPUNIT_CONFIG_STANDARD', 'phpunit.xml'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    */
    'database' => [
        'connection' => env('TEST_RUNNER_DB_CONNECTION', 'pgsql_testing'),
        'base_name' => env('TEST_RUNNER_DB_NAME', env('DB_DATABASE_TEST', env('DB_DATABASE', 'app_test'))),
        'admin_connection' => env('TEST_RUNNER_ADMIN_CONNECTION', 'pgsql'),
        'use_schema_dump' => true,
        'drop_strategy' => 'with_force',
    ],

    /*
    |--------------------------------------------------------------------------
    | Parallel Execution
    |--------------------------------------------------------------------------
    */
    'parallel' => [
        'default_processes' => 1,
        'db_provision_parallel' => 4,
        'keep_parallel_dbs' => false,
        'provisioner' => SequentialMigrateFreshProvisioner::class,
        'seeder' => NullDatabaseSeeder::class,
        'provision_max_retries' => 3,
        'provision_retry_delay_seconds' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Section Discovery
    |--------------------------------------------------------------------------
    */
    'sections' => [
        'scan_paths' => ['tests/Unit', 'tests/Feature', 'tests/Integration'],
        'force_split_directories' => [],
        'max_files_per_section' => 10,
        'resolver' => null,
        'additional_suites' => [],
        'weight_multipliers' => [
            'Integration' => 2.0,
            'Feature' => 1.5,
            'Unit' => 0.8,
            'Stripe' => 3.0,
            'Delivery' => 1.8,
            'Route' => 1.8,
        ],
        'base_weight_per_file' => 10.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment Overrides
    |--------------------------------------------------------------------------
    */
    'environment' => [
        'APP_ENV' => 'testing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Environment Variables
    |--------------------------------------------------------------------------
    */
    'worker_environment' => [
        'set_db_database_test' => false,
        'set_test_token' => false,
        'set_laravel_parallel_testing' => false,
        'set_test_log_dir' => true,
        'set_test_suite' => true,
        'set_test_individual_mode' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Hooks (class-based or closures)
    |--------------------------------------------------------------------------
    */
    'hooks' => [
        'before_run' => [],
        'before_provision' => [],
        'after_provision' => [],
        'before_worker_run' => [],
        'after_worker_run' => [],
        'before_cleanup' => [],
        'after_run' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reporting
    |--------------------------------------------------------------------------
    */
    'reports' => [
        'enabled' => true,
        'performance_path' => null,
        'runtime_baseline_path' => null,
        'skip_audit_path' => null,
        'writers' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Plan DB Naming
    |--------------------------------------------------------------------------
    */
    'db_naming' => [
        'pattern' => '{base}_w{worker}',
        'split_pattern' => '{base}_s{total}g{group}_w{worker}',
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Metrics Storage
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        'weights_file' => storage_path('test-metadata/section-weights.json'),
        'repository' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    */
    'timeouts' => [
        'default' => 600,
        'worker_default' => 240,
        'hanging_test_threshold' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Background Runs
    |--------------------------------------------------------------------------
    */
    'background' => [
        'pid_file' => storage_path('app/test-runner.pid'),
        'lock_file' => storage_path('test-runner.lock'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Extra Command Options
    |--------------------------------------------------------------------------
    */
    'extra_options' => [],
];
