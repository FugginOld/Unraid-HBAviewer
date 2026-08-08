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

# The sysfs read itself, shared with the lsiutil path (see the #14 block below)
# and so lifted out alongside whichever function is under test.
PD=$(sed -n '/^_pci_dir_of_host()/,/^}/p' "$SRC")
LF=$(sed -n '/^_link_from_sysfs()/,/^}/p' "$SRC"; sed -n '/^_link_speed()/,/^}/p' "$SRC")
[ -n "$PD" ] && [ -n "$LF" ] || { echo "FAIL  link helpers not found in $SRC"; exit 1; }

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
        bash -c "$LF"$'\n'"$HS"$'\n''_phys_json() { echo "[]"; }'$'\n''health_storcli 0' 2>/dev/null)

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
        bash -c "$LF"$'\n'"$HS"$'\n''_phys_json() { echo "[]"; }'$'\n''health_storcli 0' 2>/dev/null)
eq "no bridge: slot width is 0, not missing" "0" "$(num "$BJSON" slot_width)"
if printf '%s' "$BJSON" | grep -q '"slot_speed":""'; then
    echo "PASS  no bridge: slot speed is empty, not missing"
else
    echo "FAIL  no bridge: slot speed is empty, not missing -- $BJSON"; fail=1
fi
rm -rf "$LROOT" "$BROOT"

# ── lsiutil's route to the same sysfs files (issue #14) ──────────────────────
# lsiutil reports no maximum and there is no PCI-address line to parse, so that
# backend used to emit max_width 0 / slot_width 0 and health.php told a
# SAS9207-8i at x4 that its link was "full" — asserted on no information. The
# kernel knew the whole time: the card's x8 LnkCap is max_link_width in sysfs,
# reachable from the scsi_host. _pci_dir_of_host walks there, _link_from_sysfs
# reads it. Both are lifted out and driven against a fixture, same as above.
# jac2424's box: a SAS2308 whose x8 card sits in a chipset x4 slot. The fixture
# is the REAL sysfs shape -- /sys/class/scsi_host/hostN is a symlink INTO the
# device tree at <pci dev>/hostN/scsi_host/hostN, so pointing SYS_SCSI_HOST at
# that scsi_host dir is what readlink -f resolves to on hardware, and it walks
# up three levels to the card. No symlink is created: the suite must run on
# filesystems that don't have them.
SROOT=$(mktemp -d)
CARD="$SROOT/0000:81:00.0"
mkdir -p "$CARD/host0/scsi_host/host0"
printf '4\n'             > "$CARD/current_link_width"
printf '8\n'             > "$CARD/max_link_width"        # LnkCap x8 -- the card
printf '8.0 GT/s PCIe\n' > "$CARD/current_link_speed"
printf '8.0 GT/s PCIe\n' > "$CARD/max_link_speed"
printf '4\n'             > "$SROOT/max_link_width"       # the slot's own ceiling
printf '8.0 GT/s PCIe\n' > "$SROOT/max_link_speed"

# width/speed pre-set to what lsiutil's IOC page gives, to prove sysfs agrees
# rather than silently replacing them with a default.
SJSON=$(SYS_SCSI_HOST="$CARD/host0/scsi_host" bash -c "$PD"$'\n'"$LF"$'\n''
    width=4 maxwidth=0 speed="8.0 GT/s" maxspeed="" slotwidth=0 slotspeed=""
    _link_from_sysfs "$(_pci_dir_of_host 0)"
    echo "$width|$maxwidth|$speed|$maxspeed|$slotwidth|$slotspeed"' 2>/dev/null)
eq "lsiutil path reaches the card from its scsi_host" "4|8|8.0 GT/s|8.0 GT/s|4|8.0 GT/s" "$SJSON"

# No card behind the host (or a platform that publishes no link state): every
# field must stay at its caller-set value, so health.php still says "no maximum"
# instead of comparing against a zero it mistakes for a ceiling.
NJSON=$(SYS_SCSI_HOST="$SROOT" bash -c "$PD"$'\n'"$LF"$'\n''
    width=4 maxwidth=0 speed="8.0 GT/s" maxspeed="" slotwidth=0 slotspeed=""
    _link_from_sysfs "$(_pci_dir_of_host 99)"
    echo "$width|$maxwidth|$speed|$maxspeed|$slotwidth|$slotspeed"' 2>/dev/null)
eq "absent card leaves the link untouched" "4|0|8.0 GT/s||0|" "$NJSON"
rm -rf "$SROOT"

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
