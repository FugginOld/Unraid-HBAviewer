#!/bin/bash
# Pure filter: `storcli2 /cN/pall show all` text on stdin -> {"phys":[...]}, same
# shape as parse/storcli_phy.sh.
#
# The classic backend gets link state from storcli and the four error counters
# from Linux sysfs, because storcli does not report them. That split cannot work
# here: on an eHBA-personality 9600 the kernel registers NO SAS transport class,
# so /sys/class/sas_phy is empty and there is nothing to merge. StorCLI2 reports
# the counters itself, under its own names —
#   InvalDwdCnt RungDispartyErrCnt LosOfDwrdSynCnt PhyResetPrbCnt
# which are exactly the four the schema already carries. So this parser is
# self-contained and takes no sysfs argument.
#
# Sections matter: the same table shapes repeat for the PCIe lanes further down
# ("PCIe Lane Information", "PCIe Phyerrorcounters"), and folding those in would
# invent PHYs that do not exist.
#
# PHY numbering does NOT start at zero. A 24i reports phys 8-31; anything that
# assumes 0..N-1, or uses a phy number as an array index, is wrong here.
awk '
function esc(s){ gsub(/\\/,"\\\\",s); gsub(/"/,"\\\"",s); return s }
BEGIN { n=0; sec=""; printf "{\"phys\":[" }

/^SAS Phy Information[ \t]*:/            { sec="info";   next }
/^SAS Phyerrorcounters Information[ \t]*:/ { sec="err";  next }
/^PCIe /                                  { sec="pcie";  next }
/^[A-Za-z].*[ \t]:[ \t]*$/                { if (sec != "") sec="other" }

# PhyNum PortNum LinkNum State NegSpeed MinSpeed MaxSpeed SupportedSpeeds ConnName SASAddress DevType
# Every column up to SASAddress is a single token (SupportedSpeeds is a
# comma-separated list with no spaces), so positional reads are safe here.
# An unattached phy still reports State=On — "Unknown" in NegSpeed and "-" for
# the address are what actually distinguish it, not the State column.
sec == "info" && /^[ \t]*[0-9]+[ \t]/ {
    p = $1 + 0
    if (!(p in seen)) { seen[p]=1; order[n++]=p }
    port[p] = $2
    conn[p] = $9
    if ($5 ~ /^[0-9.]+Gb\/s$/) {
        up[p] = 1
        # Normalised to the classic backend spelling ("6.0 Gbps") so one UI column
        # does not show two vocabularies depending on which card it came from.
        s = $5; sub(/Gb\/s$/, " Gbps", s); speed[p] = s
        sas[p] = toupper($10); sub(/^0X/, "", sas[p])
    } else {
        up[p] = 0; speed[p] = "-"; sas[p] = ""
    }
    next
}

# PhyNum InvalDwdCnt RungDispartyErrCnt LosOfDwrdSynCnt PhyResetPrbCnt — all numeric.
sec == "err" && /^[ \t]*[0-9]+[ \t]+[0-9]+/ {
    p = $1 + 0
    if (!(p in seen)) { seen[p]=1; order[n++]=p }
    inv[p]=$2+0; disp[p]=$3+0; sync[p]=$4+0; rst[p]=$5+0
    next
}

END {
    for (i = 0; i < n; i++) {
        p = order[i]
        if (i) printf ","
        printf "{\"phy\":%d,\"link\":\"%s\",\"speed\":\"%s\",\"sas_addr\":\"%s\",\"port\":\"%s\",\"conn\":\"%s\",\"inv\":%d,\"disp\":%d,\"sync\":%d,\"reset\":%d}", \
            p, (up[p] ? "up" : "down"), esc(speed[p]), esc(sas[p]), esc(port[p]), esc(conn[p]), \
            inv[p]+0, disp[p]+0, sync[p]+0, rst[p]+0
    }
    printf "]}"
}
'
