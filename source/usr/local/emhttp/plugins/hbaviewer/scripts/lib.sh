#!/bin/bash
# Shared HBA invocation — the single seam to the lsiutil binary.
#
# hba_query owns only the universal part: where the binary lives. Everything
# else (port, -e expert flag, -a menu args, -b, stdin) passes through, so the
# same function covers every call style:
#   hba_query -p"$PORT" -a 25,2,0,0     # menu command on a port
#   printf '0\n' | hba_query            # interactive banner, no port
#   hba_query -b                        # board info, no port
#   hba_query -e -p"$PORT" -a 35,0      # expert-mode command
#
# require_binary emits the not-found error JSON and returns non-zero. Composers
# call it BEFORE the query|parse pipe so the error reaches PHP, never a parser.

LSIUTIL="${LSIUTIL:-/usr/local/emhttp/plugins/hbaviewer/hbaviewer.x86_64}"

require_binary() {
    if [ ! -x "$LSIUTIL" ]; then
        echo '{"error":"lsiutil binary not found. Re-install the plugin."}'
        return 1
    fi
}

hba_query() { "$LSIUTIL" "$@"; }

# Every storcli-family binary on the box, deduped, in probe order — both
# flavors mixed together on purpose. WHICH one can actually read the hardware
# is decided by use_storcli() probing each in turn below, never by position in
# this list.
#
# The bare names are tried before the absolute-path candidates because
# dkaser's plugin symlinks /usr/local/bin/storcli and /usr/local/bin/storcli2
# onto PATH, but ships the StorCLI2 Lite build as storcli2Lite-8.14 and
# symlinks THAT to whatever name the packager chose — if that symlink lands
# anywhere on PATH other than /usr/local/bin/storcli2, the absolute paths
# alone miss it.
#
# Loop structure and the awk dedupe: techanonymous, 882f88c, MIT.
storcli_candidates() {
    local c
    for c in storcli storcli64 storcli2 \
             /usr/local/sbin/storcli /usr/local/sbin/storcli64 \
             /usr/local/bin/storcli /usr/local/bin/storcli64 /usr/local/bin/storcli2 \
             /usr/sbin/storcli /usr/sbin/storcli64 \
             /opt/MegaRAID/storcli2/storcli2; do
        if   command -v "$c" >/dev/null 2>&1; then command -v "$c"
        elif [ -x "$c" ];                     then echo "$c"
        fi
    done | awk '!seen[$0]++'
}

# First storcli-family binary present — a PRESENCE test only, for "is any
# storcli installed at all?" (get_hba_info.sh's SAS4 refusal guard, and
# settings.php). To get the one that can actually read THIS card, call
# use_storcli. Honors a preset $STORCLI.
find_storcli() {
    if [ -n "$STORCLI" ]; then echo "$STORCLI"; return; fi
    storcli_candidates | head -1
}

