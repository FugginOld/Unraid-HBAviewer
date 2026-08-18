#!/bin/bash
# Overview composer: run the three lsiutil queries, hand the captured text to
# the pure parser. Config/port read stays here (candidate B consolidates later).

DIR="$(dirname "$0")"
source "$DIR/lib.sh"
source "$DIR/config.sh"   # sets PORT, ALERT

# ── 60s cache ────────────────────────────────────────────────────────────────
# The Monitor page renders this server-side on every load, the dashboard tile
# reads it, and the JS auto-refreshes it — so cache the result and read the
# hardware at most once a minute. Every caller gets a warm, snappy response.
# LSI_CACHE overridable (tests point it at /dev/null to stay stateless).
CACHE="${LSI_CACHE:-/tmp/lsiutil_dash.json}"
# Serve the cache only if it's non-empty, <60s old (freshness), AND newer than
# this script (so a code push — which updates the script mtime — invalidates it
# immediately, no manual cache clear or 60s wait). -s not -f: never serve a
# truncated/empty cache; fall through and regenerate.
NOW=$(date +%s)
CMT=$(stat -c %Y "$CACHE" 2>/dev/null || echo 0)
SMT=$(stat -c %Y "$0"     2>/dev/null || echo 0)
if [ -s "$CACHE" ] && [ "$(( NOW - CMT ))" -lt 60 ] && [ "$CMT" -gt "$SMT" ]; then
    cat "$CACHE"; exit 0
fi

# ── Produce fresh output (captured so we can cache it) ────────────────────────
# Backend selection lives in the module (lib.sh hba_each): storcli (SAS3/3.5:
# 9300/9400) if it enumerates a controller, else lsiutil (SAS2: 9200). This
# composer only declares what to read per controller for each backend.
# ponytail: auto-detect only. Add a BACKEND config override the day a box has
# BOTH a SAS2 and a SAS3 card and auto picks the wrong one.

# storcli overview: light `show` + `show temperature` (NOT `show all`, which does
# a slow per-drive SMART scan). $2 to the parser is this controller's summed
# sysfs PHY error count, for the glanceable health rollup.
# ponytail: host N == controller N (holds for these HBAs) is used for the PHY
# error rollup only -- the PHY tab uses exact SAS correlation, and topology (which
# gates the firmware verdict) resolves its host from the card's own PCI dir below.
ov_storcli() {   # $1 = controller index
    local perr=0 p idx f v out pci dom bus dev fn dir width speed power chip h hosts hnum
    for p in "${SYS_SAS_PHY:-/sys/class/sas_phy}"/phy-"${1}":*/; do
        [ -d "$p" ] || continue
        idx=$(basename "$p")
        # phy-H:N is this controller's own PHY; phy-H:N:M is a PHY on an expander
        # BEHIND it — a different device, with counters this controller does not
        # own and (measured) no counter values at all. Rolling those in inflated
        # the PHY error count with phantom entries that always read zero, but a
        # future backplane could report nonzero and pad the rollup (issue #12).
        case "${idx#phy-}" in *:*:*) continue ;; esac
        for f in invalid_dword_count running_disparity_error_count loss_of_dword_sync_count phy_reset_problem_count; do
            v=$(cat "$p/$f" 2>/dev/null); perr=$(( perr + ${v:-0} ))
        done
    done

    out=$({ "$STORCLI" /c"$1" show; "$STORCLI" /c"$1" show temperature; } 2>/dev/null)

    # storcli's controller list names the chip outright, which beats a device-ID
    # map that only knows five chips (issue #10: an 0xC4 / SAS3224 fell through it
    # and the Overview showed no chip at all). Model contains a space on some cards
    # ("HBA 9400-16i") and not others ("SAS9305-16i"), so the AdapterType column is
    # NOT at a fixed field index — cut at the first 0x and take the last token
    # before it, then drop any "(B0)" revision suffix.
    chip=$("$STORCLI" show 2>/dev/null \
         | awk -v c="$1" '$1 == c { sub(/[[:space:]]*0x.*$/, ""); print $NF; exit }' \
         | sed 's/(.*)//')

    # storcli reports "PCI Address = 00:c1:00:00" (domain:bus:device:function).
    # sysfs wants "0000:c1:00.0" — four-digit domain, dot before the function.
    # PCIe link state is not in storcli's output at all, so read it from sysfs;
    # SYS_PCI_ROOT is overridable so the suite can point it at a fixture tree.
    width=""; speed=""; power=""
    pci=$(printf '%s\n' "$out" | grep -m1 -E '^PCI Address[[:space:]]*=' | sed 's/^[^=]*=[[:space:]]*//; s/[[:space:]]*$//')
    if [ -n "$pci" ]; then
        IFS=: read -r dom bus dev fn <<< "$pci"
        dir="${SYS_PCI_ROOT:-/sys/bus/pci/devices}/$(printf '%04x:%s:%s.%d' "0x${dom:-0}" "$bus" "$dev" "0x${fn:-0}")"
        v=$(cat "$dir/current_link_width" 2>/dev/null)
        [ -n "$v" ] && [ "$v" != "0" ] && width="x$v"
        v=$(cat "$dir/current_link_speed" 2>/dev/null)
        case "$v" in
            2.5*) speed="Gen1 (2.5 GT/s)"  ;;
            5.0*|5*) speed="Gen2 (5.0 GT/s)"  ;;
            8.0*|8*) speed="Gen3 (8.0 GT/s)"  ;;
            16*)  speed="Gen4 (16.0 GT/s)" ;;
            32*)  speed="Gen5 (32.0 GT/s)" ;;
        esac
        # PCI D-state, mapped onto lsiutil's vocabulary so both backends print the
        # same words. An HBA in use is always D0, so this reads "Full" in practice.
        v=$(cat "$dir/power_state" 2>/dev/null)
        case "$v" in
            D0)        power="Full"    ;;
            D1|D2)     power="Reduced" ;;
            D3*)       power="Standby" ;;
        esac
    fi

    # storcli's own output carries SubVendor Id, but read it from sysfs for both
    # backends so there is one source of truth and one thing the diagnostic
    # bundle has to capture. $dir is already resolved above from PCI Address.
    #
    # Topology gates the multipath suppression -- a wrong answer there is a false
    # BEHIND on a correctly configured card -- so it resolves this card's scsi
    # host from $dir too, where the kernel publishes it, rather than from the
    # host-N-equals-controller-N guess the rollup above can afford. Anything but
    # exactly one host under the device reads "unknown", which suppresses.
    hosts=(); [ -n "$dir" ] && for h in "$dir"/host*/; do [ -d "$h" ] && hosts+=("$h"); done
    hnum=""; if [ "${#hosts[@]}" -eq 1 ]; then hnum=$(basename "${hosts[0]}"); hnum="${hnum#host}"; fi
    LSI_TOPOLOGY=$([ -n "$hnum" ] && hba_topology "$hnum" || printf 'unknown')
    LSI_SUBVENDOR=$([ -n "$dir" ] && hba_subvendor "$dir")
    # The slot, for grouping the two IOCs of a dual-controller board. Same
    # $dir the subvendor read uses, so it costs one more sysfs resolve.
    LSI_CARD_ID=$([ -n "$dir" ] && hba_card_id "$dir")
    export LSI_TOPOLOGY LSI_SUBVENDOR LSI_CARD_ID
    printf '%s\n' "$out" | bash "$DIR/parse/storcli_overview.sh" "$ALERT" "$perr" "$chip" "$width" "$speed" "$power"
}

