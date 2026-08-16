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
    # One entry per card, in lsi_ports order, so the index join in
    # ajax_info.php lines up with the Overview's controllers[] (issue #18).
    local p first=1
    while read -r p _ _; do
        [ "$first" = 1 ] || printf ','
        first=0
        hba_query -e -p"$p" -a 35,0 2>/dev/null | bash "$DIR/parse/events.sh"
    done < <(lsi_port_map)
}
hba_each ev_storcli ev_lsiutil
