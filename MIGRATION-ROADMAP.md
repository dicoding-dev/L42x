# L42x → Laravel 13 — Migration Roadmap & Dependency Graph

> Status: living document. **Endgame TIDAK berubah** = aplikasi jalan di **Laravel 13 stock
> (unforked)**, fork dihapus. **Approach BERUBAH (2026-09-10, Agis)** dari "gradual convergence +
> CI ratchet" menjadi **FRAMEWORK-FIRST + STRICT-MODE-L13 + ENFORCEMENT-VIA-TYPE**.
> Basis data: graph dependency **nyata** hasil ekstraksi `use`-statement dari
> `src/Illuminate/*` (fork = `laravel/framework` 4.2.90) dan
> `../framework/src/Illuminate/*` (Laravel 13.30.1), plus 42 record verified di
> `.migration-verified-records.json` + re-klasifikasi 3-bucket (Sept 2026).
>
> **Koreksi review (2026-09-10, verified vs kode):** (1) Cache-TTL **BUKAN** type-expressible di
> stock 13 (`put($key,$value,$ttl=null)` untyped di `Cache/Repository.php:367` **dan**
> `Contracts/Cache/Repository.php:29`; `getSeconds()` `:903` terima bare int) → di-reklasifikasi
> **tighten-then-lint**, bukan self-liquidating type. (2) Console `fire()→handle()` **BUKAN**
> abstract/Psalm-catch di stock 13 (`Command.php:289` `method_exists($this,'handle')?'handle':'__invoke'`
> → fire()-only jatuh ke `__invoke` missing = runtime `BadMethodCall`) → di-reklasifikasi
> **fork-only temporary-abstract ratchet + post-swap grep/boot-smoke**. (3) **Composer replace
> collision**: fork **dan** stock keduanya `laravel/framework`; fork `replace` 27 `illuminate/*`,
> stock `replace` 37 → dua `laravel/framework` **tak bisa co-install**; swap-per-cluster butuh
> **edit blok `replace` fork** tiap langkah. (4) **Swap-order** di-re-derive dari **hard-require**
> v13 (bukan suggest). (5) **Collections early** hanya **copy SOURCE ke tree Support fork**, bukan
> `composer require illuminate/collections` (fork masih punya `Support/Collection.php` on-disk → collide).

---

## 0. Ringkasan eksekutif

**Konteks.** Fork `dicoding-dev/L42x` = `laravel/framework` **4.2.90** monolitik yang dijaga
hidup di PHP 8.3/8.5. Upstream 4.2 sudah EOL bertahun-tahun → **tidak ada patch security/fitur
dari vendor**. Tim kecil → tidak sanggup jadi security-maintainer core Illuminate selamanya.
Endgame tetap: **hapus fork, jalan di stock Laravel 13** (`composer require laravel/framework:^13`).

**Approach baru — FRAMEWORK-FIRST.** Alih-alih menggeser app dulu lalu "the flip", kita
**konvergenkan FORK-nya** menuju L13, lalu **swap tiap komponen ke package `illuminate/*` v13
asli, satu dependency-cluster demi cluster, sampai fork = 0**. Fork diubah jadi
**"STRICT-MODE Laravel 13"**: signature publik-nya diperketat agar **sama-atau-lebih-ketat**
dari stock 13, sehingga idiom 4.2 lama menjadi `TypeError` / method-not-found — tertangkap PHP
saat runtime DAN Psalm secara statis (Psalm sudah jalan di CI app).

**Enforcement VIA TYPE — tapi tidak semua break bisa jadi type.** Aturan hidup di **kontrak
framework** (single source of truth) bila break-nya type-expressible. **Verified koreksi:** ada
dua kelas break yang **tidak** self-liquidating lewat type:
- **Tighten-then-lint** (Cache-TTL): stock 13 lebih **longgar** dari yang kita butuhkan
  (`$ttl=null`, terima bare int). Fork yang diperketat ke `DateTimeInterface|DateInterval`
  **lebih ketat dari stock** → saat swap, package v13 **kembali menerima bare int** → bug
  60× senyap **kembali tak-terjaga**. Maka: fork-tighten hanya **memaksa migrasi app**, tapi
  **guard permanen pasca-swap = Psalm-rule / ratchet-grep**, bukan type.
- **Fork-only temporary-abstract** (Console `handle()`): stock 13 me-resolve `handle`-or-`__invoke`
  di runtime (`Command.php:289`), jadi `fire()`-only bukan error abstract/Psalm melainkan runtime
  miss. Fork bikin `handle()` abstract **sementara** untuk me-ratchet ~308 rewrite; post-swap
  deteksi = **grep + boot-smoke**.

Ratchet CI **didemote tapi TIDAK dihapus**: ia adalah tempat berlabuh permanen untuk (a) pola yang
tak bisa jadi type sama sekali (SQL string-concat, missing global helper `str_*`/`array_*`,
route-array `'before'=>`, config assertion, wire-format), **dan** (b) **guard pasca-swap** untuk
tighten-then-lint (Cache-TTL) dan temporary-abstract (Console) yang strictness fork-nya menguap
saat swap.

**Tiga bucket per perubahan** (klasifikasi utama tiap komponen — lihat §6):

| Bucket | Definisi | Enforcement | Kerja app |
|---|---|---|---|
| **1 — FORK-ONLY** | idiom yang **juga ada** di stock 13 (Contracts, `dispatch()`, pagination `currentPage()`, Macroable) | fix di dalam fork; app tak tersentuh | **nol** |
| **2 — APP-UNAVOIDABLE** | API **dihapus** di stock 13 (`fire()`, `->lists()`, `getEnvironment()`, route filters, `illuminate/html`) | perketat/hapus signature di fork → app WAJIB adopsi idiom baru **sebelum** swap (shim mati saat swap). *Enforcement type bila removal; grep bila hanya reshape.* | ya, sebelum swap |
| **3 — VALUE-SEMANTICS** | signature **sama** tapi arti beda; framework tak bisa membedakan (Cache TTL menit→detik) | fork perketat param **sementara** (interval-only) → paksa migrasi app; **guard permanen = Psalm-rule/ratchet-grep** (stock 13 re-admit bare int → tidak self-liquidating) | ya, sudah/harus pass interval |

**Anti-goal.** Endgame **BUKAN** "L130x" (Laravel 13 yang di-fork). "Strict-mode L13" hanya
**perancah** untuk memindahkan app ke idiom L13 sebelum swap — begitu semua komponen di-swap ke
`illuminate/*` v13 asli, fork = 0 dan tidak ada lagi yang di-maintain. Semua kustomisasi →
**extension point** (`Auth::extend`, custom driver, macro, service provider). **Core Laravel
haram di-patch** — kalau tidak, jebakan fork terulang.

**Angka kepala:**

