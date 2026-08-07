#!/bin/bash
# Self-asserting check for get_hba_health.sh's _drive_count -- the one piece of
# that script with a decision in it. The lsiutil backend used to hardcode
# "drives":0, so the Health tab's Topology row read "0 drives" on a 9207-8i with
# a full backplane (issue #11). A typo in the glob would silently put it back to
# 0, and nothing else in the suite touches this script.
#
# The function is lifted out with sed rather than sourced: get_hba_health.sh
# runs hba_each at load and would go looking for real hardware.
#   bash tests/health_sh_test.sh   ->  "health_sh: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
SRC="../source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_health.sh"
fail=0
eq() {  # name  want  got
    if [ "$2" = "$3" ]; then echo "PASS  $1"; else echo "FAIL  $1 -- want '$2', got '$3'"; fail=1; fi
}

FN=$(sed -n '/^_drive_count()/,/^}/p' "$SRC")
[ -n "$FN" ] || { echo "FAIL  _drive_count not found in $SRC"; exit 1; }

# ── The link block, including the SLOT ceiling (plan 056) ────────────────────
# health_storcli() emits the JSON the Health tab parses. Two things can break
# silently here: a printf whose format string and argument list drift apart
# (which produces invalid JSON, not an error), and the slot read looking in the
# wrong place. Both are covered by running the real function against a fake
# storcli and a fake sysfs tree — no hardware, no controller.
HS=$(sed -n '/^health_storcli()/,/^}/p' "$SRC")
[ -n "$HS" ] || { echo "FAIL  health_storcli not found in $SRC"; exit 1; }

LROOT=$(mktemp -d)
# The maintainer's own shape: an x8 Gen3 card in an x16 Gen4 slot. The slot is
# WIDER than the card here, which is the case that must not be read as a
# downtrain — the inverse of issue #13 and the reason health.php clamps to
# min(card, slot) rather than trusting the slot alone.
mkdir -p "$LROOT/0000:65:00.0" "$LROOT/bridge"
printf '8\n'             > "$LROOT/0000:65:00.0/current_link_width"
printf '8\n'             > "$LROOT/0000:65:00.0/max_link_width"
printf '8.0 GT/s PCIe\n' > "$LROOT/0000:65:00.0/current_link_speed"
printf '8.0 GT/s PCIe\n' > "$LROOT/0000:65:00.0/max_link_speed"
printf '16\n'             > "$LROOT/max_link_width"     # ".." from the card dir
printf '16.0 GT/s PCIe\n' > "$LROOT/max_link_speed"

cat > "$LROOT/storcli" <<'STUB'
#!/bin/bash
echo "PCI Address = 00:65:00:00"
echo "FW Version = 16.00.12.00"
echo "Physical Drives = 8"
echo "ROC temperature(Degree Celsius) = 52"
STUB
chmod +x "$LROOT/storcli"

LJSON=$(NOW=1000 UPTIME=500 STORCLI="$LROOT/storcli" SYS_PCI_ROOT="$LROOT" \
        bash -c "$HS"$'\n''_phys_json() { echo "[]"; }'$'\n''health_storcli 0' 2>/dev/null)

# Parsed with sed, NOT php: this is the shell suite and it runs where php may
# not be installed. That is not a lesser check for the bug being guarded --
# a printf whose format and arguments drift apart emits `"slot_width":,` with
# no value, which is exactly what these patterns refuse to match.
num() { printf '%s' "$1" | sed -nE "s/.*\"$2\":([0-9]+).*/\1/p"; }
str() { printf '%s' "$1" | sed -nE "s/.*\"$2\":\"([^\"]*)\".*/\1/p"; }

if printf '%s' "$LJSON" | grep -qE '"slot_width":[0-9]+,"slot_speed":"[^"]*"\}'; then
    echo "PASS  link JSON well-formed (format string and args agree)"
else
    echo "FAIL  link JSON malformed -- $LJSON"; fail=1
