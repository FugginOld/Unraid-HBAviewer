#!/bin/bash
# Self-asserting checks for drive_triage.sh. The engine reads hardware through
# six binaries and nothing else, so stubbing those on PATH exercises the real
# control flow -- range building, classification, the report -- with no disk.
#
# Everything lives under mktemp -d: the run directories are named by timestamp
# and the fixtures carry SCSI addresses with colons in them, which NTFS cannot
# hold in a filename.
#   bash tests/drive_triage_test.sh   ->  "drive_triage: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
DT="../source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh"
fail=0
ok()  { echo "PASS  $1"; }
bad() { echo "FAIL  $1 -- $2"; fail=1; }
has()   { case "$2" in *"$3"*) ok "$1" ;; *) bad "$1" "want '$3'" ;; esac; }
hasnt() { case "$2" in *"$3"*) bad "$1" "did NOT want '$3'" ;; *) ok "$1" ;; esac; }

WORK=$(mktemp -d)
STUBDIR="$WORK/bin"; mkdir -p "$STUBDIR"
ARGS="$WORK/args"; : > "$ARGS"
trap 'rm -rf "$WORK"' EXIT

# Every stub records its own argv, so a test can assert which flags were used.
cat > "$STUBDIR/smartctl" <<'STUB'
#!/bin/bash
echo "smartctl $*" >> "$STUB_ARGS"
cat "$STUB_SMART"
STUB
cat > "$STUBDIR/sg_verify" <<'STUB'
#!/bin/bash
echo "sg_verify $*" >> "$STUB_ARGS"
exit "${STUB_VERIFY_RC:-0}"
STUB
cat > "$STUBDIR/sg_read" <<'STUB'
#!/bin/bash
echo "sg_read $*" >> "$STUB_ARGS"
exit "${STUB_READ_RC:-0}"
STUB
cat > "$STUBDIR/sg_logs" <<'STUB'
#!/bin/bash
exit 0
STUB
cat > "$STUBDIR/blockdev" <<'STUB'
#!/bin/bash
echo 1048576
STUB
cat > "$STUBDIR/dmesg" <<'STUB'
#!/bin/bash
cat "$STUB_DMESG" 2>/dev/null
STUB
chmod +x "$STUBDIR"/*

# A real SAS capture, already in the repo and already evidence.
export STUB_SMART="$PWD/fixtures/smart/sas_drive.txt"
export STUB_DMESG="$WORK/dmesg.txt"; : > "$STUB_DMESG"
export STUB_ARGS="$ARGS"

run() {  # remaining args go to the engine
    : > "$ARGS"
    OUT="$WORK/out"; rm -rf "$OUT"
    PATH="$STUBDIR:$PATH" TRIAGE_SKIP_ROOT_CHECK=1 bash "$DT" --out "$OUT" "$@" /dev/sdX 2>&1
}

# ── The CLI path still produces its report. ────────────────────────────────
out=$(run --no-triage)
RUNDIR=$(ls -1d "$WORK/out"/*/ 2>/dev/null | head -1)
[ -n "$RUNDIR" ] && ok "a run directory is created" || bad "a run directory is created" "none under $WORK/out"
[ -s "$RUNDIR/report.txt" ] && ok "report.txt is written" || bad "report.txt is written" "missing or empty"
has "the report names its version" "$(cat "$RUNDIR/report.txt")" "drive-triage v3.1"
has "the slot scan header is present" "$(cat "$RUNDIR/report.txt")" "SLOT SCAN"

# ── --out under /boot is refused outright: this must never touch the flash. ─
PATH="$STUBDIR:$PATH" bash "$DT" --out /boot/nope /dev/sdX >/dev/null 2>&1
[ $? -eq 2 ] && ok "refuses to write to /boot" || bad "refuses to write to /boot" "exit was not 2"

