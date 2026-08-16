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
    # One entry per card, in lsi_ports order, so the index join in ajax_info.php
    # lines up with the Overview's controllers[] (issue #18).
    local MAP p bus dev nports first=1
    MAP=$(lsi_port_map)
    nports=$(echo "$MAP" | wc -l | tr -d ' ')
    while read -r p bus dev; do
        [ "$first" = 1 ] || printf ','
        first=0
        _drv_lsiutil_one "$p" "$(lsi_host_for "$bus" "$dev" "$nports")" "$nports"
    done <<< "$MAP"
}

_drv_lsiutil_one() {   # $1 = port   $2 = this card's scsi host ("" if unjoined)   $3 = port count
    local TMPOS TMPSAS hnum="$2"
    TMPOS=$(mktemp); TMPSAS=$(mktemp)
    # Sysfs is swept box-wide below, and an empty $hnum leaves that sweep
    # unfiltered — correct on a one-card box, where every disk found IS this
    # card's and the historic expectations pin exactly that. On a multi-card box
    # it would give this card its neighbours' disks, so an unjoined card there
    # reports none instead.
    if [ -z "$hnum" ] && [ "$3" != "1" ]; then
        bash "$DIR/parse/drives_join.sh" /dev/null /dev/null
        rm -f "$TMPOS" "$TMPSAS"
        return
    fi

    # SYS_SAS_DEVICE / SYS_SCSI_HOST are overridable so the suite can point
    # them at a fixture tree.
    local SYS_SAS_DEVICE="${SYS_SAS_DEVICE:-/sys/class/sas_device}"
    local SYS_SCSI_HOST="${SYS_SCSI_HOST:-/sys/class/scsi_host}"

    # ── Stage 1: OS device map from lsiutil (pure parse of query text) ───────────
    hba_query -p"$1" -a 42,0 2>/dev/null | bash "$DIR/parse/drives_osmap.sh" > "$TMPOS"

    # ── Stage 2: SAS address + PHY from sysfs ────────────────────────────────────
    # $SYS_SAS_DEVICE exists on kernels with SAS transport (mpt3sas) and
    # carries sas_address + phy_identifier. Its sibling class sas_end_device/
    # shares the exact same end_device-H:B naming but holds only end-device
    # *role* attributes (I_T_nexus_loss_timeout, tlr_enabled, ...) -- neither
    # sas_address nor phy_identifier lives there, which is what makes reading
    # the wrong class here such an easy mistake to ship.
    if [ -d "$SYS_SAS_DEVICE" ]; then
        for ed in "$SYS_SAS_DEVICE"/end_device-*/; do
            [ -e "$ed" ] || continue
            # end_device-H:... — H is the scsi host, so this is the filter that
            # keeps one card's drives out of another card's list. Empty $hnum
            # (single-card box, no join) keeps every device, as before.
            if [ -n "$hnum" ]; then
                case "$(basename "$ed")" in end_device-"$hnum":*) ;; *) continue ;; esac
            fi
            sas=$(sed 's/0x//' "${ed}sas_address" 2>/dev/null | tr '[:lower:]' '[:upper:]' | tr -d ' \n')
            phy=$(tr -d ' \n' < "${ed}phy_identifier" 2>/dev/null)
            [ -n "$sas" ] || continue
            # end_device-H:N   -> attached to the HBA itself; phy_identifier is
            #                     an HBA PHY index and is unique per controller.
            # end_device-H:N:M -> attached to expander N; phy_identifier is the
            #                     EXPANDER's PHY number and collides with both
            #                     the HBA's own numbering and every other
            #                     expander's. Same naming rule plan 049 measured
            #                     for phy-H:N vs phy-H:N:M.
            # The expander's SAS address, not its index N, is what identifies it:
            # N is discovery order and can move across a reboot, and the one
            # store keyed on this is the one that cannot be rebuilt from hardware.
            name=$(basename "$ed"); name=${name#end_device-}
            exp=""
            case "$name" in
                *:*:*) exp=$(sed 's/0x//' "$SYS_SAS_DEVICE/expander-${name%:*}/sas_address" 2>/dev/null \
                             | tr '[:lower:]' '[:upper:]' | tr -d ' \n') ;;
            esac
            blk_dir=$(find "$(readlink -f "${ed}device")" -maxdepth 12 -type d -name 'block' 2>/dev/null | head -1)
            blk=$(ls "$blk_dir" 2>/dev/null | head -1)
            [ -n "$blk" ] || continue
            printf "/dev/%s %s %s %s\n" "$blk" "$sas" "${phy:-0}" "${exp:-.}"
        done
    fi > "$TMPSAS"

    # ── Stage 3: sysfs fallback if lsiutil -a 42,0 returned nothing ──────────────
    if [ ! -s "$TMPOS" ]; then
        for h in "$SYS_SCSI_HOST"/host*/; do
            proc=$(cat "${h}proc_name" 2>/dev/null)
            case "$proc" in mpt3sas|mpt2sas|mptsas) ;; *) continue ;; esac
            hn=${h%/}; hn=${hn##*host}
            # Same per-card filter as Stage 2, on the host this loop already has.
            [ -z "$hnum" ] || [ "$hn" = "$hnum" ] || continue
            host_dir=$(readlink -f "${h}device" 2>/dev/null)
            [ -n "$host_dir" ] && [ -d "$host_dir" ] || continue
            while IFS= read -r -d '' t; do
                tgt="${t%/}"                       # find gives no trailing slash, but glob callers might
                IFS=':' read -r _ ch tg <<< "${tgt##*/target}"
                for l in "$tgt"/*/; do
                    [ -d "$l" ] || continue
                    IFS=':' read -r _ _ _ lu <<< "$(basename "$l")"
                    [ "${lu:-0}" = "0" ] || continue
                    blk=$(ls "${l}block/" 2>/dev/null | head -1)
                    [ -n "$blk" ] && printf "%d_%d /dev/%s\n" "${ch:-0}" "${tg:-0}" "$blk" >> "$TMPOS"
                done
            done < <(find "$host_dir" -maxdepth 10 -type d -name "target${hn}:*" -print0 2>/dev/null)
        done
    fi

    # ── Join the two maps (pure parse) ──────────────────────────────────────────
    bash "$DIR/parse/drives_join.sh" "$TMPOS" "$TMPSAS"
    rm -f "$TMPOS" "$TMPSAS"
}

hba_each drv_storcli drv_lsiutil