# Locate the per-generation flash tool — sibling of find_storcli, same posture
# (proprietary, never bundled: probe PATH + common sbin dirs + the drop
# directory the user places it in). $1 = "sas2" | "sas3". Honors a preset
# $FLASHER (tests).
# Prints the resolved path, or nothing if not found.
find_flasher() {
    local gen="$1" tool c
    if [ -n "$FLASHER" ]; then echo "$FLASHER"; return; fi
    case "$gen" in
        sas2) tool=sas2flash ;;
        sas3) tool=sas3flash ;;
        *)    return 1 ;;
    esac
    for c in "$tool" \
             "/usr/local/sbin/$tool" "/usr/local/bin/$tool" "/usr/sbin/$tool"; do
        command -v "$c" >/dev/null 2>&1 && { command -v "$c"; return; }
        [ -x "$c" ] && { echo "$c"; return; }
    done

    # The user-supplied copy on /boot, staged into tmpfs before it is returned.
    #
    # /boot is the Unraid flash drive: vfat, mounted fmask=0177. That masks off
    # every execute bit, so a file there can NEVER be executable and chmod on it
    # is a silent no-op. The file therefore lands correctly and is then invisible
    # to this function, which resolves on [ -x ] -- it sat in the drop directory
    # reading -rw------- while the page said no tool was installed.
    # Measured on a live box: fmask=0177,dmask=0077.
    #
    # /boot is still the right place to PERSIST it -- it survives a reboot, and
    # it stays mounted when the array is stopped, which flashing requires -- and
    # the wrong place to RUN it from. So copy it where the bit sticks and hand
    # back that path.
    #
    # NOT appdata, which is the obvious answer and the wrong one: flashing
    # requires the array to be STOPPED, and /mnt/user and /mnt/cache are
    # unmounted when it is. A tool under appdata would be present through every
    # test where somebody forgot to stop the array and absent for every real
    # flash. /boot and tmpfs are the only two locations guaranteed to exist in
    # the exact condition this feature runs in, which is why it takes both.
    #
    # Yes, a lookup that writes. The alternative is find_flasher and the flash
    # itself disagreeing about whether a tool exists, which is worse: the page
    # would say "not installed" about a tool the flash would go on to use.
    # Re-staged whenever the /boot copy is newer, so replacing the tool takes
    # effect without a reboot.
    # The single drop directory the firmware page tells users to scp into, then
    # the pre-2026.08.09 tools/ location so anyone who already placed a tool
    # there is not stranded by the rename.
    local d src=""
    for d in "${LSI_TOOLS:-/boot/config/plugins/hbaviewer/flash}" \
             /boot/config/plugins/hbaviewer/tools; do
        [ -r "$d/$tool" ] && { src="$d/$tool"; break; }
    done
    [ -n "$src" ] || return 1
    local staged="${LSI_TOOL_STAGE:-/tmp/hbaviewer-tools}/$tool"
    if [ ! -x "$staged" ] || [ "$src" -nt "$staged" ]; then
        mkdir -p "${staged%/*}" 2>/dev/null || return 1
        cp -f "$src" "$staged" 2>/dev/null || return 1
        chmod 0755 "$staged" 2>/dev/null || return 1
    fi
    [ -x "$staged" ] && echo "$staged"
}

# "storcli2" (SAS4 / 9600, mpi3mr) or "storcli" (SAS3/3.5, mpt3sas), read from
# the binary's own banner — StorCLI2 prints "StorCli2 SAS Customization
# Utility". The FILENAME is not a usable signal: the storcli2 build ships as
# storcli2Lite-8.14 and is symlinked to whatever name the packager chose.
# $STORCLI_FLAVOR overrides (tests).
#
# The cd is deliberate: both tools write a debug log into the current
# directory, and running them from /tmp keeps that out of the plugin tree.
storcli_flavor() {
    [ -n "$STORCLI_FLAVOR" ] && { echo "$STORCLI_FLAVOR"; return; }
    case "$( ( cd "${STORCLI_CWD:-/tmp}" 2>/dev/null || cd /; "$1" version 2>/dev/null ) | head -5)" in
        *StorCli2*|*StorCLI2*|*storcli2*) echo storcli2 ;;
        *)                                echo storcli  ;;
    esac
}

# True (the count) iff $1 enumerates a controller. Run through the same
# scratch-dir subshell storcli_run uses (issue: this probe used to run "$sc
# show" straight in the caller's cwd — the plugin's own scripts/ dir, which is
# tmpfs — dropping a ~230KB debug log there on every tab read, on a StorCLI2
# box, before the flavor was even known well enough to call storcli_run).
_storcli_enumerates() {   # $1 = binary -> prints the count, non-zero if none
    local n
    n=$( ( cd "${STORCLI_CWD:-/tmp}" 2>/dev/null || cd /; "$1" show 2>/dev/null ) \
         | grep -m1 'Number of Controllers' | grep -oE '[0-9]+')
    [ -n "$n" ] && [ "$n" -gt 0 ] || return 1
    echo "$n"
}

