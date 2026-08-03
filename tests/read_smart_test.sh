#!/bin/bash
# Self-asserting checks for read_smart.sh: it makes two decisions -- which
# smartctl flags to use, and which transport to forward to parse/smart.sh --
# and neither was covered by anything. Stub lsblk/smartctl on PATH (same
# approach as flash_test.sh's stub/flasher) so the real spin-up policy and
# the real transport argument are exercised without hardware.
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

export STUB_ARGS
export STUB_FIXTURE="$PWD/fixtures/smart/sata_drive.txt"

# $() runs in a subshell, so `out` is captured normally but `args` must be
# read back from the stub's args file after the subshell exits.
run() {  # $1 = STUB_TRAN
    : > "$STUB_ARGS"
    STUB_TRAN="$1" PATH="$STUBDIR:$PATH" bash "$RS" /dev/sdX
}

# ── sas: log-page read, no spin-up guard ──────────────────────────────────
out=$(run sas); args=$(cat "$STUB_ARGS")
has      "sas: transport forwarded" '"transport":"sas"'
arghasnt "sas: no -n standby"       '-n standby'

# ── sata: ATA read, respects -n standby ───────────────────────────────────
out=$(run sata); args=$(cat "$STUB_ARGS")
has    "sata: transport forwarded" '"transport":"sata"'
arghas "sata: -n standby"          '-n standby'

# ── usb (or any non-sas transport): forwarded as-is, never relabelled sata.
# This is the case that catches the else branch hardcoding "sata" -- the one
# regression the plan calls out by name.
out=$(run usb); args=$(cat "$STUB_ARGS")
has    "usb: transport forwarded"                          '"transport":"usb"'
hasnt  "usb: not relabelled sata"                           '"transport":"sata"'
arghas "usb: -n standby (unknown bus respects spin-up guard)" '-n standby'

# ── unknown/empty transport: lsblk reported nothing usable ───────────────
out=$(run ""); args=$(cat "$STUB_ARGS")
has "empty: transport forwarded as empty, not guessed" '"transport":""'

echo
[ $fail -eq 0 ] && { echo "read_smart: all pass"; exit 0; } || { echo "read_smart: FAILURES"; exit 1; }
