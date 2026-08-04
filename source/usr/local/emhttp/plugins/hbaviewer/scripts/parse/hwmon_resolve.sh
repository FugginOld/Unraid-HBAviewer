#!/bin/bash
# scripts/parse/hwmon_resolve.sh — chip label [sysfs-root] -> millidegrees on
# stdout, exit 0; or nothing on stdout, exit 1, when not found.
#
# Rescans sysfs every call rather than trusting a saved path: hwmon indices are
# reassigned by driver probe order and are not stable across reboots (plan
# 029), so the ONLY safe way to find "the sensor the user picked" is to redo
# the chip/label match against however sysfs is laid out right now.
#
# Never falls back to a different sensor when the match fails — that would
# silently show a Delta computed from whatever sensor happened to be first,
# which is the single worst failure mode this feature can have. Not found
# means not found.

CHIP="$1"
LABEL="$2"
ROOT="${3:-/sys/class/hwmon}"
[ -n "$CHIP" ] && [ -n "$LABEL" ] || exit 1

line=$(bash "$(dirname "$0")/hwmon_list.sh" "$ROOT" | awk -F'\t' -v c="$CHIP" -v l="$LABEL" '$1 == c && $2 == l { print; exit }')
[ -n "$line" ] || exit 1
printf '%s\n' "$line" | cut -f4
