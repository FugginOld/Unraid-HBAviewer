#!/bin/bash
#
# ============================================================================
#  drive-triage.sh  --  Unraid array drive error sweep + media/transport triage
#  v3.0
# ============================================================================
#
#  Built for the Unraid "User Scripts" plugin. Scans every assigned array slot,
#  separates disks that are genuinely faulting from disks carrying harmless
#  lifetime counters, and runs a deep read-only triage on the worst offenders.
#
#  HOW IT TELLS MEDIA FROM PATH
#    SCSI VERIFY makes the drive read and check blocks internally -- no data
#    crosses the SAS link. SCSI READ moves the same blocks over the wire.
#      verify clean + read fails -> TRANSPORT. The platters are fine.
#      verify fails + read fails -> MEDIA. The drive is going.
#
#  WHY IT KEEPS STATE
#    invalid-dword, loss-of-sync and non-medium counts are LIFETIME totals and
#    are nonzero on every healthy SAS drive. A raw number tells you nothing.
#    This script stores counters per disk ID between runs, so the second and
#    later runs report what changed since last time -- which is the number that
#    actually matters. Disks are keyed by serial/ID, not sd letter, because sd
#    letters shift across reboots.
#
#  SAFETY
#    Every operation is read-only. No writes to any device, ever. Drives in
#    standby are left asleep. Aborts if a parity check or rebuild is running.
#
#  INSTALL
#    Settings -> User Scripts -> Add New Script -> paste this in.
#    Edit the CONFIG block, then Run Once or schedule it.
#    Run it twice a few hours apart before trusting the verdicts -- the first
#    run only establishes a baseline.
#
# ============================================================================

# Vendored into HBAviewer from the standalone User Scripts version. The CLI
# contract is unchanged on purpose -- someone with this in User Scripts must be
# able to drop the plugin's copy in and see the same report. Everything the
# plugin needs beyond that is behind --events (see tri_emit).

# ============================================================================
#  CONFIG  -- edit these. User Scripts cannot pass arguments.
# ============================================================================

# Where to write results and keep the counter baseline. Not the flash drive.
OUTDIR="/tmp/hbaviewer/triage"

# Include cache pool members and parity, not just data disks.
INCLUDE_POOLS="no"

# Leave spun-down drives asleep. Recommended for scheduled runs.
SKIP_STANDBY="yes"

# Run the deep triage on flagged disks after the sweep.
AUTO_TRIAGE="no"

# Cap deep triages per execution. Disks are triaged worst-first.
MAX_TRIAGE="3"

# Only triage disks with concrete evidence (failing sectors in the log, md
# errors, uncorrected reads, or counters rising since the last run). Prevents
# wasting the triage budget spot-checking healthy disks.
TRIAGE_EVIDENCE_ONLY="yes"

# SMART short self-test during triage (~2 min per disk).
SHORT_SELFTEST="yes"

# Queue a SMART long self-test on flagged disks. Runs in drive firmware,
# does not block, 15-20h on a 10TB disk.
LONG_SELFTEST="no"

# Full-surface scan during triage. Hours per disk. Never during a parity
# operation or on an array running reduced parity.
SURFACE_SCAN="no"

# Unraid notification when something real is flagged.
NOTIFY="yes"

# Syslog window, e.g. "Sep  9". Note the double space before a single digit.
SYSLOG_SINCE=""

# Skip the run if a parity check, rebuild or mover is active.
ABORT_IF_BUSY="yes"

# Run directories to keep.
KEEP_RUNS="14"

# --- Classification thresholds ---------------------------------------------
# A disk is called a link outlier if its combined invalid-dword + loss-of-sync
# total exceeds BOTH of these: a multiple of the fleet median, and a floor.
# The fleet median is computed live from your own disks each run.
LINK_OUTLIER_FACTOR="10"
LINK_OUTLIER_FLOOR="500"

# Non-medium error count floor before a disk is considered notable.
NONMED_FLOOR="1000"

# Any increase at or above this, since the previous run, counts as "active".
DELTA_FLOOR="10"

# Read chunk size in blocks, and optional pause between chunks in seconds.
CHUNK="2048"
THROTTLE="0"

# ============================================================================
#  END CONFIG
# ============================================================================

set -uo pipefail
VERSION="3.1"
PAD=2000
GAP=100000

