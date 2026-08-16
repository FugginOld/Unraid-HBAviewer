#!/bin/bash
# HBA Health composer: emits one stateless SAMPLE per controller (raw readings +
# a timestamp, no judgement). health.php owns the /tmp ring, the rate
# arithmetic, the five indicators, and the rollup — this script never persists
# anything, never computes a rate, never decides a state.
#   storcli: temp/fw/drives from the same light `show` + `show temperature`
#            get_hba_info.sh already uses; PCIe current+max link width/speed
#            from sysfs (storcli itself reports neither); per-PHY error
#            counters from sysfs (the driver exposes these regardless of
#            backend — same fields get_phy_health.sh's _build_phy_sysfs reads).
#   lsiutil: temp/fw + current link width/speed from the IOC + banner queries
#            get_hba_info.sh already uses. lsiutil has no max_link_width query,
#            but the KERNEL does regardless of backend, so the maximum comes
#            from the same sysfs read the storcli path uses, reached through
#            the scsi_host instead of a storcli-reported PCI address (#14).
#            Drive count comes from sysfs (_drive_count) — lsiutil itself is
#            never asked, see that function's comment.
DIR="$(dirname "$0")"
source "$DIR/lib.sh"
source "$DIR/config.sh"   # sets PORT, ALERT

# Temperature band — twin copy of parse/storcli_overview.sh's band_of; keep
# both copies identical (see that file's comment for why this isn't shared).
#   normal <=65 | elevated 66-75 | warning 76-85 | alert 86-95 | critical >=96
band_of() {
    if   [ "$1" -le 65 ]; then echo normal
    elif [ "$1" -le 75 ]; then echo elevated
    elif [ "$1" -le 85 ]; then echo warning
    elif [ "$1" -le 95 ]; then echo alert
    else echo critical; fi
}

# Per-controller sysfs PHY read: same field list as get_phy_health.sh's
# _build_phy_sysfs, but keyed directly by controller host index (the
# "host N == controller N" assumption get_hba_info.sh's ov_storcli already
# relies on for its own PHY error rollup) since health only needs THIS
# controller's phys, not a global SAS-address join.
_phys_json() {   # $1 = controller host index
    local p idx first=1 out=""
    for p in "${SYS_SAS_PHY:-/sys/class/sas_phy}"/phy-"${1}":*/; do
        [ -d "$p" ] || continue
        idx=$(basename "$p")
        # phy-H:N is this controller's own PHY; phy-H:N:M is a PHY on an expander
        # BEHIND it — a different device, with counters this controller does not
        # own and (measured) no counter values at all. Including them collapsed
        # four entries onto every index (issue #12).
        case "${idx#phy-}" in *:*:*) continue ;; esac
        idx=${idx##*:}
        [ "$first" -eq 1 ] || out+=","
        first=0
        out+=$(printf '{"idx":%d,"inv":%d,"disp":%d,"sync":%d,"rst":%d,"rate":"%s"}' \
            "$idx" \
            "$(cat "$p/invalid_dword_count"           2>/dev/null || echo 0)" \
            "$(cat "$p/running_disparity_error_count" 2>/dev/null || echo 0)" \
            "$(cat "$p/loss_of_dword_sync_count"      2>/dev/null || echo 0)" \
            "$(cat "$p/phy_reset_problem_count"       2>/dev/null || echo 0)" \
            "$(cat "$p/negotiated_linkrate"           2>/dev/null | tr ' ' '_')")
    done
    printf '[%s]' "$out"
}

