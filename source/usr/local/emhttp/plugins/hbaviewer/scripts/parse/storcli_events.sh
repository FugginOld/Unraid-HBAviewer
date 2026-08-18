#!/bin/bash
# Pure filter: `storcli /cN show events` text on stdin -> {"entries":[...]}.
# storcli events carry a human-readable description (nicer than lsiutil's hex).
#   seqNum: 0x00000001
#   Time: Wed Jun  3 20:33:17 2020
#   Code: 0x00000000
#   Event Description: Firmware initialization started (...)
#
# StorCLI2 emits the SAME record and renames exactly one key: "Sequence Number:"
# where the classic tool writes "seqNum:" (decimal rather than hex for seq and
# code, which is display only — both are carried as strings either way). Every
# other line is identical, so this parser serves both backends rather than being
# forked into a storcli2_events.sh that would have to be kept in step.
#
# It cannot report that the tool refused: StorCLI2's *Lite* build has no
# `show events` at all ("Un-supported command"), and recognising that is
# get_event_log.sh's job — a pure filter has no idea what produced its stdin.
awk '
function val(s){ sub(/^[^:]*:[ \t]*/,"",s); gsub(/[ \t]+$/,"",s); return s }
function emit(){
    if (!first) printf ","
    first=0
    gsub(/\\/,"\\\\",desc); gsub(/"/,"\\\"",desc)
    printf "{\"seq\":\"%s\",\"time\":\"%s\",\"code\":\"%s\",\"description\":\"%s\"}", seq, time, code, desc
}
BEGIN { first=1; have=0; printf "{\"entries\":[" }
/^(seqNum|Sequence Number):/ { if (have) emit(); seq=val($0); time=""; code=""; desc=""; have=1; next }
have && /^Time:/              { time=val($0) }
have && /^Code:/              { code=val($0) }
have && /^Event Description:/ { desc=val($0) }
END { if (have) emit(); printf "]}" }
'