| Metrik | Nilai |
|---|---|
| Komponen di-verify (record) | 42 |
| Bucket 1 (fork-only, nol app work) | Contracts, Collections, Conditionable, Reflection, Http, Redis, Log, View, Translation, Queue, Validation, Auth, Bus, Broadcasting, Concurrency, Image, JsonSchema, Notifications, Process, Container, Workbench |
| Bucket 2 (app-unavoidable removal) | Support, Macroable, Pipeline, Events, Encryption, Cookie, Session, Filesystem, Database, Console, Config, Exception, Foundation, Mail, CachedRouting, Html, Pagination, Routing, Hashing, Testing |
| Bucket 3 (value-semantics) | Cache (TTL menit→detik) — **tighten-then-lint, bukan self-liquidating** |
| **Cyclic clusters (SCC) di core fork** | **2** — satu 11-node (SCC-1), satu 3-node (SCC-2) |
| Sudah "done"/kerja app selesai | Cache task 1.1 (bare-int TTL → Carbon interval) — DONE, tetap valid |
| Fork `replace` illuminate/* (verified) | **27** (auth, cache, config, console, container, cookie, database, encryption, events, exception, filesystem, foundation, hashing, http, html, log, mail, pagination, queue, redis, routing, session, support, translation, validation, view, workbench) |
| Stock `replace` illuminate/* (verified) | **37** (superset; incl. contracts, collections, macroable, conditionable, reflection, pipeline, bus, testing, broadcasting, concurrency, image, json-schema, notifications, process) |

> Catatan: banyak komponen "campuran" — bucket ditetapkan berdasarkan **item load-bearing**-nya
> (mis. Database mostly flip-only tapi diklasifikasikan bucket 2 karena `->lists()` removal +
> pluck-swap yang tak-terhindarkan). Detail per-komponen & rasional bucket ada di
> `MIGRATION-ENFORCEMENT-MATRIX.md`.

---

## 1. Temuan struktural utama — DUA CYCLE (tervalidasi, JANGAN dilemahkan)

Graph `use`-statement mengungkap core fork **bukan hierarki bersih** melainkan mengandung dua
**strongly-connected component (SCC)**. **Anggota satu SCC tidak bisa di-swap satu per satu**;
mereka bergerak sebagai klaster ATAU cycle-nya diputus dulu via interface (`Contracts`).
Di frame framework-first ini SCC memaksa **swap-per-cluster** — DAN diperkuat oleh **composer
replace-collision** (§1a): namespace `Illuminate\*` bertabrakan + kedua package bernama
`laravel/framework` → **tak bisa co-exist di classpath** → satu SCC harus di-swap dalam satu window.

### SCC-1 — "the core ball of mud" (11 komponen)
```
support, container, http, session, cache, database,
cookie, encryption, events, filesystem, redis
```
**Akar penyebab (tervalidasi empiris):** `Support` seharusnya lapisan dasar, tapi:
- `src/Illuminate/Support/Facades/Response.php` → `use Illuminate\Http\...`
- `src/Illuminate/Support/Traits/CapsuleManagerTrait.php` → `use Illuminate\Container\Container`

Facade menyebut kelas **konkret** lintas framework → `support → http → session → cache
→ database → container → support`. Seluruh stack request/data terjerat jadi satu simpul.

**Cara memutusnya = PREREQUISITE #1:** perkenalkan package `Contracts` (interface) + alihkan
Facade/binding dari import konkret ke interface. **Ini langkah PERTAMA** (Prasyarat, §5) yang
memecah SCC-1. Setelah putus, "SCC-1 core cutover" adalah **satu swap cluster low-risk** —
low-risk justru karena saat itu fork sudah berperilaku seperti 13.

> ⚠️ **Gotcha graph (verified):** naive dep-graph melaporkan **false cycle** dari 5 cross-import
> type-hint-only di `Contracts` (database/http/image/support/validation). **JANGAN** promosikan
> jadi `composer require` — kalau dilakukan, setiap foundation package deadlock.

### SCC-2 — "application bootstrap knot" (3 komponen)
```
foundation, mail, auth
```
**Akar penyebab (tervalidasi):**
- `Auth/Reminders/PasswordBroker.php` → `use Illuminate\Mail\...` (email reset password)
- `Mail/MailServiceProvider.php` → `use Illuminate\Foundation\Application` (konkret)
- `Foundation` → bootstrap `Auth`

`foundation → auth → mail → foundation`. Diputus dengan **re-point Mail lewat Mailer contract**
dan **Auth lewat Contracts** (agar Foundation tak lagi import Mail/Auth konkret). Praktisnya
klaster ini = **terminal cutover** (the flip aplikasi).

### 1a. Composer replace-collision — lebih kuat dari namespace-collision (VERIFIED, koreksi review)

Bukan hanya namespace `Illuminate\*` yang bertabrakan. **Fork dan stock keduanya bernama
`laravel/framework`** (verified `composer.json`), dan keduanya punya blok `replace`:

- **Fork** `replace` **27** `illuminate/*` (incl. `illuminate/html`, `illuminate/workbench`;
  **TIDAK** ada `contracts`, `collections`, `macroable`, `conditionable`, `reflection`, `pipeline`,
  `bus`, `testing`).
- **Stock** `replace` **37** `illuminate/*` (superset — semua di atas ada).

`replace` berarti fork **mengklaim menyediakan** `illuminate/cache` dst. Konsekuensi Composer:

1. **Fork + real `illuminate/cache:^13` tak bisa co-install** — resolver menolak karena fork
   sudah `replace` `illuminate/cache`. Ini konflik **lebih kuat** dari sekadar class-collision.
2. **Dua `laravel/framework` tak bisa co-install** sama sekali (nama package identik).

**Mekanik swap-per-cluster (konsekuensi):**
- Interim state = **fork (`laravel/framework` fork) + `illuminate/<swapped>:^13`** yang di-`require`
  **individual**. Untuk tiap cluster yang di-swap, **hapus entry-nya dari blok `replace` fork**
  lalu `composer require illuminate/<pkg>:^13`. Selama entry masih di `replace`, real package ditolak.
- Untuk paket yang fork **tak** `replace` tapi stock `replace` (contracts/collections/macroable/
  conditionable/reflection/pipeline): **tak bisa** `composer require illuminate/<pkg>:^13` selama
  fork masih on-disk menyediakan kelas namespace itu (mis. `Support/Collection.php`) → introduce =
  **copy SOURCE ke tree fork**, real swap menyusul di window Support (§4/§5).
- **Fase 5 gate:** `composer require laravel/framework:^13` **hanya** setelah blok `replace` fork
  **kosong** (0 entry tersisa) — kalau tidak, resolver deadlock antara dua `laravel/framework`.

---

## 2. Dependency graph (bottom-up, per layer) — swap **bottom-up per cluster**

Panah = "butuh ini dulu (L13-shaped/swapped)". Kerja **bottom-up**. `[…]` = SCC (swap satu
window; replace-collision + namespace-collision memaksa cluster-swap).

> **Koreksi review — layer di-re-derive dari HARD-REQUIRE v13 (bukan suggest):**
> - **Console BUKAN L0.** `illuminate/console:^13` **hard-require** `illuminate/view` (menyeret
>   container/events/filesystem/session/support) → **tak bisa mendahului SCC-1**. Console pindah ke L2.
> - **Bus BUKAN opsional.** `illuminate/events:^13` (anggota SCC-1) **hard-require** `illuminate/bus`
>   → Bus adalah **transitif non-opsional** dari Events → **diperkenalkan bersama SCC-1**, bukan SKIP.
> - **Semua L2 gated ke SELURUH cluster SCC-1.** `queue`→require `database`(SCC-1)+`console`;
>   `auth`→require `queue`; `routing`→require `session`(SCC-1). "L2-parallel" hanya berlaku **setelah
>   SCC-1 selesai**; tak ada L2 yang boleh mendahului SCC-1.

```
PREREQ  split monolith fork → potongan illuminate/*-shaped
        + edit-ready blok `replace` (§1a) untuk dibuka per-cluster
        + INTRODUCE Contracts (leaf, realDeps=[])   ← MEMUTUS SCC-1, membuka semua
          + leaf traits (COPY SOURCE ke tree Support fork, BUKAN composer require):
            Macroable, Conditionable, Reflection, Collections, Pipeline
      │
L1  ┌─────────────────── CYCLE / SCC-1 core cutover ───────────────────┐
    │ support ⇄ container ⇄ http ⇄ session ⇄ cache ⇄                    │  ← 11-node ball of mud;
    │ database ⇄ cookie ⇄ encryption ⇄ events ⇄                         │    swap SATU cluster
    │ filesystem ⇄ redis    (Contracts+Reflection+BUS ikut jangkar)     │    setelah Contracts putus
    └───────────────────────────────────────────────────────────────────┘  (Bus = hard-require Events)
      │
L2   console  hashing  config  exception  translation  log  view        ← SEMUA butuh SCC-1 selesai;
     workbench  routing  validation  queue                                 tiap swap per-unit
      │   (console→view; queue→database+console; routing→session;          (replace+namespace collide)
      │    auth→queue — semua hard-require, dipenuhi oleh SCC-1)
L3  ┌── SCC-2 (terminal / the flip) ──┐
    │ foundation ⇄ mail ⇄ auth         │   cachedrouting  html  pagination
    └──────────────────────────────────┘   (auth juga hard-require queue → L2 dulu)
```

**Critical path (batas bawah durasi):**
```
[SCC-1 core cluster (+Bus)]  →  console  →  hashing  →  [foundation+mail+auth]
```
Panjang 4. Kerja lain paralel di balik rantai ini. **Catatan:** console pindah dari kepala rantai
(dulu keliru di L0) ke setelah SCC-1 karena hard-require `view`.

### Klaster yang WAJIB bergerak bareng (karena replace/namespace collision / SCC / hard-require)
- **SCC-1 core:** `contracts + reflection + support + container` jadi jangkar; `http, session,
  cache, cookie, encryption, events, filesystem, redis, database` swap dalam window yang sama.
  **`bus` ikut** (hard-require `events`). Tiap entry di-`require` individual setelah dihapus dari
  blok `replace` fork (§1a).
- **SCC-2 (terminal):** `foundation + mail + auth` — putus cycle dulu (Mailer contract + Auth
  Contracts), lalu swap sebagai the flip. `auth` juga hard-require `queue` (L2).
- **Standalone-but-atomic (L2, setelah SCC-1):** `console`, `config`, `translation`, `log`, `view`,
  `routing`, `validation`, `queue`, `hashing`, `pagination` — bukan di SCC, tapi replace+namespace
  collide → swap per-unit; masing-masing punya hard-require yang dipenuhi SCC-1.

---

## 3. Rencana paralelisasi

Komponen **satu layer tanpa edge antar-mereka** = boleh dikerjakan concurrent — **tapi SEMUA L2
gated ke SCC-1 selesai** (hard-require), jadi paralelisme L2 baru terbuka pasca-SCC-1.

| Layer | Batch paralel | Lebar | Catatan |
|---|---|---|---|
| **Prereq** | split monolith + `replace`-map + `Contracts` + leaf traits (copy source) | 1 | **membuka semua**; harus lebih dulu |
| **1** | **SCC-1 core cutover** (11 komponen **+ Bus** transitif) | 1 klaster | **tidak bisa dipecah**; swap satu window setelah Contracts memutus cycle; hapus 11+ entry dari `replace` fork |
| **2** | `console`, `hashing`, `config`, `exception`, `translation`, `log`, `view`, `workbench`, `routing`, `validation`, `queue` | **11** | **titik paralel terlebar — tapi baru setelah SCC-1**; tiap swap per-unit; hard-require (console→view, queue→database, routing→session) sudah dipenuhi SCC-1 |
| **3** | **SCC-2** `[foundation, mail, auth]`, `cachedrouting`, `html`, `pagination` | 4 | terminal; SCC-2 = the flip; auth juga butuh queue (L2) |

**Choke point:** `support` dan `container`. Memutus cycle di `support` (Facade → kontrak)
**membuka SCC-1**, dan SCC-1 selesai **membuka seluruh L2**. Karena itu Prasyarat (Contracts +
Support-shape) diprioritaskan.

---

## 4. Fondasi baru L13 — INTRODUCE lebih dulu (memutus cycle)

Komponen ini **tidak ada di fork 4.2** tapi di L13 hampir semua bergantung padanya. Mereka
duduk **di bawah `Support`** dan **memutus cycle** SCC-1. Introduce = **ship verbatim dari stock
13** (bukan hand-port), jaga divergence dari upstream.

> **Koreksi review — "introduce EARLY" ≠ "composer require EARLY".** Fork `replace` `illuminate/support`
> tapi **TIDAK** `replace` `collections/contracts/macroable/conditionable/reflection/pipeline`. Real
> `illuminate/collections:^13` mengirim `Illuminate\Support\{Collection,Arr,Enumerable}` yang
> **collide** dengan `src/Illuminate/Support/Collection.php` fork yang masih on-disk. Maka introduce
> EARLY = **copy SUPERSET SOURCE ke dalam tree Support fork** (namespace fork), **BUKAN**
> `composer require illuminate/collections`. Real package baru masuk **di window swap SCC-1** saat
> Support fork di-copot.

| Komponen | Bucket | Effort | Peran / cara introduce |
|---|---|---|---|
| **Contracts** | 1 | low | 155 interface, PSR-only composer require (**JANGAN** tambah `illuminate/*` → false cycle). **PREREQ #1, memutus SCC-1.** Fork tak `replace` contracts → aman di-require verbatim atau di-copy. |
| **Collections** | 1 | high | **copy SOURCE** superset ke `Illuminate\Support\*` tree fork (Collection/`Enumerable`/`LazyCollection`/`Arr`). Bukan call-site churn — **behavior-parity testing** (59→111 method). Real `illuminate/collections` swap **di window SCC-1**, bukan sekarang. |
| **Macroable** | 2 | trivial | rename `MacroableTrait`→`Macroable` (nama pendek hilang di 13), copy di tree Support. Bucket 2 karena rename = removal. |
| **Conditionable** | 1 | trivial | 2 file verbatim di `Support/Traits/Conditionable.php` + `Support/HigherOrderWhenProxy.php`. Additive murni. Copy source. |
| **Reflection** | 1 | trivial | `Reflector` **sudah ada** (stub 39-line); ganti dengan superset stock 13, **tetap fisik di tree Support** (jangan bikin dir baru). |
| **Pipeline** | 2 | low | copy source verbatim; **inert** sampai Routing/Kernel/Bus/Queue reshape (filter→middleware). Bucket 2 karena removal-nya ada di Routing (filter dihapus). |
| **Bus** | 1 | medium | **BUKAN SKIP** (koreksi review). `illuminate/events:^13` **hard-require** `illuminate/bus` → Bus transitif **wajib** ikut window SCC-1. App tetap 0 `Illuminate\Bus` call-site (pakai `DomainTransactionCommandBus` sendiri), jadi **nol app work**, tapi package-nya **harus terpasang** saat Events di-swap. |

> Fitur L13-baru lain (**semua bucket 1, SKIP by default / YAGNI**): `Broadcasting`,
> `Concurrency`, `Image`, `JsonSchema`, `Notifications`, `Process`, `Testing`. App tak pakai
> subsistem Illuminate-nya (grep = 0). `Access\Gate` ikut gratis di package `Auth` L13.
> `Testing` adalah pengecualian **bucket 2**: package-nya baru, tapi idiom LAMA yang digantinya
> (`assertResponseOk`/`$this->call`/crawler) **ada** di `Foundation\Testing` fork dan dipakai
> ~500 file test → rewrite wajib sebelum swap (lihat §6/matrix).
> **Catatan Bus:** beda dari YAGNI lain — Bus **tak boleh** di-skip karena hard-require Events.

---

## 5. Prasyarat #1 — SPLIT MONOLITH + INTRODUCE CONTRACTS + REPLACE-MAP (buka semua)

Fork sekarang = `laravel/framework` **monolitik** (satu package, `replace` 27 `illuminate/*`).
Real `illuminate/*` v13 = puluhan package terpisah dengan namespace `Illuminate\*` yang **sama**
DAN keduanya bernama `laravel/framework`. Konsekuensi:

1. **Replace + namespace collision** → fork dan v13 tak bisa co-exist di classpath → swap **wajib
   per-cluster**, bukan per-file bebas. Tiap swap butuh **edit blok `replace` fork** (§1a).
2. **SCC-1 harus diputus** sebelum core-nya bisa di-swap → introduce `Contracts` agar Facade/
   binding/import pindah dari kelas konkret ke interface.

**Langkah Prasyarat (urut):**
1. **Introduce `Contracts`** verbatim dari stock 13 (semua 33 subdomain) — pure leaf
   (`realDeps=[]`, `blockers=[]`). Jangan tambah `illuminate/*` ke composer-nya. Fork tak
   `replace` contracts → tak ada konflik.
2. **Introduce leaf traits/util** dengan **copy SOURCE** ke bawah Support, **tetap fisik di tree
   Support** (namespace, bukan package boundary, yang penting di monolith):
   `Macroable` (rename dari `MacroableTrait`), `Conditionable` + `HigherOrderWhenProxy`,
   `Reflector` superset + `ReflectsClosures`, `Collections` shape superset.
   **JANGAN** `composer require illuminate/collections|macroable|conditionable|pipeline` sekarang —
   collide dengan on-disk fork Support (§4). Real package menyusul di window SCC-1.
3. **Alihkan Facade/binding** dari import konkret ke interface `Contracts\*` → **SCC-1 putus**.
4. **Siapkan `replace`-map (§1a):** dokumentasikan tiap entry `replace` fork → package v13 target +
   urutan pembukaannya (SCC-1 cluster dulu, lalu L2 per-unit, lalu SCC-2). Belum diedit sekarang;
   entry dihapus **saat** cluster-nya di-swap (Fase 4).

Setelah ini, pola berulang per-komponen (§6) bisa jalan.

---

## 6. Pola berulang per-komponen (STRICT-MODE-L13 loop)

Untuk **tiap** komponen, tiga langkah:

**(a) Tighten fork type → strict-mode-L13.** Perketat signature publik fork agar sama/lebih
ketat dari stock 13. Idiom 4.2 lama jadi `TypeError`/method-not-found. Enforcement hidup di
kontrak framework (single source of truth) — **kecuali** dua kelas di bawah.

**(b) App conforms — DIPAKSA PHP+Psalm (bucket 2/3 saja).** Karena signature diperketat, call-site
lama gagal compile/resolve. App migrasi ke idiom baru. **Bucket 1 = SKIP langkah ini** (app tak
tersentuh — idiom juga ada di stock 13).

**(c) Swap komponen ke `illuminate/*` v13 asli.** Hormati replace+namespace-collision → hapus entry
dari blok `replace` fork (§1a), lalu `composer require illuminate/<pkg>:^13`, swap per-cluster
bottom-up. Setelah swap, shim/alias di fork mati.

**Dua pengecualian di mana strictness fork TIDAK bertahan pasca-swap (butuh guard non-type):**
- **Tighten-then-lint (Cache-TTL, bucket 3).** Fork perketat TTL → interval-only **sementara**,
  memaksa app pass interval. Tapi stock 13 `put($k,$v,$ttl=null)` (verified `Repository.php:367`,
  `Contracts/Cache/Repository.php:29`) **re-admit bare int** saat swap → bug 60× senyap kembali
  **tak-terjaga**. **Guard permanen = Psalm-rule/ratchet-grep** yang melarang bare-int TTL, hidup
  **melewati** swap. Bukan self-liquidating.
- **Fork-only temporary-abstract (Console `handle()`).** Fork bikin `handle()` abstract
  **sementara** untuk me-ratchet ~308 rewrite `fire()`. Tapi stock 13 me-resolve
  `method_exists($this,'handle')?'handle':'__invoke'` (verified `Command.php:289`) → `fire()`-only
  jatuh ke `__invoke` missing = **runtime `BadMethodCall`**, bukan abstract/Psalm error. Post-swap
  deteksi = **grep + boot-smoke**, bukan type.

Untuk pola yang **TIDAK type-expressible** (missing global helper `str_*`/`array_*`, SQL
string-concat, config assertion seperti default cipher / redis client, wire-format payload,
Blade-cache flush, route-array `'before'=>`): **pakai ratchet CI grep / boot-guard / runbook**.

### 6a. Ringkasan bucket + enforcement per-komponen

Detail lengkap (rasional bucket, fork strict action, app impact, swap order) ada di
**`MIGRATION-ENFORCEMENT-MATRIX.md`**. Ringkas di sini:

| Komponen | Bucket | Type-expr? | Enforcement inti | Kerja app |
|---|---|:---:|---|---|
| **Contracts** | 1 | ✅ (by presence) | introduce verbatim; binding type-hint interface | ~0 (1-file rename ikut Support) |
| **Support** | 2 | ⚠️ sebagian | rename `MacroableTrait`→`Macroable`, hapus `Support\Contracts\*Interface`; helper `str_*`/`array_*` → **ratchet (bukan type)** | kecil: 1 file interface + audit helper |
| **Collections** | 1 | ❌ (copy source, superset) | copy source ke tree Support; parity test; **real swap di SCC-1 window** (collide fork on-disk) | nol |
| **Macroable** | 2 | ✅ (rename) | `use ...MacroableTrait` → trait-not-found | nol app (framework-internal) |
| **Conditionable** | 1 | ❌ (copy source) | copy 2 file | nol |
| **Pipeline** | 2 | ❌ (removal ada di Routing) | copy source; filter-removal di Routing | indirect (filter→middleware) |
| **Reflection** | 1 | ✅ (trivial, sudah aman) | keep `Support\Reflector` superset | nol |
| **Container** | 1 | ✅ | hapus `share/isShared/bindShared/resolvingAny`; relokasi exception ke Contracts (alias) | ~0 (11 ref, 10 test, alias jaga) |
| **Http** | 1 | ✅ | hapus `FrameGuard`; internal `Support\Contracts\*`→`Contracts\Support\*` | nol |
| **Cache** | **3** | ⚠️ **tighten-then-lint** | **fork tighten TTL param → `DateTimeInterface\|DateInterval`** (bare int TypeError) untuk **memaksa migrasi app**; **guard permanen pasca-swap = Psalm-rule/ratchet-grep** (stock 13 `$ttl=null` re-admit bare int → strictness fork menguap). `StoreInterface`→`Contracts\Cache\Store`. **Fork-internal bare-int caller (verified: `CacheBasedSessionHandler.php:60`, `ArrayStore.php:74`, `ApcStore.php:94`) harus dikonversi di commit yang sama** atau fork tak boot. | task 1.1 DONE; 5 custom Store pindah contract |
| **Events** | 2 | ✅ | add `dispatch()` canonical, deprecate lalu hapus `fire()/queue()/firing()/forgetQueued()`; drop `$priority` | ~96 `fire()`→`dispatch()` + review 118 `listen()` |
| **Encryption** | 2 | ✅ | hapus `setKey()`; implement `Contracts\Encryption\*`; relokasi `DecryptException` (alias) | ~0 code; config `cipher=AES-256-CBC` (deploy) |
| **Cookie** | 2 | ⚠️ sebagian | widen jar param (additive); **rewrite Guard/Queue decorator → middleware** (flip-time) | ~0 code; runtime logout sekali di flip |
| **Session** | 2 | ⚠️ sebagian | Store `implements Contracts\Session\Session`, hapus bag API; Middleware → StartSession/AuthenticateSession | ~0 code (402 site di data-API stabil) |
| **Redis** | 1 | ❌ (wholesale replace + config) | drop-in RedisManager; config `client` = **config-guard, bukan type** | nol |
| **Filesystem** | 2 | ✅ (alias) | relokasi `FileNotFoundException` ke Contracts + `class_alias` (`@deprecated`) | 11 import (aliasable, deferrable) |
| **Database** | 2 | ⚠️ campuran | hapus `lists()`; rename `pluck`→`value` + `pluck` semantik-baru; `SoftDeletingTrait`→`SoftDeletes`; `ArrayableInterface`→`Arrayable`. **pluck-swap NOT type-expressible** → grep/Psalm-rule + review | **sangat besar**: ~173 lists + ~193 pluck + ~21 rename |
| **Queue** | 1 | ✅ (removal kecil) | hapus closure-push branch + Iron.io driver; **string-push + `fire()` handler tetap valid di 13** | 2 closure push; ops: **drain queue** di cutover |
| **Console** | 2 | ⚠️ **fork-only temp-abstract** | fork **sementara** `execute()` panggil `abstract handle()` untuk me-ratchet ~308 rewrite; **stock 13 resolve handle-or-`__invoke` runtime → post-swap deteksi = grep/boot-smoke, bukan abstract/Psalm**; hapus `Application::make/start/...` | **besar**: ~308 `fire()`→`handle()`, ~87 getArguments/Options→`$signature`, Kernel wiring |
| **Config** | 2 | ✅ | hapus `getEnvironment()` + loader/package-cascade; ctor `(array)` | ~48 site `getEnvironment()`→`App::environment()` (incl. blade) |
| **Exception** | 2 | ❌ (closure/string-binding) | hapus `App::error()`/bindings → merge ke Foundation Handler | **besar**: 11 `App::error` + custom ExceptionServiceProvider rewrite |
| **Translation** | 1 | ✅ | hapus `trans()/transChoice()`, implement `Contracts\Translation\Translator` | nol (app pakai `Lang::get`/`__`) |
| **Log** | 1 | ✅ | rename `getMonolog()`→`getLogger()`, `Writer`→`Logger`; hapus `useFiles/useDailyFiles` | 3 `getMonolog()` (CoreLogServiceProvider + Monolog 1→3) |
| **View** | 1 | ❌ (subset, sudah benar) | hapus `Factory::of/name/alias`; Blade `extend` **bukan** type → grep/manual | 2 `Blade::extend`→`directive` |
| **Workbench** | 1 | ❌ (delete) | hapus dir + composer require | nol |
| **Routing** | 2 | ✅ (method removal) | hapus `filter/before/after/when/callFilter/controller`; drop HttpKernelInterface. Route-array `'before'=>` **bukan** type → grep | **besar**: ~52 filter + 141-line filters.php + 131 route-array → middleware |
| **Validation** | 1 | ✅ (contract identity) | Validator `implements Contracts\Validation\Validator` (drop old) | nol (18 `make`, 0 custom rule) |
| **Hashing** | 2 | ✅ | `HasherInterface` extend/alias `Contracts\Hashing\Hasher`, lalu delete | 4 site type-hint → contract |
| **Foundation** | 2 | ❌ (positive bootstrap) | hapus `App::error/missing/fatal/down/middleware`, drop HttpKernel/Terminable; **reshape bootstrap = grep/boot-smoke** | **sangat besar**: bootstrap rewrite + testing base |
| **Mail** | 2 | ✅ | `send():?SentMessage`, hapus `failures()`/closure-queue, `queue()` terima Mailable | ~17 closure/callback → Mailable |
| **Auth** | 1 | ✅ (self-liquidating, app kosong) | drop `attempt()` 3rd arg, relokasi UserInterface→Contracts, AuthManager `implements Factory` | ~0 (853 file di facade stabil; 0 breaking site) |
| **Bus** | 1 | ❌ (introduce, transitif Events) | **BUKAN skip** — hard-require `illuminate/events` → wajib terpasang di SCC-1 window; copy verbatim (sudah strict) | nol (0 `Illuminate\Bus` call) |
| **CachedRouting** | 2 | ✅ (removal) | hapus `Router::cache()/clearCache()` + subtree | 28 `Route::cache(__FILE__,fn)` de-sugar |
| **Html** | 2 | ❌ (preserve API) | **app-space-extract** verbatim; enforcement = boot-smoke, bukan type | 0 view edit; pindah 3 file provider |
| **Pagination** | 2 | ✅ (mostly) | rename getter (`getCurrentPage`→`currentPage`…); hapus Factory/Presenter | ~3 site (Presenter/Factory) + `items()` rename |
| **Testing** | 2 | ✅ (removal di Foundation) | hapus `assertResponseOk/$this->call/crawler` dari `Foundation\Testing` | **besar**: ~500 file test → fluent `TestResponse` |

---

## 7. ⚠️ Gotcha kritis migrasi (verified — JANGAN dilemahkan)

Jebakan konkret yang ditemukan agent saat baca kode fork vs L13. **Prioritaskan yang "silent".**

**A. Silent behavior change (paling bahaya — tak ada error, hasil beda diam-diam)**
- 🔴 **Cache TTL menit → detik** (bucket 3, **tighten-then-lint — BUKAN self-liquidating**).
  `Cache::put/add/remember($k,$v,N)` TTL numerik: fork = N **menit**, L13 = N **detik** → 60×
  lebih pendek, senyap. **Enforcement dua tahap:** (1) fork **sementara** tighten param ke
  `DateTimeInterface|DateInterval` → bare int = `TypeError` → paksa migrasi app; (2) **guard
  permanen pasca-swap = Psalm-rule/ratchet-grep** melarang bare-int TTL — karena stock 13
  `put($k,$v,$ttl=null)` (verified `Repository.php:367`, `Contracts/Cache/Repository.php:29`,
  `getSeconds():903`) **kembali menerima bare int**, strictness fork **menguap saat swap**. Task
  1.1 (app) **DONE** (sudah pass Carbon interval). Follow-on: fork tighten (memaksa) + Psalm-rule
  (menjaga). **Fork-internal bare-int caller (verified: `CacheBasedSessionHandler.php:60` `put($id,$data,$this->minutes)`,
  `ArrayStore.php:74` & `ApcStore.php:94` `put($key,$value,0)`) harus dikonversi bareng tighten**
  atau fork tak boot.
- 🔴 **Database `pluck`↔`value`/`lists` name-swap** (bucket 2, **NOT type-expressible**).
  fork `pluck`=single value → L13 `value()`; fork `lists`=column array → L13 `pluck()`.
  193 `->pluck()` site berubah arti diam-diam. Rename fork memaksa review; **grep/Psalm-rule**
  cegah dev "memperbaiki" balik ke `pluck()` dengan makna salah.
- 🟠 **Database casting.** Timestamp default, `$dateFormat`, null vs empty-string, strict-mode.
- 🟠 **Redis default Predis → phpredis** (config assertion, bukan type): pin `client=>predis`
  bila `ext-redis` absen.
- 🟠 **Encryption default cipher** (fork AES-256-CBC vs L13 aes-128-cbc): boot-guard config,
  bukan signature — pin `cipher=AES-256-CBC` sebelum swap atau cookie/cache lama undecryptable.

**B. Persisted-state pecah saat flip (data ditulis fork tak terbaca L13)**
- 🔴 **Auth recaller cookie & session** — format "remember me" beda → logout massal.
  **Wajib dual-read** (§8).
- 🔴 **Cookie `EncryptCookies` v2.** L13 mem-prefix HMAC (`CookieValuePrefix 'v2'`) & memvalidasi;
  cookie terenkripsi lama fork ditolak saat flip (logout sekali, expected).
- 🔴 **Queue wire-format.** Payload ter-serialisasi fork **tak bisa** dikonsumsi worker L13.
  **Drain antrian sampai kosong saat cutover** (runbook, bukan type).
- 🟠 **Auth `Reminders` → `Passwords`.** Tabel `password_reminders` → `password_resets`.
- 🟠 **View Blade compiled cache** wajib di-flush di `storage/framework/views` saat swap.
- 🟠 **Cache DatabaseStore drop Encrypter** — row cache-table terenkripsi lama tak terbaca;
  invalidate/flush DB cache di cutover.

**C. Rename mekanis yang type-caught (idiom lama jadi TypeError/method-not-found)**
- `Events` `fire()` → `dispatch()` (**~96 callsite**); `firing()`/`$priority` dihapus.
- `Support` `MacroableTrait` → `Macroable`; `Support\Contracts\*Interface` → `Contracts\Support\*`.
- `Pagination` getter: `getCurrentPage`→`currentPage`, dll.; `getItems()`→`items()`.
- `Database` `->lists()` → `->pluck()`; `SoftDeletingTrait`→`SoftDeletes`; `ArrayableInterface`→`Arrayable`.
- `Config` `getEnvironment()` → `App::environment()`.
- `Cache` `StoreInterface` → `Contracts\Cache\Store`.
- `Hashing` `HasherInterface` → `Contracts\Hashing\Hasher`.
- `Filesystem` `Illuminate\Filesystem\FileNotFoundException` → `Contracts\Filesystem\...` (alias).
- **`Console` `fire()` → `handle()` (~308 def)** — type-caught **HANYA di fork** (temporary
  abstract). **Menguap saat swap** (stock resolve `handle`-or-`__invoke` runtime) → post-swap =
  grep/boot-smoke, lihat §7D.

**D. Enforcement grep/boot/runbook (NOT type-expressible / strictness fork menguap saat swap)**
- 🔴 **Cache bare-int TTL — guard PERMANEN** (strictness fork menguap; stock re-admit bare int)
  → **Psalm-rule / ratchet-grep** yang hidup melewati swap.
- 🔴 **Console `fire()`-only command pasca-swap** (stock jatuh ke `__invoke` missing =
  runtime `BadMethodCall`, bukan Psalm) → **grep `function fire(` + boot-smoke tiap command**.
- Global helper `str_*`/`array_*` (helpers.php 59→23) — missing function fatal saat call, tak
  terlihat Psalm → **ratchet grep**.
- `Routing` route-array `'before'=>'auth'` (array key, bukan method call) → **grep/Psalm-rule**.
- `Foundation` bootstrap `bootstrap/app.php` builder / Kernel middleware arrays / `withExceptions`
  → **grep + boot-smoke test** (file yang tak panggil builder baru bukan type error).
- `Exception` `App::error(Closure)` + `$app['exception']` string-binding → **grep**.
- SQL string-concat / `whereRaw` → **ratchet grep** (SQLi surface).
- Redis client / Encryption cipher / config channels logging.php → **boot-guard / config assertion**.

> Struktural: **package `Contracts` tidak ada sama sekali di tree fork**. Banyak rename di atas
> bermuara ke relokasi namespace `Illuminate\Contracts\*` → **introduce `Contracts` = Prasyarat**
> (§5) yang membuka mayoritas sweep rename ini.

---

## 8. Auth — transition dual-read (agar flip tanpa logout massal) — TIDAK berubah

`Guard` menyimpan state di format Illuminate yang **sudah beredar** di user:
- recaller cookie: `{user_id}|{remember_token}` (dienkripsi app key)
- session key user login: `login_{sha1(class)}`

Saat swap Auth, **jangan** langsung ganti format. Pola zero-downtime:
1. **Baca dua format**: recaller & session key lama (fork) *dan* format baru L13.
2. **Tulis** hanya format baru.
3. Format lama kedaluwarsa alami seiring TTL remember-me.
4. Hapus kode dual-read setelah TTL lewat.

> Catatan Auth (verified): app pakai facade stabil di **853 file**, dan **0 call-site** untuk
> setiap API yang dihapus (attempt 3rd arg, Reminders, custom provider, manager driver). Jadi
> Auth = **bucket 1** (fork-only) untuk app ini; strictness self-liquidating gratis. Yang tersisa
> = dual-read state format (runtime), bukan code edit. Catatan swap: `illuminate/auth:^13`
> **hard-require** `illuminate/queue` → Auth swap butuh Queue (L2) sudah L13-shaped.

---

## 9. ⛔ Anti-goal (kotak peringatan — DIPERKUAT)

```
╔══════════════════════════════════════════════════════════════════════╗
║  ENDGAME = LARAVEL 13 STOCK (UNFORKED). FORK DIHAPUS, FORK = 0.       ║
║                                                                       ║
║  "STRICT-MODE L13" cuma PERANCAH: fork yang signature-nya diperketat  ║
║  agar app pindah ke idiom L13 SEBELUM tiap komponen di-swap ke        ║
║  illuminate/* v13 asli. Ia SELF-LIQUIDATING untuk removal (fire/      ║
║  lists/getEnvironment) — hilang begitu swap selesai. TAPI DUA KELAS   ║
║  TIDAK self-liquidating & butuh guard non-type PERMANEN:              ║
║   • Cache-TTL (stock re-admit bare int) → Psalm-rule/ratchet.         ║
║   • Console handle() (stock resolve handle|__invoke) → grep/boot.     ║
║                                                                       ║
║  Enforcement lewat TYPE (PHP+Psalm) bila removal; ratchet CI          ║
║  DIDEMOTE tapi TETAP untuk: pola non-type (helper, SQL-concat,        ║
║  config) DAN guard pasca-swap tighten-then-lint di atas.              ║
║                                                                       ║
║  Fork & stock SAMA-SAMA laravel/framework & sama-sama replace         ║
║  illuminate/* → TAK BISA co-install. Swap per-cluster = hapus entry   ║
║  replace fork tiap langkah; Fase-5 gate: replace fork = 0 baru        ║
║  composer require laravel/framework:^13.                              ║
║                                                                       ║
║  Semua kustomisasi → EXTENSION POINT L13:                            ║
║    Auth::extend, custom cache/queue/route driver, macro,              ║
║    middleware, service provider, published config.                    ║
║  CORE LARAVEL HARAM DI-PATCH.                                          ║
╚══════════════════════════════════════════════════════════════════════╝
```

---

## 10. Roadmap berfase (framework-first)

### Fase 0 — Enabler (sekali kerja)
- **WAF di edge** (Cloudflare/AWS) — tutup surface security core 4.2 tak terpatch **sekarang**.
- **`../framework` L13 sebagai north-star** signature (single source of truth kontrak).
- **Ratchet CI didemote** — untuk pola non-type (helper global, SQL-concat, config) **DAN** guard
  pasca-swap tighten-then-lint (Cache-TTL bare-int) + fork-only temp-abstract (Console fire-only).

### Fase 1 — PRASYARAT: split monolith + introduce Contracts + replace-map (§5)
Split fork monolitik → potongan `illuminate/*`-shaped; introduce `Contracts` + leaf traits
(**copy source**, bukan composer require); alihkan Facade/binding ke interface → **putus SCC-1**.
Siapkan `replace`-map (§1a) untuk dibuka per-cluster. **Ini membuka semua.**

### Fase 2 — Strict-mode tighten (bucket 2/3, per-komponen, tanpa swap)
Perketat signature fork komponen-per-komponen (§6 langkah a). App conforms, dipaksa PHP+Psalm
(§6 langkah b). Cache TTL tighten (+ konversi fork-internal caller + tambah Psalm-rule guard),
Events `dispatch()`, Database `lists`/`pluck`/`SoftDeletes`, Console `handle()` (temp abstract),
Config `getEnvironment()`, Routing filters→middleware, dst. Mesin masih fork.

### Fase 3 — App-space extract (yang tak punya padanan v13)
`Html` extract verbatim ke package app-space (0 view edit). `CachedRouting` de-sugar 28 site.
Logika bisnis ditarik ke PHP polos (selamat dari swap).

### Fase 4 — Swap bottom-up per cluster (§6 langkah c; tiap swap = edit `replace` fork §1a)
1. **SCC-1 core cutover** (contracts+reflection+support+container jangkar; http/session/cache/
   cookie/encryption/events/filesystem/redis/database + **bus** transitif satu window). Hapus
   ~12 entry dari `replace` fork; `composer require illuminate/<pkg>:^13` masing-masing.
2. **L2** per-unit (baru terbuka setelah SCC-1; hard-require dipenuhi): console (butuh view),
   config, translation, log, view, routing (butuh session), validation, hashing, queue (butuh
   database+console). Tiap: hapus entry `replace` → require real.
3. **SCC-2 terminal (the flip):** putus cycle (Mailer contract + Auth Contracts) → swap
   foundation+mail+auth (auth butuh queue L2) → dual-read Auth (§8) → drain queue, flush Blade
   cache, migrasi tabel.

### Fase 5 — Fork = 0
Copot semua shim/alias; hapus komponen evaporate (CachedRouting/Workbench/Exception/Html-in-core);
buang core fork; **verifikasi blok `replace` fork = 0 entry** (prasyarat resolver) → `composer
require laravel/framework:^13` stock, tanpa fork; verifikasi tak ada `Illuminate\*` fork tersisa.
Hapus dual-read setelah TTL remember-me lewat. **Pertahankan** Psalm-rule Cache-TTL bare-int +
grep Console fire-only (guard pasca-swap yang tak self-liquidate).

---

## 11. Risk register

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Framework EOL, tanpa patch vendor (core Illuminate) | security surface tak terjaga | WAF (Fase 0); swap ke v13 asli mengakhiri maintainer-burden |
| Cycle SCC-1 menghalangi swap terpisah | migrasi macet | **Prasyarat #1**: introduce `Contracts` → putus SCC-1 (§5) |
| **Fork & stock sama-sama `laravel/framework` & replace illuminate/*** | **tak bisa co-install (resolver conflict)** | swap **per-cluster** + **edit blok `replace` fork tiap langkah** (§1a); Fase-5 gate replace=0 |
| Namespace `Illuminate\*` collide fork↔v13 | tak bisa co-exist | swap **per-cluster** (SCC-1 core sebagai satu window) |
| Idiom 4.2 lolos ke prod (bucket 2 removal) | regresi senyap | **enforcement via type** (PHP+Psalm), self-liquidating |
| **Cache-TTL bare-int kembali pasca-swap** (stock re-admit) | **bug 60× senyap balik** | **Psalm-rule/ratchet-grep PERMANEN** (tighten fork hanya memaksa migrasi, tak menjaga) |
| **Console `fire()`-only lolos pasca-swap** (stock jatuh ke `__invoke`) | command mati runtime | **grep + boot-smoke tiap command** (temp-abstract fork menguap saat swap) |
| Pola non-type lolos (helper/SQL-concat/config) | regresi | ratchet grep + boot-guard + runbook (§7D) |
| Endgame jadi fork baru "L130x" | jebakan terulang | anti-goal (§9): strict-mode = perancah, fork=0 |
| Flip Auth me-logout semua user | insiden produksi | **dual-read transition** (§8) |
| Queue wire-format / Cookie v2 / DB-cache | data tak terbaca | drain queue + invalidate cache + logout-sekali di cutover (runbook) |
| SQL injection di query string-concat | data breach | audit `whereRaw`/concat → binding (Fase 2, ratchet) |
| Fork tak boot setelah Cache tighten (internal bare-int caller) | build merah | konversi `CacheBasedSessionHandler:60`+`ArrayStore:74`+`ApcStore:94` di commit yang sama |

---

## 12. Metodologi & keterbatasan

- **Edges** = `grep -rhoE 'use Illuminate\\[A-Za-z]+'` per direktori komponen di kedua repo,
  dedupe lowercase, exclude self. Deterministik & tervalidasi manual.
- **SCC/layer/critical-path** = Tarjan + topological layering (deterministik), divalidasi manual
  (akar cycle di-grep langsung: Facade→Http, CapsuleTrait→Container, PasswordBroker→Mail,
  MailServiceProvider→Foundation). **Layer L2 di-re-derive dari HARD-REQUIRE v13** (console→view,
  queue→database+console, auth→queue, routing→session), bukan suggest — console pindah dari L0 ke L2.
- **Verifikasi & re-klasifikasi 3-bucket per-komponen** = workflow multi-agent adversarial (baca
  kode fork + L13, grep count nyata di app `dicoding`, koreksi klasifikasi). Data mentah di
  `.migration-verified-records.json` (42 record) + prosa `MIGRATION-DETAIL.md`; matriks
  enforcement `MIGRATION-ENFORCEMENT-MATRIX.md`.
- **Koreksi enforcement dari review (verified vs kode, 2026-09-10):**
  - **Cache-TTL BUKAN type-expressible di stock 13.** `Cache/Repository.php:367`
    `put($key,$value,$ttl=null)` + `Contracts/Cache/Repository.php:29` (untyped) + `getSeconds():903`
    (terima bare int). Fork interval-only lebih ketat dari stock → **tidak self-liquidating** →
    reklasifikasi tighten-then-lint (guard permanen = Psalm/ratchet).
  - **Console `handle()` BUKAN abstract/Psalm-catch.** `Command.php:289`
    `method_exists($this,'handle')?'handle':'__invoke'` → `fire()`-only jatuh ke `__invoke` missing =
    runtime `BadMethodCall`. Reklasifikasi fork-only temporary-abstract + post-swap grep/boot-smoke.
  - **Composer replace-collision.** Fork & stock sama-sama `laravel/framework`; fork replace 27,
    stock replace 37 `illuminate/*` → tak bisa co-install → swap-per-cluster butuh edit blok
    `replace` fork (§1a).
  - **Collections early = copy source, bukan composer require.** Fork replace `support` tapi tak
    replace `collections`; real `illuminate/collections` collide `Support/Collection.php` fork
    on-disk → introduce = copy source ke tree Support, real swap di window SCC-1.
  - **Database hard-require** (dari review): `illuminate/database:^13` hard-require **hanya**
    container/support/contracts/collections/conditionable/macroable; events/filesystem/console =
    suggest. Klaim lama "drag pagination/http/queue/broadcasting" **dikoreksi** — itu bukan hard
    require; swap-window tetap benar via namespace/replace.
  - **Bus non-opsional.** `illuminate/events:^13` hard-require `illuminate/bus` → Bus wajib ikut
    SCC-1 window (bukan SKIP/YAGNI seperti record lama).
- **Koreksi bucket dari re-klasifikasi (tetap berlaku):**
  - Record lama menyebut Exception "nothing to migrate, effort low" — **SALAH untuk app**: 11
    `App::error` + custom ExceptionServiceProvider harus di-rewrite → medium.
  - Record lama menyiratkan Queue "every job must become ShouldQueue" — **SALAH**: L13 KEEP
    string-push + `fire()` default → bucket 1, ~2 closure site saja.
  - Record lama Hashing "done, no imports" — **SALAH**: 4 site type-hint `HasherInterface`
    (dihapus di 13) → bucket 2 kecil.
- **Keterbatasan**: klaim app-space (grep count) ditemukan agent di repo `dicoding` — verifikasi
  ulang saat digarap. `.env`-specific config dir (`app/config/local/…`) harus direkonsiliasi
  dengan model single-dir + `env()` L13 saat Foundation flip. Hard-require graph L2 (console→view
  dst) berbasis metadata package v13 yang dikutip review; verifikasi ulang `composer.json` tiap
  split-package saat swap.