# True (and export a resolved $STORCLI + $STORCLI_FLAVOR) iff some storcli-
# family binary enumerates a controller. The routing test every tab composer
# uses to pick its backend.
#
# Probes every candidate rather than trusting the first name found: a 9600 box
# typically has classic storcli installed too (dkaser's unraid-storcli plugin
# ships both), and it answers "Number of Controllers = 0" there —
# indistinguishable from "no card" unless the other flavor gets a turn. That
# used to be find_storcli's job, and its first-match order (storcli ahead of
# storcli2) made SAS4 boxes with both tools installed dead on arrival.
use_storcli() {
    local sc
    # A preset override is honored verbatim and never probed past — the suite
    # points $STORCLI at a stub, and falling through to a real binary on the
    # runner's PATH would make the fixture silently not the thing under test.
    if [ -n "$STORCLI" ]; then
        _storcli_enumerates "$STORCLI" >/dev/null || return 1
        STORCLI_FLAVOR=$(storcli_flavor "$STORCLI")
        export STORCLI STORCLI_FLAVOR; return 0
    fi
    while read -r sc; do
        [ -n "$sc" ] || continue
        _storcli_enumerates "$sc" >/dev/null || continue
        STORCLI="$sc"; STORCLI_FLAVOR=$(storcli_flavor "$sc")
        export STORCLI STORCLI_FLAVOR; return 0
    done < <(storcli_candidates)
    return 1
}

# Run the storcli-family binary from somewhere harmless. Both tools drop a debug
# log into the CURRENT directory, and the plugin's own scripts/ dir is tmpfs --
# upstream measured storcli2 writing ~230KB there per call.
storcli_run() {
    ( cd "${STORCLI_CWD:-/tmp}" 2>/dev/null || cd /; "$STORCLI" "$@" )
}

pci_addr_to_sysfs_dir() {   # $1 = "dom:bus:dev:fn"
    local dom bus dev fn
    [ -n "$1" ] || return 1
    IFS=: read -r dom bus dev fn <<< "$1"
    [ -n "$bus" ] && [ -n "$dev" ] || return 1
    printf '%s/%s' "${SYS_PCI_ROOT:-/sys/bus/pci/devices}" \
        "$(printf '%04x:%s:%s.%d' "0x${dom:-0}" "$bus" "$dev" "0x${fn:-0}")"
}

# Controller count from storcli's enumeration — the single parse of
# "Number of Controllers" that every storcli path shares. Empty if none.
storcli_count() {
    if [ "$STORCLI_FLAVOR" = storcli2 ]; then
        storcli_run show nolog 2>/dev/null | grep -m1 'Number of Controllers' | grep -oE '[0-9]+'
    else
        storcli_run show 2>/dev/null | grep -m1 'Number of Controllers' | grep -oE '[0-9]+'
    fi
}

# Driver + version string for the loaded mpt driver. One detector for both
# backends. ponytail: mpt3sas first — a storcli box is SAS3 (mpt3sas); a SAS2
# lsiutil box loads only mpt2sas, so order can't misfire there.
hba_driver() {
    if   [ -r /sys/module/mpt3sas/version ]; then echo "mpt3sas $(cat /sys/module/mpt3sas/version)"
    elif [ -r /sys/module/mpt2sas/version ]; then echo "mpt2sas $(cat /sys/module/mpt2sas/version)"
    fi
}

# The one place the supported personality list lives. It was copy-pasted into
# six scripts, so adding a driver meant finding all six.
hba_is_sas_proc() { case "$1" in mpt3sas|mpt2sas|mptsas|mpi3mr) return 0 ;; esac; return 1; }

# Which mpt personality claimed each controller — one line per SAS host, empty if
# there is no LSI HBA. This, NOT /sys/module/*, is the honest SAS2-vs-SAS3 signal:
# the merged mpt3sas driver registers SAS2 cards under the mpt2sas personality, so
# issue #3's SAS9207-8i has no mpt2sas module at all yet reports proc_name=mpt2sas.
# SYS_SCSI_HOST is overridable so the suite can point it at a fixture tree.
hba_personalities() {
    local h p
    for h in "${SYS_SCSI_HOST:-/sys/class/scsi_host}"/host*/; do
        p=$(cat "${h}proc_name" 2>/dev/null)
        hba_is_sas_proc "$p" && echo "$p"
    done
}

