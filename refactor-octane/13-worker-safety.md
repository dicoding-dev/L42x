# Job 1.4 — Worker-safety changes (reachable stacked kernel, exit neutralization, `Str::flushCache`)

- **Effort (for the executing agent):** HIGH — the exit-guard design + the stacked-kernel
  shape decision carry the plan's one genuine open question (PLAN §11.2). The code is small;
  the *judgement* (what to guard, how minimal, what to leave to the spike) is the hard part.
- **Depends on:** Job 1.1 (Change #1 `bindShared`) landed and green. Otherwise **parallel**
  to 1.2 / 1.3 (this job touches different files). **Required before the 0.3 feasibility
  spike** — the spike consumes `handleOctaneRequest()` and the exit-neutralization findings.
- **Spec refs:**
  - PLAN.md §9 items 1–5 (worker safety + process-global state):
    `/home/alex/WORKSPACE/DICODING_PLAYGROUND/octane-rewrite-L42x/PLAN.md`
  - PLAN.md §6 (worker lifecycle hazards), §11.2 / §11.4 / §11.5 (risks/open questions).
  - Refactor spec §11 (what we are NOT changing):
    `/home/alex/WORKSPACE/DICODING_PLAYGROUND/octane-rewrite-L42x/L42X-REFACTOR-FOR-OCTANE-SANDBOX.md`
  - HANDOFF.md "External L42x Prerequisites" — the package already **probes** for
    `handleOctaneRequest()` and a public stacked-kernel accessor via `is_callable()` and
    **falls back to `Application::handle()`** when neither is callable:
    `/home/alex/WORKSPACE/DICODING_PLAYGROUND/octane-rewrite-L42x/HANDOFF.md:137-138,181-183`.
  - Verified anchors (re-confirmed against this branch at authoring time — re-check line
    numbers at edit time, cosmetic drift is possible):
    - `src/Illuminate/Foundation/Application.php`: imports incl. `MiddlewareBuilder` :5,
      `Facade` :12, `SymfonyRequest` :22, `SymfonyResponse` :23; class decl :26
      (`extends Container implements HttpKernelInterface, TerminableInterface,
      ResponsePreparerInterface`); `run()` :650-659; `getStackedClient()` :666-678
      (**`protected`**); `mergeCustomMiddlewares()` :686-696; `handle()` :750-772
      (rethrows when `! $catch || runningUnitTests()`); `dispatch()` :780-795;
      `terminate()` :804-809; `refreshRequest()` :817-822.
    - `src/Illuminate/Foundation/Http/MiddlewareBuilder.php:42-64` — `resolve()` returns a
      `StackedHttpKernel`; the stack is `Cookie\Guard` → `Cookie\Queue` → `Session\Middleware`.
    - `src/Illuminate/Cookie/Queue.php:45-55` — `handle()` calls the inner kernel then copies
      `$this->cookies->getQueuedCookies()` onto `$response->headers->setCookie(...)`. **This is
      the observable proof the stack ran** (the test anchor for part (a)).
    - `src/Illuminate/Exception/Handler.php` — `handleException()` :144-163 **returns** a
      Response (no `exit`/`die`); `handleUncaughtException()` :171-173 and `handleShutdown()`
      :181-194 do `->send()` but are only wired via `set_exception_handler` /
      `register_shutdown_function` (`register()` :80-87). **No bare `exit`/`die` anywhere in
      this file.**
    - `dd()` does a bare `die`: `src/Illuminate/Support/helpers.php:521-524`.
    - `src/Illuminate/Support/Str.php`: `protected static array $snakeCache = []` :17,
      `$camelCache` :24, `$studlyCache` :31; `camel()` :52-60, `snake()` :372-389,
      `studly()` :~418 (keys: `camel` on `$value`, `snake` on `$value.$delimiter`,
      `studly` on `$value`).
    - `src/Illuminate/Routing/UrlGenerator.php:228` — `forceSchema()` (**sic, misspelled**;
      `forceScheme()` does **not** exist).
- **Allowed scope (files this job may modify):**
  - `src/Illuminate/Foundation/Application.php` — add **one** public method
    `handleOctaneRequest()` (part a); add the worker-mode flag + accessor (part b, minimal).
  - `src/Illuminate/Support/Str.php` — add `flushCache()` (part c).
  - **New** test files under `tests/` (see "New tests").
  - **Nothing else.** Parts (d) and (e) are explicitly **not** code changes here (see below).
  - Do **not** edit `Exception/Handler.php` unless the spike (Job 0.3) proves a concrete exit
    that cannot be defended package-side — and if so, that edit returns here as a follow-up,
    gated on a written spike finding. Default for THIS job: no `Handler.php` edit.

## Objective

Add the **additive, dormant** framework affordances a long-lived Octane worker needs to drive
L42x safely, **without changing stock mod_php/fpm behavior by a single byte**. Concretely:

1. **(a, SETTLED)** Make the cookie/session middleware stack reachable from a worker that must
   capture (not send) the response, via a new public `handleOctaneRequest(SymfonyRequest,
   $catch = false)` that mirrors `run()` minus `send()`/`terminate()`.
2. **(b, OPEN — investigate, then minimal guard)** Add a worker-mode flag (default **OFF**)
   that lets the framework prefer return/throw over `exit` in the (small) set of spots that
   would kill the request loop — *after* characterizing, jointly with the 0.3 spike, what
   actually exits under `handle(..., $catch = false)`. Smallest guard that makes the spike
   pass; leave room to refine.
3. **(c, SETTLED)** Add `Str::flushCache()` to bound the three process-global string caches.
4. **(d)** `Translator::flushParsedKeys()` — **OPTIONAL, likely SKIP** (documented, not built).
5. **(e)** `forceSchema()` spelling — **documentation note for the worker author, NOT a code
   change.**

"Done" = the new method(s) exist + are proven dormant (full suite unchanged & green) + the new
tests pass + a written **exit-neutralization investigation record** that doubles as input to
the 0.3 spike.

## Context / why

The Octane worker boots one `Application` per process, then `clone`s it per request into a
sandbox and serves the request against the clone (PLAN §6). Three worker-only needs are not
met by stock 4.2:

- **The stack only exists inside `run()`.** `run()` (`Application.php:650`) builds the stacked
  client and immediately `send()`s + `terminate()`s. `handle()` (`:750`) is the bare kernel —
  it runs routing/dispatch but **skips the Cookie\Guard / Cookie\Queue / Session\Middleware
  stack**, so a worker calling `handle()` alone silently loses cookie encryption, queued
  cookies, and session persistence. The worker must run the *stack* but must **not** `send()`
  (FrankenPHP captures output itself) and must own the try/catch. `getStackedClient()` is
  `protected` (`:666`) → unreachable. (PLAN §6 "Critical lifecycle hazards"; §11.4.)

- **`exit`/`die` in the request path kills the whole worker process**, not just one request
  (PLAN §6, §11.2). The fear is larger than the reality here — see the verified grounding
  below — but it is the plan's one genuinely open question, so this job *characterizes* it and
  adds the *smallest* guard that lets the spike proceed, deferring any larger guard until the
  spike shows it is actually needed.

- **Process-global statics survive `clone`.** `Str`'s three caches are
  `protected static` (`Str.php:17/24/31`); a shallow `clone $app` cannot isolate them. They are
  bounded by the number of distinct inputs (low risk), but a cheap, dormant flush keeps growth
  bounded over a long-lived worker (PLAN §9.3, §8 "Str static caches" row → FLUSH bucket).

**Verified grounding on the exit surface (this is what makes part (b) "smaller than feared"):**
- `Exception\Handler::handleException()` (`Handler.php:144-163`) **returns** a Symfony Response.
  It does **not** `exit`/`die`.
- `Handler` only terminates via the global hooks it registers — `set_exception_handler(
  handleUncaughtException)` and `register_shutdown_function(handleShutdown)` (`register()`
  :80-87) — and even those just `->send()`, they don't `exit`. They fire **only** for a
  truly-uncaught throwable / a fatal at shutdown. The worker drives the kernel with
  **`$catch = false`** and wraps everything in its own try/catch, so a normal exception never
  reaches `handleUncaughtException`. (`register()` also skips the shutdown handler entirely
  when `environment == 'testing'`.)
- The concrete remaining exit risks in the request path are therefore: **`dd()`** (bare `die`,
  `helpers.php:523`), arbitrary **user/3rd-party `exit`/`die`**, and the **shutdown function**
  living for the life of the worker. `dd()` is *developer tooling that legitimately
  terminates*; we do not neutralize it. The worker's `$catch = false` + own try/catch is the
  **primary** defense; the framework flag (part b) is **secondary**.

A stock single-request app never enters worker mode: it calls `run()` (which never touches the
new method) and never sets the flag. That is the behavior-preservation contract (README
"Governing principle"; spec §1, §12).

---

## Exact changes

### (a) — Reachable stacked kernel via `handleOctaneRequest()`  [SETTLED]

**Decision (record this in the commit / PR body):** add a new public method
`Application::handleOctaneRequest(SymfonyRequest $request, $catch = false)` rather than merely
widening `getStackedClient()` to `public`.

**Why this option over the alternative:**
- The package **already probes for this exact name** via `is_callable($app, 'handleOctaneRequest')`
  and falls back to bare `Application::handle()` otherwise (HANDOFF.md:137-138,181-183). Adding
  it lights up the package's intended fast path with zero package change.
- It encapsulates the "build stack, handle, **return without sending**, with `$catch = false`"
  contract in one place, so the worker can't accidentally `send()` or re-`terminate()`.
- **Alternative considered (and rejected as the primary):** simply change
  `getStackedClient()` from `protected` to `public`. This is even smaller and the package also
  probes for a "public stacked-kernel accessor" as a secondary (HANDOFF.md:181-183). Downside:
  it leaks an internal builder type to callers and pushes the "don't send / catch=false /
  terminate separately" responsibility onto every worker. **You MAY additionally widen
  `getStackedClient()` to `public` if trivial** (it is itself dormant and additive), but
  `handleOctaneRequest()` is the contract the executor must deliver. If you widen it too, note
  both in the test ("either reachable accessor works"); do not make it the only deliverable.

**What it must reproduce.** Read `run()` (`:650-659`) and `getStackedClient()` (`:666-678`) and
reproduce the stack build **faithfully** — same `MiddlewareBuilder` push order, same
`mergeCustomMiddlewares()` call, same `resolve($this)`. Current `run()` for reference:

```php
public function run(SymfonyRequest $request = null)
{
    $request = $request ?: $this['request'];

    $response = with($stack = $this->getStackedClient())->handle($request);

    $response->send();                       // <-- worker must NOT do this

    $stack->terminate($request, $response);  // <-- worker calls terminate() itself, separately
}
```

**The method to add** (place it adjacent to `run()` / `getStackedClient()`, e.g. right after
`getStackedClient()` around `:678`, so the stack-related methods stay together):

```php
/**
 * Handle the given request through the full stacked HTTP kernel and return the
 * response WITHOUT sending it. For long-lived (Octane) workers that capture output
 * themselves and own the try/catch + terminate() lifecycle.
 *
 * Mirrors run() minus $response->send() and minus terminate(); drives the same
 * Cookie\Guard / Cookie\Queue / Session\Middleware stack as run(), via getStackedClient().
 *
 * Additive + dormant: no stock 4.2 path calls this (stock requests use run()).
 *
 * @param  \Symfony\Component\HttpFoundation\Request  $request
 * @param  bool  $catch
 * @return \Symfony\Component\HttpFoundation\Response
 *
 * @throws \Throwable
 */
public function handleOctaneRequest(SymfonyRequest $request, $catch = false): SymfonyResponse
{
    $stack = $this->getStackedClient();

    return $stack->handle($request, HttpKernelInterface::MAIN_REQUEST, $catch);
}
```

Notes the executor MUST honor:
- **Use `$catch = false` by default.** With `$catch = false`, `Application::handle()`
  (`:750-772`) **re-throws** instead of routing the exception into
  `$this['exception']->handleException()` — so the framework does **not** swallow + render the
  error; the worker owns the try/catch and decides the 500. This is deliberate and is half the
  exit defense (part b). Do **not** hardcode `true`.
- **Return the response; do NOT call `send()` and do NOT call `terminate()`** inside this
  method. The worker calls `$sandbox->terminate($request, $response)` itself (PLAN §6 step 8)
  after capturing output. Keeping `terminate()` out of this method is what lets the worker
  interleave output capture between handle and terminate.
- `StackedHttpKernel::handle()` accepts `($request, $type, $catch)` and threads `$catch`
  through every middleware down to `Application::handle()` (verified via
  `Cookie\Queue::handle()` `:45-47`, which forwards `$type`/`$catch` to the inner kernel).
  Passing `$catch` through is therefore sufficient; you do **not** need to re-wrap exceptions
  here.
- Do **not** add `refreshRequest()` / request binding inside this method — the worker binds the
  request on the sandbox (`instance('request', …)`) before calling this, and
  `Application::handle()` already re-runs `refreshRequest()`/`boot()` internally. Mirror `run()`
  exactly: build stack, handle, return.
- The required imports (`MiddlewareBuilder` :5, `SymfonyRequest` :22, `SymfonyResponse` :23,
  `HttpKernelInterface` :18) are **already present** at the top of `Application.php`. Add no new
  imports for this part.

### (b) — Exit/die neutralization  [OPEN — investigate first, then minimal guard]

This part has two deliverables: **(b1) a written investigation record** and **(b2) the smallest
additive guard that makes the 0.3 spike pass.** Do (b1) before finalizing (b2).

**(b1) Investigation (this doubles as input to the 0.3 spike — write it up).** Characterize
*exactly* what can `exit`/`die` under `handle(..., $catch = false)` driven by
`handleOctaneRequest()`. Start from the verified grounding above and confirm/extend it:
- Confirm `Exception\Handler` does not `exit`/`die` on the `$catch = false` path (it re-throws
  from `Application::handle()` :762/:768 before `handleException()` is ever reached).
- Confirm the only framework `die` reachable in a request is `dd()` (`helpers.php:523`) — search
  the request-path packages (`Foundation`, `Routing`, `Session`, `Cookie`, `View`, `Http`,
  `Exception`) for `exit`/`die`/`dd(`/`dump(`. Record every hit and whether it is reachable
  under normal dispatch with `$catch = false`.
- Note the **shutdown function** (`register_shutdown_function(handleShutdown)`,
  `Handler.php:116`): it is registered once at boot and lives for the worker's lifetime. It
  `->send()`s on a fatal. It does not `exit`, but it WILL try to emit a second response at
  process shutdown — flag this as a worker concern (the worker may want to suppress/observe it),
  but it is **not** something to "fix" in this job; record it for the spike.
- Frame `dd()`/`dump()` as **developer tooling that legitimately terminates the script** — we do
  **not** guard it. State this explicitly so the executor doesn't try to neutralize `dd()`.

**Deliverable (b1):** a short "Exit-neutralization findings" section (in the PR/commit body and
echoed into the 0.3 spike doc) listing every reachable exit, whether `$catch = false` + the
worker's own try/catch already defends it, and what (if anything) remains for a framework guard.

**(b2) The minimal guard.** Add an **opt-in, default-OFF** worker-mode marker on `Application`,
plus accessors, so framework code *could* prefer return/throw over `exit` in worker mode — but
**only wire it into a spot the spike proves actually exits and cannot be defended package-side.**
If (b1) + the spike find that `$catch = false` + the worker try/catch fully cover the request
path (the expected outcome), then the guard ships as the **flag + accessors only** (dormant
scaffolding the spike/worker can read), and **no framework branch is changed**. Do not invent a
branch to guard.

Add the flag and accessors to `Application.php` (near the other `protected` state, e.g. after
`$middlewares` ~`:75`, and the methods near `run()`):

```php
/**
 * Indicates whether the application is running inside a long-lived (Octane) worker.
 *
 * Defaults to false. A stock mod_php/fpm request NEVER sets this, so every guarded
 * branch is dormant and 4.2 behavior is byte-for-byte unchanged. Set by the Octane
 * worker on the base app (and inherited by clones) so framework code can prefer
 * returning/throwing over exit() in the (few) spots that would kill the request loop.
 *
 * @var bool
 */
protected $inOctane = false;

/**
 * Determine if the application is running inside an Octane worker.
 *
 * @return bool
 */
public function runningInOctane()
{
    return $this->inOctane;
}

/**
 * Flag the application as running inside an Octane worker (or clear the flag).
 *
 * Additive + dormant: no stock 4.2 path calls this.
 *
 * @param  bool  $value
 * @return $this
 */
public function setRunningInOctane($value = true)
{
    $this->inOctane = $value;

    return $this;
}
```

Guard-design rules the executor MUST follow:
- **Default OFF.** `$inOctane = false` as the property initializer. A stock request never calls
  `setRunningInOctane()`, so `runningInOctane()` is always `false` outside a worker. This is the
  behavior-preservation guarantee — assert it in the test.
- **`$inOctane` is a plain `bool` property**, so a shallow `clone $app` copies it by value
  (worker sets it on the base; every sandbox clone inherits `true`). Do not make it `static`.
- **Smallest possible footprint.** Ship the flag + accessors. Add **at most one** guarded branch,
  and only if the spike produces a concrete, reproducible exit on the `$catch = false` request
  path that the worker's own try/catch cannot catch (none is expected). If you add a branch, it
  must be of the form `if ($this->runningInOctane()) { throw …/return …; } <existing exit
  unchanged>;` so the stock path is literally the same statements as today.
- **Do NOT touch `dd()`/`dump()`** (developer tooling) and **do NOT edit `Exception\Handler`**
  in this job (its core path doesn't exit; see b1). If the spike later proves a Handler exit is
  unavoidable, that becomes a separate, spike-justified follow-up edit — out of scope for the
  first pass of this job.
- Prefer an **alternative marker** only if cleaner for the package: the package could instead
  bind `$app->instance('octane', true)` and framework code check `$this->bound('octane')`.
  Pick **one** mechanism (the `$inOctane` property is recommended because it survives `clone`
  by value and needs no container lookup). Document which you chose and why.

**If the guard ends up purely package-side** (the likely outcome): keep the flag + accessors
(they are harmless dormant scaffolding the worker reads to decide its own behavior), document in
(b1) that no framework branch was needed, and **drop the part-(b) "ON path" test** (see New
tests) — replace it with a one-liner test that `runningInOctane()` defaults to `false` and flips
with `setRunningInOctane()`.

### (c) — `Str::flushCache()`  [SETTLED]

Add to `src/Illuminate/Support/Str.php` (place it near the cache property declarations or
adjacent to `snake()`/`camel()`/`studly()`; it is static like its siblings):

```php
/**
 * Flush the cached snake-, camel-, and studly-cased strings.
 *
 * Process-global static caches are not isolated by clone; an Octane worker calls this
 * to bound their growth across requests. Additive + dormant: no stock 4.2 path calls it.
 *
 * @return void
 */
public static function flushCache()
{
    static::$snakeCache  = [];
    static::$camelCache  = [];
    static::$studlyCache = [];
}
```

Match the existing brace/indentation style of the file (tabs, Allman braces as used elsewhere in
`Str.php`). Do not change the visibility of the cache properties.

### (d) — `Translator::flushParsedKeys()`  [OPTIONAL — likely SKIP, do not build]

Mentioned for completeness only. It would reset the inherited `$parsed` array on the translator
**only if** the worker resets the translator *in place*. The plan does **not**: per spec §8 /
§10 the worker **clones config and translator into the sandbox** (`instance('config', clone
$base['config'])`, translator likewise), so per-request mutations die with the clone and there
is nothing to flush in place. **Do not add this method** unless a later decision switches the
translator to reset-in-place. Record "skipped — translator is cloned into the sandbox, not reset
in place (spec §8 CLONE bucket)" in the doc.

### (e) — `forceSchema()` spelling gotcha  [DOCUMENTATION NOTE — NOT a code change]

`UrlGenerator::forceSchema()` (`src/Illuminate/Routing/UrlGenerator.php:228`) is the Laravel-4.2
**misspelling**; `forceScheme()` does **not** exist in this fork. Any HTTPS-enforcement reset the
**worker author** writes must call `forceSchema()` or it will silently no-op (PLAN §9.5, §11.5).
**Make no L42x edit for this.** Just include a clearly-labeled "Worker-author note" in the
findings/PR body so the package side doesn't get bitten.

### EXPLICITLY NOT in this job — D4 event-dispatch shim

Do **NOT** add any event-dispatch bridge to L42x. The D4 object→string event shim is the
**package's** `DispatchesEvents` (already present per HANDOFF.md §"Phase 2"), and L42x's
string-keyed `Dispatcher::fire()` already exists. **No L42x change for D4.** (PLAN.md
"Boundary"; spec §11.) State this in the PR body so a future reader doesn't try to add it here.

---

## New tests

Place tests in L42x's `tests/`, matching the existing convention. Two anchors to follow:
- `tests/Foundation/FoundationApplicationTest.php` — class has **no namespace**, extends
  `L4\Tests\BackwardCompatibleTestCase`, uses `Mockery as m`, `tearDown(): void { m::close(); }`,
  and mocks container bindings directly (`$app['router'] = m::mock('StdClass'); …`). Stubs are
  declared as plain classes at the bottom of the same file.
- `tests/Support/SupportStrTest.php` — class has **no namespace**, extends
  `PHPUnit\Framework\TestCase`, calls `Str::…` statically.

Add these tests (extend the existing files where natural, or add a sibling test file in the same
directory — match whichever the repo prefers; the existing files are the safer home):

**Test 1 — stacked kernel reachable & not sent (part a).** In
`tests/Foundation/FoundationApplicationTest.php` (or a new
`tests/Foundation/FoundationApplicationOctaneTest.php` with the same base class & conventions):
- Build a real `new Application`, then bind the middleware-stack dependencies the
  `getStackedClient()` build needs: `$app['encrypter']`, `$app['cookie']`, `$app['session']`
  (and `session.reject` is read only if `bound`, so leave it unbound). Use a real
  `Illuminate\Cookie\CookieJar` for `$app['cookie']` so you can queue a cookie on it. Mock or
  stub `$app['router']` so `dispatch()` returns a known `Illuminate\Http\Response` (mirror
  `testHandleRespectsCatchArgument()`'s `$app['router'] = m::mock(...)` pattern, but
  `->andReturn($response)` instead of throwing). `$app['session']` can be a Mockery mock whose
  `Session\Middleware` interactions are satisfied (start/save), or use a real array-driver
  session — pick the lighter path that the existing session tests already demonstrate.
- **Assert the stack actually ran:** queue a cookie via
  `$app['cookie']->queue($app['cookie']->make('octane_probe', 'v'))` **before** the call, invoke
  `$response = $app->handleOctaneRequest($request)`, and assert the returned response's headers
  carry the `octane_probe` cookie (`$response->headers->getCookies()` contains it). This proves
  `Cookie\Queue::handle()` (`Cookie/Queue.php:49-51`) ran — i.e. the middleware stack, not bare
  `handle()`, was used. (Bare `handle()` would return a response with **no** queued cookie.)
- **Assert it did NOT send:** the test must complete without emitting headers/output. Since
  PHPUnit runs in CLI, simply not calling `send()` is sufficient; do **not** assert on
  `headers_sent()`. Optionally also assert the return type
  (`assertInstanceOf(Symfony\Component\HttpFoundation\Response::class, $response)`).
- **Contrast (optional but recommended):** call bare `$app->handle($request)` with a freshly
  re-primed cookie jar and assert the queued cookie is **absent** from that response, making the
  stack-vs-bare difference explicit.
- If you also widened `getStackedClient()` to `public`, add a one-line assertion that
  `$app->getStackedClient()` returns an object implementing
  `Symfony\Component\HttpKernel\HttpKernelInterface` (the `StackedHttpKernel`).

**Test 2 — `Str::flushCache()` empties the three caches (part c).** In
`tests/Support/SupportStrTest.php`:
- Prime all three caches: `Str::snake('FooBar'); Str::camel('foo_bar'); Str::studly('foo_bar');`.
- Read the three `protected static` properties via reflection and assert each is **non-empty**
  before the flush (sanity), then call `Str::flushCache()`, then assert each is `[]` after.
  Example reflection helper:
  ```php
  $read = function ($name) {
      $r = new ReflectionProperty(Illuminate\Support\Str::class, $name);
      $r->setAccessible(true);
      return $r->getValue();
  };
  // ... prime ...
  $this->assertNotEmpty($read('snakeCache'));
  Illuminate\Support\Str::flushCache();
  $this->assertSame([], $read('snakeCache'));
  $this->assertSame([], $read('camelCache'));
  $this->assertSame([], $read('studlyCache'));
  ```
- Because the caches are `static` (shared across tests in the process), **flush at the end** (or
  in `tearDown`) so this test doesn't perturb others; this is itself a small demonstration of
  why the worker needs the method.

**Test 3 — exit guard / worker-mode flag (part b).** Design the assertion around whatever
minimal guard part (b) actually lands:
- **If a framework branch was added** (only if the spike forced it): with the worker-mode flag
  **OFF** (default), assert the guarded code path behaves exactly as stock 4.2 (the existing
  behavior is unchanged — ideally reuse/extend the existing test that already covers that path);
  with the flag **ON** (`$app->setRunningInOctane()`), assert that same path now returns/throws
  instead of terminating. (You cannot meaningfully assert "did not `exit`" directly — design the
  guard so the ON path takes an observable return/throw you *can* assert, e.g. it throws a
  specific exception the test catches.)
- **If the guard ended up purely package-side** (the expected outcome — no framework branch):
  **drop the ON/OFF behavioral test** and instead add a tiny test that `runningInOctane()`
  returns `false` on a fresh `new Application`, returns `true` after
  `setRunningInOctane()`, and that the flag is **copied by value across `clone`**
  (`$clone = clone $app; $app->setRunningInOctane(); assert (clone made before) stays false` —
  i.e. confirm it's an instance property, not static). Document in the test's docblock that the
  exit defense is package-side (`$catch = false` + the worker's own try/catch) per the part-(b)
  findings.

**Dormancy proof (all parts).** The whole existing suite passing **unchanged** is the proof that
`handleOctaneRequest()`, the `$inOctane` flag/accessors, and `Str::flushCache()` are dormant in
normal flow (nothing stock calls them). Do not add a bespoke "dormancy" test beyond the
flag-default assertion in Test 3.

---

## Acceptance gate

1. **Full existing suite green, unchanged** — the behavior-preservation contract. Run via
   `make composer-test` (Docker, PHP 8.3) — see "Verification commands". No existing test may be
   modified to pass (extending a file with new test methods is fine; altering an existing
   assertion is not).
2. **New tests pass** — Test 1 (stacked kernel reachable + queued cookie present + not sent),
   Test 2 (`Str::flushCache()` empties all three caches), Test 3 (flag default OFF; ON-path
   return/throw **or**, if package-side, the flag default/clone-by-value test).
3. **Written exit-neutralization investigation record (part b1)** — a short "Exit-neutralization
   findings" section in the PR/commit body enumerating every reachable `exit`/`die` on the
   `$catch = false` request path, whether `$catch = false` + the worker try/catch already
   defends it, the shutdown-function note, and the explicit "`dd()`/`dump()` are developer
   tooling — not guarded" statement. **This record is a required deliverable and is consumed by
   the 0.3 spike.**
4. **Decision record** — one short paragraph stating: chose `handleOctaneRequest()` over public
   `getStackedClient()` (and why); chose `$inOctane` property over a bound `'octane'` marker (and
   why); whether any framework guard branch was added (and the spike finding that justified it,
   or "none — package-side defense suffices").
5. **One job = one logical commit**, scope `octane` (confirm interactively). Do not amend across
   jobs.

---

## Out of scope / do NOT do

- **No `Illuminate\Contracts\*`, no `Http\Kernel`, no `bootstrapWith()`** — out of scope per the
  fork charter (PLAN §"Boundary", spec §11).
- **No D4 event shim in L42x** — that's the package's `DispatchesEvents`; `Dispatcher::fire()`
  already exists. (See "EXPLICITLY NOT in this job".)
- **Do NOT neutralize `dd()`/`dump()`** — developer tooling that legitimately terminates.
- **Do NOT edit `Exception/Handler.php`** in this job — its core render path returns a Response,
  it doesn't `exit`. Any Handler change is a separate, spike-justified follow-up only.
- **Do NOT build `Translator::flushParsedKeys()`** — the translator is cloned into the sandbox,
  not reset in place (part d).
- **Do NOT edit `UrlGenerator`** — the `forceSchema()` spelling is a worker-author note, not a
  fix (part e).
- **Do NOT change `run()`, `handle()`, `getStackedClient()`'s existing body, or `terminate()`**
  beyond optionally widening `getStackedClient()`'s visibility. `handleOctaneRequest()` is
  **additive** — it does not modify the stock path.
- **Do NOT** add request binding / `send()` / `terminate()` inside `handleOctaneRequest()` — the
  worker owns those.
- **Do NOT** make `$inOctane` static, and do not have `__clone` touch it (it copies by value for
  free; `Application::__clone` from Job 1.2 must not reference it).
- **No opportunistic cleanup / refactoring / new abstractions.** Minimal change only; if tempted
  to touch anything outside the Allowed scope, stop and flag it (README "Minimal change").

---

## Verification commands

```sh
# Canonical full suite (Docker, PHP 8.3 — requires Job 0.1's image bump to be in place):
make composer-test          # = composer test = ./vendor/bin/phpunit --colors=always -c phpunit.xml

# Iterate inside the container:
make bash

# Targeted runs (inside the container, or locally if PHP 8.3 is available):
vendor/bin/phpunit -c phpunit.xml tests/Foundation/FoundationApplicationTest.php
vendor/bin/phpunit -c phpunit.xml tests/Support/SupportStrTest.php
# (or the new sibling files, if you added them)

# Confirm the additions exist and nothing else in src/ changed:
git -C /home/alex/WORKSPACE/DICODING_PLAYGROUND/L42x diff --stat
git -C /home/alex/WORKSPACE/DICODING_PLAYGROUND/L42x grep -n "handleOctaneRequest\|runningInOctane\|flushCache" -- src/
```

(The executing agent runs git/tests; the author of this doc does not.)

---

**Definition of done:** `Application::handleOctaneRequest()` (and the dormant `$inOctane`
flag/accessors) and `Str::flushCache()` are added — additive, default-OFF, byte-for-byte
behavior-preserving — the new tests pass, the full existing suite is green and unchanged, and a
written exit-neutralization findings record (plus the `handleOctaneRequest`-vs-`getStackedClient`
and flag-mechanism decisions, and the `forceSchema()` worker-author note) is captured for the
0.3 spike.
