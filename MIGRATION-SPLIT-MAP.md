# L42x → Laravel 13 — Migration SPLIT-MAP / REPLACE-MAP / Opening Order

> Status: living document (framework-first lens). Companion ke `MIGRATION-ROADMAP.md`
> (graph + fase) dan `MIGRATION-GRAPH.md`. Fokus doc ini: **mekanika `composer.json`
> `replace`** — kapan tiap entry fork boleh dilepas, dan urutan buka (opening order)
> yang dihitung **bottom-up** dari `illuminate/*` hard-require v13 yang **otoritatif**
> (per-package `../framework/src/Illuminate/<Comp>/composer.json` → `require`).
>
> Basis data: fork `laravel/framework` **4.2.90** (`dicoding-dev/L42x`) vs stock
> `laravel/framework` **13.30.1**. Fork `replace` = **27 entry** (terverifikasi di
> `composer.json`). Stock `replace` = 38 entry. Delta = fork-only (evaporate) vs
> stock-only (introduce).

---

## 0. Mengapa doc ini ada — invarian kunci

Fork adalah **satu paket monolitik** `laravel/framework` yang `replace`-kan 27
sub-paket `illuminate/*` dari namespace `Illuminate\*`. Stock Laravel 13 memecah
`illuminate/*` jadi **paket terpisah**, tetapi **tetap** di namespace `Illuminate\*`
dan **tetap** di dalam metapackage `laravel/framework`.

**Dua invarian yang mengunci seluruh strategi:**

