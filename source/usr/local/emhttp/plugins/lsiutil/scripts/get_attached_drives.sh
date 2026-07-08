#!/bin/bash
# Attached drives — three-stage detection:
#   1. lsiutil -a 42,0  → bus:target + OS device name  (authoritative device list)
#   2. sysfs sas_end_device  → SAS address + PHY per block device
#   3. sysfs host scan  → fallback OS device names if lsiutil -a 42,0 returns nothing

LSIUTIL="/usr/local/emhttp/plugins/lsiutil/lsiutil.x86_64"
CFG="/boot/config/plugins/lsiutil/lsiutil.cfg"
PORT=1
[ -f "$CFG" ] && source "$CFG" && PORT="${HBA_PORT:-1}"

[ -x "$LSIUTIL" ] || { echo '{"error":"lsiutil binary not found"}'; exit 1; }

# ── Stage 1: OS device name map from lsiutil ─────────────────────────────────
TMPOS=$(mktemp)
"$LSIUTIL" -p"$PORT" -a 42,0 2>/dev/null | awk '
/\/dev\/[a-z]/ {
    bus=0; tgt=0; dev=""; n=0
    for (i=1;i<=NF;i++) {
        if ($i ~ /^\/dev\//) { dev=$i }
        else if ($i ~ /^[0-9]+$/) { n++; if (n==1) bus=$i+0; else if (n==2) tgt=$i+0 }
    }
    if (dev != "") printf "%d_%d %s\n", bus, tgt, dev
}' > "$TMPOS"

# ── Stage 2: SAS address + PHY from sysfs ────────────────────────────────────
# /sys/class/sas_end_device/ exists on kernels with SAS transport support (mpt3sas)
# Each entry has sas_address, phy_identifier, and a device link to the SCSI device tree
TMPSAS=$(mktemp)
if [ -d "/sys/class/sas_end_device" ]; then
    for ed in /sys/class/sas_end_device/end_device-*/; do
        [ -e "$ed" ] || continue
        sas=$(sed 's/0x//' "${ed}sas_address" 2>/dev/null | tr '[:lower:]' '[:upper:]' | tr -d ' \n')
        phy=$(tr -d ' \n' < "${ed}phy_identifier" 2>/dev/null)
        [ -n "$sas" ] || continue
        # Traverse from end_device/device into the SCSI+block device subtree
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

# ── Build JSON: join OS-name list with sysfs SAS/PHY map ─────────────────────
awk '
BEGIN { first=1; printf "{\"drives\":[" }
NR==FNR {
    # TMPOS: "bus_tgt /dev/sdX"
    os[$1]=$2; n++; ord[n]=$1
    next
}
{
    # TMPSAS: "/dev/sdX sas_addr phy"
    sasmap[$1]=$2; phymap[$1]=$3
}
END {
    for (i=1; i<=n; i++) {
        key=ord[i]; dev=os[key]
        split(key, p, "_"); bus=p[1]+0; tgt=p[2]+0
        sas=(dev in sasmap) ? sasmap[dev] : ""
        # For SATA drives sas_end_device has no entry; target == PHY for direct-attached HBAs
        phy=(dev in phymap) ? phymap[dev]+0 : tgt
        if (!first) printf ","
        first=0
        printf "{\"bus\":%d,\"target\":%d,\"sas_address\":\"%s\",\"phy\":%d,\"os_name\":\"%s\"}",
            bus, tgt, sas, phy, dev
    }
    printf "]}"
}
' "$TMPOS" "$TMPSAS"

rm -f "$TMPOS" "$TMPSAS"
