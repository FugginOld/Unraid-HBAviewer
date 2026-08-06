# Plan 048: Locate a drive by its activity LED (no SES, no GPIO)

> **DONE — executed, hardware-verified and archived 2026-08-05.** Shipped on
> `dev`. Two things shipped after the plan and are not described below: the
> Locate button became its own STOP control (the separate "Stop blinking"
> toolbar button and the `stop_all` action were deleted), and the stop path
> gained two fixes after the maintainer found the bay still flashing over a
> stopped drive — the dispatch now waits for the process to die before
> answering, and the loop uses `sleep & wait` so a bash trap is not deferred
> until the sleep expires.
>
> Three done criteria rest on code inspection rather than a test or an observed
> run, recorded so nobody assumes otherwise: the UI stating both limits before
> the first blink, the locating state surviving a page reload, and two locates
> on one address leaving one process. Everything else is backed by
> `tests/locate_sh_test.sh` (14 assertions), `tests/locate_test.php` (34), or
> the maintainer's hardware.

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 2db6d5e..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/read_smart.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh source/usr/local/emhttp/plugins/hbaviewer/bay_map.php source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php`
> Expected output: **nothing**. Every excerpt below is quoted from `2db6d5e`
> (`dev` tip, 2026-08-04). Any difference is a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: **MEDIUM** — this is the second thing in the plugin that spawns a
  background process as root, and the first that runs one *indefinitely by
  design*. The I/O it generates is read-only SMART traffic, but the failure
  modes are operational rather than data-destroying: a forgotten locate keeps
  a drive awake forever, and a careless implementation kills the plugin's own
  SMART collector (see "The one thing not to copy").
- **Depends on**: plan 047 (the bay map) for the UI surface. The Drives-table
  button works without it.
- **Category**: feature
- **Planned at**: `2db6d5e`, 2026-08-05
- **Requested by**: maintainer, after finding the technique in
  [`olehj/disklocation`](https://github.com/olehj/disklocation) and confirming
  **it works on his own box** in that plugin. That confirmation is the whole
  reason this plan exists rather than another speculative LED feature — see
  "Why this matters".

## Step 1 result — confirmed on hardware 2026-08-05

The mapping holds on the maintainer's box. Every `sdX` resolves to an
`H:C:T:L` that exists under `/dev/bsg/`:

```text
sda -> 0:0:1:0   …  sdo -> 0:0:15:0   sdq -> 0:0:16:0     (host 0 = /c0, 16 drives)
sdp -> 1:0:0:0   …  sdx -> 1:0:7:0                        (host 1 = /c1, 8 drives)
sdy -> 22:0:0:0  sdz -> 23:0:0:0                          (not on the HBAs)
```

Two things worth keeping from that capture:

- **`0:0:0:0` exists in `/dev/bsg` with no block device behind it.** That is
  the synthesised `VirtualSES` enclosure — the exact thing plan 024 died on.
  It confirms `/dev/bsg` carries non-block SCSI devices, so the locate button
  must be offered from the *drive* list (which never contains it) rather than
  from a `/dev/bsg` listing.
- **Parity is on the second controller** (`sdp` = `1:0:0:0`), so the host
  index is not a controller-ordering assumption this feature can make. It does
  not need to: the address comes from the device, not from the controller.

## Why this matters

**Plan 024 is the reason to care.** It implemented a Locate button on the
kernel's SES `locate` attribute, unit-tested it 19 ways, and then failed
hardware acceptance on the maintainer's box: `locate=1` was written to all 24
populated slots across both enclosures, every one read back `1`, and **no
chassis LED changed**. Both enclosures are the HBA's synthesised `VirtualSES` —
the components are real and writable, but nothing is wired behind them. The
plan was rejected and removed; its row in `plans/README.md` keeps the negative
result so nobody re-derives it.

That failure is a property of the *approach*, not of the code: SES locate needs
an enclosure processor that is actually wired to LEDs, and a plain HBA into a
dumb backplane has none. **This approach needs nothing from the backplane at
all.** It does not drive an LED; it generates I/O on one drive so the drive's
own activity light flickers in a rhythm you can pick out of a rack. Anything
with an activity LED can do it, which is very nearly everything with a spinning
disk in a hot-swap tray.

The bay map (plan 047) makes this worth twice as much: the map tells you *which
bay* a drive is in according to a layout a person typed in, and Locate is how
you confirm the map is right without pulling a drive to check.

## Current state

### The upstream mechanism (reviewed, not copied)

`disklocation/pages/locate.php` is a thin `shell_exec` wrapper; the mechanism
is a four-line script its `.plg` writes to `/usr/local/bin/smartlocate`:

```bash
#!/bin/bash
# Simple hack to locate harddrives in hotswap arrays,
#   might not work on all drives or SSD's.
# Run: ./smartlocate [address]    Ex: ./smartlocate 8:0:0:0
while sleep 0.5; do
  pkill -f smartctl &> /dev/null
  smartctl -x /dev/bsg/$1 &> /dev/null
