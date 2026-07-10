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

# ── list mode: read-only, resolves the right tool + listing flag ─────────────
out=$(FLASHER="$STUB" bash "$FH" list SAS2008 0 2>&1); has "list sas2 -listall" "FLASHER -listall"
out=$(FLASHER="$STUB" bash "$FH" list SAS3008 0 2>&1); has "list sas3 -listall" "FLASHER -listall"
out=$(STORCLI="$STUB" bash "$FH" list SAS3416 0 2>&1); has "list storcli show"  "FLASHER show"

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
