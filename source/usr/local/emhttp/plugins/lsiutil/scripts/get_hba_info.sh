#!/bin/bash
# Overview composer: run the three lsiutil queries, hand the captured text to
# the pure parser. Config/port read stays here (candidate B consolidates later).

DIR="$(dirname "$0")"
source "$DIR/lib.sh"
source "$DIR/config.sh"   # sets PORT, ALERT

# ── Backend selection ────────────────────────────────────────────────────────
# storcli (SAS3/SAS3.5: 9300/9400) if it's installed and enumerates a controller;
# otherwise lsiutil (SAS2: 9200). Both emit the same {"controllers":[...]} shape.
# ponytail: auto-detect only. Add a BACKEND config override the day a box has
# BOTH a SAS2 and a SAS3 card and auto picks the wrong one.
if use_storcli; then
    exec bash "$DIR/backend_storcli.sh"      # resolved $STORCLI is exported
fi

# ── lsiutil backend (SAS2) ───────────────────────────────────────────────────
require_binary || exit 1

IOC=$(mktemp); BANNER=$(mktemp); BOARD=$(mktemp)
trap 'rm -f "$IOC" "$BANNER" "$BOARD"' EXIT

hba_query -p"$PORT" -a 25,2,0,0 2>/dev/null > "$IOC"
printf '0\n' | hba_query        2>/dev/null > "$BANNER"
hba_query -b                    2>/dev/null > "$BOARD"

# Emit the shared multi-controller contract. lsiutil addresses one controller
# per port, so this backend yields exactly one element; the storcli backend
# yields N. Consumers loop either way.
printf '{"backend":"lsiutil","controllers":['
bash "$DIR/parse/hba.sh" "$IOC" "$BANNER" "$BOARD" "$ALERT"
printf ']}'
