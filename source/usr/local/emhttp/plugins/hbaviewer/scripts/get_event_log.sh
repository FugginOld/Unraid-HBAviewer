#!/bin/bash
# Event-log composer: query the firmware log (expert mode), parse to JSON.
#   -e -a 35,0  = expert mode > Display HBA firmware log entries > quit
DIR="$(dirname "$0")"
source "$DIR/lib.sh"
source "$DIR/config.sh"   # sets PORT, ALERT

# storcli (SAS3/3.5): firmware event log with human-readable descriptions.
if use_storcli; then
    storcli_each "/c@ show events" "$DIR/parse/storcli_events.sh"
    exit 0
fi

# lsiutil (SAS2): expert-mode log, wrapped as 1-element controllers[].
require_binary || exit 1
printf '{"controllers":['
hba_query -e -p"$PORT" -a 35,0 2>/dev/null | bash "$DIR/parse/events.sh"
printf ']}'
