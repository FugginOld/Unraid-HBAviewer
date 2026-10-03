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
# Unknown power state: an ATA drive answered CHECK POWER MODE with a value
# smartctl does not know. smartctl then ignores -n and reads the drive anyway,
# so every call here is a real access. The line is smartctl's own message
# (ataprint.cpp), synthetic -- no drive on Golem produces it.
if [ "${STUB_POWER_UNKNOWN:-}" = "1" ]; then
    echo "WOKE smartctl $*" >> "$STUB_ARGS"
    echo "CHECK POWER MODE returned unknown value 0x17, ignoring -n option"
    cat "$STUB_SMART"
    exit 0
fi
# Nth-read mode: full reads (-x) numbered STUB_X_DECLINE_FROM and later
# decline. With one disk, read 1 is the sweep's, 2 is triage_disk's
# before-snap, 3 its after-snap. The probe (-i) keeps answering awake.
if [ -n "${STUB_X_DECLINE_FROM:-}" ]; then
    case " $* " in
        *" -x "*)
            n=$(( $(cat "$STUB_X_COUNTER" 2>/dev/null || echo 0) + 1 ))
            echo "$n" > "$STUB_X_COUNTER"
            if [ "$n" -ge "$STUB_X_DECLINE_FROM" ]; then
                echo "smartctl $*" >> "$STUB_ARGS"
                cat "$STUB_SMART_ASLEEP"
                exit 2
            fi
            ;;
    esac
fi
# Race mode: the standby probe (-i) answers awake, but the full read (-x) that
# follows declines -- the drive spun down between the two calls.
if [ "${STUB_X_ASLEEP:-}" = "1" ]; then
    case " $* " in
        *" -x "*)
            echo "smartctl $*" >> "$STUB_ARGS"
            cat "$STUB_SMART_ASLEEP"
            exit 2
            ;;
    esac
fi
# Counter mode: the standby PROBE ( -n standby -i ... , tri_asleep's own call)
# answers differently by call number, to race the sweep's probe against
# triage_disk()'s re-check. Keyed on " -i " specifically -- snap()'s "-x"
# call, the initial "-x" collection call, and the self-test "-t"/"-l" calls
# must never be counted or answered by this branch.
if [ -n "${STUB_PROBE_COUNTER:-}" ]; then
    case " $* " in
        *" -i "*)
            n=$(( $(cat "$STUB_PROBE_COUNTER" 2>/dev/null || echo 0) + 1 ))
            echo "$n" > "$STUB_PROBE_COUNTER"
            asleep=0
            [ -n "${STUB_PROBE_ASLEEP_MAX:-}" ] && [ "$n" -le "$STUB_PROBE_ASLEEP_MAX" ] && asleep=1
            [ -n "${STUB_PROBE_AWAKE_MAX:-}" ]  && [ "$n" -gt "$STUB_PROBE_AWAKE_MAX" ]  && asleep=1
            echo "smartctl $*" >> "$STUB_ARGS"
            if [ "$asleep" = "1" ]; then
                cat "$STUB_SMART_ASLEEP"
                exit 2
            else
                cat "$STUB_SMART"
                exit 0
            fi
            ;;
    esac
fi
if [ "${STUB_ASLEEP:-}" = "1" ]; then
    case " $* " in
        *" -n standby "*)
            echo "smartctl $*" >> "$STUB_ARGS"
            cat "$STUB_SMART_ASLEEP"
            exit 2
            ;;
        *)
            echo "WOKE smartctl $*" >> "$STUB_ARGS"
            cat "$STUB_SMART"
            exit 0
            ;;
    esac
fi
echo "smartctl $*" >> "$STUB_ARGS"
cat "$STUB_SMART"
STUB
cat > "$STUBDIR/sg_verify" <<'STUB'
#!/bin/bash
if [ "${STUB_ASLEEP:-}" = "1" ]; then
    echo "WOKE sg_verify $*" >> "$STUB_ARGS"
else
    echo "sg_verify $*" >> "$STUB_ARGS"
fi
exit "${STUB_VERIFY_RC:-0}"
STUB
cat > "$STUBDIR/sg_read" <<'STUB'
#!/bin/bash
if [ "${STUB_ASLEEP:-}" = "1" ]; then
    echo "WOKE sg_read $*" >> "$STUB_ARGS"
else
    echo "sg_read $*" >> "$STUB_ARGS"
fi
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
export STUB_SMART_ASLEEP="$PWD/fixtures/smart/sas_standby.txt"
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

# ── --state: the baseline survives across DIFFERENT --out dirs. ────────────
STATEFILE="$WORK/state.tsv"; rm -f "$STATEFILE"
out1=$(PATH="$STUBDIR:$PATH" TRIAGE_SKIP_ROOT_CHECK=1 TRIAGE_SKIP_DEV_CHECK=1 bash "$DT" --out "$WORK/run1" --state "$STATEFILE" --no-triage /dev/sdX 2>&1)
has "first run with --state has no baseline yet" "$out1" "no baseline yet"
[ -s "$STATEFILE" ] && ok "--state writes the baseline file at the given path" || bad "--state writes the baseline file at the given path" "missing: $STATEFILE"
out2=$(PATH="$STUBDIR:$PATH" TRIAGE_SKIP_ROOT_CHECK=1 TRIAGE_SKIP_DEV_CHECK=1 bash "$DT" --out "$WORK/run2" --state "$STATEFILE" --no-triage /dev/sdX 2>&1)
has "a DIFFERENT --out dir still sees the baseline via --state" "$out2" "baseline present"
# Backward compat: with no --state, baseline still lives under --out exactly as before.
out3=$(PATH="$STUBDIR:$PATH" TRIAGE_SKIP_ROOT_CHECK=1 TRIAGE_SKIP_DEV_CHECK=1 bash "$DT" --out "$WORK/run3" --no-triage /dev/sdX 2>&1)
has "without --state, a fresh --out still has no baseline (today's behavior, unchanged)" "$out3" "no baseline yet"
[ -s "$WORK/run3/baseline.tsv" ] && ok "without --state, baseline.tsv still lands under --out" || bad "without --state, baseline.tsv still lands under --out" "missing"

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
case "$1" in --version) echo "sg_verify version: 1.20 20080910"; exit 0 ;; esac
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

