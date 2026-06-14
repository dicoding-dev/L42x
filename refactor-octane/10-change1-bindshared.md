# Job 1.1 — Change #1: make `bindShared` cache via `$instances`, not the `share()` closure-static

- **Effort (for the executing agent):** **HIGH**
- **Depends on:** Job 0.1 (`00-phase0-runtime-hygiene.md`) — a green full-suite baseline on PHP 8.3 is a hard prerequisite; you cannot trust this job's acceptance gate without it. No other Phase-1 job may precede this one.
- **Sequence:** **FIRST among all code changes** (refactor spec §13.1). Fail fast: if the full suite is not green after this edit, stop and do not proceed to Jobs 1.2/1.3/1.4.
- **Spec refs:** refactor spec **§5** (authoritative for the code), §3 (leak table — the `share()` static row), §4 (change table row 1), §12 tests 1–2, §13.1 (sequencing). README "Verified grounding → Phase 1 anchors".
- **Allowed scope (the ONLY files this job may modify):**
  - `src/Illuminate/Container/Container.php` — **one method body only**: `bindShared()`.
  - A test file under `tests/Container/` (new file `tests/Container/ContainerBindSharedTest.php` — see "New tests").
  - **Nothing else.** No other `src/` file, no other doc, no git config, no composer files.

---

## Objective

Change `Container::bindShared()` so it binds the caller's closure **directly as shared** (`$this->bind($abstract, $closure, true)`) instead of wrapping it in the memoizing closure returned by `Container::share()`. After this change, shared-service singleton-ness is provided **solely** by the container's per-instance `$instances` cache (already populated by `make()`), not by a process-shared closure static. "Done" = the edit is in, the full existing PHPUnit suite is still green (byte-for-byte 4.2 behavior preserved for a single container), and two new tests (singleton identity + clone isolation) pass.

This is the **foundational** change for the clone-per-request sandbox: every later job (`__clone`, manager re-pointing, the worker swap protocol) assumes that `clone $app` yields a container whose shared services re-resolve into the clone's own `$instances` and die with the clone. The `share()` static defeats that. This job removes it from the `bindShared` path **without** touching `share()` itself.

## Context / why

### The leak being closed

`Container::share()` (`Container.php:384-400`, verified) returns a wrapper closure that memoizes in a `static $object`:

```php
public function share(Closure $closure): Closure
{
    return function($container) use ($closure)
    {
        // We'll simply declare a static variable within the Closures and if it has
        // not been set we will execute the given Closures to resolve this value
        // and return it back to these consumers of the method as an instance.
        static $object;

        if (is_null($object))
        {
            $object = $closure($container);
        }

        return $object;
    };
}
```

`bindShared()` (`Container.php:409-412`, verified) binds **that wrapper** as the concrete with `shared = true`:

```php
public function bindShared($abstract, Closure $closure): void
{
    $this->bind($abstract, $this->share($closure), true);
}
```

The wrapper closure is stored in `$bindings[$abstract]['concrete']` and is **shared by reference** across `clone $app` (a shallow clone copies the `$bindings` array by value, but its closure *elements* are the same closure objects, and the `static $object` lives on the closure, not the array). Consequence for the sandbox model: a `bindShared` service **first resolved inside a sandbox** caches *that sandbox's* instance in the shared closure's static, so every later sandbox — and the base app — gets that one pinned instance back forever. This is the **core correctness bug** the clone-sandbox model must eliminate, and it's also why `forgetInstance()` cannot reset such a service: re-`make()` re-enters the same wrapper whose static already holds the stale object (`forgetInstance()` at `Container.php:1407-1410` only does `unset($this->instances[$abstract])` — it cannot reach into the closure static). See refactor spec §3, "`share()` static cache" row.

### Why dropping the wrapper is behavior-preserving for a single container

`make()` (verified at `Container.php:773-816`) already provides singleton semantics through `$instances`, on the container, keyed by abstract:

- **Instance hit short-circuit** (`:776-778`):
  ```php
  if (isset($this->instances[$abstract]) && ! $needsContextualBuild) {
      return $this->instances[$abstract];
  }
  ```
