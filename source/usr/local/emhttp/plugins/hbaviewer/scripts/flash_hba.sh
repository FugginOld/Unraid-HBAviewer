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
#   SAS2xxx      -> sas2flash
#   SAS30xx/31xx -> sas3flash
#   SAS34xx/35xx -> storcli (/cN download)
flasher_for_chip() {
    case "$1" in
        SAS2*)          echo sas2 ;;
        SAS30*|SAS31*)  echo sas3 ;;
        SAS34*|SAS35*)  echo storcli ;;
        *)              return 1 ;;
    esac
}

mode="$1"; chip="$2"; ctl="$3"; fw="$4"; bios="$5"

[ "$mode" = list ] || [ "$mode" = flash ] || die "unknown mode: '$mode'" 2
case "$ctl" in ''|*[!0-9]*) die "controller index must be an integer: '$ctl'" 2 ;; esac

gen=$(flasher_for_chip "$chip") || die "unsupported/unknown chip: '$chip'" 3

# Resolve the tool for this generation (storcli reuses the existing seam).
if [ "$gen" = storcli ]; then tool=$(find_storcli); else tool=$(find_flasher "$gen"); fi
[ -n "$tool" ] || die "flash tool for $chip ($gen) not found — install it or upload it in Settings" 4

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
