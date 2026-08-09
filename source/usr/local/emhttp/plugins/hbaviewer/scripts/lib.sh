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

# Locate storcli (SAS3/3.5 tool) — same candidates as scripts/capture_storcli.sh.
# Honors a preset $STORCLI. Prints the resolved path, or nothing if not found.
find_storcli() {
    if [ -n "$STORCLI" ]; then echo "$STORCLI"; return; fi
    local c
    for c in storcli storcli64 storcli2 \
             /usr/local/sbin/storcli /usr/local/sbin/storcli64 \
             /usr/local/bin/storcli /usr/local/bin/storcli64 \
             /usr/sbin/storcli /usr/sbin/storcli64; do
        command -v "$c" >/dev/null 2>&1 && { command -v "$c"; return; }
        [ -x "$c" ] && { echo "$c"; return; }
    done
}

# Locate the per-generation flash tool — sibling of find_storcli, same posture
# (proprietary, never bundled: probe PATH + common sbin dirs + the plugin's
# persisted upload dir). $1 = "sas2" | "sas3". Honors a preset $FLASHER (tests).
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
    # is a silent no-op. The upload therefore worked and the tool was then
    # invisible to this function, which resolves on [ -x ] -- the file sat in
    # tools/ reading -rw------- while the page said no tool was installed.
    # Measured on a live box: fmask=0177,dmask=0077.
    #
    # /boot is still the right place to PERSIST it (it survives a reboot, which
    # is the whole point of the upload) and the wrong place to RUN it from. So
    # copy it where the bit sticks and hand back that path.
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
    local src="${LSI_TOOLS:-/boot/config/plugins/hbaviewer/tools}/$tool"
    [ -r "$src" ] || return 1
    local staged="${LSI_TOOL_STAGE:-/tmp/hbaviewer-tools}/$tool"
    if [ ! -x "$staged" ] || [ "$src" -nt "$staged" ]; then
        mkdir -p "${staged%/*}" 2>/dev/null || return 1
        cp -f "$src" "$staged" 2>/dev/null || return 1
        chmod 0755 "$staged" 2>/dev/null || return 1
    fi
    [ -x "$staged" ] && echo "$staged"
}

# True (and export a resolved $STORCLI) iff storcli is present and enumerates a
# controller. The routing test every tab composer uses to pick its backend.
use_storcli() {
    local sc n
    sc="$(find_storcli)"
    [ -n "$sc" ] || return 1
    n=$("$sc" show 2>/dev/null | grep -m1 'Number of Controllers' | grep -oE '[0-9]+')
    [ -n "$n" ] && [ "$n" -gt 0 ] || return 1
    STORCLI="$sc"; export STORCLI; return 0
}

# Controller count from storcli's enumeration — the single parse of
# "Number of Controllers" that every storcli path shares. Empty if none.
storcli_count() {
    "$STORCLI" show 2>/dev/null | grep -m1 'Number of Controllers' | grep -oE '[0-9]+'
}

# Driver + version string for the loaded mpt driver. One detector for both
# backends. ponytail: mpt3sas first — a storcli box is SAS3 (mpt3sas); a SAS2
# lsiutil box loads only mpt2sas, so order can't misfire there.
hba_driver() {
    if   [ -r /sys/module/mpt3sas/version ]; then echo "mpt3sas $(cat /sys/module/mpt3sas/version)"
    elif [ -r /sys/module/mpt2sas/version ]; then echo "mpt2sas $(cat /sys/module/mpt2sas/version)"
    fi
}

# Which mpt personality claimed each controller — one line per SAS host, empty if
# there is no LSI HBA. This, NOT /sys/module/*, is the honest SAS2-vs-SAS3 signal:
# the merged mpt3sas driver registers SAS2 cards under the mpt2sas personality, so
# issue #3's SAS9207-8i has no mpt2sas module at all yet reports proc_name=mpt2sas.
# SYS_SCSI_HOST is overridable so the suite can point it at a fixture tree.
hba_personalities() {
    local h p
    for h in "${SYS_SCSI_HOST:-/sys/class/scsi_host}"/host*/; do
        p=$(cat "${h}proc_name" 2>/dev/null)
        case "$p" in mpt3sas|mpt2sas|mptsas) echo "$p" ;; esac
    done
}

# True iff any controller is on the mpt2sas/mptsas personality — i.e. the bundled
# lsiutil 1.70 has a card it can reach. Verified on issue #3's mpt3sas-only box:
# /dev/mptctl exists there and lsiutil read the IOC temperature fine.
hba_has_sas2() { case "$(hba_personalities)" in *mpt2sas*|*mptsas*) return 0 ;; esac; return 1; }

# True iff any controller is on the mpt3sas personality — genuine SAS3/3.5, needs
# storcli. Both can be true on a box with one card of each generation.
hba_has_sas3() { case "$(hba_personalities)" in *mpt3sas*) return 0 ;; esac; return 1; }

# The backend seam. Chooses storcli-vs-lsiutil ONCE, owns controller
# enumeration and the {"backend","driver","controllers":[...]} wrapper, so a
# composer only declares *what to run per controller*.
#   $1 = storcli fn: `fn <c>` prints controller c's JSON object ($STORCLI
#        resolved+exported, count already > 0).
#   $2 = lsiutil fn: prints the inner controller object(s) on success, OR
#        prints a top-level error JSON and returns non-zero to abort the wrap.
hba_each() {
    local storcli_fn="$1" lsiutil_fn="$2" c count body rc
    if use_storcli; then
        count=$(storcli_count)
        printf '{"backend":"storcli","driver":"%s","controllers":[' "$(hba_driver)"
        for c in $(seq 0 $((count - 1))); do
            [ "$c" -gt 0 ] && printf ','
            "$storcli_fn" "$c"
        done
        printf ']}'
    else
        body=$("$lsiutil_fn"); rc=$?
        if [ "$rc" -ne 0 ]; then printf '%s' "$body"; return; fi
        printf '{"backend":"lsiutil","driver":"%s","controllers":[%s]}' "$(hba_driver)" "$body"
    fi
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

# First SAS host (mpt2sas/mpt3sas/mptsas) — same personality filter as
# hba_personalities above, but keeping the host NUMBER, needed to key
# _phys_json. The bundled lsiutil binary only ever addresses one controller.
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