fi
eq "card max width read from the device"  "8"    "$(num "$LJSON" max_width)"
eq "slot max width read from the bridge"  "16"   "$(num "$LJSON" slot_width)"
eq "slot speed has its ' PCIe' stripped"  "16.0 GT/s" "$(str "$LJSON" slot_speed)"

# A platform whose bridge publishes nothing: the keys must still be present and
# empty, so health.php falls back to the card maximum instead of tripping.
BROOT=$(mktemp -d); mkdir -p "$BROOT/0000:65:00.0"
printf '8\n' > "$BROOT/0000:65:00.0/current_link_width"
printf '8\n' > "$BROOT/0000:65:00.0/max_link_width"
cp "$LROOT/storcli" "$BROOT/storcli"
BJSON=$(NOW=1000 UPTIME=500 STORCLI="$BROOT/storcli" SYS_PCI_ROOT="$BROOT" \
        bash -c "$HS"$'\n''_phys_json() { echo "[]"; }'$'\n''health_storcli 0' 2>/dev/null)
eq "no bridge: slot width is 0, not missing" "0" "$(num "$BJSON" slot_width)"
if printf '%s' "$BJSON" | grep -q '"slot_speed":""'; then
    echo "PASS  no bridge: slot speed is empty, not missing"
else
    echo "FAIL  no bridge: slot speed is empty, not missing -- $BJSON"; fail=1
fi
rm -rf "$LROOT" "$BROOT"

ROOT=$(mktemp -d)
trap 'rm -rf "$ROOT"' EXIT

# host3: SAS-transport shape -- the kernel's SAS transport class inserts
# port-H:P/end_device-H:P/ between device/ and target*, which no fixed-depth
# glob expects. Eight disks, plus an SES enclosure target (no block/ -- must
# not count) and a LUN with a generic/ node but no block/ at all, both nested
# the same way. Shape confirmed against a live 9207-8i (issue #14):
#   hostN/device/port-H:P/end_device-H:P/targetH:C:T/H:C:T:L/block/sdX
for t in $(seq 0 7); do
    mkdir -p "$ROOT/host3/device/port-3:$t/end_device-3:$t/target3:0:$t/3:0:$t:0/block/sd$t"
done
mkdir -p "$ROOT/host3/device/port-3:8/end_device-3:8/target3:0:8/3:0:8:0"         # SES: no block/
mkdir -p "$ROOT/host3/device/port-3:9/end_device-3:9/target3:0:9/3:0:9:0/generic" # no block/ either

# Overcount trap: sd0's block/ dir carries the subdirs a real block device has
# (queue/, holders/, slaves/, power/) plus a partition dir. A `find -path`
# glob whose '*' is allowed to cross '/' matches all of these beneath
# block/sd0/ and reports 6 for this one drive; the implementation under test
# must still report 1. Mirrored in isolation on host5 so a failure here can't
# be masked by the other seven correctly-counted disks on host3.
for child in queue holders slaves power sd01; do
    mkdir -p "$ROOT/host3/device/port-3:0/end_device-3:0/target3:0:0/3:0:0:0/block/sd0/$child"
done
for child in queue holders slaves power sda1; do
    mkdir -p "$ROOT/host5/device/port-5:0/end_device-5:0/target5:0:0/5:0:0:0/block/sda/$child"
done

# host9: flat, non-SAS-transport layout -- AHCI and other non-SAS hosts really
# are shaped this way, so the fix must be depth-agnostic, not merely two
# levels deeper.
mkdir -p "$ROOT/host9/device/target9:0:0/9:0:0:0/block/sdz"       # another HBA
mkdir -p "$ROOT/host4"                                            # host with no drives
# host42 stays absent

count() { SYS_SCSI_HOST="$ROOT" bash -c "$FN"$'\n''_drive_count "$1"' _ "$1"; }

eq "counts the eight disks on host3"      8 "$(count 3)"
eq "does not count another host's disks"  1 "$(count 9)"
eq "empty host counts zero"               0 "$(count 4)"
eq "absent host counts zero"              0 "$(count 42)"
eq "block/ subdirs under one drive do not inflate it to six" 1 "$(count 5)"

[ $fail -eq 0 ] && echo "health_sh: all pass"
exit $fail
