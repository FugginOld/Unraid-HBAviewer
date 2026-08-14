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
eq "ports: single-card banner"  "1"      "$(lsi_ports fixtures/hba_banner.txt | tr '\n' ' ' | sed 's/ $//')"
eq "ports: two-card banner"     "1 2"    "$(lsi_ports fixtures/lsiutil_multi/2card/banner.txt | tr '\n' ' ' | sed 's/ $//')"
eq "ports: three-card banner"   "1 2 3"  "$(lsi_ports fixtures/lsiutil_multi/3card/banner.txt | tr '\n' ' ' | sed 's/ $//')"
# The fallback is the whole safety story: a banner shape we have never seen must
# degrade to exactly the pre-plan behaviour, not to zero cards.
PORT=7
eq "ports: unparseable falls back to \$PORT" "7" "$(lsi_ports /dev/null)"
eq "ports: missing file falls back"          "7" "$(lsi_ports fixtures/nope.txt 2>/dev/null)"
PORT=1
# The real banner carries a column header ("Port Name  Chip Vendor/Type/Rev"),
# an "N MPT Ports found" line and the "[1-3 or 0 to quit]" prompt. None of them
# is a port, and the count must not come from the prose.
eq "ports: header and prompt are not rows" "3" \
   "$(lsi_ports fixtures/lsiutil_multi/3card/banner.txt | wc -l | tr -d ' ')"

# ── _host_for_pci ───────────────────────────────────────────────────────────
ROOT=$(mktemp -d)
trap 'rm -rf "$ROOT"' EXIT
SYS_SCSI_HOST="$ROOT/scsi_host"
mkdir -p "$SYS_SCSI_HOST"/host0 "$SYS_SCSI_HOST"/host1 "$SYS_SCSI_HOST"/host2 "$SYS_SCSI_HOST"/host3
echo ahci    > "$SYS_SCSI_HOST/host0/proc_name"   # not an HBA; must be skipped
echo mpt2sas > "$SYS_SCSI_HOST/host1/proc_name"
echo mpt2sas > "$SYS_SCSI_HOST/host2/proc_name"
echo mpt2sas > "$SYS_SCSI_HOST/host3/proc_name"
# Stub: the three PCI addresses brianara3's box actually publishes (issue #18,
# 03-sysfs/pci.txt), plus a non-HBA at a fourth. lsiutil calls these buses
# 129, 130 and 131.
_pci_dir_of_host() {
    case "$1" in
        0) printf '/sys/devices/pci0000:00/0000:00:17.0' ;;
        1) printf '/sys/devices/pci0000:80/0000:80:01.0/0000:81:00.0' ;;
        2) printf '/sys/devices/pci0000:80/0000:80:03.0/0000:82:00.0' ;;
        3) printf '/sys/devices/pci0000:80/0000:80:03.2/0000:83:00.0' ;;
    esac
}
# THE case this plan nearly shipped wrong: lsiutil says 129, sysfs says 81.
eq "join: decimal 129 -> 0000:81:"  "1"  "$(_host_for_pci 129 0)"
eq "join: decimal 130 -> 0000:82:"  "2"  "$(_host_for_pci 130 0)"
eq "join: decimal 131 -> 0000:83:"  "3"  "$(_host_for_pci 131 0)"
# Reading those as hex would land on bus 0x129 and match nothing at all.
eq "join: 81 is NOT the same as 129" ""  "$(_host_for_pci 81 0 2>/dev/null)"
# A miss must be a miss. Returning card 1 here is the defect that would group
# two separate cards under one card_id.
_host_for_pci 99 0 >/dev/null 2>&1; eq "join: no match returns 1" "1" "$?"
eq "join: no match prints nothing"  ""   "$(_host_for_pci 99 0 2>/dev/null)"
_host_for_pci "zz" 0 >/dev/null 2>&1;   eq "join: garbled bus returns 1"    "1" "$?"
_host_for_pci "" ""  >/dev/null 2>&1;   eq "join: empty columns return 1"   "1" "$?"
# A zero-padded column must not be read as octal -- bash printf rejects 08.
_host_for_pci "08" "00" >/dev/null 2>&1; eq "join: 08 is not invalid octal" "1" "$?"
# host0 is at 0000:00:17.0 but runs ahci -- a non-HBA at a matching-looking
# address must never be claimed as the card.
eq "join: non-mpt host is skipped"  ""   "$(_host_for_pci 0 23 2>/dev/null)"

# ── parse/hba.sh row selection ──────────────────────────────────────────────
card() {  # $1 = fixture dir  $2 = ioc  $3 = port arg  $4 = field
    bash "$P/hba.sh" "fixtures/lsiutil_multi/$1/$2" "fixtures/lsiutil_multi/$1/banner.txt" \
         "fixtures/lsiutil_multi/$1/board.txt" 80 "" "$3" \
        | grep -oE "\"$4\": *\"?[0-9A-Za-z.:-]+\"?"
}
M2=2card; M3=3card
# masterwishx's two cards are DIFFERENT models on different buses, so a loop
# that reads the wrong row is visible as the wrong card, not a duplicate.
eq "parse: 2card port 1 board"  '"board_name": "LSI2308-IT"' "$(card $M2 ioc_p1.txt 1 board_name)"
eq "parse: 2card port 2 board"  '"board_name": "SAS9207-8i"' "$(card $M2 ioc_p1.txt 2 board_name)"
eq "parse: 2card port 1 ioc0"   '"port_name": "ioc0"'        "$(card $M2 ioc_p1.txt 1 port_name)"
eq "parse: 2card port 2 ioc1"   '"port_name": "ioc1"'        "$(card $M2 ioc_p1.txt 2 port_name)"
eq "parse: no port arg keeps the first row" '"board_name": "LSI2308-IT"' \
   "$(card $M2 ioc_p1.txt '' board_name)"
# Decimal 1 and 6 -> 01:00 and 06:00. This box cannot show the base bug; the
# three-card one below can.
eq "parse: 2card port 1 location" '"pci_location": "01:00"' "$(card $M2 ioc_p1.txt 1 pci_location)"
eq "parse: 2card port 2 location" '"pci_location": "06:00"' "$(card $M2 ioc_p1.txt 2 pci_location)"
# lsiutil says 129/130/131; lspci and sysfs say 81/82/83. The Overview printed
# "129:0" on this box before the conversion went in.
eq "parse: 3card port 1 location" '"pci_location": "81:00"' "$(card $M3 ioc_p3.txt 1 pci_location)"
eq "parse: 3card port 2 location" '"pci_location": "82:00"' "$(card $M3 ioc_p3.txt 2 pci_location)"
eq "parse: 3card port 3 location" '"pci_location": "83:00"' "$(card $M3 ioc_p3.txt 3 pci_location)"
# Temperature travels with the row's own IOC capture: 0x3F on the 2-card box's
# port 1, 0x3A on the 3-card box's port 3 -- the only two ports either bundle
# could read, which is the bug stated as a fixture.
eq "parse: 2card temperature" '"temp": 63' "$(card $M2 ioc_p1.txt 1 temp)"
eq "parse: 3card temperature" '"temp": 58' "$(card $M3 ioc_p3.txt 3 temp)"

echo
[ $fail -eq 0 ] && { echo "multiport: all pass"; exit 0; }
echo "multiport: FAILURES"; exit 1
