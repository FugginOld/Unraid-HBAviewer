#!/bin/bash
# HBA firmware/BIOS flash composer — the ONLY mutating backend script. Two modes:
#
#   flash_hba.sh list  <chip> <ctl>
#       Read-only preflight: run the resolved tool's listing so the user can
#       confirm it actually sees the card before touching anything.
#
#   flash_hba.sh flash <chip> <ctl> <fw.bin> [bios.rom]
#       Mutating: compose and run the exact per-generation flash command,
#       streaming stdout+stderr (the caller captures it to a log + exit code).
#
# Danger: a wrong image bricks the card. Every callable guardrail (array
# stopped, opt-in, confirmation, single-flight lock) lives in flash.php BEFORE
# this runs; this script still refuses on unknown chip / missing tool / bad args.
#
# $FLASHER / $STORCLI env overrides let tests point at a stub (same as lib.sh).

DIR="$(dirname "$0")"
source "$DIR/lib.sh"          # find_flasher, find_storcli

die() { echo "flash_hba: $1" >&2; exit "$2"; }

# Chip string (SAS2008 / SAS3008 / SAS3416 …) -> flash tool family.
#   SAS2xxx           -> sas2flash
#   SAS30xx/31xx/32xx -> sas3flash
#   SAS34xx-38xx      -> storcli (/cN download)
#
# Returns 0 with the family on stdout, 2 for a RAID-on-Chip part, 1 for a chip
# this script does not know. The caller distinguishes 1 from 2: "we cannot" and
# "nobody can" are different answers and the second one saves a support round.
#
# The globs were originally SAS2*, SAS30*|SAS31*, SAS34*|SAS35*, which left
# SAS32xx, SAS36xx and SAS38xx matching nothing at all — five of the thirteen
# boards in data/known-firmware.json, including the whole 9305 family, could not
# reach a flasher and reported "unsupported/unknown chip" on a card that flashes
# perfectly well with sas3flash. Found by running the mapping against every chip
# in the index rather than the two the tests happened to use.
flasher_for_chip() {
    case "$1" in
        # RAID-on-Chip first: these are MegaRAID parts with no IT firmware at any
        # version, and they cannot be crossflashed to one. Named before the family
        # globs below, which would otherwise hand SAS3108 to sas3flash and
        # SAS3508/SAS3516 to storcli as though an IT image were a thing they could
        # take. Same five as no_it_firmware in data/known-firmware.json — if that
        # list grows, grow this one with it.
        SAS2108|SAS2208|SAS3108|SAS3508|SAS3516) return 2 ;;

        SAS2*)                       echo sas2 ;;      # 9200/9201/9207/9211
        SAS30*|SAS31*|SAS32*)        echo sas3 ;;      # 9300, 9305
        SAS34*|SAS35*|SAS36*|SAS38*) echo storcli ;;   # 9400, 9405W, 9500 tri-mode
        *)                           return 1 ;;
    esac
}

mode="$1"; chip="$2"; ctl="$3"; fw="$4"; bios="$5"

# ── tool mode: which flasher does this chip need, and is it here? ─────────────
# Read-only, no controller index, never touches hardware. Exists so the firmware
# page can TELL the user which tool to supply before they need it, instead of
# finding out from a failure after pressing Verify. It answers from the same
# flasher_for_chip and find_* that the real modes use — the page must never
# carry a second copy of the mapping, which is how SAS32xx went unflashable
# without anyone noticing.
#
# Always exits 0 and always prints all four keys: "no tool for this chip" is an
# answer the page has to render, not an error it should swallow.
if [ "$mode" = tool ]; then
    t=$(flasher_for_chip "$chip"); rc=$?
    case $rc in
        0) if [ "$t" = storcli ]; then name=storcli; path=$(find_storcli)
           else name="${t}flash"; path=$(find_flasher "$t"); fi
           if [ -n "$path" ]; then st=found; else st=missing; fi
           printf 'family=%s\nname=%s\npath=%s\nstatus=%s\n' "$t" "$name" "$path" "$st" ;;
        2) printf 'family=\nname=\npath=\nstatus=roc\n' ;;
        *) printf 'family=\nname=\npath=\nstatus=unknown\n' ;;
    esac
    exit 0
fi

[ "$mode" = list ] || [ "$mode" = flash ] || die "unknown mode: '$mode'" 2
# One index, or a comma-separated list of them. A SAS9300-16i is one board
# carrying two SAS3008 IOCs, and the two are verified and written together --
# see the list block and the flash loop below. Strict about the shape (no empty
# element, no leading/trailing comma) because every element of this value
# becomes the controller argument of a tool that writes firmware.
case "$ctl" in
    ''|*[!0-9,]*|,*|*,|*,,*)
        die "controller must be an index, or a comma-separated list of indices: '$ctl'" 2 ;;
esac

gen=$(flasher_for_chip "$chip")
case $? in
    0) ;;
    2) die "$chip is a RAID-on-Chip part (MegaRAID). No IT firmware exists for it at any version and it cannot be crossflashed, so nothing here can flash it." 3 ;;
    *) die "unsupported/unknown chip: '$chip'" 3 ;;
esac