1. **Namespace collision** — fork on-disk memiliki `Illuminate\Support\`,
   `Illuminate\Container\`, dst. Paket v13 mengklaim namespace yang **sama**.
2. **Package-name collision** — fork **dan** tiap paket split sama-sama masuk lewat
   metapackage `laravel/framework`. Dua paket tak bisa co-install.

Konsekuensi: **tak ada `composer require illuminate/<x>:^13` standalone** selama fork
masih `replace`-kan entry itu (Composer akan menolak: paket sudah "disediakan"). Karena
itu:

- Swap harus **per-cluster**, bukan per-paket bebas.
- Entry `replace` dilepas **PADA SAAT swap cluster-nya**, bukan sekarang, bukan lebih awal.
- Paket fondasi baru yang **tabrakan on-disk** (`Illuminate\Support\` di-split) tak bisa
  di-`require` — harus **copy-source** ke pohon dulu.

> **Catatan label SCC (baca dulu):** label `SCC-1`/`SCC-2` di doc ini menamai **cycle
> di source fork 4.2** (Facade konkret ⇄ implementasi, per `MIGRATION-ROADMAP.md` §2) —
> **bukan** cycle di graf require v13. Graf hard-require v13 sendiri adalah **DAG murni,
> tak punya require-cycle sama sekali** (lihat §5). Yang memaksa anggota satu cluster
> flip bareng adalah **co-install collision** (namespace `Illuminate\*` + package-name
> `laravel/framework` yang sama), bukan require-cycle v13. Jadi kolom **Cluster** di
> tabel = pengelompokan flip-bareng yang dipaksa collision, diberi nama SCC fork.

---

## 1. SPLIT-MAP — fork `src/Illuminate/<Comp>` → target v13

Kolom **v13 target**: `real-package` (ada `composer.json` sendiri di
`../framework/src/Illuminate/<Comp>/`) · `core-absorbed` (tak ada `composer.json`
terpisah → masuk metapackage `laravel/framework`) · `evaporate` (tak ada di v13,
dihapus). Kolom **v13 hard-require** = `require` **otoritatif** (sudah dipangkas dari
`suggest`/self) per `composer.json` v13. Kolom **Cluster** = grup flip-bareng (nama SCC
= source fork 4.2, bukan cycle v13 — lihat §0).

| # | fork `src/Illuminate/<Comp>` | v13 target | v13 package | v13 hard-require (`illuminate/*`) | Cluster |
|---|---|---|---|---|---|
| 1 | Support | real-package | `illuminate/support` | collections, conditionable, contracts, macroable, reflection | **SCC-1-core** |
| 2 | Container | real-package | `illuminate/container` | contracts, reflection | **SCC-1-core** |
| 3 | Http | real-package | `illuminate/http` | collections, conditionable, macroable, session, support | **SCC-1-core** |
| 4 | Session | real-package | `illuminate/session` | collections, contracts, filesystem, support | **SCC-1-core** |
| 5 | Cache | real-package | `illuminate/cache` | collections, contracts, macroable, support | **SCC-1-core** |
| 6 | Database | real-package | `illuminate/database` | collections, conditionable, container, contracts, macroable, support | **SCC-1-core** |
| 7 | Cookie | real-package | `illuminate/cookie` | collections, contracts, macroable, support | **SCC-1-core** |
| 8 | Encryption | real-package | `illuminate/encryption` | contracts, support | **SCC-1-core** |
| 9 | Events | real-package | `illuminate/events` | bus, collections, container, contracts, macroable, reflection, support | **SCC-1-core** |
| 10 | Filesystem | real-package | `illuminate/filesystem` | collections, conditionable, contracts, macroable, support | **SCC-1-core** |
| 11 | Redis | real-package | `illuminate/redis` | collections, contracts, macroable, support | **SCC-1-core** |
| 12 | Console | real-package | `illuminate/console` | collections, contracts, macroable, reflection, support, view | **leaf (L0→L2-gated)** |
| 13 | Config | real-package | `illuminate/config` | collections, contracts | **L2** |
| 14 | Log | real-package | `illuminate/log` | conditionable, contracts, support | **L2** |
| 15 | Translation | real-package | `illuminate/translation` | collections, contracts, filesystem, macroable, reflection, support | **L2** |
| 16 | View | real-package | `illuminate/view` | collections, conditionable, container, contracts, events, filesystem, macroable, reflection, support | **L2** |
| 17 | Routing | real-package | `illuminate/routing` | collections, conditionable, container, contracts, http, macroable, pipeline, reflection, session, support | **L2** |
| 18 | Validation | real-package | `illuminate/validation` | collections, conditionable, container, contracts, macroable, support, translation | **L2** |
| 19 | Queue | real-package | `illuminate/queue` | collections, console, container, contracts, database, filesystem, pipeline, reflection, support | **L2** |
| 20 | Hashing | real-package | `illuminate/hashing` | contracts, support | **L2** |
| 21 | Foundation | core-absorbed | *(none — in `laravel/framework`)* | — | **SCC-2** |
| 22 | Mail | real-package | `illuminate/mail` | collections, conditionable, container, contracts, macroable, support | **SCC-2** |
| 23 | Auth | real-package | `illuminate/auth` | collections, contracts, http, macroable, queue, support | **SCC-2** |
| 24 | Pagination | real-package | `illuminate/pagination` | collections, contracts, support | **SCC-2 (terminal leaf)** |
| 25 | Exception | evaporate | *(absorbed → Foundation)* | — | **SCC-2** |
| 26 | Html | evaporate | *(none — app-space extract)* | — | **leaf (L3 terminal)** |
| 27 | Workbench | evaporate | *(none — orchestra/testbench)* | — | **L2** |
| — | CachedRouting | evaporate | *(none — native `route:cache`)* | — | **leaf (L3 terminal)** — *NOT in fork replace* |

**Catatan verifikasi otoritatif:**
- Grep mentah `composer.json` v13 menyertakan **self** (mis. `illuminate/support` di
  paket Support) + `suggest`/dev. Kolom hard-require di atas = **hanya blok `require`**,
  self dibuang. Contoh koreksi penting:
  - **Container** — grep menampilkan auth/cache/config/database/log, tapi itu semua
    `suggest`. `require` sejati = **contracts + reflection** (plus `psr/container`,
    polyfill symfony). Bukan `illuminate/support`.
  - **Cache** — database/filesystem/redis/console = `suggest` (driver-optional), bukan
    hard-require → cache flip tanpa menunggu mereka sebagai hard-dep.
  - **Database** — broadcasting/console/events/http/pagination/queue = `suggest`.
  - **Validation** — database = `suggest` (presence verifier), bukan hard-require.
  - **Queue** — redis = `suggest`; bus/log/foundation **tidak** di `require` langsung.
  - **Mail** — `illuminate/http` = `suggest` ("Required to create an attachment from an
    UploadedFile instance"), **bukan** hard-require → Mail flip **tanpa** menggerbang
    http. (Auth, sebaliknya, **memang** hard-require http — jangan tertukar.)
- **Foundation** — `../framework/src/Illuminate/Foundation/composer.json` **tidak ada**
  (terverifikasi) → `core-absorbed`, tak ada paket untuk di-`require`.

---

## 2. REPLACE-MAP + OPENING ORDER

### 2a. Prinsip perhitungan (bottom-up)

> **Aturan pelepasan entry:** entry `replace` sebuah paket boleh dilepas **hanya
> setelah SEMUA `illuminate/*` hard-require v13-nya sudah tersedia v13-shaped** (baik
> sudah di-copy-source, sudah di-`require`, atau sudah flip di cluster yang sama).

Karena banyak hard-require menunjuk ke paket fondasi baru yang **tabrakan on-disk**
(contracts/collections/macroable/conditionable/reflection), fondasi itu harus di-seed
**sebagai source** lebih dulu (§3), baru cluster core boleh flip.

### 2b. REPLACE-MAP — 27 entry fork → target + kapan dilepas

| fork `replace` entry | v13 target | Kapan entry DILEPAS | Alasan (hard-require gate) |
|---|---|---|---|
| `illuminate/support` | `illuminate/support` | **W4 · SCC-1 window** | butuh collections+conditionable+contracts+macroable+reflection (semua di-seed §3) v13 dulu; choke point cycle |
| `illuminate/container` | `illuminate/container` | **W4 · SCC-1 window** | butuh contracts+reflection (seed §3); choke point kedua |
| `illuminate/http` | `illuminate/http` | **W4 · SCC-1 window** | butuh session+support (intra-SCC-1) + collections/conditionable/macroable (seed) |
| `illuminate/session` | `illuminate/session` | **W4 · SCC-1 window** | butuh filesystem+support (intra-SCC-1) + collections/contracts (seed) |
| `illuminate/cache` | `illuminate/cache` | **W4 · SCC-1 window** | butuh support (intra-SCC-1) + collections/contracts/macroable (seed) |
| `illuminate/database` | `illuminate/database` | **W4 · SCC-1 window** | butuh container+support (intra-SCC-1) + collections/conditionable/contracts/macroable (seed) |
| `illuminate/cookie` | `illuminate/cookie` | **W4 · SCC-1 window** | butuh support (intra-SCC-1) + collections/contracts/macroable (seed); encryption edge bukan composer hard-dep |
| `illuminate/encryption` | `illuminate/encryption` | **W4 · SCC-1 window** | butuh support (intra-SCC-1) + contracts (seed) — flip terbersih SCC-1 |
| `illuminate/events` | `illuminate/events` | **W4 · SCC-1 window** | butuh container+support (intra-SCC-1) + **bus** & **reflection** (bus=require-late §3; reflection=seed) + collections/macroable |
| `illuminate/filesystem` | `illuminate/filesystem` | **W4 · SCC-1 window** | butuh support (intra-SCC-1) + collections/conditionable/contracts/macroable (seed); *session hard-requires filesystem → wajib v13 di window yang sama* |
| `illuminate/redis` | `illuminate/redis` | **W4 · SCC-1 window** | butuh support (intra-SCC-1) + collections/contracts/macroable (seed) |
| `illuminate/hashing` | `illuminate/hashing` | **W4 · L2** | hard-require paling ramping (contracts+support); model L2-flip, buka begitu SCC-1 support + contracts siap |
| `illuminate/config` | `illuminate/config` | **W4 · L2** | butuh collections+contracts (seed) + support-family v13 (SCC-1); **bukan** filesystem (I/O pindah ke bootstrapper) |
| `illuminate/log` | `illuminate/log` | **W4 · L2** | butuh support (SCC-1) + conditionable/contracts (seed); events di-*drop* jadi kontrak (bukan hard-dep v13) |
| `illuminate/translation` | `illuminate/translation` | **W4 · L2** | butuh filesystem+support (SCC-1) + collections/contracts/macroable/reflection (seed) |
| `illuminate/view` | `illuminate/view` | **W4 · L2** | butuh container/events/filesystem/support (SCC-1) + collections/conditionable/contracts/macroable/reflection (seed) |
| `illuminate/routing` | `illuminate/routing` | **W4 · L2** | butuh container/http/session/support (SCC-1) + **pipeline** (require-late §3) + collections/conditionable/contracts/macroable/reflection (seed) |
| `illuminate/validation` | `illuminate/validation` | **W4 · L2** | butuh support (SCC-1) + **translation** (L2, sesama) + collections/conditionable/contracts/macroable (seed) |
| `illuminate/queue` | `illuminate/queue` | **W4 · L2** | dep terluas L2: container/database/filesystem/support (SCC-1) + **console** + **pipeline** (require-late) + collections/contracts/reflection (seed) |
| `illuminate/console` | `illuminate/console` | **W4 · L2 (setelah view)** | Layer-0 secara kode fork (wrapper symfony/console, nol `use Illuminate\`) TAPI paket v13 hard-require support+**view**+collections/contracts/macroable/reflection → entry baru bisa lepas setelah **view** beres |
| `illuminate/foundation` | *(core-absorbed)* | **W4 · flip (4.4 cutover)** | tak ada paket target; key `replace` dihapus saat metapackage `laravel/framework` jadi v13 |
| `illuminate/mail` | `illuminate/mail` | **W4 · flip (4.4 cutover)** | SCC-2 core (foundation⇄mail⇄auth di source fork); butuh collections/conditionable/container/contracts/macroable/support (http = `suggest`, bukan gate); datang di dalam metapackage stock, bukan require terpisah |
| `illuminate/auth` | `illuminate/auth` | **W4 · flip (4.4 cutover)** | SCC-2 core; butuh http+queue+support; dual-read auth staged 4.3, dibersihkan 4.5 |
| `illuminate/pagination` | `illuminate/pagination` | **W4 · flip (4.4 cutover)** | SCC-2 terminal leaf; reshape call-site pre-flip, engine flip di 4.4 |
| `illuminate/exception` | *(evaporate → Foundation)* | **W4 · flip (4.4 cutover)** | fork-only; diserap Foundation (`Contracts\Debug\ExceptionHandler`); dihapus, tak di-swap |
| `illuminate/html` | *(evaporate → app-space)* | **W4 · flip (entry drop 4.4; kelas diekstrak 4.2)** | fork-only; tak ada paket v13; 3 kelas pindah ke app-space package |
| `illuminate/workbench` | *(evaporate)* | **W4 · flip (4.4 cutover)** | fork-only; dibuang (dev via orchestra/testbench); dihapus, tak di-swap |

*(CachedRouting: `inForkReplace=false` — bukan salah satu 27 entry, tak ada yang dilepas
dari blok `replace`; dihapus outright di 4.4, native `route:cache` L13.)*

### 2c. OPENING ORDER — daftar cluster berurut

Dihitung bottom-up. Tiap langkah baru boleh dimulai setelah hard-require-nya terpenuhi.

```
0.  SEED introduce copy-source-EARLY (§3a) — TIDAK ada entry replace yang lepas di sini.
    Urutan internal (leaf → butuh):
      Macroable, Conditionable, Contracts        (nol illuminate hard-dep — seed kapan saja)
      Collections                                (butuh conditionable+contracts+macroable)
      Reflection                                 (butuh collections+contracts)
    → memutus SCC-1 (Facade konkret → interface Contracts).

1.  SCC-1 window (flip satu batch — 11 entry lepas bersamaan):
      support, container, http, session, cache, database,
      cookie, encryption, events, filesystem, redis
    Prasyarat: seed §0 on-disk; untuk Events → Bus & Reflection tersedia
    (Reflection dari §0; Bus = require-late begitu core v13-shaped di window ini).

2.  REQUIRE-LATE dep-gated fondasi (§3b) yang jadi hard-require L2:
      Pipeline   (butuh support:^13 → baru bisa `require` setelah SCC-1)
      Bus        (butuh pipeline + seed) — juga hard-dep Events di window 1

3.  L2 batch (entry lepas per-swap; independen antar-mereka kecuali gate di bawah):
      Hashing, Config, Log, Translation, Filesystem*(sudah di SCC-1),
      View  →  lalu Console (gate: butuh View v13),
      Routing (gate: butuh Pipeline), Validation (gate: butuh Translation),
      Queue (gate: butuh Console + Pipeline + Database).
      Evaporate di layer ini: Workbench (hapus).

4.  SCC-2 / THE FLIP (4.4 cutover — sisa blok replace dibuang sekaligus):
      foundation (core-absorbed), mail, auth, pagination  → datang di metapackage stock.
      Evaporate saat flip: exception, html (entry), workbench, cachedrouting.
      Auth: dual-read staged 4.3, dual-read dibuang 4.5.

5.  REQUIRE-LATE fitur baru (§3b) — setelah core v13, `composer require` biasa:
      JsonSchema (butuh contracts) · Image · Process · Testing · Broadcasting
      (butuh Bus) · Concurrency (butuh Process) · Notifications (LAST — butuh Bus,
      Broadcasting, Mail, Queue).
```

**Critical path (batas bawah durasi):**
`seed Contracts/Collections → SCC-1 core → Hashing → [foundation+mail+auth] flip`.

---

## 3. INTRODUCE LIST — copy-source-EARLY vs require-LATE

Paket yang ada di `replace` stock tapi **tidak** di `replace` fork. Dua kelas, dibedakan
oleh **tabrakan on-disk**, bukan oleh "baru/lama".

> **Akuntansi 38 vs 27:** stock `replace` = **38 entry** = **37 `illuminate/*`** +
> **1 non-illuminate: `spatie/once`**. Fork `replace` = 27 (semua `illuminate/*`).
> Delta introduce yang di-`illuminate/*`-framing di bawah = **14** (5 copy-source + 9
> require-late). Entry ke-38, `spatie/once`, **bukan** `illuminate/*` → di luar framing
> §3a/§3b; ia masuk **transitif lewat metapackage `laravel/framework` saat flip**
> (dependency `illuminate/support`), **tak butuh aksi terpisah**. Dengan begitu
> hitungan 38 tertutup penuh: 37 illuminate (23 real-swap fork + 14 introduce) +
> spatie/once, dan 27 fork = 23 real-swap + 4 evaporate.

### 3a. Copy-source-EARLY (tabrakan pohon → tak bisa di-`require`)

Alasan seragam: paket v13 autoload ke `Illuminate\Support\` (atau sub-tree yang fork
sudah miliki on-disk). Selama fork memiliki `Illuminate\*` sebagai `laravel/framework`,
`composer require`-nya ditolak. **Solusi: promosikan/salin source ke pohon lebih dulu.**

| Paket | v13 hard-require | Titik tabrakan on-disk fork | Kenapa EARLY |
|---|---|---|---|
| **Contracts** | *(none)* | `Illuminate\Support\Contracts\{Arrayable,Jsonable,Renderable,MessageProvider,ResponsePreparer}` overlap `Illuminate\Contracts\` | **cycle-breaker SCC-1** — seed PERTAMA; nol illuminate-dep = aman paling awal |
| **Collections** | conditionable, contracts, macroable | `Illuminate\Support\Collection` (~20KB) — v13 Collection di-split keluar Support | SupportCollection clash langsung; effort=high |
| **Macroable** | *(none)* | `Illuminate\Support\Traits\MacroableTrait` → v13 `Illuminate\Support\Traits\Macroable` | SupportTraits overlap; leaf murni (php only) |
| **Conditionable** | *(none)* | mendarat di `Illuminate\Support\Traits\Conditionable` (pohon fork-owned) | leaf murni; tak bisa standalone di namespace fork-owned |
| **Reflection** | collections, contracts | `Illuminate\Support\Reflector` (fork punya stub 947B) + helpers | same-tree clash — "isi ulang" stub di tempat |

> Urutan seed: Macroable / Conditionable / Contracts (leaf) → Collections → Reflection.
> Semua ini prasyarat SCC-1 (Support hard-require kelimanya).

### 3b. Require-LATE (tak ada tabrakan on-disk → gated oleh dep)

Alasan seragam: **tidak ada** direktori padanan di pohon fork (`Illuminate\Pipeline\`,
`Illuminate\Bus\`, dst. **absen**) → tak ada yang ditimpa → cukup `composer require`.
Tapi hard-require-nya menarik `illuminate/*:^13` → **hanya bisa setelah core v13**.

| Paket | v13 hard-require | Kapan `require` | Kenapa LATE (dep-gated) |
|---|---|---|---|
| **Pipeline** | conditionable, contracts, macroable, support | setelah SCC-1 | tarik support:^13; middleware pipeline (4.2 pakai filter) |
| **Bus** | collections, conditionable, contracts, pipeline, support | setelah Pipeline | queue=suggest (bukan gate); hard-dep Events **&** Broadcasting/Notifications |
| **JsonSchema** | contracts | setelah contracts/core | paling ringan; one-liner require |
| **Image** | conditionable, container, contracts, filesystem, http, macroable, support | setelah core v13 | leaf; intervention/image=suggest |
| **Process** | collections, conditionable, contracts, macroable, support | setelah core v13 | hard-dep Concurrency |
| **Testing** | collections, conditionable, contracts, macroable, support | setelah core v13 | console/database/http/phpunit=suggest; dev-tooling leaf |
| **Broadcasting** | bus, collections, container, contracts, queue, reflection, support | setelah Bus | butuh Bus + Queue v13 |
| **Concurrency** | console, contracts, process, support | setelah Process | + laravel/serializable-closure (hard) |
| **Notifications** | broadcasting, bus, collections, conditionable, container, contracts, filesystem, mail, queue, support | **LAST** | rantai terberat: butuh Bus **dan** Broadcasting **dan** Mail **dan** Queue |

> **`spatie/once`** = entry ke-38 stock `replace`, satu-satunya **non-`illuminate/*`**.
> Tak masuk copy-source maupun require-late list di atas: ia datang **transitif** di
> dalam metapackage `laravel/framework` pada flip (deps `illuminate/support`) — **tak
> ada aksi terpisah**.

---

## 4. EVAPORATE LIST — dihapus, tak pernah di-swap

Tak ada paket v13 target. Bekukan sekarang (bugfix-only), hapus saat flip.

| fork Comp | Di fork `replace`? | Nasib v13 | Kapan dihapus |
|---|---|---|---|
| **Exception** | ya (fork-only) | diserap Foundation (`Illuminate\Foundation\Exceptions\Handler` + `Contracts\Debug\ExceptionHandler`) | **flip 4.4** (delete) |
| **Foundation** | ya (fork-only) | **core-absorbed** — tak ada `composer.json` terpisah, masuk metapackage `laravel/framework` (Application/bootstrap/Console\Kernel/Exceptions\Handler) | **flip 4.4** (key `replace` dihapus, tak ada require) |
| **Html** | ya (fork-only) | dihapus dari L13; 3 kelas (HtmlBuilder/FormBuilder/…) diekstrak ke **app-space package** (4.2), API dipertahankan verbatim (~497 view pemakai) | entry drop **flip 4.4** |
| **Workbench** | ya (fork-only) | dihapus dari L13; dev-package via **orchestra/testbench** di app-space | **flip 4.4** (delete) |
| **CachedRouting** | **tidak** (custom dicoding, 4 file src saja) | superseded oleh **native `route:cache`** L13; call-site `->cache(...)` di-desugar ke registrasi route biasa | **flip 4.4** (delete outright; tak ada entry `replace` untuk diedit) |

Foundation/Exception = fork-only replace **dan** absorbed → keduanya menghilang ke dalam
metapackage/handler saat metapackage `laravel/framework` sendiri jadi v13. Html =
satu-satunya evaporate yang kelasnya **selamat** (sebagai app-space extract), bukan
sebagai swap `illuminate/*`.

---

## 5. Mekanika (recap singkat)

- **Kenapa per-cluster, bukan per-paket bebas:** namespace `Illuminate\*` **dan**
  package-name `laravel/framework` sama-sama tabrakan antara fork & tiap paket split →
  dua paket tak bisa co-install. Anggota satu cluster (khususnya SCC) harus flip bareng
  (atau cycle-nya diputus dulu via Contracts). **Penting:** graf hard-require v13 sendiri
  **DAG murni tanpa require-cycle** — Console pun leaf (di-gate ke L2 oleh `view`, dan
  View **tidak** balik require Console). Co-flip dipaksa oleh **co-install collision**,
  bukan oleh cycle v13. Nama `SCC-1`/`SCC-2` menamai cycle **source fork 4.2**
  (`MIGRATION-ROADMAP.md` §2), dipakai ulang sebagai label grup flip-bareng.

- **Kapan entry `replace` dilepas:** **PADA SAAT swap cluster**, bukan sekarang, bukan
  lebih awal. Melepas entry sebelum paketnya siap = `Illuminate\*` tak ter-autoload sama
  sekali (fork berhenti menyediakannya, pengganti belum ada). Aturan gate: lepas hanya
  setelah semua `illuminate/*` hard-require v13-nya sudah v13-shaped.

- **Copy-source vs require:** kalau paket v13 mendarat di sub-tree yang **fork sudah
  miliki on-disk** (`Illuminate\Support\…`) → tak bisa `require` (namespace fork-owned)
  → **copy-source-early**. Kalau direktori padanan **absen** di fork → tak ada yang
  ditimpa → **require-late**, tapi tetap gated karena hard-require-nya `^13`.

- **`suggest` ≠ hard-require:** driver-optional (database/redis/flysystem/predis/
  intervention, dll.) muncul di grep mentah `composer.json` tapi ada di blok `suggest`,
  bukan `require` → **tidak** menggerbang flip. Kolom hard-require di doc ini sudah
  dipangkas ke blok `require` saja. Contoh gotcha: **Mail** menyimpan `illuminate/http`
  di `suggest` (attachment dari `UploadedFile`), **bukan** `require` → jangan salah
  bilang Mail menggerbang http (Auth yang hard-require http).

- **Foundation-absent:** tak ada `../framework/src/Illuminate/Foundation/composer.json`
  (terverifikasi) → tak ada `illuminate/foundation` untuk di-`require`; ia hidup di dalam
  metapackage stock. Key `replace: illuminate/foundation` cukup dihapus di flip.

- **`spatie/once` (entry ke-38 stock replace):** satu-satunya non-`illuminate/*` di stock
  `replace`. Tak butuh aksi terpisah — datang transitif di dalam metapackage
  `laravel/framework` saat flip. Menutup akuntansi 38-vs-27 (§3).
