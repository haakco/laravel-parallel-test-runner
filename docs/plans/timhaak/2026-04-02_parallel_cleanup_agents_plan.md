# Parallel Cleanup Agents Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the five known cleanup issues with parallel worker ownership, independent review, and full verification before starting the later deep-review phase.

**Architecture:** Keep the implementation local to the three known production files and their nearest existing tests. Use one worker per production file, require red/green TDD in each slice, then integrate and run a combined verification pass with a separate reviewer.

**Tech Stack:** PHP 8+, Laravel package testing, PHPUnit, Mockery, Composer

---

## Current State (Verified)

**Files examined:**
- `src/Commands/TestRunSectionsCommand.php` - refresh flow and path display formatting live here.
- `src/Sections/ConfigurableSectionResolver.php` - absolute-path resolution and explicit test filtering live here.
- `src/Services/TestRunnerConfigurationService.php` - environment parts and prefix rendering live here.
- `tests/Feature/Commands/TestRunSectionsCommandTest.php` - has a success-path `--refresh-db` test already.
- `tests/Unit/Sections/ConfigurableSectionResolverTest.php` - already exercises discovery and filtering-adjacent behavior.
- `tests/Unit/Services/TestRunnerConfigurationServiceTest.php` - already exercises environment-prefix behavior.

**Key findings:**
- `handleDatabaseRefresh()` logs refresh failure but does not stop execution.
- `str_replace(base_path() . '/', '', ...)` is used for displayed relative paths in `TestRunSectionsCommand`.
- `resolveAbsolutePath()` only recognizes Unix-style absolute paths.
- explicit test filtering compares raw strings after light normalization.
- environment prefix rendering joins raw `KEY=value` strings without escaping.

---

## File Ownership

### Coordinator

**Files:**
- Modify: `docs/plans/timhaak/2026-04-02_parallel_cleanup_agents_plan.md`
- Verify: `tests/Feature/Commands/TestRunSectionsCommandTest.php`
- Verify: `tests/Unit/Sections/ConfigurableSectionResolverTest.php`
- Verify: `tests/Unit/Services/TestRunnerConfigurationServiceTest.php`

- [ ] **Step 1: Dispatch workers with exclusive ownership**

Assign ownership exactly like this:
- Worker 1 owns `src/Commands/TestRunSectionsCommand.php` and `tests/Feature/Commands/TestRunSectionsCommandTest.php`
- Worker 2 owns `src/Sections/ConfigurableSectionResolver.php` and `tests/Unit/Sections/ConfigurableSectionResolverTest.php`
- Worker 3 owns `src/Services/TestRunnerConfigurationService.php` and `tests/Unit/Services/TestRunnerConfigurationServiceTest.php`
- Reviewer owns review only and does not edit production code unless explicitly redirected

- [ ] **Step 2: Require red/green updates from each worker**

Each worker must report:
- the failing test they added first
- the minimal production change they made
- the focused test command they ran
- any open risk or uncertainty

- [ ] **Step 3: Integrate only reviewed work**

Do not declare any task complete until reviewer feedback is addressed. If two workers need the same file after all, stop and reassign before merging edits.

- [ ] **Step 4: Run combined verification**

Run:
```bash
php artisan test tests/Feature/Commands/TestRunSectionsCommandTest.php
php artisan test tests/Unit/Sections/ConfigurableSectionResolverTest.php
php artisan test tests/Unit/Services/TestRunnerConfigurationServiceTest.php
```

Expected:
- all three commands pass
- no worker regression remains

- [ ] **Step 5: Prepare Phase 2 handoff**

Record that the next wave is a deep review only after Phase 1 is green and integrated.

### Task 1: Worker 1 Command Cleanup

**Files:**
- Modify: `src/Commands/TestRunSectionsCommand.php`
- Test: `tests/Feature/Commands/TestRunSectionsCommandTest.php`

- [ ] **Step 1: Write the failing refresh-failure test**

