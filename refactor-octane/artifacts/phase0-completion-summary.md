# Phase 0/1 Octane Sandbox Refactor Summary

## Verdict

**GO** means the in-process feasibility spike passed the Phase 0 gate after the required Phase 1 L42x framework refactor was landed. The L42x clone-per-request sandbox model booted, cloned, re-pointed shared services, ran through the stacked kernel, and completed the cross-request leak probes without a blocking failure.

This is not a production-ready verdict. It means the framework-side prerequisites are viable enough for the package-side worker and sandbox preparer work to proceed. Remaining runtime concerns are documented as residual risk in `refactor-octane/artifacts/spike/RESULT.md`.

## Completed Jobs

The PLAN's Phase 1 section maps to these completed L42x jobs:

- PLAN 1.1 -> Job 10: `Container::bindShared()` clone-isolation fix.
- PLAN 1.2 -> Job 11: `Application::__clone()` self-reference fix.
- PLAN 1.3 -> Job 12: re-point setters for managers, router, validator, and cookies.
- PLAN 1.4 -> Job 13: worker-safety hooks, reachable stacked kernel, and `Str::flushCache()`.

The only PLAN 1.4 item not implemented in L42x is the event-dispatch shim. Job 13 explicitly ruled that out for this repository because D4 belongs package-side; L42x already has string-keyed `Dispatcher::fire()`.

## Running The Container

This branch uses a repo-local PHP 8.3 Docker runner so it does not depend on the older root `Dockerfile` flow from the package repo.

Files:

- `Dockerfile.octane-sandbox`
- `execute-octane-sandbox`

Docker names:

- Image: `l42x-octane-sandbox`
- Container: `l42x-octane-sandbox-dev`

Common commands:

```sh
make composer-test
```

Runs the full PHPUnit suite through:

```sh
./execute-octane-sandbox composer-test
```

Run a targeted PHPUnit file:

```sh
./execute-octane-sandbox ai:test tests/Container/ContainerBindSharedTest.php
```

Run arbitrary PHP inside the same container:

```sh
./execute-octane-sandbox php -v
```

Open a shell in the container:

```sh
./execute-octane-sandbox bash
```

Run the feasibility spike:

```sh
./execute-octane-sandbox php refactor-octane/artifacts/spike/spike.php
```

Expected spike result:

```text
RESULT.md written: GO
```

Notes:

- The wrapper builds the image if needed.
- The repository is mounted at `/app`.
- Composer may print a harmless "dubious ownership" warning from inside Docker; PHPUnit still exits green.
- The root `Dockerfile` was intentionally left untouched.

### Job 00 - runtime hygiene

Committed:

- `1e4069ec build(octane): add sandbox docker runner`
- `91b33721 build(octane): establish PHP 8.3 test baseline`

Changed:

- Added `Dockerfile.octane-sandbox`.
- Added `execute-octane-sandbox`.
- Pointed test execution through the custom PHP 8.3 Docker runner.
- Established a green Composer/PHPUnit baseline.

Verification:

```text
make composer-test
Tests: 1619, Assertions: 3564, Skipped: 25.
```

### Job 01 - static-state audit

Artifact:

- `refactor-octane/artifacts/leak-register.md`

Result:

- Classified static/singleton leak concerns.
- Cross-checked PLAN section 8.
- Left the artifact uncommitted because the job was read-only and forbade git.

### Job 10 - bindShared clone isolation

Committed:

- `f66f773f refactor(octane): isolate bindShared cache per container`

Changed:

- `Container::bindShared()` now binds the raw closure as shared instead of wrapping it through `share()`.
- Added `tests/Container/ContainerBindSharedTest.php`.

Verification:

```text
ContainerBindSharedTest: OK (4 tests, 5 assertions)
ContainerExtendTest + ContainerL4Test: OK (9 tests, 16 assertions)
make composer-test
Tests: 1623, Assertions: 3569, Skipped: 25.
```

### Job 11 - Application clone self-references

Committed:

- `b7cdea74 refactor(octane): repoint application clone bindings`

Changed:

- Added `Application::__clone()` to re-point only `app` and `Illuminate\Container\Container` self-reference instances to the clone.
- Added clone self-reference and uninitialized `$tags` safety tests.

Verification:

```text
FoundationApplicationTest: OK (15 tests, 37 assertions)
make composer-test
Tests: 1626, Assertions: 3575, Skipped: 25.
```

### Job 12 - sandbox re-point setters

Committed:

- `cccaeb2b refactor(octane): add sandbox repoint setters`

Changed:

- Added `Manager::setApplication()` and `Manager::forgetDrivers()`.
- Added `QueueManager::setApplication()` and `QueueManager::forgetConnections()`.
- Added `DatabaseManager::setApplication()` and `DatabaseManager::forgetConnections()`.
- Added `CookieJar::flushQueuedCookies()`.
- Added `Router::setContainer()`.
- Added `Validation\Factory::setContainer()`.
- Added support, routing, and config clone tests.

Verification:

```text
OctaneRepointSettersTest: OK (8 tests, 20 assertions)
RoutingRouterOctaneSetContainerTest: OK (3 tests, 6 assertions)
ConfigRepositoryCloneTest: OK (2 tests, 3 assertions)
make composer-test
Tests: 1639, Assertions: 3604, Skipped: 25.
```

### Job 13 - worker safety hooks

Committed:

- `871e9a9b refactor(octane): add worker safety hooks`

Changed:

- Added `Application::handleOctaneRequest()` to run the stacked HTTP kernel and return the response without `send()` or `terminate()`.
- Added `Application::runningInOctane()` and `Application::setRunningInOctane()`.
- Added `Str::flushCache()`.
- Added Foundation and Str tests.

Exit-neutralization finding:

- `Application::handle(..., $catch = false)` rethrows to the worker catch path.
- `Exception\Handler::handleException()` returns a response and does not `exit` or `die`.
- `dd()` remains a developer-tooling `die` and is not guarded.
- No framework guard branch was added; only default-off scaffolding was shipped.

Verification:

```text
FoundationApplicationTest: OK (18 tests, 49 assertions)
SupportStrTest: OK (21 tests, 93 assertions)
make composer-test
Tests: 1643, Assertions: 3622, Skipped: 25.
```

### Job 20 - feasibility spike

Artifacts:

- `refactor-octane/artifacts/spike/spike.php`
- `refactor-octane/artifacts/spike/RESULT.md`

Result:

```text
Verdict: GO
```

Observed PASS criteria:

- Base app boots once without fatal.
- `clone $base` does not fatal and self-references point at the clone.
- No cross-request leak across the probed PLAN section 8 rows.
- Normal request exceptions under `$catch = false` reach the worker catch path and do not terminate the loop.
- The stacked kernel runs through `handleOctaneRequest()` and applies cookie/session middleware on the clone.

Residual risks:

- Real FrankenPHP superglobal marshalling.
- Uploaded-file SAPI behavior.
- Worker memory soak.
- Multi-worker behavior.
- Package-side garbage collection.
- User or third-party `exit` / `die`, including `dd()`.

## Current State

Framework-side Phase 0 and Phase 1 work for this L42x repository is complete. Code changes are committed through Job 13. Job 01 and Job 20 artifacts remain uncommitted because their job specs forbade git operations during those jobs.

Remaining package-side work should consume the GO result and implement the production worker/sandbox preparer outside this repository.
