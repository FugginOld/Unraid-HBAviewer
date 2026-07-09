#!/bin/bash
# storcli backend composer. Enumerates every controller and emits the shared
# multi-controller contract:  {"controllers":[<overview>, ...]}
# Each element is parse/storcli_overview.sh output (fixture-tested).
# STORCLI env overrides the binary so tests can point it at a stub.

DIR="$(dirname "$0")"
source "$DIR/lib.sh"             # find_storcli, storcli_each
source "$DIR/config.sh"          # ALERT (PORT unused by storcli)
STORCLI="$(find_storcli)"

[ -n "$STORCLI" ] || {
    echo '{"error":"storcli not found. Install it via the dkaser/unraid-storcli plugin (search \"storcli\" in Community Applications), then reload."}'; exit 1; }

# Overview uses the light `show` (brief: model/fw/pci/devid) + `show temperature`
# per controller — NOT `show all`, which does a slow per-drive SMART scan of
# every attached disk and made the dashboard tile take seconds to render.
if   [ -r /sys/module/mpt3sas/version ]; then DRIVER="mpt3sas $(cat /sys/module/mpt3sas/version)"
elif [ -r /sys/module/mpt2sas/version ]; then DRIVER="mpt2sas $(cat /sys/module/mpt2sas/version)"
else DRIVER=""; fi

SHOW=$("$STORCLI" show 2>/dev/null)
count=$(grep -m1 'Number of Controllers' <<<"$SHOW" | grep -oE '[0-9]+')
if [ -z "$count" ] || [ "$count" -eq 0 ]; then
    echo '{"error":"No storcli controllers found."}'; exit 0
fi
printf '{"backend":"storcli","driver":"%s","controllers":[' "$DRIVER"
for c in $(seq 0 $((count - 1))); do
    [ "$c" -gt 0 ] && printf ','
    # Chip from the enumeration's AdapterType (e.g. SAS3416, SAS2008, SAS3108).
    chip=$(awk -v c="$c" '$1==c && /SAS[0-9]/' <<<"$SHOW" | grep -oE 'SAS[0-9]+[A-Za-z0-9]*' | head -1)
    # Sum this controller's sysfs PHY error counters for the health rollup.
    # ponytail: host N == controller N (holds for these HBAs); the PHY tab uses
    # exact SAS correlation, this glanceable rollup uses the cheaper host index.
    perr=0
    for p in /sys/class/sas_phy/phy-"${c}":*/; do
        [ -d "$p" ] || continue
        for f in invalid_dword_count running_disparity_error_count loss_of_dword_sync_count phy_reset_problem_count; do
            v=$(cat "$p/$f" 2>/dev/null); perr=$(( perr + ${v:-0} ))
        done
    done
    { "$STORCLI" /c"$c" show; "$STORCLI" /c"$c" show temperature; } 2>/dev/null \
        | bash "$DIR/parse/storcli_overview.sh" "$ALERT" "$perr"
done
printf ']}'
