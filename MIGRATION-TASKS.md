# L42x → Laravel 13 — Task Backlog (kerjakan 1-per-1)

Turunan actionable dari `MIGRATION-ROADMAP.md`. **Cara pakai:** ambil task ber-nomor
terendah yang blocker-nya sudah ✅, kerjakan sampai "Done when" terpenuhi, centang, lanjut.
Tiap task **independen & shippable sendiri**.

**Kolom tempat:** `[fork]` = di repo L42x ini · `[app]` = di repo konsumen `dicoding` ·
`[ops]` = infra. **Effort:** T=trivial S=small M=medium L=large XL=x-large.

> Prinsip: mayoritas task = **konvergensi sambil tetap di 4.2** (bikin app bicara idiom L13
> lebih dulu). Pola berulang: **(a)** tambah shim/alias di `[fork]` yang menerima idiom
> lama & baru → **(b)** migrasi call-site di `[app]` ke idiom baru → **(c)** ratchet cegah
> yang lama balik. Mesin core baru benar-benar ditukar di **Wave 4 (flip)**.

---

## Wave 0 — Enabler (sekali kerja, buka semua; kerjakan berurutan)

- [ ] **0.1 — WAF di edge** `[ops · S]`
  - Why: tutup surface security core 4.2 yang tak terpatch **sekarang**; beli waktu buat sisa migrasi.
  - Steps: pasang Cloudflare/AWS WAF di depan app; aktifkan ruleset OWASP + rate-limit; mode block.
  - Done when: trafik produksi lewat WAF; dashboard menampilkan hit rule.

- [x] **0.2 — Baseline inventory anti-pattern** `[fork ✅ / app ⬜ · S]`
  - ✅ Baseline **fork** (`src/`) dibuat: `ci/convergence-baseline.txt` (real counts, 11 pola).
  - ⬜ Sisa: generate baseline **app** `dicoding` → `ci/convergence-ratchet.sh --init <app-dir>` di repo itu.

- [x] **0.3 — Divergence ledger** `[fork · S]` ✅
  - Done: `docs/DIVERGENCE-LEDGER.md` — 11 pola (kiri→kanan + `key` ratchet) + gotcha manual (Cache TTL/Queue/Auth/Cookie/View/Redis) + aturan induk (extension point, monotonik).

- [x] **0.4 — CI ratchet** `[fork · M]` ✅
  - Done & **teruji**: `ci/convergence-ratchet.sh` (mode check + `--init`); job `convergence-ratchet` di `.github/workflows/pull-request-check.yml`.
  - Terverifikasi: lolos di baseline (exit 0); ❌ exit 1 saat pola 4.2 baru; ✅ lapor saat count turun.
  - Sisa (app): copy skrip + `--init` baseline app; wire ke CI app.

---

## Wave 1 — Sweep app aman (jalan di 4.2, ROI tertinggi, urutan bebas)

> Tiap task menghapus satu **gotcha senyap / rename** sebelum flip. Bisa dicicil paralel.

- [ ] **1.1 — 🔴 Cache TTL unit-safe** `[app(+fork) · M]`
  - Why: L13 TTL numerik = **detik** (4.2 = menit) → `Cache::put(...,N)` jadi **60× lebih pendek, senyap**.
  - Steps: grep `Cache::(put|add|remember|remember)\(` + `->put/add/remember` dengan arg numerik →
    ganti ke **interval `Carbon`/`DateTime`** (unit-safe di kedua versi), mis. `now()->addMinutes(N)`.
  - Done when: nol TTL numerik telanjang; ratchet pola `->(put|add|remember)\([^,]+,[^,]+,\s*\d+` = 0.

- [ ] **1.2 — Eloquent idiom L13-safe** `[app · L]`
  - Why: `->lists()`, `ArrayableInterface`, `SoftDeletingTrait`, magic `->lists` hilang/pindah di L13.
  - Steps: `->lists('c')`→`->lists('c')` tetap? tidak → pakai `->pluck('c')` bila tersedia, atau helper app;
    `SoftDeletingTrait`→`SoftDeletes` (cek ketersediaan di fork); `ArrayableInterface`→`Arrayable` alias.
  - Done when: grep `->lists(|SoftDeletingTrait|ArrayableInterface` = 0 di app.

