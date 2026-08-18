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
    lsi_each_card _ev_one
}
_ev_one() {   # $1 = port; the rest of lsi_each_card's context is unused here
    hba_query -e -p"$1" -a 35,0 2>/dev/null | bash "$DIR/parse/events.sh"
}
# StorCLI2 / SAS4. Two differences from the classic path, both measured:
#   - The ring must be BOUNDED. An unbounded `show events` on a 9600-24i returns
#     62k lines / 1.2 MB and takes 3.6s — the whole log since the board was
#     manufactured. `type=latest=<n>` is the documented bounded form; the archive
#     in /boot is what provides history, so a page read does not need all of it.
#   - Broadcom's *Lite* build has no `show events` at all. It answers
#     "Un-supported command", and there is no substitute: eventloginfo, termlog
#     and get events are all rejected too, and `show alilog` (the one that
#     succeeds) is a config dump with no event ring in it. Say so plainly rather
#     than rendering an empty table that reads like a healthy log.
EVENT_LIMIT="${EVENT_LIMIT:-50}"
ev_storcli2() {
    local out
    out=$(storcli_run /c"$1" show events type=latest="$EVENT_LIMIT" nolog 2>/dev/null)
    case "$out" in
        *'Un-supported command'*|*'Unsupported command'*)
            printf '{"error":"This StorCLI2 build cannot read the firmware event log. The Lite build has no show events command; the full StorCLI2 from Broadcom does."}' ;;
        '') printf '{"error":"No response from StorCLI2 for the event log."}' ;;
        *)  printf '%s' "$out" | bash "$DIR/parse/storcli_events.sh" ;;
    esac
}
hba_each ev_storcli ev_lsiutil ev_storcli2
