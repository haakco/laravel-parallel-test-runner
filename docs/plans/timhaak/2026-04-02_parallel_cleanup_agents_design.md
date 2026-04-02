# Parallel Cleanup Agents Design

**Goal:** Fix the five known cleanup issues in parallel with clear agent ownership, mandatory independent review, and a separate follow-up deep review pass.

**Background:** The package already has focused command, resolver, and configuration tests. The requested work is a bounded cleanup effort across three production files, followed by a deeper audit after the known issues are resolved.

**Architecture:** Use a coordinator plus three workers plus one reviewer. Each worker owns one code slice and its nearest existing tests. The coordinator integrates only after reviewer feedback is addressed and verification passes.

**Tech Stack:** PHP 8+, Laravel package testing, PHPUnit, Mockery, Composer scripts.

---

## Current State (Verified)

**Files examined:**
- `src/Commands/TestRunSectionsCommand.php` - runs the top-level CLI flow, triggers `--refresh-db`, lists sections, and formats displayed paths.
- `src/Sections/ConfigurableSectionResolver.php` - discovers sections from scan paths, resolves paths, and filters by explicitly requested tests.
- `src/Services/TestRunnerConfigurationService.php` - builds runtime config, environment values, PHPUnit commands, and environment prefix strings.
- `tests/Feature/Commands/TestRunSectionsCommandTest.php` - covers refresh-db success and command orchestration behavior.
- `tests/Unit/Sections/ConfigurableSectionResolverTest.php` - covers section discovery, splitting, caching, and abstract-test filtering.
- `tests/Unit/Services/TestRunnerConfigurationServiceTest.php` - covers configuration defaults, environment handling, and command building.

**Key findings:**
- `TestRunSectionsCommand::handle()` calls `handleDatabaseRefresh()` before configuring and running tests, but refresh failure only emits an error message and does not stop the command.
- `TestRunSectionsCommand` derives displayed relative paths with `str_replace(base_path() . '/', '', ...)`, which assumes Unix separators and matching canonical path shapes.
- `ConfigurableSectionResolver::resolveAbsolutePath()` treats only `/...` paths as absolute, so drive-letter and UNC-style Windows paths are not recognized safely.
- `ConfigurableSectionResolver::filterByExplicitTests()` normalizes requested tests only with `resolveAbsolutePath()`, then compares raw strings, so `./tests/...`, symlinked paths, and other equivalent forms can miss.
- `TestRunnerConfigurationService::buildEnvironmentPrefix()` joins raw `KEY=value` fragments with spaces, which is brittle when values contain spaces or shell-special characters.
- Existing tests cover the surrounding behavior well enough to add focused regression tests without introducing new test infrastructure.

**Constraints that affect implementation:**
- Match existing package patterns and keep changes local to the three known hotspots.
- Follow red/green TDD for every behavior change.
- Keep Phase 1 limited to the five known issues; the deep review belongs to Phase 2.

---

## Proposed Execution Model

### Team Structure

- **Coordinator:** owns the plan, dispatches work, integrates changes, verifies cross-file cohesion, and decides when Phase 1 is complete.
- **Worker 1:** owns `src/Commands/TestRunSectionsCommand.php` and `tests/Feature/Commands/TestRunSectionsCommandTest.php`.
- **Worker 2:** owns `src/Sections/ConfigurableSectionResolver.php` and `tests/Unit/Sections/ConfigurableSectionResolverTest.php`.
- **Worker 3:** owns `src/Services/TestRunnerConfigurationService.php` and `tests/Unit/Services/TestRunnerConfigurationServiceTest.php`.
- **Reviewer:** independently reviews every worker result before integration. No task is complete without reviewer signoff.

### Work Split

**Worker 1 responsibilities**
- Make refresh-db failure terminate the command cleanly instead of logging and continuing.
- Replace fragile displayed-path formatting with a path-relativizing helper that handles separator differences and canonical paths more safely.
- Add feature tests for refresh failure and for robust path display behavior where feasible.

**Worker 2 responsibilities**
- Harden absolute-path detection for Windows drive-letter and UNC paths in addition to Unix absolute paths.
- Canonicalize explicit test filters so equivalent paths match discovered sections consistently.
- Add unit tests covering Windows-style absolute paths, relative paths like `./...`, and canonical-path matching.

**Worker 3 responsibilities**
- Make environment prefix generation shell-safe for values containing spaces or shell-special characters.
- Keep environment collection behavior unchanged where possible, limiting the fix to prefix rendering.
- Add unit tests covering quoting/escaping of representative environment values.

### Review Flow

1. Each worker writes a failing test first in its owned test file.
2. Each worker implements the minimal production change to satisfy that test.
3. Each worker runs the narrowest relevant tests and reports results.
4. Reviewer checks correctness, maintainability, edge cases, and pattern fit.
5. Coordinator integrates all approved work, runs the combined verification set, and checks that no changes overlap incorrectly.

---

## Phase Breakdown

### Phase 1: Known Cleanup Items

**Success criteria**
- All five listed issues are covered by regression tests.
- Production code changes remain isolated to the three known files unless a small helper extraction is clearly warranted.
- Reviewer signs off on each slice and the integrated result.
- Relevant tests pass in the final integrated state.

### Phase 2: Deep Review

After Phase 1 is complete, run a separate review-focused wave that:
- examines the broader package for correctness, portability, error handling, and test gaps
- records findings before implementation
- converts any accepted findings into a new scoped implementation plan

Phase 2 is intentionally not mixed into the first execution wave.

---

## Risks And Mitigations

**Risk:** Workers make overlapping edits in shared files.
**Mitigation:** Use file ownership boundaries and keep Worker 1 responsible for both command-side issues in `TestRunSectionsCommand.php`.

**Risk:** Windows-path fixes become speculative on a Unix machine.
**Mitigation:** Prefer deterministic string/path tests that verify classification and normalization logic without requiring a Windows runtime.

**Risk:** Shell-safe env prefix behavior depends on how downstream code executes commands.
**Mitigation:** Limit the contract to producing a safely escaped prefix string and cover representative special-character cases in unit tests.

**Risk:** Coordinator merges passing slices that still conflict semantically.
**Mitigation:** Run a final combined verification pass and do a cohesion review before declaring Phase 1 complete.

---

## Verification Strategy

- Narrow tests during worker development:
  - `php artisan test tests/Feature/Commands/TestRunSectionsCommandTest.php`
  - `php artisan test tests/Unit/Sections/ConfigurableSectionResolverTest.php`
  - `php artisan test tests/Unit/Services/TestRunnerConfigurationServiceTest.php`
- Combined verification after integration:
  - the three focused test files above
  - any package-level quality checks already used for this library if they remain green and are practical to run in-session

---

## Deliverables

- A detailed implementation plan in `docs/plans/timhaak/` for the Phase 1 cleanup wave
- Multi-agent execution of that plan with one dedicated reviewer
- A separate deep-review plan after Phase 1 is verified
