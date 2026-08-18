#!/bin/bash
# Pure filter: `storcli2 /cN/eall/sall show all` text on stdin -> {"drives":[...]},
# same shape as parse/storcli_drives.sh plus the two fields StorCLI2 gives that
# the classic tool does not: os_name and temp.
#
# os_name is worth the extra field. ajax_info.php drive_dev_name() already prefers
# it and only falls back to matching serial numbers against lsblk — a lookup that
# cannot work for a drive whose serial the OS reports differently. StorCLI2 states
# it outright ("OS Drive Name = sdf"), so the Device column stops being a guess.
#
# Everything is read from the per-drive "Key = Value" detail block or from
# left-anchored single-token columns. Nothing keys on a column POSITION: the
# summary table's Model column contains spaces ("WDC  WUH722222ALE6L4"), so
# counting fields from the right is unsafe, and counting from the left is only
# safe as far as Status.
awk '
function esc(s){ gsub(/\\/,"\\\\",s); gsub(/"/,"\\\"",s); return s }
function val(s){ sub(/^[^=]*=[ \t]*/,"",s); gsub(/[ \t]+$/,"",s); return s }
function emit(){
    if (!first) printf ","
    first=0
    printf "{\"slot\":\"%s\",\"port\":\"%s\",\"model\":\"%s\",\"serial\":\"%s\",\"state\":\"%s\",\"sas_address\":\"%s\",\"size\":\"%s\",\"link\":\"%s\",\"firmware\":\"%s\",\"os_name\":\"%s\",\"temp\":%s}", \
        (eid == "" ? slot : eid"/"slot), esc(port), esc(model), esc(sn), esc(state), \
        esc(wwn), esc(size), esc(link), esc(fw), esc(osname), (temp == "" ? "null" : temp)
}
BEGIN { first=1; have=0; sec=""; printf "{\"drives\":[" }

# Enclosure-less controllers address drives /c0/s0; enclosure-attached ones use
# /c0/e52/s0. Capture the parts separately so an absent EID is an empty string
# rather than a failed match.
/^Drive \/c[0-9]+(\/e[0-9]+)?\/s[0-9]+[ \t]*:[ \t]*$/ {
    if (have) emit()
    eid = match($0, /\/e([0-9]+)\//, a) ? a[1] : ""
    match($0, /\/s([0-9]+)[ \t]*:/, b); slot = b[1]
    model=""; sn=""; state=""; wwn=""; size=""; link=""; fw=""; port=""; osname=""; temp=""
    have=1; sec="sum"
    next
}
# Section markers. The same keys reappear later in the per-LU/NS properties block
# (Raw size, Logical Sector Size ...), so detail parsing is scoped rather than
# left to "last one wins".
have && /^Drive .* - Detailed Information[ \t]*:/ { sec="det"; next }
have && /^Path Information[ \t]*:/               { sec="path"; next }
have && /^(LU\/NS|Connector Information)/         { sec="other"; next }

# Summary row: "52:0     27 JBOD  Good   -  7.277 TiB ..." — EID:Slt, PID, State,
# Status are single tokens, so $3/$4 are positionally safe.
sec == "sum" && /^[ \t]*[0-9]+:[0-9]+[ \t]/ { state=$3; if ($4 != "") status=$4 }

sec == "det" && /^Model =/                   { model=val($0) }
sec == "det" && /^Serial Number =/           { sn=val($0) }
sec == "det" && /^WWN =/                     { wwn=val($0) }
sec == "det" && /^Firmware Revision Level =/ { fw=val($0) }
# The two StorCLI2 builds disagree on this field: the Lite build prints a bare
# "sdf", Broadcom full prints "/dev/sdf" (measured on the same card, same
# firmware, minutes apart). Normalise by stripping any prefix and putting one
# back — prefixing unconditionally yielded "/dev//dev/sdf", which the Device
# column would have rendered as a path that does not exist.
sec == "det" && /^OS Drive Name =/ {
    osname=val($0); sub(/^\/dev\//, "", osname)
    osname = (osname == "" ? "" : "/dev/" osname)
}
sec == "det" && /^Raw size =/                { size=val($0); sub(/ *\[.*/, "", size) }
sec == "det" && /^Temperature\(C\) =/        { temp=val($0); if (temp !~ /^[0-9]+$/) temp="" }

# Path row: "0x300062b20e23fbdf   27 Primary  1 6.0Gb/s  1" — SASAddress,
# DevicePID, Path, ConnectorIndex, NegotiatedSpeed, NegotiatedLinkWidth.
# The negotiated speed is the honest link figure; "Capable Speed" in the detail
# block is what the drive could do, not what it got.
sec == "path" && /^[ \t]*0x[0-9a-fA-F]+[ \t]/ { if (port == "") { port=$4; link=$5 } }

END { if (have) emit(); printf "]}" }
'