# True iff any controller is on the mpt2sas/mptsas personality — i.e. the bundled
# lsiutil 1.70 has a card it can reach. Verified on issue #3's mpt3sas-only box:
# /dev/mptctl exists there and lsiutil read the IOC temperature fine.
hba_has_sas2() { case "$(hba_personalities)" in *mpt2sas*|*mptsas*) return 0 ;; esac; return 1; }

# True iff any controller is on the mpt3sas personality — genuine SAS3/3.5, needs
# storcli. Both can be true on a box with one card of each generation.
hba_has_sas3() { case "$(hba_personalities)" in *mpt3sas*) return 0 ;; esac; return 1; }

# True iff any controller is on the mpi3mr personality — Broadcom's 24G/SAS4
# generation, the 9600 series on SAS4116/SAS4024 (issue #19). Listed so the card
# can be NAMED, never so it can be read: lsiutil 1.70 predates it by a decade and
# storcli enumerates zero controllers on it — 24G needs StorCLI2, which this
# plugin does not speak. Deliberately NOT folded into hba_has_sas3: everything
# downstream of that predicate assumes storcli can read the card.
hba_has_sas4() { case "$(hba_personalities)" in *mpi3mr*) return 0 ;; esac; return 1; }

# The backend seam. Chooses storcli / storcli2 / lsiutil ONCE, owns controller
# enumeration and the {"backend","driver","controllers":[...]} wrapper, so a
# composer only declares *what to run per controller*.
#   $1 = storcli fn: `fn <c>` prints controller c's JSON object ($STORCLI
#        resolved+exported, count already > 0).
#   $2 = lsiutil fn: prints the inner controller object(s) on success, OR
#        prints a top-level error JSON and returns non-zero to abort the wrap.
#   $3 = storcli2 fn (optional): same contract as $1, for the SAS4 tool whose
#        command set and output differ. Defaults to $1, so a composer that has
#        not been ported yet keeps its old behaviour instead of breaking.
# The emitted "backend" is the FLAVOR — endpoints branch on that field, never on
# which binary exists (a 9600 box can have both installed).
hba_each() {
    local storcli_fn="$1" lsiutil_fn="$2" storcli2_fn="${3:-$1}" fn c count body rc
    if use_storcli; then
        count=$(storcli_count)
        if [ "$STORCLI_FLAVOR" = storcli2 ]; then fn="$storcli2_fn"; else fn="$storcli_fn"; fi
        printf '{"backend":"%s","driver":"%s","controllers":[' "${STORCLI_FLAVOR:-storcli}" "$(hba_driver)"
        for c in $(seq 0 $((count - 1))); do
            [ "$c" -gt 0 ] && printf ','
            "$fn" "$c"
        done
        printf ']}'
    else
        body=$("$lsiutil_fn"); rc=$?
        if [ "$rc" -ne 0 ]; then printf '%s' "$body"; return; fi
        printf '{"backend":"lsiutil","driver":"%s","controllers":[%s]}' "$(hba_driver)" "$body"
    fi
}

# Every port the bundled lsiutil can address, one per line. lsiutil's own port
# table — the banner it prints before the device menu — is the authority on the
# numbering that -p takes:
#
#    1.  ioc0   LSI Logic SAS2308    14000700     b0
#    2.  ioc1   LSI Logic SAS2308    14000700     b0
#
# Every composer already captures that banner, so enumeration costs no extra
# hardware call. Issue #18: three 2308s in one box, and the plugin read only the
# port Settings named, while Detected Hardware (sysfs, not lsiutil) listed all
# three — a display that looks complete and monitors one card.
# Falls back to $PORT so a box whose banner cannot be parsed behaves exactly as
# it did before this existed.
lsi_ports() {   # $1 = banner file
    local rows
    rows=$(grep -E "^[[:space:]]+[0-9]+\.[[:space:]]+ioc" "$1" 2>/dev/null)
    if [ -n "$rows" ]; then
        printf '%s\n' "$rows" | sed -E 's/^[[:space:]]*([0-9]+)\..*/\1/'
    else
        printf '%s\n' "${PORT:-1}"
    fi
}

