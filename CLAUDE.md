# Repository Guidelines

## Project Structure & Module Organization

This is a Laravel package for section-based parallel test execution. Production code lives in `src/` under the `Haakco\ParallelTestRunner\` namespace. Key areas include `Commands/`, `Services/`, `Database/`, `Sections/`, `Scheduling/`, `Reporting/`, `Data/`, `Contracts/`, and reusable test traits in `TestingSupport/`.

Configuration and runtime bootstrap files live in `config/parallel-test-runner.php` and `bootstrap/parallel-worker.php`. Tests live in `tests/Unit` and `tests/Feature`; fixture Laravel app tests and schema dumps live under `tests/Fixtures/laravel-app`.

## Build, Test, and Development Commands

- `composer install` — install PHP dependencies.
- `composer test` — run the full PHPUnit suite.
- `composer test:unit` — run only `tests/Unit`.
- `composer test:feature` — run only `tests/Feature`.
- `composer format` — run Laravel Pint and repair style issues.
- `composer format:check` — check formatting without changing files.
- `composer analyse` — run PHPStan/Larastan at the configured level.
- `composer lint:ci` — run CI-safe format, Rector, and static analysis checks.
- `composer check-all` — run coupling checks, CI lint, and the full test suite.

## Coding Style & Naming Conventions

Use strict, readable PHP that follows the existing package patterns. The project uses PSR-4 autoloading, Laravel Pint with the `per` preset, ordered imports, trailing commas in multiline structures, and no unused imports.

Name classes by responsibility: commands end in `Command`, data objects end in `Data`, interfaces end in `Interface`, and tests end in `Test`. Keep package code framework-agnostic where possible and avoid `App\` coupling in `src/`.

## Testing Guidelines

Tests use PHPUnit with Orchestra Testbench. Add or update tests beside the behavior being changed: isolated logic belongs in `tests/Unit`, Laravel command or integration behavior belongs in `tests/Feature`. Test names should describe observable behavior, not implementation details.

Before opening a PR, run the smallest relevant suite first, then `composer check-all` when the change is ready.

## Commit & Pull Request Guidelines

Git history uses concise conventional-style subjects such as `feat: show database provisioning progress`, `ci: refresh workflow action versions`, and `test: stabilize database naming defaults in ci`. Keep commits scoped and use prefixes like `feat:`, `fix:`, `test:`, `ci:`, `docs:`, or `chore:`.

PRs should explain the problem, summarize the solution, list validation commands run, and link related issues. Include screenshots or console output only when they clarify user-facing command behavior.

## Agent-Specific Instructions

Treat the branch as shared. Check current state before editing, keep changes scoped, and never use `git stash` or `git reset`.
