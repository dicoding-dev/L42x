# refactor-octane — Executable Plan: PLAN.md Phase 0 → Phase 1 (L42x side)

This directory is the **agent-executable work plan** for the L42x framework changes that
enable Octane's clone-per-request sandbox. It covers **only Phase 0 (runtime hygiene +
feasibility spike) and Phase 1 (framework refactor)** from the master plan. Everything
here is work done **inside this repo** (`L42x`), on the branch
`improvements/octane-sandbox-enablement`.

Phases 2–5 (the Octane package itself) live in the sibling repo and are largely already
scaffolded — see *Boundary* below. Do not do package work from here.

---

## Why this exists

The Octane-for-FrankenPHP package (`../octane-rewrite-L42x`) is built and tested at the
package level, but it **cannot run correctly against a real L42x app** until L42x grows a
small set of additive, dormant changes that make `clone $app` per request safe. This plan
operationalizes those changes into discrete, independently-executable jobs with explicit
gates, so they can be handed one-at-a-time to medium/high-effort execution agents.

## Source-of-truth documents (read before executing any job)

| Doc | Path | Role |
|---|---|---|
| Master plan | `/home/alex/WORKSPACE/DICODING_PLAYGROUND/octane-rewrite-L42x/PLAN.md` | Overall plan; §10 phases/jobs; §8 state inventory |
| **Refactor spec (authoritative)** | `/home/alex/WORKSPACE/DICODING_PLAYGROUND/octane-rewrite-L42x/L42X-REFACTOR-FOR-OCTANE-SANDBOX.md` | Exact signatures, §10 worker swap protocol, §12 tests, §13 sequencing |
| Package handoff | `/home/alex/WORKSPACE/DICODING_PLAYGROUND/octane-rewrite-L42x/HANDOFF.md` | Current package state + "External L42x Prerequisites" (what these jobs satisfy) |
| Project memory | `/home/alex/WORKSPACE/DICODING_PLAYGROUND/octane-rewrite-L42x/.claude/memory/memory/octane-frankenphp-l42x-rewrite.md` | One-screen project context |
| Octane reference | `/home/alex/WORKSPACE/DICODING_PLAYGROUND/octane` | upstream `laravel/octane` v2 — **reference only**, do not copy blindly |

The refactor spec is **authoritative** for exact code. When this plan and the spec
disagree, the spec wins for *code*; this plan wins for *sequencing, gates, and effort*.

## Governing principle (do not violate)

