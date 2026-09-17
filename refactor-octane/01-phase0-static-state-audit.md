# Job 0.2 — Static-State Audit / Leak Register

- **Effort (for the executing agent):** MEDIUM
- **Depends on:** none (read-only; parallelizable with Job 0.1)
- **Spec refs:** PLAN.md §8 (per-request state inventory), PLAN.md §10 Phase 0 Job 0.2,
  README.md verified anchors
- **Allowed scope (files this job may modify):**
  `refactor-octane/artifacts/leak-register.md` — that file only.
  Do NOT touch any `src/` file. Do NOT commit. Do NOT run tests.

---

## Objective

Produce a complete "leak register" at `refactor-octane/artifacts/leak-register.md` that
enumerates every `static` property and long-lived singleton in `src/Illuminate/` that can
hold request-scoped state, classifies each by the PLAN §8 bucket, and cross-checks the
result against PLAN §8 so that any gap (a §8 row not backed by a register entry, or a
FLUSH/RE-POINT entry not covered by a Phase-1 change) is explicitly flagged. This register
is the provable completeness argument for the clone-sandbox re-point/flush list.

---

## Context / why

The clone-per-request sandbox model discards per-container state for free (binding-level
mutations vanish with the sandbox). But `static` properties and shared-object state live
outside the container graph and survive across requests. If any such location holds
request-scoped data and is not flushed or re-pointed before the next request, it is a
cross-request state leak — a security bug. PLAN §8 lists the known surface; this job
confirms that list is complete by sweeping the whole codebase, not just the known spots.

---

## Audit method (execute in order)

### Step 1 — grep sweep for static properties

Run the following greps across `src/Illuminate/`. Capture every hit with file:line.

```bash
# protected/private/public static properties (skip static methods — they hold no state)
grep -rn --include="*.php" \
  -E 'protected static \$|private static \$|public static \$' \
  src/Illuminate/
```

For each hit, open the file and read the property declaration and its class. Record in the
register table. Exclude static methods that happen to contain `static $variable` inside
their body (closure-local statics are a separate concern — see Step 3).

### Step 2 — grep sweep for closure-local `static $object` (the `share()` pattern)

The `Container::share()` method wraps a closure with `static $object` (`:391`) — this is
the exact pattern Change #1 (Job 1.1) eliminates. Confirm the only call site of `->share(`
inside `Container.php` is `bindShared()` (:411), and check whether any other file in
`src/Illuminate/` uses the same pattern independently:

```bash
grep -rn --include="*.php" 'static \$object' src/Illuminate/
grep -rn --include="*.php" '->share(' src/Illuminate/
```

Any hit outside `Container.php` must be added to the register with its bucket.

### Step 3 — MacroableTrait sweep

`MacroableTrait` (`Support/Traits/MacroableTrait.php:10`) declares `protected static
$macros`. Every class that `use`s this trait gets its own per-class static `$macros`
registry. Find all users:

```bash
grep -rn --include="*.php" 'use MacroableTrait\|use Illuminate\\Support\\Traits\\MacroableTrait' \
  src/Illuminate/
```

Each user gets one register row. These are application-lifetime registrations (set up in
service providers during boot, not per-request) — bucket is N/A, do NOT flush.

### Step 4 — AliasLoader singleton

`Foundation/AliasLoader.php:24` has `protected static $instance`. This is a
process-global singleton (set once at boot, never per-request). Verify it is not
re-instantiated per request. Bucket: N/A.

### Step 5 — ClassLoader statics

`Support/ClassLoader.php:10,17` has `protected static $directories` and `protected static
$registered`. These are boot-time configuration for the autoloader. Bucket: N/A.

### Step 6 — Pluralizer / PluralizationRules

`Support/Pluralizer.php:10` has `public static $plural` (and singular/irregular arrays).
`Translation/PluralizationRules.php:14` has `private static $rules`. Both are constant
lookup tables populated once. Verify neither is mutated per-request. Bucket: N/A.