done
```

`/dev/bsg/H:C:T:L` is the SCSI generic node for one device. A `smartctl -x`
against it twice a second is enough activity to make the tray light blink
visibly. GPL-3.0, and the idea is what is being adopted here, not the file.

### The one thing not to copy: `pkill -f smartctl`

```bash
# scripts/collect_smart.sh — the plugin's own SMART collection
lsblk -S -P -o NAME,WWN,SERIAL,MODEL 2>/dev/null | grep 'WWN="0x' | while IFS= read -r line; do
    ...
    smart=$(bash "$DIR/read_smart.sh" "/dev/$name")
    [ -n "$smart" ] || smart='{}'
```

```bash
# scripts/read_smart.sh — what actually runs smartctl
if [ "$tran" = "sas" ]; then
    smartctl -a "$dev" 2>/dev/null | bash "$DIR/parse/smart.sh" "$tran"
else
    smartctl -n standby -a "$dev" 2>/dev/null | bash "$DIR/parse/smart.sh" "$tran"
fi
```

A bare `pkill -f smartctl` kills **every** smartctl on the box, including
these. The collector does not die — it keeps looping — so the visible result is
not an error but a **silently truncated cache**: whichever drive was mid-read
yields `{}`, which reaches the SMART tab as `standby` and the bay map as a grey
`NO SMART` bay. A Locate feature that quietly greys out the table beside it is
worse than no Locate feature. Since the SMART cache is now kept until the
person presses Refresh, that corruption also *persists* until they do.

Upstream needs that `pkill` only because `smartctl -x` can outlast the 0.5 s
tick. `timeout` solves the same problem without touching anything else.

### The POST dispatch pattern this must follow

```php
/* ── POST dispatch (served only; skipped under the CLI test runner) ──────────
   Requiring this file is read-only: every branch below needs a POST with an
   `action`, so ajax_info.php can pull the store in without risk of mutating it.
   Unraid's own layer checks csrf_token on POST; the client sends it exactly as
   phy_baseline.php's Reset Baseline button does. */
if (PHP_SAPI === 'cli') return;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['action'])) return;
```

Upstream's `locate.php` is a **GET** endpoint that `shell_exec`s. Unraid's
`local_prepend.php` only checks `csrf_token` on POST, so a GET that spawns a
process is CSRF-triggerable. Every mutating path in this plugin is POST, and
this one will be too.

### The validation pattern this must follow

```php
/* Whether a string could have come out of bay_map_key(). The POST dispatch
   below is a trust boundary: without this, a crafted key writes arbitrary JSON
   object keys into a file on the boot flash. */
function bay_map_key_valid(string $key): bool {
    return (bool) preg_match('/^c\d{1,3}:[ph]\d{1,4}$/', $key);
}
```

The same posture applies to the SCSI address: it is a request value that
becomes part of a device path, so it is validated against its own shape before
it is used, not merely escaped.

### Two more upstream bugs, recorded so they are not reproduced

```php
shell_exec("pkill -f \"smartlocate " . escapeshellarg($_GET["disklocation"] . "\""));
```

The closing quote is concatenated **before** `escapeshellarg`, so the shell
receives `pkill -f "smartlocate 'sda"'` — an unterminated quote. Not
injectable (escapeshellarg holds), just unreliable. A PID file removes the need
for pattern-matching `pkill` entirely.

```php
else if(isset($_GET["cmd"]) == "killall") {
```

`isset()` returns a bool, and in PHP 8 `true == "killall"` is **true** — so any
`cmd` that is not start/stop takes this branch. Harmless there; a real logic
error worth not repeating.

## Scope

**In scope**:

- `scripts/locate_drive.sh` — the loop. One argument, an `H:C:T:L` address.
  Bounded runtime, `timeout`-bounded `smartctl`, its own PID file, no global
  `pkill`.
- `locate.php` — POST dispatch: `start`, `stop`, `stop_all`, `status`.
  Validation, single-flight per device, CSRF via Unraid's own layer.
