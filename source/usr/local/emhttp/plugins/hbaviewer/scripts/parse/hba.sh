#!/bin/bash
# Pure parser: overview JSON from three captured lsiutil text blocks.
# Overview genuinely has three sources, so this takes three files, not stdin:
#   $1  ioc     = lsiutil -pN -a 25,2,0,0   (temperature + PCIe + power)
#   $2  banner  = printf '0\n' | lsiutil     (chip model, firmware, port name)
#   $3  board   = lsiutil -b                 (product name, PCI location)
#   $4  alert   = alert threshold (int, for status classification)
#   $5  ident   = lsiutil -pN -a 1,0        (firmware image name -> IT/IR)
#   $6  port    = N, to pick this card's row out of a multi-port banner
#                 (optional; empty takes the first row, as it always did)
#
# No hardware here — feed captured fixtures to test the whole shape.

IOC=$(cat "$1" 2>/dev/null)
BANNER=$(cat "$2" 2>/dev/null)
BOARD=$(cat "$3" 2>/dev/null)
ALERT="${4:-80}"
IDENT=$(cat "$5" 2>/dev/null)
PORTSEL="${6:-}"   # which banner row is this card's; empty = the first one
# The card's own -p number, so the UI can label it "ioc1 (lsiutil -p2)" instead
# of pasting the one port Settings names onto every card (issue #18). Emitted
# only when the caller said which port this is, which keeps the output of a
# caller that does not — every single-card expectation in the suite — unchanged.
PORT_FIELD=""
[ -n "$PORTSEL" ] && PORT_FIELD=" \"port\": $PORTSEL,"

# Injected by the composer, which reads them from sysfs — this file stays a pure
# filter with no hardware access. Defaults are the suppressing values: an
# unstated topology must never read as "internal", and an unstated subvendor
# must never read as generic Broadcom.
TOPOLOGY="${LSI_TOPOLOGY:-unknown}"
SUBVENDOR="${LSI_SUBVENDOR:-}"
CARD_ID="${LSI_CARD_ID:-}"

# ── 1. Temperature (OPTIONAL — many SAS2008/9211 cards have no onboard sensor) ─
TEMP_HEX=$(echo "$IOC" | grep "IOCTemperature:" | grep -oE '0x[0-9A-Fa-f]+' | head -1)
if [ -n "$TEMP_HEX" ]; then TEMP=$((16#${TEMP_HEX#0x})); else TEMP=""; fi
# Some cards (SAS2008 / 9211-8i, issue #17) print the field but have nothing
# behind it: 0x0000 means "not reported", not 0 °C. TEMP_HEX itself stays set —
# the sanity bail below uses it to tell "answered" from "no output at all".
[ "$TEMP" = "0" ] && TEMP=""

parse_hex() { echo "$IOC" | grep "$1" | grep -oE '0x[0-9A-Fa-f]+' | head -1; }

PCIE_WIDTH_HEX=$(parse_hex "PCIeWidth:")
case "${PCIE_WIDTH_HEX,,}" in
    0x01) PCIE_WIDTH="x1"  ;; 0x02) PCIE_WIDTH="x2"  ;;
    0x04) PCIE_WIDTH="x4"  ;; 0x08) PCIE_WIDTH="x8"  ;;
    0x10) PCIE_WIDTH="x16" ;; *)    PCIE_WIDTH=""     ;;
esac

# IOUnit Page 7 PCIeSpeed is an ENUM (0,1,2,3,4), unlike PCIeWidth directly
# above it, which is a one-hot bitmask. Reading it as a bitmask reported every
# card one generation low and rendered nothing at all for Gen1 (issue #9).
# Values per mpi2_cnfg.h MPI2_IOUNITPAGE7_PCIE_SPEED_*. Compared numerically so
# a firmware that pads the field (0x0002) decodes the same as 0x02.
# Keep in sync with the same table in scripts/get_hba_health.sh.
PCIE_SPEED_HEX=$(parse_hex "PCIeSpeed:")
PCIE_SPEED=""
if [ -n "$PCIE_SPEED_HEX" ]; then
    case "$((16#${PCIE_SPEED_HEX#0x}))" in
        0) PCIE_SPEED="Gen1 (2.5 GT/s)"  ;;
        1) PCIE_SPEED="Gen2 (5.0 GT/s)"  ;;
        2) PCIE_SPEED="Gen3 (8.0 GT/s)"  ;;
        3) PCIE_SPEED="Gen4 (16.0 GT/s)" ;;
        4) PCIE_SPEED="Gen5 (32.0 GT/s)" ;;
    esac