# The scsi host a looping composer should attribute to one port, with the ONE
# rule every one of them has to apply the same way: a failed join must not fall
# back to card 1 when the box has more than one card. Two cards sharing a host
# share their topology, card_id, drive count and PHY counters — and identical
# card_ids make card_group.php fuse physically separate cards into one display
# card, the exact inverse of the dual-IOC feature. With a single port there is
# nothing to confuse, so the historic _first_sas_host fallback stands and
# single-card output is unchanged.
lsi_host_for() {   # $1 = bus   $2 = device   $3 = how many ports the box has
    local h
    if h=$(_host_for_pci "$1" "$2"); then printf '%s' "$h"; return 0; fi
    [ "$3" = "1" ] && _first_sas_host
}

# Every lsiutil card, joined and ready to read. Captures the banner and the -b
# board table ONCE (both list every port in a single call), enumerates the
# ports, resolves each port's scsi host through the one join rule and that
# host's PCI dir, calls $1 per card and comma-joins what it printed.
#
#   lsi_each_card CALLBACK
#   CALLBACK PORT BANNER BOARD HNUM PDIR NPORTS
#
# Plan 059 taught five composers this loop and each of them assembled it from
# lib.sh's primitives differently: five emit loops, three banner captures, two
# spellings of the port count, and eleven mktemps with three traps between them.
# This is that loop, once.
#
# Every composer loops as a unit because ajax_info.php joins the tabs by
# ARRAY INDEX: a tab that returns fewer cards mislabels hardware rather than
# merely omitting it.
#
# HNUM is EMPTY when the join failed on a multi-card box -- deliberately, since
# handing a card its neighbour's host is the bug issue #18 was filed about. What
# a tab does with that is the tab's business and differs for good reasons: the
# overview reports an unknown topology (which suppresses the firmware verdict),
# health falls back to host 0 on a single-card box (which its goldens pin), and
# attached-drives reports nothing rather than sweeping sysfs box-wide.
lsi_each_card() {   # $1 = callback name
    local BANNER BOARD ports nports p row bus dev hnum pdir first=1
    BANNER=$(mktemp); BOARD=$(mktemp)
    printf '0\n' | hba_query 2>/dev/null > "$BANNER"
    hba_query -b             2>/dev/null > "$BOARD"
    ports=$(lsi_ports "$BANNER")
    nports=$(echo $ports | wc -w | tr -d ' ')   # unquoted: count the tokens
    for p in $ports; do
        row=$(grep "ioc" "$BOARD" | sed -n "${p}p")
        bus=$(echo "$row" | awk '{print $3}')
        dev=$(echo "$row" | awk '{print $4}')
        hnum=$(lsi_host_for "$bus" "$dev" "$nports")
        pdir=$([ -n "$hnum" ] && _pci_dir_of_host "$hnum")
        [ "$first" = 1 ] || printf ','
        first=0
        "$1" "$p" "$BANNER" "$BOARD" "$hnum" "$pdir" "$nports"
    done
    rm -f "$BANNER" "$BOARD"
}

