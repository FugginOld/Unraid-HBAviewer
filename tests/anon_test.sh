#!/bin/bash
# Self-asserting checks for the diagnostic bundle's anonymisation pass
# (scripts/bundle_support.sh `anon`). This is the one part of plan 026 testable
# without hardware, and the part where a bug is invisible until someone's serial
# is already on a public issue tracker — so these assertions are load-bearing and
# must not be weakened to make a refactor pass.
#
# The properties, in order of how badly their loss hurts:
#   1. ONE map for the whole bundle: the same real value yields the SAME token in
#      every file. Lose this and the PHY-to-drive join (keyed on SAS address),
#      where this project's hardest bugs live, is destroyed.
#   2. Line lengths never change. storcli pads to fixed columns and the parsers
#      key on that structure; a bundle that reflows columns cannot be dropped in
#      as a test fixture.
#   3. No original identifier survives anywhere.
#   4. The map itself is never written into the bundle.
#
#   bash tests/anon_test.sh   ->  "anon: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
BS="../source/usr/local/emhttp/plugins/hbaviewer/scripts/bundle_support.sh"
fail=0
ok()  { echo "PASS  $1"; }
bad() { echo "FAIL  $1 -- $2"; fail=1; }
eq()  { [ "$2" = "$3" ] && ok "$1" || bad "$1" "'$2' != '$3'"; }
ne()  { [ "$2" != "$3" ] && ok "$1" || bad "$1" "both are '$2'"; }
absent() { grep -rqF "$2" "$3" && bad "$1" "'$2' still present" || ok "$1"; }
present() { grep -rqF "$2" "$3" && ok "$1" || bad "$1" "'$2' was lost"; }

# ── A synthetic bundle carrying one drive across four file shapes ────────────
# Same drive, same real values, expressed the way each source really expresses
# them: storcli key=value AND its padded PHY columns, sysfs lower-case with an
# 0x prefix, lsblk -P key="value", and the parser's own JSON.
T=$(mktemp -d); trap 'rm -rf "$T" "$F"' EXIT
mkdir -p "$T/02-raw" "$T/03-sysfs" "$T/04-parsed"

cat > "$T/02-raw/storcli.txt" <<'EOF'
Serial Number = SP52801234
Board Tracer Number = SK52801234ZZ
PCI Address = 00:c1:00:00
ROC temperature(Degree Celsius) = 54
SN =         2TGX1234
Model Number = WUH721414AL4204
Firmware Revision = C240
WWN = 5000CCA26A1B2C8B
SAS Address = 5003005702960060
    0 12.0 Gbps    500605B00A1B2C90 Y    0    0017 5000CCA26A1B2C8B 0    -      0
    1 12.0 Gbps    500605B00A1B2C91 Y    0    001e 5000C500A1B2C3F8 6    -      0
EOF
cat > "$T/03-sysfs/sas_phy.txt" <<'EOF'
===== /sys/class/sas_phy/phy-0:0 =====
sas_address = 0x500605b00a1b2c90
invalid_dword_count = 5
EOF
printf 'NAME="sda" WWN="0x5000cca26a1b2c8b" SERIAL="2TGX1234" MODEL="WUH721414AL4204"\n' \
    > "$T/02-raw/lsblk.txt"
printf 'Linux tower 6.1.79-Unraid #1 SMP x86_64 GNU/Linux\n' > "$T/02-raw/uname.txt"
printf '%s' '{"drives":[{"model":"WUH721414AL4204","serial":"2TGX1234","sas_address":"5000CCA26A1B2C8B","firmware":"C240"}]}' \
    > "$T/04-parsed/drives.json"

