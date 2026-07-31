#!/bin/bash
# Pure filter: `storcli /cN/eall/sall show all` text on stdin -> {"drives":[...]}.
# storcli gives enclosure/slot, WWN (SAS address), model, size, link directly —
# richer than the lsiutil path (which scraped sysfs). No /dev name (storcli
# doesn't map it); the UI shows what this backend provides.
awk '
function val(s){ sub(/^[^=]*=[ \t]*/,"",s); gsub(/[ \t]+$/,"",s); return s }
function emit(){
    if (!first) printf ","
    first=0
    printf "{\"slot\":\"%s\",\"port\":\"%s\",\"model\":\"%s\",\"serial\":\"%s\",\"state\":\"%s\",\"sas_address\":\"%s\",\"size\":\"%s\",\"link\":\"%s\",\"firmware\":\"%s\"}", \
        (eid == "" ? slot : eid"/"slot), port, model, sn, state, wwn, size, link, fw
}
BEGIN { first=1; have=0; printf "{\"drives\":[" }
/^Drive \/c[0-9]+(\/e[0-9]+)?\/s[0-9]+ :[ \t]*$/ {
    if (have) emit()
    # Enclosure-less controllers address drives /c0/s0 with a blank EID column;
    # enclosure-attached ones use /c0/e0/s0. Capture the two parts separately so
    # the absent EID is an empty string rather than a failed match.
    eid = match($0, /\/e([0-9]+)\//, a) ? a[1] : ""
    match($0, /\/s([0-9]+)[ \t]*:/, b); slot = b[1]
    model=""; sn=""; state=""; wwn=""; size=""; link=""; fw=""; port=""; have=1
    next
}
have && /^[ \t]*[0-9]*:[0-9]+[ \t]/ { state=$3 }   # summary row: EID:Slt DID State ...
have && /^Model Number =/           { model=val($0) }
have && /^SN =/                     { sn=val($0) }
have && /^WWN =/                     { wwn=val($0) }
have && /^Firmware Revision =/       { fw=val($0) }
have && /^Link Speed =/              { link=val($0) }
have && /^Raw size =/                { size=val($0); sub(/ *\[.*/, "", size) }
have && /^Connected Port Number =/   { port=val($0); sub(/[ \t(].*/, "", port) }  # "14(path0)" -> "14"
END { if (have) emit(); printf "]}" }
'
