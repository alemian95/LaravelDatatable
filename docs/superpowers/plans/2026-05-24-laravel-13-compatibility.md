# Laravel 13 Compatibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Laravel 13 to the supported framework range of `alemian95/laraveldatatable` without dropping Laravel 11 or 12, and cover Laravel 13 in CI.

**Architecture:** Pure dependency-constraint widening. No source code changes are expected; the package only consumes stable Laravel surfaces (`Illuminate\Http\Request`, `Console\Command`, Eloquent builder). Verification is the existing Pest test suite executed against a Laravel 13 + Testbench 11 install resolved locally and in CI.

**Tech Stack:** PHP 8.4, Composer, Laravel 13 (released Feb 2026, requires PHP ^8.3), `orchestra/testbench ^11.0`, Pest 4, Larastan 3, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-05-24-laravel-13-compatibility-design.md`

---

## File Structure

Files touched in this plan:

| File | Action | Responsibility |
|------|--------|----------------|
| `composer.json` | Modify | Widen `illuminate/contracts` (require) and `orchestra/testbench` (require-dev) version ranges to include L13/Testbench 11. |
| `.github/workflows/run-tests.yml` | Modify | Add a Laravel 13 row to the test matrix, with matching Testbench 11. |
| `docs/superpowers/plans/2026-05-24-laravel-13-compatibility.md` | This plan | — |

No source files in `src/` are modified by this plan. If verification surfaces a real Laravel 13 incompatibility, a follow-up task is added inline at that point (see Task 1, Step 8).

`composer.lock` is intentionally **not** changed: the committed lock continues to resolve against Laravel 12 (current default). Laravel 13 is resolved on the fly in CI via `composer require`.

---

## Task 1: Widen composer constraints and verify against Laravel 13 locally

**Files:**
- Modify: `composer.json` (the `require` and `require-dev` blocks)
- Inspect: `composer.lock` (must remain on Laravel 12 after this task)

- [ ] **Step 1: Snapshot the current lock file**

Run:

```bash
cp composer.lock composer.lock.bak
```

Why: we will install Laravel 13 transiently to validate the constraints; the committed `composer.lock` must remain on Laravel 12 at the end of the task.

- [ ] **Step 2: Confirm the failing state — Laravel 13 cannot be installed with current constraints**

Run:

```bash
composer require "laravel/framework:^13.0" "orchestra/testbench:^11.0" --no-interaction --no-update --dev
composer update --prefer-stable --no-interaction --dry-run
```

Expected: composer reports a conflict on `illuminate/contracts` (current constraint is `^11.0||^12.0`, while `laravel/framework ^13.0` requires `illuminate/contracts ^13.0`).

This is the "failing test" for this task: the current constraints actively block Laravel 13.

Reset the `composer.json` mutation introduced by the failed `composer require` so the next step starts from a clean source-controlled state:

```bash
git checkout -- composer.json
```

- [ ] **Step 3: Widen `illuminate/contracts` constraint**

Edit `composer.json`. Replace:

```json
        "illuminate/contracts": "^11.0||^12.0"
```

with:

```json
        "illuminate/contracts": "^11.0||^12.0||^13.0"
```

- [ ] **Step 4: Widen `orchestra/testbench` dev constraint**

Edit `composer.json`. Replace:

```json
        "orchestra/testbench": "^10.0.0||^9.0.0",
```

with:

```json
        "orchestra/testbench": "^11.0.0||^10.0.0||^9.0.0",
```

- [ ] **Step 5: Verify the default (Laravel 12) install still resolves and tests still pass**

Run:

```bash
composer update --prefer-stable --no-interaction
vendor/bin/pest --ci
vendor/bin/phpstan analyse --no-progress
```

Expected:
- `composer update` succeeds, lock continues to resolve `laravel/framework` on the `^12.x` line (the highest stable Laravel that satisfies *all* dev deps; since L13 isn't required by anything in the default install, the resolver may still pick 12 or jump to 13 — either is acceptable for this step, the goal is that resolution succeeds).
- `vendor/bin/pest --ci` exits 0.
- `vendor/bin/phpstan analyse` exits 0 with no new errors.

- [ ] **Step 6: Force Laravel 13 + Testbench 11 and re-run the full check**

Run:

```bash
composer require "laravel/framework:^13.0" "orchestra/testbench:^11.0" --no-interaction --no-update --dev
composer update --prefer-stable --no-interaction
composer show laravel/framework orchestra/testbench
vendor/bin/pest --ci
vendor/bin/phpstan analyse --no-progress
```

Expected:
- `composer show` reports `laravel/framework` on `13.x` and `orchestra/testbench` on `11.x`.
- `vendor/bin/pest --ci` exits 0.
- `vendor/bin/phpstan analyse` exits 0.

- [ ] **Step 7: Repeat the L13 check with `--prefer-lowest` to mirror the CI matrix**

Run:

```bash
composer update --prefer-lowest --prefer-stable --no-interaction
composer show laravel/framework orchestra/testbench
vendor/bin/pest --ci
```

Expected:
- `composer show` reports `laravel/framework 13.0.x` and `orchestra/testbench 11.0.x`.
- `vendor/bin/pest --ci` exits 0.

If this step fails because a dev dep (e.g. `larastan/larastan`, `pestphp/pest-plugin-laravel`, `nunomaduro/collision`) has no stable release that resolves with Laravel 13, proceed to Step 8. Otherwise jump to Step 9.

- [ ] **Step 8: (Conditional) Bump conflicting dev deps**

Only run this step if Step 6 or Step 7 failed on a dependency conflict. For each conflicting package:

1. Identify the lowest stable version that supports Laravel 13:

   ```bash
   composer why-not laravel/framework ^13.0
   composer show <conflicting-package> --all | head -40
   ```

2. Edit `composer.json` and widen that package's constraint with the same `||` pattern used for `orchestra/testbench` — keep the older majors so L11/L12 builds still resolve.

3. Re-run Step 6 and Step 7 until both pass.

Stop and ask the user before committing if the bump requires dropping an older major (i.e. the `||` strategy is not possible). Do **not** silently drop framework support.

- [ ] **Step 9: Restore the committed lock**

Run:

```bash
mv composer.lock.bak composer.lock
composer install --no-interaction
vendor/bin/pest --ci
```

Expected:
- The restored lock installs cleanly.
- `vendor/bin/pest --ci` exits 0 — proves the L12-locked baseline is still green after the constraint widening.

- [ ] **Step 10: Inspect the diff before committing**

Run:

```bash
git diff composer.json
git status
```

Expected diff shape — only the two version-range lines change inside `composer.json` (plus any conditional dev-dep bumps from Step 8). `composer.lock` must show no changes. No files outside `composer.json` should be modified.

- [ ] **Step 11: Commit**

```bash
git add composer.json
git commit -m "$(cat <<'EOF'
chore: extend dependency range to Laravel 13

