#!/bin/bash
# Capture Linux SAS-transport sysfs — the source for PHY error counters and
# drive<->phy correlation on SAS3/3.5 cards (storcli does not expose counters,
# but the mpt3sas driver populates /sys/class/sas_phy/*/).
#   bash scripts/capture_sysfs.sh [OUTDIR]
OUT="${1:-tests/fixtures/sysfs}"
mkdir -p "$OUT"

{
    echo "### sas_phy: one line per phy (counters + link rates) ###"
    for p in /sys/class/sas_phy/*/; do
        [ -d "$p" ] || continue
        n=$(basename "$p")
        printf "%s inv=%s disp=%s losssync=%s reset=%s neg=%s max=%s sas=%s\n" \
            "$n" \
            "$(cat "$p/invalid_dword_count"           2>/dev/null)" \
            "$(cat "$p/running_disparity_error_count" 2>/dev/null)" \
            "$(cat "$p/loss_of_dword_sync_count"      2>/dev/null)" \
            "$(cat "$p/phy_reset_problem_count"       2>/dev/null)" \
            "$(cat "$p/negotiated_linkrate"           2>/dev/null | tr ' ' '_')" \
            "$(cat "$p/maximum_linkrate"              2>/dev/null | tr ' ' '_')" \
            "$(cat "$p/sas_address"                   2>/dev/null)"
    done
} > "$OUT/sas_phy.txt" 2>&1

{
    echo "### sas_end_device -> block dev + sas_address (drive<->/dev mapping) ###"
    for ed in /sys/class/sas_end_device/end_device-*/; do
        [ -e "$ed" ] || continue
        sas=$(cat "${ed}sas_address" 2>/dev/null)
        blkdir=$(find -L "${ed}device" -maxdepth 12 -type d -name block 2>/dev/null | head -1)
        blk=$(ls "$blkdir" 2>/dev/null | head -1)
        printf "%s sas=%s dev=/dev/%s\n" "$(basename "$ed")" "$sas" "$blk"
    done
} > "$OUT/sas_device.txt" 2>&1

echo "captured to $OUT/ — share sas_phy.txt (and sas_device.txt for drive mapping)"
