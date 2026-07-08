#!/bin/bash
# storcli backend composer. Enumerates every controller and emits the shared
# multi-controller contract:  {"controllers":[<overview>, ...]}
# Each element is parse/storcli_overview.sh output (fixture-tested).
# STORCLI env overrides the binary so tests can point it at a stub.

DIR="$(dirname "$0")"
source "$DIR/config.sh"          # ALERT (PORT unused by storcli)
STORCLI="${STORCLI:-storcli}"

command -v "$STORCLI" >/dev/null 2>&1 || [ -x "$STORCLI" ] || {
    echo '{"error":"storcli not found. Install it or set the storcli path."}'; exit 1; }

count=$("$STORCLI" show 2>/dev/null | grep -m1 'Number of Controllers' | grep -oE '[0-9]+')
if [ -z "$count" ] || [ "$count" -eq 0 ]; then
    echo '{"error":"No storcli controllers found."}'; exit 0
fi

printf '{"controllers":['
for c in $(seq 0 $((count - 1))); do
    [ "$c" -gt 0 ] && printf ','
    "$STORCLI" /c"$c" show all 2>/dev/null | bash "$DIR/parse/storcli_overview.sh" "$ALERT"
done
printf ']}'
