# Job 0.3 — Feasibility spike (clone-per-request GO/NO-GO gate)

- **Effort (for the executing agent):** HIGH (integration + judgment)
- **Depends on:** Jobs 1.1, 1.2, 1.3, 1.4 — **all four must be landed and the full suite green**. This is the integration gate for the whole of Phase 1 and the entry condition for the package's Phase-2 §10 swap protocol. Do not start until:
  - 1.1 `Container::bindShared()` caches via `$instances` (spec §5) — landed.
  - 1.2 `Application::__clone()` self-reference fixup + `$tags` guard (spec §6) — landed.
  - 1.3 re-point setters: `Support\Manager::setApplication/forgetDrivers`, `QueueManager`/`DatabaseManager` `setApplication/forgetConnections`, `Router::setContainer`, `Validation\Factory::setContainer`, `CookieJar::flushQueuedCookies` (spec §7–§9) — landed.
  - 1.4 worker-safety: reachable stacked kernel (`handleOctaneRequest($request, $catch=false)` and/or public `getStackedClient()`), exit neutralization, `Str::flushCache()` (PLAN §9, spec §11) — landed.
- **Spec refs:** PLAN.md §10 (Phase 0, job 0.3); PLAN.md §6 "Boot (once per process)" + per-request loop body; PLAN.md §8 (state inventory — one assertion per AUTO/CLONE/RE-POINT/FLUSH row); PLAN.md §11 (risks #1, #2, #4); PLAN.md §12 (leak harness); spec §10 (authoritative ordered worker swap protocol); spec §2/§3 (execution model — what's free / what leaks); spec §12 (cross-request leak gate); HANDOFF.md "External L42x Prerequisites" + the `handleOctaneRequest()`→`Application::handle()` fallback note.
- **Allowed scope (files this job may modify):**
  - **Throwaway spike artifacts only**, under `refactor-octane/artifacts/spike/` (create the directory): the spike script(s) and the `RESULT.md` GO/NO-GO note. A tmp path is also acceptable if you prefer not to leave anything in the tree — but `refactor-octane/artifacts/spike/` is the recommended home so the note is reviewable.
  - **Do NOT modify any file under `src/`.** This job *consumes* Phase-1 changes; it does not make them. If the spike reveals a missing or broken Phase-1 change, the deliverable is a NO-GO note that pinpoints the gap — not a fix here.
  - Do **not** run git (no commit, no branch ops). Do **not** modify other docs in `refactor-octane/`. Do **not** add anything to `src/`, `tests/`, `composer.json`, or `Dockerfile`.

## Objective

Prove, against an **actually-run** in-process spike, that L42x's clone-per-request sandbox model is viable end-to-end after Phase 1: boot a real L42x application **once**, then serve 2–3 synthetic requests against **cloned** sandboxes through the stacked kernel, and demonstrate (a) no fatal on boot, (b) no fatal on `clone $base`, (c) **no cross-request state leak** across every PLAN §8 RE-POINT/CLONE/FLUSH concern, (d) no `exit`/`die` in the normal request path kills the loop, and (e) the stacked kernel runs cookie/session middleware *on the clone* (not bypassed). The single deliverable that matters is a written **GO/NO-GO note** (`refactor-octane/artifacts/spike/RESULT.md`) with all five criteria evaluated. "Done" = that note exists, every criterion is marked PASS / FAIL / ACCEPTED-RESIDUAL-RISK against observed spike output, and GO is recommended only if all five are satisfied (or a residual risk is documented and explicitly signed off).

This is a **feasibility spike**, not the production worker. Keep it minimal — just enough to answer go/no-go. It is a miniature, in-process dry-run of the package's blocking leak-gate (spec §12 / PLAN §12) so we catch an incomplete re-point list *before* the package's Phase-2 `Worker`/`SandboxPreparer` is wired against a real app.

## Context / why

The Octane-for-FrankenPHP package (`../octane-rewrite-L42x`) is built and tested at the package level but has **never run against a real L42x app** — its current tests mock the app, and per HANDOFF.md the package falls back to `Application::handle()` whenever `handleOctaneRequest()` is not callable, and a "minimal real-L42x host-app fixture" is still a package TODO. PLAN §6 describes the worker request lifecycle (boot once → per request: clone → make-current → re-point → handle via stacked kernel → respond → terminate → discard); spec §10 is the authoritative **ordered** swap protocol; spec §2/§3 explain *why* it works (a FrankenPHP worker handles one request at a time, the clone isolates the bindings/instances arrays for free, and shared stateful objects are re-pointed at the sandbox). PLAN §8 enumerates the per-concern state inventory; **an omitted row is a cross-request leak** (a security bug), so each row gets exactly one leak-probe assertion.

The danger this gate closes: each Phase-1 change passed its *own* unit tests in isolation, but nobody has run the *composition* — real boot + real `clone` + the full §10 re-point sequence + the real stacked kernel — and verified the whole thing is leak-free and crash-free under a long-lived loop. Three specific hazards motivate the spike (PLAN §11):
- **`clone $base` fatal.** `Container::$tags` is `private array $tags;` (`Container.php:108`) — an *uninitialized typed property*. Reading it before `tag()` is ever called is a PHP 8.3 fatal. Job 1.2's `__clone` must not touch `$tags`. The spike confirms a bare `clone $base` does not fatal.
- **Cross-request leak.** If any RE-POINT row (db/auth/cache/session/queue/router/view/validator/cookie) or CLONE row (config) is incomplete, request B sees request A's identity/session/config/cookie. This is the security-critical core (PLAN §8 "Correctness bar").
- **`exit`/`die` kills the worker.** 4.2's `dd()` does a bare `die` (`helpers.php:523`); user code or the exception path can `exit`. README's verified grounding notes the exception `Handler` does *not* itself `exit` in its core path (it renders via `Response::send()`) — so the risk is narrower than feared, but must be characterized under `$catch=false`, and the finding fed back into Job 1.4.

> **Authoritative sources, in priority order, when they disagree:** spec §10 wins for the *exact ordered code* of the swap; PLAN §8 wins for *which concerns* to probe (the row list); PLAN §6 is the readable narrative; this doc wins for *spike sequencing, the assertion list, and the GO/NO-GO format*. Re-read spec §10 verbatim before writing the per-request body — it is the checklist.

## Verified anchors (re-confirm at edit time; accurate as of branch `improvements/octane-sandbox-enablement`)

These are the framework surfaces the spike calls. They were confirmed by source read; the executor should still re-confirm exact lines (Phase-1 jobs may have shifted them by a few lines):

- **Boot sequence** — `start.php`: `$app->instance('app', $app)` (`:62`), `Facade::clearResolvedInstances()` (`:91`), `Facade::setFacadeApplication($app)` (`:93`), `$app->instance('config', new Config(...))` (`:133`), `Request::enableHttpMethodParameterOverride()` (`:195`), provider load (`:210`), `$app->booted(...)` registers the routes/global-start callback (`:223`). `Application::__construct(Request $request = null)` (`:111`) eagerly calls `registerBaseBindings` (binds `request` + `Illuminate\Container\Container`, `:136-141`) + `registerBaseServiceProviders` (Event/Exception/Routing, `:148-154`) + `registerBaseMiddlewares` (`:117`). `boot()` (`:594`) is idempotent (`if ($this->booted) return;`). `bindInstallPaths()` (`:192`). `startExceptionHandling()` (`:220`).
- **Per-request kernel** — `Application::handle(SymfonyRequest, $type = MAIN_REQUEST, $catch = true): SymfonyResponse` (`:750`) — returns the response **without sending it**; with `$catch=false` it rethrows instead of routing through `$this['exception']->handleException()`. `getStackedClient()` (`:666`, **protected pre-1.4**) builds `Cookie\Guard`(encrypter) → `Cookie\Queue`(cookie) → `Session\Middleware`(session) via `MiddlewareBuilder` and `->resolve($this)`. `run()` (`:650`) is `getStackedClient()->handle()` + `$response->send()` + `$stack->terminate()` — **do not call `run()`** (it sends + you can't capture cleanly); drive the stack yourself. `terminate(SymfonyRequest, SymfonyResponse)` (`:804`) = finish callbacks + shutdown. `refreshRequest(Request)` (`:817`, protected) = `instance('request', …)` + `Facade::clearResolvedInstance('request')`.
- **Stacked-kernel reach (Job 1.4)** — the worker cannot call `getStackedClient()` (protected) today. Job 1.4 makes the stack reachable via an additive `handleOctaneRequest($request, $catch=false)` and/or by making `getStackedClient()` public. **Probe with `is_callable()` (not `method_exists()`)** — this mirrors the package's own probing fix (HANDOFF "In Progress": `Worker.php` switched to `is_callable()` for `handleOctaneRequest()`/`getStackedClient()`). Prefer `handleOctaneRequest($req, false)`; fall back to `getStackedClient()->handle($req, MAIN_REQUEST, false)` if only the accessor was made public. **If neither is callable, the spike is a NO-GO on criterion 5** (and you've reproduced exactly the gap HANDOFF flags — the package's `Application::handle()` fallback *bypasses the cookie/session middleware stack*, which is wired only in `run()`/`getStackedClient()`, not in bare `handle()`).
- **Statics for the swap** — `Container::setInstance(Container $c = null): ?Container` (`:129`); `Container::getInstance()` (`:115`) lazily `new static` if the static is null (so only call it if you actually use it). `Facade::clearResolvedInstances()` (`:168`), `Facade::setFacadeApplication($app)` (`:189`), `Facade::clearResolvedInstance($name)` (`:158`).
- **Clone concerns** — `Container::$tags` (`:108`) uninitialized typed property (do not read pre-`tag()`); `Application::__clone` / `Container::__clone` provided by Job 1.2 (confirm present before running — `grep -n 'function __clone' src/Illuminate/Foundation/Application.php`). `bindShared()` (`:409`) post-1.1 binds shared directly via `$instances` (no `share()` static wrapper).
- **Rebinding plumbing (leverage, do not hand-roll)** — `RoutingServiceProvider` rebinds `request` → `url->setRequest`; `PaginationServiceProvider` does `refresh('request', $paginator, 'setRequest')`. So `instance('request', $fresh)` on the sandbox auto-re-points `url` and `paginator` (PLAN §8 AUTO row). Do **not** manually re-point those.
- **Confirmed ABSENT before this job (sanity check on the dependency)** — `handleOctaneRequest`, `Str::flushCache`, `Application::__clone`, `Container::__clone` were all absent on the branch base. If they are *still* absent when you run, Phase 1 is not landed → stop and report which job is missing rather than spiking against an incomplete base.

## How to boot a real app once (the base template)

PLAN §6 "Boot (once per process)" is the model. Two options; **recommend (a)** for the spike.

**(a) Minimal inline boot (RECOMMENDED).** A full host app is a later *package* fixture (HANDOFF "Still Needed: Add a minimal real-L42x host-app fixture"); do not build it here. Construct the base directly, in-process, mirroring the `start.php` sequence just enough to warm the heavy singletons:

1. `$base = new Application;` — the ctor already binds `request`, `Illuminate\Container\Container`, and registers Event/Exception/Routing providers (`Application.php:111-154`).
2. Bind a minimal config so providers can boot. `$base['config']` must be a real `Config\Repository` (clone-safe, plain `$items` array) seeded with at least: `app.providers` (the provider set you load), `app.aliases` (can be empty/minimal), `app.debug`, `app.url`, `app.timezone`, `session.*` (driver `array`), `cache.*` (driver `array`), `database.*` (a `sqlite` `:memory:` connection or skip db routes entirely), `cookie.*`, `view.*`, `auth.*` (driver `eloquent` or `database`, plus a `users` provider). Keep drivers in-memory (`array` session/cache; sqlite `:memory:` db) so the spike is hermetic. Look at how `tests/Foundation/FoundationApplicationTest.php` and the existing test bootstrap (`phpunit.php`) construct/override `$app['config']` for the minimal-override idiom.
3. Run the load-bearing parts of the `start.php` sequence by hand: `$base->instance('app', $base)`; `Facade::clearResolvedInstances()`; `Facade::setFacadeApplication($base)`; `$base->registerCoreContainerAliases()`; `Request::enableHttpMethodParameterOverride()`; load the provider set (`$base->getProviderRepository()->load($base, $providers)` or `register()` the handful you need directly — Session, Cache, Database, Cookie, Auth, View, Translation, Encryption, Hashing, Filesystem, Routing, Pagination). You generally do **not** need the full `ProviderRepository` machinery for a spike — `register()` the providers you assert on.
4. `$base->boot();` (idempotent at `:596`). The clone will inherit `$booted = true` and never re-boot.
5. **Warm the heavy singletons** so the base is a pristine *template* and no service is first-resolved inside a sandbox (this is what defends the Change #1 `bindShared` path — spec §5): touch each of `config`, `db`, `cache`, `encrypter`, `files`, `hash`, `router`, `routes`, `session`, `translator`, `url`, `view` once (e.g. `$base['cache']; $base['session']; …`). Register a trivial route or two on `$base['router']` (e.g. a GET `/` closure that returns the bound `auth` user id or `config('spike.marker')`, and a route that *mutates* state — sets session, queues a cookie, logs in a user) so the requests have something to exercise. **`Container::setInstance()` is NOT called here** — the base stays a template, never served on directly; `setInstance($sandbox)` happens per-request in the swap.

**(b) Reuse an existing test fixture (only if one already exists).** The repo's `phpunit.php` bootstrap + `L4\Tests\BackwardCompatibleTestCase` build an app for the suite. If (and only if) that path yields a *fully booted, route-registered* app cheaply, you may reuse it. As confirmed, there is **no** dedicated reusable host-app fixture today, so (a) is expected. Do not invent one in `src/`/`tests/`.

> The base is **never served on directly** and `Container::setInstance` is not set to it (PLAN §6). Every request runs against a fresh `clone`.

## Per-request swap = implement spec §10 verbatim against `$sandbox = clone $base`

This is the heart of the spike. Implement spec §10's ordered protocol exactly; guard every re-point with `isset`/resolved checks (`$sandbox->resolved('x')` or `isset($sandbox['x'])`) so you only re-point services that were actually warmed. Sequence per request:

```php
// 0. CLONE — fresh sandbox from the pristine base.
$sandbox = clone $base;          // Application::__clone (Job 1.2) fixes 'app' + container self-refs;
                                 // inherits $booted=true; must NOT fatal on uninitialized $tags.

// 1. MAKE SANDBOX CURRENT (process-statics — NOT copied by clone). spec §10 step 1.
Facade::clearResolvedInstances();
Facade::setFacadeApplication($sandbox);
Container::setInstance($sandbox);            // only matters if anything calls getInstance()

// 2. ISOLATE MUTABLE VALUE SERVICES — clone config into the sandbox. spec §10 step 2 / PLAN §8 CLONE row.
$sandbox->instance('config', clone $base['config']);
$sandbox->instance('translator', clone $base['translator']);   // PLAN §8 translator row (CLONE) — isolate per-request setLocale()/$parsed; NOT optional, probe it (see leak assertions)

// 3. BIND THE FRESH REQUEST — fires rebinding('request') → url + paginator self-re-point. spec §10 step 3 / PLAN §8 AUTO row.
$sandbox->instance('request', $freshRequest);   // do NOT hand-roll url/paginator re-pointing

// 4. RE-POINT SHARED STATEFUL MANAGERS/SERVICES at the sandbox (Jobs 1.3/#3,#4,#6). spec §10 step 4 / PLAN §8 RE-POINT + FLUSH rows.
//    Guard each with isset/resolved — only re-point what was warmed.
if ($sandbox->resolved('db'))        $sandbox['db']->setApplication($sandbox);                       // + forgetConnections() if you don't keep PDO warm
if ($sandbox->resolved('auth'))      $sandbox['auth']->setApplication($sandbox)->forgetDrivers();    // fresh Guard reads new request/session
if ($sandbox->resolved('cache'))     $sandbox['cache']->setApplication($sandbox)->forgetDrivers();
if ($sandbox->resolved('session'))   $sandbox['session']->setApplication($sandbox)->forgetDrivers();
if ($sandbox->resolved('queue'))     $sandbox['queue']->setApplication($sandbox)->forgetConnections();
if ($sandbox->resolved('router'))    $sandbox['router']->setContainer($sandbox);                     // rebuilds cached ControllerDispatcher (#4)
if ($sandbox->resolved('view'))      $sandbox['view']->setContainer($sandbox)->share('app', $sandbox)->flushSections();
if ($sandbox->resolved('validator')) $sandbox['validator']->setContainer($sandbox);
if ($sandbox->resolved('cookie'))    $sandbox['cookie']->flushQueuedCookies();                       // FLUSH the shared queued-cookie bag (#3)
// (optional, Job 1.4) Str::flushCache(); EngineResolver::forget() — see PLAN §8 FLUSH(optional)

// 5. HANDLE via the STACKED kernel (cookie/session middleware), $catch=false, capture output. spec §10 step 5 / PLAN §6 step 6-7.
ob_start();
if (is_callable([$sandbox, 'handleOctaneRequest'])) {
    $response = $sandbox->handleOctaneRequest($freshRequest, false);     // Job 1.4 path (preferred)
} elseif (is_callable([$sandbox, 'getStackedClient'])) {
    $response = $sandbox->getStackedClient()->handle($freshRequest, HttpKernelInterface::MAIN_REQUEST, false);
} else {
    // NO-GO on criterion 5 — record it; do NOT silently fall back to bare handle() (that bypasses cookie/session middleware).
    $response = '<<STACKED KERNEL UNREACHABLE — Job 1.4 incomplete>>';
}
$output = ob_get_clean();

// 6. TERMINATE the sandbox (finish + shutdown + terminable middleware). spec §10 step 5 (cont.) / PLAN §6 step 8.
if ($response instanceof SymfonyResponse) $sandbox->terminate($freshRequest, $response);

// 7. DISCARD the sandbox + RESTORE base statics. spec §10 step 6 / PLAN §6 step 9.
unset($sandbox);                              // drop the only reference — binding-level mutations vanish for free
Facade::clearResolvedInstances();
Facade::setFacadeApplication($base);
Container::setInstance($base);
```

Wrap the whole body in your **own** `try/catch (\Throwable $e)` (worker-grade) so a throwable in one request neither leaks state nor kills the loop, and so you can characterize what reaches you under `$catch=false`. Run this body for each synthetic request in a `for`/`while` loop (the long-lived-loop stand-in) so the leak probe is genuinely cross-request.

> **Why each step:** spec §3 — clone isolates `$bindings`/`$instances` (transient per-request binds discarded for free); statics (`Facade::$app`, `Container::$instance`) are *not* cloned, so step 1 flips them and step 7 flips them back; shared *objects* (managers) live in `$instances` and are shared by handle, so step 4 re-points them; `config` is a shared mutable value, so step 2 clones it. Safe because one request is in flight at a time (spec §2).

## The synthetic request sequence (the leak probe)

A miniature of the package's blocking leak-gate (spec §12 / PLAN §12): drive a scripted sequence as **different identities** and assert no state from request N is visible in request N+1. **One assertion per PLAN §8 RE-POINT / CLONE / FLUSH row.** Minimum sequence (2 required, a 3rd recommended):

**Request A — the "dirtying" request (authenticated, mutates everything reachable):**
- Authenticates a user (e.g. hits a route that does `Auth::loginUsingId(1)` or `Auth::login($user)`).
- Writes session data (`Session::put('spike.secret', 'A-was-here')`, and flash input `Session::flashInput([...])`).
- Mutates config at runtime (`Config::set('spike.mutated', 'A')` / `config(['spike.mutated' => 'A'])`).
- Mutates the locale (`App::setLocale('fr')` / `$sandbox['translator']->setLocale('fr')`) — exercises the translator CLONE row.
- Queues a cookie (`Cookie::queue('spike_cookie', 'A-value')`).
- Binds a transient instance (`$sandbox->instance('spike.transient', 'A')`) to prove the FREE row.
- Capture: the response, the resolved `auth` user id, the bound `request` identity.

**Request B — the leak detector (anonymous, *different* identity):**
- Anonymous (no login). Hits a read route that *reports* what it sees.
- **Assert, one per §8 row:**
  - **AUTO (request/url/paginator):** `$sandboxB['request']` is the *B* request (not A's); `url()->current()`/`UrlGenerator` reflects B's request, not A's. (Confirms `instance('request')` rebinding fired.)
  - **CLONE (config):** `Config::get('spike.mutated')` is the **base default** (null / unset), **NOT** `'A'`. (Confirms `clone $base['config']` isolation — the central CLONE assertion.)
  - **CLONE (translator):** `App::getLocale()` is the **base default** locale, **NOT** `'fr'`. (Confirms `clone $base['translator']` isolation — the PLAN §8 translator row; **required, not optional**.)
  - **RE-POINT (auth):** `Auth::check()` is `false` and `Auth::user()` is `null` — B does **not** see A's logged-in user. (The headline identity-leak assertion.)
  - **RE-POINT (session):** `Session::get('spike.secret')` is `null` — B does not see A's session data; flashed input from A is gone (`Input::old(...)` / `Session::getOldInput()` empty).
  - **RE-POINT (cache):** a value A wrote to the array cache is not implicitly leaked into B's identity (with `array` driver + `forgetDrivers`, B gets a fresh store; assert the store object is fresh / A's per-request cache entry isn't masquerading as B's). Keep this assertion modest — array cache may legitimately persist on the *base*; the point is the manager is re-pointed at the sandbox and the request-scoped store is fresh.
  - **RE-POINT (db):** the `db` manager's `$app` is the sandbox, not the base (`$sandboxB['db']` resolves against B); if you kept a query log, it's empty for B. (Low-stakes for the spike if you skip db routes — note it as N/A-for-spike if so.)
  - **RE-POINT (queue):** `queue` manager re-pointed; no A connection state visible. (Often N/A-for-spike — note it.)
  - **RE-POINT (router):** the controller dispatcher resolves controllers from the **sandbox** container, not the base. Probe via a controller route (or assert `$sandboxB['router']`'s container identity is `$sandboxB`). This is the one real routing leak (#4).
  - **RE-POINT (view):** `View::shared('app')` is the sandbox; sections from A are flushed (no stale `@section` content bleeds into B).
  - **RE-POINT (validator):** `validator`'s container is the sandbox (only materially matters with class-based rule extensions; assert container identity).
  - **FLUSH (cookie):** B's queued-cookie bag does **not** contain A's `spike_cookie` — `Cookie::getQueuedCookies()` for B is clean. (Confirms `flushQueuedCookies()`.)
  - **FREE (bindings):** `$sandboxB->bound('spike.transient')` is `false` — A's transient bind died with A's sandbox. (The payoff of clone-discard.)
  - **FLUSH (Str cache), optional:** if Job 1.4's `Str::flushCache()` is exercised, assert it doesn't error and growth is bounded. Low urgency.
  - **N/A-for-spike (uploaded files, GC):** PLAN §8's `uploaded files` (SAPI upload shims) and the optional `garbage collection` row are worker/package-side (Octane package Phase 2/3) and out of this in-process spike's scope — record them as N/A in the per-row table so the §8 accounting is provably complete.

**Request C (recommended) — re-authenticate as a *second, different* user (id 2):** confirm B's anonymity didn't "stick" and that C sees *its own* identity (id 2, not A's id 1, not anonymous). This catches a re-point that resets-to-null but fails to re-bind correctly, and proves the loop is reusable across ≥3 requests.

Record each assertion's actual observed value next to PASS/FAIL in `RESULT.md`. A single FAIL on any RE-POINT/CLONE/FLUSH row is a **leak** → NO-GO, and the note must name the failing row + the Phase-1 change that owns it (e.g. "session leak → Job 1.3 `Support\Manager::forgetDrivers` / spec §7" or "config leak → spec §10 step 2 clone-config not applied").

## GO/NO-GO criteria (the five explicit pass conditions — the doc MUST evaluate each)

`RESULT.md` must list these five and mark each PASS / FAIL / ACCEPTED-RESIDUAL-RISK against actually-observed spike output. **GO requires all five satisfied** (or a documented residual risk explicitly signed off):

1. **Boots once, no fatal.** The base app constructs, providers register, `boot()` completes, and the heavy singletons warm without a fatal/exception. Evidence: the spike prints a "base booted" marker and the warmed-service list.
2. **`clone $base` does not fatal.** A bare `clone $base` succeeds — *specifically* confirming Job 1.2's `__clone` does not read the uninitialized typed `$tags` (`Container.php:108`) and correctly re-points `['app']`/`['Illuminate\Container\Container']` to the clone. Evidence: `($clone)['app'] === $clone` and the clone is not the base; no `Typed property ... must not be accessed before initialization` fatal.
3. **No cross-request leak across all §8 RE-POINT/CLONE/FLUSH concerns.** Every leak-probe assertion above passes for ≥2 requests as different identities (auth user, session, config mutation, bound request, flashed input, queued cookie, router/view/validator container identity, freed transient bind). Evidence: the per-row PASS table.
4. **No `exit`/`die` in the normal request path terminates the loop.** The loop completes all 2–3 requests; the worker-grade `try/catch` is never the reason the process ends. **Characterize** the exception-handler behavior under `$catch=false`: confirm a thrown exception in a handled request rethrows to *your* catch (it does **not** route through `$this['exception']->handleException()` when `$catch=false`) and does **not** `exit`; note that `dd()` (`helpers.php:523`) and user-code `exit` would still kill a real worker (the residual hazard Job 1.4's neutralization addresses). **Feed this characterization back into Job 1.4** (record it explicitly in `RESULT.md` under a "Feedback to Job 1.4" heading). Note: `Application::handle()` *also* rethrows when `runningUnitTests()` (i.e. `$this['env'] == 'testing'`), and `dispatch()`'s auto session-start is env-gated on `testing` — so run the spike with `app.env != 'testing'` (or start the session yourself and treat the rethrow as the expected `$catch=false` behavior), otherwise you cannot distinguish `$catch=false` behavior from testing-env behavior. Optional: include a deliberately-throwing route to observe the `$catch=false` path reaching your catch without killing the loop.
5. **The stacked kernel runs cookie/session middleware on the clone (not bypassed).** The request was served through `handleOctaneRequest($req, false)` or the public `getStackedClient()` path — i.e. `Cookie\Guard` → `Cookie\Queue` → `Session\Middleware` ran *on the sandbox*. Evidence: a `Set-Cookie` for the session/queued cookie appears on the response (proof Cookie\Queue ran), and the session middleware started/saved the session on the clone. If only bare `Application::handle()` was reachable, this is **FAIL** (bare `handle()` skips the stack — exactly the HANDOFF fallback caveat).

## Deliverables

1. **The GO/NO-GO note — `refactor-octane/artifacts/spike/RESULT.md`** (the acceptance artifact). It must contain:
   - A one-line verdict: **GO** or **NO-GO**.
   - The five criteria above, each PASS / FAIL / ACCEPTED-RESIDUAL-RISK with the observed evidence (printed marker, assertion value, response header, etc.).
   - The per-row leak-probe table (PLAN §8 row → assertion → observed → PASS/FAIL).
   - A "Feedback to Job 1.4" section with the characterized `exit`/exception-handler behavior under `$catch=false`.
   - A "Residual risk" section (anything not fully proven by an in-process spike vs. a real FrankenPHP loop — e.g. real superglobal marshalling, memory soak, multi-worker — explicitly out of this spike's scope and deferred to package Phase 4).
   - **If NO-GO:** the note must pinpoint *which* §8 row failed and *which* Phase-1 job/spec section owns the incomplete change (e.g. "NO-GO: session leak — Job 1.3 `forgetDrivers` missing/ineffective, spec §7"; or "NO-GO: criterion 5 — Job 1.4 stacked-kernel reach not landed, `handleOctaneRequest`/public `getStackedClient` not callable").
2. **The throwaway spike script(s)** under `refactor-octane/artifacts/spike/` (e.g. `spike.php` + any tiny helper). Plain CLI PHP, run with the project's PHP 8.3 (inside the Docker container per Job 0.1, or local 8.3 if available). It must `require` the project autoloader, build the base, run the loop, print clear per-assertion PASS/FAIL lines, and exit non-zero on any FAIL so a human/CI can read the result at a glance.

## New tests

**None in `tests/`.** This job adds no committed unit tests — it is a throwaway integration spike whose output is the GO/NO-GO note. The Phase-1 unit tests already landed with Jobs 1.1–1.4 (spec §12 tests 1–6); the *committed* cross-request leak harness is the **package's** Phase-4 job (PLAN §4.1, spec §12 "blocking gate"), not this repo's. This spike is the dry-run that de-risks that package harness.

## Acceptance gate

- `refactor-octane/artifacts/spike/RESULT.md` exists and evaluates **all five** GO/NO-GO criteria against an **actually-run** spike (not a paper analysis) — each marked PASS / FAIL / ACCEPTED-RESIDUAL-RISK with observed evidence.
- **GO** is recorded only if all five are satisfied, *or* a residual risk is documented and explicitly signed off in the note.
- The per-§8-row leak-probe table is present and every RE-POINT/CLONE/FLUSH row is accounted for (PASS, or FAIL with the owning Phase-1 job named, or a justified N/A-for-spike).
- The spike script(s) exist under `refactor-octane/artifacts/spike/` (or the chosen tmp path) and reproduce the result when re-run.
- **No file under `src/` was modified.** (This job consumes Phase 1; it does not edit it.)

## Out of scope / do NOT do

- Do **not** build the production `Worker` or `SandboxPreparer` — those are the package, Phase 2 (PLAN §10 Phase 2; HANDOFF shows them already scaffolded in `../octane-rewrite-L42x`). The spike is minimal and throwaway.
- Do **not** commit the spike to `src/` (or anywhere the framework autoloads/ships). Keep it in `refactor-octane/artifacts/spike/` or a tmp path.
- Do **not** port any FrankenPHP server bits — no `frankenphp_handle_request`, no Caddyfile, no `ServerStateFile`, no `Request::createFromGlobals` marshalling of real superglobals. Synthesize requests in-process (e.g. `Illuminate\Http\Request::create('/path', 'GET', …)`); the FrankenPHP runtime contract is the package's Phase 3.
- Do **not** call `Application::run()` (it `send()`s the response and you can't capture cleanly) — drive `handleOctaneRequest`/`getStackedClient` and `ob_start`/`ob_get_clean` yourself, per spec §10 step 5.
- Do **not** modify any Phase-1 source to "make the spike pass." If something is missing/broken, that is the NO-GO finding — name the gap, don't patch `src/`.
- Do **not** introduce `Illuminate\Contracts\*`, `Http\Kernel`, or `bootstrapWith()` (out of scope per the fork charter, spec §11).
- Do **not** hand-roll `url`/`paginator` request re-pointing — the existing `rebinding`/`refresh` plumbing does it on `instance('request')` (spec §10, PLAN §8 AUTO row).
- Do **not** deep-clone the application or any manager. Clone is shallow by design; managers are *re-pointed*, not cloned (spec §6/§7).
- Do **not** run git, and do **not** edit other `refactor-octane/` docs or any test/config file.

## Verification commands

```sh
# 0. PRECONDITION — confirm Phase 1 is actually landed (these must all be PRESENT now):
grep -n "function __clone" src/Illuminate/Foundation/Application.php          # Job 1.2
grep -n "function setApplication\|function forgetDrivers" src/Illuminate/Support/Manager.php   # Job 1.3
grep -n "function setContainer" src/Illuminate/Routing/Router.php src/Illuminate/Validation/Factory.php  # Job 1.3
grep -n "function flushQueuedCookies" src/Illuminate/Cookie/CookieJar.php     # Job 1.3
grep -n "function handleOctaneRequest\|public function getStackedClient" src/Illuminate/Foundation/Application.php  # Job 1.4 (at least one)
grep -n "function flushCache" src/Illuminate/Support/Str.php                  # Job 1.4
# Confirm bindShared no longer wraps via share() (Job 1.1):
grep -n "function bindShared" src/Illuminate/Container/Container.php          # body should bind directly, not $this->share(...)
# If any required one is ABSENT → STOP, report which Phase-1 job is missing; do not spike against an incomplete base.

# 1. Confirm the full suite is green on PHP 8.3 (Job 0.1 baseline + Jobs 1.1-1.4 kept it green):
make composer-test            # must exit 0 before trusting the spike

# 2. Create the artifacts dir and run the spike (inside the 8.3 container, per Job 0.1):
mkdir -p refactor-octane/artifacts/spike
make bash                     # shell into the container, then:
php refactor-octane/artifacts/spike/spike.php
# Expect: "base booted" marker, per-request per-assertion PASS lines, a final GO/NO-GO summary,
# and exit code 0 on all-PASS / non-zero on any FAIL.

# 3. Confirm the deliverable exists and reads as a verdict:
test -f refactor-octane/artifacts/spike/RESULT.md && echo "RESULT.md present"
grep -iE "^#|GO|NO-GO|PASS|FAIL" refactor-octane/artifacts/spike/RESULT.md | head -40

# 4. Confirm NO src/ files were touched by this job:
git status --porcelain src/   # must print nothing
```

---

**Definition of done:** `refactor-octane/artifacts/spike/RESULT.md` records a GO/NO-GO verdict with all five criteria evaluated against an actually-run in-process spike (boot-once + clone-per-request + §10 re-point + stacked-kernel handling for 2–3 different-identity requests), GO only if all five pass (or a residual risk is signed off), the per-§8-row leak table is complete, the `exit`/`$catch=false` behavior is characterized and fed back to Job 1.4 — and no `src/` file was modified.