# Drives behind one SAS host, from sysfs. Depth-agnostic because SAS transport
# inserts port-H:P/end_device-H:P/ between a host's device/ dir and its
# target* entries, and non-SAS hosts (AHCI etc.) do not — a fixed-depth glob
# matches one shape and silently returns 0 on the other. Resolve the host
# device once and find target dirs by name, then list each LUN's block/
# non-recursively: `find -path` with a wildcard that crosses `/` overcounts
# a single drive's queue/, holders/, slaves/, power/ and partition subdirs.
# Enclosure/SES targets carry no `block/` and are still skipped. Issue #11
# fixed this returning a hardcoded 0; issue #14 fixed the glob that replaced
# it — it matched neither real SAS-transport nor flat sysfs depth correctly —
# and the fixture that had certified the wrong depth as correct.
_drive_count() {   # $1 = controller host index
    local host_dir t l blk n=0
    host_dir=$(readlink -f "${SYS_SCSI_HOST:-/sys/class/scsi_host}/host$1/device" 2>/dev/null)
    [ -n "$host_dir" ] && [ -d "$host_dir" ] || { printf '0'; return; }
    while IFS= read -r -d '' t; do
        for l in "$t"/*/; do
            [ -d "$l" ] || continue
            blk=$(ls "${l}block/" 2>/dev/null | head -1)
            [ -n "$blk" ] && n=$((n + 1))
        done
    done < <(find "$host_dir" -maxdepth 10 -type d -name "target$1:*" -print0 2>/dev/null)
    printf '%d' "$n"
}

UPTIME=$(cut -d. -f1 /proc/uptime 2>/dev/null); UPTIME="${UPTIME:-0}"
NOW=$(date +%s)

# PCIe link state from a sysfs PCI device dir. Bash is dynamically scoped, so
# these land on the CALLER's locals (width/maxwidth/speed/maxspeed/slotwidth/
# slotspeed) — six values, and returning them any other way costs more than it
# buys. current_* only overwrite when sysfs actually answers, so the lsiutil
# path keeps the values it already read from the IOC page.
#
# ".." is the upstream bridge: the SLOT's own ceiling, which is what makes a
# narrow-slot card readable as normal rather than downtrained (plan 056).
# Nothing on the card can say so — max_link_* here is the CARD's own.
# Absent (0/"") where a platform does not publish it, and health.php then
# falls back to the card maximum, which is the old rule.
_link_from_sysfs() {   # $1 = /sys/bus/pci/devices/0000:xx:yy.z
    local d="$1" v
    [ -d "$d" ] || return 0
    v=$(cat "$d/current_link_width" 2>/dev/null);   width="${v:-$width}"
    v=$(cat "$d/max_link_width"     2>/dev/null);   maxwidth="${v:-0}"
    v=$(_link_speed "$d/current_link_speed");       speed="${v:-$speed}"
    maxspeed=$(_link_speed "$d/max_link_speed")
    v=$(cat "$d/../max_link_width"  2>/dev/null);   slotwidth="${v:-0}"
    slotspeed=$(_link_speed "$d/../max_link_speed")
}

# sysfs prints "8.0 GT/s PCIe"; every consumer here wants the rate alone.
_link_speed() { cat "$1" 2>/dev/null | sed -E 's/[[:space:]]*PCIe[[:space:]]*$//'; }

health_storcli() {   # $1 = controller index
    local out pci dom bus dev fn dir
    local temp fw drives band readok=true
    local width=0 maxwidth=0 speed="" maxspeed="" slotwidth=0 slotspeed=""

    out=$({ "$STORCLI" /c"$1" show; "$STORCLI" /c"$1" show temperature; } 2>/dev/null)
    val() { printf '%s\n' "$out" | grep -m1 -E "^$1[[:space:]]*=" | sed 's/^[^=]*=[[:space:]]*//; s/[[:space:]]*$//'; }

    temp=$(printf '%s\n' "$out" | grep -m1 'ROC temperature' | grep -oE '[0-9]+' | tail -1)
    fw=$(val "FW Version"); [ -n "$fw" ] || fw=$(val "Firmware Version")
    drives=$(val "Physical Drives"); drives="${drives:-0}"
    band=""
    if [ -n "$temp" ]; then band=$(band_of "$temp"); else readok=false; fi
    [ -n "$out" ] || readok=false

    # storcli reports "PCI Address = 00:c1:00:00" (domain:bus:device:function).
    # sysfs wants "0000:c1:00.0" — same mapping get_hba_info.sh's ov_storcli
    # uses, extended to also read max_link_width/max_link_speed (which that
    # composer never needed, since it only shows the current link state).
    pci=$(printf '%s\n' "$out" | grep -m1 -E '^PCI Address[[:space:]]*=' | sed 's/^[^=]*=[[:space:]]*//; s/[[:space:]]*$//')
    if [ -n "$pci" ]; then
        IFS=: read -r dom bus dev fn <<< "$pci"
        dir="${SYS_PCI_ROOT:-/sys/bus/pci/devices}/$(printf '%04x:%s:%s.%d' "0x${dom:-0}" "$bus" "$dev" "0x${fn:-0}")"
        _link_from_sysfs "$dir"
    fi

    printf '{"t":%d,"uptime":%d,"temp":%s,"temp_band":"%s","fw":"%s","drives":%s,"read_ok":%s,"link":{"width":%s,"max_width":%s,"speed":"%s","max_speed":"%s","slot_width":%s,"slot_speed":"%s"},"phys":%s}' \
        "$NOW" "$UPTIME" \
        "${temp:-null}" "$band" "$fw" "$drives" "$readok" \
        "$width" "$maxwidth" "$speed" "$maxspeed" "$slotwidth" "$slotspeed" \
        "$(_phys_json "$1")"
}

health_lsiutil() {
    require_binary || return 1
    # The dashboard tile reads this, and on a multi-card box it read card 1's
    # temperature for every card — the symptom issue #18 was filed about. One
    # entry per port now, in lsi_ports order, so the index join in ajax_info.php
    # lines up with the Overview's controllers[].
    local MAP BANNER p bus dev nports first=1
    MAP=$(lsi_port_map)
    nports=$(echo "$MAP" | wc -l | tr -d ' ')
    BANNER=$(mktemp)                        # lists every port; captured once
    trap 'rm -f "$BANNER"' EXIT
    printf '0\n' | hba_query 2>/dev/null > "$BANNER"
    while read -r p bus dev; do
        [ "$first" = 1 ] || printf ','
        first=0
        _health_lsiutil_one "$p" "$bus" "$dev" "$nports" "$BANNER"
    done <<< "$MAP"
}

