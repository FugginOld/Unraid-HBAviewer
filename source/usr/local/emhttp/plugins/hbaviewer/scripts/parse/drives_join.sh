#!/bin/bash
# Pure parser: join OS-name map with sysfs SAS/PHY map -> drives JSON.
#   $1 osmap : "bus_tgt /dev/sdX"        (drives_osmap.sh, or sysfs fallback)
#   $2 sasmap: "/dev/sdX SAS_ADDR PHY"   (sysfs sas_end_device)
# This join is where the historical drive bugs lived — golden-tested.
awk '
BEGIN { first=1; printf "{\"drives\":[" }
NR==FNR {
    os[$1]=$2; n++; ord[n]=$1
    next
}
{
    sasmap[$1]=$2; phymap[$1]=$3; expmap[$1]=($4 == "." ? "" : $4)
}
END {
    for (i=1; i<=n; i++) {
        key=ord[i]; dev=os[key]
        split(key, p, "_"); bus=p[1]+0; tgt=p[2]+0
        sas=(dev in sasmap) ? sasmap[dev] : ""
        # SATA drives have no sas_end_device entry; target == PHY for direct-attached
        phy=(dev in phymap) ? phymap[dev]+0 : tgt
        expdr=(dev in expmap) ? expmap[dev] : ""
        if (!first) printf ","
        first=0
        printf "{\"bus\":%d,\"target\":%d,\"sas_address\":\"%s\",\"phy\":%d,\"expander\":\"%s\",\"os_name\":\"%s\"}",
            bus, tgt, sas, phy, expdr, dev
    }
    printf "]}"
}
' "$1" "$2"
