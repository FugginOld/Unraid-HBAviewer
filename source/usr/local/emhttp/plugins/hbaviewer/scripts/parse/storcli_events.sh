#!/bin/bash
# Pure filter: `storcli /cN show events` text on stdin -> {"entries":[...]}.
# storcli events carry a human-readable description (nicer than lsiutil's hex).
#   seqNum: 0x00000001
#   Time: Wed Jun  3 20:33:17 2020
#   Code: 0x00000000
#   Event Description: Firmware initialization started (...)
awk '
function val(s){ sub(/^[^:]*:[ \t]*/,"",s); gsub(/[ \t]+$/,"",s); return s }
function emit(){
    if (!first) printf ","
    first=0
    gsub(/\\/,"\\\\",desc); gsub(/"/,"\\\"",desc)
    printf "{\"seq\":\"%s\",\"time\":\"%s\",\"code\":\"%s\",\"description\":\"%s\"}", seq, time, code, desc
}
BEGIN { first=1; have=0; printf "{\"entries\":[" }
/^seqNum:/            { if (have) emit(); seq=val($0); time=""; code=""; desc=""; have=1; next }
have && /^Time:/              { time=val($0) }
have && /^Code:/              { code=val($0) }
have && /^Event Description:/ { desc=val($0) }
END { if (have) emit(); printf "]}" }
'
