#!/bin/bash
# Background SMART collector. smartctl is slow (~1s/drive) and this walks every
# HBA disk, so it's meant to be launched detached (nohup ... &) by the SMART tab
# endpoint; the tab polls the cache + progress file while this runs.
#
#   /tmp/lsiutil_smart.json           final cache  {"drives":[{dev,serial,model,smart}]}
#   /tmp/lsiutil_smart.json.progress  "12/24" while running (removed when done)
#
# -n standby: a sleeping drive is reported as such, never woken.
# ponytail: model/serial are alnum(+space); emitted into JSON without escaping.
# Add escaping if a drive ever ships a quote/backslash in those fields.

DIR="$(dirname "$0")"
OUT="${LSI_SMART_CACHE:-/tmp/lsiutil_smart.json}"
PROG="$OUT.progress"
TMP="$OUT.tmp"

# HBA disks = SCSI block devices with a WWN (excludes USB sticks / no-WWN).
total=$(lsblk -S -o NAME,WWN -n 2>/dev/null | awk '$2 ~ /^0x/' | wc -l)

printf '{"drives":[' > "$TMP"
i=0
first=1
lsblk -S -o NAME,WWN,SERIAL,MODEL -n 2>/dev/null | awk '$2 ~ /^0x/' | while read -r name wwn serial model; do
    i=$(( i + 1 )); echo "$i/$total" > "$PROG"
    smart=$(bash "$DIR/read_smart.sh" "/dev/$name")
    [ -n "$smart" ] || smart='{}'
    [ "$first" -eq 1 ] || printf ',' >> "$TMP"
    first=0
    printf '{"dev":"/dev/%s","serial":"%s","model":"%s","smart":%s}' \
        "$name" "$serial" "$model" "$smart" >> "$TMP"
done
printf ']}' >> "$TMP"

mv -f "$TMP" "$OUT"
rm -f "$PROG"
