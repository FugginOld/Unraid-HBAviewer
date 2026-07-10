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
# Serve the cache only if it's non-empty, <60s old (freshness), AND newer than
# this script (so a code push — which updates the script mtime — invalidates it
# immediately, no manual cache clear or 60s wait). -s not -f: never serve a
# truncated/empty cache; fall through and regenerate.
NOW=$(date +%s)
CMT=$(stat -c %Y "$CACHE" 2>/dev/null || echo 0)
SMT=$(stat -c %Y "$0"     2>/dev/null || echo 0)
if [ -s "$CACHE" ] && [ "$(( NOW - CMT ))" -lt 60 ] && [ "$CMT" -gt "$SMT" ]; then
    cat "$CACHE"; exit 0
fi

# ── Produce fresh output (captured so we can cache it) ────────────────────────
# Backend selection lives in the module (lib.sh hba_each): storcli (SAS3/3.5:
# 9300/9400) if it enumerates a controller, else lsiutil (SAS2: 9200). This
# composer only declares what to read per controller for each backend.
# ponytail: auto-detect only. Add a BACKEND config override the day a box has
# BOTH a SAS2 and a SAS3 card and auto picks the wrong one.

# storcli overview: light `show` + `show temperature` (NOT `show all`, which does
# a slow per-drive SMART scan). $2 to the parser is this controller's summed
# sysfs PHY error count, for the glanceable health rollup.
# ponytail: host N == controller N (holds for these HBAs); the PHY tab uses exact
# SAS correlation, this cheaper host index is only for the rollup.
ov_storcli() {   # $1 = controller index
    local perr=0 p f v
    for p in /sys/class/sas_phy/phy-"${1}":*/; do
        [ -d "$p" ] || continue
        for f in invalid_dword_count running_disparity_error_count loss_of_dword_sync_count phy_reset_problem_count; do
            v=$(cat "$p/$f" 2>/dev/null); perr=$(( perr + ${v:-0} ))
        done
    done
    { "$STORCLI" /c"$1" show; "$STORCLI" /c"$1" show temperature; } 2>/dev/null \
        | bash "$DIR/parse/storcli_overview.sh" "$ALERT" "$perr"
}

ov_lsiutil() {
    # A pure SAS3/3.5 box (mpt3sas, no mpt2sas) with no storcli: the bundled
    # lsiutil 1.70 can't reliably read it — point the user at the storcli plugin.
    if [ -z "$(find_storcli)" ] && [ -d /sys/module/mpt3sas ] && [ ! -d /sys/module/mpt2sas ]; then
        echo '{"error":"storcli not found. This looks like a SAS3/SAS3.5 (mpt3sas) controller — install storcli via the dkaser/unraid-storcli plugin (Community Applications), then reload."}'
        return 1
    fi
    require_binary || return 1
    local IOC BANNER BOARD
    IOC=$(mktemp); BANNER=$(mktemp); BOARD=$(mktemp)
    trap 'rm -f "$IOC" "$BANNER" "$BOARD"' EXIT
    hba_query -p"$PORT" -a 25,2,0,0 2>/dev/null > "$IOC"
    printf '0\n' | hba_query        2>/dev/null > "$BANNER"
    hba_query -b                    2>/dev/null > "$BOARD"
    bash "$DIR/parse/hba.sh" "$IOC" "$BANNER" "$BOARD" "$ALERT"
}

out=$(hba_each ov_storcli ov_lsiutil)

printf '%s' "$out"
# Cache only good output, so a transient error is retried next call.
case "$out" in *'"error"'*) : ;; *) printf '%s' "$out" > "$CACHE" 2>/dev/null ;; esac
