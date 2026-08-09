#!/bin/bash
# Self-asserting checks for hba_topology and hba_subvendor in lib.sh.
#
# Topology decides whether a firmware verdict is shown at all. Broadcom ships a
# separate multi-path firmware track for the 9300/9305/9400/9405W with its own
# version numbering, so comparing a multipath card against the standard track
# reports a correctly configured card as six major versions behind. The index
# suppresses those boards unless topology is known to be internal -- which,
# without this function, is never, and the feature renders nothing on the most
# common cards.
#
# The trees are built at runtime under mktemp -d, never committed: every path
# here contains a colon, which Windows/NTFS forbids and MSYS silently mangles.
#
#   bash tests/topology_test.sh   ->  "topology: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
SRC="../source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh"
fail=0
eq() {  # name  want  got
    if [ "$2" = "$3" ]; then echo "PASS  $1"; else echo "FAIL  $1 -- want '$2', got '$3'"; fail=1; fi
}

FN=$(sed -n '/^hba_topology()/,/^}/p' "$SRC"; sed -n '/^hba_subvendor()/,/^}/p' "$SRC")
[ -n "$FN" ] || { echo "FAIL  hba_topology/hba_subvendor not found in $SRC"; exit 1; }

ROOT=$(mktemp -d)
trap 'rm -rf "$ROOT"' EXIT

# host9: the maintainer's reporter shape -- a 9305-24i with 15 SATA drives all
# direct-attached, no expander anywhere. This is the case that must produce a
# verdict; if it does not, the feature is invisible on the card that motivated it.
mkdir -p "$ROOT/dev" "$ROOT/exp"
for n in $(seq 0 14); do mkdir -p "$ROOT/dev/end_device-9:$n"; done

# host3 and host4 each carry exactly ONE of the two disqualifying signals, never
# both -- so a mutant that deletes either check independently still fails one of
# these two, instead of both silently passing because the other signal covers it.
#
# host3: an expander-H:N entry ALONE (its end_devices are ordinary two-component
# children, same shape as host9's). Kills a mutant that deletes/neuters the
# SYS_SAS_EXPANDER loop -- without that loop this reads "internal".
mkdir -p "$ROOT/exp/expander-3:0"
mkdir -p "$ROOT/dev/end_device-3:0" "$ROOT/dev/end_device-3:1"

# host4: a three-component end_device-H:N:M child ALONE, no expander entry at
# all. Kills a mutant that neuters the "*:*:*" case check -- without it this
# reads "internal" too, since found=1 and no expander disqualifies it.
mkdir -p "$ROOT/dev/end_device-4:0:0"

# host7: no matching sysfs entries at all. This function has no way to tell
# "host present, nothing attached" from "host absent" -- both are a glob that
# matches nothing -- so one assertion honestly covers both, rather than two
# identical inputs dressed up as different cases.

top() { SYS_SAS_DEVICE="$ROOT/dev" SYS_SAS_EXPANDER="$ROOT/exp" \
        bash -c "$FN"$'\n''hba_topology "$1"' _ "$1"; }

eq "direct-attached card is internal"                  "internal" "$(top 9)"
eq "expander alone (no 3-component child) is unknown"  "unknown"  "$(top 3)"
eq "3-component child alone (no expander) is unknown"  "unknown"  "$(top 4)"
eq "no sysfs entries (present-empty or absent) is unknown" "unknown" "$(top 7)"

# Another host's expander must not suppress this card. A two-HBA box where one
# card sits behind an expander would otherwise silence both.
eq "host9 stays internal despite host3's expander" "internal" "$(top 9)"

# subvendor: a plain sysfs attribute read, with the failure case being the one
# that matters -- an unreadable file must yield empty, never a bare 0x0.
PCI=$(mktemp -d); trap 'rm -rf "$ROOT" "$PCI"' EXIT
mkdir -p "$PCI/card" "$PCI/bare" "$PCI/spaced"
printf '0x1000\n' > "$PCI/card/subsystem_vendor"
printf '0x 1000\n' > "$PCI/spaced/subsystem_vendor"
sub() { bash -c "$FN"$'\n''hba_subvendor "$1"' _ "$1"; }
eq "subvendor read from sysfs"        "0x1000" "$(sub "$PCI/card")"
eq "missing attribute yields empty"   ""       "$(sub "$PCI/bare")"
eq "absent directory yields empty"    ""       "$(sub "$PCI/nope")"
# A mutant replacing the whitespace strip with a bare "$v" survives on the
# happy-path fixture above only because $(cat ...) already eats the trailing
# newline -- this is the case that actually needs the strip.
eq "internal whitespace is stripped" "0x1000" "$(sub "$PCI/spaced")"

[ $fail -eq 0 ] && echo "topology: all pass"
exit $fail