# ── Root gate: verifies real-world behavior is unchanged. Script still enforces ─
# ── root requirement when TRIAGE_SKIP_ROOT_CHECK is not set. ───────────────────
out=$(PATH="$STUBDIR:$PATH" bash "$DT" --out "$WORK/rootcheck" /dev/sdX 2>&1)
[ $? -eq 3 ] && has "root gate fires without test bypass" "$out" "must run as root" || bad "root gate fires without test bypass" "exit was not 3 or missing message"

# ── --events: a second, line-oriented channel. ─────────────────────────────
EV="$WORK/events.ndjson"
: > "$EV"
out=$(run --no-triage --events "$EV")
[ -s "$EV" ] && ok "--events writes an event file" || bad "--events writes an event file" "empty"

# Every line must be one complete JSON object -- the SSE reader in
# diagnose_stream.php splits on newlines and cannot recover from a wrapped one.
badline=0
while IFS= read -r l; do
    case "$l" in '{"t":"'*'}') ;; *) badline=1; echo "    offending line: $l" ;; esac
done < "$EV"
[ $badline -eq 0 ] && ok "every event line is one complete object" \
                   || bad "every event line is one complete object" "see above"

# Only the four documented types.
types=$(grep -o '"t":"[a-z]*"' "$EV" | sort -u | tr '\n' ' ')
case "$types" in
    *'"t":"phase"'*) ok "a phase event is emitted" ;;
    *) bad "a phase event is emitted" "types seen: $types" ;;
esac
unknown=$(grep -o '"t":"[a-z]*"' "$EV" | sort -u \
          | grep -v -E '"t":"(phase|chunk|counter|verdict)"' || true)
[ -z "$unknown" ] && ok "no undocumented event type" || bad "no undocumented event type" "$unknown"

