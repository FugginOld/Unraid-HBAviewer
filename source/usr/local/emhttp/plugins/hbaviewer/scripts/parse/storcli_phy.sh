#!/bin/bash
# Pure filter: `storcli /cN/pall show` on stdin -> {"phys":[...]}, merged with
# SAS error counters from Linux sysfs (storcli itself doesn't expose them).
#   $1 = sysfs counters file: "<ctrlBaseSAS> <phyIdx> <inv> <disp> <sync> <reset> <neg>"
# Correlation: this controller's base SAS = phy 0's HBASASADDR in the pall table,
# which equals the sysfs sas_address for every phy of that controller.
#
#   PhyNo SASLinkSpeed HBASASADDR       Enbl APhy AhDH AhDevAddr        Port ...
#       0 12.0 Gbps    500605B0105A5B90 Y    0    0017 5000CCA25319FB45 0    ...
# AhDevAddr ($8) = attached device SAS; HBASASADDR ($4) = HBA phy SAS (phy0 = base).

CF="${1:-/dev/null}"

awk -v cf="$CF" '
BEGIN {
    first=1; base=""
    while ((getline line < cf) > 0) {
        n = split(line, f, " ")
        if (n >= 6) cnt[f[1] SUBSEP (f[2]+0)] = f[3] " " f[4] " " f[5] " " f[6]
    }
    printf "{\"phys\":["
}
/^[[:space:]]*[0-9]+[[:space:]]/ && $1 ~ /^[0-9]+$/ {
    phy = $1 + 0
    if ($2 ~ /^[0-9]+\.[0-9]+$/ && $3 == "Gbps") {
        link="up"; speed=$2 " Gbps"; sas=$8; port=$9
    } else {
        link="down"; speed="-"; sas=""; port=""
    }
    if (base == "") base = $4          # first phy HBASASADDR = controller base SAS

    inv=0; disp=0; sync=0; rst=0
    key = base SUBSEP phy
    if (key in cnt) { split(cnt[key], c, " "); inv=c[1]+0; disp=c[2]+0; sync=c[3]+0; rst=c[4]+0 }

    if (!first) printf ","
    first = 0
    printf "{\"phy\":%d,\"link\":\"%s\",\"speed\":\"%s\",\"sas_addr\":\"%s\",\"port\":\"%s\",\"inv\":%d,\"disp\":%d,\"sync\":%d,\"reset\":%d}", \
        phy, link, speed, sas, port, inv, disp, sync, rst
}
END { printf "]}" }
'