# StorCLI2 / SAS4 (9600 series). Differs from ov_storcli in three ways that are
# all forced by the hardware and the tool, not by preference:
#   1. ONE `show all` instead of `show` + `show temperature`. StorCLI2 has no
#      `show temperature` subcommand at all (syntax error on Lite and full
#      alike), and the brief `show` carries no temperature. The reason the
#      classic path avoids `show all` — a slow per-drive SMART scan — does not
#      apply: measured under a second on a 9600-24i.
#   2. PHY errors come from StorCLI2, not sysfs. An eHBA-personality controller
#      registers no SAS transport class, so /sys/class/sas_phy is EMPTY. The
#      classic composer's `phy-<controller>:*` glob would sum nothing and report
#      a confident zero — and it is doubly wrong here anyway, because the
#      controller index is not the scsi host number (this card is host17).
#   3. No chip lookup. `show all` names the chip outright ("Chip Name = SAS4024"),
#      so there is no AdapterType column to cut apart and no device-ID map.
ov_storcli2() {   # $1 = controller index
    local out perr dir v width speed power
    out=$(storcli_run /c"$1" show all nolog 2>/dev/null)

    # Empty (not 0) when the counters cannot be read: the parser scores an
    # unmeasured card differently from a measured-clean one.
    perr=$(storcli_run /c"$1"/pall show all nolog 2>/dev/null | awk '
        /^SAS Phyerrorcounters Information[ \t]*:/ { s=1; next }
        /^PCIe /                                   { s=0 }
        s && /^[ \t]*[0-9]+[ \t]+[0-9]+/           { t += $2 + $3 + $4 + $5; seen=1 }
        END { if (seen) print t+0 }')

    width=""; speed=""; power=""
    dir=$(pci_addr_to_sysfs_dir "$(printf '%s\n' "$out" | grep -m1 -E '^PCI Address[[:space:]]*=' | sed 's/^[^=]*=[[:space:]]*//; s/[[:space:]]*$//')")
    if [ -n "$dir" ]; then
        v=$(cat "$dir/current_link_width" 2>/dev/null)
        [ -n "$v" ] && [ "$v" != "0" ] && width="x$v"
        v=$(cat "$dir/current_link_speed" 2>/dev/null)
        case "$v" in
            2.5*)    speed="Gen1 (2.5 GT/s)"  ;;
            5.0*|5*) speed="Gen2 (5.0 GT/s)"  ;;
            8.0*|8*) speed="Gen3 (8.0 GT/s)"  ;;
            16*)     speed="Gen4 (16.0 GT/s)" ;;
            32*)     speed="Gen5 (32.0 GT/s)" ;;
            64*)     speed="Gen6 (64.0 GT/s)" ;;
        esac
        v=$(cat "$dir/power_state" 2>/dev/null)
        case "$v" in
            D0)    power="Full"    ;;
            D1|D2) power="Reduced" ;;
            D3*)   power="Standby" ;;
        esac
    fi

    printf '%s\n' "$out" | bash "$DIR/parse/storcli2_overview.sh" "$ALERT" "$perr" "" "$width" "$speed" "$power"
}