# The PCI device behind a scsi_host. lsiutil never reports a PCI address (and
# unlike storcli there is no line to parse), but the kernel already knows it:
# /sys/class/scsi_host/hostN resolves into the device tree under the card, so
# walk up until a dir that publishes link state appears. Issue #14 — a SAS2308
# negotiated at x4 in a chipset slot, with the card's x8 maximum sitting in
# sysfs the whole time while the plugin reported no maximum at all.
# Lives here rather than in get_hba_health.sh because the overview composer now
# needs the same walk to reach subsystem_vendor.
_pci_dir_of_host() {   # $1 = scsi host number
    local d
    d=$(readlink -f "${SYS_SCSI_HOST:-/sys/class/scsi_host}/host$1" 2>/dev/null)
    while [ -n "$d" ] && [ "$d" != "/" ] && [ "$d" != "." ]; do
        [ -r "$d/current_link_width" ] && { printf '%s' "$d"; return 0; }
        d=$(dirname "$d")
    done
}

# The scsi host number of the card at a given PCI bus/device. lsiutil prints no
# PCI address in its own telemetry, but `-b` does — the Bus and Device columns
# parse/hba.sh already reads — and every scsi_host resolves to a PCI dir through
# _pci_dir_of_host. That is the join, and it is what lets a per-port loop reach
# the RIGHT card's sysfs: port -> bus/dev -> host -> topology/subvendor/card_id.
# Prints nothing and returns non-zero when no host matches; the caller decides
# what that means, and on a multi-port box it must NOT mean card 1 (see the
# gate in ov_lsiutil — two cards sharing one card_id would be grouped into one
# display card, the exact inverse of the dual-IOC feature).
# **lsiutil prints Seg/Bus/Dev in DECIMAL; sysfs is hex.** Confirmed on the
# 3-card box in issue #18: `-b` says bus 129, 130, 131 and sysfs says
# 0000:81:00.0, 0000:82:00.0, 0000:83:00.0. The 2-card bundle could not have
# shown this — its buses are 1 and 6, identical in either base — which is
# exactly the kind of agreement that makes a wrong assumption look verified.
_host_for_pci() {   # $1 = bus (decimal)   $2 = device (decimal)
    local h hn d bus dev
    # Digits-only first, then 10# on the arithmetic: a garbled column must
    # return 1, never abort the composer with a bash error mid-JSON, and a
    # zero-padded "08" must not be read as invalid octal.
    case "$1" in ''|*[!0-9]*) return 1 ;; esac
    case "$2" in ''|*[!0-9]*) return 1 ;; esac
    bus=$(printf '%02x' "$((10#$1))")
    dev=$(printf '%02x' "$((10#$2))")
    for h in "${SYS_SCSI_HOST:-/sys/class/scsi_host}"/host*/; do
        case "$(cat "${h}proc_name" 2>/dev/null)" in mpt3sas|mpt2sas|mptsas) ;; *) continue ;; esac
        hn=${h%/}; hn=${hn##*host}
        d=$(_pci_dir_of_host "$hn")
        [ -n "$d" ] || continue
        case "$(basename "$d")" in *:"$bus":"$dev".*) printf '%s' "$hn"; return 0 ;; esac
    done
    return 1
}

# First SAS host (mpt2sas/mpt3sas/mptsas) — same personality filter as
# hba_personalities above, but keeping the host NUMBER, needed to key
# _phys_json. The fallback route, for the single-port case and for a card whose
# board line gives no PCI address to join on; _host_for_pci above is the one
# that can tell two cards apart.
# Lives here rather than in get_hba_health.sh because the overview composer now
# needs the same lookup to reach this card's topology and subsystem_vendor.
_first_sas_host() {
    local h
    for h in "${SYS_SCSI_HOST:-/sys/class/scsi_host}"/host*/; do
        case "$(cat "${h}proc_name" 2>/dev/null)" in
            mpt3sas|mpt2sas|mptsas) basename "$h" | sed 's/^host//'; return ;;
        esac
    done
}

