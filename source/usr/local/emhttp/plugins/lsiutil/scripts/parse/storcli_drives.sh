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
    printf "{\"slot\":\"%s\",\"model\":\"%s\",\"sas_address\":\"%s\",\"size\":\"%s\",\"link\":\"%s\",\"firmware\":\"%s\"}", \
        eid"/"slot, model, wwn, size, link, fw
}
BEGIN { first=1; have=0; printf "{\"drives\":[" }
/^Drive \/c[0-9]+\/e[0-9]+\/s[0-9]+ :[ \t]*$/ {
    if (have) emit()
    match($0, /e([0-9]+)\/s([0-9]+)/, a); eid=a[1]; slot=a[2]
    model=""; wwn=""; size=""; link=""; fw=""; have=1
    next
}
have && /^Model Number =/     { model=val($0) }
have && /^WWN =/               { wwn=val($0) }
have && /^Firmware Revision =/ { fw=val($0) }
have && /^Link Speed =/        { link=val($0) }
have && /^Raw size =/          { size=val($0); sub(/ *\[.*/, "", size) }
END { if (have) emit(); printf "]}" }
'