fi

POWER_HEX=$(parse_hex "CurrentPowerMode:")
case "${POWER_HEX,,}" in
    0x00) POWER_MODE="Full"    ;;
    0x08) POWER_MODE="Reduced" ;;
    0x10) POWER_MODE="Standby" ;;
    *)    POWER_MODE=""        ;;
esac

# ── 2. Banner: chip model, firmware, port name ──────────────────────────────
# The banner lists EVERY port lsiutil found, one row each, so on a multi-card
# box the row must be picked by port number rather than taken first (issue #18).
# $6 is optional and defaults to the historic first-row behaviour, which is what
# keeps every single-card fixture byte-identical.
if [ -n "$PORTSEL" ]; then
    CARD_LINE=$(echo "$BANNER" | grep -E "^[[:space:]]+${PORTSEL}\.[[:space:]]+ioc" | head -1)
else
    CARD_LINE=$(echo "$BANNER" | grep -E "^\s+[0-9]+\.\s+ioc" | head -1)
fi
MODEL=$(echo "$CARD_LINE"     | grep -oE 'SAS[0-9]+[A-Za-z0-9]*' | head -1)
PORT_NAME=$(echo "$CARD_LINE" | awk '{print $2}')

# Firmware: the banner prints the version as four packed HEX bytes, so
# "14000700" is 0x14.0x00.0x07.0x00 = 20.00.07.00 — a P20 card. lsiutil itself
# confirms the decode when you pick menu option 1:
#   "Current active firmware version is 14000700 (20.00.07)"
#   "Firmware image's version is MPTFW-20.00.07.00-IT"
# Splitting the digits as decimal reported that P20 card as "14.00.07.00" and
# then falsely flagged it pre-P20 in the UI.
FW_RAW=$(echo "$CARD_LINE" | grep -oE '[0-9a-f]{8}' | head -1)
if [ -n "$FW_RAW" ]; then
    FW_MAJOR=$((16#${FW_RAW:0:2}))
    FW_VER=$(printf '%02d.%02d.%02d.%02d' "$FW_MAJOR" \
        "$((16#${FW_RAW:2:2}))" "$((16#${FW_RAW:4:2}))" "$((16#${FW_RAW:6:2}))")
else
    FW_MAJOR=""
    FW_VER="Unknown"
fi

# ── Firmware personality (IT vs IR) ──────────────────────────────────────────
# lsiutil main-menu option 1 names the flashed firmware image, and the suffix
# IS the personality:
#   "Firmware image's version is MPTFW-20.00.07.00-IT"
# Anchored on that exact sentence and on the END of the token, NOT a bare grep
# for "IT" — the same block prints "MPT2BIOS-..." and free text ("LSI Logic",
# "Not Packaged Yet"), and a loose match would call every card IT. A port that
# does not exist prints "ERROR:  No such port." with no MPTFW line at all, and
# must yield "" so the UI hides the row rather than inventing a mode.
MODE=$(printf '%s\n' "$IDENT" \
    | grep -m1 -oE "Firmware image's version is MPTFW-[0-9.]+-(IT|IR)" \
    | grep -oE '(IT|IR)$')

# ── 3. Board: product name, PCI location ────────────────────────────────────
# `lsiutil -b` lists EVERY port in one call, in the same order as the banner
# (issue #18's bundles show ioc0/ioc1/ioc2 in both), so this card's row is the
# PORTSEL'th one — no per-port -b capture needed.
if [ -n "$PORTSEL" ]; then
    BOARD_LINE=$(echo "$BOARD" | grep "ioc" | sed -n "${PORTSEL}p")
else
    BOARD_LINE=$(echo "$BOARD" | grep "ioc" | head -1)
fi
# The board name can contain SPACES: a 9400 reads "HBA 9400-16i", where a 9207
# reads "SAS9207-8i". Taking field 5 kept "HBA" and dropped the model, which
# also cost that card its firmware verdict — fw_evaluate cannot match a board
# called "HBA". Column offsets are no help (the Seg/Bus/Dev columns shift by a
# character between a 1-digit and a 3-digit bus), but the name is never
# double-spaced while the gap to the Board Assembly column always is: so take
# everything after the four leading columns, up to the first run of 2+ spaces.
# A card with no assembly or tracer (the 2-card fixture) just runs to the end.
BOARD_NAME=$(echo "$BOARD_LINE" | sed -E 's/^[[:space:]]*([^[:space:]]+[[:space:]]+){4}//; s/[[:space:]]{2,}.*$//; s/[[:space:]]+$//')
# The Seg/Bus/Dev columns are DECIMAL, and every other place a PCI address
# appears — sysfs, lspci, the Overview's own storcli path — is hex. Issue #18's
# 3-card box read "129:0" here where lspci says 81:00.0. Converted, not merely
# padded; left verbatim if the column is not a plain number, so an lsiutil that
# prints something else entirely still shows what it printed.
_pcihex() {   # $1 = decimal column
    case "$1" in ''|*[!0-9]*) printf '%s' "$1" ;; *) printf '%02x' "$((10#$1))" ;; esac
}
PCI_BUS=$(_pcihex "$(echo "$BOARD_LINE" | awk '{print $3}')")
PCI_DEV=$(_pcihex "$(echo "$BOARD_LINE" | awk '{print $4}')")

