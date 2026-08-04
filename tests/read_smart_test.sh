#!/bin/bash
# Self-asserting checks for read_smart.sh: it makes one decision -- which
# smartctl flags to use (the spin-up guard, from lsblk's TRAN) -- and forwards
# that same TRAN to parse/smart.sh as a fallback only. Neither was covered by
# anything. Stub lsblk/smartctl on PATH (same approach as flash_test.sh's
# stub/flasher) so the real spin-up policy and the real fallback argument are
# exercised without hardware.
#   bash tests/read_smart_test.sh   ->  "read_smart: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
RS="../source/usr/local/emhttp/plugins/hbaviewer/scripts/read_smart.sh"
fail=0
ok()  { echo "PASS  $1"; }
bad() { echo "FAIL  $1 -- $2"; fail=1; }
has()      { case "$out" in *"$2"*) ok "$1" ;; *) bad "$1" "want '$2' in: $out" ;; esac; }
hasnt()    { case "$out" in *"$2"*) bad "$1" "did NOT want '$2' in: $out" ;; *) ok "$1" ;; esac; }
arghas()   { case "$args" in *"$2"*) ok "$1" ;; *) bad "$1" "want '$2' in args: $args" ;; esac; }
arghasnt() { case "$args" in *"$2"*) bad "$1" "did NOT want '$2' in args: $args" ;; *) ok "$1" ;; esac; }

STUBDIR=$(mktemp -d)
STUB_ARGS=$(mktemp)
trap 'rm -rf "$STUBDIR"; rm -f "$STUB_ARGS"' EXIT

# lsblk stub: ignore its args, report whatever transport this case is testing.
cat > "$STUBDIR/lsblk" <<'STUB'
#!/bin/bash
echo "$STUB_TRAN"
STUB
# smartctl stub: record the exact flags read_smart.sh chose, then hand back a
# real SMART capture so parse/smart.sh downstream has something to parse.
cat > "$STUBDIR/smartctl" <<'STUB'
#!/bin/bash
echo "$*" >> "$STUB_ARGS"
cat "$STUB_FIXTURE"
STUB
chmod +x "$STUBDIR/lsblk" "$STUBDIR/smartctl"

# A capture with neither vocabulary: no SAS log-page fields, no ATA attribute
# table -- roughly what a standby read actually returns (issue #10's evidence
# was that a *sleeping* drive emits almost nothing to classify from). This is
# the only shape where parse/smart.sh's fallback argument can be observed in
# the output at all; every other capture has its own vocabulary and wins.
cat > "$STUBDIR/neutral_smart.txt" <<'FIXTURE'
smartctl 7.5 2025-04-30 r5714 [x86_64-linux-6.18.38-Unraid] (local build)

=== START OF INFORMATION SECTION ===
Device Model:     Unknown
Device is in STANDBY mode, suppress additional output with -n
FIXTURE

export STUB_ARGS
export STUB_FIXTURE  # each case reassigns this before calling run(); must
                      # stay exported so the stub -- a real child process,
                      # not just a forked subshell -- can see later values too.

# $() runs in a subshell, so `out` is captured normally but `args` must be
# read back from the stub's args file after the subshell exits.
run() {  # $1 = STUB_TRAN
    : > "$STUB_ARGS"
    STUB_TRAN="$1" PATH="$STUBDIR:$PATH" bash "$RS" /dev/sdX
}

# ── sas bus, real SAS drive: bus decision skips the spin-up guard, and the
# drive's own SAS vocabulary happens to agree with the bus. ─────────────────
STUB_FIXTURE="$PWD/fixtures/smart/sas_drive.txt"
out=$(run sas); args=$(cat "$STUB_ARGS")
arghasnt "sas bus: no -n standby (electronics-only read wakes nothing)" '-n standby'
has      "sas bus + SAS drive: transport is sas"                        '"transport":"sas"'

# ── sata bus, real SATA drive: bus decision respects the spin-up guard, and
# the drive's own ATA vocabulary agrees with the bus. ───────────────────────
STUB_FIXTURE="$PWD/fixtures/smart/sata_drive.txt"
out=$(run sata); args=$(cat "$STUB_ARGS")
arghas "sata bus: -n standby (ATA read can spin the disk up)" '-n standby'
has    "sata bus + SATA drive: transport is sata"              '"transport":"sata"'

# ── usb (or any non-sas bus): spin-up guard still applies (bus decision only
# knows "not sas"). The drive says nothing classifiable, so parse/smart.sh's
# fallback carries the bus guess through verbatim -- this asserts the
# fallback ARGUMENT, not anything the drive told us. ────────────────────────
STUB_FIXTURE="$STUBDIR/neutral_smart.txt"
out=$(run usb); args=$(cat "$STUB_ARGS")
arghas "usb bus: -n standby (unknown bus respects spin-up guard)" '-n standby'
has    "usb bus, silent drive: fallback argument forwarded"       '"transport":"usb"'

# ── unknown/empty bus (lsblk reported nothing usable), drive silent too:
# fallback carries the empty string through, never inventing a guess. ───────
out=$(run ""); args=$(cat "$STUB_ARGS")
has "empty bus, silent drive: fallback argument forwarded as empty" '"transport":""'

# ── issue #10 (@jac2424, SAS9207-8i): a SATA drive behind a SAS HBA. lsblk
# calls the bus "sas" -- same as a real SAS drive -- so the spin-up guard is
# STILL skipped here. That is the known, deliberately-unfixed gap (see the
# comment in read_smart.sh): an ATA passthrough read gets no -n standby guard
# on this path. What this plan DOES fix: the drive's own ATA attribute table
# overrides the "sas" bus guess in the reported transport. ──────────────────
STUB_FIXTURE="$PWD/fixtures/smart/sata_drive.txt"
out=$(run sas); args=$(cat "$STUB_ARGS")
arghasnt "SATA-behind-SAS: no -n standby (the known, unfixed spin-up gap)" '-n standby'
has      "SATA-behind-SAS: drive's ATA vocabulary overrides the sas bus"   '"transport":"sata"'

echo
[ $fail -eq 0 ] && { echo "read_smart: all pass"; exit 0; } || { echo "read_smart: FAILURES"; exit 1; }
