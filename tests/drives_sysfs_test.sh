#!/bin/bash
# Self-asserting check for get_attached_drives.sh's drv_lsiutil() sysfs stages
# -- Stage 2's SAS-address/PHY join and Stage 3's fallback -- the SAS-transport
# depth bug from issue #14. Neither stage had a test before this plan: reading
# the wrong sysfs class (sas_end_device instead of sas_device) and the
# fixed-depth target glob were both invisible to the suite until a reporter
# hit them on a real SAS9207-8i.
#
# drv_lsiutil is lifted out with sed rather than sourced: the script calls
# hba_each at load and would go looking for real hardware. require_binary and
# hba_query (normally lib.sh) are stubbed so no lsiutil binary is needed.
#   bash tests/drives_sysfs_test.sh   ->  "drives_sysfs: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
SRC="../source/usr/local/emhttp/plugins/hbaviewer/scripts/get_attached_drives.sh"
DIR="$(dirname "$SRC")"
fail=0
eq() {  # name  want  got
    if [ "$2" = "$3" ]; then echo "PASS  $1"; else echo "FAIL  $1 -- want '$2', got '$3'"; fail=1; fi
}

FN=$(sed -n '/^drv_lsiutil()/,/^}/p' "$SRC")
[ -n "$FN" ] || { echo "FAIL  drv_lsiutil not found in $SRC"; exit 1; }
eval "$FN"

require_binary() { :; }
PORT=0

ROOT=$(mktemp -d)
trap 'rm -rf "$ROOT"' EXIT

# ── Fixture A: Stage 2, a real lsiutil OS map is present ────────────────────
SAS="$ROOT/a/sas_device"

# Regression drive: the OS map says target 0; sysfs says phy 3 -- the
# reporter's own pairing on the 9207-8i (issue #14). The old code read
# /sys/class/sas_end_device (the wrong class -- no sas_address or
# phy_identifier live there), got nothing, and the join silently fell back to
# phy == target: wrong for 6 of 8 drives on that box. The address is chosen
# with hex letters in it to also pin the 0x-strip + upper-casing.
mkdir -p "$SAS/end_device-0:0/device/target0:0:0/0:0:0:0/block/sda"
printf '0xaa11bb22cc330044' > "$SAS/end_device-0:0/sas_address"
printf '3'                  > "$SAS/end_device-0:0/phy_identifier"

# No block/ anywhere beneath this end_device (an SES-shaped target). It must
# be skipped from the SAS map -- not emitted with an empty device name, which
# would desync the awk join's field count for every line that follows it.
mkdir -p "$SAS/end_device-0:1/device/target0:0:1/0:0:1:0"
printf '0x1122334455667788' > "$SAS/end_device-0:1/sas_address"
printf '7'                  > "$SAS/end_device-0:1/phy_identifier"

hba_query() {   # raw lsiutil -a 42,0 text, real column shape (fixtures/drives_hbaviewer.txt)
    printf ' B___T___L  Type       Vendor   Product          Rev      OS Device\n'
    printf ' 0   0   0  Disk       ATA      FAKE DISK        0000     /dev/sda\n'
    printf ' 0   1   0  Disk       ATA      FAKE DISK        0000     /dev/sdb\n'
}

OUT=$( (SYS_SAS_DEVICE="$SAS" SYS_SCSI_HOST="$ROOT/a/no_such_scsi_host" drv_lsiutil) 2>"$ROOT/a.err" )

eq "regression: sysfs join uses the real phy (3), not the target id (0), sas_address upper-cased with 0x stripped" \
   '{"bus":0,"target":0,"sas_address":"AA11BB22CC330044","phy":3,"os_name":"/dev/sda"}' \
   "$(printf '%s' "$OUT" | grep -o '{"bus":0,"target":0[^}]*}')"

eq "end_device with no block/ is skipped from the SAS map: sas empty, phy falls back to target" \
   '{"bus":0,"target":1,"sas_address":"","phy":1,"os_name":"/dev/sdb"}' \
   "$(printf '%s' "$OUT" | grep -o '{"bus":0,"target":1[^}]*}')"

# ── Fixture B: Stage 3, lsiutil -a 42,0 returned nothing -- sysfs-only fallback ──
SCSI="$ROOT/b/scsi_host"
mkdir -p "$SCSI/host0/device/port-0:3/end_device-0:3/target0:0:3/0:0:3:0/block/sdc"
printf 'mpt3sas' > "$SCSI/host0/proc_name"

hba_query() { :; }   # empty -a 42,0 reply -> Stage 3 fallback kicks in

OUT2=$( (SYS_SAS_DEVICE="$ROOT/b/no_such_sas_device" SYS_SCSI_HOST="$SCSI" drv_lsiutil) 2>"$ROOT/b.err" )

eq "stage 3 fallback finds the real target id (3) through port-*/end_device-*/, not the trailing-slash-mangled one" \
   '{"bus":0,"target":3,"sas_address":"","phy":3,"os_name":"/dev/sdc"}' \
   "$(printf '%s' "$OUT2" | grep -o '{"bus":0,"target":3[^}]*}')"

eq "stage 3 fallback emits nothing on stderr" "" "$(cat "$ROOT/b.err")"

[ $fail -eq 0 ] && echo "drives_sysfs: all pass"
exit $fail