# ── A drive that is actually asleep must be seen as asleep, not zeroed. ────
# `-n standby` exits 2 for a sleeping drive; smartctl the stub, and sg_verify/
# sg_read, all mark a real hardware access with a WOKE line when STUB_ASLEEP=1.
cat > "$STUBDIR/sg_verify" <<'STUB'
#!/bin/bash
if [ "${STUB_ASLEEP:-}" = "1" ]; then
    echo "WOKE sg_verify $*" >> "$STUB_ARGS"
else
    echo "sg_verify $*" >> "$STUB_ARGS"
fi
exit "${STUB_VERIFY_RC:-0}"
STUB
cat > "$STUBDIR/sg_read" <<'STUB'
#!/bin/bash
if [ "${STUB_ASLEEP:-}" = "1" ]; then
    echo "WOKE sg_read $*" >> "$STUB_ARGS"
else
    echo "sg_read $*" >> "$STUB_ARGS"
fi
exit "${STUB_READ_RC:-0}"
STUB
chmod +x "$STUBDIR/sg_verify" "$STUBDIR/sg_read"

# A -- a sleeping drive is seen as asleep.
SF_A="$WORK/state_a.tsv"
printf 'manual\t1700000000\t7\t0\t0\t0\t0\tmanual\n' > "$SF_A"
outA=$(STUB_ASLEEP=1 TRIAGE_SKIP_DEV_CHECK=1 run --state "$SF_A" --no-triage)
RUNDIR_A=$(ls -1d "$WORK/out"/*/ 2>/dev/null | head -1)
has "A: a sleeping drive's slot row ends SLEEPING" "$outA" "SLEEPING"
smartfile_a=$(find "$RUNDIR_A" -iname 'smart-sdX.txt' 2>/dev/null)
[ -z "$smartfile_a" ] && ok "A: no smart-sdX.txt is written for a sleeping drive" \
                       || bad "A: no smart-sdX.txt is written for a sleeping drive" "found: $smartfile_a"
gotUncorrA=$(awk -F'\t' '$1=="manual"{print $3; exit}' "$SF_A")
[ "$gotUncorrA" = "7" ] && ok "A: the state file's manual row keeps uncorr=7" \
                         || bad "A: the state file's manual row keeps uncorr=7" "got '$gotUncorrA'"

# B -- a sleeping, flagged drive is not woken.
echo "kernel: sd 0:0:0:0: [sdX] tag#0 FAILED dev sdX, sector 12345 op 0x0" > "$STUB_DMESG"
outB=$(STUB_ASLEEP=1 TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 run --auto-triage --all)
wokeB=$(grep -cE '^(WOKE|sg_verify|sg_read)' "$ARGS" || true)
[ "${wokeB:-0}" -eq 0 ] && ok "B: a sleeping flagged drive is never woken" \
                         || bad "B: a sleeping flagged drive is never woken" "$wokeB line(s): $(cat "$ARGS")"
has "B: output says the drive was left asleep" "$outB" "left asleep"

# C -- exactly one STANDBY verdict, flagged and unflagged.
: > "$EV"
outC1=$(STUB_ASLEEP=1 TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 run --auto-triage --all --events "$EV")
vcountC1=$(grep -c '"t":"verdict"' "$EV")
[ "$vcountC1" -eq 1 ] && ok "C: exactly one verdict event (flagged+asleep)" \
                       || bad "C: exactly one verdict event (flagged+asleep)" "got $vcountC1"
has "C: that verdict is STANDBY (flagged+asleep)" "$(cat "$EV")" '"v":"STANDBY"'

: > "$EV"
: > "$STUB_DMESG"
outC2=$(STUB_ASLEEP=1 TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 run --auto-triage --all --events "$EV")
vcountC2=$(grep -c '"t":"verdict"' "$EV")
[ "$vcountC2" -eq 1 ] && ok "C: exactly one verdict event (unflagged+asleep)" \
                       || bad "C: exactly one verdict event (unflagged+asleep)" "got $vcountC2"
has "C: that verdict is STANDBY (unflagged+asleep)" "$(cat "$EV")" '"v":"STANDBY"'

# D -- an awake drive is unchanged (regression guard; passes before and after).
echo "kernel: sd 0:0:0:0: [sdX] tag#0 FAILED dev sdX, sector 12345 op 0x0" > "$STUB_DMESG"
: > "$EV"
outD=$(TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 run --auto-triage --all --events "$EV")
has "D: an awake flagged drive still runs sg_verify" "$(cat "$ARGS")" "sg_verify"
has "D: an awake flagged drive still runs sg_read"   "$(cat "$ARGS")" "sg_read"
hasnt "D: an awake drive gets no STANDBY verdict" "$(cat "$EV")" '"v":"STANDBY"'
: > "$STUB_DMESG"

# E -- VERDICT_SENT guard: asleep at the sweep, awake again by the time
# triage_disk() re-checks it. The sweep's own probe is call 1 (declines,
# asleep); triage_disk()'s re-check is call 2 (answers normally, awake), so
# triage runs for real and tri_verdict() sends the actual verdict. Without
# VERDICT_SENT, the end-of-run check would not know a verdict already went
# out and would append a second, false STANDBY on top of it.
echo "kernel: sd 0:0:0:0: [sdX] tag#0 FAILED dev sdX, sector 12345 op 0x0" > "$STUB_DMESG"
: > "$EV"
PC_E="$WORK/probe_e"; rm -f "$PC_E"
outE=$(STUB_PROBE_COUNTER="$PC_E" STUB_PROBE_ASLEEP_MAX=1 \
       TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 \
       run --auto-triage --all --events "$EV")
has "E: the sweep saw it asleep (SLEEPING row)" "$outE" "SLEEPING"
has "E: triage_disk found it awake and actually ran (sg_verify called)" "$(cat "$ARGS")" "sg_verify"
vcountE=$(grep -c '"t":"verdict"' "$EV")
[ "$vcountE" -eq 1 ] && ok "E: exactly one verdict (asleep at sweep, awake by triage)" \
                      || bad "E: exactly one verdict (asleep at sweep, awake by triage)" "got $vcountE: $(cat "$EV")"
hasnt "E: that verdict is not STANDBY -- triage's real verdict must win" "$(cat "$EV")" '"v":"STANDBY"'
: > "$STUB_DMESG"

# F -- STANDBY_OF guard (reverse race): awake at the sweep, asleep by the
# time triage_disk() re-checks it. The sweep's probe is call 1 (answers
# normally, awake, so it is flagged and queued for triage); triage_disk()'s
# re-check is call 2 (declines, asleep), so triage_disk marks STANDBY_OF
# itself -- the sweep never saw this disk asleep. Without that mark, nothing
# records the disk as asleep and the end-of-run check stays silent instead of
# telling the Verdict screen why nothing was tested.
echo "kernel: sd 0:0:0:0: [sdX] tag#0 FAILED dev sdX, sector 12345 op 0x0" > "$STUB_DMESG"
: > "$EV"
PC_F="$WORK/probe_f"; rm -f "$PC_F"
outF=$(STUB_PROBE_COUNTER="$PC_F" STUB_PROBE_AWAKE_MAX=1 \
       TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 \
       run --auto-triage --all --events "$EV")
hasnt "F: the sweep saw it awake (no SLEEPING row)" "$outF" "SLEEPING"
vcountF=$(grep -c '"t":"verdict"' "$EV")
[ "$vcountF" -eq 1 ] && ok "F: exactly one verdict (awake at sweep, asleep by triage)" \
                      || bad "F: exactly one verdict (awake at sweep, asleep by triage)" "got $vcountF: $(cat "$EV")"
has "F: that verdict is STANDBY" "$(cat "$EV")" '"v":"STANDBY"'
wokeF=$(grep -cE '^(WOKE|sg_verify|sg_read)' "$ARGS" || true)
[ "${wokeF:-0}" -eq 0 ] && ok "F: triage_disk's re-check caught it before any wake" \
                         || bad "F: triage_disk's re-check caught it before any wake" "$wokeF line(s): $(cat "$ARGS")"
: > "$STUB_DMESG"

# G -- --no-skip-standby used to remove only the probe; every read still
# passes -n standby and declines, and the decline parsed as all-zero counters
# that overwrote the baseline. The flag is gone: it is ignored, and the drive
# is still seen as asleep with its baseline intact.
SF_G="$WORK/state_g.tsv"
printf 'manual	1700000000	7	0	0	0	0	manual
' > "$SF_G"
outG=$(STUB_ASLEEP=1 TRIAGE_SKIP_DEV_CHECK=1 run --state "$SF_G" --no-triage --no-skip-standby)
has "G: --no-skip-standby still leaves a sleeping drive SLEEPING" "$outG" "SLEEPING"
gotUncorrG=$(awk -F'	' '$1=="manual"{print $3; exit}' "$SF_G")
[ "$gotUncorrG" = "7" ] && ok "G: --no-skip-standby does not zero the baseline (uncorr=7 kept)"                          || bad "G: --no-skip-standby does not zero the baseline (uncorr=7 kept)" "got '$gotUncorrG'"

# H -- the drive falls asleep between the sweep's probe and its full read.
# The probe answers awake; the -x read declines. That declined read must be
# treated as asleep, not parsed as zeros into the baseline.
SF_H="$WORK/state_h.tsv"
printf 'manual	1700000000	7	0	0	0	0	manual
' > "$SF_H"
outH=$(STUB_X_ASLEEP=1 TRIAGE_SKIP_DEV_CHECK=1 run --state "$SF_H" --no-triage)
has "H: a read declined after an awake probe ends SLEEPING" "$outH" "SLEEPING"
gotUncorrH=$(awk -F'	' '$1=="manual"{print $3; exit}' "$SF_H")
[ "$gotUncorrH" = "7" ] && ok "H: a declined read does not zero the baseline (uncorr=7 kept)"                          || bad "H: a declined read does not zero the baseline (uncorr=7 kept)" "got '$gotUncorrH'"
smartfile_h=$(find "$WORK/out" -iname 'smart-sdX.txt' 2>/dev/null)
[ -z "$smartfile_h" ] && ok "H: the declined read leaves no smart-sdX.txt behind"                        || bad "H: the declined read leaves no smart-sdX.txt behind" "found: $smartfile_h"

# I -- a power state smartctl cannot name is not "awake". The probe itself is
# the only access; the drive is not read, not triaged, and the baseline keeps
# its values. A web Diagnose gets one POWER_UNKNOWN verdict saying why.
echo "kernel: sd 0:0:0:0: [sdX] tag#0 FAILED dev sdX, sector 12345 op 0x0" > "$STUB_DMESG"
: > "$EV"
SF_I="$WORK/state_i.tsv"
printf 'manual	1700000000	7	0	0	0	0	manual
' > "$SF_I"
outI=$(STUB_POWER_UNKNOWN=1 TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1        run --state "$SF_I" --auto-triage --all --events "$EV")
has "I: the sweep row says POWER UNKNOWN" "$outI" "POWER UNKNOWN"
hasnt "I: the drive is not read in full (no smartctl -x)" "$(cat "$ARGS")" " -x "
hasnt "I: the drive is not triaged (no sg_verify)" "$(cat "$ARGS")" "sg_verify"
hasnt "I: the drive is not triaged (no sg_read)"   "$(cat "$ARGS")" "sg_read"
gotUncorrI=$(awk -F'	' '$1=="manual"{print $3; exit}' "$SF_I")
[ "$gotUncorrI" = "7" ] && ok "I: the baseline keeps uncorr=7"                          || bad "I: the baseline keeps uncorr=7" "got '$gotUncorrI'"
vcountI=$(grep -c '"t":"verdict"' "$EV")
[ "$vcountI" -eq 1 ] && ok "I: exactly one verdict event"                       || bad "I: exactly one verdict event" "got $vcountI: $(cat "$EV")"
has "I: that verdict is POWER_UNKNOWN" "$(cat "$EV")" '"v":"POWER_UNKNOWN"'
: > "$STUB_DMESG"

# J -- triage_disk's before-snap is a read of its own, after the probe. If
# the drive spins down in between, that read declines: proof it is asleep
# now. sg_verify/sg_read must not run, and the run ends STANDBY.
echo "kernel: sd 0:0:0:0: [sdX] tag#0 FAILED dev sdX, sector 12345 op 0x0" > "$STUB_DMESG"
: > "$EV"
XC_J="$WORK/xcount_j"; rm -f "$XC_J"
outJ=$(STUB_X_DECLINE_FROM=2 STUB_X_COUNTER="$XC_J" TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 \
       run --auto-triage --all --events "$EV")
hasnt "J: the sweep read it awake (no SLEEPING row)" "$outJ" "SLEEPING"
wokeJ=$(grep -cE '^(sg_verify|sg_read)' "$ARGS" || true)
[ "${wokeJ:-0}" -eq 0 ] && ok "J: a declined before-snap stops triage before any VERIFY/READ" \
                         || bad "J: a declined before-snap stops triage before any VERIFY/READ" "$wokeJ line(s)"
vcountJ=$(grep -c '"t":"verdict"' "$EV")
[ "$vcountJ" -eq 1 ] && ok "J: exactly one verdict" || bad "J: exactly one verdict" "got $vcountJ: $(cat "$EV")"
has "J: that verdict is STANDBY" "$(cat "$EV")" '"v":"STANDBY"'
hasnt "J: no counter movement is reported" "$(cat "$EV")" '"t":"counter"'
smartfile_j=$(find "$WORK/out" -iname 'smart-sdX.txt' 2>/dev/null)
[ -z "$smartfile_j" ] && ok "J: the declined before-snap leaves no smart-sdX.txt behind"                        || bad "J: the declined before-snap leaves no smart-sdX.txt behind" "found: $smartfile_j"

# K -- the after-snap declines (asleep again by the end of triage). Its
# zeros must not be compared against the before-snap: that would report the
# drive's lifetime counters as movement during this triage.
: > "$EV"
XC_K="$WORK/xcount_k"; rm -f "$XC_K"
outK=$(STUB_X_DECLINE_FROM=3 STUB_X_COUNTER="$XC_K" TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 \
       run --auto-triage --all --events "$EV")
has "K: triage ran (sg_verify called)" "$(cat "$ARGS")" "sg_verify"
hasnt "K: a declined after-snap reports no counter movement" "$(cat "$EV")" '"t":"counter"'
vcountK=$(grep -c '"t":"verdict"' "$EV")
[ "$vcountK" -eq 1 ] && ok "K: exactly one verdict" || bad "K: exactly one verdict" "got $vcountK: $(cat "$EV")"
has "K: triage's own verdict stands (CLEAN), not STANDBY" "$(cat "$EV")" '"v":"CLEAN"'
hasnt "K: the deltas header is not printed with nothing under it" "$outK" "counter deltas across this triage"
: > "$STUB_DMESG"

# L -- a web Diagnose on a healthy, awake drive the sweep does not flag.
# It used to get no triage and so no verdict, and the Verdict screen said
# "did not classify". The drive the user named is always tested: the LBA 0
# spot-check, and a real verdict.
: > "$STUB_DMESG"
: > "$EV"
outL=$(TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 run --auto-triage --all --events "$EV")
has "L: an unflagged named drive is still triaged (sg_verify called)" "$(cat "$ARGS")" "sg_verify"
vcountL=$(grep -c '"t":"verdict"' "$EV")
[ "$vcountL" -eq 1 ] && ok "L: exactly one verdict" || bad "L: exactly one verdict" "got $vcountL: $(cat "$EV")"
has "L: that verdict is CLEAN" "$(cat "$EV")" '"v":"CLEAN"'
has "L: its why says nothing was on record" "$(cat "$EV")" '"why":"no fault on record'
# The fixture has no self-test pass line, and the self-test never feeds the
# verdict -- so the why must not claim it passed.
hasnt "L: its why claims nothing about the self-test" "$(cat "$EV")" 'self-test clean'
has "L: the report's triage results show it though nothing was flagged" "$outL" "nothing on record, spot-check clean"
# Testing the named disk is not the sweep flagging it: no "flagged" summary,
# and so no Unraid notification about a healthy drive.
has   "L: the summary still says no slots flagged" "$outL" "no slots flagged"
hasnt "L: the summary does not list it as flagged" "$outL" "slot(s) flagged"
# A named drive already left asleep at the sweep is not queued as well: one
# power probe, not a second one from triage_disk()'s re-check.
: > "$EV"
outL3=$(STUB_ASLEEP=1 TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 run --auto-triage --all --events "$EV")
probesL3=$(grep -c ' -i ' "$ARGS")
[ "$probesL3" -eq 1 ] && ok "L: a named drive asleep at the sweep is probed once, not queued" \
                       || bad "L: a named drive asleep at the sweep is probed once, not queued" "got $probesL3 probes"
vcountL3=$(grep -c '"v":"STANDBY"' "$EV")
[ "$vcountL3" -eq 1 ] && ok "L: ...and gets exactly one STANDBY verdict" || bad "L: ...and gets exactly one STANDBY verdict" "got $vcountL3"

# M -- a named disk the sweep skipped (not a block device: a typo'd CLI path)
# is never triaged. With VERIFY failing as it would on a missing node, the
# old queueing produced a false MEDIA verdict.
: > "$EV"
outM=$(STUB_VERIFY_RC=1 TRIAGE_SKIP_SELFTEST_WAIT=1 run --auto-triage --all --events "$EV")
hasnt "M: a disk the sweep skipped is not triaged" "$(cat "$ARGS")" "sg_verify"
hasnt "M: ...and gets no verdict" "$(cat "$EV")" '"t":"verdict"'

# N -- a named disk the sweep DID flag is triaged once, not twice.
echo "kernel: sd 0:0:0:0: [sdX] tag#0 FAILED dev sdX, sector 12345 op 0x0" > "$STUB_DMESG"
: > "$EV"
outN=$(TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 run --auto-triage --all --events "$EV")
tcountN=$(grep -c 'TRIAGE: manual' <<< "$outN")
[ "$tcountN" -eq 1 ] && ok "N: a flagged named disk is triaged once" || bad "N: a flagged named disk is triaged once" "got $tcountN"
vcountN=$(grep -c '"t":"verdict"' "$EV")
[ "$vcountN" -eq 1 ] && ok "N: ...with exactly one verdict" || bad "N: ...with exactly one verdict" "got $vcountN"
hasnt "N: ...whose why is the flagged wording, not 'no fault on record'" "$(cat "$EV")" 'no fault on record'
: > "$STUB_DMESG"
# MAX_TRIAGE=0 is a cap on every triage, the named disk's included.
MUT0="$WORK/maxtriage0.sh"
sed 's/^MAX_TRIAGE="3"/MAX_TRIAGE="0"/' "$DT" > "$MUT0"
grep -q '^MAX_TRIAGE="0"' "$MUT0" || bad "L: MAX_TRIAGE mutant built" "sed did not match"
: > "$ARGS"; : > "$EV"
TRIAGE_SKIP_ROOT_CHECK=1 TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 \
    PATH="$STUBDIR:$PATH" bash "$MUT0" --out "$WORK/m0out" --auto-triage --all --events "$EV" /dev/sdX > "$WORK/m0.log" 2>&1
has "L: the MAX_TRIAGE=0 run finished (it is not passing by crashing)" "$(cat "$WORK/m0.log")" "no slots flagged"
hasnt "L: MAX_TRIAGE=0 leaves the named disk untested" "$(cat "$ARGS")" "sg_verify"

# The CLI without --all keeps TRIAGE_EVIDENCE_ONLY: a clean named disk has no
# evidence to test against and is not triaged, as before.
: > "$EV"
outL4=$(TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 run --auto-triage --events "$EV")
hasnt "L: --auto-triage without --all leaves a clean named disk untested" "$(cat "$ARGS")" "sg_verify"
# Without --auto-triage (CLI sweep only) nothing is triaged, as before.
outL2=$(TRIAGE_SKIP_DEV_CHECK=1 run --no-triage)
hasnt "L: --no-triage still triages nothing" "$(cat "$ARGS")" "sg_verify"

# ── --badrange-state: the Phase 2a bad-range ledger, flag plumbing. ─────────
LEDGER="$WORK/ledger.tsv"
# A fake sysfs holding each disk's VPD page 0x80 (unit serial number): a
# 4-byte header, then the serial, space-padded as real drives pad it. The
# length byte is 0x30 -- printable ('0') -- so a reader that fails to skip the
# header is caught instead of having the header stripped as non-printable.
mkvpd() { mkdir -p "$WORK/sys/block/$1/device"; printf '\000\200\000\060  %s  ' "$2" > "$WORK/sys/block/$1/device/vpd_pg80"; }
SX=SERIALX00001
mkvpd sdX "$SX"
# One flagged triage of sdX with the ledger on. BR_BIN swaps the stub dir (a
# box with no sg3_utils), BR_ENGINE the engine (a mutant) and BR_SYS the fake
# sysfs (a disk with no readable serial) without a second copy of this
# function. TRIAGE_SYSFS is ignored until Task 2 teaches the engine to read it.
brun() {
    : > "$ARGS"
    OUT="$WORK/brout"; rm -rf "$OUT"
    PATH="${BR_BIN:-$STUBDIR}:$PATH" TRIAGE_SKIP_ROOT_CHECK=1 TRIAGE_SKIP_DEV_CHECK=1 \
        TRIAGE_SKIP_SELFTEST_WAIT=1 TRIAGE_SYSFS="${BR_SYS:-$WORK/sys}" bash "${BR_ENGINE:-$DT}" --out "$OUT" \
        --badrange-state "$LEDGER" --auto-triage --all "$@" /dev/sdX 2>&1
}
seed() { echo "kernel: sd 0:0:0:0: [sdX] tag#0 FAILED dev sdX, sector $1 op 0x0" > "$STUB_DMESG"; }

# /boot is flash. Same refusal --out and --state already get, and it must fire
# before anything is created.
out=$(PATH="$STUBDIR:$PATH" bash "$DT" --out "$WORK/bootbr" --badrange-state /boot/x.tsv /dev/sdX 2>&1); rc=$?
[ $rc -eq 2 ] && has "--badrange-state under /boot is refused" "$out" "change --badrange-state" \
              || bad "--badrange-state under /boot is refused" "exit was $rc, not 2"
[ ! -e "$WORK/bootbr" ] && ok "and nothing is created before the refusal" \
                        || bad "and nothing is created before the refusal" "$WORK/bootbr exists"

# One physical-drive-identity reset clears the baseline AND the ledger.
printf 'manual\t10240\t2\tr1\tr2\tmedia\t1\n' > "$LEDGER"
out=$(brun --no-triage --reset-baseline)
[ ! -e "$LEDGER" ] && ok "--reset-baseline also clears the ledger it is given" \
                   || bad "--reset-baseline also clears the ledger it is given" "survived: $(cat "$LEDGER")"
has "the ledger path is named in the report when the flag is given" "$out" "bad-range ledger: $LEDGER"

# Flag absent = today's behavior, byte for byte -- the --state precedent
# (413864f). Same flagged MEDIA scenario both ways; the reports may differ ONLY
# in lines naming the ledger and the four lines carrying a time or the run's
# own path.
norm() { grep -v -E '^(started |results |finished |full report:)|bad-range ledger' "$1"; }
seed 12345
STUB_VERIFY_RC=1 STUB_READ_RC=1 TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 run --auto-triage --all >/dev/null
RA=$(ls -1d "$WORK/out"/*/ | head -1)
cp "${RA}report.txt" "$WORK/report-noflag.txt"
cut -f1,3- "$WORK/out/baseline.tsv" > "$WORK/state-noflag.tsv"
ls -1 "$RA" > "$WORK/files-noflag.txt"
hasnt "flag absent: the report never mentions the ledger" "$(cat "$WORK/report-noflag.txt")" "bad-range ledger"
leak=$(ls -R "$WORK/out" | grep -i badrange || true)
[ -z "$leak" ] && ok "flag absent: no ledger or ledger record is written anywhere" \
               || bad "flag absent: no ledger or ledger record is written anywhere" "$leak"