- [ ] **1.3 — Query pakai binding (bukan concat)** `[app · M]`
  - Why: tutup SQL injection **+** konvergensi (surface core yang tak keurus).
  - Steps: grep `whereRaw|DB::raw|"\$` dalam query builder → ubah ke binding `where('c',$v)` / `?` params.
  - Done when: audit selesai; sisa `whereRaw` terdaftar & dijustifikasi.

- [ ] **1.4 — Events `fire()` → `dispatch()`** `[fork+app · S]`
  - Why: L13 rename `fire()`→`dispatch()`; `firing()` dihapus (21 callsite internal + app).
  - Steps: `[fork]` tambah alias `dispatch()` di `Events\Dispatcher` (delegasi ke `fire`) → `[app]` migrasi callsite → deprecate `fire()`.
  - Done when: app pakai `dispatch()`; ratchet blok `->fire(` baru.

- [ ] **1.5 — `Config::getEnvironment()` callsites** `[app · S]`
  - Why: method **dihapus** di L13 tapi dipakai app (`pixel.blade.php`, `sentry.blade.php`, dll).
  - Steps: buat helper app-space `app_env()` (baca `config('app.env')`/`App::environment()`) → ganti semua `Config::getEnvironment()`.
  - Done when: grep `getEnvironment(` di app = 0.

- [ ] **1.6 — Route definition style** `[app · S]`
  - Why: `['uses'=>'C@m']` → `[C::class,'m']` (valid di 4.2 **dan** 13); siapkan model binding.
  - Steps: sweep route files ke callable-array; hindari string `C@m`.
  - Done when: ratchet blok `'uses'\s*=>` baru.

- [ ] **1.7 — Pagination getter alias** `[fork+app · S]`
  - Why: getter di-rename massal (`getCurrentPage`→`currentPage`, `getLastPage`→`lastPage`, `getFrom`→`firstItem`, `getTo`→`lastItem`, `getTotal`→`total`, `getPerPage`→`perPage`).
  - Steps: `[fork]` tambah alias L13-named di Paginator → `[app]` migrasi template/controller ke nama baru.
  - Done when: template pakai nama L13; ratchet blok `get(CurrentPage|LastPage|From|To|Total|PerPage)\(`.

- [ ] **1.8 — `Arr::`/`Str::` L13 signature** `[app · S]` (sudah dimulai)
  - Why: lanjutkan `array_first/last`→`Arr::first/last`; helper global → `Arr::`/`Str::`.
  - Done when: ratchet `array_(first|last)\(` = 0; helper 4.2-only ter-daftar.

---

## Wave 2 — Extract & decouple (per-modul, oportunistik)

- [ ] **2.1 — Ekstrak `Html` (Form/HTML) ke package app-space** `[fork→app · L]`
  - Why: `illuminate/html` dihapus di L13; **~497 file view** pakai `Form::`/`HTML::`.
  - Steps: pindah `Illuminate\Html` ke package internal (mis. `dicoding/html`) yang mempertahankan facade `Form`/`HTML` **verbatim**; bind ke Session/Request app; daftarkan provider.
  - Done when: view render identik lewat package; `illuminate/html` tak lagi dari core.

- [ ] **2.2 — Tarik logika bisnis keluar framework** `[app · XL, continuous]`
  - Why: kode framework-agnostic **selamat dari flip** → flip jadi murah.
  - Steps: tiap sentuh controller/model gemuk, ekstrak logika ke **service/class PHP polos** (tanpa Eloquent/facade/Request di domain). Ratchet ukur % file domain lepas-framework.
  - Done when (per-modul): logika modul X ada di service teruji unit; controller tipis.

- [ ] **2.3 — Pusatkan rule validasi ke FormRequest-like** `[app · M]`
  - Why: 1 tempat per form → migrasi ke `FormRequest` L13 murah.
  - Steps: pindah rule inline controller ke class request app-space.
  - Done when: controller tak lagi punya `Validator::make` inline untuk form utama.

- [ ] **2.4 — Route filters → middleware** `[fork+app · L]`
  - Why: filter **dihapus total** di L13; wajib jadi middleware **sebelum** flip.
  - Steps: tiap `Route::filter`/`->before/->after`/`filters.php` → class middleware-shaped; `[fork]` sediakan jalur middleware kompatibel bila perlu.
  - Done when: grep `Route::filter|->before\(|->after\(` = 0; semua jadi middleware.

---

## Wave 3 — Shim fondasi L13 (bottom-up STRICT — urutan wajib)

> Ini yang **memutus cycle** & membuka core. Kerjakan berurutan; tiap task blocker task berikut.

