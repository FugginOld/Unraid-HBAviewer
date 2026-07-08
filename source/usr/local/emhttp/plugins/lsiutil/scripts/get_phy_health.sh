#!/bin/bash
# PHY health: per-port link state and error counters
# Command: lsiutil -pN -a 20,12,0,0
#   20 = Diagnostics submenu, 12 = Display PHY counters (SAS), 0,0 = quit

LSIUTIL="/usr/local/emhttp/plugins/lsiutil/lsiutil.x86_64"
CFG="/boot/config/plugins/lsiutil/lsiutil.cfg"

PORT=1
[ -f "$CFG" ] && source "$CFG" && PORT="${HBA_PORT:-1}"

if [ ! -x "$LSIUTIL" ]; then
    echo '{"error":"lsiutil binary not found"}'
    exit 1
fi

OUTPUT=$("$LSIUTIL" -p"$PORT" -a 20,12,0,0 2>/dev/null)

if [ -z "$OUTPUT" ] || ! echo "$OUTPUT" | grep -q "Adapter Phy"; then
    echo '{"error":"No PHY data returned. Card may not support this feature."}'
    exit 1
fi

# Parse PHY blocks into JSON.
# Output format (from source):
#   "Adapter Phy 0:  Link Up, No Errors"     <- no error counts follow
#   "Adapter Phy 1:  Link Up"                <- 4 error count lines follow
#     "  Invalid DWord Count            5"
#     "  Running Disparity Error Count  0"
#     "  Loss of DWord Synch Count      0"
#     "  Phy Reset Problem Count        0"
echo "$OUTPUT" | awk '
BEGIN { first=1; cur=-1; printf "{\"phys\":[" }

/^Adapter Phy [0-9]+:/ {
    # Flush previous pending entry (one with error counters)
    if (cur >= 0) {
        if (!first) printf ","
        first=0
        printf "{\"phy\":%d,\"link\":\"%s\",\"inv\":%d,\"disp\":%d,\"sync\":%d,\"reset\":%d}", \
            cur, lnk, inv, disp, sync, rst
    }
    # Start new entry
    match($0, /Phy ([0-9]+):/, a); cur=a[1]+0
    lnk = ($0 ~ /Link Up/) ? "up" : "down"
    inv=0; disp=0; sync=0; rst=0
    if ($0 ~ /No Errors/) {
        if (!first) printf ","
        first=0
        printf "{\"phy\":%d,\"link\":\"%s\",\"inv\":0,\"disp\":0,\"sync\":0,\"reset\":0}", cur, lnk
        cur=-1
    }
    next
}
cur >= 0 && /Invalid DWord/            { inv  = $NF+0 }
cur >= 0 && /Running Disparity/        { disp = $NF+0 }
cur >= 0 && /Loss of DWord/            { sync = $NF+0 }
cur >= 0 && /Phy Reset Problem/ {
    rst = $NF+0
    if (!first) printf ","
    first=0
    printf "{\"phy\":%d,\"link\":\"%s\",\"inv\":%d,\"disp\":%d,\"sync\":%d,\"reset\":%d}", \
        cur, lnk, inv, disp, sync, rst
    cur=-1
}
END {
    if (cur >= 0) {
        if (!first) printf ","
        printf "{\"phy\":%d,\"link\":\"%s\",\"inv\":%d,\"disp\":%d,\"sync\":%d,\"reset\":%d}", \
            cur, lnk, inv, disp, sync, rst
    }
    printf "]}"
}
'