rm -f "$LEDGER"
STUB_VERIFY_RC=1 STUB_READ_RC=1 brun >/dev/null
RB=$(ls -1d "$WORK/brout"/*/ | head -1)
d=$(diff <(norm "$WORK/report-noflag.txt") <(norm "${RB}report.txt"))
[ -z "$d" ] && ok "the flag changes nothing in the report but its own ledger lines" \
            || bad "the flag changes nothing in the report but its own ledger lines" "$d"
d=$(diff "$WORK/state-noflag.tsv" <(cut -f1,3- "$WORK/brout/baseline.tsv"))
[ -z "$d" ] && ok "the flag changes nothing in the baseline" \
            || bad "the flag changes nothing in the baseline" "$d"
d=$(diff "$WORK/files-noflag.txt" <(ls -1 "$RB" | grep -v '^badranges-run\.tsv$'))
[ -z "$d" ] && ok "the flag adds no run file but its own record" \
            || bad "the flag adds no run file but its own record" "$d"
rm -f "$LEDGER"; : > "$STUB_DMESG"

# ── Per-range class, recorded for the ledger under the drive's serial. ─────
# sector 12345 -> build_ranges pads 2000 below -> range 10345:4000 -> chunk
# 10240 (5 x 2048). The key is sdX's serial from the fake sysfs -- NOT the
# slot ID $STATE uses, which on a /dev/ run is the literal "manual".
rec() { local d; d=$(ls -1d "$WORK/brout"/*/ 2>/dev/null | head -1); cat "${d}badranges-run.tsv" 2>/dev/null; }
# Exact equality, not a substring: a key with junk in front of the serial
# (an unskipped VPD header byte) still CONTAINS the serial.
is() { [ "$2" = "$3" ] && ok "$1" || bad "$1" "want '$3', got '$2'"; }
rm -f "$LEDGER"; seed 12345
STUB_VERIFY_RC=1 STUB_READ_RC=1 brun >/dev/null
is "VERIFY failed is recorded media, keyed by the drive's serial" "$(rec)" "$SX"$'\t10240\tmedia'
hasnt "and never by the slot ID" "$(rec)" "manual"
STUB_READ_RC=1 brun >/dev/null
is "VERIFY clean + READ failed is recorded transport" "$(rec)" "$SX"$'\t10240\ttransport'
brun >/dev/null
is "nothing failing is recorded intermittent" "$(rec)" "$SX"$'\t10240\tintermittent'
seed 12500    # range starts at 10500: exact-match would record 10500
brun >/dev/null
is "the range start rounds DOWN to its 2048-block chunk" "$(rec)" "$SX"$'\t10240\tintermittent'
: > "$STUB_DMESG"
brun >/dev/null
is "no evidence: the 0:256 spot-check is recorded at chunk 0" "$(rec)" "$SX"$'\t0\tintermittent'

