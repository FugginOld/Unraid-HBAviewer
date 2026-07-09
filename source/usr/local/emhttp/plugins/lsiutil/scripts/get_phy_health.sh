#!/bin/bash
# PHY health composer: query the SAS PHY counters, parse to JSON.
#   -a 20,12,0,0  = Diagnostics > Display PHY counters > quit
DIR="$(dirname "$0")"
source "$DIR/lib.sh"
source "$DIR/config.sh"   # sets PORT, ALERT

# storcli (SAS3/3.5): link/speed/attached-SAS per phy, per controller.
if use_storcli; then
    storcli_each "/c@/pall show" "$DIR/parse/storcli_phy.sh"
    exit 0
fi

# lsiutil (SAS2): error counters per phy. Wrapped as a 1-element controllers[].
require_binary || exit 1
printf '{"controllers":['
hba_query -p"$PORT" -a 20,12,0,0 2>/dev/null | bash "$DIR/parse/phy.sh"
printf ']}'
