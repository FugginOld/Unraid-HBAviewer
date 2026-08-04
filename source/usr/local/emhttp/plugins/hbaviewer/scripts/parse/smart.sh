#!/bin/bash
# Pure filter: `smartctl -a /dev/sdX` text on stdin -> SMART summary JSON.
# Parses both vocabularies: SAS named fields (health/temp/defect-list log
# pages) and the SATA ATA attribute table, falling back from one to the other
# field-by-field. Empty fields mean "not reported" (e.g. drive asleep under
# `-n standby`).
TRAN="${1:-}"   # "sas" | "sata" | "" — from lsblk, injected by read_smart.sh.
                # A bus guess only now: a SATA drive behind a SAS HBA (issue
                # #10, @jac2424's SAS9207-8i — eight SATA drives, `lsblk`
                # called every one of them TRAN=sas) reports its bus as sas
                # while its own smartctl output is pure ATA. The drive's
                # vocabulary below overrides this; it's the last resort for
                # when neither vocabulary shows up (e.g. a standby read).
awk -v tran="$TRAN" '
function afterColon(s){ sub(/^[^:]*:[ \t]*/,"",s); gsub(/[ \t]+$/,"",s); return s }
BEGIN { health=""; temp=""; trip=""; poh=""; defects=""; pending=""; nonmed="";
        st=""; sp=""; sd=""; spd=""; is_sas=0; is_sata=0 }
# ── SAS: named fields ────────────────────────────────────────────────────────
# These fields only ever appear in SCSI/SAS log-page output, never in an ATA
# passthrough read — so seeing one is proof of the drives own vocabulary,
# not just the bus it happened to be plugged into.
/SMART Health Status:/                       { health=afterColon($0); is_sas=1 }
/SMART overall-health self-assessment/       { n=split($0,a,":"); health=a[n]; gsub(/^[ \t]+|[ \t]+$/,"",health) }
/Current Drive Temperature:/                 { match($0,/([0-9]+)[ \t]*C/,m); temp=m[1]; is_sas=1 }
/Drive Trip Temperature:/                    { match($0,/([0-9]+)[ \t]*C/,m); trip=m[1]; is_sas=1 }
/Accumulated power on time/                  { match($0,/[ \t]([0-9]+):[0-9]+/,m); poh=m[1]; is_sas=1 }
/Elements in grown defect list:/             { match($0,/:[ \t]*([0-9]+)/,m); defects=m[1]; is_sas=1 }
/Pending defect count:/                      { match($0,/count:[ \t]*([0-9]+)/,m); pending=m[1]; is_sas=1 }
/Non-medium error count:/                    { match($0,/:[ \t]*([0-9]+)/,m); nonmed=m[1]; is_sas=1 }
# ── SATA: attribute table (ID NAME FLAG VAL WORST THRESH TYPE UPD WHEN RAW) ───
# RAW_VALUE is $10; its leading number is what we want. A SATA drive behind a
# SAS HBA still emits this exact table — no SCSI fields, ATA attributes only —
# which is the evidence issue #10 confirmed: the table proves ATA regardless
# of what lsblk called the bus.
NF>=10 && $1==5   && $2 ~ /Reallocated_Sector/ { sd=$10; is_sata=1 }
NF>=10 && $1==9   && $2 ~ /Power_On_Hours/      { sp=$10; is_sata=1 }
NF>=10 && $1==194 && $2 ~ /Temperature/         { st=$10; is_sata=1 }
NF>=10 && $1==197 && $2 ~ /Current_Pending/     { spd=$10; is_sata=1 }
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
    #
    # transport: which vocabulary the drive itself spoke, not which bus lsblk
    # thinks it is on. Prefer SAS if somehow both fired (should not happen —
    # a SAS drive has no ATA attribute table); fall back to the injected bus
    # guess only when the drive said nothing at all (e.g. asleep under
    # `-n standby`, which emits almost no SMART data to classify from).
    if (is_sas)        transport = "sas"
    else if (is_sata)  transport = "sata"
    else                transport = tran
    printf "{\"health\":\"%s\",\"temp\":\"%s\",\"trip_temp\":\"%s\",\"power_on_hours\":\"%s\",\"defects\":\"%s\",\"pending\":\"%s\",\"nonmedium\":\"%s\",\"transport\":\"%s\"}", \
        health, temp, trip, poh, defects, pending, nonmed, transport
}
'
