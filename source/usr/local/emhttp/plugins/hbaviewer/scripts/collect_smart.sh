#!/bin/bash
# Background SMART collector. smartctl is slow (~1s/drive) and this walks every
# HBA disk, so it's meant to be launched detached (nohup ... &) by the SMART tab
# endpoint; the tab polls the cache + progress file while this runs.
#
#   /tmp/lsiutil_smart.json           final cache  {"drives":[{dev,serial,model,size,smart}]}
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
# -P (key="value" pairs) not positional columns: a drive with an empty SERIAL
# collapses its column in the padded output, which silently shifted MODEL into
# the serial field and left the model blank.
kv() {   # $1 = lsblk -P line, $2 = key -> the unquoted value
    printf '%s\n' "$1" | sed -n "s/.*\b$2=\"\([^\"]*\)\".*/\1/p"
}

total=$(lsblk -S -P -o NAME,WWN 2>/dev/null | grep -c 'WWN="0x')

printf '{"drives":[' > "$TMP"
i=0
first=1
lsblk -S -P -o NAME,WWN,SERIAL,MODEL,SIZE 2>/dev/null | grep 'WWN="0x' | while IFS= read -r line; do
    name=$(kv "$line" NAME)
    serial=$(kv "$line" SERIAL)
    model=$(kv "$line" MODEL)
    # Capacity, for the backends that do not report one themselves:
    # lsiutil emits no size at all, so the bay map had nothing to print
    # on a 9207-8i (issue #15). "7.3T" from lsblk, digits and a unit.
    # lsblk says "7.3T"; storcli says "7.276 TB". The bay card splits the number
    # from its unit and prints them at different sizes, so a bare "T" next to a
    # neighbouring "TB" reads as a truncation. Normalise to storcli's spelling.
    size=$(kv "$line" SIZE | sed -E 's/^([0-9.]+)([KMGTP])$/\1 \2B/')
    i=$(( i + 1 )); echo "$i/$total" > "$PROG"
    smart=$(bash "$DIR/read_smart.sh" "/dev/$name")
    [ -n "$smart" ] || smart='{}'
    [ "$first" -eq 1 ] || printf ',' >> "$TMP"
    first=0
    printf '{"dev":"/dev/%s","serial":"%s","model":"%s","size":"%s","smart":%s}' \
        "$name" "$serial" "$model" "$size" "$smart" >> "$TMP"
done
printf ']}' >> "$TMP"

mv -f "$TMP" "$OUT"
rm -f "$PROG"