# A page carrying NUL/control bytes: the engine's key must drop them, exactly
# as diag_disk_serial() does (tests/diagnose_test.php pins the same body).
seed 12345
mkdir -p "$WORK/syscc/block/sdX/device"
printf '\000\200\000\060  AB\000C\001D  ' > "$WORK/syscc/block/sdX/device/vpd_pg80"
BR_SYS="$WORK/syscc" STUB_VERIFY_RC=1 STUB_READ_RC=1 brun >/dev/null
is "control bytes in the VPD serial are dropped from the key" "$(rec)" $'ABCD\t10240\tmedia'

# No readable serial: evidence that cannot be tied to a drive is not filed
# under one. One line says so; nothing is recorded.
seed 12345
out=$(BR_SYS="$WORK/nosys" STUB_VERIFY_RC=1 STUB_READ_RC=1 brun)
n=$(printf '%s\n' "$out" | grep -c "no serial readable for /dev/sdX")
[ "$n" -eq 1 ] && ok "no serial: the report says so, once" || bad "no serial: the report says so, once" "want 1 line, got $n"
[ -z "$(rec)" ] && ok "no serial: nothing is recorded" || bad "no serial: nothing is recorded" "$(rec)"

# No VERIFY available (HAVE_SG=0) + READ failed = unresolved. Needs a PATH with
# no sg_verify/sg_logs at all; a runner with real sg3_utils cannot force it.
NOSG="$WORK/nosg"; mkdir -p "$NOSG"
for b in smartctl sg_read blockdev dmesg; do cp "$STUBDIR/$b" "$NOSG/"; done
if PATH="$NOSG:$PATH" command -v sg_verify >/dev/null 2>&1 || PATH="$NOSG:$PATH" command -v sg_logs >/dev/null 2>&1; then
    echo "SKIP  unresolved class (real sg3_utils on PATH; HAVE_SG cannot be forced to 0)"
