# L42x → Laravel 13 — Task Backlog (framework-first, kerjakan 1-per-1)

Turunan actionable dari `MIGRATION-ROADMAP.md` (approach **framework-first / strict-mode-L13 /
enforcement-via-type**). **Cara pakai:** ambil task ber-nomor terendah yang blocker-nya sudah ✅,
kerjakan sampai "Done when" terpenuhi, centang, lanjut. Tiap task **independen & shippable sendiri**.

**Kolom tempat:** `[fork]` = di repo L42x ini · `[app]` = di repo konsumen `dicoding` ·
`[ops]` = infra. **Effort:** T=trivial S=small M=medium L=large XL=x-large.
**Bucket:** `B1`=fork-only · `B2`=app-unavoidable removal · `B3`=value-semantics.

> **Pola berulang (STRICT-MODE-L13):** untuk tiap komponen —
> **(a)** `[fork]` perketat signature ke strict-mode-L13 (idiom lama → `TypeError`/method-not-found)
> → **(b)** `[app]` conform, **dipaksa PHP+Psalm** (hanya B2/B3; B1 nol app work)
> → **(c)** `[fork/ops]` swap komponen ke `illuminate/*` v13 asli (per-cluster; **edit blok `replace`
>   fork tiap langkah** — fork & stock sama-sama `laravel/framework`, tak bisa co-install).
> Enforcement lewat **type** bila removal. **DUA pengecualian butuh guard non-type permanen:**
> **Cache-TTL** (stock 13 `$ttl=null` re-admit bare int → Psalm-rule/ratchet) dan **Console
> `handle()`** (stock resolve `handle|__invoke` → grep/boot-smoke). Ratchet CI **didemote** ke pola
> non-type + dua guard pasca-swap itu.

---

## Wave 0 — Enabler (sekali kerja)

- [ ] **0.1 — WAF di edge** `[ops · S]`
  - Why: tutup surface security core 4.2 tak terpatch **sekarang**; beli waktu.
  - Done when: trafik produksi lewat WAF; dashboard menampilkan hit rule.

- [ ] **0.2 — Demote ratchet CI ke pola non-type + guard pasca-swap** `[fork · S]`
  - Why: enforcement pindah ke type (PHP+Psalm) **bila removal**. Ratchet tetap untuk yang **tak**
    bisa jadi type, **plus** dua guard yang strictness fork-nya menguap saat swap.
  - Steps: pangkas `ci/convergence-ratchet.sh` → sisakan pola: helper global `str_*`/`array_*`,
    SQL string-concat/`whereRaw`, route-array `'before'=>`, `App::error(`/`['exception']` binding,
    **`Cache::put/add/remember` dengan arg TTL bare-int** (guard permanen §7D), **`function fire(`
    di command app** (Console fire-only pasca-swap §7D).
  - Done when: ratchet hanya cek pola non-type + 2 guard pasca-swap; sisanya dihapus dari daftar.

- [ ] **0.3 — Pastikan Psalm jalan di CI app + siapkan 2 custom rule** `[app · S]`
  - Why: enforcement statis butuh Psalm hijau di `dicoding` (sudah ada) sebagai baseline; 2 pola
    non-self-liquidating perlu Psalm-rule permanen.
  - Steps: catat baseline Psalm; siapkan (draft) custom Psalm-rule/plugin: (1) bare-int arg pada
    `Cache::put/add/remember` = error; (2) command class tanpa `handle()` = error. Rule aktif penuh
    saat komponen di-tighten (2.2 / 2.6).
  - Done when: Psalm baseline app tercatat; 2 rule ter-draft; siap menangkap `UndefinedMethod`/`TooManyArguments`.

---

## Wave 1 — PRASYARAT: split monolith + Contracts + replace-map (buka semua; urut wajib)

> Ini yang **memutus SCC-1** dan memungkinkan pola berulang jalan. Kerjakan berurutan.

