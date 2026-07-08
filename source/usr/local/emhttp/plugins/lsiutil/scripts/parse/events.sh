#!/bin/bash
# Pure filter: lsiutil -e -pN -a 35,0 text on stdin -> event-log JSON.
# Owns the "unsupported", "empty log", and entry-parse cases.
#
#   MPI2 (our card): "Entry    1  Qualifier 0x0001  Data: 00000000 00000000 00000000  Time: 00000000:000012ab"
#   MPI1:            "Entry    1  Qualifier 0x0001  Data: 00000000 00000000 00000000  Time: 000012ab"

input=$(cat)
if [ -z "$input" ] || ! grep -qiE "entry|log|no entries" <<<"$input"; then
    echo '{"error":"No event log data returned. Card may not support this feature."}'
    exit 0
fi

if grep -qi "no entries\|log is empty\|0 entries" <<<"$input"; then
    echo '{"entries":[],"note":"Log is empty"}'
    exit 0
fi

awk '
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
' <<<"$input"
