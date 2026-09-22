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
    PATH="$STUBDIR:$PATH" EUID=0 bash "$DT" --out "$OUT" "$@" /dev/sdX 2>&1
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

echo
[ $fail -eq 0 ] && { echo "drive_triage: all pass"; exit 0; } || { echo "drive_triage: FAILURES"; exit 1; }
