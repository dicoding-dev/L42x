# Divergence Ledger — pola 4.2 → padanan L13

Kamus konvergensi: setiap kali menyentuh kode, geser dari **kolom kiri** ke **kolom kanan**.
Ini juga **spec yang di-enforce** oleh `ci/convergence-ratchet.sh` (kolom `key` = id pola di
baseline). Turunan dari `MIGRATION-ROADMAP.md` §5b/§5c.

**Aturan induk #1:** kustomisasi baru → **extension point** (`Auth::extend`, custom driver,
macro, middleware, service provider). **Core Laravel haram di-patch.** Kalau tidak, kita
melahirkan fork baru ("L130x") dan mengulang jebakan yang sama.

**Aturan induk #2 (ratchet):** count tiap pola **tidak boleh naik**. Turun → update baseline.
Grandfather yang lama; blok yang **baru**.

---

## Pola yang dilarang bertambah

| `key` (ratchet) | Pola 4.2 (hindari) | Konvergen ke L13 | Kenapa |
|---|---|---|---|
| `array_first_last` | `array_first($x)` / `array_last($x)` | `Arr::first($x)` / `Arr::last($x)` | helper global dihapus di L13 |
| `route_uses_string` | `Route::get('/x', ['uses'=>'C@m'])` | `Route::get('/x', [C::class,'m'])` | valid di 4.2 **dan** 13 |
| `event_fire` | `Event::fire(...)` / `->fire(...)` | `->dispatch(...)` | rename di L13; `firing()` dihapus |
| `config_getEnvironment` | `Config::getEnvironment()` | helper app `app_env()` (`config('app.env')`) | method **dihapus** di L13 (dipakai app!) |
| `where_raw` | `->whereRaw("id=$id")` | binding `->where('id',$id)` / `?` | SQL injection + konvergensi |
| `eloquent_lists` | `$q->lists('c')` | `$q->pluck('c')` | `->lists()` dihapus |
| `macroable_trait` | `use …\MacroableTrait` | `use …\Macroable` | rename di L13 |
| `softdeleting_trait` | `use …\SoftDeletingTrait` | `use …\SoftDeletes` | rename di L13 |
| `legacy_contracts` | `ArrayableInterface` / `JsonableInterface` / `RenderableInterface` | `Contracts\Support\{Arrayable,Jsonable,Renderable}` | pindah namespace |
| `pagination_getters` | `->getCurrentPage()`, `->getLastPage()`, `->getFrom/To/Total/PerPage()` | `->currentPage()`, `->lastPage()`, `->firstItem/lastItem/total/perPage()` | getter di-rename massal |
| `route_filters` | `Route::filter(...)`, `->before(...)`, `->after(...)` | middleware | filter **dihapus total** di L13 |

## Gotcha yang butuh perhatian manual (tak di-ratchet, cek saat digarap)

| Isu | Aksi |
|---|---|
| 🔴 **Cache TTL menit→detik** | TTL numerik telanjang → interval `Carbon`/`DateTime` (unit-safe di kedua versi). Lihat task 1.1. |
| 🔴 **Queue wire-format** | Drain antrian saat cutover (payload L42x tak terbaca worker L13). |
| 🔴 **Auth recaller/session** | Dual-read saat flip (§8 ROADMAP); `password_reminders`→`password_resets`. |
| 🔴 **Cookie v2 HMAC** | Cookie terenkripsi lama ditolak L13 → dual-read/re-issue. |
| **View Blade cache** | Flush `storage/framework/views` saat flip. |
| **Redis client** | Default Predis→phpredis; pin `client` bila `ext-redis` absen. |

---

## Cara pakai

- **Review PR:** perubahan harus bergerak ke kolom kanan, tidak menambah kiri.
- **CI:** `ci/convergence-ratchet.sh` menghitung otomatis; PR yang menaikkan count → merah.
- **Regenerate baseline** setelah menurunkan count: `ci/convergence-ratchet.sh --init <dir>`.
