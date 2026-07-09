#!/bin/bash
# Overview composer: run the three lsiutil queries, hand the captured text to
# the pure parser. Config/port read stays here (candidate B consolidates later).

DIR="$(dirname "$0")"
source "$DIR/lib.sh"
source "$DIR/config.sh"   # sets PORT, ALERT

# ── 60s cache ────────────────────────────────────────────────────────────────
# The Monitor page renders this server-side on every load, the dashboard tile
# reads it, and the JS auto-refreshes it — so cache the result and read the
# hardware at most once a minute. Every caller gets a warm, snappy response.
# LSI_CACHE overridable (tests point it at /dev/null to stay stateless).
CACHE="${LSI_CACHE:-/tmp/lsiutil_dash.json}"
if [ -f "$CACHE" ] && [ "$(( $(date +%s) - $(stat -c %Y "$CACHE" 2>/dev/null || echo 0) ))" -lt 60 ]; then
    cat "$CACHE"; exit 0
fi

# ── Produce fresh output (captured so we can cache it) ────────────────────────
# Backend selection: storcli (SAS3/3.5: 9300/9400) if installed and it enumerates
# a controller; else lsiutil (SAS2: 9200). Both emit {"controllers":[...]}.
# ponytail: auto-detect only. Add a BACKEND config override the day a box has
# BOTH a SAS2 and a SAS3 card and auto picks the wrong one.
out=$(
    if use_storcli; then
        bash "$DIR/backend_storcli.sh"       # resolved $STORCLI is exported
    else
        require_binary || exit 1
        IOC=$(mktemp); BANNER=$(mktemp); BOARD=$(mktemp)
        trap 'rm -f "$IOC" "$BANNER" "$BOARD"' EXIT
        hba_query -p"$PORT" -a 25,2,0,0 2>/dev/null > "$IOC"
        printf '0\n' | hba_query        2>/dev/null > "$BANNER"
        hba_query -b                    2>/dev/null > "$BOARD"
        if   [ -r /sys/module/mpt2sas/version ]; then DRIVER="mpt2sas $(cat /sys/module/mpt2sas/version)"
        elif [ -r /sys/module/mpt3sas/version ]; then DRIVER="mpt3sas $(cat /sys/module/mpt3sas/version)"
        else DRIVER=""; fi
        printf '{"backend":"lsiutil","driver":"%s","controllers":[' "$DRIVER"
        bash "$DIR/parse/hba.sh" "$IOC" "$BANNER" "$BOARD" "$ALERT"
        printf ']}'
    fi
)

printf '%s' "$out"
# Cache only good output, so a transient error is retried next call.
case "$out" in *'"error"'*) : ;; *) printf '%s' "$out" > "$CACHE" 2>/dev/null ;; esac
