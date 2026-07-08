#!/bin/bash
# HBA firmware event log (requires expert mode: -e)
# Command: lsiutil -e -pN -a 35,0
#   -e = enable expert mode (required for option 35)
#   35 = Display HBA firmware Log entries, 0 = quit

LSIUTIL="/usr/local/emhttp/plugins/lsiutil/lsiutil.x86_64"
CFG="/boot/config/plugins/lsiutil/lsiutil.cfg"

PORT=1
[ -f "$CFG" ] && source "$CFG" && PORT="${HBA_PORT:-1}"

if [ ! -x "$LSIUTIL" ]; then
    echo '{"error":"lsiutil binary not found"}'
    exit 1
fi

OUTPUT=$("$LSIUTIL" -e -p"$PORT" -a 35,0 2>/dev/null)

if [ -z "$OUTPUT" ] || ! echo "$OUTPUT" | grep -qiE "entry|log|no entries"; then
    echo '{"error":"No event log data returned. Card may not support this feature."}'
    exit 1
fi

# Check for empty log
if echo "$OUTPUT" | grep -qi "no entries\|log is empty\|0 entries"; then
    echo '{"entries":[],"note":"Log is empty"}'
    exit 0
fi

# Parse log entries.
# MPI2 format (our card): "Entry    1  Qualifier 0x0001  Data: 00000000 00000000 00000000  Time: 00000000:000012ab"
# MPI1 format:            "Entry    1  Qualifier 0x0001  Data: 00000000 00000000 00000000  Time: 000012ab"
echo "$OUTPUT" | awk '
BEGIN { first=1; printf "{\"entries\":[" }
/Entry[[:space:]]+[0-9]+[[:space:]]+Qualifier/ {
    match($0, /Entry[[:space:]]+([0-9]+)/, seq_a);  seq=seq_a[1]+0
    match($0, /Qualifier (0x[0-9a-f]+)/, q_a);      qual=q_a[1]
    match($0, /Data: ([0-9a-f]+ [0-9a-f]+ [0-9a-f]+)/, d_a); data=d_a[1]
    match($0, /Time: ([0-9a-f:]+)/, t_a);            ts=t_a[1]
    if (!first) printf ","
    first=0
    gsub(/"/, "\\\"", data)
    printf "{\"seq\":%d,\"qualifier\":\"%s\",\"data\":\"%s\",\"timestamp\":\"%s\"}", seq, qual, data, ts
}
END { printf "]}" }
'
