#!/bin/bash
# Grown defect count per disk, keyed by the /dev/disk/by-id name.
#
#   bash get_defects.sh   ->  {"ata-WDC_X":12,"ata-HGST_Y":0}
#
# KEYED BY STABLE ID, not by sd letter: sd letters shift across reboots, so a
# letter-keyed baseline subtracts two different disks from each other and calls
# the difference an increase.
#
# -n standby on every call. A sleeping disk is left asleep and OMITTED from the
# output -- not recorded as 0. Zero is a measurement; absence is not health,
# and a sleeping disk re-entering the baseline at 0 makes its real count look
# like an increase the next time it happens to be awake.
BYID="${DEFECTS_BYID:-/dev/disk/by-id}"

id_of() {  # sd name -> by-id name, or empty
    local dev="$1" link
    for link in "$BYID"/*; do
        [ -e "$link" ] || continue
        case "$(basename "$(readlink -f "$link")")" in
            "$dev") basename "$link"; return 0 ;;
        esac
    done
}

first=1
printf '{'
for dev in $(lsblk -S -d -n -o NAME 2>/dev/null); do
    n="$(smartctl -n standby -a "/dev/$dev" 2>/dev/null \
         | awk '/Elements in grown defect/ { gsub(/[^0-9]/, "", $NF); print $NF; exit }')"
    case "$n" in ''|*[!0-9]*) continue ;; esac
    id="$(id_of "$dev")"
    [ -n "$id" ] || continue
    [ $first -eq 1 ] || printf ','
    first=0
    printf '"%s":%s' "$id" "$n"
done
printf '}'
