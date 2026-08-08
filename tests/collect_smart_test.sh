#!/bin/bash
# Self-asserting check for collect_smart.sh's capacity normalisation -- the one
# transform in that script. lsblk spells a capacity "7.3T"; storcli spells the
# same thing "7.276 TB", and the bay card prints the number and its unit at
# different sizes, so a bare "T" beside a neighbouring "TB" reads as a
# truncation (issue #15, where the lsiutil backend had no size of its own and
# the cache became the only source).
#
# The expression is lifted out of the script with sed rather than reimplemented
# here: a test carrying its own copy of the regex passes whatever the script
# does.
#   bash tests/collect_smart_test.sh  ->  "collect_smart: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
SRC="../source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh"
fail=0
eq() {  # name  want  got
    if [ "$2" = "$3" ]; then echo "PASS  $1"; else echo "FAIL  $1 -- want '$2', got '$3'"; fail=1; fi
}

EXPR=$(sed -n 's/.*kv "$line" SIZE | \(sed -E .*\))$/\1/p' "$SRC")
[ -n "$EXPR" ] || { echo "FAIL  size normalisation not found in $SRC"; exit 1; }
norm() { printf '%s' "$1" | eval "$EXPR"; }

eq "terabytes gain the B"      "7.3 TB"   "$(norm 7.3T)"
eq "gigabytes gain the B"      "480 GB"   "$(norm 480G)"
eq "petabytes gain the B"      "1.1 PB"   "$(norm 1.1P)"
# Already-spelled-out and unrecognised values pass through untouched rather
# than being mangled -- an unparseable size still has to render as itself.
eq "an already-full unit is left alone" "7.276 TB" "$(norm "7.276 TB")"
eq "an empty size stays empty"          ""         "$(norm "")"
eq "an unrecognised value passes through" "unknown" "$(norm unknown)"

[ $fail -eq 0 ] && echo "collect_smart: all pass"
exit $fail
