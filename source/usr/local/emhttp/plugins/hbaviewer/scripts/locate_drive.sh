#!/bin/bash
# Blink ONE drive's activity light, so a row in a table becomes a bay you can
# walk to (plan 048).
#
# There is no LED being driven here. The loop generates read-only SMART traffic
# on one device twice a second, and the drive's own activity light flickers in
# a rhythm you can pick out of a rack. That is why this works where plan 024's
# SES `locate` did not: it asks the backplane for nothing. A synthesised
# VirtualSES with nothing wired behind it — the maintainer's own hardware —
# cannot fail this the way it failed that.
#
# Two limits, both stated in the UI before it starts:
#   - It is the ACTIVITY light, not a dedicated locate LED. On a busy array
#     other drives blink too; you are looking for the steady rhythm.
#   - It wakes a sleeping drive and keeps it awake. Inherent — the technique IS
#     "generate activity" — and the opposite of read_smart.sh's spin-up guard.
#
# `timeout`, NOT `pkill -f smartctl`. The upstream this idea comes from
# (olehj/disklocation, GPL-3.0 — the idea is adopted, not the code) kills every
# smartctl on the box each tick to stop a slow read overrunning the next one.
# Here that would kill collect_smart.sh's reads mid-collection: the collector
# does not die, it just records {} for whichever drive was being read, which
# reaches the SMART tab as `standby` and the bay map as a grey NO SMART bay —
# and now persists until someone presses Refresh. A locate that quietly greys
# out the table beside it is worse than no locate.
#
# Bounded HERE, not in the browser. A closed tab must not leave a drive being
# read forever, unable to spin down.
#
#   locate_drive.sh <H:C:T:L> [max_secs]
#
# The two directories are overridable for tests only (tests/locate_sh_test.sh).

ADDR="$1"
MAX="${2:-300}"
BSG_DIR="${HBAV_BSG_DIR:-/dev/bsg}"
PID_DIR="${HBAV_PID_DIR:-/tmp}"

# Second line of defence — locate.php validates this before we are ever called,
# but this argument becomes a device path and belt-and-braces is cheap.
case "$ADDR" in
    ''|*[!0-9:]*) echo "usage: locate_drive.sh <H:C:T:L> [max_secs]" >&2; exit 2 ;;
esac
case "$MAX" in ''|*[!0-9]*) MAX=300 ;; esac

[ -e "$BSG_DIR/$ADDR" ] || { echo "no such device: $BSG_DIR/$ADDR" >&2; exit 3; }

PIDFILE="$PID_DIR/hbav_locate_${ADDR//:/_}.pid"
echo $$ > "$PIDFILE"
# EXIT removes the marker however we leave, so the UI never shows a locate that
# is not running.
trap 'rm -f "$PIDFILE"' EXIT
# INT/TERM must EXIT, not merely clean up. A trap that only removes the marker
# lets the loop keep reading the drive after Stop -- and with the marker gone,
# nothing is left to stop it by. 143 = 128 + SIGTERM, the conventional code.
trap 'exit 143' INT TERM

END=$(( $(date +%s) + MAX ))
while [ "$(date +%s)" -lt "$END" ]; do
    timeout 0.4 smartctl -x "$BSG_DIR/$ADDR" >/dev/null 2>&1
    # `sleep &` + `wait`, not a bare `sleep`: bash defers a trap until the
    # foreground command finishes, so a plain sleep would swallow Stop for up
    # to half a second. `wait` is interruptible, so TERM is acted on at once —
    # the UI reports the stop from the same request that asked for it.
    sleep 0.5 & wait $!
done
