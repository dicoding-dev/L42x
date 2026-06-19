# Job 0.1 — Runtime hygiene & PHP 8.3 baseline

- **Effort (for the executing agent):** MEDIUM
- **Depends on:** nothing — this is the prerequisite for every other job
- **Spec refs:** PLAN.md §10 (Phase 0, job 0.1); PLAN.md §11 risk #6; README.md "Verified grounding — Phase 0 runtime drift"
- **Allowed scope (files this job may modify):**
  - `Dockerfile`
  - `composer.lock` (re-resolved by Composer — do not hand-edit)
  - `src/Illuminate/Log/Writer.php` — ONLY if monolog 2.x breaks it and the suite fails; see the HIGH-ATTENTION section below
  - `tests/Log/LogWriterTest.php` — ONLY if Writer changes make the existing test fail and forward-fixing Writer requires a test update; keep tests functionally equivalent

## Objective

Get a green `make composer-test` on PHP 8.3. Three verified drifts must be closed: the Docker base image is still `php:8.1-cli` while composer.json declares `php >=8.3.29`; `composer.lock` pins `monolog/monolog 1.27.1` while composer.json requires `^2.10` (a major-version gap); and `composer.lock`'s `platform` block still reads `php >=8.1.0`. After this job every subsequent job can trust the test gate.

## Context / why

Without this job the Docker image diverges from the declared runtime, CI runs PHP 8.3.29 but `make composer-test` runs 8.1, and the locked monolog is two major versions behind what composer.json declares. Any test result on this mismatched stack is untrustworthy as a gate for Phase 1 changes. The CI workflow (`.github/workflows/pull-request-check.yml`) already targets `8.3.29`; the Docker side must match.

The monolog bump is the only change with real regression risk. Monolog 2.x changed several internals (PSR-3 compliance tightening, `Logger::addRecord()` signature, the `psr/log` requirement bumped to `~1.0|~2.0|~3.0`). `Log\Writer` wraps Monolog via `pushHandler`, `setFormatter`, and magic `callMonolog` delegation. The level constants (`MonologLogger::DEBUG` etc.) still exist in 2.x as plain integers. The most likely breakage is in handler/formatter constructor signatures or in how `psr/log` types changed across the dependency graph. The existing suite (`tests/Log/LogWriterTest.php`) is the arbiter — run it, fix forward if it fails.

## Exact changes

### Step 1 — Confirm the current state (read-only, do before touching anything)

Re-verify the anchors against HEAD before making any edits:

```sh
# Confirm Dockerfile base image line
head -3 Dockerfile
# Expected: FROM php:8.1-cli

# Confirm monolog in lock
grep -A2 '"monolog/monolog"' composer.lock | head -4
# Expected: "version": "1.27.1"

# Confirm lock platform
grep -A3 '"platform"' composer.lock | tail -5
# Expected: "php": ">=8.1.0"

# Confirm composer.json declares ^2.10
grep '"monolog' composer.json
# Expected: "monolog/monolog": "^2.10"
```

### Step 2 — Bump the Dockerfile base image

Edit `Dockerfile` line 2. Change:

```dockerfile
FROM php:8.1-cli
```

to:

```dockerfile
FROM php:8.3-cli
```

Keep the rest of the `RUN` block **exactly as-is**: the `apt-get` install of `libzip-dev libbz2-dev`, and the three `docker-php-ext-install` calls (`pcntl`, `bz2`, `zip`). Do not add or remove any extension at this step.

After editing, the file should open with:

```dockerfile
# syntax=docker/dockerfile:1
FROM php:8.3-cli

RUN apt-get update -y && apt-get install -y --no-install-recommends \
    libzip-dev libbz2-dev && \
    docker-php-ext-install pcntl && \
    docker-php-ext-install bz2 && \
    docker-php-ext-install zip && \
    ...
```

### Step 3 — Rebuild the Docker image

```sh
make docker-build
```

If the build fails due to a missing system library for an extension (e.g. `zip` requires `libzip-dev`, which is already present; `bz2` requires `libbz2-dev`, also present), fix the `apt-get` install list but stay minimal — add only what fails. Do not add `mbstring`, `pdo`, `gd`, or any other extension unless a build failure or a subsequent test failure explicitly requires it. Record any additions in the commit message.

