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
case "$ctl" in ''|*[!0-9]*) die "controller index must be an integer: '$ctl'" 2 ;; esac

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
        sas2|sas3) die "${gen}flash is required to flash $chip, and it is not installed. Broadcom does not permit bundling it. Put it in /boot/config/plugins/hbaviewer/tools/${gen}flash (or upload it under Step 2), and make sure it is executable. Cards on the 9400/9500 generation use storcli instead and need nothing extra." 4 ;;
        storcli)   die "storcli is required to flash $chip, and it is not installed. Install the dkaser/unraid-storcli plugin from Community Applications." 4 ;;
        *)         die "no flash tool is known for $chip ($gen)" 4 ;;
    esac
fi

if [ "$mode" = list ]; then
    # Scope to THE referenced controller (not -listall / show-all) so the operator
    # verifies the exact card /c$ctl that the flash command will write to — a
    # multi-HBA box must not confuse which physical card maps to this index.
    if [ "$gen" = storcli ]; then "$tool" /c"$ctl" show; else "$tool" -c "$ctl" -list; fi
    exit $?
fi

# ── flash ────────────────────────────────────────────────────────────────────
[ -n "$fw" ] || die "no firmware image given" 5
[ -f "$fw" ] || die "firmware image not found: $fw" 5
[ -z "$bios" ] || [ -f "$bios" ] || die "BIOS image not found: $bios" 5

if [ "$gen" = storcli ]; then
    # SAS3.5 / 9400: firmware package flashed via storcli. BIOS travels inside
    # the package, so a separate BIOS file is not applicable here.
    echo "+ storcli /c$ctl download file=$fw"
    "$tool" /c"$ctl" download file="$fw"
else
    # SAS2 / SAS3: sasNflash -c <N> -o -f <fw.bin> [-b <bios.rom>]
    set -- -c "$ctl" -o -f "$fw"
    [ -n "$bios" ] && set -- "$@" -b "$bios"
    echo "+ $(basename "$tool") $*"
    "$tool" "$@"
fi
exit $?
