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
    # storcli_run, but WITHOUT `nolog`: that argument is verified on StorCLI2 and
    # unverified on the classic tool, and there is no SAS3 card here to try it on.
    # The wrapper alone already keeps the debug log out of the plugin directory.
    encl=$(storcli_run /c"$1"/eall show all 2>/dev/null | bash "$DIR/parse/storcli_enclosures.sh")
    drv=$( storcli_run /c"$1"/eall/sall show all 2>/dev/null | bash "$DIR/parse/storcli_drives.sh")
    # Controllers whose drives carry no enclosure ID answer the eall form with
    # "No drive found!" and address their drives /cN/sN instead. Try the flat form
    # only when the enclosure form yielded nothing — the order matters, because on
    # a controller WITH enclosure-attached drives it is /cN/sall that fails.
    # Keyed on "no drives came back", never on "no enclosure exists": issue #6's
    # box reports a VirtualSES enclosure that simply has no drives attached to it.
    case "$drv" in
        ''|'{"drives":[]}')
            drv=$(storcli_run /c"$1"/sall show all 2>/dev/null | bash "$DIR/parse/storcli_drives.sh") ;;
    esac
    [ -n "$encl" ] || encl='{"enclosures":[]}'
    [ -n "$drv" ]  || drv='{"drives":[]}'
    printf '%s,%s' "${encl%\}}" "${drv#\{}"     # merge two single-key objects into one
}

# StorCLI2 / SAS4. Same two reads and the same enclosure-less fallback as
# drv_storcli — only the parsers differ. Note this backend needs NO sysfs stage
# at all: an eHBA-personality 9600 registers no SAS transport class, so the
# lsiutil path's sas_address/phy join has nothing to read, and StorCLI2 supplies
# the one thing the classic storcli path never could — "OS Drive Name", the real
# /dev name, which ajax_info.php's drive_dev_name() prefers over guessing from
# the serial.
drv_storcli2() {   # $1 = controller index
    local encl drv
    encl=$(storcli_run /c"$1"/eall show all nolog 2>/dev/null | bash "$DIR/parse/storcli2_enclosures.sh")
    drv=$( storcli_run /c"$1"/eall/sall show all nolog 2>/dev/null | bash "$DIR/parse/storcli2_drives.sh")
    case "$drv" in
        ''|'{"drives":[]}')
            drv=$(storcli_run /c"$1"/sall show all nolog 2>/dev/null | bash "$DIR/parse/storcli2_drives.sh") ;;
    esac
    [ -n "$encl" ] || encl='{"enclosures":[]}'
    [ -n "$drv" ]  || drv='{"drives":[]}'
    printf '%s,%s' "${encl%\}}" "${drv#\{}"
}

drv_lsiutil() {
    require_binary || return 1
    # One entry per card, in lsi_ports order, so the index join in ajax_info.php
    # lines up with the Overview's controllers[] (issue #18).
    lsi_each_card _drv_lsiutil_one
}

_drv_lsiutil_one() {   # $1 port  $2 banner  $3 board  $4 hnum  $5 pdir  $6 nports
    local TMPOS TMPSAS hnum="$4"
    TMPOS=$(mktemp); TMPSAS=$(mktemp)
    # Sysfs is swept box-wide below, and an empty $hnum leaves that sweep
    # unfiltered — correct on a one-card box, where every disk found IS this
    # card's and the historic expectations pin exactly that. On a multi-card box
    # it would give this card its neighbours' disks, so an unjoined card there
    # reports none instead.
    if [ -z "$hnum" ] && [ "$6" != "1" ]; then
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

hba_each drv_storcli drv_lsiutil drv_storcli2
