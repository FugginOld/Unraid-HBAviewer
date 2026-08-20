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

FN=$(sed -n '/^lsi_ports()/,/^}/p' "$SRC"; sed -n '/^_host_for_pci()/,/^}/p' "$SRC"
     sed -n '/^lsi_host_for()/,/^}/p' "$SRC")
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
# A 9400's board name contains a SPACE ("HBA 9400-16i"); field 5 alone read
# "HBA", which is also a name no firmware index can match. Reachable through
# this parser on a mixed box, where a SAS2 card keeps the whole box on the
# lsiutil backend and the loop then parses the 9400's row too.
eq "parse: spaced board name survives" '"board_name": "HBA 9400-16i"' \
   "$(bash "$P/hba.sh" fixtures/hba_ioc.txt fixtures/hba_banner.txt \
        fixtures/lsiutil_multi/9400/board.txt 80 "" 1 \
      | grep -oE '"board_name": "[^"]*"')"
eq "parse: second 9400 row, also spaced" '"board_name": "HBA 9400-8i"' \
   "$(bash "$P/hba.sh" fixtures/hba_ioc.txt fixtures/hba_banner.txt \
        fixtures/lsiutil_multi/9400/board.txt 80 "" 2 \
      | grep -oE '"board_name": "[^"]*"')"
# The bus column shifts by a character between a 1-digit and a 3-digit bus, so
# the name cannot be cut at a fixed offset -- 193 decimal is c1 hex.
eq "parse: 9400 location survives the same cut" '"pci_location": "c1:00"' \
   "$(bash "$P/hba.sh" fixtures/hba_ioc.txt fixtures/hba_banner.txt \
        fixtures/lsiutil_multi/9400/board.txt 80 "" 1 \
      | grep -oE '"pci_location": "[^"]*"')"
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

# ── ov_lsiutil loops over every port ────────────────────────────────────────
# The composer itself, with lsiutil replaced by a stub that replays the 3-card
# fixture per -p. Everything the loop reaches into sysfs for is stubbed above or
# here; what is under test is the WIRING -- three objects out, each carrying its
# own port's temperature and its own card's identity.
#
# The three IOC captures are SYNTHESIZED from the one real capture the bundle
# holds (ioc_p3.txt), with the temperature byte replaced by the values
# brianara3's box actually reported on 2026-08-16: 53, 61, 59 C. The bundle can
# only ever contain one port's capture -- that is the bug -- so a per-port
# expectation has to come from the terminal output instead.
STUBDIR=$(mktemp -d); trap 'rm -rf "$ROOT" "$STUBDIR"' EXIT
i=0
for c in 53 61 59; do
    i=$((i + 1))
    sed -E "s/(IOCTemperature:[[:space:]]*)0x[0-9A-Fa-f]+/\1$(printf '0x%04X' "$c")/" \
        fixtures/lsiutil_multi/3card/ioc_p3.txt > "$STUBDIR/ioc_p$i.txt"
done
hba_query() {
    case "$1" in
        -b) cat fixtures/lsiutil_multi/3card/board.txt ;;
        -p*) case "$3" in
                 25,2,0,0) cat "$STUBDIR/ioc_p${1#-p}.txt" ;;
                 *) : ;;   # -a 1,0 identify: absent from the bundle, empty is a valid read
             esac ;;
        *) cat fixtures/lsiutil_multi/3card/banner.txt ;;
    esac
}
require_binary()  { return 0; }
find_storcli()    { :; }
hba_has_sas2()    { return 0; }
hba_has_sas3()    { return 1; }
hba_has_sas4()    { return 1; }
hba_topology()    { printf 'direct'; }
hba_subvendor()   { printf '0x1000'; }
hba_card_id()     { basename "$1"; }   # the PCI slot, which is what groups IOCs
DIR="../source/usr/local/emhttp/plugins/hbaviewer/scripts"
ALERT=80
eval "$(sed -n '/^ov_lsiutil()/,/^}/p' "$DIR/get_hba_info.sh"
        sed -n '/^_ov_one()/,/^}/p'    "$DIR/get_hba_info.sh"
        sed -n '/^lsi_each_card()/,/^}/p' "$SRC")"
