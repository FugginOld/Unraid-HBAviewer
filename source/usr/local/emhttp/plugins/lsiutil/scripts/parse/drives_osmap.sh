#!/bin/bash
# Pure filter: lsiutil -pN -a 42,0 text on stdin -> "bus_tgt /dev/sdX" lines.
# The authoritative OS-device list; joined with sysfs SAS/PHY in drives_join.sh.
awk '
/\/dev\/[a-z]/ {
    bus=0; tgt=0; dev=""; n=0
    for (i=1;i<=NF;i++) {
        if ($i ~ /^\/dev\//) { dev=$i }
        else if ($i ~ /^[0-9]+$/) { n++; if (n==1) bus=$i+0; else if (n==2) tgt=$i+0 }
    }
    if (dev != "") printf "%d_%d %s\n", bus, tgt, dev
}'
