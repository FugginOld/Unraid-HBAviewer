#!/bin/bash
# Attached-drives composer: declare the per-backend read, let the module dispatch.
#   storcli: enclosure topology + drives per controller (two calls, merged).
#   lsiutil: lsiutil OS map + sysfs SAS join, two pure parse stages wrapping
#            impure sysfs I/O that can't be captured as lsiutil text.
DIR="$(dirname "$0")"
source "$DIR/lib.sh"
source "$DIR/config.sh"   # sets PORT, ALERT

drv_storcli() {   # $1 = controller index
    local encl drv
    encl=$("$STORCLI" /c"$1"/eall show all      2>/dev/null | bash "$DIR/parse/storcli_enclosures.sh")
    drv=$( "$STORCLI" /c"$1"/eall/sall show all 2>/dev/null | bash "$DIR/parse/storcli_drives.sh")
    # Controllers whose drives carry no enclosure ID answer the eall form with
    # "No drive found!" and address their drives /cN/sN instead. Try the flat form
    # only when the enclosure form yielded nothing — the order matters, because on
    # a controller WITH enclosure-attached drives it is /cN/sall that fails.
    # Keyed on "no drives came back", never on "no enclosure exists": issue #6's
    # box reports a VirtualSES enclosure that simply has no drives attached to it.
    case "$drv" in
        ''|'{"drives":[]}')
            drv=$("$STORCLI" /c"$1"/sall show all 2>/dev/null | bash "$DIR/parse/storcli_drives.sh") ;;
    esac
    [ -n "$encl" ] || encl='{"enclosures":[]}'
    [ -n "$drv" ]  || drv='{"drives":[]}'
    printf '%s,%s' "${encl%\}}" "${drv#\{}"     # merge two single-key objects into one
}

drv_lsiutil() {
    require_binary || return 1
    local TMPOS TMPSAS
    TMPOS=$(mktemp); TMPSAS=$(mktemp)
    trap 'rm -f "$TMPOS" "$TMPSAS"' EXIT

    # ── Stage 1: OS device map from lsiutil (pure parse of query text) ───────────
    hba_query -p"$PORT" -a 42,0 2>/dev/null | bash "$DIR/parse/drives_osmap.sh" > "$TMPOS"

    # ── Stage 2: SAS address + PHY from sysfs ────────────────────────────────────
    # /sys/class/sas_device/ exists on kernels with SAS transport (mpt3sas) and
    # carries sas_address + phy_identifier. Its sibling class sas_end_device/
    # shares the exact same end_device-H:B naming but holds only end-device
    # *role* attributes (I_T_nexus_loss_timeout, tlr_enabled, ...) -- neither
    # sas_address nor phy_identifier lives there, which is what makes reading
    # the wrong class here such an easy mistake to ship.
    if [ -d "/sys/class/sas_device" ]; then
        for ed in /sys/class/sas_device/end_device-*/; do
            [ -e "$ed" ] || continue
            sas=$(sed 's/0x//' "${ed}sas_address" 2>/dev/null | tr '[:lower:]' '[:upper:]' | tr -d ' \n')
            phy=$(tr -d ' \n' < "${ed}phy_identifier" 2>/dev/null)
            [ -n "$sas" ] || continue
            blk_dir=$(find "$(readlink -f "${ed}device")" -maxdepth 12 -type d -name 'block' 2>/dev/null | head -1)
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

    # ── Join the two maps (pure parse) ──────────────────────────────────────────
    bash "$DIR/parse/drives_join.sh" "$TMPOS" "$TMPSAS"
}

hba_each drv_storcli drv_lsiutil
