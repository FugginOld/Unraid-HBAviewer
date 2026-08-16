#!/bin/bash
# Pure filter: lsiutil -e -pN -a 35,0 text on stdin -> event-log JSON.
# Owns the "unsupported", "empty log", and entry-parse cases.
#
#   MPI2 (our card): "Entry    1  Qualifier 0x0001  Data: 00000000 00000000 00000000  Time: 00000000:000012ab"
#   MPI1:            "Entry    1  Qualifier 0x0001  Data: 00000000 00000000 00000000  Time: 000012ab"
#   TABLE:           "0055 8001 5000000000000015 00000001 00120020 00801000 30201000"
#
# The TABLE form is what lsiutil 1.70 actually prints on a SAS2308, under a
# "SeqN Type       Time       Data" header. Only the two Entry/Qualifier forms
# were matched, so every SAS2 box rendered an EMPTY Events tab while its card
# held a full log — brianara3's bundle (issue #18) has "85 Log entries found"
# in the raw capture and `{"entries":[]}` in the parsed JSON beside it. It never
# looked like an error because the guard below passes on the word "log".
# Columns map onto the same four keys the Entry form emits, so the renderer and
# the archive's shape test (event_archive.php keys off `qualifier`) are unchanged:
# SeqN is hex and becomes `seq`, Type becomes `qualifier`, Time becomes
# `timestamp`, and every remaining word is `data`.
# The Data words are NOT decoded. They are an MPI2 log payload whose meaning is
# firmware-private; some carry little-endian ASCII ("736f6942" is "Bios"), which
# is a tempting pattern to over-read. Showing the raw words is honest and is
# what the Entry form has always done.

input=$(cat)
if [ -z "$input" ] || ! grep -qiE "entry|log|no entries" <<<"$input"; then
    echo '{"error":"No event log data returned. Card may not support this feature."}'
    exit 0
fi

if grep -qiE "no entries|log is empty|0 entries|^0 log entries found" <<<"$input"; then
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
# The table form: SeqN and Type are 4 hex digits, Time is one hex word, and
# everything after is payload. Anchored on the two fixed-width columns so the
# banner rows above the table (" 3.  ioc2   LSI Logic SAS2308 ...") cannot match.
$1 ~ /^[0-9a-f]{4}$/ && $2 ~ /^[0-9a-f]{4}$/ && $3 ~ /^[0-9a-f]+$/ {
    seq  = strtonum("0x" $1)
    qual = "0x" $2
    ts   = $3
    data = ""
    for (i = 4; i <= NF; i++) data = data (i > 4 ? " " : "") $i
    if (!first) printf ","
    first=0
    printf "{\"seq\":%d,\"qualifier\":\"%s\",\"data\":\"%s\",\"timestamp\":\"%s\"}", seq, qual, data, ts
}
END { printf "]}" }
' <<<"$input"