else
    seed 12345
    STUB_READ_RC=1 BR_BIN="$NOSG" brun >/dev/null
    is "no VERIFY available + READ failed is recorded unresolved" "$(rec)" "$SX"$'\t10240\tunresolved'
fi
rm -f "$LEDGER"; : > "$STUB_DMESG"

# ── The ledger: only media confirms. ───────────────────────────────────────
row() { awk -F'\t' -v s="$SX" -v c="$1" '$1==s && $2==c' "$LEDGER" 2>/dev/null; }
col() { row "$1" | cut -f"$2"; }

rm -f "$LEDGER"; seed 12345
STUB_VERIFY_RC=1 STUB_READ_RC=1 brun >/dev/null
[ "$(col 10240 3)" = 1 ] && ok "a media range on a fresh ledger creates a row at confirm_count 1" \
                         || bad "a media range on a fresh ledger creates a row at confirm_count 1" "row: $(row 10240)"
[ "$(col 10240 6)" = media ] && ok "and records last_class media" || bad "and records last_class media" "row: $(row 10240)"
run1=$(col 10240 4)
# Each triage sleeps 1s (TRIAGE_SKIP_SELFTEST_WAIT), so the next run's
# second-resolution STAMP is guaranteed to differ from run1.
seed 12500    # shifted start offset, same 2048-block chunk
STUB_VERIFY_RC=1 STUB_READ_RC=1 brun >/dev/null
[ "$(wc -l < "$LEDGER")" -eq 1 ] && ok "a shifted start inside the same chunk updates the row, not a new one" \
                                 || bad "a shifted start inside the same chunk updates the row, not a new one" "$(cat "$LEDGER")"
