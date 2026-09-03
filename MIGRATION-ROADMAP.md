# L42x → Laravel 13 — Migration Roadmap & Dependency Graph

> Status: living document. Tujuan akhir = **Laravel 13 stock (unforked)**, bukan fork baru.
> Basis data: graph dependency **nyata** hasil ekstraksi `use`-statement dari
> `src/Illuminate/*` (L42x = `laravel/framework` 4.2.72) dan
> `../framework/src/Illuminate/*` (Laravel 13.30.1), Sept 2026.

---

## 0. Ringkasan eksekutif

**Konteks.** L42x adalah fork Laravel **4.2.72** yang dijaga hidup di PHP 8.3/8.5.
Upstream 4.2 sudah EOL bertahun-tahun → **tidak ada patch security/fitur dari vendor**.
Tim kecil → tidak sanggup jadi security-maintainer core Illuminate selamanya, tidak
sanggup big-bang migrasi, tidak bisa jalankan dua app paralel. App code jauh lebih besar
dari framework-nya.

**Strategi.** Bukan "migrasi bertahap" (mustahil in-process: core 4.2 & 13 tak bisa
seboot). Yang bertahap = **membuat *flip* akhir jadi murah**:

1. Setiap perubahan kode digeser mendekat ke idiom L13 (konvergensi oportunistik,
   di-*enforce* ratchet CI — nol izin management).
2. Komponen "leaf" & yang native-di-13 dibereskan/diuapkan lebih dulu.
3. Logika bisnis ditarik keluar dari framework (app-space) → selamat dari migrasi.
4. Suatu hari: **satu flip** menukar mesin core 4.2 → Laravel 13 stock. Karena app
   sudah bicara idiom L13, flip = beberapa minggu, bukan 18 bulan.

**Anti-goal.** Endgame **BUKAN** "L130x" (Laravel 13 yang di-fork). Itu cuma me-reset
jam kiamat satu versi ke atas. Semua kustomisasi diarahkan ke **extension point**
(`Auth::extend`, custom driver, macro, service provider), **core Laravel haram
di-patch** — kalau tidak, jebakan fork terulang.

**Angka kepala:**

| Metrik | Nilai |
|---|---|
| Komponen L42x (existing) | 28 |
| Komponen L13 | 38 |
| Komponen fondasi baru L13 yang wajib diperkenalkan dulu | 7 (Contracts, Collections, Macroable, Conditionable, Reflection, Pipeline, Bus) |
| **Cyclic clusters (SCC) di core 4.2** | **2** — satu 11-node, satu 3-node |
| Layer topologis | 4 (0–3) |
| Panjang critical path | 4 |
| Sudah "done" (di primitif terawat) | 2 (Encryption→OpenSSL, Hashing→`password_*`) |
| "Evaporate" (native di L13, hapus saat flip) | ≥4 (CachedRouting, Workbench, Exception, sebagian Queue/SQS) |

---

## 1. Temuan struktural utama — DUA CYCLE

Graph `use`-statement mengungkap bahwa core 4.2 **bukan hierarki bersih** melainkan
mengandung dua **strongly-connected component (SCC)** — kumpulan komponen yang saling
bergantung melingkar. **Anggota satu SCC tidak bisa diurutkan/dimigrasi satu per satu
secara terisolasi** — mereka harus bergerak sebagai klaster, ATAU cycle-nya diputus dulu
dengan memperkenalkan interface (itulah fungsi komponen `Contracts` di L13).

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

**Cara L13 memutusnya:** komponen `Contracts` (interface) + Facade yang resolve lewat
container/kontrak, bukan import konkret. **Maka langkah pertama konvergensi = perkenalkan
`Contracts` dan alihkan Facade/import konkret ke interface** — ini yang memecah SCC-1
sehingga komponen-komponennya bisa dikerjakan terpisah.

