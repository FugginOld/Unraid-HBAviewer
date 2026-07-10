#!/bin/bash
# Pure filter: /proc/diskstats on stdin, $1 = space-separated device allowlist
# (e.g. "sdb sdc"). Emits one raw cumulative-counter snapshot per allowed device:
#   {"drives":[{"dev","r_io","r_sect","w_io","w_sect","io_ticks","weighted"}]}
# Columns are absolute /proc/diskstats fields: 4 reads, 6 sectors read, 8 writes,
# 10 sectors written, 13 io_ticks (ms busy), 14 weighted io (ms). Throughput/IOPS/
# %util/latency are computed by the browser from the delta between two snapshots —
# this script only reports the counters, so it stays instant and stateless.
awk -v allow="$1" '
BEGIN {
    n = split(allow, a, " ")
    for (i = 1; i <= n; i++) if (a[i] != "") want[a[i]] = 1
    printf "{\"drives\":["
    first = 1
}
$3 in want {
    if (!first) printf ","
    first = 0
    printf "{\"dev\":\"%s\",\"r_io\":%d,\"r_sect\":%d,\"w_io\":%d,\"w_sect\":%d,\"io_ticks\":%d,\"weighted\":%d}", \
        $3, $4, $6, $8, $10, $13, $14
}
END { printf "]}" }
'
