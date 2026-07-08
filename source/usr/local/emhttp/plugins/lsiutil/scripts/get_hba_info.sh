#!/bin/bash
# Overview composer: run the three lsiutil queries, hand the captured text to
# the pure parser. Config/port read stays here (candidate B consolidates later).

DIR="$(dirname "$0")"
source "$DIR/lib.sh"
source "$DIR/config.sh"   # sets PORT, ALERT

require_binary || exit 1

IOC=$(mktemp); BANNER=$(mktemp); BOARD=$(mktemp)
trap 'rm -f "$IOC" "$BANNER" "$BOARD"' EXIT

hba_query -p"$PORT" -a 25,2,0,0 2>/dev/null > "$IOC"
printf '0\n' | hba_query        2>/dev/null > "$BANNER"
hba_query -b                    2>/dev/null > "$BOARD"

bash "$DIR/parse/hba.sh" "$IOC" "$BANNER" "$BOARD" "$ALERT"