### SCC-2 — "application bootstrap knot" (3 komponen)
```
foundation, mail, auth
```
**Akar penyebab (tervalidasi):**
- `Auth/Reminders/PasswordBroker.php` → `use Illuminate\Mail\...` (email reset password)
- `Mail/MailServiceProvider.php` → `use Illuminate\Foundation\Application` (konkret)
- `Foundation` → bootstrap `Auth`

`foundation → auth → mail → foundation`. Diputus di L13 via kontrak Mailer + service
provider yang decoupled. Praktisnya klaster ini bagian dari **the flip** (core aplikasi).

---

## 2. Dependency graph (bottom-up, per layer)

Panah = "butuh ini dulu". Kerja **bottom-up**. `[…]` = cyclic cluster (bergerak bareng
atau putus cycle dulu). `*` = sudah `done`.

```
L0  console                                   ← tak bergantung apa pun (paling dasar)
      │
L1  ┌─────────────────── CYCLE / SCC-1 ───────────────────┐
    │ support ⇄ container ⇄ http ⇄ session ⇄ cache ⇄       │  ← 11-node ball of mud;
    │ database ⇄ cookie ⇄ encryption* ⇄ events ⇄           │    putus dulu via Contracts
    │ filesystem ⇄ redis                                   │
    └──────────────────────────────────────────────────────┘
      │
L2   hashing*  config  exception  translation  log  view      ← 10 komponen INDEPENDEN
     workbench  routing  validation  queue                       (paralel lebar)
      │
L3  ┌── SCC-2 ──┐
    │ foundation │  cachedrouting   html   pagination           ← terminal
    │ ⇄ mail     │
    │ ⇄ auth     │
    └────────────┘
```

**Critical path (batas bawah durasi):**
```
console  →  [SCC-1 core cluster]  →  hashing  →  [foundation+mail+auth]
```
Panjang 4. Semua kerja lain bisa disembunyikan paralel di balik rantai ini.

---

## 3. Rencana paralelisasi (apa boleh dikerjakan bersamaan)

Komponen **satu layer tanpa edge antar-mereka** = boleh dikerjakan concurrent.

| Layer | Batch paralel | Lebar | Catatan |
|---|---|---|---|
| **0** | `console` | 1 | fondasi, tak ada blocker |
| **1** | **SCC-1** `[support, container, http, session, cache, database, cookie, encryption*, events, filesystem, redis]` | 1 klaster | **tidak bisa dipecah** sebelum cycle diputus via `Contracts`. Setelah diputus → 11 sub-target paralel |
| **2** | `hashing*`, `config`, `exception`, `translation`, `log`, `view`, `workbench`, `routing`, `validation`, `queue` | **10** | **titik paralel terlebar** — sebar tim / ratchet oportunistik di sini |
| **3** | **SCC-2** `[foundation, mail, auth]`, `cachedrouting`, `html`, `pagination` | 4 | terminal; SCC-2 = bagian the flip |

**Choke point (fan-in tertinggi, kerjakan/putus lebih dulu):** `support` dan `container`
— hampir semua melewatinya. Memutus cycle di `support` (Facade → kontrak) **membuka L2–L3**.

---

## 4. Fondasi baru L13 — WAJIB diperkenalkan lebih dulu

Komponen ini **tidak ada di 4.2** tapi di L13 hampir semua bergantung padanya. Mereka duduk
**di bawah `Support`** dan **memutus cycle** SCC-1. Perkenalkan sebagai shim/polyfill dulu.

| Komponen | Effort | Peran | Status di 4.2 / kenapa prasyarat |
|---|---|---|---|
| **Contracts** | low | interface untuk semua service | **tidak ada** package Contracts di 4.2 → **memutus SCC-1** (import konkret → interface) |
| **Collections** | **high** | `Collection` dipisah dari Support | 4.2: satu class `Collection` ~20KB di Support; pemisahan + API L13 jauh lebih besar |
| **Macroable** | trivial | `::macro()` | 4.2: `MacroableTrait` di dalam support → tinggal dipromosikan jadi package |
| **Conditionable** | trivial | `->when()/->unless()` | idiom fluent L13; **tak ada** di 4.2 |
| **Reflection** | trivial | util refleksi | 4.2: cuma `Reflector` stub 947B → bukan introduce, "isi ulang" saja |
| **Pipeline** | low | middleware pipeline | **tak ada** di 4.2 (request lewat filter, bukan pipeline) |
| **Bus** | medium | command/job bus | **tak ada** abstraksi command-bus di 4.2 sama sekali |