Add a feature test that:
- stubs `refreshTestDatabase()` to return `DatabaseRefreshResultData::failure('boom')`
- asserts `configure()` is never called
- asserts `runConfigured()` is never called
- expects output containing `Failed to refresh database: boom`
- asserts exit code `1`

Target command:
```bash
php artisan test tests/Feature/Commands/TestRunSectionsCommandTest.php --filter=refresh_db_failure
```

Expected before implementation:
- FAIL because the command still continues after the failed refresh

- [ ] **Step 2: Write the failing relative-path formatting test**

Add a focused test around list or result output that demonstrates a path under the project root is rendered relative without depending on a raw `str_replace(base_path() . '/', ...)` assumption.

Use an example that would still be correct if separators or canonical path shape differ.

Target command:
```bash
php artisan test tests/Feature/Commands/TestRunSectionsCommandTest.php --filter=relative_path
```

Expected before implementation:
- FAIL because current formatting logic is fragile

- [ ] **Step 3: Implement the minimal refresh-stop fix**

Update `TestRunSectionsCommand` so refresh failure returns `Command::FAILURE` from the main command flow instead of only printing an error.

Implementation constraints:
- keep the success path unchanged
- keep the progress callback behavior unchanged
- avoid moving unrelated command logic

- [ ] **Step 4: Implement robust relative-path formatting**