DEV_OVERRIDE=""
while [[ $# -gt 0 ]]; do
    # User Scripts invokes with an empty argument. Never abort over an arg.
    [[ -z "$1" ]] && { shift; continue; }
    case "$1" in
        --auto-triage)     AUTO_TRIAGE="yes" ;;
        --no-triage)       AUTO_TRIAGE="no" ;;
        --pools)           INCLUDE_POOLS="yes" ;;
        --no-skip-standby) SKIP_STANDBY="no" ;;
        --all)             TRIAGE_EVIDENCE_ONLY="no" ;;
        --surface)         SURFACE_SCAN="yes" ;;
        --long)            LONG_SELFTEST="yes" ;;
        --since)           SYSLOG_SINCE="$2"; shift ;;
        --out)             OUTDIR="$2"; shift ;;
        --force)           ABORT_IF_BUSY="no" ;;
        --reset-baseline)  RESET_BASELINE="yes" ;;
        /dev/*)            DEV_OVERRIDE="$1" ;;
        -h|--help)         sed -n '2,45p' "$0" | sed 's/^#//'; exit 0 ;;
        *) echo "ignoring unrecognized argument: $1" >&2 ;;
    esac
    shift
done
RESET_BASELINE="${RESET_BASELINE:-no}"

if [[ -t 1 ]]; then
    C_R=$'\033[31m'; C_Y=$'\033[33m'; C_G=$'\033[32m'; C_B=$'\033[1m'; C_0=$'\033[0m'
else
    C_R=""; C_Y=""; C_G=""; C_B=""; C_0=""
fi

case "$OUTDIR" in
    /boot/*) echo "refusing to write to the flash drive. change OUTDIR." >&2; exit 2 ;;
esac
if ! mkdir -p "$OUTDIR" 2>/dev/null; then
    echo "cannot create $OUTDIR -- falling back to /tmp (lost on reboot)" >&2
    OUTDIR="/tmp/drive-triage"; mkdir -p "$OUTDIR" || exit 2
fi
STAMP="$(date +%Y%m%d-%H%M%S)"
RUN="$OUTDIR/$STAMP"
mkdir -p "$RUN" || exit 2
LOG="$RUN/report.txt"
STATE="$OUTDIR/baseline.tsv"
[[ "$RESET_BASELINE" == "yes" ]] && rm -f "$STATE"

log()  { printf '%s\n' "$*" | tee -a "$LOG"; }
info() { log "      $*"; }
ok()   { log "  ok  $*"; }
warn() { log "  !!  ${C_Y}$*${C_0}"; }
bad()  { log "  XX  ${C_R}$*${C_0}"; }
sect() { log ""; log "${C_B}===== $* =====${C_0}"; }
have() { command -v "$1" >/dev/null 2>&1; }

notify() {
    [[ "$NOTIFY" == "yes" ]] || return 0
    local n="/usr/local/emhttp/webGui/scripts/notify"
    [[ -x "$n" ]] || return 0
    "$n" -e "Drive Triage" -s "$1" -d "$2" -i "${3:-warning}" >/dev/null 2>&1
}

# Sum a per-phy SMART counter across all phys instead of taking the first.
sum_field() {
    awk -v pat="$1" '$0 ~ pat { v=$NF; gsub(/[^0-9]/,"",v); if (v != "") s += v }
                     END { print (s=="" ? 0 : s) }' "$2"
}

log "drive-triage v$VERSION"
log "host    : $(hostname)"
log "started : $(date)"
log "results : $RUN"

# ============================================================================
sect "PREFLIGHT"
# ============================================================================

[[ $EUID -eq 0 ]] || { bad "must run as root"; exit 3; }
have smartctl || { bad "smartctl missing"; exit 3; }

HAVE_SG=0
if have sg_verify && have sg_logs; then
    HAVE_SG=1; ok "sg3_utils present -- VERIFY/READ discriminator available"
else
    warn "sg3_utils missing -- can tell you THAT a disk fails, not WHY"
fi

if [[ -s "$STATE" ]]; then
    PREV_AGE="$(( ( $(date +%s) - $(stat -c %Y "$STATE") ) / 3600 ))"
    ok "baseline present, last updated ${PREV_AGE}h ago -- deltas available"
    HAVE_BASELINE=1
else
    warn "no baseline yet. This run only records one."
    warn "Lifetime counters alone cannot tell active faults from old history."
    warn "Run again in a few hours for meaningful deltas."
    HAVE_BASELINE=0
fi

if have mdcmd; then
    mdcmd status > "$RUN/mdcmd.txt" 2>&1
    RESYNC="$(grep -m1 '^mdResync=' "$RUN/mdcmd.txt" | cut -d= -f2)"
    MDSTATE="$(grep -m1 '^mdState=' "$RUN/mdcmd.txt" | cut -d= -f2)"
    info "array state: ${MDSTATE:-unknown}"
    if [[ -n "${RESYNC:-}" && "${RESYNC:-0}" != "0" ]]; then
        if [[ "$ABORT_IF_BUSY" == "yes" ]]; then
            bad "parity check / rebuild in progress -- exiting"
            exit 0
        fi
        warn "parity operation running, ABORT_IF_BUSY off. Proceeding."
    else
        ok "no parity operation in progress"
    fi
fi
if pgrep -f '/usr/local/sbin/mover' >/dev/null 2>&1 && [[ "$ABORT_IF_BUSY" == "yes" ]]; then
    warn "mover running -- deep triage disabled this run"
    AUTO_TRIAGE="no"
fi

STUCK="$(ps -eo stat,pid,comm 2>/dev/null | awk '$1 ~ /^D/ && $3 ~ /smartctl|sg_/' | wc -l)"
if [[ "$STUCK" -gt 0 ]]; then
    bad "$STUCK smartctl/sg process(es) in uninterruptible sleep"
    bad "controller path may be wedged; results below may be unreliable"
    notify "Stuck SMART processes on $(hostname)" "$STUCK in D-state" "alert"
fi

# ============================================================================
sect "ARRAY SLOTS"
# ============================================================================

SLOTFILE="$RUN/slots.tsv"
DISKS_INI="/var/local/emhttp/disks.ini"

if [[ -n "$DEV_OVERRIDE" ]]; then
    printf 'manual\t%s\tDISK_OK\t0\tmanual\n' "$(basename "$DEV_OVERRIDE")" > "$SLOTFILE"
elif [[ -r "$DISKS_INI" ]]; then
    awk -v pools="$INCLUDE_POOLS" '
        /^\[/ { if (name != "") emit(); name=""; dev=""; st=""; err="0"; id="" }
        /^name=/      { v=$0; gsub(/[",]/,"",v); sub(/^name=/,"",v);      name=v }
        /^device=/    { v=$0; gsub(/[",]/,"",v); sub(/^device=/,"",v);    dev=v }
        /^status=/    { v=$0; gsub(/[",]/,"",v); sub(/^status=/,"",v);    st=v }
        /^numErrors=/ { v=$0; gsub(/[",]/,"",v); sub(/^numErrors=/,"",v); err=v }
        /^id=/        { v=$0; gsub(/[",]/,"",v); sub(/^id=/,"",v);        id=v }
        END { if (name != "") emit() }
        function emit() {
            if (dev == "") return
            if (st ~ /DISK_NP$/) return
            if (pools != "yes" && name !~ /^disk[0-9]+$/) return
            if (id == "") id = dev
            printf "%s\t%s\t%s\t%s\t%s\n", name, dev, st, err, id
        }
    ' "$DISKS_INI" > "$SLOTFILE"
    info "found $(wc -l < "$SLOTFILE") assigned slot(s)"
else
    bad "cannot read $DISKS_INI"; exit 3
fi
[[ -s "$SLOTFILE" ]] || { warn "no slots to examine"; exit 0; }

# ============================================================================
sect "SYSLOG HARVEST"
# ============================================================================

CAT_LOG="$RUN/syslog-window.txt"
SRC=()
for f in /var/log/syslog /var/log/syslog.1 /var/log/messages; do
    [[ -r "$f" ]] && SRC+=("$f")
done
if [[ ${#SRC[@]} -gt 0 ]]; then
    if [[ -n "$SYSLOG_SINCE" ]]; then
        cat "${SRC[@]}" 2>/dev/null | awk -v s="$SYSLOG_SINCE" 'index($0,s){f=1} f' > "$CAT_LOG"
        info "window: from \"$SYSLOG_SINCE\" onward"
    else
        cat "${SRC[@]}" > "$CAT_LOG" 2>/dev/null
    fi
else
    dmesg > "$CAT_LOG" 2>/dev/null
    warn "no syslog readable; kernel ring buffer only"
fi
info "$(wc -l < "$CAT_LOG") lines to search"

# ============================================================================
sect "COLLECTING COUNTERS"
# ============================================================================

DATA="$RUN/data.tsv"
: > "$DATA"
declare -A LBS_OF HOST_OF

while IFS=$'\t' read -r name dev status mderr id; do
    [[ -b "/dev/$dev" ]] || continue

    HOST_OF["$dev"]="$(readlink -f "/sys/block/$dev/device" 2>/dev/null | grep -o 'host[0-9]*' | head -1)"
    LBS_OF["$dev"]="$(cat "/sys/block/$dev/queue/logical_block_size" 2>/dev/null || echo 512)"
    host="${HOST_OF[$dev]:-?}"

    grep -E "dev $dev, sector [0-9]+" "$CAT_LOG" 2>/dev/null \
        | grep -o 'sector [0-9]*' | awk '{print $2}' | sort -n -u > "$RUN/sectors-$dev.txt"
    grep -E "\[$dev\].*Sense Key" "$CAT_LOG" 2>/dev/null \
        | grep -o 'Sense Key : 0x[0-9a-f]*' | sort | uniq -c | sort -rn > "$RUN/sense-$dev.txt"
    maxage="$(grep -E "\[$dev\].*cmd_age=" "$CAT_LOG" 2>/dev/null \
        | grep -o 'cmd_age=[0-9]*' | grep -o '[0-9]*' | sort -n | tail -1)"
    # NOTE: grep -c exits 1 when the count is zero, so "|| echo 0" would print
    # a SECOND line and embed a newline in the field. Sanitize instead.
    nsect="$(wc -l < "$RUN/sectors-$dev.txt" 2>/dev/null)"
    skmed="$(grep -c '0x3' "$RUN/sense-$dev.txt" 2>/dev/null)"
    skabt="$(grep -c '0xb' "$RUN/sense-$dev.txt" 2>/dev/null)"
    for v in nsect skmed skabt maxage; do
        eval "x=\${$v:-0}"; x="${x%%$'\n'*}"
        [[ "$x" =~ ^[0-9]+$ ]] || x=0
        eval "$v=$x"
    done

    standby=0
    if [[ "$SKIP_STANDBY" == "yes" ]]; then
        if smartctl -n standby -i -d auto "/dev/$dev" 2>&1 | grep -qi 'STANDBY\|SLEEP'; then
            standby=1
        fi
    fi

    if [[ "$standby" -eq 1 ]]; then
        printf '%s\t%s\t%s\t%s\t%s\t%s\t0\t0\t0\t0\t0\t0\t0\t%s\t%s\t%s\t%s\t1\t0\t-\t-\n' \
            "$name" "$dev" "$id" "$status" "$host" "$mderr" \
            "$nsect" "$skmed" "$skabt" "${maxage:-0}" >> "$DATA"
        continue
    fi

    smartctl -x -d auto -n never "/dev/$dev" > "$RUN/smart-$dev.txt" 2>&1
    S="$RUN/smart-$dev.txt"

    uncorr="$(awk '/^read:/ {print $NF}' "$S" | head -1)"
    grown="$(awk '/Elements in grown defect/ {gsub(/[^0-9]/,"",$NF); print $NF}' "$S" | head -1)"
    nonmed="$(awk '/Non-medium error count/ {gsub(/[^0-9]/,"",$NF); print $NF}' "$S" | head -1)"
    # per-phy counters: SUM across all phys, do not take the first
    invdw="$(sum_field 'Invalid DWORD count' "$S")"
    loss="$(sum_field 'Loss of DWORD synchronization' "$S")"
    # Running disparity is the most diagnostic link counter there is: it means
    # bits arrived corrupted on the wire. Track it separately, not folded in.
    disp="$(sum_field 'Running disparity error count' "$S")"
    phyrst="$(sum_field 'Phy reset problem' "$S")"
    poh="$(awk '/Accumulated power on time/ {print $(NF-1)}' "$S" | head -1)"
    # Which expander port this disk is attached to. Localizes shared faults to
    # a specific lane rather than a whole controller.
    att="$(awk '/attached SAS address/ {print $NF}' "$S" | grep -v '^0x0$' | head -1)"
    attphy="$(awk '/attached phy identifier/ {print $NF}' "$S" | head -1)"
    [[ -z "$att" ]] && att="-"
    [[ "$attphy" =~ ^[0-9]+$ ]] || attphy="-"

    [[ -z "$uncorr" ]] && uncorr="$(awk '/Reallocated_Sector_Ct/ {print $NF}' "$S" | head -1)"
    [[ -z "$grown"  ]] && grown="$(awk '/Current_Pending_Sector/ {print $NF}' "$S" | head -1)"
    [[ "$invdw" == "0" ]] && invdw="$(awk '/UDMA_CRC_Error_Count/ {print $NF}' "$S" | head -1)"
    [[ -z "$poh" ]] && poh="$(awk '/Power_On_Hours/ {print $NF}' "$S" | head -1)"

    for v in uncorr grown nonmed invdw loss disp phyrst poh; do
        eval "x=\${$v:-0}"; [[ "$x" =~ ^[0-9]+$ ]] || x=0; eval "$v=$x"
    done

    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t0\t%s\t%s\t%s\n' \
        "$name" "$dev" "$id" "$status" "$host" "$mderr" \
        "$uncorr" "$grown" "$nonmed" "$invdw" "$loss" "$phyrst" "$poh" \
        "$nsect" "$skmed" "$skabt" "${maxage:-0}" \
        "$disp" "$att" "$attphy" >> "$DATA"
done < "$SLOTFILE"

# Fleet median of combined link errors -- the yardstick for "abnormal"
MEDIAN="$(awk -F'\t' 'NF>=21 && $18==0 && $10 ~ /^[0-9]+$/ {print $10 + $11}' "$DATA" \
    | sort -n | awk '
    { a[NR]=$1 } END { if (NR==0) print 0; else if (NR%2) print a[(NR+1)/2];
                       else print int((a[NR/2] + a[NR/2+1]) / 2) }')"
THRESH=$(( MEDIAN * LINK_OUTLIER_FACTOR ))
[[ "$THRESH" -lt "$LINK_OUTLIER_FLOOR" ]] && THRESH="$LINK_OUTLIER_FLOOR"
info "fleet median link errors: $MEDIAN   outlier threshold: $THRESH"
info "(lifetime counters are nonzero on every healthy SAS drive --"
info " what matters is being far above your own fleet's normal)"

# ============================================================================
sect "SLOT SCAN"
# ============================================================================

log ""
printf '%-7s %-5s %-5s %6s %6s %6s %8s %9s %7s  %s\n' \
    SLOT DEV HOST mdERR UNCORR GROWN NONMED DISPARITY SECTORS VERDICT | tee -a "$LOG"
log "$(printf '%.0s-' $(seq 1 90))"

FLAGGED=()      # score:name:dev:verdict
NONMED_NOTE=()
declare -A PORT_OF PORT_DISP
declare -A VERDICT_OF DELTA_NOTE

while IFS=$'\t' read -r name dev id status host mderr uncorr grown nonmed invdw loss phyrst poh nsect skmed skabt maxage standby disp att attphy; do

    if [[ "$standby" == "1" ]]; then
        printf '%-7s %-5s %-5s %6s %6s %6s %8s %9s %7s  %s\n' \
            "$name" "$dev" "$host" "$mderr" "-" "-" "-" "-" "$nsect" "SLEEPING" | tee -a "$LOG"
        [[ "$nsect" -gt 0 ]] && FLAGGED+=("60:$name:$dev:INVESTIGATE")
        continue
    fi

    linktot=$(( invdw + loss ))

    # --- delta against stored baseline -------------------------------------
    d_uncorr=0; d_grown=0; d_nonmed=0; d_link=0; active=0
    if [[ "$HAVE_BASELINE" -eq 1 ]]; then
        prev="$(awk -F'\t' -v k="$id" '$1==k {print; exit}' "$STATE")"
        if [[ -n "$prev" ]]; then
            IFS=$'\t' read -r _ p_ts p_uncorr p_grown p_nonmed p_invdw p_loss _ <<< "$prev"
            d_uncorr=$(( uncorr - ${p_uncorr:-0} ))
            d_grown=$(( grown - ${p_grown:-0} ))
            d_nonmed=$(( nonmed - ${p_nonmed:-0} ))
            d_link=$(( linktot - ( ${p_invdw:-0} + ${p_loss:-0} ) ))
            [[ $d_uncorr -gt 0 || $d_grown -gt 0 ]] && active=2
            [[ $d_link -ge $DELTA_FLOOR || $d_nonmed -ge $DELTA_FLOOR ]] && active=1
            DELTA_NOTE["$dev"]="since last run: uncorr +$d_uncorr grown +$d_grown nonmed +$d_nonmed link +$d_link"
        fi
    fi

    # --- classification ----------------------------------------------------
    verdict="clean"; score=0
    if [[ "$status" == *DSBL* || "$status" == *INVALID* ]]; then
        verdict="DISABLED"; score=100
    elif [[ "$uncorr" -gt 0 || "$skmed" -gt 0 || "$d_uncorr" -gt 0 || "$d_grown" -gt 0 ]]; then
        verdict="MEDIA"; score=90
    elif [[ "$nsect" -gt 0 ]]; then
        verdict="FAULTING"; score=80
    elif [[ "$active" -ge 1 ]]; then
        verdict="ACTIVE"; score=70
    elif [[ "$mderr" -gt 0 ]]; then
        verdict="MD-ERRORS"; score=60
    elif [[ "$linktot" -gt "$THRESH" ]]; then
        verdict="OUTLIER"; score=40
    fi
    # Non-medium error count alone is NOT evidence. On some HGST/WD firmware it
    # runs into the hundreds of thousands on drives with a spotless link, so it
    # is reported but never promotes a disk on its own.
    if [[ "$verdict" == "clean" && "$nonmed" -ge "$NONMED_FLOOR" ]]; then
        NONMED_NOTE+=("$name ($dev): non-medium $nonmed, link clean -- firmware artifact, not a fault")
    fi
    VERDICT_OF["$dev"]="$verdict"

    case "$verdict" in
        DISABLED|MEDIA)              col="$C_R" ;;
        FAULTING|ACTIVE)             col="$C_R" ;;
        MD-ERRORS|OUTLIER)           col="$C_Y" ;;
        *)                           col="" ;;
    esac

    printf "%-7s %-5s %-5s %6s %6s %6s %8s %9s %7s  ${col}%s${C_0}\n" \
        "$name" "$dev" "$host" "$mderr" "$uncorr" "$grown" \
        "$nonmed" "$disp" "$nsect" "$verdict" | tee -a "$LOG"

    PORT_OF["$dev"]="$att:$attphy"
    if [[ "$att" != "-" && -n "${att:-}" ]]; then
        PORT_DISP["$att"]=$(( ${PORT_DISP[$att]:-0} + disp ))
    fi

    if [[ "$verdict" != "clean" ]]; then
        FLAGGED+=("$score:$name:$dev:$verdict")
        [[ -n "${DELTA_NOTE[$dev]:-}" ]] && echo "        ${DELTA_NOTE[$dev]}" | tee -a "$LOG"
        if [[ "$maxage" -gt 60 ]] 2>/dev/null; then
            echo "        worst cmd_age=${maxage}s -- commands hung over a minute." | tee -a "$LOG"
            echo "        Drive recovery gives up in 7-30s, so this is a timeout," | tee -a "$LOG"
            echo "        not a media retry." | tee -a "$LOG"
        fi
    fi
done < "$DATA"

log ""
log "UNCORR/GROWN = media. DISPARITY = corrupted bits on the wire, the single"
log "most diagnostic link counter. Lifetime totals -- judge against the"
log "threshold above and the delta line, never in isolation."
if [[ ${#NONMED_NOTE[@]} -gt 0 ]]; then
    log ""
    log "high non-medium counts, NOT treated as faults:"
    for n in "${NONMED_NOTE[@]}"; do log "  $n"; done
fi

sect "EXPANDER PORT MAP"
log "which backplane/expander port each disk hangs off, and total disparity"
log "per port. One hot disk on a port is a lane or cable; a whole hot expander"
log "is the cable to it or the expander itself."
log ""
printf '%-7s %-5s %-20s %5s %10s\n' SLOT DEV "ATTACHED SAS ADDR" PHY DISPARITY | tee -a "$LOG"
while IFS=$'\t' read -r name dev id status host mderr uncorr grown nonmed invdw loss phyrst poh nsect skmed skabt maxage standby disp att attphy; do
    [[ "$standby" == "1" ]] && continue
    [[ "$att" == "-" || -z "${att:-}" ]] && continue
    printf '%-7s %-5s %-20s %5s %10s\n' "$name" "$dev" "$att" "$attphy" "$disp" | tee -a "$LOG"
done < "$DATA"
log ""
for a in "${!PORT_DISP[@]}"; do
    log "expander $a  total disparity: ${PORT_DISP[$a]}"
done

# ============================================================================
sect "SHARED-PATH CORRELATION"
# ============================================================================

# Only genuinely abnormal disks count here -- otherwise every host looks bad.
declare -A H_BAD H_TOT H_LIST
while IFS=$'\t' read -r name dev id status host mderr uncorr grown nonmed invdw loss phyrst poh nsect skmed skabt maxage standby disp att attphy; do
    [[ "$standby" == "1" ]] && continue
    H_TOT["$host"]=$(( ${H_TOT[$host]:-0} + 1 ))
    case "${VERDICT_OF[$dev]:-clean}" in
        FAULTING|ACTIVE|OUTLIER|MD-ERRORS)
            H_BAD["$host"]=$(( ${H_BAD[$host]:-0} + 1 ))
            H_LIST["$host"]="${H_LIST[$host]:-} $name($dev)" ;;
    esac
done < "$DATA"

CORRELATED=0
for h in "${!H_TOT[@]}"; do
    b="${H_BAD[$h]:-0}"; t="${H_TOT[$h]}"
    if [[ "$b" -gt 1 ]]; then
        bad "$h: $b of $t disks abnormal --${H_LIST[$h]}"
        info "several drives behind one controller is a SHARED fault."
        info "suspect cable, backplane, expander, HBA or its power first."
        CORRELATED=1
    elif [[ "$b" -eq 1 ]]; then
        warn "$h: 1 of $t abnormal --${H_LIST[$h]} -- isolated to that drive or slot"
    else
        ok "$h: $t disks, none abnormal"
    fi
done

# ============================================================================
sect "FAILURE RANGES"
# ============================================================================

build_ranges() {
    local dev="$1" div=$(( ${LBS_OF[$dev]:-512} / 512 ))
    [[ "$div" -lt 1 ]] && div=1
    awk -v div="$div" -v pad="$PAD" -v gap="$GAP" '
        { lba = int($1 / div)
          if (NR==1) { s=lba; e=lba; next }
          if (lba - e > gap) { printf "%d:%d,", (s>pad?s-pad:0), (e-s)+2*pad; s=lba }
          e=lba }
        END { if (NR>0) printf "%d:%d", (s>pad?s-pad:0), (e-s)+2*pad }
    ' "$RUN/sectors-$dev.txt"
}

ANY=0
for entry in "${FLAGGED[@]:-}"; do
    [[ -n "$entry" ]] || continue
    IFS=: read -r score name dev verdict <<< "$entry"
    [[ -s "$RUN/sectors-$dev.txt" ]] || continue
    build_ranges "$dev" > "$RUN/ranges-$dev.txt"
    log "$name ($dev, ${LBS_OF[$dev]:-512}B blocks, $(wc -l < "$RUN/sectors-$dev.txt") failing sectors)"
    [[ "${LBS_OF[$dev]:-512}" == "4096" ]] && info "4Kn: kernel sector / 8 = drive LBA"
    info "$(cat "$RUN/ranges-$dev.txt")"
    ANY=1
done
[[ "$ANY" -eq 0 ]] && info "no failing sectors in the log window"

# ============================================================================
#  DEEP TRIAGE
# ============================================================================

run_verify() {
    local dev="$1" start="$2" count="$3" pos=0 n rc fails=0
    while [[ $pos -lt $count ]]; do
        n=$(( count - pos )); [[ $n -gt $CHUNK ]] && n=$CHUNK
        if ! timeout 120 sg_verify --16 --vrprotect=0 --lba=$(( start + pos )) \
            --count=$n "/dev/$dev" >> "$RUN/verify-$dev.txt" 2>&1; then
            rc=$?; fails=$(( fails + 1 ))
            echo "VERIFY FAIL lba=$(( start + pos )) count=$n rc=$rc" >> "$RUN/verify-fail-$dev.txt"
        fi
        pos=$(( pos + n ))
        [[ "$THROTTLE" != "0" ]] && sleep "$THROTTLE"
    done
    return $(( fails > 0 ))
}

run_read() {
    local dev="$1" start="$2" count="$3" pos=0 n rc fails=0 bs="${LBS_OF[$dev]:-512}"
    while [[ $pos -lt $count ]]; do
        n=$(( count - pos )); [[ $n -gt $CHUNK ]] && n=$CHUNK
        if have sg_read; then
            timeout 120 sg_read if="/dev/$dev" bs="$bs" skip=$(( start + pos )) \
                count=$n bpt=256 >> "$RUN/read-$dev.txt" 2>&1 || {
                rc=$?; fails=$(( fails + 1 ))
                echo "READ FAIL lba=$(( start + pos )) count=$n rc=$rc" >> "$RUN/read-fail-$dev.txt"; }
        else
            timeout 120 dd if="/dev/$dev" of=/dev/null bs="$bs" \
                skip=$(( start + pos )) count=$n iflag=direct >> "$RUN/read-$dev.txt" 2>&1 || {
                rc=$?; fails=$(( fails + 1 ))
                echo "READ FAIL lba=$(( start + pos )) count=$n rc=$rc" >> "$RUN/read-fail-$dev.txt"; }
        fi
        pos=$(( pos + n ))
        [[ "$THROTTLE" != "0" ]] && sleep "$THROTTLE"
    done
    return $(( fails > 0 ))
}

snap() {  # device -> summed counters, one key per line
    local S="$RUN/smart-$1.txt"
    smartctl -x -d auto -n never "/dev/$1" > "$S" 2>&1
    echo "uncorr=$(awk '/^read:/ {print $NF}' "$S" | head -1 | grep -o '[0-9]*' || echo 0)"
    echo "grown=$(awk '/Elements in grown defect/ {gsub(/[^0-9]/,"",$NF); print $NF}' "$S" | head -1)"
    echo "nonmed=$(awk '/Non-medium error count/ {gsub(/[^0-9]/,"",$NF); print $NF}' "$S" | head -1)"
    echo "invdw=$(sum_field 'Invalid DWORD count' "$S")"
    echo "loss=$(sum_field 'Loss of DWORD synchronization' "$S")"
}

SUMMARY=()
triage_disk() {
    local name="$1" dev="$2" verdict="$3"
    sect "TRIAGE: $name (/dev/$dev)  [$verdict]"

    snap "$dev" | grep -E '^[a-z]+=[0-9]+$' > "$RUN/before-$dev.txt"
    local dmark; dmark="$(dmesg | wc -l)"
    local ranges vfail=0 rfail=0 vran=0

    if [[ -s "$RUN/ranges-$dev.txt" ]]; then
        ranges="$(cat "$RUN/ranges-$dev.txt")"
    else
        ranges="0:256"
        info "no recorded failing sectors; spot-checking LBA 0 only"
    fi
    info "ranges: $ranges"

    IFS=',' read -ra RL <<< "$ranges"
    for r in "${RL[@]}"; do
        local rs="${r%%:*}" rc="${r##*:}"
        log "  LBA $rs +$rc"
        if [[ $HAVE_SG -eq 1 ]]; then
            vran=1
            if run_verify "$dev" "$rs" "$rc"; then
                ok "  VERIFY clean -- drive read these blocks internally"
            else
                bad "  VERIFY failed -- drive cannot read its own media here"; vfail=1
            fi
        fi
        if run_read "$dev" "$rs" "$rc"; then
            ok "  READ   clean"
        else
            bad "  READ   failed -- blocks could not cross the link"; rfail=1
        fi
    done

    if [[ "$SURFACE_SCAN" == "yes" && $HAVE_SG -eq 1 ]]; then
        local total step
        total=$(( $(blockdev --getsz "/dev/$dev") / ( ${LBS_OF[$dev]:-512} / 512 ) ))
        warn "full-surface VERIFY of $total blocks -- hours"
        step=$(( total / 20 ))
        for i in $(seq 0 19); do
            run_verify "$dev" $(( i * step )) "$step" || vfail=1
            info "  $(( (i+1) * 5 ))% ($(date +%H:%M:%S))"
        done
    fi

    if [[ "$SHORT_SELFTEST" == "yes" ]]; then
        smartctl -t short -d auto "/dev/$dev" >> "$RUN/selftest-$dev.txt" 2>&1
        info "short self-test running, waiting 130s"
        sleep 130
        smartctl -l selftest -d auto "/dev/$dev" >> "$RUN/selftest-$dev.txt" 2>&1
        if grep -qi "Completed without error\|Completed  *-" "$RUN/selftest-$dev.txt"; then
            ok "short self-test passed"
        else
            warn "short self-test did not report a clean pass"
        fi
    fi

    [[ "$LONG_SELFTEST" == "yes" ]] && {
        smartctl -t long -d auto "/dev/$dev" >> "$RUN/selftest-$dev.txt" 2>&1
        info "long self-test queued; check: smartctl -l selftest -d auto /dev/$dev"; }

    dmesg | tail -n +"$dmark" | grep -i "$dev" > "$RUN/dmesg-$dev.txt" 2>/dev/null
    if [[ -s "$RUN/dmesg-$dev.txt" ]]; then
        local ma; ma="$(grep -o 'cmd_age=[0-9]*' "$RUN/dmesg-$dev.txt" | grep -o '[0-9]*' | sort -n | tail -1)"
        [[ -n "$ma" ]] && warn "worst cmd_age during triage: ${ma}s"
        grep -o 'Sense Key : 0x[0-9a-f]*' "$RUN/dmesg-$dev.txt" | sort -u | while read -r sk; do
            case "$sk" in
                *0x3*) bad  "$sk = MEDIUM ERROR -> genuine bad sectors" ;;
                *0xb*) warn "$sk = ABORTED COMMAND -> transport/target, not media" ;;
                *0x4*) warn "$sk = HARDWARE ERROR" ;;
            esac
        done
    fi

    snap "$dev" | grep -E '^[a-z]+=[0-9]+$' > "$RUN/after-$dev.txt"
    log ""
    log "  counter deltas across this triage:"
    local dmedia=0 dpath=0
    while IFS='=' read -r k v2; do
        local v1; v1="$(grep -m1 "^$k=" "$RUN/before-$dev.txt" | cut -d= -f2)"
        [[ "$v1" =~ ^[0-9]+$ && "$v2" =~ ^[0-9]+$ ]] || continue
        local d=$(( v2 - v1 ))
        printf '    %-8s %10s -> %-10s %+d\n' "$k" "$v1" "$v2" "$d" | tee -a "$LOG"
        if [[ $d -gt 0 ]]; then
            case "$k" in
                uncorr|grown)      dmedia=$(( dmedia + d )) ;;
                nonmed|invdw|loss) dpath=$(( dpath + d )) ;;
            esac
        fi
    done < "$RUN/after-$dev.txt"

    log ""
    if [[ $vran -eq 1 && $vfail -eq 0 && $rfail -eq 1 ]]; then
        bad "$name VERDICT: TRANSPORT"
        info "the drive read these blocks fine internally but they could not"
        info "cross the link. The platters are not the problem."
        info "next: move this disk to a different slot on a different cable."
        info "  errors follow the SLOT  -> cable / backplane / expander / HBA"
        info "  errors follow the DRIVE -> the drive's SAS interface electronics"
        SUMMARY+=("$name ($dev): TRANSPORT -- link fault, not media")
    elif [[ $vfail -eq 1 ]]; then
        bad "$name VERDICT: MEDIA"
        info "the drive cannot read its own platters here. Plan replacement."
        SUMMARY+=("$name ($dev): MEDIA -- replace")
    else
        ok "$name VERDICT: no fault reproduced"
        info "intermittent. re-run under load, or enable SURFACE_SCAN."
        SUMMARY+=("$name ($dev): not reproduced")
    fi
    [[ $dpath -gt 0 && $dmedia -eq 0 ]] && warn "counters moved on the PATH side only (+$dpath)"
    [[ $dmedia -gt 0 ]] && bad "media counters rose by $dmedia during this run"
}

if [[ ${#FLAGGED[@]} -gt 0 && "$AUTO_TRIAGE" == "yes" ]]; then
    # worst first, by score
    mapfile -t ORDERED < <(printf '%s\n' "${FLAGGED[@]}" | sort -t: -k1,1nr)
    n=0
    for entry in "${ORDERED[@]}"; do
        IFS=: read -r score name dev verdict <<< "$entry"
        [[ "$verdict" == "DISABLED" ]] && { warn "skipping $name -- already disabled, handle in the GUI"; continue; }
        if [[ "$TRIAGE_EVIDENCE_ONLY" == "yes" && "$score" -lt 60 ]]; then
            info "skipping $name ($verdict) -- no concrete evidence to test against"
            continue
        fi
        n=$(( n + 1 ))
        [[ $n -gt $MAX_TRIAGE ]] && { warn "hit MAX_TRIAGE=$MAX_TRIAGE, stopping"; break; }
        triage_disk "$name" "$dev" "$verdict"
    done
fi

# ============================================================================
sect "SUMMARY"
# ============================================================================

if [[ ${#FLAGGED[@]} -eq 0 ]]; then
    ok "no slots flagged"
else
    mapfile -t ORDERED < <(printf '%s\n' "${FLAGGED[@]}" | sort -t: -k1,1nr)
    log "${#ORDERED[@]} slot(s) flagged, worst first:"
    for entry in "${ORDERED[@]}"; do
        IFS=: read -r score name dev verdict <<< "$entry"
        log "  $name  /dev/$dev  $verdict"
    done
    if [[ ${#SUMMARY[@]} -gt 0 ]]; then
        log ""
        log "triage results:"
        for s in "${SUMMARY[@]}"; do log "  $s"; done
    elif [[ "$AUTO_TRIAGE" != "yes" ]]; then
        log ""
        log "set AUTO_TRIAGE=\"yes\" to diagnose these on the next run."
    fi

    TOP="$(printf '%s\n' "${ORDERED[@]}" | head -3 | cut -d: -f2,4 | tr '\n' ' ')"
    if [[ "$CORRELATED" -eq 1 ]]; then
        notify "Multiple disks abnormal on one controller" \
               "Shared path fault suspected. Worst: $TOP" "alert"
    else
        notify "${#ORDERED[@]} disk(s) flagged on $(hostname)" "Worst: $TOP" "warning"
    fi
fi

# ============================================================================
# save baseline for next run, keyed by disk ID (sd letters shift on reboot)
# ============================================================================
NOW="$(date +%s)"
: > "$STATE.new"
while IFS=$'\t' read -r name dev id status host mderr uncorr grown nonmed invdw loss phyrst poh nsect skmed skabt maxage standby disp att attphy; do
    if [[ "$standby" == "1" ]]; then
        # keep the previous record rather than losing it to a sleeping disk
        awk -F'\t' -v k="$id" '$1==k' "$STATE" >> "$STATE.new" 2>/dev/null
        continue
    fi
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
        "$id" "$NOW" "$uncorr" "$grown" "$nonmed" "$invdw" "$loss" "$name" >> "$STATE.new"
done < "$DATA"
mv -f "$STATE.new" "$STATE"
info "baseline saved for $(wc -l < "$STATE") disk(s)"

if [[ "$KEEP_RUNS" =~ ^[0-9]+$ && "$KEEP_RUNS" -gt 0 ]]; then
    ls -1dt "$OUTDIR"/*/ 2>/dev/null | tail -n +$(( KEEP_RUNS + 1 )) | while read -r d; do rm -rf "$d"; done
fi

log ""
log "full report: $LOG"
log "finished   : $(date)"
exit 0