> Fitur L13-baru lain (opsional, adopsi saat perlu): `Broadcasting`, `Concurrency`, `Image`,
> `JsonSchema`, `Notifications`, `Process`, `Testing` — semua `introduce`, tak ada padanan 4.2.
> `Access\Gate` (otorisasi) ikut gratis di package `Auth` L13. Bukan prasyarat migrasi.

---

## 5. Tabel migrasi per-komponen (28 existing)

Kolom **class** (enum verified): `done` · `evaporate` (native di 13, hapus saat flip) ·
`shim-standalone` (swap internal ke lib/native, API tetap) · `shim-symfony` (bungkus
Symfony) · `reshape-callsites` (bentuk ulang call-site sekarang, mesin flip di akhir) ·
`flip-only` (core, mesin baru pindah saat flip) · `app-space-extract` (pindah ke package).

**Breakdown verified (28 existing):** flip-only 13 · reshape-callsites 7 · evaporate 3 ·
shim-symfony 2 · done 1 (hashing) · shim-standalone 1 (encryption) · app-space-extract 1 (html).
Effort: trivial 1 · low 6 · medium 10 · high 7 · very-high 4.

> ✅ **Basis verifikasi:** kolom **deps/cycle** = deterministik (grep `use`-statement +
> Tarjan). Kolom **class/effort** = **28/28 komponen existing ter-verifikasi adversarial**
> oleh agent (baca kode L42x + L13, grep count nyata, koreksi klasifikasi) + cross-check
> backing library (§5b). Gotcha behavior-change konkret yang ditemukan → §5c.
> Catatan lensa: `flip-only` = *mesin* baru pindah saat flip; tapi call-site tetap
> disiapkan bertahap sekarang (Fase 5) — checklist reshape-nya = §5c.