From the fork charter (`UPGRADE_TO_LARAVEL_5.md:77-81`): **do not change Laravel 4.2
behavior.** Every change in Phase 1 is either:
- **additive + dormant** — a new public method no existing 4.2 path calls, or
- **behavior-preserving internal** — identity-preserving for a single container (only
  Change #1 is in this bucket).

A stock mod_php/fpm single-request app must behave **byte-for-byte the same**. This is
enforced by the global gate (full existing suite green after every change).

**Minimal change. No scope creep.** Implement exactly what the spec specifies — no extra
refactoring, no opportunistic cleanup, no new abstractions. If a job tempts you to touch
something not listed in its "Allowed scope", stop and flag it instead.

---

## Verified grounding (confirmed against this repo, branch base = master)

A read of the current source confirmed the spec's anchors with **zero meaningful drift**
(only cosmetic off-by-ones, noted). Executors should still re-confirm the exact line at
edit time, but these are accurate as of branch creation:

**Phase 0 — runtime drift (all real, all to fix in Job 0.1):**
- `Dockerfile:2` → `php:8.1-cli` (composer.json:13 requires `php >=8.3.29`).
- `composer.lock` pins `monolog/monolog 1.27.1`; `composer.json:18` requires `^2.10`
  (major-version gap — re-resolving may surface `Log\Writer` breakage; tests are the gate).
- `composer.lock` platform says `php >=8.1.0`.
- Tests: `make composer-test` → `composer test` → `./vendor/bin/phpunit --colors=always -c phpunit.xml`. CI runs PHP `8.3.29` (`.github/workflows/pull-request-check.yml`). ~162 test files under `tests/`. `pcntl` is installed in the Dockerfile and not strictly required by deps.

**Phase 1 — anchors (all MATCH unless noted):**
- `Container.php`: `share()` :384-400 (wraps a closure with `static $object`), `bindShared()` :409 (`$this->bind($abstract, $this->share($closure), true)` — **the only `->share(` call site in this file**), shared cache in `make()` :803-804, `instance()` :472, `build()` closure call :933, `rebinding()` :602, `refresh()` :622, `forgetInstance()` :1407, `flush()` :1427, static `setInstance()` :129 / `getInstance()` :115 / `$instance` :17. **No `__clone` exists.** `$tags` :108 is `private array $tags;` — **uninitialized typed property → reading it pre-init is a PHP 8.3 fatal; `__clone` must not touch it.**
- `Application.php`: class decl :26, `VERSION='4.2.72'` :33, self-bindings `instance('Illuminate\Container\Container',$this)` :140 (in `registerBaseBindings`; `:138` binds `request`) + `instance('app',$this)` at `start.php:62`, `handle()` :750, `run()` :650, `terminate()` :804, `boot()` :594, `refreshRequest()` :817. **No `__clone` exists.** **`getStackedClient()` :666 is `protected`** — the worker cannot reach it yet (Job 1.4).
- `start.php:62` → `$app->instance('app', $app)`.
- Re-point surface (all target methods **ABSENT** today, as expected): `Support/Manager.php` (`$app` :12, `$drivers` :26, `$customCreators` :19 — keep) → add `setApplication`/`forgetDrivers`; `Queue/QueueManager.php` (does NOT extend Manager; `$app` :17, `$connections` :24, `$connectors` :10 — keep) → add `setApplication`/`forgetConnections`; `Database/DatabaseManager.php` (`$app` :13, `$connections` :27, `purge()` :94, `$extensions`/`$factory` — keep) → add `setApplication`/`forgetConnections`; `Cookie/CookieJar.php` (`$queued` :26) → add `flushQueuedCookies`; `Routing/Router.php` (`$container` :28, `$controllerDispatcher` :50, `getControllerDispatcher()` :1744, `setControllerDispatcher()` :1761, `dispatch()` :1026) → add `setContainer`; `Validation/Factory.php` (`$container` :28, used in `make()` :102) → add `setContainer`.
- Already present (reuse, do not re-add): `View/Factory.php` `setContainer()` :798 / `share()` :288 / `flushSections()` :614; `Database/Connection.php` `flushQueryLog()` :1130 / `setEventDispatcher()` :1027; `UrlGenerator.php` `forceSchema()` :228 (**sic — misspelled; `forceScheme()` does not exist**).
- Process-global caches needing a flush method: `Str.php` static `$snakeCache` :17 / `$camelCache` :24 / `$studlyCache` :31 (add `flushCache()`); `EngineResolver.php` `$resolved` :19 (optional `forget()`).
- `Config/Repository.php`: `$items` :28 (plain array → shallow `clone` is CoW-safe); shares `$loader`/`$packages`/`$afterLoad` by reference (acceptable). No `__clone` today.
- Rebinding plumbing already wired: `RoutingServiceProvider` rebinds `request` → `url->setRequest`; `PaginationServiceProvider` `refresh('request',$paginator,'setRequest')`. **Leverage this — do not hand-roll request re-pointing for `url`/`paginator`.**
- `dd()` does a bare `die` at `helpers.php:523`. **The exception `Handler` does NOT itself `exit`/`die` in its core path** (it renders via `Response::send()`); it registers `set_exception_handler` + `register_shutdown_function`. This makes "exit neutralization" (Job 1.4) smaller than feared — the real risks are `dd()`, user-code `exit`, and the shutdown function in a long-lived loop.

---

## Work documents (index)

Execute in dependency order (see DAG). Each doc declares its own effort level for the
**executing** agent.

| # | File | Job | Spec | Effort | Touches code? |
|---|---|---|---|---|---|
| 1 | `00-phase0-runtime-hygiene.md` | 0.1 runtime drift fix | PLAN §10 / §11 (risk 6) | **MEDIUM** | Dockerfile, composer.lock (+ maybe Log\Writer) |
| 2 | `01-phase0-static-state-audit.md` | 0.2 leak register | PLAN §10 / §8 | **MEDIUM** | none (read-only → produces a doc) |
| 3 | `10-change1-bindshared.md` | 1.1 Change #1 | spec §5 | **HIGH** | `Container::bindShared` + tests |
| 4 | `11-change2-app-clone.md` | 1.2 Change #2 | spec §6 | **MEDIUM** | `Application::__clone` + tests |
| 5 | `12-change3-4-6-repoint-setters.md` | 1.3 Changes #3/#4/#6 (+opt #5/#7/#8) | spec §7–§9 | **MEDIUM** | managers/router/validation (+view/engine/config) + tests |
| 6 | `13-worker-safety.md` | 1.4 worker-safety | PLAN §9, spec §11 | **HIGH** | stacked-kernel reach, exit guard, `Str::flushCache` + tests |
| 7 | `20-phase0-feasibility-spike.md` | 0.3 spike (go/no-go) | PLAN §10 / §6 | **HIGH** | throwaway spike script (not committed to src) |

## Dependency DAG & recommended order

```
0.1 runtime-hygiene ─┐  (green baseline on PHP 8.3 — prerequisite for trusting ANY test)
0.2 static-audit ────┤  (read-only; run in parallel with 0.1)
                     │
1.1 bindshared  ─────┼──→ 1.2 app-clone ──→ 1.3 repoint-setters ──┐
                     │                                            │
1.4 worker-safety ───┘  (needs 0.1 green; otherwise independent)  │
                                                                  ▼
        (1.1 + 1.2 + 1.3 + 1.4 all landed) ──────→ 0.3 feasibility-spike  [GO/NO-GO]
```

- **Strict order on the critical path:** 0.1 → 1.1 → 1.2 → 1.3, then 0.3.
- **Parallelizable:** 0.2 (read-only) alongside everything; 1.4 alongside 1.1–1.3 once 0.1
  is green.
- **0.3 is the integration gate** for the whole of Phase 1 and the entry condition for the
  package's Phase-2 swap protocol. It needs 1.1–1.4 done.
- Optional Changes #5/#7/#8 (view/engine/config helpers) are folded into doc 5; they are
  defensive parity and may be skipped without blocking 0.3.

## Global gates (apply to every code-touching job)

1. **Full existing suite green** before and after the change — this is the
   behavior-preservation contract. Run `make composer-test` (Docker) or
   `vendor/bin/phpunit -c phpunit.xml` (if PHP 8.3 is available locally). 0.1 must achieve
   the green *baseline* first; later jobs must keep it green.
2. **New tests land with the change** — each Phase-1 job adds the specific test(s) named in
   refactor spec §12, in L42x's `tests/` dir, matching existing test conventions. (Job 1.4
   additionally adds worker-safety tests drawn from PLAN §9; those are not numbered in spec §12.)