### Step 7 — Facade statics

`Support/Facades/Facade.php:12` — `protected static $app`.
`Support/Facades/Facade.php:19` — `protected static $resolvedInstance`.

Both are managed by the worker swap sequence
(`clearResolvedInstances()` + `setFacadeApplication()` at sandbox entry and restore at
`finally`). Bucket: handled by worker swap (not a Phase-1 source change).

### Step 8 — Container::$instance

`Container/Container.php:17` — `private static ?Container $instance`.

Managed by `Container::setInstance($sandbox)` at sandbox entry and
`Container::setInstance($base)` at `finally`. No Phase-1 change required. Bucket:
handled by worker swap.

### Step 9 — Inspect Str caches

`Support/Str.php:17` — `protected static array $snakeCache`.
`Support/Str.php:24` — `protected static array $camelCache`.
`Support/Str.php:31` — `protected static array $studlyCache`.

These are unbounded process-global caches that cloning cannot isolate. Confirm that no
`flushCache()` method exists today:

```bash
grep -n 'flushCache' src/Illuminate/Support/Str.php
```

If absent (expected), record as FLUSH with "added by Job 1.4". Memory growth is bounded
by the vocabulary of distinct string inputs, but a long-lived worker accumulates more than
a single PHP-FPM request.

### Step 10 — EngineResolver::$resolved

`View/Engines/EngineResolver.php:19` — `protected $resolved`.

NOTE: this is an **instance** property, not static. It is held on the `EngineResolver`
instance that lives in the container. The clone sandbox inherits the resolved engine
instances (already constructed, stateless renderers). This is safe but optional to reset.
Confirm:

```bash
grep -n 'forget' src/Illuminate/View/Engines/EngineResolver.php
```

If `forget()` is absent (expected), record as FLUSH (optional) with "added by Job 1.4".

### Step 11 — View\Factory::$shared

`View/Factory.php:45` — `protected $shared`.

Instance property. The worker re-shares `app` on the sandbox via
`$sandbox['view']->share('app', $sandbox)` and calls `flushSections()` before each
request. Confirm `share()` (:288) and `flushSections()` (:614) already exist:

```bash
grep -n 'function share\|function flushSections\|function setContainer' \
  src/Illuminate/View/Factory.php
```

Bucket: RE-POINT (setContainer + share + flushSections, all already present).

### Step 12 — Pagination\Factory instance fields

`Pagination/Factory.php:14` — `protected $request`.
`Pagination/Factory.php:42` — `protected $currentPage`.

Both are instance properties on the `Pagination\Factory` singleton. `$request` is
re-pointed by the existing `PaginationServiceProvider` `rebinding('request', ...)` →
`$paginator->setRequest($request)` (AUTO bucket). `$currentPage` is reset implicitly
when `setRequest` fires (caller sets it via URL input each request). No Phase-1 change
needed. Bucket: AUTO.

### Step 13 — Routing\Router instance fields for request state

`Routing/Router.php:45` — `protected $currentRequest` (instance property).