| Komponen | Layer | Class (verified) | Effort | Gotcha / catatan kunci |
|---|---|---|---|---|
| **hashing** | 2 | **done** | low | default cost 10→12 (hash lama tetap verify, no lockout) |
| **encryption** | 1 | **shim-standalone** | low | algoritma **identik** (OpenSSL AES-256-CBC, HMAC-SHA256, wire-format kompatibel); bukan `done` krn tak implement Contracts + pakai `bindShared()` lawas |
| **cookie** | 1 | **shim-symfony** | medium | L13 `EncryptCookies` HMAC-prefix `v2` + validasi; cookie terenkripsi lama L42x **tak valid** saat flip |
| **session** | 1 | **shim-symfony** | medium | `implements` pindah Symfony `SessionInterface`→`Contracts\Session\Session`; Middleware HttpKernel→pipeline (non-inkremental) |
| **cache** | 1 | **reshape-callsites** | high | ⚠️ **TTL menit→detik (60x lebih pendek, senyap)**; `StoreInterface`→`Contracts\Cache\Store` |
| **config** | 2 | **reshape-callsites** | medium | ctor `(loader,env)`→`(array)`; ⚠️ `getEnvironment()` **dihapus** tapi **dipakai app** (pixel/sentry blade) |
| **events** | 2 | **reshape-callsites** | medium | `fire()`→`dispatch()` (21 callsite internal); `firing()` dihapus |
| **log** | 2 | **reshape-callsites** | medium | Monolog **1→3** (level jadi enum); wiring imperatif→config channels |
| **routing** | 2 | **reshape-callsites** | very-high | ⚠️ **filters dihapus total → wajib jadi middleware sebelum flip**; Router bukan lagi HttpKernel |
| **pagination** | 3 | **reshape-callsites** | high | getter rename massal (`getCurrentPage`→`currentPage`…); Presenter dihapus |
| **mail** | 3 | **reshape-callsites** | high | engine sudah symfony/mailer (menipu!); API/provider beda; queued-mail path beda (Mailable) |
| **console** | 0 | **flip-only** | medium | `fire()`→`handle()` (48 def); array args→`$signature` |
| **container** | 1 | **flip-only** | medium | exception pindah ke `Contracts\Container` |
| **http** | 1 | **flip-only** | medium | `Support\Contracts\*`→`Contracts\*`; `FrameGuard` (X-Frame-Options) hilang |
| **filesystem** | 1 | **flip-only** | low | L13 = **superset** kompatibel; `FileNotFoundException` pindah ke Contracts |
| **redis** | 1 | **flip-only** | low | default Predis→**phpredis**; predis 2.x construct cluster beda |
| **translation** | 2 | **flip-only** | medium | dep Symfony **dilepas** → native; `transChoice`/domain diganti |
| **view** | 2 | **flip-only** | high | ⚠️ **flush Blade compiled cache saat flip**; direktif L4-era bisa beda render |
| **validation** | 2 | **flip-only** | high | surface lama kompatibel; +`validate()`/`safe()`, +egulias/brick deps |
| **support** | 1 | **flip-only** | high | split ke Collections/Macroable/Contracts; `MacroableTrait`→`Macroable`; Contracts pindah namespace |
| **database** | 1 | **flip-only** | very-high | ⚠️ **blast radius app**: `->lists()`, `ArrayableInterface`, `SoftDeletingTrait`, casting timestamp/null |
| **queue** | 2 | **flip-only** | very-high | ⚠️ **wire-format payload beda → drain antrian saat cutover**; dispatch API inkompatibel |
| **foundation** | 3 | **flip-only** | very-high | Application = container **+** HTTP kernel; `App::error/down/middleware` hilang |
| **auth** | 3 | **flip-only** | high | ⚠️ `Reminders`→`Passwords` (tabel `password_reminders`→`password_resets`); Guard split 3; recaller format beda (§8) |
| **cachedrouting** | 3 | **evaporate** | low | **custom dicoding** → `route:cache` bawaan L13; app pemanggil `->cache()` harus di-desugar |
| **exception** | 2 | **evaporate** | low | package hilang; `App::error()` closure → `reportable()/renderable()`; Whoops hilang |
| **workbench** | 2 | **evaporate** | trivial | dev-tool, dibuang; tak dipakai app |
| **html** | 3 | **app-space-extract** | medium | **~497 file view** pakai `Form::`/`HTML::` → ekstrak ke package, API dipertahankan verbatim |

### Catatan per-komponen non-trivial
- **support** — choke point + biang cycle. Konvergensi = pisahkan `Collection` (→
  Collections), sediakan `Arr`/`Str` tanda tangan L13 (sudah dimulai: `Arr::first`),
  dan **alihkan Facade dari import konkret ke kontrak**. Ini yang memutus SCC-1.
- **database** — surface app terbesar. Eloquent/query builder banyak beda (casts, relasi,
  `$fillable` default). Fokus: pakai **binding** (bukan string-concat) → tutup SQLi
  sekalian; pusatkan akses ke repository/service (app-extract) agar flip murah.
- **routing** — beda paling tajam: 4.2 "filters" vs 13 "middleware", `['uses'=>'C@m']` vs
  `[C::class,'m']`, route model binding. Semua call-site route bisa digeser sekarang.
- **session** — kandidat **shim-symfony** terbaik: kontrak = storage, cocok dengan
  Symfony HttpFoundation Session (sudah jadi dependency). Hati-hati semantik flash & CSRF.
- **auth** — ~200 baris glue di atas primitif yang **sudah** terawat (password_verify,
  OpenSSL recaller). Audit sekali, pin; flip pakai dual-read (§8).
