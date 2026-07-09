#!/bin/bash
# Pure filter: `storcli /cN show all` text on stdin -> overview JSON, same shape
# as the lsiutil backend (parse/hba.sh). $1 = alert threshold.
# PCIe width/speed/power are left empty — storcli doesn't report them; source
# those from lspci if that panel is wanted on SAS3/3.5 cards.
#
# Feed a captured `storcli /cN show all` to test — no hardware needed.

input=$(cat)
ALERT="${1:-80}"

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
PCI=$(val "PCI Address")
BIOS=$(val "BIOS Version")
DRIVES=$(val "Physical Drives")
DEVID=$(val "Device Id")
case "${DEVID,,}" in
    0xac) CHIP="SAS3416" ;;
    0xaf|0xad) CHIP="SAS3408" ;;
    0x97) CHIP="SAS3008" ;;
    0x87) CHIP="SAS2308" ;;
    0x72) CHIP="SAS2008" ;;
    *)    CHIP="" ;;
esac

# IT vs IR from the drive states: JBOD = passthrough (IT); RAID/Onln/Optl = IR.
if   printf '%s\n' "$input" | grep -qE '\bJBOD\b';          then MODE="IT"
elif printf '%s\n' "$input" | grep -qE '\b(Onln|Optl|RAID)\b'; then MODE="IR"
else MODE=""; fi

if   [ "$TEMP" -ge "$ALERT" ];          then STATUS="alert"
elif [ "$TEMP" -ge $(( ALERT - 10 )) ]; then STATUS="warn"
else STATUS="ok"; fi

cat <<EOF
{"temp":$TEMP,"model":"${CHIP}","firmware":"${FW}","bios":"${BIOS}","mode":"${MODE}","drive_count":"${DRIVES}","port_name":"","board_name":"${BOARD}","pci_location":"${PCI}","pcie_width":"","pcie_speed":"","power_mode":"","alert_threshold":$ALERT,"status":"$STATUS"}
EOF
