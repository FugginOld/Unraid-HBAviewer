#!/bin/bash
# Pure filter: `storcli2 /cN/eall show all` text on stdin -> {"enclosures":[...]},
# same shape as parse/storcli_enclosures.sh.
#
# Slot and drive counts come from COUNTING the Slot Map rows, not from reading
# them out of the Properties table. That table's columns are
#   EID State DeviceType Slots PD Partner-EID Multipath PS Fans TSs Alms SIM ProdID
# and DeviceType holds "Logical Enclosure" — two words — so Slots is $5 on this
# card and $4 on one whose DeviceType is a single word. Counting rows cannot
# drift that way, and the Slot Map is the same data: one row per slot, PID "-"
# where the slot is empty.
#
# "VirtualSES" as the product = the HBA's own synthetic enclosure for
# direct-attached drives rather than a real expander/backplane. Same rule the
# classic parser uses. Note ARCHITECTURE.md's warning: this says nothing about
# what the enclosure can DO — a VirtualSES can still expose writable slot
# attributes with no LED behind them.
awk '
function esc(s){ gsub(/\\/,"\\\\",s); gsub(/"/,"\\\"",s); return s }
function val(s){ sub(/^[^=]*=[ \t]*/,"",s); gsub(/[ \t]+$/,"",s); return s }
function emit(){
    if (!first) printf ","
    first=0
    direct = (product ~ /VirtualSES/ || name ~ /VirtualSES/) ? "true" : "false"
    printf "{\"eid\":\"%s\",\"type\":\"%s\",\"vendor\":\"%s\",\"product\":\"%s\",\"slots\":\"%s\",\"drives\":\"%s\",\"state\":\"%s\",\"direct\":%s}", \
        eid, esc(type), esc(vendor), esc(product), slots, drives, esc(state), direct
}
BEGIN { first=1; have=0; sec=""; printf "{\"enclosures\":[" }

# Only the bare header opens a new enclosure. "Enclosure /c0/e52 Properties :"
# appears later in the SAME enclosure and would otherwise start a second, empty
# one — so the eid must be the last thing before the colon.
/^Enclosure \/c[0-9]+\/e[0-9]+[ \t]*:[ \t]*$/ {
    if (have) emit()
    match($0, /e([0-9]+)[ \t]*:/, a); eid=a[1]
    type=""; vendor=""; product=""; name=""; state=""; slots=0; drives=0
    have=1; sec="head"
    next
}
have && /^Properties[ \t]*:/           { sec="props";   next }
have && /^Slot Map Information[ \t]*:/ { sec="slotmap"; next }
have && /^Information[ \t]*:/          { sec="info";    next }
have && /^Inquiry Data[ \t]*:/         { sec="inq";     next }
have && /^(EnclSasAddress|Connector Information)[ \t]*:/ { sec="other"; next }

# Properties row: "52 OK Logical Enclosure 24 10 ...". Only $1/$2 are read —
# they are ahead of the variable-width DeviceType, so they cannot shift.
sec == "props" && /^[ \t]*[0-9]+[ \t]+[A-Za-z]/ { state=$2 }

# Slot Map row: "EID SID SlotStatus PID", PID "-" for an empty slot.
sec == "slotmap" && /^[ \t]*[0-9]+[ \t]+[0-9]+[ \t]/ { slots++; if ($4 != "-" && $4 != "") drives++ }

sec == "info" && /^Type =/       { type=val($0) }
sec == "info" && /^Name =/       { name=val($0) }
sec == "inq"  && /^Vendor Id =/  { vendor=val($0) }
sec == "inq"  && /^Product Id =/ { product=val($0) }

END { if (have) emit(); printf "]}" }
'