- [ ] **3.1 — Introduce `Contracts`** `[fork · M]`
  - Why: `Contracts` **tak ada di pohon 4.2**; memutus SCC-1 (import konkret → interface). Prasyarat semua rename namespace.
  - Steps: tambah `Illuminate\Contracts\*` (interface) sesuai L13; alias interface lama (`Support\Contracts\ArrayableInterface`→`Contracts\Support\Arrayable`).
  - Done when: kontrak inti tersedia; Facade/komponen bisa refer interface.

- [ ] **3.2 — Promote `Macroable` + add `Conditionable`/`Pipeline` shims** `[fork · S]` · blocked-by: 3.1
  - Steps: `MacroableTrait`→`Macroable` (alias lama); tambah `Conditionable` (`when/unless`), `Pipeline` shim signature L13.
  - Done when: idiom `->when()/::macro()` tersedia dengan tanda tangan L13.

- [ ] **3.3 — `Support`: Arr/Str/Collection ke signature L13 + pisah Collection** `[fork · L]` · blocked-by: 3.1
  - Why: choke point + biang SCC-1; **alihkan Facade dari import konkret ke kontrak**.
  - Steps: `Collection` API L13; Facade resolve via container/kontrak (bukan `use Http`/`use Container` konkret) → **SCC-1 putus**.
  - Done when: grep SCC-1 cycle (Support→Http/Container konkret) hilang; Collection sig = L13.

- [ ] **3.4 — `Session` shim atas Symfony HttpFoundation** `[fork · M]` · blocked-by: 3.1
  - Why: kandidat shim terbaik (kontrak=storage). `implements` pindah Symfony→`Contracts\Session\Session`.
  - Steps: bungkus HttpFoundation Session di balik API `Store` Illuminate; hati-hati semantik flash & CSRF token.
  - Done when: `Session::get/put/flash/token` jalan via shim; test sesi hijau.

- [ ] **3.5 — Config/Log/Filesystem konvergensi ringan** `[fork · M]` · blocked-by: 3.1
  - Steps: Config ctor `(loader,env)`→terima `array`; Log wiring imperatif→channel-ready; Filesystem siap Flysystem-shaped.
  - Done when: ketiganya expose API L13-shaped tanpa ganti mesin.

---

## Wave 4 — The Flip (cutover terjadwal — sekali, berurutan)

> Prasyarat: Wave 1–3 mayoritas ✅. Ini jam-jaman/hari, bukan bulan.

- [ ] **4.1 — Skeleton L13 stock** `[app · M]`: buat app L13 stock; port config → `.env`/`config()`.
- [ ] **4.2 — Port app code + package app-space** `[app · L]`: pindah kode (sudah idiom L13) + package Html/domain.
- [ ] **4.3 — 🔴 Data cutover** `[ops · M]`:
  - **Drain antrian** SQS/Redis sampai kosong (wire-format L42x tak terbaca worker L13).
  - **Flush** Blade compiled cache (`storage/framework/views`).
  - Migrasi tabel `password_reminders` → `password_resets`.
  - Pasang **dual-read** Auth recaller cookie + session key + Cookie v2 HMAC.
- [ ] **4.4 — Cutover** `[ops · M]`: arahkan trafik ke L13; **hapus semua shim** + komponen evaporate (CachedRouting/Workbench/Exception); buang core 4.2.
- [ ] **4.5 — Post-cutover** `[app · S]`: setelah TTL remember-me lewat, **hapus dual-read**; verifikasi tak ada `Illuminate\*` 4.2 tersisa.

---

## Papan ringkas (dependency)

```
0.1 0.2 0.3 → 0.4 ─┐
                   ├─► Wave 1 (1.1–1.8, paralel)  ─┐
                   └─► Wave 2 (2.1–2.4, per-modul) ─┤
3.1 → 3.2/3.3/3.4/3.5 (bottom-up) ─────────────────┤
                                                    └─► Wave 4 (flip 4.1→4.5)
```

**Mulai dari:** 0.1 → 0.2 → 0.3 → 0.4, lalu ambil Wave 1 mana saja (rekomendasi **1.1 Cache TTL**
duluan — gotcha paling berbahaya). Wave 3 butuh 3.1 (Contracts) lebih dulu.

> Referensi detail tiap komponen (apiDelta/risks) → `MIGRATION-DETAIL.md`. Gotcha → `MIGRATION-ROADMAP.md` §5c.
