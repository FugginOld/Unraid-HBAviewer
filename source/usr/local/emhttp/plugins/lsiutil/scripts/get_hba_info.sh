#!/bin/bash
# Overview data: temperature, PCIe info, card model, firmware, board name

PLUGIN_DIR="/boot/config/plugins/lsiutil"
LSIUTIL="/usr/local/emhttp/plugins/lsiutil/lsiutil.x86_64"
CFG="$PLUGIN_DIR/lsiutil.cfg"

PORT=1
ALERT=80

if [ -f "$CFG" ]; then
    source "$CFG"
    PORT="${HBA_PORT:-1}"
    ALERT="${ALERT_THRESHOLD:-80}"
fi

if [ ! -x "$LSIUTIL" ]; then
    echo '{"error":"lsiutil binary not found. Re-install the plugin."}'
    exit 1
fi

# ── 1. IOUnit7 page: temperature + PCIe + power info ────────────────────────
IOC_OUTPUT=$("$LSIUTIL" -p"$PORT" -a 25,2,0,0 2>/dev/null)

TEMP_HEX=$(echo "$IOC_OUTPUT" | grep "IOCTemperature:" | grep -oE '0x[0-9A-Fa-f]+' | head -1)
if [ -z "$TEMP_HEX" ]; then
    echo "{\"error\":\"Temperature read failed on port $PORT. Check HBA_PORT in settings.\"}"
    exit 1
fi
TEMP=$((16#${TEMP_HEX#0x}))

parse_hex() { echo "$IOC_OUTPUT" | grep "$1" | grep -oE '0x[0-9A-Fa-f]+' | head -1; }

PCIE_WIDTH_HEX=$(parse_hex "PCIeWidth:")
case "${PCIE_WIDTH_HEX,,}" in
    0x01) PCIE_WIDTH="x1"  ;; 0x02) PCIE_WIDTH="x2"  ;;
    0x04) PCIE_WIDTH="x4"  ;; 0x08) PCIE_WIDTH="x8"  ;;
    0x10) PCIE_WIDTH="x16" ;; *)    PCIE_WIDTH=""     ;;
esac

PCIE_SPEED_HEX=$(parse_hex "PCIeSpeed:")
case "${PCIE_SPEED_HEX,,}" in
    0x01) PCIE_SPEED="Gen1 (2.5 GT/s)" ;;
    0x02) PCIE_SPEED="Gen2 (5.0 GT/s)" ;;
    0x04) PCIE_SPEED="Gen3 (8.0 GT/s)" ;;
    *)    PCIE_SPEED="" ;;
esac

POWER_HEX=$(parse_hex "CurrentPowerMode:")
case "${POWER_HEX,,}" in
    0x00) POWER_MODE="Full"    ;;
    0x08) POWER_MODE="Reduced" ;;
    0x10) POWER_MODE="Standby" ;;
    *)    POWER_MODE=""        ;;
esac

# ── 2. Banner: chip model, firmware revision, port name ─────────────────────
BANNER=$(printf "0\n" | "$LSIUTIL" 2>/dev/null)
CARD_LINE=$(echo "$BANNER" | grep -E "^\s+[0-9]+\.\s+ioc" | head -1)
MODEL=$(echo "$CARD_LINE"    | grep -oE 'SAS[0-9]+[A-Za-z0-9]*' | head -1)
PORT_NAME=$(echo "$CARD_LINE" | awk '{print $2}')

# Firmware: "14000700" → "14.00.07.00"
FW_RAW=$(echo "$CARD_LINE" | grep -oE '[0-9a-f]{8}' | head -1)
if [ -n "$FW_RAW" ]; then
    FW_VER="${FW_RAW:0:2}.${FW_RAW:2:2}.${FW_RAW:4:2}.${FW_RAW:6:2}"
else
    FW_VER="Unknown"
fi

# ── 3. Board manufacturing info: product name, PCI location ─────────────────
BOARD_RAW=$("$LSIUTIL" -b 2>/dev/null)
BOARD_LINE=$(echo "$BOARD_RAW" | grep "ioc" | head -1)
BOARD_NAME=$(echo "$BOARD_LINE" | awk '{print $5}')
PCI_BUS=$(echo "$BOARD_LINE"   | awk '{print $3}')
PCI_DEV=$(echo "$BOARD_LINE"   | awk '{print $4}')

# ── 4. Status ────────────────────────────────────────────────────────────────
if   [ "$TEMP" -ge "$ALERT" ];                then STATUS="alert"
elif [ "$TEMP" -ge $(( ALERT - 10 )) ];       then STATUS="warn"
else STATUS="ok"; fi

cat <<EOF
{
  "temp": $TEMP,
  "model": "${MODEL:-Unknown}",
  "firmware": "${FW_VER}",
  "port_name": "${PORT_NAME:-ioc0}",
  "board_name": "${BOARD_NAME:-}",
  "pci_location": "${PCI_BUS:-0}:${PCI_DEV:-0}",
  "pcie_width": "${PCIE_WIDTH}",
  "pcie_speed": "${PCIE_SPEED}",
  "power_mode": "${POWER_MODE}",
  "port": $PORT,
  "alert_threshold": $ALERT,
  "status": "$STATUS"
}
EOF