- A **Locate** button per drive on the Drives table and per filled bay on the
  map, with a visible "locating" state and a Stop control while one runs.
- `LOCATE_MAX_SECS` in `LSI_SCHEMA` (default **300**, range 30–1800). The
  expiry is enforced **in the script**, not only in the browser — a closed tab
  must not leave a drive being hammered forever.
- The `/dev/sdX` → `H:C:T:L` resolution, from sysfs, server-side.
- Honest UI text about the two limits of the technique (below).

**Out of scope**:

- Any SES/`locate`-attribute work. That is plan 024 and it is rejected.
- NVMe. There is no `/dev/bsg` node of this shape for NVMe and no activity LED
  convention to rely on; the button is simply not offered for those drives.
- Blinking a *pattern* (long/short) to distinguish two simultaneous locates.
  One rhythm, one drive at a time per bay, is enough for v1.
- Any change to `collect_smart.sh`, `read_smart.sh` or `parse/smart.sh`. This
  plan only avoids disturbing them.

## The two honest limits (must reach the UI, not just this file)

1. **It is the activity LED, not a dedicated locate LED.** On a busy array
   other drives blink too — you are looking for a *rhythm*, not a unique light.
   Say so in the UI.
2. **It wakes a sleeping drive and keeps it awake.** That is inherent: the
   whole technique is "generate activity". It is the correct trade when you are
   about to pull a disk, and the exact opposite of the spin-up guard
   `read_smart.sh` is careful about. The button must say so before it starts.

## Steps

### Step 1: Confirm the address mapping and the effect on hardware

The maintainer has already confirmed the *technique* works on his box via the
upstream plugin. What this step confirms is the **mapping this plan will use**,
which upstream gets from its own database and this plugin must derive:

```bash
# The bsg node for a device, from sysfs — no lookup table, no new dependency.
for d in /dev/sd?; do
  n=$(basename "$d")
  printf '%s -> %s\n' "$n" "$(basename "$(readlink -f /sys/block/$n/device)")"
done
ls /dev/bsg/ | head
```

**Expected**: every `sdX` maps to an `H:C:T:L` string that exists in
`/dev/bsg/`. Record the output in the plan's status row.

**STOP** if `/dev/bsg` does not exist or the addresses do not match — the whole
approach rests on this and there is no point continuing on a box where it does
not hold.

Then confirm the blink itself, by hand, before writing any code:

```bash
timeout 20 bash -c 'while sleep 0.5; do timeout 0.4 smartctl -x /dev/bsg/<ADDR> &>/dev/null; done'
```

**Expected**: that bay's LED flickers at ~2 Hz for 20 seconds and stops on its
own. Note whether neighbouring bays are visually distinguishable while the
array is idle — that answers how strongly limit #1 needs to be worded.

### Step 2: `scripts/locate_drive.sh`

```bash
#!/bin/bash
# Blink one drive's ACTIVITY light by generating read-only SMART traffic on it.
# ... (full header explaining the technique and its two limits)
#   locate_drive.sh <H:C:T:L> <max_secs>
ADDR="$1"; MAX="${2:-300}"
case "$ADDR" in *[!0-9:]*|"") exit 2 ;; esac    # belt: PHP validated it too
PIDFILE="/tmp/hbav_locate_${ADDR//:/_}.pid"
echo $$ > "$PIDFILE"
trap 'rm -f "$PIDFILE"' EXIT INT TERM
END=$(( $(date +%s) + MAX ))
while [ "$(date +%s)" -lt "$END" ]; do
    # timeout, NOT `pkill -f smartctl`: this must never touch a smartctl that
    # belongs to collect_smart.sh (see the plan's "one thing not to copy").
    timeout 0.4 smartctl -x "/dev/bsg/$ADDR" >/dev/null 2>&1
    sleep 0.5
done
```

**Verify**: `bash -n` clean. Started with `MAX=3`, it exits on its own after
~3 s and removes its PID file. `pgrep -f smartctl` during a run shows at most
one, and a concurrently running `collect_smart.sh` completes with a full cache
(this is the regression the whole plan is written around).

### Step 3: `locate.php` — the dispatch

Pure functions above the CLI guard, dispatch below, exactly as `bay_map.php`
and `phy_baseline.php` do:

- `locate_addr_valid(string $addr): bool` — `/^\d{1,4}:\d{1,4}:\d{1,4}:\d{1,4}$/`.
- `locate_pid_path(string $addr): string`, `locate_running(string $addr): bool`
  (PID file exists **and** `/proc/<pid>` exists — a stale file from a killed
  process must not read as running), `locate_active(): array` — every address
  currently locating, for the UI to restore state on reload.
