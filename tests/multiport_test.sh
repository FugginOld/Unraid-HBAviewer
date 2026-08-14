#!/bin/bash
# Self-asserting checks for the two lib.sh helpers a multi-card SAS2 box needs
# (plan 059, issue #18): lsi_ports, which enumerates lsiutil's own port table,
# and _host_for_pci, which joins a port's PCI bus/device to the scsi host that
# owns it. Without the second one every card in a loop reads card 1's topology,
# subvendor and card_id -- and identical card_ids make card_group.php merge two
# physically separate cards into one display card.
#
# _pci_dir_of_host is STUBBED here rather than exercised: it needs real symlinks
# under a colon-laden sysfs tree, which Git Bash turns into junctions readlink -f
# will not traverse (the same wall tests/hwmon_chip_test.sh hit). Its own walk is
# unchanged by this plan; what is new, and what these cases pin, is the matching.
#
#   bash tests/multiport_test.sh   ->  "multiport: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
SRC="../source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh"
P="../source/usr/local/emhttp/plugins/hbaviewer/scripts/parse"
fail=0
eq() {  # name  want  got
    if [ "$2" = "$3" ]; then echo "PASS  $1"; else echo "FAIL  $1 -- want '$2', got '$3'"; fail=1; fi
}

FN=$(sed -n '/^lsi_ports()/,/^}/p' "$SRC"; sed -n '/^_host_for_pci()/,/^}/p' "$SRC")
[ -n "$FN" ] || { echo "FAIL  lsi_ports/_host_for_pci not found in $SRC"; exit 1; }
eval "$FN"

# ── lsi_ports ───────────────────────────────────────────────────────────────
PORT=1
eq "ports: single-card banner"  "1"    "$(lsi_ports fixtures/hba_banner.txt | tr '\n' ' ' | sed 's/ $//')"
eq "ports: two-card banner"     "1 2"  "$(lsi_ports fixtures/lsiutil_dual/banner.txt | tr '\n' ' ' | sed 's/ $//')"
# The fallback is the whole safety story: a banner shape we have never seen must
# degrade to exactly the pre-plan behaviour, not to zero cards.
PORT=7
eq "ports: unparseable falls back to \$PORT" "7" "$(lsi_ports /dev/null)"
eq "ports: missing file falls back"          "7" "$(lsi_ports fixtures/nope.txt 2>/dev/null)"
PORT=1
# "Select a device: [1-2 ...]" and the version line must not be mistaken for rows.
eq "ports: prompt line is not a row" "1 2" \
   "$(lsi_ports fixtures/lsiutil_dual/banner.txt | tr '\n' ' ' | sed 's/ $//')"

# ── _host_for_pci ───────────────────────────────────────────────────────────
ROOT=$(mktemp -d)
trap 'rm -rf "$ROOT"' EXIT
SYS_SCSI_HOST="$ROOT/scsi_host"
mkdir -p "$SYS_SCSI_HOST"/host0 "$SYS_SCSI_HOST"/host1 "$SYS_SCSI_HOST"/host2
echo ahci    > "$SYS_SCSI_HOST/host0/proc_name"   # not an HBA; must be skipped
echo mpt2sas > "$SYS_SCSI_HOST/host1/proc_name"
echo mpt2sas > "$SYS_SCSI_HOST/host2/proc_name"
# Stub: host N sits at the PCI address real hardware would resolve to.
_pci_dir_of_host() {
    case "$1" in
        0) printf '/sys/devices/pci0000:00/0000:00:17.0' ;;
        1) printf '/sys/devices/pci0000:00/0000:00:1c.0/0000:03:00.0' ;;
        2) printf '/sys/devices/pci0000:00/0000:00:1c.4/0000:04:00.0' ;;
    esac
}
eq "join: bus 03 -> host1"          "1"  "$(_host_for_pci 03 00)"
eq "join: bus 04 -> host2"          "2"  "$(_host_for_pci 04 00)"
# lsiutil's column width is not a promise; "3" and "03" are the same bus.
eq "join: unpadded bus still joins" "1"  "$(_host_for_pci 3 0)"
eq "join: uppercase hex joins"      "1"  "$(_host_for_pci 0A 00; _host_for_pci 03 00)"
# A miss must be a miss. Returning card 1 here is the defect that would group
# two separate cards under one card_id.
_host_for_pci 09 00 >/dev/null 2>&1; eq "join: no match returns 1" "1" "$?"
eq "join: no match prints nothing"  ""   "$(_host_for_pci 09 00 2>/dev/null)"
_host_for_pci "zz" 00 >/dev/null 2>&1;  eq "join: garbled bus returns 1"    "1" "$?"
_host_for_pci "" ""  >/dev/null 2>&1;   eq "join: empty columns return 1"   "1" "$?"
# host0 is at 0000:00:17.0 but runs ahci -- a non-HBA at a matching-looking
# address must never be claimed as the card.
eq "join: non-mpt host is skipped"  ""   "$(_host_for_pci 00 17 2>/dev/null)"

# ── parse/hba.sh row selection ──────────────────────────────────────────────
card() {  # $1 = ioc  $2 = board  $3 = port arg  $4 = field
    bash "$P/hba.sh" "$1" fixtures/lsiutil_dual/banner.txt "$2" 80 "" "$3" \
        | grep -oE "\"$4\": *\"?[0-9A-Za-z.]+\"?"
}
# Port 2's row carries a different firmware (P16 vs P20) precisely so a loop
# that reads the wrong row is visible as a wrong value, not a duplicate.
eq "parse: port 1 row" '"firmware": "20.00.07.00"' \
   "$(card fixtures/lsiutil_dual/ioc_p1.txt fixtures/lsiutil_dual/board_p1.txt 1 firmware)"
eq "parse: port 2 row" '"firmware": "16.00.07.00"' \
   "$(card fixtures/lsiutil_dual/ioc_p2.txt fixtures/lsiutil_dual/board_p2.txt 2 firmware)"
eq "parse: no port arg keeps the first row" '"firmware": "20.00.07.00"' \
   "$(card fixtures/lsiutil_dual/ioc_p1.txt fixtures/lsiutil_dual/board_p1.txt '' firmware)"
# The row's name and its board line must agree -- port 2 is ioc1 on bus 04, and
# a crossed capture shows up here before it ever reaches a card.
eq "parse: port 2 names ioc1" '"port_name": "ioc1"' \
   "$(card fixtures/lsiutil_dual/ioc_p2.txt fixtures/lsiutil_dual/board_p2.txt 2 port_name)"
# Temperature travels with the row's own IOC capture, not with the banner.
eq "parse: port 2 temperature" '"temp": 55' \
   "$(card fixtures/lsiutil_dual/ioc_p2.txt fixtures/lsiutil_dual/board_p2.txt 2 temp)"

echo
[ $fail -eq 0 ] && { echo "multiport: all pass"; exit 0; }
echo "multiport: FAILURES"; exit 1
