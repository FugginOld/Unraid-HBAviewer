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
#   lsiutil: temp/fw from the IOC + banner queries get_hba_info.sh already
#            uses. lsiutil has no max_link_width/max_link_speed query, so
#            host_link degrades to "no downtraining signal" on this backend
#            rather than a false negative. Drive count comes from sysfs
#            (_drive_count) — lsiutil itself is never asked, see that
#            function's comment.
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

health_storcli() {   # $1 = controller index
    local out val_out pci dom bus dev fn dir
    local temp fw drives band readok=true
    local width=0 maxwidth=0 speed="" maxspeed=""

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
        val_out=$(cat "$dir/current_link_width" 2>/dev/null); width="${val_out:-0}"
        val_out=$(cat "$dir/max_link_width"     2>/dev/null); maxwidth="${val_out:-0}"
        speed=$(cat "$dir/current_link_speed" 2>/dev/null | sed -E 's/[[:space:]]*PCIe[[:space:]]*$//')
        maxspeed=$(cat "$dir/max_link_speed"  2>/dev/null | sed -E 's/[[:space:]]*PCIe[[:space:]]*$//')
    fi

    printf '{"t":%d,"uptime":%d,"temp":%s,"temp_band":"%s","fw":"%s","drives":%s,"read_ok":%s,"link":{"width":%s,"max_width":%s,"speed":"%s","max_speed":"%s"},"phys":%s}' \
        "$NOW" "$UPTIME" \
        "${temp:-null}" "$band" "$fw" "$drives" "$readok" \
        "$width" "$maxwidth" "$speed" "$maxspeed" \
        "$(_phys_json "$1")"
}

# First SAS host (mpt2sas/mpt3sas/mptsas) — same personality filter as
# lib.sh's hba_personalities, but keeping the host NUMBER, needed to key
# _phys_json. The bundled lsiutil binary only ever addresses one controller.
_first_sas_host() {
    local h
    for h in "${SYS_SCSI_HOST:-/sys/class/scsi_host}"/host*/; do
        case "$(cat "${h}proc_name" 2>/dev/null)" in
            mpt3sas|mpt2sas|mptsas) basename "$h" | sed 's/^host//'; return ;;
        esac
    done
}

health_lsiutil() {
    require_binary || return 1
    local IOC BANNER temp_hex temp fw_raw fw band readok=true
    local width_hex speed_hex width=0 speed="" hnum
    IOC=$(mktemp); BANNER=$(mktemp)
    trap 'rm -f "$IOC" "$BANNER"' EXIT
    hba_query -p"$PORT" -a 25,2,0,0 2>/dev/null > "$IOC"
    printf '0\n' | hba_query        2>/dev/null > "$BANNER"

    temp_hex=$(grep "IOCTemperature:" "$IOC" | grep -oE '0x[0-9A-Fa-f]+' | head -1)
    if [ -n "$temp_hex" ]; then temp=$((16#${temp_hex#0x})); else temp=""; readok=false; fi
    band=""
    [ -n "$temp" ] && band=$(band_of "$temp")

    fw_raw=$(grep -E "^\s+[0-9]+\.\s+ioc" "$BANNER" | head -1 | grep -oE '[0-9a-f]{8}' | head -1)
    if [ -n "$fw_raw" ]; then
        fw=$(printf '%02d.%02d.%02d.%02d' "$((16#${fw_raw:0:2}))" "$((16#${fw_raw:2:2}))" "$((16#${fw_raw:4:2}))" "$((16#${fw_raw:6:2}))")
    else
        fw="Unknown"
    fi

    # lsiutil has no max_link_width/max_link_speed query, so max stays 0/""
    # and host_link never false-flags a card it can't fully read.
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

    hnum=$(_first_sas_host)

    printf '{"t":%d,"uptime":%d,"temp":%s,"temp_band":"%s","fw":"%s","drives":%s,"read_ok":%s,"link":{"width":%s,"max_width":0,"speed":"%s","max_speed":""},"phys":%s}' \
        "$NOW" "$UPTIME" \
        "${temp:-null}" "$band" "$fw" "$(_drive_count "${hnum:-0}")" "$readok" \
        "$width" "$speed" \
        "$(_phys_json "${hnum:-0}")"
}

hba_each health_storcli health_lsiutil
