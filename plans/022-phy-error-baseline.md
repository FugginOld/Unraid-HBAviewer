# Plan 022: Per-PHY error baseline, with rate shown alongside the raw counter

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 8286fe7..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/get_phy_health.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/phy.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_phy.sh source/usr/local/emhttp/plugins/hbaviewer/config.php`
> Expected output: **nothing**. Every excerpt below is quoted from `8286fe7`
> (`dev` tip, 2026-07-30). Any difference is a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MEDIUM — new persisted state, first of its kind in this plugin
- **Depends on**: none directly, but **read "Relationship to plan 020" below
  before starting** — this plan and 020 both touch PHY-error persistence
- **Category**: feature
- **Planned at**: `8286fe7`, 2026-07-31
- **Requested by**: external roadmap review

## Relationship to plan 020 — read this first

**Rewritten 2026-07-31 — this section's original framing is out of date.** It
described plan 020 as blocked on two open questions (*where does history live*,
*what drives the cadence*). Both were answered and **020 has since been written,
executed and verified on hardware**: it keeps a 240-sample ring in `/tmp`,
computes per-PHY rates from sample timestamps, and resolves `link_integrity`
from grey to a real state within a couple of page loads. Anyone reading the old
text would have rebuilt something that already exists.

**The two remain complementary, and this plan is still worth doing.** They answer
different questions:

| | plan 020 | this plan |
|---|---|---|
| Question | "is it degrading *right now*?" | "has anything happened *since I fixed it*?" |
| Window | rolling ~4 hours | since the user last pressed the button |
| Survives reboot | no, deliberately | **yes — that is the point** |
| Surface | HBA Health rollup indicator | per-PHY detail on the PHY Health tab |

A user-set baseline *should* persist across reboots, and it is written once per
button press rather than every 60 seconds — so the flash-wear reasoning that put
020's ring in `/tmp` does not apply here. `/boot` is the right home for this one.

**Reuse 020's building blocks rather than duplicating them.** `health.php` already
has the rate arithmetic and, critically, the reset detection this plan needs (see
"The reset trap" below). Read it before writing a second implementation.

The scoped-down design below still stands on its own merits:

- **User-triggered baseline, not periodic sampling.** A "Reset baseline"
  button captures one snapshot. The rate shown is `(current - baseline) /
  (now - baseline_time)`, computed at render time from two points, not a
  continuously sampled series. No cadence requirement, because there's no
  series — just two snapshots and a subtraction.
- **A single flat file, not a database.** One row per PHY, keyed by
  controller+PHY index, in the same `/boot/config/plugins/hbaviewer/`
  directory `flash.php`'s `FLASH_TOOLS` constant already uses for persisted
  plugin state. `config.php`'s existing `LSI_CFG` pattern (schema-clamped
  KEY=value file, read/write functions co-located) is the template — this
  plan follows it rather than introducing SQLite or any new dependency.

This is a narrower, faster-to-ship version of one piece of plan 020, not a
replacement for it. Plan 020's five-sub-indicator rollup with hysteresis
still needs its own persistence decision when it's picked up; this plan's
baseline file is not assumed to be that decision, though whoever writes 020
should look at what this plan ships before deciding it needs something
different.

## The reset trap — this plan is more exposed to it than 020 was

PHY error counters are maintained by the driver and **reset to zero when the
driver reloads**, which a reboot does. A baseline persisted in `/boot` therefore
outlives the counters it was measured against: after the next reboot the stored
baseline is larger than the live counter, and `current - baseline` goes negative.
Rendered naively that is a nonsense figure like `-38,412 errors since baseline`.

Plan 020 hits this too and handles it with **two independent signals**, both of
which this plan needs:

- **Host uptime decreased** since the stored sample → the box rebooted.
- **Any counter decreased** → the driver reloaded without a reboot
  (`modprobe -r mpt3sas`), which uptime alone cannot see.

`health.php`'s `health_ingest()` implements exactly this and has unit tests for
both cases. **Reuse that logic rather than reinventing it**, and store the host
uptime alongside each baseline so the comparison is possible at all.

What to do when a reset is detected is a product decision this plan must make
explicitly rather than leave to the executor. The honest options:

1. **Invalidate the baseline** and show "baseline reset by reboot — press Reset
   Baseline to re-establish". Truthful, and asks the user for one click.
2. **Auto-rebase silently** to the post-reboot counters. Convenient, but it
   quietly discards the user's reference point, which is the one thing they
   explicitly asked for.

Recommend option 1. A baseline the user set deliberately should not be moved
without telling them — and a plugin that silently rebases is exactly the class of
behaviour that made the old cumulative counters untrustworthy in the first place.

## Why this matters

`parse/phy.sh` (below) and `parse/storcli_phy.sh` emit **cumulative**
counters — invalid DWord count, running disparity errors, loss-of-sync,
reset problems — that count from either boot or the last link reset. A
cable that logged 40,000 invalid DWords two months ago and a cable that
logged its first 40,000 last night look identical in the current PHY Health
tab. There's no way to tell "this has always been like this" from "this
started degrading" without a reference point in time.

## Current state

### `scripts/get_phy_health.sh` (34 lines) — the composer

```bash
phy_storcli() {
    [ -n "$SYSFS" ] || { SYSFS=$(mktemp); trap 'rm -f "$SYSFS"' EXIT; _build_phy_sysfs > "$SYSFS"; }
    "$STORCLI" /c"$1"/pall show 2>/dev/null | bash "$DIR/parse/storcli_phy.sh" "$SYSFS"
}
phy_lsiutil() {
    require_binary || return 1
    hba_query -p"$PORT" -a 20,12,0,0 2>/dev/null | bash "$DIR/parse/phy.sh"
}
hba_each phy_storcli phy_lsiutil
```

### `scripts/parse/phy.sh` (60 lines) — the lsiutil-path JSON shape

Each PHY becomes one object:
`{"phy":N,"link":"up"|"down","inv":N,"disp":N,"sync":N,"reset":N}`

The storcli path (`parse/storcli_phy.sh`, not excerpted here — read it before
Step 1) merges the same four counters from sysfs since storcli itself
doesn't expose them, per `get_phy_health.sh`'s own header comment. Both
paths converge on the same four-field counter shape, which is what makes a
single baseline mechanism workable for both backends.

### `config.php` — the pattern this plan follows

```php
const LSI_CFG = '/boot/config/plugins/hbaviewer/hbaviewer.cfg';
const LSI_SCHEMA = [ /* key => [default, min, max] */ ];
function lsi_config_read(?string $path = null): array { /* ... */ }
function lsi_config_write(array $raw, ?string $path = null): void { /* ... */ }
```

## Scope

**In scope**:

- A new baseline store: `/boot/config/plugins/hbaviewer/phy_baseline.json`
  (or similar — pick a path in the same directory family as `LSI_CFG` and
  `FLASH_TOOLS`), keyed by `"ctrl:phy"` → `{inv, disp, sync, reset, ts}`
- Read/write functions for that store (PHP, colocated with or adjacent to
  `config.php`'s existing pattern — new file `phy_baseline.php` is probably
  cleanest so `config.php` doesn't grow a second, unrelated schema)
- A "Reset baseline" action, scoped per-controller or global (decide in
  Step 1 which the UI needs — see Steps)
- Rendering: alongside each PHY's raw counters, show delta-since-baseline
  and a computed rate (errors/hour), only when a baseline exists for that
  PHY; fall back to today's raw-only display when it doesn't
- Feed delta/rate into the existing PHY error floor logic as an additional
  signal, not a replacement. **`PHYERR_FLOOR` is in
  `scripts/parse/storcli_overview.sh`, not `hbaviewer.php`** — the first draft
  of this plan named the wrong file. Note also that plan 020 already treats that
  floor as superseded by its own rate work; check what 020 left in place before
  changing rollup behaviour, or the two will fight over the same badge.

**Out of scope**:

- Automatic/periodic snapshotting — this is user-triggered only (see
  "Relationship to plan 020")
- Any change to the existing `>0`/`PHYERR_FLOOR` rollup thresholds
  themselves — this plan adds a rate *display* and a rate *input* to
  whatever plan 020 eventually builds, it does not redesign the rollup
- Plan 020's five-sub-indicator work, flap suppression, or `unknown` state

## Steps

### Step 1: Decide baseline granularity and confirm the write path is legal

Before writing code, confirm two things against the live repo, not
assumption:

1. **Per-PHY or per-controller reset?** A global "reset all baselines"
   button is simpler; per-PHY is more precise (lets someone baseline just
   the PHY they just reseated). Recommend per-controller as the middle
   ground — check `hbaviewer.php`'s existing per-controller card boundary
   (used throughout plans 013–019) and hang the button there.
2. **`/boot` write frequency.** `/boot` is the flash drive — Unraid users
   watch its write cycles (this exact concern is called out in plan 020's
   "open questions"). A baseline reset is user-triggered and rare (not
   once-per-poll), so this is a fundamentally different write pattern than
   a periodic sampler would be — confirm that reasoning holds before
   proceeding; if the maintainer wants an even lighter touch, `/tmp` (RAM,
   resets on reboot — same trade the Performance tab already makes) is the
   fallback, at the cost of the baseline not surviving a reboot.

### Step 2: `phy_baseline.php` — store schema and read/write

Follow `config.php`'s shape:

```php
const PHY_BASELINE_PATH = '/boot/config/plugins/hbaviewer/phy_baseline.json';