# Not responding at all (no temp, no model, no board) — likely the wrong port.
if [ -z "$TEMP_HEX" ] && [ -z "$MODEL" ] && [ -z "$BOARD_NAME" ]; then
    echo '{"error":"No response from the HBA. Check the lsiutil port in Settings."}'
    exit 0
fi

# Firmware baseline: P20 (major version 20) is the IT-mode standard for SAS2;
# flag anything older (a known ZFS/passthrough headache on 9200s). FW_MAJOR is
# the decoded hex byte from above — not re-derived from the formatted string,
# whose zero padding ("09") would land in test's integer parsing.
FW_OLD="false"
case "$FW_MAJOR" in ''|*[!0-9]*) : ;; *) [ "$FW_MAJOR" -lt 20 ] && FW_OLD="true" ;; esac

# ── 4. Status (temp-based when a sensor exists; otherwise ok — no false alarm) ─
# Five fixed bands. ALERT no longer means "the temperature that is bad"; it names
# the first band at which the badge complains (see the twin copy in
# parse/storcli_overview.sh — keep both copies identical). Cut-points are the
# card-independent ones the maintainer specified; per-card limits are not worth
# a config knob.
#   normal <=65 | elevated 66-75 | warning 76-85 | alert 86-95 | critical >=96
band_of() {   # $1 = temperature in C -> band name
    if   [ "$1" -le 65 ]; then echo normal
    elif [ "$1" -le 75 ]; then echo elevated
    elif [ "$1" -le 85 ]; then echo warning
    elif [ "$1" -le 95 ]; then echo alert
    else echo critical; fi
}
band_index() { case "$1" in normal) echo 0;; elevated) echo 1;; warning) echo 2;; alert) echo 3;; *) echo 4;; esac; }

CFG_BAND=$(band_of "$ALERT")
if [ -n "$TEMP" ]; then
    TEMP_BAND=$(band_of "$TEMP")
    ti=$(band_index "$TEMP_BAND"); ci=$(band_index "$CFG_BAND")
    if   [ "$ti" -gt "$ci" ]; then STATUS="alert"
    elif [ "$ti" -eq "$ci" ]; then STATUS="warn"
    else STATUS="ok"; fi
    TEMPJSON="$TEMP"
else
    # No sensor (many SAS2008/9211 cards): no band, and never a false alarm.
    STATUS="ok"; TEMPJSON='""'; TEMP_BAND=""
fi

cat <<EOF
{
  "temp": $TEMPJSON,
  "model": "${MODEL:-Unknown}",
  "firmware": "${FW_VER}",
  "mode": "${MODE}",
  "fw_old": $FW_OLD,
  "port_name": "${PORT_NAME:-ioc0}",${PORT_FIELD}
  "board_name": "${BOARD_NAME:-}",
  "pci_location": "${PCI_BUS:-0}:${PCI_DEV:-0}",
  "card_id": "${CARD_ID}",
  "topology": "${TOPOLOGY}",
  "subvendor_id": "${SUBVENDOR}",
  "pcie_width": "${PCIE_WIDTH}",
  "pcie_speed": "${PCIE_SPEED}",
  "power_mode": "${POWER_MODE}",
  "alert_threshold": $ALERT,
  "temp_band": "${TEMP_BAND}",
  "cfg_band": "${CFG_BAND}",
  "status": "$STATUS"
}
EOF
