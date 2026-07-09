#!/bin/bash
# storcli backend composer. Enumerates every controller and emits the shared
# multi-controller contract:  {"controllers":[<overview>, ...]}
# Each element is parse/storcli_overview.sh output (fixture-tested).
# STORCLI env overrides the binary so tests can point it at a stub.

DIR="$(dirname "$0")"
source "$DIR/lib.sh"             # find_storcli, storcli_each
source "$DIR/config.sh"          # ALERT (PORT unused by storcli)
STORCLI="$(find_storcli)"

[ -n "$STORCLI" ] || {
    echo '{"error":"storcli not found. Install it or set the storcli path."}'; exit 1; }

storcli_each "/c@ show all" "$DIR/parse/storcli_overview.sh" "$ALERT"
