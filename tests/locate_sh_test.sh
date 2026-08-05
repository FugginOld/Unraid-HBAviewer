#!/bin/bash
# Self-asserting checks for scripts/locate_drive.sh (plan 048).
#
# The load-bearing one is `no pkill`: the upstream this technique comes from
# kills every smartctl on the box each tick, which here would truncate
# collect_smart.sh's cache silently — {} for whichever drive was mid-read,
# surfacing as `standby` on the SMART tab and a grey bay on the map, and now
# persisting until someone presses Refresh. A stub `pkill` on PATH touches a
# marker file; that marker must never appear.
#
# smartctl, timeout and pkill are all stubbed on PATH (same approach as
# read_smart_test.sh), and both directories the script touches are injected, so
# this needs no /dev/bsg and no hardware.
#   bash tests/locate_sh_test.sh   ->  "locate_sh: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
LOC="../source/usr/local/emhttp/plugins/hbaviewer/scripts/locate_drive.sh"
fail=0
ok()  { echo "PASS  $1"; }
bad() { echo "FAIL  $1 -- $2"; fail=1; }

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/stub" "$WORK/bsg" "$WORK/pid"
: > "$WORK/bsg/0:0:1:0"          # a fake device node to point at

cat > "$WORK/stub/smartctl" <<STUB
#!/bin/bash
echo "\$@" >> "$WORK/smartctl.args"
STUB
# timeout is stubbed to just run the command, so the arg list stays visible.
cat > "$WORK/stub/timeout" <<'STUB'
#!/bin/bash
shift          # drop the duration
exec "$@"
STUB
# The tripwire. If the script ever gains a pkill, this marker appears.
cat > "$WORK/stub/pkill" <<STUB
#!/bin/bash
touch "$WORK/PKILL_WAS_CALLED"
STUB
chmod +x "$WORK/stub"/*

run() {   # $1 = addr, $2 = max secs
    HBAV_BSG_DIR="$WORK/bsg" HBAV_PID_DIR="$WORK/pid" \
        PATH="$WORK/stub:$PATH" bash "$LOC" "$1" "$2"
}

# ── 1. It stops by itself ────────────────────────────────────────────────────
# The bound is enforced in the script, not the browser: a closed tab must not
# leave a drive being read forever, unable to spin down.
start=$(date +%s)
run "0:0:1:0" 2
elapsed=$(( $(date +%s) - start ))
if [ "$elapsed" -ge 1 ] && [ "$elapsed" -le 6 ]; then
    ok "expires on its own (${elapsed}s for a 2s bound)"
else
    bad "expires on its own" "took ${elapsed}s"
fi

# ── 2. It never kills anything globally ──────────────────────────────────────
if [ -e "$WORK/PKILL_WAS_CALLED" ]; then
    bad "no pkill" "the script invoked pkill — this would truncate collect_smart.sh's cache"
else
    ok "no pkill: nothing global is killed"
fi

# ── 3. It read the right device ──────────────────────────────────────────────
if grep -q -- "$WORK/bsg/0:0:1:0" "$WORK/smartctl.args" 2>/dev/null; then
    ok "reads /dev/bsg/<addr>"
else
    bad "reads /dev/bsg/<addr>" "args were: $(cat "$WORK/smartctl.args" 2>/dev/null)"
fi
reads=$(wc -l < "$WORK/smartctl.args")
if [ "$reads" -ge 2 ]; then ok "blinks repeatedly ($reads reads)"; else bad "blinks repeatedly" "only $reads read(s)"; fi

# ── 4. The marker is written while running and gone afterwards ───────────────
if [ -z "$(ls -A "$WORK/pid" 2>/dev/null)" ]; then
    ok "marker removed on exit"
else
    bad "marker removed on exit" "left: $(ls "$WORK/pid")"
fi

# Now catch it mid-run: the marker must exist and hold a live PID.
: > "$WORK/smartctl.args"
run "0:0:1:0" 5 &
bg=$!
sleep 1
pidfile="$WORK/pid/hbav_locate_0_0_1_0.pid"
if [ -f "$pidfile" ]; then
    ok "marker written while running"
    held=$(cat "$pidfile")
    if [ -d "/proc/$held" ] || kill -0 "$held" 2>/dev/null; then
        ok "marker holds a live pid ($held)"
    else
        bad "marker holds a live pid" "pid $held is not alive"
    fi
else
    bad "marker written while running" "no file at $pidfile"
fi
# ── 5. Stop kills it, and the marker goes with it ────────────────────────────
# Kill the PID *from the marker*, which is what locate.php does — never the
# wrapper that launched it. Killing the launcher leaves the script orphaned and
# still reading the drive, which is the whole reason stopping is by stored PID.
kill "$held" 2>/dev/null
sleep 2      # the trap runs after the in-flight `sleep 0.5` returns
if [ -f "$pidfile" ]; then bad "marker removed when stopped" "still at $pidfile"; else ok "marker removed when stopped"; fi
if kill -0 "$held" 2>/dev/null; then bad "the loop is gone after stop" "pid $held still alive"; else ok "the loop is gone after stop"; fi
wait "$bg" 2>/dev/null

# ── 6. Bad input refuses rather than building a path out of it ───────────────
for badaddr in "" "8:0:0" "../../etc/passwd" "8:0:0:0;reboot"; do
    if run "$badaddr" 1 2>/dev/null; then
        bad "refuses '$badaddr'" "exited 0"
    else
        ok "refuses '$badaddr'"
    fi
done
# A well-formed address with no device behind it is refused too — nothing to read.
if run "9:9:9:9" 1 2>/dev/null; then
    bad "refuses an absent device" "exited 0"
else
    ok "refuses an absent device"
fi

[ $fail -eq 0 ] && echo "locate_sh: all pass"
exit $fail
