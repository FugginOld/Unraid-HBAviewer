#!/bin/bash
# Read SMART for one device, transport-aware, and emit parse/smart.sh JSON.
#
# The branch below is a BUS decision, made from `lsblk -dno TRAN` before any
# smartctl output exists — it cannot know what kind of drive is attached, only
# what bus it enumerated on. That distinction matters: a SATA drive behind a
# SAS HBA (issue #10, @jac2424's SAS9207-8i — all eight of his SATA drives)
# reports TRAN=sas here, same as a real SAS drive, and so takes the "sas"
# branch below with no `-n standby` guard. A known gap, not fixed by this
# plan (plan 046) — nobody has measured whether an ATA passthrough read
# through the SAS layer actually wakes a standby drive.
#
#   SAS bus: log-page reads (health/temp/defects) are electronics-only and do
#        NOT spin up the platters, so read even a standby drive.
#   Non-SAS bus (or unknown): an ATA SMART read can spin the disk up, so
#        respect -n standby and skip a sleeping drive.
#
# The $tran value passed to parse/smart.sh below is only a fallback now — the
# drive's own smartctl vocabulary (ATA attribute table vs. SCSI log pages)
# decides the reported "transport", not this bus guess. See parse/smart.sh.
#
#   read_smart.sh /dev/sdX
DIR="$(dirname "$0")"
dev="$1"
[ -n "$dev" ] || { echo '{}'; exit 0; }

tran=$(lsblk -dno TRAN "$dev" 2>/dev/null | tr -d ' \n')
if [ "$tran" = "sas" ]; then
    smartctl -a "$dev" 2>/dev/null | bash "$DIR/parse/smart.sh" "$tran"
else
    smartctl -n standby -a "$dev" 2>/dev/null | bash "$DIR/parse/smart.sh" "$tran"
fi
