#!/bin/bash
# Pure filter: the get_hba_info.sh cache JSON on stdin -> one line per
# controller, in controller order: the integer temperature, or "null" when that
# controller reports none (no sensor, or a controller-level error object).
#
# Positional greping of "temp" cannot do this. The lsiutil backend pretty-prints
# `"temp": 72` WITH a space, so a `"temp":[0-9]+` pattern silently matches
# nothing on every SAS2 box; and an erroring storcli controller emits no temp key
# at all, so a flat match list shifts every later controller onto the wrong card.
# Walking the controllers array keeps position and value tied together.
#
# ponytail: brace-depth scan, not a real JSON parser — safe because every value
# these two backends emit is a number or a brace-free string. A value containing
# a literal { or } would need a real parser (or jq, which Unraid doesn't ship).
awk '
{ s = s $0 }
END {
    i = index(s, "\"controllers\"")
    if (i == 0) exit
    s = substr(s, i)
    i = index(s, "[")
    if (i == 0) exit
    s = substr(s, i + 1)
    depth = 0; obj = ""
    n = length(s)
    for (j = 1; j <= n; j++) {
        c = substr(s, j, 1)
        if (c == "{") depth++
        if (depth > 0) obj = obj c
        if (c == "}") {
            depth--
            if (depth == 0) { emit(obj); obj = "" }
        }
        if (depth == 0 && c == "]") break
    }
}
function emit(o,   m) {
    if (match(o, /"temp"[ \t]*:[ \t]*[0-9]+/)) {
        m = substr(o, RSTART, RLENGTH)
        sub(/.*:[ \t]*/, "", m)
        print m
    } else print "null"
}'