[ "$(col 10240 3)" = 2 ] && ok "a second media run makes it 2" || bad "a second media run makes it 2" "row: $(row 10240)"
[ -n "$run1" ] && [ "$(col 10240 4)" = "$run1" ] && ok "first_run_id is kept" || bad "first_run_id is kept" "run1='$run1' row: $(row 10240)"
[ -n "$(col 10240 5)" ] && [ "$(col 10240 5)" != "$run1" ] && ok "last_run_id moves to the new run" \
                        || bad "last_run_id moves to the new run" "row: $(row 10240)"
seed 12345
brun >/dev/null    # both stubs clean: the chunk stopped failing
[ "$(col 10240 3)" = 2 ] && [ "$(col 10240 6)" = intermittent ] \
    && ok "a media row that comes back clean keeps its count and becomes intermittent, not removed" \
    || bad "a media row that comes back clean keeps its count and becomes intermittent, not removed" "row: $(row 10240)"

# THE discriminating case: an implementation that counts every failure
# reaches 2 here.
rm -f "$LEDGER"; seed 12345
STUB_READ_RC=1 brun >/dev/null
STUB_READ_RC=1 brun >/dev/null
[ "$(col 10240 3)" = 0 ] && [ "$(col 10240 6)" = transport ] \
    && ok "two transport runs on one chunk stay at confirm_count 0" \
    || bad "two transport runs on one chunk stay at confirm_count 0" "row: $(row 10240)"

