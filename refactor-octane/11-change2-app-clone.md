# Job 1.2 — Change #2: add `Application::__clone()`

- **Effort (for the executing agent):** MEDIUM
- **Depends on:** Job 1.1 (`bindShared` fix, doc `10-change1-bindshared.md`) must be landed and the full test suite must be green before starting this job.
- **Spec refs:** refactor spec §6; README verified anchors; `Application.php` (confirmed no `__clone` exists); `Container.php:108` (`$tags` uninitialized typed property).
- **Allowed scope (files this job may modify):**
  - `src/Illuminate/Foundation/Application.php` — add `__clone()` only
  - `tests/Foundation/FoundationApplicationTest.php` — add the three new test cases described below (§ New tests)

---

## Objective

Add a single `__clone()` method to `Application` that re-points the container's two self-reference
entries (`$instances['app']` and `$instances['Illuminate\Container\Container']`) at the clone after
`clone $app`. This is the minimum necessary so that `clone $base` produces a sandbox whose own
`$app` / `Container` keys resolve to itself rather than to `$base`. Nothing else changes.

---

## Context / why

`Application` extends `Container`. The container stores singleton instances in a plain PHP array
`$instances`. A shallow `clone` copies that array by value, so the clone starts with its own
independent `$instances` — good. But two entries in that array still hold object handles pointing
at `$base`:

| Key | Set at | Handle after clone |
|---|---|---|
| `'app'` | `start.php:62` — `$app->instance('app', $app)` | points at `$base` |
| `'Illuminate\Container\Container'` | `Application.php:140` — `registerBaseBindings()` line `:140` | points at `$base` |

Any sandbox code that resolves `app('app')` or `app('Illuminate\Container\Container')` gets back the
base application, breaking sandbox isolation. `__clone` fixes that with two assignments.

The `Container` class also has a private `array $tags` property declared at `Container.php:108` with
**no default value** (`private array $tags;`). This is an uninitialized typed property under PHP 8.3:
reading or writing it before the property is initialized (i.e., before `tag()` is first called)
raises a fatal `Typed property … must not be accessed before initialization`. Therefore `__clone`
must not touch `$tags` at all.

---

## Exact change

### Step 1 — confirm current state

Before editing, re-confirm (read-only):

1. `src/Illuminate/Foundation/Application.php` — search for `__clone`: must not exist.
2. `src/Illuminate/Foundation/Application.php:140` — must read
   `$this->instance('Illuminate\Container\Container', $this);`
3. `src/Illuminate/Foundation/start.php:62` — must read
   `$app->instance('app', $app);`
4. `src/Illuminate/Container/Container.php:108` — must read
   `private array $tags;` (no `= []` initializer).

If any of these do not match, stop and flag the drift rather than proceeding.

### Step 2 — add `__clone()` to `Application.php`

Insert the following method as the **last** public method before the final closing `}` of the class
(after `registerCoreContainerAliases()` which currently ends at line `~1157`). Add it immediately
before the closing `}` of the class at line `1159`.

```php
	/**
	 * Re-point the container's self-reference bindings at the clone.
	 *
	 * After a shallow clone the $instances array is copied by value (independent),
	 * but the two self-reference handles still point at the base app.  Fix them here
	 * so that clone $app produces a fully self-referential sandbox.
	 *
	 * IMPORTANT: do NOT touch $this->tags — it is an uninitialized typed property
	 * (Container::$tags, declared as `private array $tags;` with no default) and
	 * accessing it before tag() is called fatals under PHP 8.3.
	 *
	 * Do NOT call Facade::setFacadeApplication() or Container::setInstance() here —
	 * those static swaps belong in the worker swap protocol (spec §10), not in
	 * __clone, so that a bare `clone $app` expression stays side-effect-free.
	 */
	public function __clone()
	{
		// Re-point the container's self-bindings at the clone (they pointed at the base app).
		$this->instances['app'] = $this;
		$this->instances['Illuminate\Container\Container'] = $this;
	}
```

Use a single tab for indentation, matching the surrounding code in `Application.php`.

---

## New tests

Add the following three test methods to
`tests/Foundation/FoundationApplicationTest.php` inside the existing
`FoundationApplicationTest` class (after the last existing test method, before the closing `}`
of the class). Follow the class's existing style: `BackwardCompatibleTestCase`, no namespace,
tabs for indentation.

### Test 1 — clone self-reference: `app` key resolves to clone, not base

