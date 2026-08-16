#!/bin/bash
# Pure filter: `storcli /cN show all` text on stdin -> overview JSON, same shape
# as the lsiutil backend (parse/hba.sh). $1 = alert threshold.
# storcli reports no PCIe link or power state, so width/speed/power arrive as
# $4/$5/$6 — the composer reads them from sysfs, which keeps this a pure stdin
# filter with no hardware access.
#
# Feed a captured `storcli /cN show all` to test — no hardware needed.

input=$(cat)
ALERT="${1:-80}"
PHYERR="${2:-0}"    # total sysfs phy error counters for this controller (from composer)
CHIPARG="${3:-}"    # chip name from storcli AdapterType (covers every chipset; no ID map)
PCIEW="${4:-}"      # PCIe link width  (e.g. "x8") — sysfs, read by the composer
PCIES="${5:-}"      # PCIe link speed  (e.g. "Gen3 (8.0 GT/s)") — sysfs, read by the composer
PWRM="${6:-}"       # power mode       (e.g. "Full") — sysfs PCI D-state, ditto

# Injected by the composer, which reads them from sysfs — this file stays a pure
# filter with no hardware access. Defaults are the suppressing values: an
# unstated topology must never read as "internal", and an unstated subvendor
# must never read as generic Broadcom.
TOPOLOGY="${LSI_TOPOLOGY:-unknown}"
SUBVENDOR="${LSI_SUBVENDOR:-}"
CARD_ID="${LSI_CARD_ID:-}"

# First "Key = Value" line for an exact key (anchored, so "Model" != "Model Number")
val() { printf '%s\n' "$input" | grep -m1 -E "^$1[[:space:]]*=" | sed 's/^[^=]*=[[:space:]]*//; s/[[:space:]]*$//'; }

TEMP=$(printf '%s\n' "$input" | grep -m1 'ROC temperature' | grep -oE '[0-9]+' | tail -1)
if [ -z "$TEMP" ]; then
    echo '{"error":"No temperature in storcli output. Check the controller index."}'
    exit 0
fi

# Labels differ between `show` (brief) and `show all`; accept either.
BOARD=$(val "Product Name"); [ -n "$BOARD" ] || BOARD=$(val "Model")
FW=$(val "FW Version");      [ -n "$FW" ]    || FW=$(val "Firmware Version")
# storcli prints "00:c1:00:00" — domain:bus:device:function, colon-separated.
# The lsiutil path emits bus:dev ("c1:00"), so the same card read through the
# two backends printed two different spellings of its own address. Normalise on
# the shorter one, which is what lspci's own listing starts with: take bus and
# device, drop the domain and the function rather than invent a function number
# lsiutil never reports. The full sysfs address is still carried by card_id.
PCI=$(val "PCI Address")
case "$PCI" in
    *:*:*:*) PCI=$(printf '%s' "$PCI" | cut -d: -f2,3) ;;
esac
BIOS=$(val "BIOS Version")
DRIVES=$(val "Physical Drives")
# Chip: prefer storcli's AdapterType (works for any SAS2/SAS3/SAS3.5 chipset);
# fall back to a small device-ID map only if AdapterType wasn't passed.
CHIP="$CHIPARG"
if [ -z "$CHIP" ]; then
    DEVID=$(val "Device Id")
    case "${DEVID,,}" in
        0xac) CHIP="SAS3416" ;;
        0xaf|0xad) CHIP="SAS3408" ;;
        0x97) CHIP="SAS3008" ;;
        0x87) CHIP="SAS2308" ;;
        0x72) CHIP="SAS2008" ;;
        *)    CHIP="" ;;
    esac
fi

# Drive states from the drive-summary table's State column ONLY ($3 of rows like
# "0:0  15 JBOD ..." or " :0  1 UGood ..." where the controller reports no
# enclosure ID). Scanning the whole output would false-match legend text such as
# "UGood-Unconfigured Good|UBad-Unconfigured Bad".
DSTATES=$(printf '%s\n' "$input" | awk '/^[ \t]*[0-9]*:[0-9]+[ \t]/ { print $3 }')