# Resolve the tool for this generation (storcli reuses the existing seam).
if [ "$gen" = storcli ]; then tool=$(find_storcli); else tool=$(find_flasher "$gen"); fi
# Name the BINARY, not the family. "flash tool for SAS3008 (sas3) not found" sent
# a maintainer looking for an inconsistency between two of his own boxes: one has
# 9400s, which flash via storcli and never want sas3flash at all, so the same
# plugin asked for a tool on one machine and not the other with no way to see
# why. The chip decides the tool; say which tool, and say where it looked.
if [ -z "$tool" ]; then
    case "$gen" in
        sas2|sas3) die "${gen}flash is required to flash $chip, and it is not installed. Broadcom does not permit bundling it. Copy it to /boot/config/plugins/hbaviewer/flash/${gen}flash — the same drop directory the firmware images go in — and it does not need to be executable there, the plugin stages a runnable copy itself. Cards on the 9400/9500 generation use storcli instead and need nothing extra." 4 ;;
        storcli)   die "storcli is required to flash $chip, and it is not installed. Install the dkaser/unraid-storcli plugin from Community Applications." 4 ;;
        *)         die "no flash tool is known for $chip ($gen)" 4 ;;
    esac
fi

if [ "$mode" = list ]; then
    # Scope to THE CARD — every IOC on it and nothing else. Not -listall: on a
    # box with a 9300-16i and a 9200-8i that would show the 9200 while the
    # operator is verifying the 16i, which is the confusion this scoping has
    # always existed to prevent. A dual-IOC board is one card, so both of its
    # controllers belong in the same verification output.
    rc=0
    for one in $(printf '%s' "$ctl" | tr ',' ' '); do
        echo "--- controller /c$one ---"
        if [ "$gen" = storcli ]; then "$tool" /c"$one" show || rc=$?
        else                          "$tool" -c "$one" -list || rc=$?; fi
    done
    exit $rc
fi

# ── flash ────────────────────────────────────────────────────────────────────
# Firmware and BIOS are each optional, but not both: sasNflash accepts -f, -b or
# both, so updating only the BIOS is a real operation the tool supports and this
# script used to refuse outright.
[ -n "$fw" ] || [ -n "$bios" ] || die "no firmware or BIOS image given" 5
[ -z "$fw" ]   || [ -f "$fw" ]   || die "firmware image not found: $fw" 5
[ -z "$bios" ] || [ -f "$bios" ] || die "BIOS image not found: $bios" 5

# Write EVERY IOC on this card, one at a time. Broadcom's advice for a
# dual-controller board is -fwall, which this deliberately does not use: -fwall
# means every controller in the SYSTEM, not every controller on this card, so on
# a box holding a 9300-16i and a 9300-8i it writes the 16i image to the 8i and
# bricks it. Looping the card's own indices meets the same intent — never leave
# one IOC behind — with no blast radius.
#
# That the list IS this card's own indices is not decidable here; flash.php
# checks the posted list against the groups lsi_group_cards() derives from the
# live hardware, and refuses anything that is not exactly one card.
done_ok=""
for one in $(printf '%s' "$ctl" | tr ',' ' '); do
    # Per iteration, and never inherited: flash_rc is only assigned on failure,
    # so a value arriving from the environment made iteration 1 report a
    # SUCCESSFUL write as "nothing was written" — the most dangerous lie this
    # loop can tell, since the operator then re-runs from a state the script has
    # misdescribed. done_ok and rc are initialised for the same reason.
    flash_rc=""
    if [ "$gen" = storcli ]; then
        # SAS3.5 / 9400: firmware package flashed via storcli. The BIOS travels
        # INSIDE that package, so there is no separate BIOS file to flash and a
        # BIOS-only request has nothing to act on — refuse rather than silently
        # flashing the firmware the user did not ask for.
        [ -n "$fw" ] || die "$chip is flashed through storcli, where the BIOS is part of the firmware package. A BIOS-only flash is not possible on this generation." 5
        echo "+ storcli /c$one download file=$fw"
        "$tool" /c"$one" download file="$fw" || flash_rc=$?
    else
        # SAS2 / SAS3: sasNflash -c <N> -o [-f <fw.bin>] [-b <bios.rom>]
        set -- -c "$one" -o
        [ -n "$fw" ]   && set -- "$@" -f "$fw"
        [ -n "$bios" ] && set -- "$@" -b "$bios"
        echo "+ $(basename "$tool") $*"
        "$tool" "$@" || flash_rc=$?
    fi
    if [ -n "$flash_rc" ]; then
        # A board left with one IOC updated and one not is the new hazard this
        # loop introduces, and it must never be reported as a generic failure:
        # the operator has to know the card is now mismatched and which half
        # succeeded, because rebooting on a half-flashed board is what turns a
        # failed update into a dead card.
        #
        # Its OWN exit code, 7. Sharing 6 with the safe case made "the card is
        # mismatched, do not reboot" and "nothing was written, you are fine"
        # record the same value in flash.status, so the page rendered both as
        # the same generic error and only the free text told them apart. 7 is
        # what flash.php turns into done=partial and the page into its own
        # banner.
        if [ -n "$done_ok" ]; then
            # Re-run the WHOLE CARD, not /c$one alone. The membership gate in
            # flash.php only accepts a card's complete controller list, so a
            # request naming one half is refused -- and rewriting both is the
            # safe action anyway: it puts the same image on every controller of
            # the board, which is the state the failed run was aiming for.
            die "PARTIAL FLASH. Controller(s) /c$done_ok on this card were written successfully and /c$one FAILED. The two controllers on this board are now running different firmware. Do NOT reboot. Re-run the flash for the WHOLE CARD (all of its controllers, the same way you started this one) before doing anything else -- that rewrites both controllers with the same image and is the safe action." 7
        fi
        die "flash of /c$one failed and nothing was written" 6
    fi
    done_ok="${done_ok:+$done_ok,}$one"
done
exit 0