### Step 4 — Re-resolve monolog in composer.lock

Run from inside the container (use `make bash` to get a shell, or pipe via `docker run`):

```sh
# Option A — interactive shell (preferred for iterative work):
make bash
# then inside:
composer update monolog/monolog --with-all-dependencies

# Option B — one-shot:
docker run --rm --volume "${PWD}:/usr/src/myapp" -w /usr/src/myapp l42x \
    composer update monolog/monolog --with-all-dependencies
```

`--with-all-dependencies` allows Composer to also update packages that `monolog/monolog ^2.10` transitively requires (primarily `psr/log`, which moves from `~1.0` to `~2.0|~3.0` in monolog 2.x). Do **not** pass `--no-update` and do **not** run a bare `composer update` — that would upgrade everything. Only `monolog/monolog` and its direct dependency graph.

After the update completes, verify the lock reflects the new version and the corrected platform:

```sh
grep -A2 '"monolog/monolog"' composer.lock | head -4
# Must show: "version": "2.x.y"  (≥2.10.0)

grep -A3 '"platform"' composer.lock | tail -5
# Must show: "php": ">=8.3.29"   (Composer reads this from composer.json's php requirement)
```

The lock's `platform` block mirrors composer.json's `config.platform` (if one is set); a
`composer update monolog/monolog` will **not** rewrite it on its own. If the lock still reads
`php >=8.1.0` and you want it aligned, run:

```sh
composer config platform.php 8.3.29   # edits composer.json's config block — the one permitted composer.json change
```

then re-run the update. This is **best-effort**: the binding gate is the suite passing on the
real 8.3 image (acceptance gate #2), not the exact lock `platform` string — do not get stuck
chasing it.

### Step 5 (HIGH-ATTENTION) — Run the suite; fix Log\Writer if monolog 2.x breaks it

```sh
make composer-test
```

Watch for failures in `tests/Log/`. The most likely breakage patterns under monolog 2.x:

**Pattern A — `psr/log` type changes.** Monolog 2.x requires `psr/log ^2.0|^3.0`. In PSR-3 v2/v3, the `LoggerInterface` methods became strictly typed (`string $message`, not `mixed`). If any handler's constructor or method signature changed, it will surface here. `Log\Writer` calls `pushHandler(new StreamHandler(...))`, `new RotatingFileHandler(...)`, `new ErrorLogHandler(...)` — check whether these handler constructors changed their `$level` parameter type (monolog 2.x uses `int` or `Level` enum, but the integer constants are backward-compatible through 2.x).

**Pattern B — `Logger` constant values or method name changes.** In monolog 2.x `MonologLogger::DEBUG`, `::INFO`, etc. are still plain integers. The `callMonolog` delegation via `call_user_func_array([$this->monolog, $method], $parameters)` calls methods like `error()`, `debug()`, etc. — these methods are unchanged in 2.x.

**Pattern C — `LineFormatter` constructor signature.** `new LineFormatter(null, null, true)` — the third positional argument in 1.x controls `allowInlineLineBreaks`. In monolog 2.x the constructor added a fourth parameter (`ignoreEmptyContextAndExtra`) but kept the third. This call should be safe.

**If tests fail:** read the error output carefully. Fix forward in `src/Illuminate/Log/Writer.php` — adapt to the monolog 2.x API. Do not re-pin monolog back to 1.x in composer.json; that contradicts the declared intent. Only fall back to re-pinning (and flag the decision explicitly in the commit message and in a `## Decision` note appended to this doc) if forward-fixing Writer proves disproportionate (e.g., requires rewriting more than Writer.php and its direct test).

**If tests pass:** no Writer changes are needed. Proceed to Step 6.

### Step 6 — Verify extension presence

Inside the container (`make bash`):

```sh
php -m | grep -E 'pcntl|bz2|zip|mbstring|pdo'
```

`pcntl`, `bz2`, and `zip` must be present (they are explicitly installed). `mbstring` and `pdo` are not installed by the current Dockerfile; confirm no test fails for their absence. If a test does fail because of a missing extension, add only the failing extension's build dep to the `apt-get` line and its `docker-php-ext-install` call, then rebuild.

### Step 7 — Run the full suite

```sh
make composer-test
```

All ~162 test files must pass (same count as before the change). A change in pass count is a regression — investigate before proceeding.

## New tests

This job adds **no new unit tests.** The gate is the existing ~162-file suite staying green. The before/after evidence is:

- **Before:** record the suite result on the old image (run `make composer-test` before building the new image, or note the most recent CI run on `master`). Capture the pass/fail count.
- **After:** capture the `make composer-test` output showing all tests green on the rebuilt 8.3 image with monolog 2.x.

Include both outputs in the commit description.

## Acceptance gate

1. `make docker-build` completes without error using `FROM php:8.3-cli`.
2. `make composer-test` exits 0 — all tests pass.
3. `grep -A2 '"monolog/monolog"' composer.lock` shows version `^2.10`-satisfying (2.10.0 or later).
4. Platform alignment is **best-effort**: the lock's `platform.php` may still read `>=8.1.0`
   unless you ran `composer config platform.php 8.3.29` (Step 4). The binding gate is #2 (suite
   green on the freshly built 8.3 image), not the lock platform string.