# Is this controller directly attached, or is there an expander in the path?
#
# Broadcom publishes a SEPARATE multi-path firmware track for the 9300, 9302,
# 9305, 9400 and 9405W, with its own version numbering — a card on that track
# correctly runs a version far below the standard branch. Comparing the two
# tracks reports a working multipath card as badly out of date, and acting on
# that destroys the configuration. So the firmware verdict is suppressed unless
# the card can be shown to be internal, and this is that proof.
#
# Two independent signals, either sufficient to disqualify: an expander device
# for this host, or any three-component end_device-H:N:M child (a device behind
# something that numbers its own PHYs). The two-vs-three component rule is the
# same one get_hba_health.sh's _phys_json uses to keep an expander's PHYs out of
# a controller's own error counts (issue #12).
#
# Scoped to ONE host: a box with two HBAs, one behind an expander, must not have
# that expander silence the other card. An empty tree is "unknown", not
# "internal" — a card with nothing attached proves nothing about topology.
hba_topology() {   # $1 = scsi host number -> "internal" | "unknown"
    local d n found=0
    for d in "${SYS_SAS_EXPANDER:-/sys/class/sas_expander}"/expander-"${1}":*; do
        [ -e "$d" ] && { printf 'unknown'; return; }
    done
    for d in "${SYS_SAS_DEVICE:-/sys/class/sas_device}"/end_device-"${1}":*; do
        [ -e "$d" ] || continue
        found=1
        n=$(basename "$d")
        case "${n#end_device-}" in *:*:*) printf 'unknown'; return ;; esac
    done
    [ "$found" -eq 1 ] && printf 'internal' || printf 'unknown'
}

# PCI subsystem vendor for a card, from its sysfs device dir. 0x1000 is a
# generic Broadcom board; anything else is an OEM rebrand (IBM M1015, Dell
# H200/H310 and friends) whose NVDATA and BIOS differ, where reaching a generic
# firmware version is a CROSSFLASH rather than an upgrade. Getting this wrong
# tells a user to perform a materially riskier operation than the one described,
# so an unreadable attribute must yield empty and suppress the verdict — never a
# default that happens to look generic.
hba_subvendor() {   # $1 = sysfs PCI device dir
    local v
    v=$(cat "$1/subsystem_vendor" 2>/dev/null) || return 0
    printf '%s' "${v//[[:space:]]/}"
}

# The physical slot a controller occupies, named by its PCI root port -- the
# first device under the host bridge in the resolved sysfs path. Two
# controllers sharing one are on the same board unless a switch sits between
# them and the root port, which is why grouping also requires ioc_count to
# match the group size exactly. pci_location cannot answer this and
# board_name must not: two SEPARATE 9300-8i cards report the same name, so
# grouping on it would merge unrelated hardware, which is worse than not
# grouping at all.
#
# A SAS9300-16i carries a PCIe switch of its own, so its two SAS3008 IOCs
# differ at every level below the root port:
#   pci0000:80/0000:80:01.0/0000:82:00.0/0000:83:00.0/0000:84:00.0
#   pci0000:80/0000:80:01.0/0000:82:00.0/0000:83:09.0/0000:86:00.0
#
# Empty when the ancestry is not visible -- an absent entry, a flat test tree,
# or a backend that reports no PCI address. Callers MUST treat empty as "do not
# group", including against other empties: two unknowns are not a match.
hba_card_id() {   # $1 = sysfs PCI device dir -> "0000:80:01.0" | ""
    local real rest
    real=$(readlink -f "$1" 2>/dev/null) || return 0
    # Redundant while readlink -f guarantees an absolute path (${rest%%/*} then
    # blanks it), and load-bearing the moment it does not: a relative input would
    # otherwise print a bogus slot ID -- the failure that merges unrelated cards.
    case "$real" in
        */pci[0-9][0-9][0-9][0-9]:[0-9a-f][0-9a-f]/*) ;;
        *) return 0 ;;
    esac
    rest="${real#*/pci[0-9][0-9][0-9][0-9]:[0-9a-f][0-9a-f]/}"
    rest="${rest%%/*}"
    case "$rest" in
        [0-9a-f][0-9a-f][0-9a-f][0-9a-f]:[0-9a-f][0-9a-f]:[0-9a-f][0-9a-f].[0-9])
            printf '%s' "$rest" ;;
    esac
}