before=$(mktemp); cat "$T"/02-raw/* "$T"/03-sysfs/* "$T"/04-parsed/* > "$before"
bash "$BS" anon "$T" tower >/dev/null 2>&1

val() {  # $1 = file, $2 = sed expression yielding the token
    sed -n "$2" "$T/$1" | head -1
}
# The drive's WWN, as each of the four sources spells it.
w_storcli=$(val 02-raw/storcli.txt   's/^WWN = \(.*\)$/\1/p')
w_column=$( val 02-raw/storcli.txt   's/^ *0 .* \([0-9]\{16\}\) 0 .*$/\1/p')
w_lsblk=$(  val 02-raw/lsblk.txt     's/.*WWN="0x\([^"]*\)".*/\1/p')
w_json=$(   val 04-parsed/drives.json 's/.*"sas_address":"\([^"]*\)".*/\1/p')
# A PHY's own address, upper case in storcli and lower case in sysfs.
p_storcli=$(val 02-raw/storcli.txt   's/^ *0 12.0 Gbps *\([0-9A-F]\{16\}\) .*$/\1/p')
p_sysfs=$(  val 03-sysfs/sas_phy.txt 's/^sas_address = 0x\(.*\)$/\1/p')
# The drive serial, from storcli, lsblk and the parsed JSON.
s_storcli=$(val 02-raw/storcli.txt   's/^SN = *\(.*\)$/\1/p')
s_lsblk=$(  val 02-raw/lsblk.txt     's/.*SERIAL="\([^"]*\)".*/\1/p')
s_json=$(   val 04-parsed/drives.json 's/.*"serial":"\([^"]*\)".*/\1/p')
c_serial=$( val 02-raw/storcli.txt   's/^Serial Number = \(.*\)$/\1/p')

# ── 1. One map for the whole bundle ─────────────────────────────────────────
eq "same WWN, raw storcli vs its own PHY column"  "$w_storcli" "$w_column"
eq "same WWN, storcli vs lsblk"                   "$w_storcli" "$w_lsblk"
eq "same WWN, raw file vs parsed JSON"            "$w_storcli" "$w_json"
# The join: sysfs writes sas_address lower case, storcli upper. Keying the map
# case-sensitively gave the SAME phy two different pseudonyms — the exact
# failure that would silently destroy the PHY-to-drive correlation.
eq "same PHY address, upper-case storcli vs lower-case sysfs" "$p_storcli" "$p_sysfs"
eq "same serial, storcli vs lsblk"                "$s_storcli" "$s_lsblk"
eq "same serial, raw file vs parsed JSON"         "$s_storcli" "$s_json"

# ── 2. Different values get different tokens ────────────────────────────────
ne "drive WWN vs its PHY address"        "$w_storcli" "$p_storcli"
ne "drive serial vs controller serial"   "$s_storcli" "$c_serial"
n_addr=$(grep -rhoE '\b5[0-9]{15}\b' "$T"/0* | sort -u | wc -l)
eq "five distinct addresses in, five distinct tokens out" "$n_addr" "5"