_health_lsiutil_one() {   # $1 = port  $2 = bus  $3 = device  $4 = port count  $5 = banner file
    local IOC BANNER temp_hex temp fw_raw fw band readok=true
    local width_hex speed_hex hnum
    local width=0 maxwidth=0 speed="" maxspeed="" slotwidth=0 slotspeed=""
    IOC=$(mktemp); BANNER="$5"
    hba_query -p"$1" -a 25,2,0,0 2>/dev/null > "$IOC"

    temp_hex=$(grep "IOCTemperature:" "$IOC" | grep -oE '0x[0-9A-Fa-f]+' | head -1)
    if [ -n "$temp_hex" ]; then temp=$((16#${temp_hex#0x})); else temp=""; readok=false; fi
    # 0x0000 is a sensorless card (SAS2008 / 9211-8i, issue #17), not 0 °C.
    # readok stands: the query answered, there is just no sensor behind it.
    [ "$temp" = "0" ] && temp=""
    band=""
    [ -n "$temp" ] && band=$(band_of "$temp")

    # This card's banner row, not the first one — every card on a multi-card box
    # reported card 1's firmware otherwise, and the firmware verdict hangs off it.
    fw_raw=$(grep -E "^[[:space:]]+$1\.[[:space:]]+ioc" "$BANNER" | head -1 | grep -oE '[0-9a-f]{8}' | head -1)
    if [ -n "$fw_raw" ]; then
        fw=$(printf '%02d.%02d.%02d.%02d' "$((16#${fw_raw:0:2}))" "$((16#${fw_raw:2:2}))" "$((16#${fw_raw:4:2}))" "$((16#${fw_raw:6:2}))")
    else
        fw="Unknown"
    fi

    # lsiutil has no max_link_width/max_link_speed query — the maximum comes
    # from sysfs below, once hnum resolves the card. These two are the CURRENT
    # link, and stand if sysfs has nothing to say.
    # PCIeWidth is a one-hot bitmask; PCIeSpeed is an enum (mpi2_cnfg.h,
    # MPI2_IOUNITPAGE7_*). They are NOT the same encoding — see plan 038.
    # Keep the speed table in sync with scripts/parse/hba.sh.
    width_hex=$(grep "PCIeWidth:" "$IOC" | grep -oE '0x[0-9A-Fa-f]+' | head -1)
    case "${width_hex,,}" in
        0x01) width=1 ;; 0x02) width=2 ;; 0x04) width=4 ;; 0x08) width=8 ;; 0x10) width=16 ;; *) width=0 ;;
    esac
    speed_hex=$(grep "PCIeSpeed:" "$IOC" | grep -oE '0x[0-9A-Fa-f]+' | head -1)
    speed=""
    if [ -n "$speed_hex" ]; then
        case "$((16#${speed_hex#0x}))" in
            0) speed="2.5 GT/s" ;; 1) speed="5.0 GT/s"  ;; 2) speed="8.0 GT/s" ;;
            3) speed="16.0 GT/s" ;; 4) speed="32.0 GT/s" ;;
        esac
    fi

    # This port's own card, joined through its PCI bus/device — the drive count
    # and the PHY counters below are per-card, and handing every card host 1's
    # would be the same bug in a different field.
    hnum=$(lsi_host_for "$2" "$3" "$4")
    # One card and no join: keep the historic host-0 default, so single-card
    # output stays byte-identical. More than one card, and empty means empty —
    # zero drives and no PHYs beats another card's.
    [ -z "$hnum" ] && [ "$4" = "1" ] && hnum=0

    # Same six link fields as the storcli path, from the same sysfs files —
    # only the route to the device dir differs, since there is no storcli line
    # to read a PCI address from. Stays 0/"" on a card sysfs can't reach, and
    # health.php then says so instead of inventing a ceiling.
    [ -n "$hnum" ] && _link_from_sysfs "$(_pci_dir_of_host "$hnum")"

    printf '{"t":%d,"uptime":%d,"temp":%s,"temp_band":"%s","fw":"%s","drives":%s,"read_ok":%s,"link":{"width":%s,"max_width":%s,"speed":"%s","max_speed":"%s","slot_width":%s,"slot_speed":"%s"},"phys":%s}' \
        "$NOW" "$UPTIME" \
        "${temp:-null}" "$band" "$fw" "$(_drive_count "$hnum")" "$readok" \
        "$width" "$maxwidth" "$speed" "$maxspeed" "$slotwidth" "$slotspeed" \
        "$(_phys_json "$hnum")"
    rm -f "$IOC"
}

hba_each health_storcli health_lsiutil