- Dispatch: `start` (validate → refuse if already running → launch detached
  with `LOCATE_MAX_SECS`), `stop` (read PID file, `kill` that PID only),
  `stop_all`, `status`.

**Verify**: `php -l` clean; unit tests below; `start` twice in a row leaves
exactly one process.

### Step 4: UI

- Drives table: a **Locate** button per row, next to SMART. Disabled with a
  reason when the drive has no resolvable `H:C:T:L`.
- Bay map: the same on each filled bay; the cell pulses while locating (CSS
  only, and it must respect `prefers-reduced-motion` like the rebuild stripe
  already does).
- A single **Stop all locating** control, shown only when something is running.
- First press shows a one-time confirm naming both limits: it blinks the
  *activity* light, and it will wake the drive and keep it awake.
- On load, `status` restores the locating state — a locate started in another
  tab, or before a reload, must not disappear from the UI while it is still
  running.

### Step 5: Tests

- `tests/locate_test.php` — address validation (accept `8:0:0:0`, reject
  `8:0:0`, `../../etc`, `8:0:0:0;reboot`, empty, and a 10-digit component),
  PID path derivation, and stale-PID-file handling with an injected temp dir.
- `tests/locate_sh_test.sh` — stub `smartctl` and `timeout` on PATH (same
  approach as `tests/read_smart_test.sh`'s stubs) and assert: the loop exits by
  itself at `MAX`, the PID file is written and removed, **no `pkill` is ever
  invoked** (a stub `pkill` that touches a marker file — the marker must not
  exist), and the address is passed through as `/dev/bsg/<addr>`.
- Wire both into `tests/run.sh` / `tests/run_php.sh`.

## Test plan

- Everything above the dispatch in `locate.php` is pure and temp-path
  injectable, tested with no HTTP and no hardware.
- The shell loop is tested against stubs; the *only* thing requiring hardware
  is "does the light actually blink", which Step 1 answers before any code is
  written.
- `bash tests/run.sh` stays green.

## Done criteria

- [ ] Step 1's mapping confirmed on hardware and recorded in the status row
- [ ] `locate_drive.sh` exits on its own at `LOCATE_MAX_SECS` and removes its
      PID file, verified with a short `MAX`
- [ ] **A full `collect_smart.sh` run completes with a complete cache while a
      locate is running** — no drive reports `{}` that would not otherwise
- [ ] `grep -r 'pkill' source/` finds nothing new (the existing `flash.php`
      and SMART-progress uses are unchanged)
- [ ] Address validation rejects everything in the Step 5 list
- [ ] Two locates on the same address leave exactly one process
- [ ] Stop kills only that drive's process; other locates keep running
- [ ] A stale PID file (process gone) reads as not-running and can be restarted
- [ ] The UI states both limits before the first locate starts
- [ ] Locating state survives a page reload while the process is still running
- [ ] `php -l` / `bash -n` clean on every touched file
- [ ] `bash tests/run.sh` → `--- all pass ---`

## STOP conditions

- Any `pkill -f smartctl`, or any process-killing that is not "kill exactly the
  PID in this drive's PID file".
- A loop with no upper bound, or a bound enforced only in the browser.
- A GET endpoint that starts or stops anything.
- A device path built from a request value that has not been validated against
  `locate_addr_valid()` — escaping alone is not enough here.
- Any edit to `collect_smart.sh`, `read_smart.sh` or `parse/smart.sh`.
- The SMART cache is measurably degraded by a locate (done criterion 3 fails).

## Maintenance notes

- **This is the third process-spawning path** after `flash.php` and the SMART
  collector, and the only one that is *meant* to keep running after the request
  ends. If a fourth appears, the PID-file + expiry pattern here is the one to
  copy, not `flash.php`'s single-flight lock (that one guards a mutation; this
  one guards a runaway).
- **The technique's cost is a drive that cannot sleep.** `LOCATE_MAX_SECS`
  exists so that cost is bounded by default rather than by the user
  remembering. Raising the default is a decision about spun-down drives, not a
  cosmetic one.
- **If someone ever reports a box where this does not blink**, the likely cause
  is a backplane that wires activity LEDs in common, or an SSD with no LED at
  all — not a bug in this code. Record it; do not add heuristics that claim to
  detect it.
- Upstream (`olehj/disklocation`) is GPL-3.0. The idea is adopted here, not the
  code; this implementation shares no lines with it. If that ever changes, the
  licence question becomes real.