# ── 3. Line lengths never change ────────────────────────────────────────────
after=$(mktemp); cat "$T"/02-raw/* "$T"/03-sysfs/* "$T"/04-parsed/* > "$after"
d=$(diff <(awk '{print length}' "$before") <(awk '{print length}' "$after"))
[ -z "$d" ] && ok "line lengths unchanged (synthetic bundle)" \
             || bad "line lengths unchanged (synthetic bundle)" "$d"
rm -f "$before" "$after"

# ── 4. No original survives ─────────────────────────────────────────────────
absent "drive WWN gone"          5000CCA26A1B2C8B "$T"
absent "drive WWN gone (lower)"  5000cca26a1b2c8b "$T"
absent "PHY address gone"        500605B00A1B2C90 "$T"
absent "PHY address gone (lower)" 500605b00a1b2c90 "$T"
absent "second PHY address gone" 500605B00A1B2C91 "$T"
absent "controller SAS address gone" 5003005702960060 "$T"
absent "drive serial gone"       2TGX1234         "$T"
absent "controller serial gone"  SP52801234        "$T"
absent "board tracer number gone" SK52801234ZZ     "$T"
absent "hostname gone from uname -a" " tower "     "$T"
present "kernel version kept"    "6.1.79-Unraid"   "$T"

# A short hostname must still go. The length floor that stops a stray 2-char
# value being swapped out everywhere does NOT apply to the hostname, which is an
# exact literal the caller supplies — and nas/srv-length names are common.
S=$(mktemp -d)
printf 'Linux nas 6.1.79-Unraid #1 SMP\n' > "$S/uname.txt"
bash "$BS" anon "$S" nas >/dev/null 2>&1
absent "short hostname gone" " nas " "$S/uname.txt"
eq "short hostname kept its length" \
   "$(awk '{print length}' "$S/uname.txt")" "30"
rm -rf "$S"

# ── 5. The map is never written into the bundle ─────────────────────────────
[ -z "$(find "$T" -name '*.anon' -o -name '*map*' -o -name '*.anon_counts')" ] \
    && ok "no map or scratch file left in the bundle" \
    || bad "no map or scratch file left in the bundle" "$(find "$T" -name '*.anon*')"
absent "ANONYMISED.txt names no real value" 2TGX1234 "$T/ANONYMISED.txt"
present "ANONYMISED.txt names the classes"  "serial numbers" "$T/ANONYMISED.txt"

# ── 6. What is deliberately KEPT — a bundle hiding these diagnoses nothing ──
present "drive model kept"     WUH721414AL4204 "$T"
present "firmware kept"        C240            "$T"
present "temperature kept"     "Celsius) = 54" "$T"
present "PCI address kept"     "00:c1:00:00"   "$T"
present "error counter kept"   "invalid_dword_count = 5" "$T"

# ── 7. The real gate, on real fixture text ──────────────────────────────────
# tests/fixtures/storcli/ is committed pre-masked (real column padding, X-ed out
# identifiers), so it is run BOTH as committed — which proves the pass leaves
# untouched text byte-identical — and with the masks turned back into plausible
# hex, which is what actually exercises the replacement against real storcli
# column alignment.
F=$(mktemp -d)
mkdir -p "$F/asis" "$F/demasked"
cp fixtures/storcli/*.txt "$F/asis/"
for f in fixtures/storcli/*.txt; do sed 'y/XY/AB/' "$f" > "$F/demasked/${f##*/}"; done
cp -r "$F/asis" "$F/asis.orig"; cp -r "$F/demasked" "$F/demasked.orig"
bash "$BS" anon "$F/asis" >/dev/null 2>&1
bash "$BS" anon "$F/demasked" >/dev/null 2>&1
for v in asis demasked; do
    d=""
    for f in "$F/$v.orig"/*.txt; do
        b=${f##*/}
        d="$d$(diff <(awk '{print length}' "$f") <(awk '{print length}' "$F/$v/$b"))"
    done
    [ -z "$d" ] && ok "line lengths unchanged (real storcli fixtures, $v)" \
                || bad "line lengths unchanged (real storcli fixtures, $v)" "$d"
done
# The committed fixtures are masked but still carry real-SHAPED identifiers: the
# X-ed serials keep their key lines, and enclosures_c0.txt's EnclLogicalID is a
# genuine 16-hex SAS address. Those must be replaced; every other line must come
# back untouched, or the pass is corrupting text it was never asked to change.
# Plan 036's real /c0 show + /c0/sall show all capture (issue #5) left the
# controller's SAS Address and Board Tracer Number unmasked, and its per-drive
# WWN lines unmasked too -- all genuine identifier lines the anonymiser is
# meant to rewrite, so they join the allowlist here rather than in the pass.
absent "committed fixture EnclLogicalID replaced" 300605B010115B90 "$F/asis"
d=$(for f in "$F/asis.orig"/*.txt; do diff "$f" "$F/asis/${f##*/}"; done \
    | grep -c '^[<>].*' 2>/dev/null)
d2=$(for f in "$F/asis.orig"/*.txt; do diff "$f" "$F/asis/${f##*/}"; done \
    | grep '^[<>]' | grep -vcE 'SN =|Serial Number =|EnclLogicalID|SAS Address =|Board Tracer Number =|WWN =')
eq "committed fixtures changed only on identifier lines" "$d2" "0"
[ "$d" -gt 0 ] && ok "committed fixtures did have identifiers to replace ($d lines)" \
               || bad "committed fixtures did have identifiers to replace" "nothing changed"
# ...and the de-masked ones must actually have had their identifiers replaced,
# or the length check above proved nothing.
absent "de-masked SAS address replaced" 5000CCA2AAAAAA8B "$F/demasked"
present "de-masked bundle got tokens"   5000000000000001 "$F/demasked"
present "de-masked model kept"          WUH721414AL4204  "$F/demasked"