- **cachedrouting / exception / workbench / html** — tidak diport. Native/absorbed/dibuang
  di L13. Bekukan sekarang (bugfix only), hapus saat flip.

---

### 5b. Pergeseran backing library (penentu class/effort)

`require` non-illuminate di composer.json = mesin terawat yang menopang komponen. Delta
backing = penentu utama `done`/`shim`/`reshape`.

| Komponen | Backing 4.2 | Backing 13 | Implikasi |
|---|---|---|---|
| encryption | OpenSSL | OpenSSL | sama → **done** |
| hashing | `password_*` | `password_*` | sama → **done** |
| cookie/http/session/routing/console | `symfony/*` | `symfony/*` (sama) | mesin sama; delta = API Illuminate, bukan engine → effort lebih rendah |
| filesystem | `symfony/finder` (FS native) | **`league/flysystem`** | re-platform → shim-std |
| support | (mungil, pure) | **doctrine/inflector, vlucas/phpdotenv, ramsey/uuid, league/uri, commonmark, symfony/uid, carbon** | membengkak → choke point **very-high** |
| translation | `symfony/translation` | native (ditulis ulang) | backing dilepas; API mirip |
| pagination | `symfony/http-foundation`+`translation` | native (ditulis ulang) | reshape ringan |
| cache | native/carbon | **+PSR-16, `symfony/cache`** | reshape |
| validation | `symfony/translation`+`http-foundation` | **+`egulias/email-validator`, `brick/math`, `ramsey/uuid`** | rule bertambah |
| database | carbon | **+`brick/math`** (desimal presisi) | reshape, surface app terbesar |
| log | `monolog` v2 | `monolog` v3 | major bump |
| mail | `symfony/mailer` | **+`league/commonmark`, css-inline, transport bridges** | Mailable/markdown baru |
| container | pure | **`psr/container`** (PSR-11) | flip; adopsi kontrak PSR-11 |

**Pola:** komponen ber-backing Symfony sama di kedua sisi (cookie/http/session/routing/
console) → murah, delta cuma di lapisan Illuminate. Yang mahal = yang **ganti/menambah
backing** (filesystem→Flysystem, support membengkak, validation/cache/database nambah lib).

---

### 5c. ⚠️ Gotcha kritis migrasi (temuan verified per-komponen)

Ini jebakan konkret yang ditemukan agent saat baca kode L42x vs L13. **Prioritaskan yang
"silent" — tak ada error, cuma hasil salah.**

**A. Silent behavior change (paling bahaya — tak ada error, hasil beda diam-diam)**
- 🔴 **Cache TTL menit → detik.** Setiap `Cache::put/add/remember($k,$v, N)` dengan TTL
  numerik: L42x = N **menit**, L13 = N **detik** → cache **60× lebih pendek**, senyap.
  Regresi paling berdampak. Sweep semua call-site TTL numerik.
- 🟠 **Hashing cost 10 → 12.** Hash lama tetap `verify` (aman), cuma CPU/login naik.
- 🟠 **Database casting.** Timestamp default, `$dateFormat`, null vs empty-string,
  strict-mode — bisa ubah nilai tanpa error.
- 🟠 **Redis default Predis → phpredis.** L13 default `ext-redis`; kalau tak terpasang & app
  mengandalkan Predis, wiring harus dipin ke `predis`.

**B. Persisted-state pecah saat flip (data ditulis L42x tak terbaca L13)**
- 🔴 **Auth recaller cookie & session.** Format "remember me" beda (L42x `getRecallerName`
  vs L13 `Recaller`) → sesi aktif invalid, logout massal. **Wajib dual-read** (§8).
- 🔴 **Cookie `EncryptCookies` v2.** L13 mem-prefix HMAC (`CookieValuePrefix 'v2'`) &
  memvalidasi; cookie terenkripsi lama L42x ditolak saat flip.
- 🔴 **Queue wire-format.** Payload ter-serialisasi L42x di SQS/Redis **tak bisa** dikonsumsi
  worker L13. **Drain antrian sampai kosong saat cutover.**
