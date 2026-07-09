#!/bin/bash
# Pure filter: `smartctl -a /dev/sdX` text on stdin -> SMART summary JSON.
# Targets SAS drives (named fields, no SATA attribute table); also picks up the
# SATA overall-health line. Empty fields mean "not reported" (e.g. drive asleep
# under `-n standby`, or a SATA drive whose attributes we don't parse yet).
awk '
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
    printf "{\"health\":\"%s\",\"temp\":\"%s\",\"trip_temp\":\"%s\",\"power_on_hours\":\"%s\",\"defects\":\"%s\",\"pending\":\"%s\",\"nonmedium\":\"%s\"}", \
        health, temp, trip, poh, defects, pending, nonmed
}
'