OUT=$(ov_lsiutil)
eq "loop: three controllers" "3" "$(grep -o '"temp"' <<< "$OUT" | wc -l | tr -d ' ')"
eq "loop: each port its own temperature" "53 61 59" \
   "$(grep -oE '"temp": [0-9]+' <<< "$OUT" | awk '{print $2}' | tr '\n' ' ' | sed 's/ $//')"
# The join, end to end: port 1/2/3 -> decimal bus 129/130/131 -> host1/2/3 ->
# three DIFFERENT slots. Identical card_ids here would make card_group.php fuse
# three separate cards into one display card.
eq "loop: each card its own card_id" "0000:81:00.0 0000:82:00.0 0000:83:00.0" \
   "$(grep -oE '"card_id": "[^"]*"' <<< "$OUT" | cut -d'"' -f4 | tr '\n' ' ' | sed 's/ $//')"
eq "loop: each card its own board row" "81:00 82:00 83:00" \
   "$(grep -oE '"pci_location": "[^"]*"' <<< "$OUT" | cut -d'"' -f4 | tr '\n' ' ' | sed 's/ $//')"
# A failed join must not hand card 1's identity to card 2 (see the gate in
# ov_lsiutil): with no matching host at all, every card_id comes back empty
# rather than all three sharing _first_sas_host's slot.
_host_for_pci_real=$(declare -f _host_for_pci)
# Defined through eval so shellcheck does not see a function DEFINITION below
# the calls the real one legitimately serves above (SC2218). The real one is
# restored a few lines down; this stub exists only to fail the join.
eval '_host_for_pci() { return 1; }'
_first_sas_host() { printf '1'; }
eq "loop: failed join yields no card_id on a multi-port box" "" \
   "$(ov_lsiutil | grep -oE '"card_id": "[^"]+"' | tr -d '\n')"
eval "$_host_for_pci_real"

# ── health_lsiutil loops, and reaches the RIGHT card's sysfs ────────────────
# The dashboard tile is the symptom issue #18 was filed about: three cards, one
# temperature. _drive_count is stubbed to echo the host number it was handed,
# which is how a card silently reading its neighbour's sysfs becomes visible.
HSRC="../source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_health.sh"
eval "$(sed -n '/^health_lsiutil()/,/^}/p'      "$HSRC"
        sed -n '/^_health_lsiutil_one()/,/^}/p' "$HSRC"
        sed -n '/^lsi_each_card()/,/^}/p'       "$SRC")"
band_of()         { printf 'normal'; }
_drive_count()    { printf '%s' "${1:-none}"; }
_phys_json()      { printf '[]'; }
_link_from_sysfs(){ :; }
NOW=1000 UPTIME=500
# Port 2's firmware bumped to 0x11000000 = 17.00.00.00: a mixed-firmware box is
# the normal state of a box mid-flash, and the firmware verdict hangs off this
# field, so reading row 1 for every card would recommend the wrong image.
sed '0,/ioc1/s/\(ioc1.*\)14000700/\111000000/' fixtures/lsiutil_multi/3card/banner.txt > "$STUBDIR/banner_mixed.txt"
hba_query() {
    case "$1" in
        -b)  cat fixtures/lsiutil_multi/3card/board.txt ;;
        -p*) cat "$STUBDIR/ioc_p${1#-p}.txt" ;;
        *)   cat "$STUBDIR/banner_mixed.txt" ;;
    esac
}
HOUT=$(health_lsiutil)
eq "health: each card its own firmware row" "20.00.07.00 17.00.00.00 20.00.07.00" \
   "$(grep -oE '"fw":"[0-9.]+"' <<< "$HOUT" | cut -d'"' -f4 | tr '\n' ' ' | sed 's/ $//')"
