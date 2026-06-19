# Job 1.3 — Changes #3 / #4 / #6: Manager Re-point API + Router/Validation Container Setters

- **Effort (for the executing agent):** MEDIUM
- **Depends on:** Job 1.1 (`bindShared` caching, `10-change1-bindshared.md`) + Job 1.2 (`Application::__clone`, `11-change2-app-clone.md`)
- **Spec refs:** `L42X-REFACTOR-FOR-OCTANE-SANDBOX.md` §7 (Change #3), §8 (Change #4), §9 (Changes #5–#8), §10 (worker swap protocol), §12 (tests 4–5, optional 6); `README.md` template
- **Allowed scope (files this job may modify):**
  - `src/Illuminate/Support/Manager.php`
  - `src/Illuminate/Queue/QueueManager.php`
  - `src/Illuminate/Database/DatabaseManager.php`
  - `src/Illuminate/Cookie/CookieJar.php`
  - `src/Illuminate/Routing/Router.php`
  - `src/Illuminate/Validation/Factory.php`
  - *(OPTIONAL)* `src/Illuminate/View/Factory.php`
  - *(OPTIONAL)* `src/Illuminate/View/Engines/EngineResolver.php`
  - *(OPTIONAL)* `src/Illuminate/Config/Repository.php`
  - `tests/Support/OctaneRepointSettersTest.php` *(new file)*
  - `tests/Routing/RoutingRouterOctaneSetContainerTest.php` *(new file)*
  - *(OPTIONAL)* `tests/Config/ConfigRepositoryCloneTest.php` *(new file, only if Change #8 is done)*

---

## Objective

Add the minimal re-pointing API that the Octane sandbox worker will call after `clone $app` to
flush per-request driver/connection caches and re-aim shared stateful objects at the sandbox
container. Every addition is **additive and dormant** — no existing 4.2 code path calls any of
the new methods, and a stock mod\_php / fpm app must behave byte-for-byte the same after this
job.

---

## Context / why

After `$sandbox = clone $app` (enabled by Jobs 1.1 + 1.2), the clone has its own `$instances`
and `$bindings` arrays, but the **objects** already resolved into `$instances` (managers, router,
validator factory) are **shared by handle** between the base app and the clone. Those shared
objects still hold a reference to the **base** app/container, so any controller resolution or
driver creation made against the sandbox would silently use base-app bindings.

The worker protocol (spec §10) repairs this by calling lightweight setters on the shared objects
immediately after cloning, before dispatching the request. This job adds those setters so the
protocol has something to call. All six required methods plus the optional three are dormant until
the worker explicitly invokes them — the unchanged PHPUnit suite is the proof.

---

## Exact changes

Confirm the exact target line numbers in your working tree before editing (the spec-verified
anchors below match the current `master` branch; re-read each file at edit time to confirm).

---

### Step 1 — `src/Illuminate/Support/Manager.php` (Change #3, required)

**Anchors:** `$app` at line 12 · `$customCreators` at line 19 · `$drivers` at line 26

**What to add.** Append the following two public methods to the class body, before the closing
`}`. The most natural insertion point is after the existing `callCustomCreator()` method (search
for it) or just before the closing brace:

```php
/**
 * Set the application instance used by the manager.
 * Called by the Octane worker to re-point the shared manager at the per-request sandbox.
 *
 * @param  \Illuminate\Foundation\Application  $app
 * @return $this
 */
public function setApplication($app)
{
    $this->app = $app;
    return $this;
}

/**
 * Forget all resolved driver instances so the next call to driver() re-resolves
 * from the sandbox.  App-lifetime registrations in $customCreators are preserved.
 *
 * @return $this
 */
public function forgetDrivers()
{
    $this->drivers = array();
    return $this;
}
```

**Do NOT touch `$customCreators`** (line 19). Those are app-lifetime registrations made at boot
time; clearing them would break the normal request path and violate the governing rule.

This base class covers `AuthManager`, `CacheManager`, and `SessionManager` — all three inherit
`$app` and `$drivers` from here. No changes needed in those subclasses.

---

### Step 2 — `src/Illuminate/Queue/QueueManager.php` (Change #3, required)

**Anchors:** `$connectors` at line 10 · `$app` at line 17 · `$connections` at line 24

`QueueManager` does **not** extend `Support\Manager`; it has its own `$app`, `$connections`, and
`$connectors`. Append the following two methods to the class body (a good insertion point is after
`connection()` or near the end of the public API section):

```php
/**
 * Set the application instance used by the queue manager.
 * Called by the Octane worker to re-point the shared manager at the per-request sandbox.
 *
 * @param  \Illuminate\Foundation\Application  $app
 * @return $this
 */
public function setApplication($app)
{
    $this->app = $app;
    return $this;
}

/**
 * Forget all resolved queue connections so they are rebuilt against the sandbox.
 * Preserves $connectors (the registered connector factories, which are app-lifetime).
 *
 * @return $this
 */
public function forgetConnections()
{
    $this->connections = array();
    return $this;
}
```

**Do NOT touch `$connectors`** (line 10). The connector factories are registered at boot.

---

### Step 3 — `src/Illuminate/Database/DatabaseManager.php` (Change #3, required)

**Anchors:** `$app` at line 13 · `$factory` at line 20 · `$connections` at line 27 · `$extensions` at line 34 · existing `purge()` at line 94

Append the following two methods to the class body. Insert after the existing `purge()` /
`disconnect()` block (around line 99) or at the end of the class, before the closing `}`:

```php
/**
 * Set the application instance used by the database manager.
 * Called by the Octane worker to re-point the shared manager at the per-request sandbox.
 *
 * @param  \Illuminate\Foundation\Application  $app
 * @return $this
 */
public function setApplication($app)
{
    $this->app = $app;
    return $this;
}

/**
 * Disconnect and forget all resolved database connections.
 * Delegates to the existing purge() (line 94) which calls disconnect() + unset.
 * Preserves $factory and $extensions (both are app-lifetime, not per-request).
 *
 * @return $this
 */
public function forgetConnections()
{
    foreach (array_keys($this->connections) as $name) {
        $this->purge($name);     // existing purge() :94 → disconnect() + unset
    }
    return $this;
}
```

**Do NOT touch `$factory`** (line 20) or **`$extensions`** (line 34). Both are app-lifetime.

> **Documented alternative — keep PDO sockets warm (skip `forgetConnections()`):**
> If you want to preserve PDO connections across requests (for connection-pool parity), you may
> skip `forgetConnections()` and instead call, per request, the **already-existing** methods
> `Connection::flushQueryLog()` (`Connection.php:1130`) and `Connection::setEventDispatcher()`
> (`:1027`) — no new code is needed on `Connection`. The default in this job is
> `forgetConnections()` as specified; the warm-socket path is an Octane-package decision and
> does not require any new framework code.

---

### Step 4 — `src/Illuminate/Cookie/CookieJar.php` (Change #3, required)

**Anchor:** `$queued` at line 26

Add one method. Insert it after the existing `queue()` / `unqueue()` / `getQueuedCookies()`
cluster (search for `getQueuedCookies`), or at the end of the class before the closing `}`:

```php
/**
 * Flush all queued cookies for the current request cycle.
 * Clears in place — does NOT rebind a new jar, because Guards hold a reference to this
 * instance via setCookieJar() and those references must remain valid.
 *
 * @return $this
 */
public function flushQueuedCookies()
{
    $this->queued = array();
    return $this;
}
```

---

### Step 5 — `src/Illuminate/Routing/Router.php` (Change #4, required)

**Anchors:** `$container` at line 28 (typed `Container`) · `$controllerDispatcher` at line 50
(typed `?ControllerDispatcher`, nullable) · existing `getControllerDispatcher()` at line 1744 ·
existing `setControllerDispatcher()` at line 1761

**Why nulling `$controllerDispatcher` matters.** `getControllerDispatcher()` (line 1744) lazily
builds and **caches** a `ControllerDispatcher` that captures `$container` at construction time.
Without nulling it, every controller dispatch resolves from the **base** container forever — this
is the one real routing leak. You cannot `forgetInstance('router')` (it holds all registered
routes); re-point instead.

Add the following method. The most natural insertion point is **after** the existing
`setControllerDispatcher()` at line 1761:

```php
/**
 * Set the container instance on the router and invalidate the cached ControllerDispatcher.
 *
 * The dispatcher is rebuilt lazily on the next getControllerDispatcher() call (line 1744),
 * so after this setter the next controller dispatch resolves from $container, not from the
 * base container that was current when the router was booted.
 *
 * Why null the cache: getControllerDispatcher() lazily builds and caches a
 * ControllerDispatcher(:1748) that captures $container at construction; without nulling it,
 * every controller resolves from the BASE container forever (the one real routing leak).
 *
 * @param  \Illuminate\Container\Container  $container
 * @return $this
 */
public function setContainer(Container $container)
{
    $this->container = $container;
    $this->controllerDispatcher = null;   // force rebuild against the new container (:1748)
    return $this;
}
```

`Container` is already imported at the top of the file (`use Illuminate\Container\Container;`
line 7), so no new `use` statement is needed.

---

### Step 6 — `src/Illuminate/Validation/Factory.php` (Change #6, required)

**Anchors:** `$container` at line 28 · `make()` uses it at line 102

This is the one genuinely missing setter in the framework — `View\Factory` already has
`setContainer()` (line 798), but `Validation\Factory` does not. Add the mirror:

```php
/**
 * Set the IoC container instance.
 * Required when re-pointing the shared validator factory at the per-request sandbox,
 * so class-based rule extensions resolve from the current sandbox's container.
 *
 * @param  \Illuminate\Container\Container  $container
 * @return $this
 */
public function setContainer(Container $container)
{
    $this->container = $container;
    return $this;
}
```

`Container` is already imported (`use Illuminate\Container\Container;` line 4). Insert the
method near the existing `setPresenceVerifier()` method at the bottom of the public API section,
or at the end of the class before the closing `}`.

---

### Step 7 — OPTIONAL: `src/Illuminate/View/Factory.php` (Change #5)

**Status: OPTIONAL — defensive parity, may be skipped without blocking the spike.**

All three methods that the worker needs (`setContainer()` :798, `share()` :288, `flushSections()`
:614) already exist on this class. This optional change adds a convenience wrapper:

```php
/**
 * Flush request-scoped state: section stack and shared variables that are re-resolved
 * per request (e.g. 'errors' from the session binder).
 * Convenience only — the worker can call the individual methods instead.
 *
 * @return $this
 */
public function flushState()
{
    $this->flushSections();
    // Drop request-scoped shared keys so they re-resolve from the sandbox session.
    unset($this->shared['errors']);
    return $this;
}
```

> Note: `flushSections()` (`:614`) normally self-fires via `flushSectionsIfDoneRendering()`
> (`:628`) at the end of `View::render()`, so sections rarely leak in practice. This flush is
> defensive for worker safety. The `errors` key is re-shared by `registerSessionBinder`
> (mirror `ViewServiceProvider::registerSessionBinder`) on each request cycle.

---

### Step 8 — OPTIONAL: `src/Illuminate/View/Engines/EngineResolver.php` (Change #7)

**Status: OPTIONAL — defensive parity, may be skipped without blocking the spike.**

**Assessment:** effectively unnecessary. The resolved `blade` / `php` engines hold no per-request
state; `BladeCompiler` self-resets `$footer`/`$path` at the top of each `compile()` call. Ship
it only if it is cheap and the team wants belt-and-suspenders parity.

**Anchor:** `$resolved` array at line 19.

```php
/**
 * Remove a single resolved engine from the cache.
 * Useful if the sandbox re-points blade.compiler/files; otherwise engines are stateless.
 *
 * @param  string  $engine
 * @return void
 */
public function forget($engine)
{
    unset($this->resolved[$engine]);
}
```

---

### Step 9 — OPTIONAL: `src/Illuminate/Config/Repository.php` (Change #8)

**Status: OPTIONAL — no code expected; default is to confirm with a test, not add a `__clone`.**

**Assessment:** `Repository` holds a plain `$items` array (line 28) — PHP's copy-on-write
semantics mean a shallow `clone` produces an independent copy of `$items` with zero overhead
until the first write. The shared refs (`$loader`, `$packages`, `$afterLoad`) are read-only at
runtime (they are populated at boot and never mutated per-request), so sharing them across the
base and sandbox is correct.

**Expectation:** verify in Test 6 (below) that `clone $config` → `set()` on the clone does NOT
touch the base. If the test passes without any code change, no `__clone` is needed — simply
document the test as the confirmation. Only add a `__clone` if a shared mutable ref surfaces
(none are expected).

---

## New tests

Create each new test file matching the convention of the **sibling directory it lives in**
(open an existing test in that dir and copy its namespace + base class). **Verified conventions:**

- `tests/Support/` → **no namespace**, `extends \PHPUnit\Framework\TestCase` (e.g. `SupportStrTest.php`).
- `tests/Routing/` and `tests/Config/` → **no namespace**, `extends L4\Tests\BackwardCompatibleTestCase`
  (e.g. `RoutingControllerDispatcherTest.php`, `ConfigRepositoryTest.php`).

Use Mockery where needed and call `m::close()` in `tearDown` (works with either base class —
`BackwardCompatibleTestCase` just extends `PHPUnit\Framework\TestCase` with a legacy `getMock()` shim).

---

### Test file 1 (required): `tests/Support/OctaneRepointSettersTest.php`

Covers spec §12 test 4 — **manager re-point dormancy**.

```php
<?php

use Illuminate\Container\Container;
use Illuminate\Cookie\CookieJar;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\QueueManager;
use Illuminate\Routing\Router;
use Illuminate\Support\Manager;
use Illuminate\Validation\Factory as ValidationFactory;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

// tests/Support/ convention: no namespace, extends plain PHPUnit\Framework\TestCase.
class OctaneRepointSettersTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    // --- Support\Manager (covers Auth/Cache/Session subclasses) ---

    public function testManagerSetApplicationReplacesAppAndReturnsThis()
    {
        $manager = $this->newConcreteManager(['env' => 'testing']);
        $other   = ['env' => 'sandbox'];

        $result = $manager->setApplication($other);

        $this->assertSame($result, $manager);         // fluent
        $this->assertSame($other, $this->getManagerApp($manager));
    }

    public function testManagerForgetDriversClearsDriversPreservesCustomCreators()
    {
        $manager = $this->newConcreteManager(['env' => 'testing']);
        // Register a custom creator so we can assert it survives
        $noop = function () {};
        $manager->extend('fake', $noop);

        // Seed a fake resolved driver via reflection
        $this->setPrivate($manager, 'drivers', ['fake' => new \stdClass()]);

        $result = $manager->forgetDrivers();

        $this->assertSame($result, $manager);         // fluent
        $this->assertEmpty($this->getManagerDrivers($manager));
        // customCreators must be untouched
        $this->assertArrayHasKey('fake', $this->getManagerCustomCreators($manager));
    }

    // --- QueueManager ---

    public function testQueueManagerSetApplicationReturnsThis()
    {
        $app    = ['config' => ['queue.default' => 'sync']];
        $qm     = new QueueManager($app);
        $other  = ['config' => ['queue.default' => 'sync']];

        $result = $qm->setApplication($other);

        $this->assertSame($result, $qm);
        $this->assertSame($other, $this->getPrivate($qm, 'app'));
    }

    public function testQueueManagerForgetConnectionsClearsConnectionsPreservesConnectors()
    {
        $app = ['config' => ['queue.default' => 'sync']];
        $qm  = new QueueManager($app);

        // Seed a fake connector and a fake connection via reflection
        $this->setPrivate($qm, 'connectors',  ['fake' => function () {}]);
        $this->setPrivate($qm, 'connections', ['fake' => new \stdClass()]);

        $result = $qm->forgetConnections();

        $this->assertSame($result, $qm);
        $this->assertEmpty($this->getPrivate($qm, 'connections'));
        $this->assertNotEmpty($this->getPrivate($qm, 'connectors')); // preserved
    }

    // --- DatabaseManager ---

    public function testDatabaseManagerSetApplicationReturnsThis()
    {
        $app     = m::mock('Illuminate\Foundation\Application');
        $factory = m::mock('Illuminate\Database\Connectors\ConnectionFactory');
        $dm      = new DatabaseManager($app, $factory);
        $other   = m::mock('Illuminate\Foundation\Application');

        $result = $dm->setApplication($other);

        $this->assertSame($result, $dm);
        $this->assertSame($other, $this->getPrivate($dm, 'app'));
    }

    public function testDatabaseManagerForgetConnectionsPreservesExtensionsAndFactory()
    {
        $app     = m::mock('Illuminate\Foundation\Application');
        $factory = m::mock('Illuminate\Database\Connectors\ConnectionFactory');
        $dm      = new DatabaseManager($app, $factory);

        // Seed $extensions and $connections via reflection; no real PDO needed
        $this->setPrivate($dm, 'extensions',  ['foo' => function () {}]);
        // Leave $connections empty — purge() would try to disconnect; testing with no
        // real connection to avoid PDO dependency. An empty connections array is sufficient
        // to verify the loop iterates safely and returns $this.
        $result = $dm->forgetConnections();

        $this->assertSame($result, $dm);
        $this->assertNotEmpty($this->getPrivate($dm, 'extensions')); // preserved
        $this->assertSame($factory, $this->getPrivate($dm, 'factory')); // preserved
    }

    // --- CookieJar ---

    public function testCookieJarFlushQueuedCookiesClearsQueueAndReturnsThis()
    {
        $jar = new CookieJar();
        $jar->queue($jar->make('foo', 'bar'));
        $this->assertNotEmpty($jar->getQueuedCookies());

        $result = $jar->flushQueuedCookies();

        $this->assertSame($result, $jar);
        $this->assertEmpty($jar->getQueuedCookies());
    }

    // --- Validation\Factory ---

    public function testValidationFactorySetContainerReplacesContainerAndReturnsThis()
    {
        $translator = m::mock(TranslatorInterface::class);
        $factory    = new ValidationFactory($translator);
        $container  = new Container();

        $result = $factory->setContainer($container);

        $this->assertSame($result, $factory);
        $this->assertSame($container, $this->getPrivate($factory, 'container'));
    }

    // --- Dormancy proof ---
    // The above tests confirm each method changes ONLY the intended field.
    // The full existing PHPUnit suite (make composer-test) is the dormancy proof:
    // a green suite means no 4.2 code path calls any of the new methods.

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function newConcreteManager(array $app): Manager
    {
        return new class($app) extends Manager {
            public function getDefaultDriver(): string { return 'default'; }
            protected function createDefaultDriver()   { return new \stdClass(); }
        };
    }

    private function getManagerApp(Manager $m)
    {
        return $this->getPrivate($m, 'app');
    }

    private function getManagerDrivers(Manager $m): array
    {
        return $this->getPrivate($m, 'drivers');
    }

    private function getManagerCustomCreators(Manager $m): array
    {
        return $this->getPrivate($m, 'customCreators');
    }

    private function getPrivate(object $obj, string $prop)
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setAccessible(true);
        return $ref->getValue($obj);
    }

    private function setPrivate(object $obj, string $prop, $value): void
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }
}
```

---

### Test file 2 (required): `tests/Routing/RoutingRouterOctaneSetContainerTest.php`

Covers spec §12 test 5 — **router dispatcher invalidation**.

```php
<?php

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class RoutingRouterOctaneSetContainerTest extends BackwardCompatibleTestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testSetContainerReplacesContainerAndReturnsThis()
    {
        $router = $this->makeRouter();
        $other  = new Container();

        $result = $router->setContainer($other);

        $this->assertSame($result, $router);
        $this->assertSame($other, $this->getPrivate($router, 'container'));
    }

    public function testSetContainerNullsControllerDispatcherCache()
    {
        $router = $this->makeRouter();

        // Warm the cache so $controllerDispatcher is not null
        $router->getControllerDispatcher();
        $this->assertNotNull($this->getPrivate($router, 'controllerDispatcher'));

        // Re-point at a new container
        $other = new Container();
        $router->setContainer($other);

        // Cache must be nulled so the next getControllerDispatcher() rebuilds from $other
        $this->assertNull($this->getPrivate($router, 'controllerDispatcher'));
    }

    public function testDispatcherRebuildsFromNewContainerAfterSetContainer()
    {
        $router = $this->makeRouter();

        // Warm the dispatcher against the original container
        $base = $this->getPrivate($router, 'container');
        $router->getControllerDispatcher();

        // Swap container
        $sandbox = new Container();
        $router->setContainer($sandbox);

        // The rebuilt dispatcher must capture $sandbox, not $base
        $rebuilt = $router->getControllerDispatcher();
        $this->assertSame($sandbox, $this->getPrivate($rebuilt, 'container'));
        $this->assertNotSame($base, $this->getPrivate($rebuilt, 'container'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRouter(): Router
    {
        $container = new Container();
        $events    = new Dispatcher($container);
        return new Router($events, $container);
    }

    private function getPrivate(object $obj, string $prop)
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setAccessible(true);
        return $ref->getValue($obj);
    }
}
```

> **Note on `ControllerDispatcher::$container`:** confirm the property name before running. Check
> `src/Illuminate/Routing/ControllerDispatcher.php` — the constructor stores the container; the
> exact property name may be `$container`, `$filterer`, or similar. Adjust the reflection key in
> `testDispatcherRebuildsFromNewContainerAfterSetContainer` accordingly.

---

### Test file 3 (optional): `tests/Config/ConfigRepositoryCloneTest.php`

**Only create this file if Change #8 is being executed (i.e., you are confirming `__clone`
behaviour).** Covers spec §12 test 6 — **config clone isolation**.

```php
<?php

use Illuminate\Config\Repository;
use L4\Tests\BackwardCompatibleTestCase;
use Mockery as m;

class ConfigRepositoryCloneTest extends BackwardCompatibleTestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testCloneIsolatesItemsFromBase()
    {
        $loader = m::mock('Illuminate\Config\LoaderInterface');
        $loader->shouldReceive('getNamespaces')->andReturn([]);
        $base = new Repository($loader, 'testing');
        // Seed a value via the array access interface
        $base['app.name'] = 'BaseApp';

        $clone = clone $base;

        // Mutate the clone
        $clone['app.name'] = 'CloneApp';

        // Base must be unchanged
        $this->assertEquals('BaseApp', $base['app.name']);
        $this->assertEquals('CloneApp', $clone['app.name']);
    }

    public function testCloneSharesLoaderByReference()
    {
        $loader = m::mock('Illuminate\Config\LoaderInterface');
        $loader->shouldReceive('getNamespaces')->andReturn([]);
        $base  = new Repository($loader, 'testing');
        $clone = clone $base;

        // Loader is shared (same object) — that is intentional and correct
        $refBase  = new \ReflectionProperty($base,  'loader');
        $refClone = new \ReflectionProperty($clone, 'loader');
        $refBase->setAccessible(true);
        $refClone->setAccessible(true);

        $this->assertSame($refBase->getValue($base), $refClone->getValue($clone));
    }
}
```

If both assertions pass without any new `__clone` method on `Repository`, **no code change is
needed** — just ship the test as the confirming regression guard.

---

## Acceptance gate

1. **Full existing suite green** (no regressions): `vendor/bin/phpunit -c phpunit.xml` (or
   `make composer-test` via Docker after Job 0.1). This is the dormancy proof.
2. **Test file 1 green** (`OctaneRepointSettersTest`): all assertions in test file 1 pass.
3. **Test file 2 green** (`RoutingRouterOctaneSetContainerTest`): all three test cases pass,
   including `testDispatcherRebuildsFromNewContainerAfterSetContainer` (verifies that after
   `setContainer($sandbox)`, `getControllerDispatcher()` returns a dispatcher whose container IS
   `$sandbox`).
4. **Every new method returns `$this`** where specified (fluent interface).
5. **Optional gate:** if Change #8 was executed, `ConfigRepositoryCloneTest` passes and confirms
   the `$items` array is isolated without a custom `__clone`.

---

## Out of scope / do NOT do

- Do NOT add `Route::flushController()` — L42x's `Route` caches no controller instance;
  it would be a no-op. Explicitly excluded in spec §8.
- Do NOT reset `RouteCollection` — it holds no request-scoped state (spec §8).
- Do NOT add a `Redirector` re-point — the `url` generator self-heals via the
  `RoutingServiceProvider` rebound callback (spec §10).
- Do NOT clear `$customCreators` on `Support\Manager` — app-lifetime registrations (spec §7).
- Do NOT clear `$connectors` on `QueueManager` — the registered connector factories (spec §7).
- Do NOT clear `$extensions` or `$factory` on `DatabaseManager` (spec §7).
- Do NOT add dispatcher cloning or `Event::listen()` per-request snapshot/restore — the shared
  dispatcher is intentional; request-scoped listeners are an unsupported pattern (spec §11).
- Do NOT add `Illuminate\Contracts\*`, `Http\Kernel`, or `bootstrapWith()` — out of scope per
  the fork charter.
- Do NOT add `__clone` to `Config\Repository` unless Test 6 fails (i.e., unless a shared mutable
  ref surfaces) — default is no new code (spec §9).
- Do NOT do any opportunistic cleanup or refactoring beyond the items listed in "Exact changes".
  Minimal change only.

---

## Verification commands

```sh
# Confirm the green baseline before editing (must already be green from Jobs 1.1 + 1.2):
vendor/bin/phpunit -c phpunit.xml

# After all changes, run the full suite:
vendor/bin/phpunit -c phpunit.xml

# Run only the new test files for fast iteration:
vendor/bin/phpunit -c phpunit.xml tests/Support/OctaneRepointSettersTest.php
vendor/bin/phpunit -c phpunit.xml tests/Routing/RoutingRouterOctaneSetContainerTest.php

# Optional (only if Change #8 done):
vendor/bin/phpunit -c phpunit.xml tests/Config/ConfigRepositoryCloneTest.php

# Verify no existing 4.2 code calls the new methods (dormancy spot-check):
grep -rn 'setApplication\|forgetDrivers\|forgetConnections\|flushQueuedCookies' src/
grep -rn 'Router.*setContainer\|router->setContainer' src/
grep -rn 'Validation.*setContainer\|validator->setContainer' src/
# All results should be ZERO (the new methods should not appear in src/).

# Verify each new method signature (fluent return):
grep -n 'return \$this' src/Illuminate/Support/Manager.php
grep -n 'return \$this' src/Illuminate/Queue/QueueManager.php
grep -n 'return \$this' src/Illuminate/Database/DatabaseManager.php
grep -n 'return \$this' src/Illuminate/Cookie/CookieJar.php
grep -n 'return \$this' src/Illuminate/Routing/Router.php
grep -n 'return \$this' src/Illuminate/Validation/Factory.php
```

---

**Definition of done:** full existing suite green, tests 4 and 5 green (test 6 green if Change #8
executed), every new method returns `$this` where specified, and `grep` over `src/` finds zero
call sites for any new method.
