#!/bin/bash
# Self-asserting check for get_hba_health.sh's _phys_json -- the glob that
# used to match both an HBA's own PHYs (phy-H:N) AND every PHY on an expander
# behind it (phy-H:N:M), truncating both down to the same `idx` via
# ${idx##*:}. On a box with an expander this collapsed 29 real PHYs and 76
# phantom expander PHYs onto the same handful of indexes, and since expander
# PHY counter files read back empty (-> 0 via the `|| echo 0` fallback), the
# phantom with idx N sometimes overwrote the real PHY N's non-zero reading.
# health.php's ring-reset check reads that as a counter DECREASE (a driver
# reload) and wipes the ring on every sample -- Link Integrity never leaves
# "not enough samples yet" (issue #12).
#
# The function is lifted out with sed rather than sourced: get_hba_health.sh
# runs hba_each at load and would go looking for real hardware.
#   bash tests/phys_json_test.sh   ->  "phys_json: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
SRC="../source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_health.sh"
fail=0
eq() {  # name  want  got
    if [ "$2" = "$3" ]; then echo "PASS  $1"; else echo "FAIL  $1 -- want '$2', got '$3'"; fail=1; fi
}

FN=$(sed -n '/^_phys_json()/,/^}/p' "$SRC")
[ -n "$FN" ] || { echo "FAIL  _phys_json not found in $SRC"; exit 1; }

ROOT=$(mktemp -d)
trap 'rm -rf "$ROOT"' EXIT

# One populated PHY directory: $1 = path, $2 = counter value (non-zero == a
# real, readable PHY). An EMPTY counter file (rather than one holding "0") is
# what an expander PHY actually reports -- `cat` on it returns nothing, and
# `printf '%d'`'s `|| echo 0` fallback is what turns that into a phantom zero.
mkphy() {
    mkdir -p "$1"
    for f in invalid_dword_count running_disparity_error_count loss_of_dword_sync_count phy_reset_problem_count; do
        if [ -n "$2" ]; then printf '%s\n' "$2" > "$1/$f"; else : > "$1/$f"; fi
    done
    printf '12.0 Gbit\n' > "$1/negotiated_linkrate"
}

# host0: the HBA's own 8 PHYs, non-zero counters
for i in $(seq 0 7); do mkphy "$ROOT/phy-0:$i" 5; done
# host0 behind a first expander: phy-0:0:0 .. phy-0:0:7, empty counters --
# the collision: ${idx##*:} used to truncate every one of these down to 0-7,
# same indexes as the real PHYs above.
for i in $(seq 0 7); do mkphy "$ROOT/phy-0:0:$i" ""; done
# host0 behind a second expander: phy-0:1:0 .. phy-0:1:3, empty counters
for i in $(seq 0 3); do mkphy "$ROOT/phy-0:1:$i" ""; done
# host1: a different controller entirely -- must not appear in `_phys_json 0`
for i in $(seq 0 3); do mkphy "$ROOT/phy-1:$i" 9; done

got=$(SYS_SAS_PHY="$ROOT" bash -c "$FN"$'\n''_phys_json "$1"' _ 0)

idxs=$(printf '%s' "$got" | grep -oE '"idx":[0-9]+' | grep -oE '[0-9]+')
dupes=$(printf '%s\n' "$idxs" | sort -n | uniq -d)
count=$(printf '%s\n' "$idxs" | grep -c .)
sorted=$(printf '%s\n' "$idxs" | sort -n | tr '\n' ' ')

eq "no duplicate idx"           ""          "$dupes"
eq "exactly 8 entries emitted"  8           "$count"
eq "indexes are exactly 0-7"    "0 1 2 3 4 5 6 7 " "$sorted"
eq "no host1 (inv=9) counter leaks in" ""   "$(printf '%s' "$got" | grep -oE '"inv":9')"

[ $fail -eq 0 ] && echo "phys_json: all pass"
exit $fail
