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

ov_lsiutil() {
    # No storcli, and EVERY controller is on the mpt3sas personality: genuine
    # SAS3/3.5 hardware that the bundled lsiutil 1.70 cannot read. Keyed off
    # proc_name, not /sys/module — the merged driver reports proc_name=mpt2sas for
    # SAS2 cards even when only the mpt3sas module is loaded, and lsiutil reads
    # those fine (issue #3: /dev/mptctl present, IOC temperature returned). The
    # old /sys/module test refused those cards outright. hba_has_sas3 also keeps a
    # box with no HBA at all falling through to require_binary's clearer error.
    if [ -z "$(find_storcli)" ] && hba_has_sas3 && ! hba_has_sas2; then
        local h board=""
        for h in "${SYS_SCSI_HOST:-/sys/class/scsi_host}"/host*/; do
            case "$(cat "${h}proc_name" 2>/dev/null)" in mpt3sas|mpt2sas|mptsas) ;; *) continue ;; esac
            board=$(tr -d '\n' < "${h}board_name" 2>/dev/null)
            [ -n "$board" ] && break
        done
        printf '{"error":"%s is on the mpt3sas driver and the bundled lsiutil cannot read through it. Install storcli via the dkaser/unraid-storcli plugin (Community Applications), then reload."}' \
            "${board:-This controller}"
        return 1
    fi
    require_binary || return 1
    local IOC BANNER BOARD IDENT
    IOC=$(mktemp); BANNER=$(mktemp); BOARD=$(mktemp); IDENT=$(mktemp)
    trap 'rm -f "$IOC" "$BANNER" "$BOARD" "$IDENT"' EXIT
    hba_query -p"$PORT" -a 25,2,0,0 2>/dev/null > "$IOC"
    printf '0\n' | hba_query        2>/dev/null > "$BANNER"
    hba_query -b                    2>/dev/null > "$BOARD"
    # Main-menu option 1 = "Identify firmware, BIOS, and/or FCode". Plain menu
    # item, NOT expert mode, so no -e. Read-only: it reports what is flashed.
    hba_query -p"$PORT" -a 1,0      2>/dev/null > "$IDENT"
    # lsiutil reports no PCI address at all, so the card is reached through its
    # scsi_host — the same walk issue #14 added for the PCIe link maximum.
    local hnum pdir
    hnum=$(_first_sas_host)
    pdir=$([ -n "$hnum" ] && _pci_dir_of_host "$hnum")
    LSI_TOPOLOGY=$([ -n "$hnum" ] && hba_topology "$hnum" || printf 'unknown')
    LSI_SUBVENDOR=$([ -n "$pdir" ] && hba_subvendor "$pdir")
    # At most one card here: lsiutil addresses a single controller, so this
    # path never produces two entries to group — resolves to empty whenever
    # the ancestry isn't visible, same as the storcli path. Emitted anyway so
    # the field means the same thing on both backends.
    LSI_CARD_ID=$([ -n "$pdir" ] && hba_card_id "$pdir")
    export LSI_TOPOLOGY LSI_SUBVENDOR LSI_CARD_ID
    bash "$DIR/parse/hba.sh" "$IOC" "$BANNER" "$BOARD" "$ALERT" "$IDENT"
}

out=$(hba_each ov_storcli ov_lsiutil)

printf '%s' "$out"
# Cache only good output, so a transient error is retried next call.
case "$out" in *'"error"'*) : ;; *) printf '%s' "$out" > "$CACHE" 2>/dev/null ;; esac
