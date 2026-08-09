#!/bin/bash
# Self-asserting checks for flash_hba.sh: per-generation command composition and
# the refusal guards. A stub flasher (stub/flasher) echoes its args so we assert
# the EXACT command without a real tool. No hardware, no flashing.
#   bash tests/flash_test.sh   ->  "flash: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
FH="../source/usr/local/emhttp/plugins/hbaviewer/scripts/flash_hba.sh"
STUB="$PWD/stub/flasher"; chmod +x "$STUB" 2>/dev/null
FW=$(mktemp); BIOS=$(mktemp); trap 'rm -f "$FW" "$BIOS"' EXIT
fail=0
ok()  { echo "PASS  $1"; }
bad() { echo "FAIL  $1 -- $2"; fail=1; }
has()  { case "$out" in *"$2"*) ok "$1" ;; *) bad "$1" "want '$2' in: $out" ;; esac; }
code() { [ "$2" = "$3" ] && ok "$1" || bad "$1" "want exit $2 got $3"; }

# ── list mode: read-only, scoped to the referenced controller only ───────────
out=$(FLASHER="$STUB" bash "$FH" list SAS2008 0 2>&1); has "list sas2 scoped" "FLASHER -c 0 -list"
out=$(FLASHER="$STUB" bash "$FH" list SAS3008 1 2>&1); has "list sas3 scoped" "FLASHER -c 1 -list"
out=$(STORCLI="$STUB" bash "$FH" list SAS3416 1 2>&1); has "list storcli scoped" "FLASHER /c1 show"

# ── flash mode: exact per-generation command ─────────────────────────────────
out=$(FLASHER="$STUB" bash "$FH" flash SAS2008 0 "$FW" 2>&1)
has "flash sas2 cmd log" "+ flasher -c 0 -o -f $FW"
has "flash sas2 exec"    "FLASHER -c 0 -o -f $FW"
out=$(FLASHER="$STUB" bash "$FH" flash SAS3008 1 "$FW" "$BIOS" 2>&1)
has "flash sas3 ctl 1"     "-c 1 -o -f $FW"
has "flash sas3 with bios" "-b $BIOS"
out=$(STORCLI="$STUB" bash "$FH" flash SAS3416 0 "$FW" 2>&1)
has "flash storcli download" "/c0 download file=$FW"

# ── refusals (the guards that keep a bad call from ever running a tool) ───────
out=$(FLASHER="$STUB" bash "$FH" flash SAS9999 0 "$FW" 2>&1); rc=$?
code "unknown chip exit 3" 3 "$rc"; has "unknown chip msg" "unknown chip"

# ── every chip the plugin can DETECT must reach a flasher, or say why not ─────
# The mapping shipped with globs for SAS2*, SAS30*|SAS31* and SAS34*|SAS35*,
# which silently left SAS32xx (9305-16i/-24i), SAS36xx (9405W) and SAS38xx
# (9500) matching nothing — the user saw "unsupported/unknown chip" on cards
# that flash fine. The two chips the tests above happen to use both matched, so
# nothing caught it. This walks the actual chip list out of the firmware index
# instead of a list somebody remembers to update.
#
# Two accepted outcomes per chip, and they are different answers: a family on
# stdout, or exit 2 for a RAID-on-Chip part that no tool can flash. Exit 1 --
# "this script has never heard of your card" -- is the bug.
IDX="../source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json"
FN=$(sed -n '/^flasher_for_chip()/,/^}/p' "$FH")
if [ -r "$IDX" ] && [ -n "$FN" ]; then
    # grep the chips out rather than parse JSON: no jq on Unraid, and the two
    # shapes we need ("chip": "X" under boards, and the no_it_firmware keys) are
    # both plain quoted SASnnnn tokens.
    chips=$({ grep -oE '"chip"[[:space:]]*:[[:space:]]*"SAS[0-9]+"' "$IDX" | grep -oE 'SAS[0-9]+'
              sed -n '/"no_it_firmware"/,/^  }/p' "$IDX" | grep -oE '"SAS[0-9]+"' | tr -d '"'; } | sort -u)
    [ -n "$chips" ] || bad "index chip list" "found no chips in $IDX"
    unmapped=""
    for c in $chips; do
        bash -c "$FN"$'\n''flasher_for_chip "$1" >/dev/null' _ "$c"; rc=$?
        [ "$rc" -eq 0 ] || [ "$rc" -eq 2 ] || unmapped="$unmapped $c"
    done
    [ -z "$unmapped" ] && ok "every indexed chip maps to a flasher or a refusal" \
                       || bad "unmapped chips" "no flasher family and no RoC refusal for:$unmapped"
else
    bad "index reachable" "cannot read $IDX or extract flasher_for_chip"
fi

# The 9305 family specifically -- the regression that started this, and the one
# board family in the index with a real card behind it in a bug report.
out=$(FLASHER="$STUB" bash "$FH" list SAS3224 0 2>&1); has "9305-24i lists via sas3flash" "FLASHER -c 0 -list"
out=$(FLASHER="$STUB" bash "$FH" list SAS3216 0 2>&1); has "9305-16i lists via sas3flash" "FLASHER -c 0 -list"
out=$(STORCLI="$STUB" bash "$FH" list SAS3616 0 2>&1); has "9405W lists via storcli"      "FLASHER /c0 show"
out=$(STORCLI="$STUB" bash "$FH" list SAS3816 0 2>&1); has "9500-16i lists via storcli"   "FLASHER /c0 show"

# RAID-on-Chip is refused by name, not routed at a tool that cannot help.
out=$(FLASHER="$STUB" bash "$FH" list SAS3108 0 2>&1); rc=$?
code "RoC exit 3" 3 "$rc"; has "RoC msg names the reason" "RAID-on-Chip"
out=$(STORCLI="$STUB" bash "$FH" list SAS3516 0 2>&1); rc=$?
code "RoC 9460 exit 3" 3 "$rc"; has "RoC 9460 refused" "cannot be crossflashed"
out=$(env -u FLASHER -u STORCLI bash "$FH" list SAS2008 0 2>&1); rc=$?
code "missing tool exit 4" 4 "$rc"
out=$(FLASHER="$STUB" bash "$FH" flash SAS2008 x "$FW" 2>&1); rc=$?
code "bad ctl exit 2" 2 "$rc"
out=$(FLASHER="$STUB" bash "$FH" flash SAS2008 0 /no/such/fw.bin 2>&1); rc=$?
code "missing fw exit 5" 5 "$rc"
out=$(FLASHER="$STUB" bash "$FH" bogus SAS2008 0 2>&1); rc=$?
code "bad mode exit 2" 2 "$rc"

echo
[ $fail -eq 0 ] && { echo "flash: all pass"; exit 0; } || { echo "flash: FAILURES"; exit 1; }
