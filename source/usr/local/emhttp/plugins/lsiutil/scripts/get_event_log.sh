#!/bin/bash
# Event-log composer: query the firmware log (expert mode), parse to JSON.
#   -e -a 35,0  = expert mode > Display HBA firmware log entries > quit
DIR="$(dirname "$0")"
source "$DIR/lib.sh"
source "$DIR/config.sh"   # sets PORT, ALERT

require_binary || exit 1
hba_query -e -p"$PORT" -a 35,0 2>/dev/null | bash "$DIR/parse/events.sh"
