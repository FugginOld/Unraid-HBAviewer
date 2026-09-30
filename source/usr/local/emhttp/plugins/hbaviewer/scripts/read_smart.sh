#!/bin/bash
# Read SMART for one device, transport-aware, and emit parse/smart.sh JSON.
#
# -n standby on EVERY bus: a sleeping drive is reported as such, never woken.
# This used to skip the flag when lsblk said TRAN=sas, on the theory that a
# SAS log-page read is electronics-only and spins nothing up. Measured on
# Golem (2026-09-29): `smartctl -a` against a sleeping SAS drive (sdd) had
# emhttpd logging it spun up within a second. The same branch also let an ATA
# passthrough read reach a SATA drive behind a SAS HBA unguarded (issue #10,
# @jac2424's SAS9207-8i), since lsblk reports TRAN=sas for those too.
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
smartctl -n standby -a "$dev" 2>/dev/null | bash "$DIR/parse/smart.sh" "$tran"
