#!/usr/bin/env bash
#
# convergence-ratchet.sh — monotonic guard against NEW Laravel-4.2-only patterns.
#
# Konvergensi L42x -> Laravel 13 dikerjakan bertahap tanpa tim khusus. Ratchet ini
# memastikan gerakannya SATU ARAH: pola 4.2 lama di-grandfather (baseline), tapi
# yang BARU ditolak. Pattern ids = docs/DIVERGENCE-LEDGER.md.
#
# Usage:
#   ci/convergence-ratchet.sh [SCAN_DIR]          # check vs baseline (CI mode); exit 1 kalau naik
#   ci/convergence-ratchet.sh --init [SCAN_DIR]   # (re)generate baseline dari count saat ini
#
# Default SCAN_DIR = src. Untuk repo app (dicoding), jalankan dengan SCAN_DIR=app/
# dan baseline-nya sendiri.
# NB: sengaja tanpa `-e` — grep tanpa match keluar 1 (via pipefail) saat assign count,
# padahal "0 match" itu normal. Skrip melacak kegagalan sendiri via $fail.
set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
BASELINE="$HERE/convergence-baseline.txt"

MODE=check
if [ "${1:-}" = "--init" ]; then MODE=init; shift; fi
SCAN_DIR="${1:-src}"

# key:::extended-regex   (:::= pemisah, karena regex mengandung '|')
PATTERNS=(
  "array_first_last:::\\b(array_first|array_last)\\("
  "route_uses_string:::['\"]uses['\"][[:space:]]*=>"
  "event_fire:::->fire\\("
  "config_getEnvironment:::getEnvironment\\("
  "where_raw:::->whereRaw\\("
  "eloquent_lists:::->lists\\("
  "macroable_trait:::MacroableTrait"
  "softdeleting_trait:::SoftDeletingTrait"
  "legacy_contracts:::(Arrayable|Jsonable|Renderable)Interface"
  "pagination_getters:::->get(CurrentPage|LastPage|From|To|Total|PerPage)\\("
  "route_filters:::Route::filter\\(|->before\\(|->after\\("
)

count() {
  # jumlah baris match di file *.php (line-level; cukup untuk ratchet)
  grep -rEc --include='*.php' -- "$1" "$SCAN_DIR" 2>/dev/null | awk -F: '{s+=$2} END{print s+0}'
}

if [ "$MODE" = init ]; then
  { echo "# convergence baseline — dihasilkan dari: $SCAN_DIR"
    echo "# regenerate: ci/convergence-ratchet.sh --init $SCAN_DIR   (hanya setelah count TURUN)"
    for p in "${PATTERNS[@]}"; do
      key="${p%%:::*}"; rx="${p#*:::}"
      echo "$key=$(count "$rx")"
    done
  } > "$BASELINE"
  echo "baseline ditulis -> $BASELINE (scan: $SCAN_DIR)"
  cat "$BASELINE"
  exit 0
fi

[ -f "$BASELINE" ] || { echo "❌ baseline tidak ada: $BASELINE (jalankan --init dulu)"; exit 2; }

fail=0
for p in "${PATTERNS[@]}"; do
  key="${p%%:::*}"; rx="${p#*:::}"
  base=$(grep -E "^$key=" "$BASELINE" | head -1 | cut -d= -f2)
  base="${base:-0}"
  cur=$(count "$rx")
  if   (( cur > base )); then
    echo "❌ $key: $cur > baseline $base — pola 4.2 BARU ditambahkan (lihat DIVERGENCE-LEDGER.md)"
    fail=1
  elif (( cur < base )); then
    echo "✅ $key: $cur < baseline $base — turun! update baseline: ci/convergence-ratchet.sh --init $SCAN_DIR"
  else
    echo "•  $key: $cur (= baseline)"
  fi
done

exit $fail