# ── 8. A counter is not an identifier ───────────────────────────────────────
# Found on real hardware: host_busy, a live queue-depth counter, came back as
# "host_busy = 01". reg()'s host class waives the 4-char length floor (nas/srv
# are real hostnames) and checks no pattern at all, so a caller literal of "12"
# registered "12" and every "12" in the bundle was rewritten. The literal below
# is that exact shape — anonymising a count is both wrong and, for any class
# whose token is not length-matched, a column-alignment break.
C=$(mktemp -d); Corig=$(mktemp)
cat > "$C/scsi_host.txt" <<'EOF'
===== /sys/class/scsi_host/host0 =====
host_busy = 12
can_queue = 7532
cmd_per_lun = 7
unique_id = 1
ioc_reset_count = 0
host_sas_address = 0x5000000000000137
EOF
cp "$C/scsi_host.txt" "$Corig"
bash "$BS" anon "$C" 12 >/dev/null 2>&1
present "counter left alone (host_busy)"  "host_busy = 12"   "$C/scsi_host.txt"
present "counter left alone (can_queue)"  "can_queue = 7532" "$C/scsi_host.txt"
present "counter left alone (unique_id)"  "unique_id = 1"    "$C/scsi_host.txt"
# ...while the identifier on the very next line still goes.
absent  "sysfs host SAS address still replaced" 5000000000000137 "$C/scsi_host.txt"
d=$(diff <(awk '{print length}' "$Corig") <(awk '{print length}' "$C/scsi_host.txt"))
[ -z "$d" ] && ok "line lengths unchanged (counter fixture)" \
             || bad "line lengths unchanged (counter fixture)" "$d"
rm -rf "$C" "$Corig"

# ── 9. A non-text file is refused, not rewritten ────────────────────────────
# mpt3sas exposes host_trace_buffer, a binary firmware trace ring. Rewriting one
# mangles it AND leaves a blob no text grep can clear — a binary form of a SAS
# address is invisible to every check in this file, so "0 hits" would prove
# nothing. Refuse it, and say which file was refused.
N=$(mktemp -d); Norig=$(mktemp)
printf 'SN = 2TGX1234\nSAS Address = 5000CCA26A1B2C8B\n' > "$N/text.txt"
printf 'host_trace_buffer = \303\050\200\377\000\376ABCD 5000CCA26A1B2C8B\n' > "$N/binary.txt"
cp "$N/binary.txt" "$Norig"
bash "$BS" anon "$N" >/dev/null 2>&1
cmp -s "$Norig" "$N/binary.txt" && ok "binary file left byte-identical" \
                                || bad "binary file left byte-identical" "it was rewritten"
present "the refusal names the file"   "binary.txt" "$N/ANONYMISED.txt"
present "the refusal is stated plainly" "not text"  "$N/ANONYMISED.txt"
# The text file beside it must still be anonymised — one bad file must not stop
# the pass.
absent  "text file beside it still anonymised" 2TGX1234 "$N/text.txt"
rm -rf "$N" "$Norig"

# ── 10. Binary sysfs attributes are never captured in the first place ───────
# The real fix for the above: attr() names a binary attribute instead of
# catting it, so the bundle is honest about the omission rather than carrying
# bytes it cannot vouch for.
A=$(mktemp -d)
printf '12\n' > "$A/host_busy"
printf '0x5000000000000137\n' > "$A/host_sas_address"
printf '\303\050\200\377\000\376trace\n' > "$A/host_trace_buffer"
eq "text attribute captured"     "$(bash "$BS" attr "$A/host_busy")"         "host_busy = 12"
eq "text attribute captured (2)" "$(bash "$BS" attr "$A/host_sas_address")"  "host_sas_address = 0x5000000000000137"
eq "binary attribute named, not captured" \
   "$(bash "$BS" attr "$A/host_trace_buffer")" "host_trace_buffer = <skipped: binary>"
rm -rf "$A"

echo
if [ $fail -eq 0 ]; then echo "anon: all pass"; exit 0; else echo "anon: FAILURES"; exit 1; fi