- **Shared-cache write after build** (`:803-804`, the load-bearing anchor):
  ```php
  if ($this->isShared($abstract) && ! $needsContextualBuild) {
      $this->instances[$abstract] = $object;
  }
  ```
- `isShared()` (`:1347-1352`) returns true when `$bindings[$abstract]['shared'] === true` — which `bind(..., true)` sets — so a directly-bound shared closure **is** cached in `$instances` after its first `make()`.
- `build()` (`:927-934`) executes a `Closure` concrete as `$concrete($this, $parameters)` (`:932-933`), passing the **current** container — so a closure resolved on a clone builds against the clone.

So for a **single** container the observable behavior is identical:
1. First `make($abstract)` → not in `$instances` → `build()` runs the closure once → result cached in `$instances[$abstract]`.
2. Every subsequent `make($abstract)` → returned from `$instances` → **same object** (`===`).

The `share()` static was a **redundant second cache** that merely seeded `$instances` the first time. Removing it changes nothing for one container, and for a **clone** it is strictly correct: the clone has its own `$instances` array (copied by value at clone time), so a service forgotten on, or first-resolved in, the clone re-runs the closure **against the clone** and caches **on the clone** — isolated, and discarded with the clone. The leak is gone by construction.

### Why this is "correct-by-construction" and the rejected alternative

The spec (§5) records a **REJECTED** alternative: keep `share()` as-is and instead **warm every shared service at boot** so each static seeds with the base instance, relying solely on setter re-pointing (§3). That leaves a **narrow residual leak** — any service *first* resolved inside a sandbox still pins itself in the shared static. **Do NOT implement the boot-warming alternative.** Change #1 removes the leak at its root and is the recommended option. Out of scope for this job entirely.

## Exact changes

### Step 1 (BLOCKING SUB-STEP) — establish the green baseline

Confirm Job 0.1 has landed and run the **full** suite first, recording the result. You are about to make the **only** internal edit in Phase 1 that can regress 4.2 behavior; you must know the suite was green *before* you touched anything.

```sh
make composer-test
# or, if PHP 8.3 is available locally:
vendor/bin/phpunit -c phpunit.xml
```

If this is not green, **stop** — fix the baseline (Job 0.1) first. Do not edit `bindShared` against a red baseline.

### Step 2 (BLOCKING SUB-STEP) — the `->share(` call-site audit