```php
	public function testCloneSelfReferenceAppKey()
	{
		$base = new Application;
		$base->instance('app', $base);

		$clone = clone $base;

		$this->assertSame($clone, $clone['app'],
			'clone[\'app\'] must resolve to the clone, not the base');
		$this->assertSame($base, $base['app'],
			'base[\'app\'] must still resolve to the base after cloning');
		$this->assertNotSame($base, $clone['app'],
			'clone[\'app\'] must not point at the base app');
	}
```

### Test 2 — clone self-reference: Container FQCN key resolves to clone

```php
	public function testCloneSelfReferenceContainerKey()
	{
		$base = new Application;
		$base->instance('Illuminate\Container\Container', $base);

		$clone = clone $base;

		$this->assertSame($clone, $clone['Illuminate\Container\Container'],
			'clone[Container] must resolve to the clone');
		$this->assertSame($base, $base['Illuminate\Container\Container'],
			'base[Container] must still resolve to the base after cloning');
	}
```

### Test 3 — cloning an app that never called `tag()` does not fatal

This guards the `$tags` uninitialized-typed-property concern.

```php
	public function testCloneDoesNotFatalOnUninitializedTags()
	{
		// An Application that has never called tag() has an uninitialized
		// $tags typed property (Container.php:108).  Cloning must not read
		// or write it, otherwise PHP 8.3 throws a fatal.
		$base = new Application;
		// Do NOT call $base->tag() — leave $tags uninitialized.

		$exception = null;
		try {
			$clone = clone $base;
		} catch (\Throwable $e) {
			$exception = $e;
		}

		$this->assertNull($exception,
			'clone $app must not throw when $tags has never been initialized; got: '
			. ($exception ? $exception->getMessage() : ''));
	}
```

---

## Acceptance gate

1. **Full existing suite green** before and after:
   ```sh
   vendor/bin/phpunit -c phpunit.xml
   ```
   (or `make composer-test` via Docker). The suite must be green before you begin (Job 1.1 landed
   and verified) and must remain green after your change.

2. **Three new tests pass**:
   ```sh
   vendor/bin/phpunit -c phpunit.xml tests/Foundation/FoundationApplicationTest.php
   ```
   All three new methods must appear as green.

3. **Additive/dormant proof** — the only file edited in `src/` is `Application.php`, and the only
   change is the new `__clone()` method. Confirm no existing 4.2 code path calls `__clone`
   directly; the passing unchanged suite is the proof.

---

## Out of scope / do NOT do

- **Do NOT deep-clone** any property (arrays, objects, anything). A shallow clone with only the two
  `$instances` entries rewritten is exactly what is needed. Deep-cloning would break shared-service
  semantics: managers, providers, and all other `$instances` entries are intentionally shared between
  base and sandbox and get re-pointed by the worker protocol (spec §10, Job 1.3).
- **Do NOT call `Facade::setFacadeApplication()`** or **`Container::setInstance()`** inside
  `__clone`. Those static swaps live in the worker swap protocol (spec §10) so that a bare
  `clone $app` expression is side-effect-free. Putting static mutations inside `__clone` would
  break any code that clones for non-worker purposes (tests, tooling).
- **Do NOT touch `$this->tags`** in any way — not read, not write, not `isset()`. It is a PHP 8.3
  uninitialized typed property until `tag()` is called and access before initialization is fatal.
- **Do NOT modify `Container.php`** — this job touches only `Application.php` and the one test
  file.
- **Do NOT add any other methods** to `Application.php` beyond `__clone()`. Methods for re-pointing
  managers, router, and validation belong to Job 1.3 (`12-change3-4-6-repoint-setters.md`).
- **Do NOT amend prior commits.** Create a single new commit scoped to this job.

---

## Verification commands

```sh
# 1. Confirm __clone does not exist before your edit (should return nothing):
grep -n '__clone' src/Illuminate/Foundation/Application.php

# 2. Confirm the $tags anchor in Container.php:
sed -n '105,112p' src/Illuminate/Container/Container.php

# 3. Confirm the self-binding anchors:
sed -n '136,141p' src/Illuminate/Foundation/Application.php
sed -n '60,64p' src/Illuminate/Foundation/start.php

# 4. Full suite before edit (must be green — inherited from Job 1.1):
vendor/bin/phpunit -c phpunit.xml

# 5. After edit — targeted run of the changed test file:
vendor/bin/phpunit -c phpunit.xml tests/Foundation/FoundationApplicationTest.php

# 6. Full suite after edit (must still be green):
vendor/bin/phpunit -c phpunit.xml
```

---

**Definition of done:** `Application::__clone()` exists, rewrites only the two self-reference
`$instances` entries, and all three new tests plus the full pre-existing suite pass green.