eq "health: one sample per card" "3" "$(grep -o '"fw"' <<< "$HOUT" | wc -l | tr -d ' ')"
eq "health: each card its own temperature" "53 61 59" \
   "$(grep -oE '"temp":[0-9]+' <<< "$HOUT" | cut -d: -f2 | tr '\n' ' ' | sed 's/ $//')"
eq "health: each card its own scsi host" "1 2 3" \
   "$(grep -oE '"drives":[0-9]+' <<< "$HOUT" | cut -d: -f2 | tr '\n' ' ' | sed 's/ $//')"

# The host-0 fallback is for a ONE-card box, where every disk found IS this
# card's. On a multi-card box an unjoined card must stay unjoined -- reporting
# host 0's drive count and PHYs as this card's is the exact confusion issue #18
# was filed about, and the drives side pins the same rule ("card 3 is unjoined
# and emits nothing"). Health had no equivalent: deleting the [ "$6" = "1" ]
# condition, so the fallback fired on any card count, left every assertion
# above green. _drive_count's stub prints the host it was handed, or "none" for
# an empty one, so host 0 leaking through reads as 0 rather than none.
# lsi_host_for is overridden inside the substitution only: the drives block
# below builds its own join and must not inherit a broken one.
HUNJOINED=$(lsi_host_for() { :; }; health_lsiutil)
eq "health: unjoined cards on a multi-card box do not fall back to host 0" "none none none" \
   "$(grep -oE '"drives":[a-z0-9]+' <<< "$HUNJOINED" | cut -d: -f2 | tr '\n' ' ' | sed 's/ $//')"

# ── drv_lsiutil gives each card only its own drives ─────────────────────────
# Stage 3's sweep is box-wide; without the per-card filter card 1 lists card 2's
# disks and the Drives tab shows every disk under every card.
DSRC="../source/usr/local/emhttp/plugins/hbaviewer/scripts/get_attached_drives.sh"
DIR="../source/usr/local/emhttp/plugins/hbaviewer/scripts"
eval "$(sed -n '/^drv_lsiutil()/,/^}/p'      "$DSRC"
        sed -n '/^_drv_lsiutil_one()/,/^}/p' "$DSRC"
        sed -n '/^lsi_each_card()/,/^}/p'    "$SRC")"
require_binary() { :; }
SCSI="$ROOT/drv/scsi_host"
for h in 1 2; do
    mkdir -p "$SCSI/host$h/device/port-$h:0/end_device-$h:0/target$h:0:0/$h:0:0:0/block/sd$h"
    printf 'mpt2sas' > "$SCSI/host$h/proc_name"
done
hba_query() {
    case "$1" in
        -b)  cat fixtures/lsiutil_multi/3card/board.txt ;;
        -p*) : ;;   # empty -a 42,0 reply -> Stage 3 fallback, the box-wide one
        *)   cat fixtures/lsiutil_multi/3card/banner.txt ;;
    esac
}
DOUT=$( (SYS_SAS_DEVICE="$ROOT/drv/none" SYS_SCSI_HOST="$SCSI" drv_lsiutil) 2>/dev/null )
ctrl() { awk -F'\\},\\{' -v n="$1" '{print $n}' <<< "$DOUT" | grep -oE '/dev/sd[0-9]' | tr '\n' ' ' | sed 's/ $//'; }
eq "drives: card 1 lists only host1's disk" "/dev/sd1" "$(ctrl 1)"
eq "drives: card 2 lists only host2's disk" "/dev/sd2" "$(ctrl 2)"
# Card 3 has no host under this block's SYS_SCSI_HOST, so its PCI join fails.
# On a multi-card box that must emit NOTHING rather than sweeping sysfs
# box-wide and handing this card every disk in the machine.
eq "drives: card 3 is unjoined and emits nothing" "" "$(ctrl 3)"

