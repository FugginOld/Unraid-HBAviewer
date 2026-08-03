#!/bin/bash
# Pure filter: `smartctl -a /dev/sdX` text on stdin -> SMART summary JSON.
# Parses both vocabularies: SAS named fields (health/temp/defect-list log
# pages) and the SATA ATA attribute table, falling back from one to the other
# field-by-field. Empty fields mean "not reported" (e.g. drive asleep under
# `-n standby`).
TRAN="${1:-}"   # "sas" | "sata" | "" — from lsblk, injected by read_smart.sh
awk -v tran="$TRAN" '
function afterColon(s){ sub(/^[^:]*:[ \t]*/,"",s); gsub(/[ \t]+$/,"",s); return s }
BEGIN { health=""; temp=""; trip=""; poh=""; defects=""; pending=""; nonmed="";
        st=""; sp=""; sd=""; spd="" }
# ── SAS: named fields ────────────────────────────────────────────────────────
/SMART Health Status:/                       { health=afterColon($0) }
/SMART overall-health self-assessment/       { n=split($0,a,":"); health=a[n]; gsub(/^[ \t]+|[ \t]+$/,"",health) }
/Current Drive Temperature:/                 { match($0,/([0-9]+)[ \t]*C/,m); temp=m[1] }
/Drive Trip Temperature:/                    { match($0,/([0-9]+)[ \t]*C/,m); trip=m[1] }
/Accumulated power on time/                  { match($0,/[ \t]([0-9]+):[0-9]+/,m); poh=m[1] }
/Elements in grown defect list:/             { match($0,/:[ \t]*([0-9]+)/,m); defects=m[1] }
/Pending defect count:/                      { match($0,/count:[ \t]*([0-9]+)/,m); pending=m[1] }
/Non-medium error count:/                    { match($0,/:[ \t]*([0-9]+)/,m); nonmed=m[1] }
# ── SATA: attribute table (ID NAME FLAG VAL WORST THRESH TYPE UPD WHEN RAW) ───
# RAW_VALUE is $10; its leading number is what we want.
NF>=10 && $1==5   && $2 ~ /Reallocated_Sector/ { sd=$10 }
NF>=10 && $1==9   && $2 ~ /Power_On_Hours/      { sp=$10 }
NF>=10 && $1==194 && $2 ~ /Temperature/         { st=$10 }
NF>=10 && $1==197 && $2 ~ /Current_Pending/     { spd=$10 }
END {
    if (temp    == "") temp    = st    # fall back to SATA attributes
    if (poh     == "") poh     = sp
    if (defects == "") defects = sd
    if (pending == "") pending = spd
    # `defects` means two different things depending on the bus: grown defects
    # (SAS log page) or reallocated sectors (ATA attribute 5). Both are
    # "sectors the drive permanently retired", which is why one field carries
    # both — but the UI cannot label the column honestly without knowing which
    # bus it came from, so the transport travels with the data.
    printf "{\"health\":\"%s\",\"temp\":\"%s\",\"trip_temp\":\"%s\",\"power_on_hours\":\"%s\",\"defects\":\"%s\",\"pending\":\"%s\",\"nonmedium\":\"%s\",\"transport\":\"%s\"}", \
        health, temp, trip, poh, defects, pending, nonmed, tran
}
'