Set by `Router::dispatch(Request)` (:1027) at the start of each request. With
`Router::setContainer($sandbox)` (Change #4, Job 1.3), the router is re-pointed at the
sandbox but `$currentRequest` is simply overwritten on every `dispatch()` call — no
explicit flush needed. Confirm the line number:

```bash
grep -n '\$currentRequest' src/Illuminate/Routing/Router.php | head -5
```

Bucket: FREE (overwritten by each request dispatch, no residual risk across clones).

### Step 14 — scan for any remaining static properties missed by earlier steps

After collecting all hits from Steps 1–13, do a final broad sweep to catch anything
unusual (e.g. traits, abstract classes, test stubs outside `tests/`):

```bash
grep -rn --include="*.php" \
  -E '^\s+(protected|private|public) static \$' \
  src/Illuminate/ \
  | grep -v '/Tests/' \
  | grep -v 'function '
```

Compare this list against the register. Every hit must appear in the register. Add any
new rows with a preliminary classification and mark them `NEEDS-VERIFY`.

### Step 15 — cross-check against PLAN §8

Read PLAN.md §8 table row by row. For every row that is not N/A:
- Confirm a matching register entry exists.
- Confirm the bucket matches.
- For RE-POINT/FLUSH rows: confirm a Phase-1 job (1.1–1.4) covers the mechanism.

Flag any mismatch as a **GAP** in the register's "Gap / note" column.

---

## Output file

Write the completed register to:

```
refactor-octane/artifacts/leak-register.md
```

Create the `artifacts/` directory if it does not exist. The file must contain exactly one
table with the columns below, followed by a "Gaps" section listing any unresolved GAPs.

### Required columns

| Column | Content |
|---|---|
| # | Sequential row number |
| Location | `File.php:line` (relative to `src/Illuminate/`) |
| Symbol | Property or singleton name, e.g. `Str::$snakeCache` |
| Kind | `static-prop` / `instance-prop` / `singleton` / `closure-static` |
| Bucket | `AUTO` / `CLONE` / `RE-POINT` / `FLUSH` / `FREE` / `N/A` |
| Phase-1 coverage | Job # that adds the flush/setter, or "already present", or "worker swap", or "—" for N/A |
| Gap / note | "OK" if covered; otherwise a gap description |

---

## Starter leak register

> STARTER — the executing agent MUST verify every row against current source (re-confirm
> file:line, confirm no flush/setter already exists where noted) and MUST complete the
> table by running the audit steps above. Rows marked "NEEDS-VERIFY" require source
> confirmation before the register is considered complete.

| # | Location | Symbol | Kind | Bucket | Phase-1 coverage | Gap / note |
|---|---|---|---|---|---|---|
| 1 | `Support/Str.php:17` | `Str::$snakeCache` | static-prop | FLUSH | Job 1.4 (`Str::flushCache()`) | OK — flush added by Job 1.4 |
| 2 | `Support/Str.php:24` | `Str::$camelCache` | static-prop | FLUSH | Job 1.4 (`Str::flushCache()`) | OK — flush added by Job 1.4 |
| 3 | `Support/Str.php:31` | `Str::$studlyCache` | static-prop | FLUSH | Job 1.4 (`Str::flushCache()`) | OK — flush added by Job 1.4 |
| 4 | `Container/Container.php:17` | `Container::$instance` | static-prop | FREE | worker swap (`Container::setInstance`) | OK — managed by worker swap, not a Phase-1 change |
| 5 | `Support/Facades/Facade.php:12` | `Facade::$app` | static-prop | FREE | worker swap (`Facade::setFacadeApplication`) | OK — managed by worker swap |
| 6 | `Support/Facades/Facade.php:19` | `Facade::$resolvedInstance` | static-prop | FREE | worker swap (`Facade::clearResolvedInstances`) | OK — managed by worker swap |
| 7 | `View/Engines/EngineResolver.php:19` | `EngineResolver->$resolved` | instance-prop | FLUSH | Job 1.4 (optional `forget()`) | OK — optional; low urgency (renderers are stateless) |
| 8 | `View/Factory.php:45` | `View\Factory->$shared` | instance-prop | RE-POINT | Job 1.3 (`setContainer` + `share` + `flushSections`) | OK — `setContainer/share/flushSections` already present |
| 9 | `Pagination/Factory.php:42` | `Pagination\Factory->$currentPage` | instance-prop | AUTO | already present (rebinding via `PaginationServiceProvider`) | OK — reset via `setRequest` callback each request |
| 10 | `Pagination/Factory.php:14` | `Pagination\Factory->$request` | instance-prop | AUTO | already present (rebinding via `PaginationServiceProvider`) | OK — auto re-pointed via `rebinding('request')` |
| 11 | `Support/Traits/MacroableTrait.php:10` | `MacroableTrait::$macros` (all users: `Str`, `Cache\Repository`, `Support\Facades\Response`, `Html\FormBuilder`, `Html\HtmlBuilder`, `Support\Arr`, …) | static-prop | N/A | — | OK — app-lifetime registrations set at boot; must NOT flush |
| 12 | `Foundation/AliasLoader.php:24` | `AliasLoader::$instance` | static-prop | N/A | — | OK — process-global singleton, set once at boot |
| 13 | `Support/ClassLoader.php:10` | `ClassLoader::$directories` | static-prop | N/A | — | OK — autoloader config, set once at boot |
| 14 | `Support/ClassLoader.php:17` | `ClassLoader::$registered` | static-prop | N/A | — | OK — autoloader flag, set once at boot |
| 15 | `Support/Pluralizer.php:10` | `Pluralizer::$plural` (+ `$singular`, `$irregular`) | static-prop | N/A | — | OK — constant lookup tables, never mutated per-request (NEEDS-VERIFY: confirm no app mutates these) |
| 16 | `Translation/PluralizationRules.php:14` | `PluralizationRules::$rules` | static-prop | N/A | — | OK — locale rule cache, lazily populated once per locale key, no per-request mutation (NEEDS-VERIFY) |
| 17 | `Container/Container.php:384–400` | `share()` closure-local `static $object` | closure-static | FREE | Job 1.1 (`bindShared` fix eliminates this path) | OK — eliminated for `bindShared` by Change #1; any remaining direct `share()` usage after Job 1.1 must be audited here |
| 18 | `Routing/Router.php:45` | `Router->$currentRequest` | instance-prop | FREE | Job 1.3 (`Router::setContainer`) | OK — overwritten by each `dispatch()` call; no cross-request residue once router is re-pointed at the sandbox |

> Rows 19–28 below: the shared **service singletons** PLAN §8 marks RE-POINT / CLONE / FLUSH.
> They are not `static` properties (they live in the container's `$instances`), but the audit
> must list them so the PLAN §8 cross-check (Step 15) is provably one-per-row. They are
> re-pointed/cloned by the worker swap (spec §10) using the Job 1.3 setters; pre-seeded here.

| # | Location | Symbol | Kind | Bucket | Phase-1 coverage | Gap / note |
|---|---|---|---|---|---|---|
| 19 | `Database/DatabaseManager.php` (`db`) | `DatabaseManager->$app` + `$connections` | singleton | RE-POINT | Job 1.3 (`setApplication`+`forgetConnections`) | OK — shared `db` manager re-pointed at sandbox; Eloquent resolver IS this object |
| 20 | `Auth/AuthManager.php` (`auth`) | `AuthManager->$app` + `$drivers` (Guard holds `$user`) | singleton | RE-POINT | Job 1.3 (`setApplication`+`forgetDrivers`) | OK — `forgetDrivers` → fresh Guard reads new request/session (the identity-leak row) |
| 21 | `Cache/CacheManager.php` (`cache`) | `CacheManager->$app` + `$drivers` | singleton | RE-POINT | Job 1.3 (`setApplication`+`forgetDrivers`) | OK |
| 22 | `Session/SessionManager.php` (`session`) | `SessionManager->$app` + `$drivers` (Store) | singleton | RE-POINT | Job 1.3 (`setApplication`+`forgetDrivers`) | OK — fresh `Store` bound to the new request |
| 23 | `Queue/QueueManager.php` (`queue`) | `QueueManager->$app` + `$connections` | singleton | RE-POINT | Job 1.3 (`setApplication`+`forgetConnections`) | OK |
| 24 | `Routing/Router.php:28,50` (`router`) | `Router->$container` + cached `$controllerDispatcher` | singleton | RE-POINT | Job 1.3 (`Router::setContainer` nulls the dispatcher) | OK — the one real routing leak (Change #4) |
| 25 | `Validation/Factory.php:28` (`validator`) | `Validation\Factory->$container` | singleton | RE-POINT | Job 1.3 (`Validation\Factory::setContainer`) | OK — for class-based rule extensions |
| 26 | `Cookie/CookieJar.php:26` (`cookie`) | `CookieJar->$queued` | singleton | FLUSH | Job 1.3 (`flushQueuedCookies`) | OK — shared jar held by Guards; flush in place, do NOT rebind |
| 27 | `Config/Repository.php:28` (`config`) | `Repository->$items` | singleton | CLONE | worker swap (`instance('config', clone $base['config'])`) | OK — per-request `set()` isolated by the clone (spec §10 step 2) |
| 28 | `Translation/Translator.php` (`translator`) | `Translator->$parsed` (+ locale/fallback) | singleton | CLONE (or FLUSH `flushParsedKeys`) | worker swap (clone into sandbox, spec §10 step 2; or reset locale — see Job 1.4(d)) | OK — per-request `setLocale()`/parsed-keys isolation; PLAN §8 translator row (do NOT leave this unprobed in the 0.3 spike) |

---

## Acceptance gate

The register is complete when all of the following hold:

1. Every row produced by the Step 1–14 grep sweep appears in the register table (no
   hit left unclassified).
2. Every row in PLAN §8 that is not "N/A for 4.2" has a matching register entry, and the
   bucket in the register matches the bucket in §8.
3. Every row with bucket RE-POINT or FLUSH has a Phase-1 coverage entry that names a
   specific job (1.1–1.4).
4. The "Gaps" section is present. If no gaps exist, it reads "No gaps identified." If
   gaps exist, each one names: the location, the risk (cross-request leak vs. unbounded
   growth), and the recommended action.
5. All NEEDS-VERIFY rows have been confirmed or promoted to a substantive bucket.

---

## Out of scope / do NOT do

- Do NOT modify any file under `src/`.
- Do NOT run tests or git commands.
- Do NOT add flush calls, setters, or any code — this job is pure analysis.
- Do NOT audit `tests/` directory PHP files.
- Do NOT chase upstream `laravel/octane` listeners that target services absent from 4.2
  (LogManager, broadcasting, notifications, PaginationState, CompiledRouteCollection,
  Once::flush, scoped instances — all listed as N/A in PLAN §8).
- Do NOT include Doctrine DBAL or other vendor statics — scope is `src/Illuminate/` only.
- Do NOT add register rows for PLAN §8's `uploaded files` (SAPI `is_uploaded_file` /
  `move_uploaded_file` shims) or the optional `garbage collection` row — both are
  worker/package-side (Octane package Phase 2/3), N/A for this framework-level register.
  Record them as "N/A — package-side (Phase 2/3)" in the Gaps section so the per-row §8
  accounting is provably complete.

---

## Verification commands

```bash
# Step 1: all static property declarations
grep -rn --include="*.php" \
  -E '^\s+(protected|private|public) static \$' \
  src/Illuminate/ \
  | grep -v 'function '

# Step 2: closure-local static $object and share() call sites
grep -rn --include="*.php" 'static \$object' src/Illuminate/
grep -rn --include="*.php" '->share(' src/Illuminate/

# Step 3: MacroableTrait users
grep -rn --include="*.php" 'MacroableTrait' src/Illuminate/ | grep 'use '

# Step 9: confirm Str::flushCache absent
grep -n 'flushCache' src/Illuminate/Support/Str.php

# Step 10: confirm EngineResolver::forget absent
grep -n 'forget' src/Illuminate/View/Engines/EngineResolver.php

# Step 11: confirm View\Factory has share/flushSections/setContainer
grep -n 'function share\|function flushSections\|function setContainer' \
  src/Illuminate/View/Factory.php

# Step 13: confirm Router::$currentRequest line
grep -n '\$currentRequest' src/Illuminate/Routing/Router.php | head -5
```

---

**Definition of done:** `refactor-octane/artifacts/leak-register.md` exists, contains a
fully classified row for every static property and relevant singleton found by the grep
sweep, cross-checks cleanly against PLAN §8 with no unresolved gaps, and every
RE-POINT/FLUSH row names the Phase-1 job that covers it.