Extract a small helper inside `TestRunSectionsCommand` that:
- compares against `base_path()` after normalization
- tolerates `/` and `\` separator differences
- falls back to the original path if it cannot safely relativize

Replace both existing `str_replace(base_path() . '/', '', ...)` usages with that helper.

- [ ] **Step 5: Run focused command tests**

Run:
```bash
php artisan test tests/Feature/Commands/TestRunSectionsCommandTest.php
```

Expected:
- PASS

- [ ] **Step 6: Commit**

```bash
git add src/Commands/TestRunSectionsCommand.php tests/Feature/Commands/TestRunSectionsCommandTest.php
git commit -m "fix: harden section command refresh and path output"
```

### Task 2: Worker 2 Resolver Path Normalization

**Files:**
- Modify: `src/Sections/ConfigurableSectionResolver.php`
- Test: `tests/Unit/Sections/ConfigurableSectionResolverTest.php`

- [ ] **Step 1: Write the failing Windows absolute-path test**

Add a unit test that proves `resolveAbsolutePath()` behavior through `resolve()` by using Windows-style scan paths such as:
- `C:\project\tests\Unit`
- `\\server\share\tests\Unit`

The test should verify the resolver does not incorrectly prepend `base_path()` to those values.

Target command:
```bash
php artisan test tests/Unit/Sections/ConfigurableSectionResolverTest.php --filter=windows_absolute
```

Expected before implementation:
- FAIL because current logic only treats `/...` as absolute

- [ ] **Step 2: Write the failing canonical explicit-test filter test**

Add a unit test that:
- resolves sections from the fixture tests directory
- passes explicit tests using a non-canonical but equivalent path such as `./tests/...` or another normalized equivalent
- asserts the expected section still matches

If symlink coverage is practical in the test environment, include it; if not, stick to deterministic relative-path normalization.

Target command:
```bash
php artisan test tests/Unit/Sections/ConfigurableSectionResolverTest.php --filter=explicit_test
```

Expected before implementation:
- FAIL because matching depends on exact raw path strings

- [ ] **Step 3: Implement path classification and normalization**

Update `ConfigurableSectionResolver` to add small private helpers that:
- detect Unix, drive-letter, and UNC absolute paths
- normalize separators consistently for comparison
- canonicalize with `realpath()` when available, while preserving a safe fallback when a path does not exist yet

Keep the resolver API unchanged.

- [ ] **Step 4: Use canonical comparison for explicit tests**

Update explicit-test filtering so both requested test paths and discovered file paths are normalized through the same helper before comparison.

Behavioral target:
- logically equivalent paths should match
- unrelated paths should still not match

- [ ] **Step 5: Run resolver tests**

Run:
```bash
php artisan test tests/Unit/Sections/ConfigurableSectionResolverTest.php
```

Expected:
- PASS

- [ ] **Step 6: Commit**

```bash
git add src/Sections/ConfigurableSectionResolver.php tests/Unit/Sections/ConfigurableSectionResolverTest.php
git commit -m "fix: normalize resolver paths consistently"
```

### Task 3: Worker 3 Environment Prefix Escaping

**Files:**
- Modify: `src/Services/TestRunnerConfigurationService.php`
- Test: `tests/Unit/Services/TestRunnerConfigurationServiceTest.php`

- [ ] **Step 1: Write the failing environment-prefix escaping test**

Add a unit test that configures environment values containing spaces and shell-special characters, for example:
- `APP_NAME=My App`
- `SPECIAL=value$with!chars`

Assert that `buildEnvironmentPrefix()` returns a string safe to embed in a shell command, with values escaped or quoted predictably.

Target command:
```bash
php artisan test tests/Unit/Services/TestRunnerConfigurationServiceTest.php --filter=environment_prefix
```

Expected before implementation:
- FAIL because the current prefix joins raw values with spaces

- [ ] **Step 2: Preserve the collection-level contract**

Add or update a test to confirm `getProcessEnvironment()` and `buildEnvironmentParts()` still return the same semantic values after the prefix fix.

Target command:
```bash
php artisan test tests/Unit/Services/TestRunnerConfigurationServiceTest.php --filter=environment_parts
```

Expected before implementation:
- either PASS already or FAIL if the prefix fix requires adjusting the test contract

- [ ] **Step 3: Implement shell-safe prefix rendering**

Update `buildEnvironmentPrefix()` so it renders environment assignments safely for shell usage.

Implementation constraints:
- do not change the return type
- keep `buildEnvironmentParts()` useful as a semantic collection of assignments
- prefer a standard escaping strategy such as `escapeshellarg()` on values while preserving `KEY=VALUE` assignment structure

- [ ] **Step 4: Run service tests**

Run:
```bash
php artisan test tests/Unit/Services/TestRunnerConfigurationServiceTest.php
```

Expected:
- PASS

- [ ] **Step 5: Commit**

```bash
git add src/Services/TestRunnerConfigurationService.php tests/Unit/Services/TestRunnerConfigurationServiceTest.php
git commit -m "fix: escape environment prefixes safely"
```

### Task 4: Reviewer Pass

**Files:**
- Review: `src/Commands/TestRunSectionsCommand.php`
- Review: `src/Sections/ConfigurableSectionResolver.php`
- Review: `src/Services/TestRunnerConfigurationService.php`
- Review: `tests/Feature/Commands/TestRunSectionsCommandTest.php`
- Review: `tests/Unit/Sections/ConfigurableSectionResolverTest.php`
- Review: `tests/Unit/Services/TestRunnerConfigurationServiceTest.php`

- [ ] **Step 1: Review Worker 1 output**

Check:
- refresh failure now stops the command
- success path is unchanged
- relative-path helper is simpler and safer than the previous `str_replace`

- [ ] **Step 2: Review Worker 2 output**

Check:
- Windows absolute-path detection is explicit and readable
- explicit test filtering uses one normalization strategy on both sides
- tests prove behavior without depending on platform-specific runtime quirks

- [ ] **Step 3: Review Worker 3 output**

Check:
- env prefix rendering is shell-safe
- environment semantics are preserved
- tests cover at least spaces and shell-special characters

- [ ] **Step 4: Review integrated result**

Check:
- no duplicated path-normalization logic should obviously live elsewhere
- no command-flow regression was introduced
- no new ambiguity remains about Phase 1 being complete

---

## Final Checklist

You may only say Phase 1 is complete if all of these are true:

- [ ] Readable
- [ ] Linted or formatted where required by the package
- [ ] Focused tests pass
- [ ] No known listed cleanup issue remains
- [ ] Reviewer signoff captured
- [ ] Changes match existing package patterns
- [ ] No workaround-only fixes
- [ ] Phase 2 deep review remains separate