- [ ] **1.0 — Split fork monolitik → struktur `illuminate/*`-shaped + replace-map** `[fork · L]`
  - Why: real `illuminate/*` v13 = banyak package; namespace `Illuminate\*` collide **DAN** fork &
    stock sama-sama `laravel/framework` yang `replace` illuminate/* → tak bisa co-install → swap
    **per-cluster** + edit `replace` tiap langkah.
  - Steps: (1) petakan `src/Illuminate/*` fork ke boundary package v13 (support/collections/macroable/
    conditionable/reflection dst) — **namespace tetap**, siapkan composer split-map. Jangan bikin dir
    baru untuk leaf yang di stock 13 tetap di tree Support (Conditionable/Reflector). (2) **Dokumentasikan
    `replace`-map (§1a):** 27 entry `replace` fork → package v13 target + urutan pembukaan (SCC-1
    cluster dulu, lalu L2 per-unit, lalu SCC-2). Catat: entry dihapus **saat** cluster-nya di-swap
    (Wave 4), bukan sekarang. Catat 6 package yang fork **tak** `replace` tapi stock `replace`
    (contracts/collections/macroable/conditionable/reflection/pipeline) → introduce = copy source.
  - Done when: peta split + `replace`-map + urutan pembukaan terdokumentasi; tiap komponen fork dipetakan ke package v13 target.

- [ ] **1.1 — Introduce `Contracts`** `[fork · M]` `B1` · blocked-by: 1.0
  - Why: `Contracts` **tak ada di tree fork**; **memutus SCC-1**. Prasyarat semua rename namespace.
  - Steps: copy `src/Illuminate/Contracts/*` verbatim dari `../framework` (semua 33 subdomain);
    composer require **PSR-only** (⚠️ **JANGAN** tambah `illuminate/*` → false cycle dari 5 type-hint cross-import).
    Fork tak `replace` contracts → aman.
  - Enforcement: **by presence** — begitu komponen type-hint `Contracts\X`, PHP+Psalm tangkap consumer non-conform.
  - Done when: `Illuminate\Contracts\*` tersedia; komponen bisa refer interface.

- [ ] **1.2 — Introduce leaf traits/util (COPY SOURCE, fisik di tree Support)** `[fork · S]` `B1/B2` · blocked-by: 1.1
  - Steps: `Macroable` (rename `MacroableTrait`→`Macroable`, `B2`); `Conditionable` +
    `HigherOrderWhenProxy` verbatim (`B1`); `Reflector` superset + `ReflectsClosures` (`B1`).
    **Copy SOURCE ke tree Support fork** — **JANGAN** `composer require illuminate/macroable|conditionable`
    (fork tak replace-nya, collide dengan on-disk Support). **Jangan** bikin `src/Illuminate/{Conditionable,Reflection}` dir.
  - Enforcement: `use ...\MacroableTrait` → trait-not-found (PHP+Psalm).
  - Done when: idiom `->when()/::macro()`/`Reflector` tersedia dengan shape L13; ~4 site internal `use MacroableTrait` di-rename.

- [ ] **1.3 — Introduce `Collections` shape (COPY SOURCE ke tree Support fork)** `[fork · L]` `B1` · blocked-by: 1.1, 1.2
  - Why: `Collection` dipisah dari Support di v13 (namespace `Illuminate\Support\Collection` tetap).
    **Fork tak `replace` collections**, dan real `illuminate/collections:^13` mengirim
    `Illuminate\Support\{Collection,Arr,Enumerable}` yang **collide** dengan `src/Illuminate/Support/Collection.php`
    fork on-disk → **TIDAK BISA `composer require illuminate/collections` sekarang**.
  - Steps: **copy SUPERSET SOURCE** ke tree Support fork: tambah `EnumeratesValues`/`Enumerable`/
    `LazyCollection`/`Arr` standalone/`HigherOrderCollectionProxy`. Real package swap **nanti di
    window SCC-1** (saat Support fork di-copot, §4.2).
  - Enforcement: **bukan type** (superset) → **behavior-parity test** (`random/first/groupBy/sortBy` edge).
  - Done when: surface Collection = L13 (source di tree fork); parity test hijau. (Real `illuminate/collections` swap ditunda ke 4.2.)

- [ ] **1.4 — Alihkan Facade/binding konkret → interface Contracts** `[fork · L]` `B1` · blocked-by: 1.1
  - Why: memutus SCC-1 (`Support\Facades\Response`→Http konkret, `CapsuleManagerTrait`→Container konkret).
  - Steps: Facade resolve via container/kontrak; binding type-hint `Contracts\*`.
  - Done when: grep SCC-1 cycle (Support→Http/Container konkret) hilang.

---

## Wave 2 — Strict-mode tighten (bucket 2/3; app conform via PHP+Psalm; urutan bebas)

> Tiap task: `[fork]` perketat → `[app]` migrasi (dipaksa type). Bisa dicicil paralel setelah Wave 1.

> **Catatan:** bekas **2.1 (app-sweep cache) DIGABUNG ke 2.2** — di framework-first, konversi call-site
> app adalah *konsekuensi* dari fork-tighten (dipaksa type), bukan task berdiri sendiri. Draft app
> sempat dibuat lalu **di-drop** (2026-09-10); recoverable: `git cherry-pick 9edeba6f52b`.

- [ ] **2.2 — 🔴 Cache: tighten TTL param di fork → app dipaksa konform + guard permanen** `[fork+app · M]` `B3` · blocked-by: 1.1, 0.3
  - Why: L13 baca int TTL = **detik**, fork 4.2 = **menit** → bare int senyap **60× lebih pendek**
    pasca-swap. Interval absolut (`Carbon`) aman di kedua versi. Fork-tighten = *driver* yang **memaksa**
    migrasi app; strictness fork **menguap saat swap** (stock `Repository.php:367`/`Contracts/Cache/Repository.php:29`
    `$ttl=null`, `getSeconds():903` re-admit bare int) → guard permanen = **Psalm-rule/ratchet** (0.3), bukan type.
  - Steps: `[fork]` di `src/Illuminate/Cache/Repository.php` `put()/add()/remember()` (line 88/106/124) +
    mirror di `TaggedCache`, ganti `$minutes` untyped → `DateTimeInterface|DateInterval $ttl`; drop
    `getMinutes()/getDefaultCacheTime()`. Relokasi `StoreInterface`→`Contracts\Cache\Store` (alias sementara).
    **WAJIB di commit yang sama — konversi fork-internal bare-int caller** atau fork tak boot (verified):
    `Session/CacheBasedSessionHandler.php:60` `put($id,$data,$this->minutes)` → interval;
    `Cache/ArrayStore.php:74` & `Cache/ApcStore.php:94` `put($key,$value,0)` (dari `forever()`) →
    `DateInterval`/null forever-path.
  - Steps `[app]` (DIPAKSA oleh type di atas): bare-int TTL → `Carbon::now()->addMinutes(N)`. Cakupan
    verified: **NullableCache fix pusat** (menutup 54 caller via param `int $cacheDurationInMinutes`) +
    **6 inject raw `Repository`** + **6 call-site facade**. 2 FAQ `3600*24*3` (=180 hari) = latent bug lama
    → **pertahankan + flag** (bukan diubah di sini).
  - Steps `[ci/psalm]`: aktifkan Psalm-rule bare-int TTL (0.3) — **guard PERMANEN** (strictness fork menguap saat swap).
  - Enforcement: fork = bare int `TypeError`+Psalm (memaksa app). **Pasca-swap = Psalm-rule/ratchet
    (permanen)** — stock 13 re-admit bare int.
  - Done when: bare-int TTL tak type-check di fork; fork boot hijau (3 internal caller dikonversi);
    app dikonversi & Psalm app hijau; Psalm-rule bare-int aktif & hijau.

- [ ] **2.3 — Cache: 5 custom Store → contract baru** `[app · S]` `B2` · blocked-by: 2.2
  - Steps: 5 kelas app-space yang implement `StoreInterface` → `Illuminate\Contracts\Cache\Store`
    (tambah `many()/putMany()` bila perlu). Alias mati saat swap.
  - Done when: grep `StoreInterface` di app = 0; custom store implement contract.

- [ ] **2.4 — Events `fire()` → `dispatch()`** `[fork+app · M]` `B2` · blocked-by: 1.1
  - Steps: `[fork]` `Events\Dispatcher`: add `dispatch()` canonical + `fire()` deprecated alias;
    rename `queue()`→`push()`, `forgetQueued()`→`forgetPushed()`; drop `$priority` (line 62);
    implement L13 `DispatcherContract`. Lalu hapus `fire()/queue()/firing()/forgetQueued()`.
    `[app]` ~96 `Event::fire()`/`->fire()` → `dispatch()`; review 118 `listen()` (wildcard payload).
  - Enforcement: post-hapus, `fire()` = method-not-found; 3-arg `listen()` = ArgumentCountError.
  - Note swap: `illuminate/events:^13` **hard-require** `illuminate/bus` → Bus wajib ikut window SCC-1 (bukan skip).
  - Done when: grep `->fire(` app = 0; 118 `listen()` di-review; Psalm hijau.

- [ ] **2.5 — Database: `lists()`/`pluck`-swap/`SoftDeletes`/`Arrayable`** `[fork+app · XL]` `B2(+B3)` · blocked-by: 1.1
  - Steps (urut aman): `[fork]` (1) hapus `lists()` dari Query+Eloquent Builder+Collection; rename
    single-value `pluck($column)`→`value($column)`; tambah L13-semantics `pluck($column,$key=null)`
    (= body `lists()` lama). (2) rename `SoftDeletingTrait`→`SoftDeletes`, `ScopeInterface`→`Scope`
    (hapus nama lama, **no alias** — mau fatal). (3) Model implement `Contracts\Support\{Arrayable,Jsonable}`.
    `[app]` ~173 `->lists()`→`->pluck()`; ~193 `->pluck()` (single) → `->value()`; ~21 SoftDelete/Arrayable rename.
  - Enforcement: `lists()`/`pluck(single)` = method-not-found (type-caught). **pluck-swap makna =
    NOT type-expressible** → grep/Psalm-rule + human review cegah "fix" balik ke `pluck()` salah makna.
  - Done when: grep `->lists(|SoftDeletingTrait|ArrayableInterface` app = 0; pluck/value review selesai; Psalm hijau.

- [ ] **2.6 — Console `fire()` → `handle()` + `$signature` (fork temp-abstract)** `[fork+app · L]` `B2` · blocked-by: 1.1, 0.3
  - Why koreksi: **stock 13 BUKAN abstract** — `Command.php:289` `method_exists($this,'handle')?'handle':'__invoke'`
    → `fire()`-only jatuh ke `__invoke` missing = runtime `BadMethodCall`, **bukan** unimplemented-abstract/Psalm.
    Maka fork bikin `handle()` **abstract sementara** hanya untuk **me-ratchet ~308 rewrite**; guard
    pasca-swap = **grep `function fire(` + boot-smoke** (0.2), bukan type.
  - Steps: `[fork]` `Console/Command.php` `execute()` → panggil `abstract public function handle()`
    (drop `fire()` indirection, **catatan: strictness ini menguap saat swap ke stock**); hapus reader
    `getArguments()/getOptions()` (keep `$signature` parsing); hapus `Application::make/start/
    setExceptionHandler/renderException/setAutoExit`; ctor `(Container,Dispatcher,version)`; update
    `command.stub`. `[app]` ~308 `fire()`→`handle()` (Rector rule Laravel fire→handle), ~87
    getArguments/getOptions→`$signature`, pindah `artisan.php` manual reg → Kernel command registration.
    `[ci]` aktifkan grep `function fire(` di command app (0.2).
  - Enforcement: **fork sementara** = subclass hanya-`fire()` unimplemented-abstract fatal (ratchet).
    **Pasca-swap = grep + boot-smoke** (stock jatuh ke `__invoke`).
  - Done when: grep `function fire(` app command = 0; artisan.php → Kernel; boot-smoke tiap command hijau; Psalm hijau.

- [ ] **2.7 — Config `getEnvironment()` → `App::environment()`** `[fork+app · M]` `B2` · blocked-by: 1.1
  - Steps: `[fork]` `Config/Repository.php` hapus `getEnvironment()` (line 346) + loader/package-cascade
    (`getLoader/setLoader/package/afterLoading/addNamespace/hasGroup`); ctor `(LoaderInterface,$env)`→`(array)`;
    implement `Contracts\Config\Repository`; add typed getter `string()/integer()/…`. `[app]` ~48 site
    `getEnvironment()`→`App::environment()` (incl. blade `pixel/sentry/payment`).
  - Enforcement: `getEnvironment()` = method-not-found; ctor pass loader = TypeError.
  - Done when: grep `getEnvironment(` app = 0; Psalm hijau.

- [ ] **2.8 — Routing filters → middleware** `[fork+app · XL]` `B2` · blocked-by: 1.1, 1.2 (Pipeline)
  - Steps: `[fork]` (1) **dulu** introduce `Http\Kernel` + middleware dispatch (Pipeline) agar middleware
    jalan saat filter masih ada. (2) hapus `Router::filter/before/after/when/whenRegex/callFilter` +
    `RouteFiltererInterface`, `controller()/controllers()`, drop `HttpKernelInterface`. Keep
    `UrlGenerator`/`Redirector` (identik L13). `[app]` ~52 `Route::filter` (141-line filters.php) →
    middleware; 131 route-array `'before'/'after'=>` → `->middleware()`.
  - Enforcement: `Route::filter()/->before()/->after()` = method-not-found. Route-array `'before'=>`
    (array key) = **NOT type** → **grep/Psalm-rule** (§7D).
  - Note swap: `illuminate/routing:^13` **hard-require** `illuminate/session` (SCC-1) → routing swap butuh SCC-1 selesai.
  - Done when: grep `Route::filter|->before\(|->after\(` app = 0; route-array grep bersih; boot hijau.

- [ ] **2.9 — Encryption: strict-mode contract** `[fork+app · S]` `B2` · blocked-by: 1.1
  - Steps: `[fork]` hapus `setKey()`; implement `Contracts\Encryption\{Encrypter,StringEncrypter}`;
    relokasi `DecryptException/EncryptException` ke `Contracts\Encryption` (`class_alias` bridge);
    throw `EncryptException` (bukan `\RuntimeException`); drop dead Symfony import + `symfony/security-core`.
    `[app]` optional re-hint ke Contract (0 forced). `[ops]` pin `config['cipher']=AES-256-CBC` + `previous_keys`.
  - Enforcement: `setKey()` = method-not-found. **cipher default = config assertion / boot-guard, bukan type**.
  - Done when: `setKey` di app = 0 (sudah); cipher config pinned; Psalm hijau.

- [ ] **2.10 — Filesystem `FileNotFoundException` → Contracts** `[fork+app · S]` `B2` · blocked-by: 1.1
  - Steps: `[fork]` relokasi ke `Contracts\Filesystem\FileNotFoundException` + `class_alias` dari FQCN lama
    (`@deprecated` → Psalm `DeprecatedClass`); provider `bindShared('files')`→`singleton`. `[app]` repoint
    11 import produksi (aliasable/deferrable). **Jangan** introduce Storage/flysystem di sini.
  - Enforcement: alias jaga catch lama; `@deprecated` surface 11 import untuk dimigrasi.
  - Done when: 11 import repoint (atau alias jalan); Psalm hijau. (Catatan: `DicodingUtils\...FileNotFoundException` app **tak** tersentuh.)

- [ ] **2.11 — Hashing `HasherInterface` → contract** `[fork+app · S]` `B2` · blocked-by: 1.1
  - Steps: `[fork]` `HasherInterface` extend `Contracts\Hashing\Hasher` (bridge); rebind `'hash'` ke
    HashManager; register contract. `[app]` 4 site (`ApplicationGateway`, `UserPasswordUpdateSpecification`
    + 2 test) → `Illuminate\Contracts\Hashing\Hasher`. `[ops]` verify `hashing.driver`=bcrypt fallback.
  - Enforcement: setelah delete `HasherInterface`, hint/`make(HasherInterface::class)` = unresolvable.
  - Done when: 4 site migrasi ke contract; Psalm hijau.

- [ ] **2.12 — Cookie/Session: widen jar + siapkan middleware** `[fork · M]` `B2` · blocked-by: 1.1, 2.9
  - Steps: `[fork]` **Cookie**: widen `queued/hasQueued/unqueue/setDefaultPathAndDomain` (additive optional
    param); add `CookieValuePrefix`; siapkan `Middleware/EncryptCookies`+`AddQueuedCookiesToResponse`
    (constructor-typed `Contracts\Encryption\Encrypter`) — **rewrite Guard/Queue decorator → middleware
    flip-time-coupled**. **Session**: Store `implements Contracts\Session\Session`, ekstrak bag ke
    `SymfonySessionDecorator` (hapus `registerBag/getBag/...`); siapkan `Middleware/StartSession`+
    `AuthenticateSession`; widen `flash/put/get` key `UnitEnum|string`.
  - Enforcement: `implements` Symfony `SessionInterface` / bag call = TypeError/method-not-found (app: 0).
  - Done when: jar/store shape = L13; middleware disiapkan (aktif saat kernel flip); app data-API tak tersentuh.

- [ ] **2.13 — Log: `Writer`→`Logger`, `getMonolog`→`getLogger`** `[fork+app · M]` `B1(+B2 tail)` · blocked-by: 1.1, 2.7(Config)
  - Steps: `[fork]` rename class `Writer`→`Logger`, `getMonolog()`→`getLogger()`; `write($level,$message,array $context=[]):void`;
    hapus `useFiles/useDailyFiles/useErrorLog` (pindah ke LogManager); bind `'log'` ke LogManager singleton;
    ctor `(Psr\Log\LoggerInterface, Contracts\Events\Dispatcher)`. `[app]` 3 `getMonolog()` caller
    (`CoreLogServiceProvider` :30/:41 + test) → `getLogger()` **+ rewrite Monolog 1→3** (Level enum, handler API).
    `[ops]` author `config/logging.php` channels.
  - Enforcement: `getMonolog()`/`use Writer` = not-found. **Monolog API di caller = NOT type** → manual.
  - Done when: 335 `Log::` PSR-3 utuh; 3 getMonolog caller di-rewrite; Psalm hijau.

- [ ] **2.14 — View: hapus named-view sugar + rename internal** `[fork+app · S]` `B1` · blocked-by: SCC-1 (nanti)
  - Steps: `[fork]` hapus `Factory::of()/name()/alias()` (line 153/165/177); rename `flushSections`→`flushState`.
    **Keep** `BladeCompiler::extend()/createMatcher()` (jangan strip pre-swap). `[app]` 2 `Blade::extend`
    (`DicodingServiceProvider` :180/:186) → `Blade::directive()`. `[ops]` flush `storage/framework/views` di swap.
  - Enforcement: `of/name/alias` = method-not-found. **`Blade::extend` callback = NOT type** → manual/grep.
  - Done when: 2 Blade::extend → directive; Psalm hijau.

- [ ] **2.15 — Pagination: rename getter + reshape Factory/Presenter** `[fork+app · M]` `B2` · blocked-by: 1.1
  - Steps: `[fork]` rename `getCurrentPage`→`currentPage` (+ lastPage/firstItem/lastItem/total/perPage),
    `getItems()`→`items()`; hapus Factory object + Presenter/BootstrapPresenter; ctor Paginator drop `$factory`.
    `[app]` `PaginationPresenter.php` → Blade view; `Factory::class->setCurrentPage` (`ParentComments...` :83)
    → `currentPageResolver`; 2 `new Paginator($factory,...)` test; sweep `->getItems()`→`items()`.
    `[ops]` `useBootstrapFour/Five()` untuk approx markup (slider view tak ada di L13).
  - Enforcement: getter lama = method-not-found; `extends Presenter`/`Factory::class` = class-not-found; `new Paginator($factory)` = TypeError.
  - Done when: 3 site reshape + items() sweep; `->links()` (164) utuh; Psalm hijau.

- [ ] **2.16 — Mail: retype send/queue + drop closure-queue** `[fork+app · L]` `B2` · blocked-by: 1.1, Queue L13-shaped
  - Steps: `[fork]` introduce `Mailable/PendingMail/SendQueuedMailable`; `send():?SentMessage`; `queue()/later()`
    first param `Mailable`; hapus `failures()` + `SerializableClosure` `mailer@handleQueuedMessage`; split ke MailManager.
    `[app]` ~17 closure/callback (Mail::send 13 + Mail::queue 4) → Mailable class; drop 3 `isPretending/failures`.
    `[ops]` `mail.php` → `mailers.*`.
  - Enforcement: closure-queue = method-not-found; `queue(Closure)` = TypeError; `send()` void→?SentMessage (Psalm).
  - Done when: ~17 site → Mailable; Psalm hijau. (Depends: Queue L13-shaped untuk SendQueuedMailable.)

- [ ] **2.17 — Testing: rewrite ~500 file test ke fluent TestResponse** `[fork+app · L]` `B2` · blocked-by: SCC-1, Foundation-shape
  - Steps: `[fork]` (1) introduce `Illuminate\Testing\TestResponse` (75 assert) + `Fluent\AssertableJson` +
    L13 `Foundation TestCase` (return `Illuminate\Http\Response`). (2) hapus legacy `assertResponseOk/
    assertResponseStatus/assertRedirectedTo/see()/$this->call()` (BrowserKit) dari `Foundation\Testing`.
    `[app]` ~500 file test → fluent `$response->assert*`.
  - Enforcement: `assertResponseOk` dll = method-not-found (PHP+Psalm), ratcheting ~500 file.
  - Done when: grep `assertResponseOk|assertResponseStatus|->call(` di test = 0; suite hijau di PHPUnit 11/12.

---

## Wave 3 — App-space extract & de-sugar (yang tak punya padanan v13; urutan bebas)

- [ ] **3.1 — Ekstrak `Html` (Form/HTML) ke package app-space** `[fork→app · M]` `B2` · blocked-by: Routing/Session/Support shape
  - Why: `illuminate/html` **tak ada padanan** di L13; ~484 blade pakai `Form::`/`HTML::`. Preserve API verbatim.
    (Catatan: fork `replace` `illuminate/html` → saat Fase-5 drop dari `replace`; tak ada real package pengganti.)
  - Steps: lift `FormBuilder/HtmlBuilder/HtmlServiceProvider` verbatim ke package app-space (namespace app),
    keep binding `'html'/'form'` + facade alias; fix 3 mekanis: `bindShared()`→`singleton()`,
    `MacroableTrait`→`Macroable`, rebind `session.store` ke Session L13.
  - Enforcement: **bukan type** (API preserved) → **boot-smoke / 1 integration test** render `Form::open`.
  - Done when: 484 blade render identik; `illuminate/html` tak dari core.

- [ ] **3.2 — CachedRouting: de-sugar 28 `Route::cache()`** `[fork+app · S]` `B2` · blocked-by: Routing L13-shaped
  - Steps: `[fork]` hapus `Router::cache()/clearCache()` + subtree CachedRouting + rebinding provider.
    `[app]` 28 `Route::cache(__FILE__, fn)` (app/routes.php + 24 file app/routes/) → de-sugar ke registrasi biasa;
    pastikan **tak ada** Closure-based route (native `route:cache` menolak). `[ops]` `php artisan route:cache` di pipeline deploy.
  - Enforcement: `Route::cache()` = method-not-found (28 site menyala).
  - Done when: grep `Route::cache(` app = 0; `route:cache` masuk pipeline.

- [ ] **3.3 — Tarik logika bisnis keluar framework** `[app · XL, continuous]`
  - Why: kode framework-agnostic **selamat dari swap** → swap murah.
  - Steps: tiap sentuh controller/model gemuk, ekstrak logika ke service/class PHP polos (tanpa Eloquent/facade di domain).
  - Done when (per-modul): logika modul X di service teruji unit; controller tipis.

---

## Wave 4 — Swap bottom-up per cluster (§6 langkah c; urut wajib; tiap swap = edit `replace` fork §1a)

> Namespace `Illuminate\*` collide + fork/stock sama-sama `laravel/framework` yang `replace`
> illuminate/* → **tak bisa co-install**. Swap **per-cluster**: tiap langkah **hapus entry dari
> blok `replace` fork** lalu `composer require illuminate/<pkg>:^13`. Interim = fork +
> `illuminate/<swapped>:^13` individual. Prasyarat tiap swap: Wave 1–2 komponen terkait ✅.

- [ ] **4.1 — SCC-1 core cutover (satu window)** `[fork · XL]` · blocked-by: 1.1–1.4, 2.2, 2.4, 2.5, 2.9, 2.10, 2.12, 2.13
  - Cluster: `contracts+reflection+support+container` (jangkar) + `http, session, cache, cookie,
    encryption, events, filesystem, redis, database` + **`bus`** (transitif hard-require `events`).
    Swap ke `illuminate/*` v13 **bersamaan**.
  - Composer (§1a): hapus ~12 entry dari blok `replace` fork (support/container/http/session/cache/
    cookie/encryption/events/filesystem/redis/database + html **tetap** sampai 3.1); `composer require`
    `illuminate/{contracts,collections,macroable,conditionable,reflection,pipeline,support,container,
    http,session,cache,cookie,encryption,events,bus,filesystem,redis,database}:^13`. Contracts/
    collections/macroable/conditionable/reflection/pipeline (yang fork tak replace, dulu copy-source)
    swap ke real package **di sini** (Support fork di-copot → tak lagi collide).
  - Prasyarat: PHP 8.3 runtime; helper-shim `str_*`/`array_*` survive di Support sampai swap; Redis
    client config; Encryption cipher pinned; Cache Psalm-rule bare-int aktif (guard pasca-swap).
  - Done when: SCC-1 komponen di classpath v13; entry `replace` cluster terhapus; boot hijau; app test hijau.

- [ ] **4.2 — Swap `console` ke illuminate/console v13** `[fork · M]` · blocked-by: 2.6, 4.1, Foundation Kernel wiring
  - Note koreksi: **console BUKAN L0** — `illuminate/console:^13` **hard-require** `illuminate/view`
    (menyeret container/events/filesystem/session/support) → **butuh SCC-1 (4.1) selesai**. Namespace
    collide → atomik; butuh app fire()→handle() (2.6) + Kernel; hapus `console` dari `replace` fork.
  - Done when: console v13 di classpath; boot-smoke tiap command hijau; app test hijau.

- [ ] **4.3 — Swap L2 per-unit** `[fork · L]` · blocked-by: 4.1, 4.2
  - Urut: `config` (2.7) → `log` (2.13) → `translation` → `hashing` (2.11) → `view` (2.14) →
    `validation` → `routing` (2.8; hard-require session/SCC-1 ✅) → `queue` (hard-require
    database+console ✅). Tiap swap per-unit: hapus entry `replace` fork → `composer require illuminate/<pkg>:^13`.
  - Done when: tiap komponen L2 di classpath v13; entry `replace` terhapus; app test hijau.

- [ ] **4.4 — Delete evaporate components** `[fork · S]` · blocked-by: sesuai
  - `Workbench` (hapus dir + entry `replace`), `Exception` (merge ke Foundation Handler; fork tak
    replace exception? — verified fork **replace** `illuminate/exception` → hapus entry saat merge),
    `CachedRouting` (3.2 selesai), `Html`-in-core (3.1 selesai; hapus `illuminate/html` dari `replace`).
    **Jangan port.**

- [ ] **4.5 — SCC-2 terminal (the flip): foundation+mail+auth** `[fork+app · XL]` · blocked-by: 4.1, 4.3, 2.16, 2.17
  - Steps: putus cycle (Mail via Mailer contract, Auth via Contracts) → swap `foundation+mail+auth` ke v13.
    `auth` **hard-require** `queue` (L2 ✅ di 4.3). `[app]` bootstrap rewrite (`start.php`→`bootstrap/app.php`
    builder, `App::error`→`withExceptions`, Kernel middleware arrays), testing base swap. `[ops]`
    **dual-read Auth** (§8). Composer: hapus `foundation/mail/auth` dari `replace` fork → require real.
  - Enforcement: `App::error/missing/fatal/down` = method-not-found; **bootstrap baru = grep/boot-smoke** (§7D).
  - Done when: foundation+mail+auth di classpath v13; entry `replace` terhapus; app boot & test hijau.

---

## Wave 5 — Data cutover + fork = 0

- [ ] **5.1 — 🔴 Data cutover** `[ops · M]` · blocked-by: 4.5
  - **Drain antrian** SQS/Redis sampai kosong (wire-format fork tak terbaca worker L13).
  - **Flush** Blade compiled cache (`storage/framework/views`).
  - **Invalidate** DB cache (Cache DatabaseStore drop Encrypter → row lama undecryptable).
  - Migrasi tabel `password_reminders` → `password_resets`.
  - Pasang **dual-read** Auth recaller cookie + session key + Cookie v2 HMAC.

- [ ] **5.2 — Cutover trafik** `[ops · M]` · blocked-by: 5.1
  - Arahkan trafik ke stok L13; copot semua shim/alias; buang core fork.

- [ ] **5.3 — Verifikasi fork = 0 (gate: `replace` fork kosong)** `[fork+app · S]` · blocked-by: 5.2
  - **Prasyarat resolver:** blok `replace` fork **= 0 entry** (kalau tidak, dua `laravel/framework`
    deadlock). `composer require laravel/framework:^13` stock; verifikasi tak ada `Illuminate\*` fork tersisa.
  - Done when: fork dihapus; app jalan di stock L13; `composer why laravel/framework` = hanya stock.

- [ ] **5.4 — Post-cutover: hapus dual-read; PERTAHANKAN guard pasca-swap** `[app · S]` · blocked-by: 5.2 + TTL remember-me lewat
  - Setelah TTL remember-me lewat, hapus kode dual-read Auth.
  - **JANGAN hapus** Psalm-rule Cache-TTL bare-int + grep Console `function fire(` — dua guard ini
    **tidak self-liquidating** (stock 13 re-admit bare int / resolve `__invoke`), jadi permanen.

---

## Papan ringkas (dependency)

```
0.1 0.2 0.3
   │
1.0 (split+replace-map) → 1.1 (Contracts) → 1.2 → 1.3 (copy-source) ─┐
                                    └→ 1.4 (putus SCC-1) ─────────────┤
   │                                                                   │
   ├─► Wave 2 (2.2–2.17, paralel setelah 1.1) ─┐
   ├─► Wave 3 (3.1–3.3) ─────────────────────────────────┤
   │                                                       │
   └─► Wave 4 (4.1 SCC-1+Bus → 4.2 console[hard-req view] → 4.3 L2 → 4.4 → 4.5 SCC-2) ─► Wave 5 (5.1→5.4)
        (tiap swap: hapus entry `replace` fork §1a; Fase-5 gate replace=0)
```

**Mulai dari:** 0.1–0.3 → **1.0 split+replace-map + 1.1 Contracts** (buka semua) → Wave 2
(rekomendasi **2.2 Cache: fork-tighten TTL → app konform + guard** duluan; bekas 2.1 digabung ke 2.2).
Wave 4 swap butuh Wave 1–2 komponen terkait ✅ dan **SCC-1 (4.1) selesai sebelum semua L2** (hard-require).

> Referensi: detail apiDelta/risks per komponen → `MIGRATION-DETAIL.md`. Matriks enforcement per
> komponen → `MIGRATION-ENFORCEMENT-MATRIX.md`. Gotcha → `MIGRATION-ROADMAP.md` §7. Replace-collision
> mekanik → `MIGRATION-ROADMAP.md` §1a.