- 🟠 **Auth `Reminders` → `Passwords`.** Tabel `password_reminders` → `password_resets`;
  migration/kode yang menyebut `Illuminate\Auth\Reminders\*` pecah.
- 🟠 **View Blade compiled cache.** `.php` hasil kompilasi di `storage/framework/views`
  **wajib di-flush** saat flip.

**C. Rename mekanis (sweep sebelum/saat flip)**
- `Console` `fire()` → `handle()` (**48 definisi**); array args → `$signature`.
- `Events` `fire()` → `dispatch()` (**21 callsite**); `firing()` dihapus.
- `Support` `MacroableTrait` → `Macroable`; `Illuminate\Support\Contracts\*` →
  `Illuminate\Contracts\Support\*` (Arrayable/Jsonable/Renderable/MessageProvider).
- `Pagination` getter: `getCurrentPage`→`currentPage`, `getLastPage`→`lastPage`,
  `getFrom`→`firstItem`, `getTo`→`lastItem`, `getTotal`→`total`, `getPerPage`→`perPage`.
- `Routing` filters (`Route::filter`, `->before/->after`, `filters.php`) → **middleware**.
- Eloquent `->lists()` → `pluck()`; `ArrayableInterface` → `Arrayable`; `SoftDeletingTrait`.

**D. Breakage di app-space (ditemukan agent grep app konsumen — verifikasi ulang di app)**
- `Html` `Form::`/`HTML::` di **~497 file view** → ekstrak ke package, API dipertahankan.
- `Config::getEnvironment()` dipakai di `pixel.blade.php`, `sentry.blade.php` dll — method
  ini **dihapus** di L13.
- `CachedRouting` `->cache(...)` pada route facade → de-sugar ke registrasi route biasa.

> Struktural: **komponen `Contracts` tidak ada sama sekali di pohon L42x** (`src/Illuminate/
> Contracts` absen). Karena banyak rename di atas bermuara ke relokasi ke namespace
> `Illuminate\Contracts\*`, **memperkenalkan `Contracts` = prasyarat** (Fase 4) yang membuka
> mayoritas sweep rename ini.

---

## 6. Roadmap berfase (cross-ref graph)

### Fase 0 — Enabler (sekali kerja, buka semua)
- **WAF di edge** (Cloudflare/AWS) — tutup surface security core 4.2 yang tak terpatch **sekarang**.
- **Ratchet CI + divergence ledger** — rem satu arah agar konvergensi tak mundur.
- **`../framework` L13 sebagai north-star** tanda tangan API.

### Fase 1 — Evaporate (susutkan fork tanpa port)
`cachedrouting`, `workbench`, `exception`, bagian `queue`/SQS → bekukan, tandai "hapus saat flip".

### Fase 2 — Leaf/done (audit + pin)
`encryption`, `hashing` → sudah di primitif terawat. Audit sekali, dokumentasikan "sengaja begini".

### Fase 3 — Decoupling app-space *(bulk bertahun-tahun, nol izin management)*
Tarik logika bisnis dari `database`/controller ke PHP polos; kustomisasi core → service
provider/package. Digerakkan ratchet, bukan proyek. **Metric % file lepas-framework =
bukti progres ke management.**

### Fase 4 — Putus cycle + shim (bottom-up per graph)
1. Perkenalkan `Contracts`/`Collections` → **putus SCC-1**.
2. `support` (Arr/Str/Collection L13-sig, Facade→kontrak) — buka L2–L3.
3. `session` (shim-symfony), `config`, `log`, `filesystem` (flysystem), `translation`.

### Fase 5 — Reshape call-site (framework-locked)
`routing`, `validation`, `http`, `view`, `pagination`, `mail`, `cache`, `events`, `queue`,
`auth` → bentuk call-site ke idiom L13; mesin tetap 4.2 sampai flip.