# IT vs IR from drive states, and ONLY where a state actually proves one.
#   Onln/Optl -> IR: those drives are members of a configured RAID volume, so
#                    a RAID layer exists.
#   JBOD      -> IT: JBOD is the state IT firmware reports for a bare disk.
#   UGood/UBad -> NOTHING. "Unconfigured" is equally true of a bare disk on an
#                    IT-only HBA and on an IR card with no arrays. Issue #10:
#                    an IT-flashed SAS9305-16i (a card with no IR firmware in
#                    existence) reports 13x UGood and was being labelled IR.
#                    An empty mode hides the row, which beats a confident lie.
# storcli's brief `show` carries no personality field — `show all` has
# "Enable JBOD" but the overview path never runs it. Checked on the reporter's
# own capture; do not go looking for one here again.
if   printf '%s\n' "$DSTATES" | grep -qiE '^(Onln|Optl)$'; then MODE="IR"
elif printf '%s\n' "$DSTATES" | grep -qiE '^JBOD$';        then MODE="IT"
else MODE=""; fi

# ── Temperature band (absolute, NOT derived from the setting) ────────────────
# Five fixed bands. ALERT no longer means "the temperature that is bad"; it names
# the first band at which the badge complains (see the twin copy in
# parse/hba.sh — keep both copies identical). Cut-points are the card-independent
# ones the maintainer specified; per-card limits are not worth a config knob.
#   normal <=65 | elevated 66-75 | warning 76-85 | alert 86-95 | critical >=96
band_of() {   # $1 = temperature in C -> band name
    if   [ "$1" -le 65 ]; then echo normal
    elif [ "$1" -le 75 ]; then echo elevated
    elif [ "$1" -le 85 ]; then echo warning
    elif [ "$1" -le 95 ]; then echo alert
    else echo critical; fi
}
band_index() { case "$1" in normal) echo 0;; elevated) echo 1;; warning) echo 2;; alert) echo 3;; *) echo 4;; esac; }

TEMP_BAND=$(band_of "$TEMP")
# The configured band = whichever band contains the stored ALERT value. Storing a
# band floor (66/76/86/96) is the normal case; any legacy value (e.g. 80) still
# lands in a sensible band, so no config migration is needed.
CFG_BAND=$(band_of "$ALERT")

# Badge rank: below the configured band = ok, at it = warn, above it = alert.
ti=$(band_index "$TEMP_BAND"); ci=$(band_index "$CFG_BAND")
if   [ "$ti" -gt "$ci" ]; then RANK=2
elif [ "$ti" -eq "$ci" ]; then RANK=1
else RANK=0; fi

if printf '%s\n' "$DSTATES" | grep -qiE '^(Failed|Offln|Missing|UBad|Foreign)$'; then
    [ "$RANK" -lt 2 ] && RANK=2
elif printf '%s\n' "$DSTATES" | grep -qiE '^(Rbld|Rebuild|Copyback)$'; then
    [ "$RANK" -lt 1 ] && RANK=1
fi

# PHY error counters are CUMULATIVE SINCE BOOT and never reset, so ">0" flagged
# every card that had ever seen a transient — a cable reseat months ago pinned a
# healthy controller to amber forever (issue #8: 8 errors on one phy out of 21).
# A failing link produces counts in the thousands to millions, so a floor
# separates the two cases cheaply.
# ponytail: static floor. The honest signal is the RATE of change, which needs
# per-read history we don't keep; add that if a real fault ever slips under 100.
PHYERR_FLOOR=100
if [ "${PHYERR:-0}" -ge "$PHYERR_FLOOR" ] && [ "$RANK" -lt 1 ]; then RANK=1; fi

case "$RANK" in 2) STATUS="alert" ;; 1) STATUS="warn" ;; *) STATUS="ok" ;; esac

cat <<EOF
{"temp":$TEMP,"model":"${CHIP}","firmware":"${FW}","bios":"${BIOS}","mode":"${MODE}","drive_count":"${DRIVES}","port_name":"","board_name":"${BOARD}","pci_location":"${PCI}","card_id":"${CARD_ID}","topology":"${TOPOLOGY}","subvendor_id":"${SUBVENDOR}","pcie_width":"${PCIEW}","pcie_speed":"${PCIES}","power_mode":"${PWRM}","alert_threshold":$ALERT,"temp_band":"$TEMP_BAND","cfg_band":"$CFG_BAND","status":"$STATUS"}
EOF
