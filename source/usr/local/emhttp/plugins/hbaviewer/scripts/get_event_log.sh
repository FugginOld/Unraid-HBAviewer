#!/bin/bash
# Event-log composer: declare the per-backend read, let the module dispatch.
#   storcli:  /c<n> show events   (human-readable descriptions)
#   lsiutil:  -e -a 35,0          (expert mode > firmware log > quit)
DIR="$(dirname "$0")"
source "$DIR/lib.sh"
source "$DIR/config.sh"   # sets PORT, ALERT

ev_storcli() { "$STORCLI" /c"$1" show events 2>/dev/null | bash "$DIR/parse/storcli_events.sh"; }
ev_lsiutil() {
    require_binary || return 1
    hba_query -e -p"$PORT" -a 35,0 2>/dev/null | bash "$DIR/parse/events.sh"
}
hba_each ev_storcli ev_lsiutil