# Mutation check, in-suite like the standby one: an engine whose bump() counts
# every non-intermittent class must be caught by the case above. If the sed
# stops matching, the mutant IS the engine and this would pass vacuously.
MUTL="$WORK/mutant-ledger.sh"
sed 's/function bump(c) { return c == "media" ? 1 : 0 }/function bump(c) { return c != "intermittent" ? 1 : 0 }/' "$DT" > "$MUTL"
if cmp -s "$DT" "$MUTL"; then
    bad "the transport assertion is able to fail" "sed matched nothing -- the mutant is identical to the engine"
else
    rm -f "$LEDGER"; seed 12345
    STUB_READ_RC=1 BR_ENGINE="$MUTL" brun >/dev/null
    STUB_READ_RC=1 BR_ENGINE="$MUTL" brun >/dev/null
    [ "$(col 10240 3)" = 2 ] && ok "the transport assertion is able to fail (mutant counted two transport runs to 2)" \
                             || bad "the transport assertion is able to fail" "mutant left the count at '$(col 10240 3)'"
fi

# No evidence, clean spot-check: nothing written -- not even an empty file.
rm -f "$LEDGER"; : > "$STUB_DMESG"
brun >/dev/null
[ ! -e "$LEDGER" ] && ok "a clean 0:256 spot-check on a disk with no evidence writes no ledger" \
                   || bad "a clean 0:256 spot-check on a disk with no evidence writes no ledger" "$(cat "$LEDGER")"

# A CLI sweep shares one ledger across disks: another disk's row on the SAME
# chunk, carrying a later extra column, passes through byte for byte; a
# malformed line is dropped on rewrite, not fatal.
printf 'not a row\nWDC_OTHER_SERIAL\t10240\t2\tr1\tr2\tmedia\t1\textra\n' > "$LEDGER"
seed 12345
STUB_VERIFY_RC=1 STUB_READ_RC=1 brun >/dev/null
grep -qx $'WDC_OTHER_SERIAL\t10240\t2\tr1\tr2\tmedia\t1\textra' "$LEDGER" \
    && ok "another disk's row on the same chunk passes through byte for byte" \
    || bad "another disk's row on the same chunk passes through byte for byte" "$(cat "$LEDGER")"
[ "$(col 10240 3)" = 1 ] && ok "this disk gets its own row for that chunk" || bad "this disk gets its own row for that chunk" "$(cat "$LEDGER")"
! grep -q '^not a row' "$LEDGER" && ok "a malformed row is dropped on rewrite" || bad "a malformed row is dropped on rewrite" "$(cat "$LEDGER")"

# A ledger path in a directory that does not exist (a CLI typo) must not
# abort the run, and the engine must not create the directory.
LEDGER_KEEP="$LEDGER"; LEDGER="$WORK/nodir/ledger.tsv"; seed 12345
out=$(STUB_VERIFY_RC=1 brun); rc=$?
LEDGER="$LEDGER_KEEP"
[ $rc -eq 0 ] && has "a ledger path in a missing directory does not abort the run" "$out" "finished" \
              || bad "a ledger path in a missing directory does not abort the run" "exit $rc"
has "and the failed write is reported as a warning" "$out" "could not write $WORK/nodir/ledger.tsv -- left unchanged"
[ ! -e "$WORK/nodir" ] && ok "and the engine does not create that directory" \
                       || bad "and the engine does not create that directory" "$WORK/nodir exists"

# No readable serial: a media run on a fresh ledger writes no ledger at all.
rm -f "$LEDGER"; seed 12345
BR_SYS="$WORK/nosys" STUB_VERIFY_RC=1 STUB_READ_RC=1 brun >/dev/null
[ ! -e "$LEDGER" ] && ok "no serial: a media run writes no ledger" \
                   || bad "no serial: a media run writes no ledger" "$(cat "$LEDGER")"