5. `php -m | grep pcntl` inside the container returns `pcntl`.
6. No source file under `src/` has been modified **except** `src/Illuminate/Log/Writer.php` — and only if the suite required it (see Step 5).

## Out of scope / do NOT do

- Do **not** upgrade any dependency other than `monolog/monolog` and its transitive deps (`psr/log`, etc.). Do not run bare `composer update`.
- Do **not** upgrade Symfony pins (`~6.4`), PHPUnit (`~9.6`), Rector, Mockery, or any other dep.
- Do **not** refactor `Log\Writer` beyond what is strictly required to make the monolog 2.x API work. No cleanup, no new features, no additional methods.
- Do **not** add PHP extensions that are not required by the suite (no `mbstring`, `pdo`, `gd`, `curl`, etc. unless a test failure demands it).
- Do **not** touch `composer.json`'s `require`/`require-dev` constraints — they are already correct (`php >=8.3.29`, `monolog/monolog ^2.10`). The ONLY permitted `composer.json` edit is `composer config platform.php 8.3.29` to align the lock platform (Step 4), if you choose to.
- Do **not** make any changes to Phase 1 files (`Container.php`, `Application.php`, `Support/Manager.php`, `Queue/QueueManager.php`, etc.).
- Do **not** commit any file under `.github/` — the CI workflow already targets 8.3.29 and needs no change.

## Verification commands

> `make docker-build` tags the image `l42x` (see `Makefile`); the raw `docker run --rm l42x …`
> commands below use that tag.

```sh
# 1. Rebuild the image with the bumped base
make docker-build

# 2. Confirm PHP version in the image
docker run --rm l42x php --version
# Must print: PHP 8.3.x ...

# 3. Confirm extensions
docker run --rm l42x php -m | grep -E 'pcntl|bz2|zip'
# Must print all three

# 4. Install deps and run the full suite
make composer-install
make composer-test
# Must exit 0, all tests green

# 5. Spot-check monolog version in vendor
docker run --rm --volume "${PWD}:/usr/src/myapp" -w /usr/src/myapp l42x \
    php -r "echo (new ReflectionClass(Monolog\Logger::class))->getFileName(), PHP_EOL;"
# Path must be under vendor/monolog/monolog/src/Monolog/Logger.php
docker run --rm --volume "${PWD}:/usr/src/myapp" -w /usr/src/myapp l42x \
    composer show monolog/monolog | grep versions
# Must show 2.x

# 6. Confirm lock platform
grep -A5 '"platform":' composer.lock | tail -5
# Must contain: "php": ">=8.3.29"

# 7. Targeted log test (quick sanity before full run)
docker run --rm --volume "${PWD}:/usr/src/myapp" -w /usr/src/myapp l42x \
    ./vendor/bin/phpunit --colors=always -c phpunit.xml tests/Log/LogWriterTest.php
# Must be green
```

---

**Definition of done:** `make composer-test` exits 0 on a freshly built `php:8.3-cli` image with `monolog/monolog ≥2.10` resolved in `composer.lock`, `pcntl`/`bz2`/`zip` present, and every pre-existing test still passing.