function phy_baseline_read(?string $path = null): array {
    $path ??= PHY_BASELINE_PATH;
    if (!is_file($path)) return [];
    $data = json_decode((string) @file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function phy_baseline_write(array $baseline, ?string $path = null): void {
    $path ??= PHY_BASELINE_PATH;
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode($baseline, JSON_PRETTY_PRINT));
}

// One controller's worth of PHYs, captured at "now".
function phy_baseline_set(int $ctrl, array $phys, ?string $path = null): void {
    $b = phy_baseline_read($path);
    $ts = time();
    foreach ($phys as $p) {
        $b["$ctrl:{$p['phy']}"] = ['inv'=>$p['inv'],'disp'=>$p['disp'],'sync'=>$p['sync'],'reset'=>$p['reset'],'ts'=>$ts];
    }
    phy_baseline_write($b, $path);
}
```

Unit-test this the way `config.php`'s functions are tested (find and follow
the existing test pattern for config read/write before assuming PHPUnit-
style vs the repo's actual CLI-harness style — see `tests/run_php.sh`).

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/phy_baseline.php` → clean

### Step 3: Wire a reset endpoint

Add a POST action (CSRF-protected the same way `settings.php`'s existing
save handler and `flash.php`'s dispatch are — check both before picking a
pattern) that calls `phy_baseline_set()` with the controller's current PHY
readout.

### Step 4: Render delta + rate

In whichever file renders the PHY Health tab (`hbaviewer.php` or
`ajax_info.php`'s `type=phy` path — confirm exact location), for each PHY
look up its baseline entry; if present, compute:

```php
$elapsed_hours = max(($now - $baseline['ts']) / 3600, 1/60); // floor at 1 minute
$delta = $current['inv'] - $baseline['inv']; // repeat per counter
$rate  = $delta / $elapsed_hours;
```

Display raw counter (as today) plus `Δ since baseline` and `rate/hr` when a
baseline exists; omit both when it doesn't, rather than showing zeros that
could be misread as "no baseline == no errors."

**Handle counter resets** (a link reset or reboot can zero the hardware
counter below the stored baseline) — clamp negative deltas to "baseline
invalid, reset it" rather than showing a negative error count.

### Step 5: Feed into rollup (optional, coordinate with whoever owns 020)

If plan 020 is not yet in progress when this ships, leave the rollup alone
— this plan's job is display, not policy. If 020 is already underway,
check with its owner before wiring rate into the rollup so the two plans
don't fight over the same threshold.

## Test plan

- `phy_baseline_read`/`write`/`set` are pure functions over an injectable
  path — test them directly the way `config.php`'s functions are already
  tested (locate that existing test file first and match its style).
- Delta/rate math: three fixture cases — normal (current > baseline),
  counter-reset (current < baseline, must clamp not go negative), and no
  baseline (must render raw-only, no crash on a missing array key).
- `bash tests/run.sh` stays green; add new cases rather than modifying
  existing PHY goldens (the raw counter display is unchanged, only additive).

## Done criteria

- [ ] `php -l` clean on the new file and every touched file
- [ ] Baseline read/write round-trips through a temp path in a unit test
- [ ] Delta math test covers: normal, counter-reset-clamped, no-baseline
- [ ] `bash tests/run.sh` → `--- all pass ---`, existing PHY goldens
      unchanged, new baseline-specific cases added and passing
- [ ] Reset button visible on the PHY Health tab, scoped per Step 1's
      decision, CSRF-protected consistent with the rest of the plugin
- [ ] `git status --porcelain` shows only the intended new/touched files

## STOP conditions

- The drift check prints anything.
- You find yourself building a periodic sampler, a cron job, or anything
  that writes to `/boot` on a fixed interval rather than on user action —
  that's the piece of plan 020 this plan explicitly does not attempt. Stop
  and flag it as a 020 dependency instead.
- A negative delta renders as a negative error count anywhere in the UI.
- The baseline write path is anything other than user-triggered — re-read
  "Relationship to plan 020" before adding any automatic write.

## Maintenance notes

- **This is the first persisted state below `/boot` this plugin has that
  isn't config or flash-tool storage.** If a second feature needs
  persistence (plan 020's history, the notification last-fired state in a
  separate plan), consider whether they should share one small storage
  module instead of each inventing its own JSON-file convention.
- **The rate is only as good as the baseline's freshness.** A baseline set
  once at install and never touched again just measures "errors since
  install," which is closer to the raw counter than a real rate. The UI
  should make "when was this baselined" visible, not just the computed
  number, so a stale baseline doesn't read as a fresh one.