# ── lsi_each_card ───────────────────────────────────────────────────────────
# The per-card read: banner and board captured once, ports enumerated, each
# port joined to its own host, callback called per card, output comma-joined.
# Stubs are defined through eval so shellcheck does not see a definition below
# the call sites the real functions serve above (SC2218).
eval "$(sed -n '/^lsi_each_card()/,/^}/p' "$SRC")"
eval 'hba_query() {
    case "$1" in
        -b) cat fixtures/lsiutil_multi/3card/board.txt ;;
        *)  cat fixtures/lsiutil_multi/3card/banner.txt ;;
    esac
}'
eval '_pci_dir_of_host() {
    case "$1" in
        1) printf "/sys/devices/pci0000:80/0000:80:01.0/0000:81:00.0" ;;
        2) printf "/sys/devices/pci0000:80/0000:80:03.0/0000:82:00.0" ;;
        3) printf "/sys/devices/pci0000:80/0000:80:03.2/0000:83:00.0" ;;
    esac
}'
# Reads the CONTENT of the two files it is handed, so a swapped BANNER/BOARD
# argument pair fails here rather than surfacing as a misparse in parse/hba.sh
# five tasks later. Both files are equally "one unique path per run", so the
# name alone cannot tell them apart.
_probe_card() { printf "p=%s hnum=%s pdir=%s n=%s banner=%s board=%s bkind=%s dkind=%s" \
    "$1" "$4" "$5" "$6" "$(basename "$2")" "$(basename "$3")" \
    "$(grep -q 'Chip Vendor' "$2" && echo banner || echo WRONG)" \
    "$(grep -q 'Board Assembly' "$3" && echo board || echo WRONG)"; }

EACH=$(lsi_each_card _probe_card)
eq "each: one entry per card"      "3"   "$(grep -o 'p=' <<<"$EACH" | wc -l | tr -d ' ')"
eq "each: comma-joined"            "2"   "$(grep -o ',p=' <<<"$EACH" | wc -l | tr -d ' ')"
eq "each: ports in banner order"   "1 2 3" \
   "$(grep -oE 'p=[0-9]+' <<<"$EACH" | cut -d= -f2 | tr '\n' ' ' | sed 's/ $//')"
eq "each: each card its own host"  "1 2 3" \
   "$(grep -oE 'hnum=[0-9]*' <<<"$EACH" | cut -d= -f2 | tr '\n' ' ' | sed 's/ $//')"
eq "each: each card its own pci dir" "0000:81:00.0 0000:82:00.0 0000:83:00.0" \
   "$(grep -oE 'pdir=[^ ]*' <<<"$EACH" | cut -d= -f2 | xargs -n1 basename | tr '\n' ' ' | sed 's/ $//')"
eq "each: port count passed through" "3 3 3" \
   "$(grep -oE 'n=[0-9]+' <<<"$EACH" | cut -d= -f2 | tr '\n' ' ' | sed 's/ $//')"
# The banner and the board table list every port in one call, so they are
# captured ONCE and handed to every card -- health read the banner twice per
# request before this existed.
# [^, ] not [^ ]: cards are joined with a bare comma, and board= is the last
# field of a record, so a space-only class runs straight into the next card.
eq "each: same banner file for every card" "1" \
   "$(grep -oE 'banner=[^, ]*' <<<"$EACH" | sort -u | wc -l | tr -d ' ')"
eq "each: same board file for every card"  "1" \
   "$(grep -oE 'board=[^, ]*' <<<"$EACH" | sort -u | wc -l | tr -d ' ')"
eq "each: the banner position holds the banner" "banner banner banner" \
   "$(grep -oE 'bkind=[^, ]*' <<<"$EACH" | cut -d= -f2 | tr '\n' ' ' | sed 's/ $//')"
eq "each: the board position holds the board"   "board board board" \
   "$(grep -oE 'dkind=[^, ]*' <<<"$EACH" | cut -d= -f2 | tr '\n' ' ' | sed 's/ $//')"

echo
[ $fail -eq 0 ] && { echo "multiport: all pass"; exit 0; }
echo "multiport: FAILURES"; exit 1
