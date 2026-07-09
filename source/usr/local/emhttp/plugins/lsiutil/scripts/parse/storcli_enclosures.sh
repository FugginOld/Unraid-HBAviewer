#!/bin/bash
# Pure filter: `storcli /cN/eall show all` text on stdin -> {"enclosures":[...]}.
# Product "VirtualSES" = the HBA's own virtual enclosure for direct-attached
# drives (no real expander). A real expander/backplane shows a different product.
awk '
function val(s){ sub(/^[^=]*=[ \t]*/,"",s); gsub(/[ \t]+$/,"",s); return s }
function emit(){
    if (!first) printf ","
    first=0
    direct = (product ~ /VirtualSES/) ? "true" : "false"
    printf "{\"eid\":\"%s\",\"type\":\"%s\",\"vendor\":\"%s\",\"product\":\"%s\",\"slots\":\"%s\",\"drives\":\"%s\",\"state\":\"%s\",\"direct\":%s}", \
        eid, type, vendor, product, slots, drives, state, direct
}
BEGIN { first=1; have=0; printf "{\"enclosures\":[" }
/^Enclosure \/c[0-9]+\/e[0-9]+/ {
    if (have) emit()
    match($0, /e([0-9]+)/, a); eid=a[1]
    type=""; vendor=""; product=""; slots=""; drives=""; state=""; have=1
    next
}
have && /^Enclosure Type =/         { type=val($0) }
have && /^Vendor Identification =/  { vendor=val($0) }
have && /^Product Identification =/ { product=val($0) }
# Properties data row: "  0 OK       16 16  0 ..."  ($1 eid, $2 state, $3 slots, $4 PD)
have && /^[ \t]*[0-9]+[ \t]+[A-Za-z]+[ \t]+[0-9]+[ \t]+[0-9]+[ \t]/ { state=$2; slots=$3; drives=$4 }
END { if (have) emit(); printf "]}" }
'
