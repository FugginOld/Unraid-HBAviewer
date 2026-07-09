#!/bin/bash
# PHY health composer: query the SAS PHY counters, parse to JSON.
#   -a 20,12,0,0  = Diagnostics > Display PHY counters > quit
DIR="$(dirname "$0")"
source "$DIR/lib.sh"
source "$DIR/config.sh"   # sets PORT, ALERT

# storcli (SAS3/3.5): link/speed/attached-SAS from storcli, merged with SAS
# error counters from sysfs (mpt3sas populates /sys/class/sas_phy; storcli can't).
if use_storcli; then
    SYSFS=$(mktemp); trap 'rm -f "$SYSFS"' EXIT
    for p in /sys/class/sas_phy/phy-*/; do
        [ -d "$p" ] || continue
        sas=$(sed 's/0x//' "$p/sas_address" 2>/dev/null | tr 'a-f' 'A-F' | tr -d ' \n')
        idx=$(basename "$p"); idx=${idx##*:}
        printf "%s %s %s %s %s %s %s\n" "$sas" "$idx" \
            "$(cat "$p/invalid_dword_count"           2>/dev/null || echo 0)" \
            "$(cat "$p/running_disparity_error_count" 2>/dev/null || echo 0)" \
            "$(cat "$p/loss_of_dword_sync_count"      2>/dev/null || echo 0)" \
            "$(cat "$p/phy_reset_problem_count"       2>/dev/null || echo 0)" \
            "$(cat "$p/negotiated_linkrate"           2>/dev/null | tr ' ' '_')" >> "$SYSFS"
    done

    count=$("$STORCLI" show 2>/dev/null | grep -m1 'Number of Controllers' | grep -oE '[0-9]+')
    if [ -z "$count" ] || [ "$count" -eq 0 ]; then echo '{"error":"No storcli controllers found."}'; exit 0; fi
    printf '{"controllers":['
    for c in $(seq 0 $((count - 1))); do
        [ "$c" -gt 0 ] && printf ','
        "$STORCLI" /c"$c"/pall show 2>/dev/null | bash "$DIR/parse/storcli_phy.sh" "$SYSFS"
    done
    printf ']}'
    exit 0
fi

# lsiutil (SAS2): error counters per phy. Wrapped as a 1-element controllers[].
require_binary || exit 1
printf '{"controllers":['
hba_query -p"$PORT" -a 20,12,0,0 2>/dev/null | bash "$DIR/parse/phy.sh"
printf ']}'
