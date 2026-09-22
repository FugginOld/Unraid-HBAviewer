#!/bin/bash
# Pure filter: /dev/kmsg text on stdin, one JSON object on stdout.
#
#   bash kmsg.sh [since_seq] < /dev/kmsg
#   {"max_seq":4812,"events":[{"seq":4807,"dev":"sdf","text":"..."}]}
#
# WHY SEQUENCE NUMBERS. The script this replaces counted dmesg lines and
# resumed from the count. A kernel ring buffer WRAPS: the line count stops
# growing while the content keeps changing, so every event after the first wrap
# went unreported -- on exactly the busy, erroring box where they matter.
# /dev/kmsg stamps each record with a monotonic sequence in field 2, which
# survives a wrap and restarts only at boot (where starting from 0 is right).
#
# max_seq is the highest sequence in the INPUT, matched or not. Filtering it to
# matched records would freeze the cursor through every quiet period and
# re-report the same error on the next run that found one.
#
# Continuation lines begin with a space, belong to the record above and carry
# no sequence of their own; they are skipped.
# No hardware access, no environment, no side effects -- the parser contract.
awk -v since="${1:-0}" '
    BEGIN { max = 0; n = 0 }
    /^[ \t]/ { next }
    {
        semi = index($0, ";")
        if (semi == 0) next
        head = substr($0, 1, semi - 1)
        msg  = substr($0, semi + 1)
        split(head, f, ",")
        seq = f[2] + 0
        if (seq > max) max = seq
        if (seq <= since + 0) next
        if (tolower(msg) !~ /critical medium error/) next
        dev = ""
        if (match(msg, /dev [a-z0-9]+/)) dev = substr(msg, RSTART + 4, RLENGTH - 4)
        gsub(/\\/, "\\\\", msg); gsub(/"/, "\\\"", msg)
        gsub(/[\r\n\t]/, " ", msg)
        ev[n++] = "{\"seq\":" seq ",\"dev\":\"" dev "\",\"text\":\"" msg "\"}"
    }
    END {
        out = ""
        for (i = 0; i < n; i++) out = out (i ? "," : "") ev[i]
        printf "{\"max_seq\":%d,\"events\":[%s]}", max, out
    }
'
