#!/bin/bash
# One hba_each run must enumerate the controllers ONCE.
#
# `storcli show` -- the global enumeration with no /cN -- was measured at 3-7s
# on a 9300-16i (against 0.15s for `/c0 show`), and hba_each ran it twice back
# to back for the same number: use_storcli probed with _storcli_enumerates,
# discarded the count it printed, and storcli_count then asked again. Every
# composer goes through hba_each, so every tab paid it on every open.
#
# Counting invocations, not timing them: a timing assertion on a machine with
# no controller measures the stub, and would pass just as happily with the
# duplicate call back in place.
#
#   bash tests/storcli_count_test.sh   ->  "storcli_count: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
SRC="../source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh"
fail=0
eq() {  # name  want  got
    if [ "$2" = "$3" ]; then echo "PASS  $1"; else echo "FAIL  $1 -- want '$2', got '$3'"; fail=1; fi
}

TMP=$(mktemp -d) || exit 2
trap 'rm -rf "$TMP"' EXIT
TALLY="$TMP/calls"

# A counting wrapper in front of the real stub: it records every argument list
# it is asked to run, then delegates. Anything the stub already answers keeps
# answering, so this measures call COUNT without changing behaviour.
cat > "$TMP/storcli" <<EOF
#!/bin/bash
printf '%s\n' "\$*" >> "$TALLY"
exec bash "$(pwd)/stub/storcli" "\$@"
EOF
chmod +x "$TMP/storcli"

STUB_FIX="$(pwd)/fixtures/storcli"; export STUB_FIX
export STORCLI="$TMP/storcli"
# The flavor probe caches in STORCLI_FLAVOR, and a value inherited from this
# shell would skip the `version` call and hide a regression in that cache.
unset STORCLI_FLAVOR STORCLI_COUNT

# shellcheck disable=SC1090
. "$SRC"

# Trivial per-controller function: hba_each's job here is the dispatch, not the
# payload, so this emits the index and nothing else.
one() { printf '{"c":%s}' "$1"; }

out=$(hba_each one 'printf "{\"error\":\"no lsiutil\"}"; return 1')

# The fixture enumerates two controllers, so the dispatch must run twice --
# without this, a broken count of 0 would produce zero enumerations and pass
# the assertion below for entirely the wrong reason.
eq "both controllers dispatched" '{"backend":"storcli","driver":"","controllers":[{"c":0},{"c":1}]}' \
   "$(printf '%s' "$out" | sed 's/"driver":"[^"]*"/"driver":""/')"

enum=$(grep -cx 'show' "$TALLY")
eq "the global enumeration runs exactly once" 1 "$enum"

# The cache must not survive a different binary being resolved. use_storcli
# re-runs the probe, so a second resolution overwrites the count rather than
# inheriting the first one -- the failure mode that made exporting it wrong.
: > "$TALLY"
STORCLI_COUNT=99
use_storcli >/dev/null
eq "re-resolving overwrites a stale count" 2 "$STORCLI_COUNT"

# ── The DISCOVERY path, not the preset one ──────────────────────────────────
# Everything above presets $STORCLI, which takes use_storcli's first branch and
# never reaches the candidate loop. Mutation-testing found that: putting the
# >/dev/null back on the LOOP's probe left every assertion above green. The
# loop is what runs on a real box, where nothing presets the binary.
: > "$TALLY"
unset STORCLI STORCLI_FLAVOR STORCLI_COUNT
PATH="$TMP:$PATH" use_storcli >/dev/null
eq "discovery resolves the binary"    "$TMP/storcli" "$STORCLI"
eq "discovery keeps its count"        2              "$STORCLI_COUNT"
eq "discovery enumerates exactly once" 1             "$(grep -cx 'show' "$TALLY")"

echo "$([ $fail -eq 0 ] && echo 'storcli_count: all pass' || echo 'storcli_count: FAILED')"
exit $fail
