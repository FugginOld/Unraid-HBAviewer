#!/bin/bash
# Pure filter: `storcli /cN/pall show` text on stdin -> {"phys":[...]}.
# storcli exposes phy link/speed/attached-device, NOT the SAS error counters
# lsiutil reports — so this backend's phy shape differs (the UI adapts per shape).
#
#   PhyNo SASLinkSpeed HBASASADDR       Enbl APhy AhDH AhDevAddr        Port ...
#       0 12.0 Gbps    500605B0105A5B90 Y    0    0017 5000CCA25319FB45 0    ...
# AhDevAddr ($8) is the attached device's SAS address; Port ($9) its phy port.

awk '
BEGIN { first=1; printf "{\"phys\":[" }
/^[[:space:]]*[0-9]+[[:space:]]/ && $1 ~ /^[0-9]+$/ {
    phy = $1 + 0
    if ($2 ~ /^[0-9]+\.[0-9]+$/ && $3 == "Gbps") {
        link = "up"; speed = $2 " Gbps"; sas = $8; port = $9
    } else {
        # ponytail: down/disabled row layout not in the captures (all phys were
        # up) — inferred as a non-numeric speed field; re-verify against a real
        # down phy if the column positions shift.
        link = "down"; speed = "-"; sas = ""; port = ""
    }
    if (!first) printf ","
    first = 0
    printf "{\"phy\":%d,\"link\":\"%s\",\"speed\":\"%s\",\"sas_addr\":\"%s\",\"port\":\"%s\"}", \
        phy, link, speed, sas, port
}
END { printf "]}" }
'