Before editing, grep the **entire** `src/` tree for every `->share(` call site and classify each as **APP-SCOPED** (one shared instance across sandboxes is correct → leave as `->share(`) or **REQUEST-SCOPED** (would bleed across requests → must be converted to `bindShared`/`singleton`). This is a **blocking** sub-step: any request-scoped `->share(` site you find is a latent cross-request leak and must be converted **in this job** before you proceed. (Converting a genuinely request-scoped site is in-scope here because it is the same class of bug Change #1 fixes; if you find one, note it loudly in the commit body.)

Run:

```sh
grep -rn '\->share(' src/ --include='*.php'
```

**Critical disambiguation — two unrelated `share()` methods exist.** Not every `->share(` is a *container* call:
- **`Container::share(Closure)`** (`Container.php:384`) — the one this job concerns: wraps a closure for shared binding.
- **`View\Factory::share($key, $value)`** (`View/Factory.php:288`, verified present) — shares **view data** with all views; totally unrelated. Call sites: `View/Factory.php:110` (`$this->share('__env', $this)`), `View/Factory.php:294` (`$this->share($innerKey, $innerValue)`), `ViewServiceProvider.php:129` (`$env->share('app', $app)`), `ViewServiceProvider.php:153` / `:161` (`$app['view']->share('errors', ...)`). **These are NOT container calls — exclude them from this audit entirely.**
- **Untracked `vendor/` copies** (`src/Illuminate/Queue/vendor/...`, `src/Illuminate/Cookie/vendor/...`) are vendored snapshots, untracked in git, and **out of scope** — do not touch them. (`Queue/vendor/.../Container.php:230` and its `EventServiceProvider.php:14` are vendor duplicates; ignore.)

The verdict table below was produced from a verified read of this repo on branch `improvements/octane-sandbox-enablement`. **Re-confirm it at edit time** (line numbers may drift; the classification will not). Every genuine container `->share(` site is **APP-SCOPED** — *no conversions are required by this job* — but you must reproduce and verify this table, not assume it:

| File:line | Bound abstract | Scope verdict | Rationale | Action |
|---|---|---|---|---|
| `Routing/RoutingServiceProvider.php:28` | `router` | **APP-SCOPED** | Boot-time singleton holding all registered routes; cannot be forgotten without losing routes. Re-pointed per request via `Router::setContainer()` (Job 1.3 / Change #4), **not** re-shared. | Leave `->share(` |
| `Routing/RoutingServiceProvider.php:51` | `url` | **APP-SCOPED** | `UrlGenerator` self-heals its request via the `rebinding('request', fn → $app['url']->setRequest($request))` registered right here at `:58-61`. Worker rebinds `request` per request → callback fires → `url` re-points. | Leave `->share(` |
| `Routing/RoutingServiceProvider.php:72` | `redirect` | **APP-SCOPED** | `Redirector` reads the request **through** the shared `url` generator (which self-heals, above); session is set at build. No request-scoped field pinned. | Leave `->share(` |
| `Events/EventServiceProvider.php:14` | `events` | **APP-SCOPED** | Shared `Dispatcher`. Per spec §11, request-scoped `Event::listen()` is **unsupported** by design (listeners register at boot); the shared dispatcher is intentional. | Leave `->share(` |
| `Exception/ExceptionServiceProvider.php:41` | `exception` | **APP-SCOPED** | `Handler` holds `$app` + two displayers; stateless per request (renders via `Response::send()`). | Leave `->share(` |
| `Exception/ExceptionServiceProvider.php:54` | `exception.plain` | **APP-SCOPED** | `PlainDisplayer` — stateless. | Leave `->share(` |
| `Exception/ExceptionServiceProvider.php:79` | `exception.debug` | **APP-SCOPED** | `WhoopsDisplayer` — stateless. | Leave `->share(` |
| `Exception/ExceptionServiceProvider.php:94` | `whoops` | **APP-SCOPED** | `Whoops\Run` configured once (`allowQuit(false)`); no per-request state retained across requests. | Leave `->share(` |
| `Exception/ExceptionServiceProvider.php:116` | `whoops.handler` | **APP-SCOPED** | Whoops handler — stateless. | Leave `->share(` |
| `Exception/ExceptionServiceProvider.php:154` | `whoops.handler` | **APP-SCOPED** | Alternate handler branch — stateless. | Leave `->share(` |
| `Mail/MailServiceProvider.php:123` | `symfony.transport` (SMTP) | **APP-SCOPED** | Transport built from static `mail` config; reused across requests is correct. The **mailer** is re-pointed per request via existing `Mailer::setContainer()` (`:463`) / `setQueue()` (`:450`) per spec §7 — the transport itself need not be per-request. | Leave `->share(` |
| `Mail/MailServiceProvider.php:169` | `symfony.transport` (sendmail) | **APP-SCOPED** | Built from config; same rationale. | Leave `->share(` |
| `Mail/MailServiceProvider.php:182` | `symfony.transport` (mail) | **APP-SCOPED** | Built from config; same rationale. | Leave `->share(` |

**Expected result of the audit: zero conversions.** The two app-scoped families the spec calls out explicitly — `url` (self-heals via request rebinding) and the mail transports — are both confirmed app-scoped above. If your re-run surfaces a `->share(` site **not** in this table (e.g. introduced by a later commit), classify it and convert it if request-scoped before continuing. **Record the audit (the grep output + your verdict per new/changed site) in the commit body** so the gate is auditable.

### Step 3 — the one-line edit

In `src/Illuminate/Container/Container.php`, change **only** the body of `bindShared()`. Verbatim from spec §5 (note: keep the existing `: void` return type and signature exactly as they are in this fork — the spec's snippet omits it, but **do not** drop the return type):

**Before** (`Container.php:409-412`, verified):
```php
	public function bindShared($abstract, Closure $closure): void
    {
		$this->bind($abstract, $this->share($closure), true);
	}
```

**After:**
```php
	public function bindShared($abstract, Closure $closure): void
    {
		$this->bind($abstract, $closure, true);
	}
```

Use an exact-string Edit. The single delta is `$this->share($closure)` → `$closure`. Preserve the file's existing indentation (this file mixes tabs and spaces; match what's already on the line — the opening brace line is space-indented, the body lines are tab-indented). Do **not** touch the docblock, the signature, or the return type.

### Step 4 (BLOCKING SUB-STEP) — leave `share()` intact

**Do NOT delete or modify `Container::share()` (`:384-400`).** It is still called directly by app-scoped providers via `$this->app['x'] = $this->app->share(fn …)` (the entire audit table above). Those bindings are **app-scoped** and one shared instance across sandboxes is **CORRECT**. Removing `share()` would break them and would break the existing test `ContainerL4Test::testShareMethod()` (`tests/Container/ContainerL4Test.php:173-180`), which asserts the wrapper memoizes:
```php
public function testShareMethod(): void
{
    $container = new Container;
    $closure = $container->share(function() { return new stdClass; });
    $class1 = $closure($container);
    $class2 = $closure($container);
    $this->assertSame($class1, $class2);
}
```
That test must keep passing unchanged — it is part of your "full suite green" gate and is the canary that you left `share()` alone.

### Step 5 (analysis sub-step) — `extend()` and wrapper-identity audit

Confirm (no code change expected) that nothing depends on the **identity** of the `share()`-wrapper closure:

- **`extend()`** (`Container.php:425-440`): when an instance already exists it extends `$this->instances[$abstract]` directly (`:429-432`); otherwise it queues an extender applied in `make()` at `:796-798` **after** `build()` and **before** the `$instances` write (`:803-804`). Extenders operate on the **built object**, never on the concrete closure, so whether the concrete is the bare closure or the `share()` wrapper is irrelevant to `extend()`. The `ContainerExtendTest` suite (esp. `testExtendInstancesArePreserved`, `testMultipleExtends`, `testExtendBindRebindingCallback`) is the gate for this — it must stay green.
- **Double-bind / override**: `bind()` calls `dropStaleInstances()` (`:286` → `:1396-1399`, `unset($this->instances[$abstract], $this->aliases[$abstract])`), so re-binding a shared abstract correctly clears the stale `$instances` entry and the next `make()` rebuilds. With the bare closure this is *more* correct than before (previously the dropped wrapper's static could still hold the old object if the same wrapper instance were re-resolved). Behavior for a single container is unchanged because `make()` rebuilds from the (new) concrete either way.
- **Callers depend on singleton-ness, not on the wrapper object.** No code in `src/` inspects or stores the wrapper closure returned by `share()` when used via `bindShared` (the only `bindShared` internal caller of `share()` was the line you just changed). Confirm with: `grep -rn 'bindShared' src/ --include='*.php' | grep -v vendor/` — every hit is a provider registering a service it later resolves via `make()`/array-access, all of which depend only on getting the same instance back.

This step produces **no edit**; it is the reasoning the executor must verify so they trust the green suite as proof.

## New tests

Per refactor spec §12, tests **1** (singleton identity) and **2** (clone isolation). Create **one** new file, matching the existing convention observed in `tests/Container/ContainerL4Test.php` and `tests/Container/ContainerExtendTest.php`:

- Namespace `Illuminate\Tests\Container;`
- `use Illuminate\Container\Container;` and `use PHPUnit\Framework\TestCase;`
- Extend `PHPUnit\Framework\TestCase`; typed `: void` test methods.
- The PHPUnit suite auto-discovers any `*Test.php` under `./tests` (`phpunit.xml` testsuite `./tests`, suffix `Test.php`), so no registration is needed.

**File:** `tests/Container/ContainerBindSharedTest.php`

```php
<?php

namespace Illuminate\Tests\Container;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use stdClass;

class ContainerBindSharedTest extends TestCase
{
    // --- Spec §12 Test 1: singleton identity (Change #1 must preserve this) ---

    public function testBindSharedReturnsSameInstanceOnRepeatedMake(): void
    {
        $container = new Container;
        $container->bindShared('shared', function () {
            return new stdClass;
        });

        $first = $container->make('shared');
        $second = $container->make('shared');

        // Singleton identity now comes from $instances, not the share() static.
        $this->assertSame($first, $second);
    }

    public function testBindSharedClosureRunsExactlyOnce(): void
    {
        $container = new Container;
        $calls = 0;
        $container->bindShared('counter', function () use (&$calls) {
            $calls++;
            return new stdClass;
        });

        $container->make('counter');
        $container->make('counter');
        $container->make('counter');

        $this->assertSame(1, $calls); // resolved once, then served from $instances
    }

    public function testBindSharedIsRegisteredAsSharedBinding(): void
    {
        $container = new Container;
        $container->bindShared('shared', function () {
            return new stdClass;
        });

        // The binding itself must carry shared=true so make() caches it in $instances.
        $bindings = $container->getBindings();
        $this->assertTrue($bindings['shared']['shared']);
    }

    // --- Spec §12 Test 2: clone isolation, demonstrated at the bare Container level ---
    // (Runs independently of Job 1.2 / Application::__clone — see "Acceptance gate".)

    public function testClonedContainerForgetInstanceReResolvesIndependentObject(): void
    {
        $base = new Container;
        $base->bindShared('cache', function () {
            return new stdClass;
        });

        $baseInstance = $base->make('cache');

        // Shallow clone copies $instances by value: the clone starts pointing at the
        // SAME resolved object until it is forgotten on the clone.
        $clone = clone $base;
        $this->assertSame($baseInstance, $clone->make('cache'));

        // Forgetting on the clone must let the clone re-resolve a NEW object,
        // WITHOUT disturbing the base's instance. This is the property the share()
        // static used to break (the wrapper static would hand back the stale object).
        $clone->forgetInstance('cache');
        $cloneInstance = $clone->make('cache');

        $this->assertNotSame($baseInstance, $cloneInstance); // clone is isolated
        $this->assertSame($baseInstance, $base->make('cache')); // base is unchanged
    }
}
```

**What each test asserts / why it is the gate for this change:**
- `testBindSharedReturnsSameInstanceOnRepeatedMake` — spec §12 Test 1: proves singleton identity is preserved by `$instances` after the wrapper is gone (`make('x') === make('x')`).
- `testBindSharedClosureRunsExactlyOnce` — strengthens Test 1: proves the closure is memoized (not re-run) by `$instances`, i.e. the `share()` static was genuinely redundant.
- `testBindSharedIsRegisteredAsSharedBinding` — proves the edit still sets `shared = true` (so `isShared()` is true and `make()` caches), guarding against accidentally passing `false`.
- `testClonedContainerForgetInstanceReResolvesIndependentObject` — spec §12 Test 2: the core regression test for the leak. With the old `share()`-wrapper concrete this **fails** (the clone's `make('cache')` after `forgetInstance` re-enters the shared wrapper whose static still holds `$baseInstance`, so the two assertions `assertNotSame` / base-unchanged break). With the bare-closure concrete it passes. This is the test that proves the fix.

**Note on the §12 Test 2 "`clone $app`" form vs the bare-`Container` form (specify both, per the job brief):**
- The spec's Test 2 is written against an **`Application`** clone (`clone $app; forgetInstance('cache')`). A true `Application`-level clone-isolation test additionally requires `Application::__clone()` from **Job 1.2** to be present (otherwise the clone's `$instances['app']`/`['Illuminate\Container\Container']` still point at the base — orthogonal to this change, but it makes the *application* clone not fully self-consistent). **Therefore the binding-isolation property of Change #1 is demonstrated here at the bare `Container` level**, which exercises exactly the cache that Change #1 alters (`$instances` + the shared-binding concrete) and **runs independently of Job 1.2**.
- Do **not** add an `Application`-clone variant in this job — it belongs to Job 1.2 (`11-change2-app-clone.md`), which carries spec §12 Test 3 and may extend a clone-isolation test to the `Application` level once `__clone` exists. Keeping the Change-#1 gate at the `Container` level avoids a false cross-job dependency and lets this job fail fast on its own.

## Acceptance gate

This is the **strictest gate in the plan** (refactor spec §13.1: "the only change that can regress 4.2 behavior"). ALL of the following must hold:

1. **Full existing PHPUnit suite green** — before (baseline, Step 1) **and** after the edit. The whole suite, not a subset. This is the behavior-preservation contract. The highest-risk surfaces are the `extend()` / double-bind edge cases — `tests/Container/ContainerExtendTest.php` and `tests/Container/ContainerL4Test.php` (incl. `testShareMethod`, `testSharedClosureResolution`, `testSharedConcreteResolution`) **must** stay green unchanged.
2. **New tests 1–2 pass** — `tests/Container/ContainerBindSharedTest.php` (all four methods green).
3. **`->share(` audit complete** — the verdict table (Step 2) reproduced from a fresh `grep -rn '\->share(' src/ --include='*.php'`, every genuine container site classified, and **any** request-scoped site converted to `bindShared`/`singleton` (expected: none). The grep output + verdicts recorded in the commit body.
4. **`share()` untouched** — `Container::share()` (`:384-400`) byte-for-byte unchanged; `testShareMethod` still green (canary).
5. **Diff is minimal** — exactly one one-line change in `Container.php` (the `bindShared` body) plus the one new test file. Nothing else in `src/`.

## Out of scope / do NOT do

- **Do NOT delete or modify `Container::share()`** — it stays intact for app-scoped providers (audit table). Removing it breaks them and `testShareMethod`.
- **Do NOT change `bind()`, `make()`, `build()`, `instance()`, `extend()`, `isShared()`, `forgetInstance()`, `dropStaleInstances()`,** or any other container method. The only edit is the `bindShared` body.
- **Do NOT implement the rejected boot-warming alternative** (spec §5) — no "warm every shared service at boot," no relying on setter-repoint instead of this change. Change #1 is correct-by-construction; the alternative leaves a residual leak.
- **Do NOT add `Application::__clone()` here** — that is Job 1.2. Keep the clone-isolation test at the bare `Container` level.
- **Do NOT touch `tests/Container/ContainerL4Test.php` or `ContainerExtendTest.php`** (or any other existing test) — they are the regression gate and must pass unmodified. Add only the new file.
- **Do NOT touch the untracked `src/Illuminate/*/vendor/` snapshots** (Queue, Cookie) — out of scope, untracked, vendored duplicates.
- **No opportunistic container cleanup**, no new abstractions, no signature changes (keep `: void`), no docblock churn, no reformatting of unrelated lines. Minimal change only — per README "Minimal change. No scope creep."
- **Do NOT run git mutations or amend across jobs.** One job = one logical commit (README global gate 4); commit scope `octane`, confirm interactively, and end the commit body with the required `Co-Authored-By` trailer.

## Verification commands

```sh
# 0. (Step 1) Baseline BEFORE the edit — must be green:
make composer-test
#   or, if PHP 8.3 is local:
vendor/bin/phpunit -c phpunit.xml

# 1. (Step 2) The mandatory ->share( audit — reproduce the verdict table from this:
grep -rn '\->share(' src/ --include='*.php'
#   (exclude View\Factory::share + untracked */vendor/* per Step 2)

# 2. Confirm the bindShared call sites all rely on singleton-ness (no wrapper identity):
grep -rn 'bindShared' src/ --include='*.php' | grep -v vendor/

# 3. After the edit — targeted runs first:
vendor/bin/phpunit -c phpunit.xml tests/Container/ContainerBindSharedTest.php
vendor/bin/phpunit -c phpunit.xml tests/Container/ContainerExtendTest.php
vendor/bin/phpunit -c phpunit.xml tests/Container/ContainerL4Test.php

# 4. Then the FULL suite — the binding gate (must be green, same as baseline):
make composer-test
#   or:
vendor/bin/phpunit -c phpunit.xml

# 5. Sanity-check the diff is exactly the bindShared one-liner + the new test file:
git --no-pager diff --stat
git --no-pager diff src/Illuminate/Container/Container.php
```

---

**Definition of done:** `Container::bindShared()` binds the closure directly as shared (`$this->bind($abstract, $closure, true)`) with `share()` left intact, the `->share(` site audit table is reproduced with every site classified and zero request-scoped sites left unconverted, and the full existing PHPUnit suite plus the new `ContainerBindSharedTest` (singleton-identity + Container-level clone-isolation) are all green.
