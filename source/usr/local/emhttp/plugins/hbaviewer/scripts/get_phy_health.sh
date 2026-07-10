#!/bin/bash
# PHY health composer: declare the per-backend read, let the module dispatch.
#   storcli: link/speed/attached-SAS from storcli, merged with the SAS error
#            counters mpt3sas exposes in /sys/class/sas_phy (storcli can't read).
#   lsiutil: -a 20,12,0,0  = Diagnostics > Display PHY counters > quit
DIR="$(dirname "$0")"
source "$DIR/lib.sh"
source "$DIR/config.sh"   # sets PORT, ALERT

# Snapshot every sysfs PHY (sas_addr + error counters) once, keyed by SAS addr,
# for storcli_phy.sh to merge. Built lazily on the first controller only.
_build_phy_sysfs() {
    local p sas idx
    for p in /sys/class/sas_phy/phy-*/; do
        [ -d "$p" ] || continue
        sas=$(sed 's/0x//' "$p/sas_address" 2>/dev/null | tr 'a-f' 'A-F' | tr -d ' \n')
        idx=$(basename "$p"); idx=${idx##*:}
        printf "%s %s %s %s %s %s %s\n" "$sas" "$idx" \
            "$(cat "$p/invalid_dword_count"           2>/dev/null || echo 0)" \
            "$(cat "$p/running_disparity_error_count" 2>/dev/null || echo 0)" \
            "$(cat "$p/loss_of_dword_sync_count"      2>/dev/null || echo 0)" \
            "$(cat "$p/phy_reset_problem_count"       2>/dev/null || echo 0)" \
            "$(cat "$p/negotiated_linkrate"           2>/dev/null | tr ' ' '_')"
    done
}
phy_storcli() {
    [ -n "$SYSFS" ] || { SYSFS=$(mktemp); trap 'rm -f "$SYSFS"' EXIT; _build_phy_sysfs > "$SYSFS"; }
    "$STORCLI" /c"$1"/pall show 2>/dev/null | bash "$DIR/parse/storcli_phy.sh" "$SYSFS"
}
phy_lsiutil() {
    require_binary || return 1
    hba_query -p"$PORT" -a 20,12,0,0 2>/dev/null | bash "$DIR/parse/phy.sh"
}
hba_each phy_storcli phy_lsiutil