### Fase 6 — The Flip *(minggu, bukan bulan)*
Skeleton L13 stock → pindah app code (sudah L13-sig) + package app-space → copot semua shim,
buang core 4.2 + komponen evaporate → **dual-read Auth** (§8) → **Laravel 13 stock, tanpa fork**.

---

## 7. Risk register

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Framework EOL, tanpa patch vendor (core Illuminate) | security surface tak terjaga | WAF (Fase 0); leaf sudah di primitif terawat; audit sekali komponen glue |
| Cycle SCC-1 menghalangi kerja terpisah | migrasi macet | perkenalkan `Contracts` lebih dulu (Fase 4) |
| "Gradual" membusuk jadi limbo dua-idiom | onboarding makin kacau | **ratchet CI** (blok pola 4.2 baru, grandfather lama) |
| Endgame jadi fork baru "L130x" | jebakan terulang | anti-goal (§9): kustomisasi ke extension point, core haram |
| Flip Auth me-logout semua user | insiden produksi | **dual-read transition** (§8) |
| SQL injection di query string-concat | data breach | audit `whereRaw`/concat → binding (Fase 3) |

---

## 8. Auth — transition dual-read (agar flip tanpa logout massal)

`Guard` menyimpan state di format Illuminate yang **sudah beredar** di user:
- recaller cookie: `{user_id}|{remember_token}` (dienkripsi app key)
- session key user login: `login_{sha1(class)}`

Saat flip, **jangan** langsung ganti format (semua sesi/cookie invalid). Pola zero-downtime:
1. **Baca dua format**: recaller & session key lama (Illuminate) *dan* format baru L13.
2. **Tulis** hanya format baru.
3. Format lama kedaluwarsa alami seiring TTL remember-me (mis. beberapa minggu).
4. Hapus kode dual-read setelah TTL lewat.

---

## 9. ⛔ Anti-goal (kotak peringatan)

```
╔══════════════════════════════════════════════════════════════════════╗
║  ENDGAME = LARAVEL 13 STOCK (UNFORKED).                               ║
║                                                                       ║
║  "L13 custom" hanya MILESTONE/perancah (4.2 memakai baju L13 via      ║
║  shim), BUKAN tujuan. Jika berhenti di L13 yang di-fork, jebakan      ║
║  4.2 terulang satu versi ke atas.                                     ║
║                                                                       ║
║  Semua kustomisasi → EXTENSION POINT L13:                            ║
║    Auth::extend, custom cache/queue/route driver, macro,              ║
║    middleware, service provider, published config.                    ║
║  CORE LARAVEL HARAM DI-PATCH.                                          ║
╚══════════════════════════════════════════════════════════════════════╝
```

---

## 10. Metodologi & keterbatasan

- **Edges** = `grep -rhoE 'use Illuminate\\[A-Za-z]+'` per direktori komponen di kedua repo,
  dedupe lowercase, exclude self. Deterministik & tervalidasi manual.
- **SCC/layer/critical-path** = Tarjan + topological layering (kode deterministik),
  divalidasi manual (akar cycle di-grep langsung: Facade→Http, CapsuleTrait→Container,
  PasswordBroker→Mail, MailServiceProvider→Foundation).
- **Verifikasi per-komponen** = workflow multi-agent (baca kode L42x + L13, grep count
  nyata, koreksi adversarial). Dijalankan bertahap dengan resume tiap org spend limit reset.
  Status: **28/28 komponen existing ter-verifikasi**; 7 komponen **L13-baru** (Bus,
  JsonSchema, Process, Broadcasting, Notifications, Image, Testing) belum ter-verify —
  tidak kritikal (bukan tabel utama, lihat §4). Data mentah verified disimpan di
  `.migration-verified-records.json` (apiDelta + risks lengkap per komponen).
- **Keterbatasan**: auto-generate doc + audit oleh agent gagal di spend limit; dokumen ini
  **di-merge manual** dari record verified (bukan tulisan agent). Klaim app-space di §5c(D)
  ditemukan agent via grep app konsumen — **verifikasi ulang di repo `dicoding`** saat digarap.