# The board name of the first host on one of $1's personalities, for the refusal
# messages below — naming the card is what turns "unsupported" into something a
# reporter can act on. Membership is tested against a space-separated list, NOT
# a case pattern: `case $p in $1)` never treats an expanded "a|b" as alternation,
# because case parses its alternation before the expansion happens, so every
# card came back as the fallback. Falls back to "This controller" when sysfs
# publishes no board_name.
_board_on() {   # $1 = space-separated proc_names
    local h p board=""
    for h in "${SYS_SCSI_HOST:-/sys/class/scsi_host}"/host*/; do
        p=$(cat "${h}proc_name" 2>/dev/null)
        [ -n "$p" ] || continue          # an empty proc_name would match any list
        case " $1 " in *" $p "*) ;; *) continue ;; esac
        board=$(tr -d '\n' < "${h}board_name" 2>/dev/null)
        [ -n "$board" ] && break
    done
    printf '%s' "${board:-This controller}"
}

ov_lsiutil() {
    # No storcli, and EVERY controller is on the mpt3sas personality: genuine
    # SAS3/3.5 hardware that the bundled lsiutil 1.70 cannot read. Keyed off
    # proc_name, not /sys/module — the merged driver reports proc_name=mpt2sas for
    # SAS2 cards even when only the mpt3sas module is loaded, and lsiutil reads
    # those fine (issue #3: /dev/mptctl present, IOC temperature returned). The
    # old /sys/module test refused those cards outright. hba_has_sas3 also keeps a
    # box with no HBA at all falling through to require_binary's clearer error.
    if [ -z "$(find_storcli)" ] && hba_has_sas3 && ! hba_has_sas2; then
        printf '{"error":"%s is on the mpt3sas driver and the bundled lsiutil cannot read through it. Install storcli via the dkaser/unraid-storcli plugin (Community Applications), then reload."}' \
            "$(_board_on 'mpt3sas mpt2sas mptsas')"
        return 1
    fi
    # 24G / SAS4 (9600 series, mpi3mr) — issue #19. lsiutil 1.70 predates the
    # generation and storcli enumerates zero controllers on it, which would
    # otherwise route the card into the lsiutil branch below and end in "check
    # the lsiutil port in Settings" — advice that cannot work on any port. When
    # StorCLI2 is on the box, hba_each routes there instead and this branch is
    # never reached; this is the fallback for when it is not, so say what the
    # card is and what it needs. Gated on there being no SAS2 or SAS3 card as
    # well, so a mixed box still gets the backend that serves the cards this
    # plugin CAN read.
    if hba_has_sas4 && ! hba_has_sas2 && ! hba_has_sas3 && [ -z "$(find_storcli)" ]; then
        printf '{"error":"%s is a 24G/SAS4 controller on the mpi3mr driver. The bundled lsiutil and storcli cannot read this generation — it needs Broadcom StorCLI2, which HBAviewer does not support yet (issue #19)."}' \
            "$(_board_on 'mpi3mr')"
        return 1
    fi
    require_binary || return 1
    lsi_each_card _ov_one
}
_ov_one() {   # $1 port  $2 banner  $3 board  $4 hnum  $5 pdir  $6 nports
    local IOC IDENT
    IOC=$(mktemp); IDENT=$(mktemp)
    hba_query -p"$1" -a 25,2,0,0 2>/dev/null > "$IOC"
    # Main-menu option 1 = "Identify firmware, BIOS, and/or FCode". Plain menu
    # item, NOT expert mode, so no -e. Read-only: it reports what is flashed.
    hba_query -p"$1" -a 1,0      2>/dev/null > "$IDENT"
    # An unresolved host reads "unknown", which suppresses the firmware verdict.
    # A WRONG topology would be worse than none: it is what gates the multipath
    # suppression, and acting on a false BEHIND destroys a working config.
    LSI_TOPOLOGY=$([ -n "$4" ] && hba_topology "$4" || printf 'unknown')
    LSI_SUBVENDOR=$([ -n "$5" ] && hba_subvendor "$5")
    # The slot, for grouping the two IOCs of a dual-controller board.
    LSI_CARD_ID=$([ -n "$5" ] && hba_card_id "$5")
    export LSI_TOPOLOGY LSI_SUBVENDOR LSI_CARD_ID
    bash "$DIR/parse/hba.sh" "$IOC" "$2" "$3" "$ALERT" "$IDENT" "$1"
    rm -f "$IOC" "$IDENT"
}

out=$(hba_each ov_storcli ov_lsiutil ov_storcli2)

printf '%s' "$out"
# Cache only good output, so a transient error is retried next call.
case "$out" in *'"error"'*) : ;; *) printf '%s' "$out" > "$CACHE" 2>/dev/null ;; esac