Widens illuminate/contracts and orchestra/testbench constraints so the
package installs on Laravel 13 / Testbench 11 while keeping L11 and L12
support intact.
EOF
)"
```

---

## Task 2: Add Laravel 13 to the CI test matrix

**Files:**
- Modify: `.github/workflows/run-tests.yml` (the `matrix:` block, around lines 25-40)

- [ ] **Step 1: Read the current matrix to confirm the exact shape**

Read `.github/workflows/run-tests.yml` and locate the `matrix:` block. The current shape is:

```yaml
    strategy:
      fail-fast: true
      matrix:
        os: [ubuntu-latest, windows-latest]
        php: [8.4]
        laravel: [12.*, 11.*]
        stability: [prefer-lowest, prefer-stable]
        include:
          - laravel: 12.*
            testbench: 10.*
          - laravel: 11.*
            testbench: 9.*
```

- [ ] **Step 2: Add Laravel 13 to the matrix**

Edit `.github/workflows/run-tests.yml`. Replace the block above with:

```yaml
    strategy:
      fail-fast: true
      matrix:
        os: [ubuntu-latest, windows-latest]
        php: [8.4]
        laravel: [13.*, 12.*, 11.*]
        stability: [prefer-lowest, prefer-stable]
        include:
          - laravel: 13.*
            testbench: 11.*
          - laravel: 12.*
            testbench: 10.*
          - laravel: 11.*
            testbench: 9.*
```

The new row is `laravel: 13.*` paired with `testbench: 11.*`. The rest of the file (steps, concurrency, triggers) stays untouched.

- [ ] **Step 3: Validate the YAML is well-formed**

Run:

```bash
python3 -c "import yaml; yaml.safe_load(open('.github/workflows/run-tests.yml'))" && echo "YAML OK"
```

Expected output: `YAML OK` (no traceback).

- [ ] **Step 4: Confirm the diff is minimal**

Run:

```bash
git diff .github/workflows/run-tests.yml
```

Expected: only the `laravel:` array gains `13.*` and the `include:` block gains the `13.* / 11.*` pair. No other lines change.

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/run-tests.yml
git commit -m "$(cat <<'EOF'
ci: add Laravel 13 to the run-tests matrix

Pairs laravel 13.* with orchestra/testbench 11.* in the existing
prefer-lowest / prefer-stable cross-matrix.
EOF
)"
```

---

## Task 3: Final integration check

**Files:** none modified — this task is verification only.

- [ ] **Step 1: Confirm clean working tree**

Run:

```bash
git status
```

Expected: working tree clean, branch ahead of `origin/main` by exactly the two commits from Task 1 and Task 2 (plus the spec commit from brainstorming, if not yet pushed).

- [ ] **Step 2: Confirm the lock did not drift**

Run:

```bash
git diff main -- composer.lock
```

Expected: empty output. `composer.lock` must be unchanged.

- [ ] **Step 3: Re-run the default install + tests one last time**

Run:

```bash
composer install --no-interaction
vendor/bin/pest --ci
vendor/bin/phpstan analyse --no-progress
```

Expected: all green against the committed lock (Laravel 12).

- [ ] **Step 4: Re-run the Laravel 13 scenario one last time**

Run:

```bash
cp composer.lock composer.lock.bak
composer require "laravel/framework:^13.0" "orchestra/testbench:^11.0" --no-interaction --no-update --dev
composer update --prefer-stable --no-interaction
vendor/bin/pest --ci
mv composer.lock.bak composer.lock
composer install --no-interaction
```

Expected: tests green against Laravel 13; lock restored cleanly afterward.

- [ ] **Step 5: Push and open the PR (only if user has asked for it)**

This step is **not** automatic. Surface the branch state to the user and ask whether to push and open a PR. The default exit state of this plan is: two local commits on `main` (or a feature branch, if the executor created one), tests green in both L12 and L13 scenarios, nothing pushed.

---

## Definition of Done

- `composer.json` advertises `illuminate/contracts ^11.0||^12.0||^13.0` and `orchestra/testbench ^11.0.0||^10.0.0||^9.0.0` (plus any conditional dev-dep widening from Task 1 Step 8).
- `.github/workflows/run-tests.yml` matrix includes a `laravel: 13.*` row paired with `testbench: 11.*`.
- `vendor/bin/pest --ci` and `vendor/bin/phpstan analyse` pass against both Laravel 12 (committed lock) and Laravel 13 (transient install) locally.
- `composer.lock` is unchanged from the pre-task state.
- No source files in `src/` are modified (unless Task 1 Step 8 explicitly required it, in which case the change is the minimum needed and called out in its own commit).
