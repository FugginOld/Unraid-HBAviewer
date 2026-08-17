#!/bin/bash
# Pure filter: `storcli2 /cN show all` text on stdin -> overview JSON, same shape
# as the other two backends (parse/storcli_overview.sh, parse/hba.sh).
#
# Why `show all` rather than the cheap `show` the classic backend uses: StorCLI2
# has NO `show temperature` subcommand — it is a syntax error on both the Lite
# and the full build — and the brief `show` carries no temperature either. The
# reason `show all` is avoided on the classic tool (a slow per-drive SMART scan)
# does not apply here: measured on a 9600-24i it returns in well under a second.
#
#   $1 alert threshold, $2 summed PHY errors, $3 chip override,
#   $4/$5/$6 PCIe width / speed / power mode (sysfs, read by the composer).
#
# Feed a captured `storcli2 /cN show all` to test — no hardware needed.

input=$(cat)
ALERT="${1:-80}"
PHYERR="${2:-}"     # EMPTY means "not measured" — see the rollup note below
CHIPARG="${3:-}"
PCIEW="${4:-}"
PCIES="${5:-}"
PWRM="${6:-}"

# Exact "Key = Value" lookup. index()==1 rather than a regex because several
# StorCLI2 keys contain characters a regex would eat — "Chip temperature(C)" —
# and because it makes the match exact: the key "Model" must not answer with the
# line "Model Number = ...".
val() {
    printf '%s\n' "$input" | awk -v k="$1" \
        'index($0, k " =") == 1 { sub(/^[^=]*=[ \t]*/, ""); sub(/[ \t]+$/, ""); print; exit }'
}

# The controller sensor. "Chip temperature" is StorCLI2's name for what the
# classic tool calls "ROC temperature"; there is a separate "Board temperature"
# a few degrees lower, which is the board inlet and not what the bands are for.
TEMP=$(val "Chip temperature(C)")
case "$TEMP" in ''|*[!0-9]*) TEMP="" ;; esac

BOARD=$(val "Board Name");   [ -n "$BOARD" ] || BOARD=$(val "Product Name")
FW=$(val "Firmware Version")
BIOS=$(val "BIOS Version")
PCI=$(val "PCI Address")
DRIVES=$(val "Physical Drives")
CHIP="$CHIPARG"; [ -n "$CHIP" ] || CHIP=$(val "Chip Name")

# Personality, not IT/IR. A 9600 has exactly one personality and it is named in
# the output ("Controller Personality = eHBA"), so there is nothing to infer from
# drive states here — and inferring would be wrong: these cards report JBOD for
# bare disks exactly as IT firmware does, which is the trap issue #10 documented
# on the classic backend. The field is free text; the UI prints what it is given.
MODE=$(val "Controller Personality")

# Drive states from the PD LIST section ONLY. Scoped to the section rather than
# scanned globally: `show all` is 400+ lines of other tables, and the state
# vocabulary appears again in the legend text at the bottom.
# Columns are EID:Slt PID State Status ..., all single tokens from the left, so
# $3/$4 are safe here even though later columns (Model) contain spaces.
DSTATES=$(printf '%s\n' "$input" | awk '
    /^PD LIST :$/      { inpd=1; next }
    /^[A-Za-z].* :$/   { inpd=0 }
    inpd && /^[ \t]*[0-9]+:[0-9]+[ \t]/ { print $3; print $4 }')

# ── Temperature band (absolute, NOT derived from the setting) ────────────────
# Five fixed bands. ALERT names the first band at which the badge complains.
# THIRD copy of this block (parse/hba.sh and parse/storcli_overview.sh hold the
# others) — keep all three identical.
#   normal <=65 | elevated 66-75 | warning 76-85 | alert 86-95 | critical >=96
band_of() {
    if   [ "$1" -le 65 ]; then echo normal
    elif [ "$1" -le 75 ]; then echo elevated
    elif [ "$1" -le 85 ]; then echo warning
    elif [ "$1" -le 95 ]; then echo alert
    else echo critical; fi
}
band_index() { case "$1" in normal) echo 0;; elevated) echo 1;; warning) echo 2;; alert) echo 3;; *) echo 4;; esac; }

CFG_BAND=$(band_of "$ALERT")

# No sensor reading is NOT an error and NOT a zero. The classic parser answers
# `{"error":"No temperature..."}` when its label does not match, which blanks the
# whole Overview rather than one field — on this card that is exactly what would
# happen, because the label it looks for does not exist in StorCLI2. Absence
# renders grey: temp "" and an empty band, the same shape parse/hba.sh emits for
# a sensorless SAS2008 (issue #17).
if [ -n "$TEMP" ]; then
    TEMP_BAND=$(band_of "$TEMP")
    ti=$(band_index "$TEMP_BAND"); ci=$(band_index "$CFG_BAND")
    if   [ "$ti" -gt "$ci" ]; then RANK=2
    elif [ "$ti" -eq "$ci" ]; then RANK=1
    else RANK=0; fi
    TEMPJSON="$TEMP"
else
    TEMP_BAND=""; RANK=0; TEMPJSON='""'
fi

if printf '%s\n' "$DSTATES" | grep -qiE '^(Failed|Offln|Missing|UBad|Foreign|Bad)$'; then
    [ "$RANK" -lt 2 ] && RANK=2
elif printf '%s\n' "$DSTATES" | grep -qiE '^(Rbld|Rebuild|Copyback)$'; then
    [ "$RANK" -lt 1 ] && RANK=1
fi

# PHY errors. Empty means NOT MEASURED: on an eHBA-personality 9600 the kernel
# registers no SAS transport class, so the sysfs counters the classic composer
# sums do not exist — the composer passes StorCLI2's own total instead, or an
# empty string when even that could not be read.
# The case guard is load-bearing for a blunter reason than the semantics: a bare
# `[ "" -ge 100 ]` is not a false comparison, it is a shell ERROR ("integer
# expected", exit 2) printed to stderr, and the classic parser only avoids it by
# defaulting the value to 0. Non-numeric input is dropped rather than defaulted,
# so an unmeasured card cannot be scored as a measured clean one — with the floor
# at 100 that distinction does not change today's badge, but "not measured" and
# "measured zero" must not become the same value on the way in.
# Floor and reasoning otherwise as parse/storcli_overview.sh.
PHYERR_FLOOR=100
case "$PHYERR" in
    ''|*[!0-9]*) : ;;
    *) [ "$PHYERR" -ge "$PHYERR_FLOOR" ] && [ "$RANK" -lt 1 ] && RANK=1 ;;
esac

case "$RANK" in 2) STATUS="alert" ;; 1) STATUS="warn" ;; *) STATUS="ok" ;; esac

cat <<EOF
{"temp":$TEMPJSON,"model":"${CHIP}","firmware":"${FW}","bios":"${BIOS}","mode":"${MODE}","drive_count":"${DRIVES}","port_name":"","board_name":"${BOARD}","pci_location":"${PCI}","pcie_width":"${PCIEW}","pcie_speed":"${PCIES}","power_mode":"${PWRM}","alert_threshold":$ALERT,"temp_band":"$TEMP_BAND","cfg_band":"$CFG_BAND","status":"$STATUS"}
EOF
