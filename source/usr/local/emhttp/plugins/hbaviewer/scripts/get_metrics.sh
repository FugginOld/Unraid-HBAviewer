#!/bin/bash
# Performance snapshot composer — the INSTANT path only. Emits raw cumulative
# counters + cached temperature; the browser polls this ~2s, keeps a ring buffer,
# and computes throughput/IOPS/%util/latency/PHY-error-rate from deltas itself.
#
# Touches ONLY instant sources — never a storcli/lsiutil call:
#   /sys/class/scsi_host + /sys/block  drive -> controller map
#   /proc/diskstats                     per-drive IO counters (via parse/diskstats.sh)
#   /sys/class/sas_phy                  PHY error counters
#   /tmp/lsiutil_dash.json (60s cache)  controller temperature
#
#   {"t":<epoch>,"controllers":[
#     {"idx":N,"temp":<n|null>,"phy":{"inv","disp","sync","reset"},
#      "drives":[{"dev","r_io","r_sect","w_io","w_sect","io_ticks","weighted"}]}]}
#
# ponytail: controller idx = position among the SAS scsi_hosts (mpt2sas/mpt3sas),
# the same host order the PHY rollup already assumes. sysfs is instant, so unlike
# the slow storcli enumeration this needs no drivemap cache. Serial-exact
# attribution (per storcli /cN) is the upgrade path if host order ever diverges.

DIR="$(dirname "$0")"

# Ordered SAS host numbers (mpt2sas/mpt3sas) — one per controller.
hosts=()
for h in /sys/class/scsi_host/host*/; do
    [ -d "$h" ] || continue
    case "$(cat "${h}proc_name" 2>/dev/null)" in mpt3sas|mpt2sas|mptsas) ;; *) continue ;; esac
    hn=$(basename "$h"); hosts+=("${hn#host}")
done
# numeric sort so the index order is stable (host2 before host10)
if [ "${#hosts[@]}" -gt 0 ]; then
    IFS=$'\n' hosts=($(printf '%s\n' "${hosts[@]}" | sort -n)); unset IFS
fi

# host number -> controller index
declare -A hidx
for i in "${!hosts[@]}"; do hidx["${hosts[$i]}"]=$i; done

# controller index -> "sdb sdc" (block devices attached to that controller)
cdevs=()
for i in "${!hosts[@]}"; do cdevs[$i]=""; done
for d in /sys/block/sd*; do
    [ -e "$d" ] || continue
    dev=$(basename "$d")
    real=$(readlink -f "$d/device" 2>/dev/null) || continue
    host=$(grep -oE 'host[0-9]+' <<<"$real" | head -1); host=${host#host}
    idx=${hidx[$host]}
    [ -n "$idx" ] || continue          # drive isn't on a SAS HBA
    cdevs[$idx]="${cdevs[$idx]} $dev"
done

# One consistent diskstats snapshot for the whole poll.
DS=$(cat /proc/diskstats 2>/dev/null)

# Controller temperatures from the existing overview cache (no hardware hit).
CACHE="${LSI_CACHE:-/tmp/lsiutil_dash.json}"
temps=($(grep -oE '"temp":[0-9]+' "$CACHE" 2>/dev/null | grep -oE '[0-9]+'))

phy_sum() {   # $1 = host number; echoes "inv disp sync reset"
    local host=$1 inv=0 disp=0 sync=0 reset=0 p v
    for p in /sys/class/sas_phy/phy-"${host}":*/; do
        [ -d "$p" ] || continue
        v=$(cat "$p/invalid_dword_count"           2>/dev/null); inv=$((inv+${v:-0}))
        v=$(cat "$p/running_disparity_error_count" 2>/dev/null); disp=$((disp+${v:-0}))
        v=$(cat "$p/loss_of_dword_sync_count"      2>/dev/null); sync=$((sync+${v:-0}))
        v=$(cat "$p/phy_reset_problem_count"       2>/dev/null); reset=$((reset+${v:-0}))
    done
    echo "$inv $disp $sync $reset"
}

printf '{"t":%s,"controllers":[' "$(date +%s)"
for i in "${!hosts[@]}"; do
    [ "$i" -gt 0 ] && printf ','
    read -r inv disp sync reset <<<"$(phy_sum "${hosts[$i]}")"
    drives=$(bash "$DIR/parse/diskstats.sh" "${cdevs[$i]}" <<<"$DS")   # {"drives":[...]}
    printf '{"idx":%d,"temp":%s,"phy":{"inv":%d,"disp":%d,"sync":%d,"reset":%d},%s' \
        "$i" "${temps[$i]:-null}" "$inv" "$disp" "$sync" "$reset" "${drives#\{}"
done
printf ']}'