# A CLI sweep (no /dev/ argument): two array disks, both failing on the SAME
# chunk, one ledger file. Each row must be filed under its own drive's serial
# -- never the slot IDs $STATE uses (SLOT_X/SLOT_Y here).
mkvpd sdY SERIALY00002
SWI="$WORK/disks.ini"
printf '[disk1]\nname="disk1"\ndevice="sdX"\nstatus="DISK_OK"\nid="SLOT_X"\n[disk2]\nname="disk2"\ndevice="sdY"\nstatus="DISK_OK"\nid="SLOT_Y"\n' > "$SWI"
printf 'kernel: sd 0:0:0:0: [sdX] tag#0 FAILED dev sdX, sector 12345 op 0x0\nkernel: sd 0:0:1:0: [sdY] tag#0 FAILED dev sdY, sector 12345 op 0x0\n' > "$STUB_DMESG"
rm -f "$LEDGER"; : > "$ARGS"; rm -rf "$WORK/sweep"
STUB_VERIFY_RC=1 STUB_READ_RC=1 PATH="$STUBDIR:$PATH" TRIAGE_SKIP_ROOT_CHECK=1 TRIAGE_SKIP_DEV_CHECK=1 \
    TRIAGE_SKIP_SELFTEST_WAIT=1 TRIAGE_SYSFS="$WORK/sys" TRIAGE_DISKS_INI="$SWI" \
    bash "$DT" --out "$WORK/sweep" --badrange-state "$LEDGER" --auto-triage --all >/dev/null 2>&1
grep -q "^$SX"$'\t10240\t1\t' "$LEDGER" && grep -q $'^SERIALY00002\t10240\t1\t' "$LEDGER" \
    && [ "$(wc -l < "$LEDGER")" -eq 2 ] \
    && ok "a CLI sweep files each disk under its own serial" \
    || bad "a CLI sweep files each disk under its own serial" "$(cat "$LEDGER" 2>&1)"
[ -s "$LEDGER" ] && ! grep -q '^SLOT_' "$LEDGER" 2>/dev/null && ok "and never under a slot ID" || bad "and never under a slot ID" "$(cat "$LEDGER")"

# A failed ledger write must leave an existing ledger byte-identical. An awk
# that emits a partial row and exits 1 (a full /tmp) stands in for the failure;
# it is scoped to the ledger merge (the only awk invoked with recs=).
BADAWK="$WORK/badawk"; mkdir -p "$BADAWK"
cat > "$BADAWK/awk" <<'STUB'
#!/bin/bash
case "$*" in *recs=*) "$REAL_AWK" "$@" | head -c 10; exit 1 ;; esac
exec "$REAL_AWK" "$@"
STUB
chmod +x "$BADAWK/awk"
rm -f "$LEDGER"; seed 12345
STUB_VERIFY_RC=1 STUB_READ_RC=1 brun >/dev/null
before=$(cat "$LEDGER")
seed 20000
out=$(REAL_AWK="$(command -v awk)" STUB_VERIFY_RC=1 STUB_READ_RC=1 BR_BIN="$BADAWK:$STUBDIR" brun)
[ -n "$before" ] && [ "$(cat "$LEDGER")" = "$before" ] && [ ! -e "$LEDGER.new" ] && has "a failed write leaves the existing ledger byte-identical, with a warning" "$out" "left unchanged" \
    || bad "a failed write leaves the existing ledger byte-identical, with a warning" "before: $before / after: $(cat "$LEDGER")"

# Per-run fold to the worst class: this run's engine record is transport (VERIFY
# clean, READ fails) and is written LAST; a sg_read stub files a media record for
# the same serial+chunk first. Last-wins would leave count 0 / transport.
FOLD="$WORK/foldbin"; mkdir -p "$FOLD"; cp "$STUBDIR"/* "$FOLD/"
cat > "$FOLD/sg_read" <<'STUB'
#!/bin/bash
for d in "$WORK"/brout/*/; do printf '%s\t10240\tmedia\n' "$SX" >> "${d}badranges-run.tsv"; done
exit 1
STUB
rm -f "$LEDGER"; seed 12345
WORK="$WORK" SX="$SX" BR_BIN="$FOLD" brun >/dev/null
[ "$(col 10240 3)" = 1 ] && [ "$(col 10240 6)" = media ] && [ "$(wc -l < "$LEDGER")" -eq 1 ] \
    && ok "one run's media and transport records for a chunk fold to media, counted once" \
    || bad "one run's media and transport records for a chunk fold to media, counted once" "row: $(row 10240)"
rm -f "$LEDGER"; : > "$STUB_DMESG"

# O -- uncorrected WRITES: SCSI error counter log page 0x02, the write: row.
# Unraid disables a disk when a write fails, so the drive's lifetime count is
# the Verdict screen's most direct answer to "why was it disabled". A derived
# copy of the SAS capture, not an edited fixture: only the write: row's last
# field differs from read:'s, so reading the wrong row reports 0, not 5.
SMART_W="$WORK/sas_uncorr_write.txt"
sed '/^write:/ s/[0-9][0-9]*$/5/' "$PWD/fixtures/smart/sas_drive.txt" > "$SMART_W"
grep -q '^write:.* 5$' "$SMART_W" || bad "O: write-error capture built" "sed did not match"
: > "$EV"
STUB_SMART="$SMART_W" TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 \
    run --auto-triage --all --events "$EV" > /dev/null
has "O: the write: row's uncorrected count is a counter event" "$(cat "$EV")" '"key":"wuncorr","before":5,"after":5'
has "O: the read: row is still uncorr, unchanged" "$(cat "$EV")" '"key":"uncorr","before":0,"after":0'
# SATA has no error counter log: the key is absent, never a false zero.
: > "$EV"
STUB_SMART="$PWD/fixtures/smart/sata_drive.txt" TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 \
    run --auto-triage --all --events "$EV" > /dev/null
has   "O: a SATA triage still reports counters (it ran)" "$(cat "$EV")" '"t":"counter"'
hasnt "O: ...but no uncorrected-write counter" "$(cat "$EV")" '"key":"wuncorr"'

echo
[ $fail -eq 0 ] && { echo "drive_triage: all pass"; exit 0; } || { echo "drive_triage: FAILURES"; exit 1; }
