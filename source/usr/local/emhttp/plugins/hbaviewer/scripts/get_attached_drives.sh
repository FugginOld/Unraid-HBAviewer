#!/bin/bash
# Attached-drives composer. Two pure parse stages (osmap, join) wrap impure
# sysfs I/O that can't be captured as lsiutil text:
#   Stage 1  lsiutil -a 42,0  -> parse/drives_osmap.sh   (pure, tested)
#   Stage 2  /sys sas_end_device  -> SAS address + PHY    (impure I/O)
#   Stage 3  /sys scsi_host fallback if stage 1 empty     (impure I/O)
#   Join     parse/drives_join.sh                          (pure, tested)

DIR="$(dirname "$0")"
source "$DIR/lib.sh"
source "$DIR/config.sh"   # sets PORT, ALERT

# storcli (SAS3/3.5): enclosure topology + drives per controller.
if use_storcli; then
    count=$("$STORCLI" show 2>/dev/null | grep -m1 'Number of Controllers' | grep -oE '[0-9]+')
    if [ -z "$count" ] || [ "$count" -eq 0 ]; then echo '{"error":"No storcli controllers found."}'; exit 0; fi
    printf '{"controllers":['
    for c in $(seq 0 $((count - 1))); do
        [ "$c" -gt 0 ] && printf ','
        encl=$("$STORCLI" /c"$c"/eall show all      2>/dev/null | bash "$DIR/parse/storcli_enclosures.sh")
        drv=$( "$STORCLI" /c"$c"/eall/sall show all 2>/dev/null | bash "$DIR/parse/storcli_drives.sh")
        [ -n "$encl" ] || encl='{"enclosures":[]}'
        [ -n "$drv" ]  || drv='{"drives":[]}'
        printf '%s,%s' "${encl%\}}" "${drv#\{}"     # merge two single-key objects into one
    done
    printf ']}'
    exit 0
fi

# lsiutil (SAS2): lsiutil OS map + sysfs SAS join, wrapped as 1-element controllers[].
require_binary || exit 1

TMPOS=$(mktemp); TMPSAS=$(mktemp)
trap 'rm -f "$TMPOS" "$TMPSAS"' EXIT

# ── Stage 1: OS device map from lsiutil (pure parse of query text) ───────────
hba_query -p"$PORT" -a 42,0 2>/dev/null | bash "$DIR/parse/drives_osmap.sh" > "$TMPOS"

# ── Stage 2: SAS address + PHY from sysfs ────────────────────────────────────
# /sys/class/sas_end_device/ exists on kernels with SAS transport (mpt3sas).
if [ -d "/sys/class/sas_end_device" ]; then
    for ed in /sys/class/sas_end_device/end_device-*/; do
        [ -e "$ed" ] || continue
        sas=$(sed 's/0x//' "${ed}sas_address" 2>/dev/null | tr '[:lower:]' '[:upper:]' | tr -d ' \n')
        phy=$(tr -d ' \n' < "${ed}phy_identifier" 2>/dev/null)
        [ -n "$sas" ] || continue
        blk_dir=$(find -L "${ed}device" -maxdepth 12 -type d -name 'block' 2>/dev/null | head -1)
        blk=$(ls "$blk_dir" 2>/dev/null | head -1)
        [ -n "$blk" ] || continue
        printf "/dev/%s %s %s\n" "$blk" "$sas" "${phy:-0}"
    done
fi > "$TMPSAS"

# ── Stage 3: sysfs fallback if lsiutil -a 42,0 returned nothing ──────────────
if [ ! -s "$TMPOS" ]; then
    for h in /sys/class/scsi_host/host*/; do
        proc=$(cat "${h}proc_name" 2>/dev/null)
        case "$proc" in mpt3sas|mpt2sas|mptsas) ;; *) continue ;; esac
        hn=${h%/}; hn=${hn##*host}
        for t in "${h}device/target${hn}:"[0-9]*/; do
            [ -d "$t" ] || continue
            IFS=':' read -r _ ch tg <<< "${t##*/target}"
            for l in "${t}"*/; do
                [ -d "$l" ] || continue
                IFS=':' read -r _ _ _ lu <<< "$(basename "$l")"
                [ "${lu:-0}" = "0" ] || continue
                blk=$(ls "${l}block/" 2>/dev/null | head -1)
                [ -n "$blk" ] && printf "%d_%d /dev/%s\n" "${ch:-0}" "${tg:-0}" "$blk" >> "$TMPOS"
            done
        done
    done
fi

# ── Join the two maps (pure parse), wrapped in the controllers[] contract ────
printf '{"controllers":['
bash "$DIR/parse/drives_join.sh" "$TMPOS" "$TMPSAS"
printf ']}'