3. **Additive/dormant proof** — for every new method, confirm no existing 4.2 code path
   calls it (the unchanged suite passing is the proof). Change #1 is the only internal edit;
   it carries the singleton-identity + clone-isolation tests as its specific gate.
4. **One job = one logical commit.** Use the `git-commit` skill; scope `octane` (confirm
   interactively). Do not amend across jobs.

## How to run tests

```sh
# Canonical (Docker, after Job 0.1 bumps the image to 8.3):
make composer-test          # = composer test = ./vendor/bin/phpunit --colors=always -c phpunit.xml
make bash                   # shell into the container for iterative runs

# Targeted file run inside the container:
vendor/bin/phpunit -c phpunit.xml tests/Container/ContainerL4Test.php
```

**Test conventions vary by directory** — before adding a test, open a sibling in the SAME
directory and copy its namespace + base class. Verified: `tests/Support/` = no namespace +
`PHPUnit\Framework\TestCase`; `tests/Routing/`, `tests/Config/`, `tests/Foundation/` = no
namespace + `L4\Tests\BackwardCompatibleTestCase`; `tests/Container/` = `namespace
Illuminate\Tests\Container` + `PHPUnit\Framework\TestCase`.

---

## Work-doc template (every doc 1–7 follows this)

```markdown
# Job <N> — <title>

- **Effort (for the executing agent):** LOW | MEDIUM | HIGH
- **Depends on:** <jobs that must land first>
- **Spec refs:** <PLAN.md §, refactor-doc §, verified anchors>
- **Allowed scope (files this job may modify):** <explicit list>

## Objective
<one paragraph: what "done" means>

## Context / why
<just enough so the executor understands the leak/risk being closed>

## Exact changes
<step-by-step, with verified file:line anchors and the precise code to add/edit;
quote the spec's signatures verbatim>

## New tests
<which spec §12 test(s); file + cases; what they assert>

## Acceptance gate
<the specific pass condition: full suite green + these new tests + any extra check>

## Out of scope / do NOT do
<explicit anti-scope-creep list>

## Verification commands
<exact commands to run>
```

---

## Boundary — what is NOT in this plan

- **Phases 2–5** (Octane package: `ApplicationFactory`, `Worker`, `SandboxPreparer`,
  FrankenPHP commands, leak harness, docs) — done/scaffolded in `../octane-rewrite-L42x`
  per `HANDOFF.md`. The package's §10 worker swap protocol *consumes* what Phase 1 builds.
- **D4 event-dispatch shim** — that's the package's `DispatchesEvents` (already present per
  HANDOFF); L42x's string `Dispatcher::fire()` already exists, so **no L42x change** is
  needed for D4. (Job 1.4 does not implement D4. Note: PLAN §10 lists D4 under Job 1.4, but
  that is superseded here — D4 is package-side per PLAN §D4 / spec §11.)
- **No `Illuminate\Contracts\*`, no `Http\Kernel`, no `bootstrapWith()`** — out of scope per
  the fork charter (PLAN §D5, spec §11).
- **Not porting:** `Route::flushController()`, `RouteCollection` reset, `Redirector`
  re-point, dispatcher cloning, RoadRunner/Swoole/PSR-7 (spec §11, PLAN §D2).
