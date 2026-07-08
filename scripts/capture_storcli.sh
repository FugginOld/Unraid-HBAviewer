#!/bin/bash
# Capture real storcli output for the 9400-series (SAS3408/SAS3416) backend work.
# Run on the Unraid box, then share the tests/fixtures/storcli/ files (or paste
# 00_controllers.txt and c0_show_all.txt) so parsers get built against real
# output, not guessed labels.
#
#   bash scripts/capture_storcli.sh [/path/to/storcli] [OUTDIR]
#
# Finds the storcli binary if not passed. Handles storcli / storcli64 / storcli2.

STORCLI="$1"
OUT="${2:-tests/fixtures/storcli}"

# Locate the binary if not given
if [ -z "$STORCLI" ]; then
    for c in storcli storcli64 storcli2 \
             /usr/local/sbin/storcli /usr/local/sbin/storcli64 \
             /usr/sbin/storcli /usr/sbin/storcli64; do
        if command -v "$c" >/dev/null 2>&1; then STORCLI=$(command -v "$c"); break; fi
        [ -x "$c" ] && { STORCLI="$c"; break; }
    done
fi
[ -n "$STORCLI" ] && [ -x "$STORCLI" ] || command -v "$STORCLI" >/dev/null 2>&1 || {
    echo "storcli not found — pass its path: bash scripts/capture_storcli.sh /path/to/storcli"; exit 1; }

echo "using: $STORCLI"
"$STORCLI" version 2>/dev/null | head -3
mkdir -p "$OUT"

# Enumerate controllers (tells us how many /cN there are, and their models)
"$STORCLI" show                       > "$OUT/00_controllers.txt" 2>&1

# Per-controller dumps. /call = all controllers; also dump each individually so
# we see exactly where the boundaries are for a multi-controller card.
"$STORCLI" /call show                 > "$OUT/00_call_show.txt"        2>&1
"$STORCLI" /call show all             > "$OUT/00_call_show_all.txt"    2>&1
"$STORCLI" /call show temperature     > "$OUT/00_call_temperature.txt" 2>&1

# Controller 0 and 1 explicitly (you have two: SAS3408 + SAS3416)
for c in 0 1; do
    "$STORCLI" /c$c show all          > "$OUT/c${c}_show_all.txt"      2>&1
    "$STORCLI" /c$c show temperature  > "$OUT/c${c}_temperature.txt"   2>&1
    "$STORCLI" /c$c/eall/sall show all> "$OUT/c${c}_drives.txt"        2>&1
    "$STORCLI" /c$c/eall show all     > "$OUT/c${c}_enclosures.txt"    2>&1
    "$STORCLI" /c$c show events       > "$OUT/c${c}_events.txt"        2>&1
    "$STORCLI" /c$c/pall show         > "$OUT/c${c}_phys.txt"          2>&1
done

echo
echo "Captured to $OUT/. Share these two first — they anchor the parser:"
echo "  $OUT/00_controllers.txt"
echo "  $OUT/c0_show_all.txt"
echo "(the rest let me build phy/drives/events without a second round-trip)"
