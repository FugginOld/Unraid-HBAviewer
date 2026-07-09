#!/bin/bash
# Read SMART for one device, transport-aware, and emit parse/smart.sh JSON.
#   SAS: log-page reads (health/temp/defects) are electronics-only and do NOT
#        spin up the platters, so read even a standby drive.
#   SATA (or unknown): an ATA SMART read can spin the disk up, so respect
#        -n standby and skip a sleeping drive.
#
#   read_smart.sh /dev/sdX
DIR="$(dirname "$0")"
dev="$1"
[ -n "$dev" ] || { echo '{}'; exit 0; }

tran=$(lsblk -dno TRAN "$dev" 2>/dev/null | tr -d ' \n')
if [ "$tran" = "sas" ]; then
    smartctl -a "$dev" 2>/dev/null | bash "$DIR/parse/smart.sh"
else
    smartctl -n standby -a "$dev" 2>/dev/null | bash "$DIR/parse/smart.sh"
fi
