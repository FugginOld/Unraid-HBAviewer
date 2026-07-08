#!/bin/bash
# PHY health composer: query the SAS PHY counters, parse to JSON.
#   -a 20,12,0,0  = Diagnostics > Display PHY counters > quit
DIR="$(dirname "$0")"
source "$DIR/lib.sh"
source "$DIR/config.sh"   # sets PORT, ALERT

require_binary || exit 1
hba_query -p"$PORT" -a 20,12,0,0 2>/dev/null | bash "$DIR/parse/phy.sh"