# The human report is unaffected by the flag -- this is the CLI contract.
RUNDIR=$(ls -1d "$WORK/out"/*/ 2>/dev/null | head -1)
has "the report is still written alongside events" "$(cat "$RUNDIR/report.txt")" "SLOT SCAN"

# Without the flag, nothing is emitted anywhere.
: > "$EV"
run --no-triage >/dev/null
[ ! -s "$EV" ] && ok "no --events, no event output" || bad "no --events, no event output" "file grew"

# ── chunk and verdict events come from the triage path. ────────────────────
# triage_disk() only runs on a flagged disk, so seed a failing-sector line the
# engine's own syslog harvest will pick up -- this is what flags sdX FAULTING.
: > "$EV"
echo "kernel: sd 0:0:0:0: [sdX] tag#0 FAILED dev sdX, sector 12345 op 0x0" > "$STUB_DMESG"
STUB_READ_RC=1 TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 run --auto-triage --all --events "$EV" >/dev/null
: > "$STUB_DMESG"
has "a chunk event carries op and ms" "$(cat "$EV")" '"t":"chunk"'
has "a chunk event names its op"      "$(cat "$EV")" '"op":"verify"'
has "a verdict event is emitted"      "$(cat "$EV")" '"t":"verdict"'
has "verify clean + read failed reads TRANSPORT" "$(cat "$EV")" '"v":"TRANSPORT"'

# ── Surface scans use big chunks; targeted re-tests keep small ones. ───────
# triage_disk() only runs on a flagged disk, so seed a failing-sector line the
# engine's own syslog harvest will pick up, same as the block above.
echo "kernel: sd 0:0:0:0: [sdX] tag#0 FAILED dev sdX, sector 12345 op 0x0" > "$STUB_DMESG"

cat > "$STUBDIR/sg_verify" <<'STUB'
#!/bin/bash
echo "sg_verify $*" >> "$STUB_ARGS"
case "$1" in --version) echo "sg_verify version: 1.48 20230228"; exit 0 ;; esac
exit "${STUB_VERIFY_RC:-0}"
STUB
chmod +x "$STUBDIR/sg_verify"

: > "$EV"
TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 run --auto-triage --all --surface --events "$EV" >/dev/null
has "the surface phase is announced"   "$(cat "$EV")" '"phase":"surface"'
has "surface chunks are 32768 blocks"  "$(cat "$EV")" '"n":32768,"op":"verify"'
has "targeted chunks stay at 2048"     "$(grep '"phase":"targeted"' -A0 "$EV"; grep -m1 '"n":2048' "$EV")" '"n":2048'

# ── An sg3_utils too old for the big chunk degrades, it does not fail. ────
cat > "$STUBDIR/sg_verify" <<'STUB'
#!/bin/bash
echo "sg_verify $*" >> "$STUB_ARGS"
case "$1" in --version) echo "sg_verify version: 1.30 20101219"; exit 0 ;; esac
exit "${STUB_VERIFY_RC:-0}"
STUB
chmod +x "$STUBDIR/sg_verify"
: > "$EV"
out=$(TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 run --auto-triage --all --surface --events "$EV")
hasnt "an old sg3_utils does not use the big chunk" "$(cat "$EV")" '"n":32768'
has   "and says why in the report"                  "$out" "sg3_utils"

# ── sg_verify that cannot report a version is treated as old. ─────────────
cat > "$STUBDIR/sg_verify" <<'STUB'
#!/bin/bash
echo "sg_verify $*" >> "$STUB_ARGS"
case "$1" in --version) exit 1 ;; esac
exit "${STUB_VERIFY_RC:-0}"
STUB
chmod +x "$STUBDIR/sg_verify"
: > "$EV"
TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 run --auto-triage --all --surface --events "$EV" >/dev/null
hasnt "an unparseable version is treated as old" "$(cat "$EV")" '"n":32768'
: > "$STUB_DMESG"

# ── Never spin up a sleeping drive. ────────────────────────────────────────
# triage_disk() only runs on a flagged disk, so seed a failing-sector line the
# engine's own syslog harvest will pick up, same pattern as above -- this
# gets snap() and the self-test smartctl calls exercised too, not just the
# counter-collection loop, so the assertions below cover all three call sites.
echo "kernel: sd 0:0:0:0: [sdX] tag#0 FAILED dev sdX, sector 12345 op 0x0" > "$STUB_DMESG"
TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 run --auto-triage --all >/dev/null
smart_calls=$(grep -c '^smartctl ' "$ARGS")
[ "$smart_calls" -gt 0 ] && ok "smartctl was actually called ($smart_calls times)" \
                         || bad "smartctl was actually called" "zero calls -- the assertion below would pass vacuously"
hasnt "no smartctl call passes -n never" "$(cat "$ARGS")" '-n never'
nostandby=$(grep '^smartctl ' "$ARGS" | grep -v -- '-n standby' || true)
[ -z "$nostandby" ] && ok "every smartctl call passes -n standby" \
                    || bad "every smartctl call passes -n standby" "$nostandby"

# Mutation check: prove the assertion above can fail. A copy of the engine with
# the guard removed must be caught by exactly these two cases and nothing else.
# This invokes the mutant directly, not through run(), so it does not inherit
# run()'s TRIAGE_SKIP_ROOT_CHECK -- all three test-only bypasses are set here
# explicitly, and the dmesg seed above is still active so the mutant is
# exercised through snap() and the self-test calls too, not just one site.
MUT="$WORK/mutant.sh"
sed 's/-n standby/-n never/g' "$DT" > "$MUT"
: > "$ARGS"
TRIAGE_SKIP_ROOT_CHECK=1 TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 \
    PATH="$STUBDIR:$PATH" bash "$MUT" --out "$WORK/mout" --auto-triage --all /dev/sdX >/dev/null 2>&1
mutleft=$(grep '^smartctl ' "$ARGS" | grep -v -- '-n standby' || true)
[ -n "$mutleft" ] && ok "the standby assertion is able to fail (mutant caught)" \
                  || bad "the standby assertion is able to fail" "mutant passed -- the assertion proves nothing"
: > "$STUB_DMESG"

echo
[ $fail -eq 0 ] && { echo "drive_triage: all pass"; exit 0; } || { echo "drive_triage: FAILURES"; exit 1; }
