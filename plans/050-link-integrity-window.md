# Plan 050: Two tabs, two meanings of "errors/hr" — neither says which

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 4749006..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/health.php source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php source/usr/local/emhttp/plugins/hbaviewer/phy_baseline.php`
> Expected output: **nothing**. Every excerpt below is quoted from `4749006`
> (`dev` tip, 2026-08-05). Any difference is a STOP condition.
>
> **Re-stamped `cc6def6` → `4749006`.** Plan 049 landed in between and changed
> `health.php`: `health_ingest()` and `health_rates()` now count how often each
> PHY index appears in each sample and skip the comparison for any index that is
> not unique in both, so a duplicate index can never again be read as a counter
> reset. The excerpt below is refreshed to match. **Do not remove or weaken
> those guards** — see STOP conditions.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW — adds context to two strings and one threshold. No new state,
  no new endpoint, no hardware access.
- **Depends on**: none. **Independent of plan 049**, which is an expander-only
  collector bug that empties the ring; this is about what a *full, healthy*
  ring means. Both touch `health.php`; execute 049 first if both are in
  flight.
- **Category**: bug (misleading display, not a wrong number)
- **Planned at**: `cc6def6`, 2026-08-05
- **Found by**: the maintainer, comparing his own two tabs — and then
  **falsifying the first explanation** with the measurement in the next
  section. That sequence is why this plan says what it does.

## The measurement that decided this plan

PHY Health, `/c0`:

```text
Top offenders:  PHY 5 — 0/10 · /dev/sdc     3.3/hr
                inv 1.9 · disp 1.0 · sync 0.4 · reset 0.0
PHY 5 row:      115 invalid DWords (Δ115 · 1.9/hr)
                 59 disparity      (Δ59  · 1.0/hr)
                 23 loss of sync   (Δ23  · 0.4/hr)
```

HBA Health, same controller, same moment: **`0/hr`, "No new cabling errors on
any PHY"**.

The first hypothesis was that the Health tab's window was too short to see a
slow fault — its ring only advances when the tab is rendered, so it can be
minutes wide. **That hypothesis was wrong**, and one command killed it:

```text
ring span: 46189s (12.83 h) over 9 samples
(no PHY printed — not one counter moved)
```

**Twelve hours and fifty minutes with zero growth on every counter of every
PHY.** So the Health tab is not under-observing: it is reporting a genuinely
clean recent window, and it is *right*.

What that means for the other tab is the actual finding:

> `1.9/hr` on the PHY tab is **Δ-since-baseline ÷ hours-since-baseline** — a
> long-run average. 115 errors that all landed in a burst days ago produce
> "1.9/hr" forever, and will keep producing it, decaying asymptotically toward
> zero, long after the cable was fixed or the event ended.

Neither number is wrong. They answer different questions and **neither says
which question it answered**, so a user comparing them concludes the plugin
contradicts itself — and, worse, may pull a healthy drive.

The correction matters operationally: on this hardware the link has been clean
for at least 12.8 hours, and the 115 errors are history, not a live fault.

## Current state

The PHY tab's rate, straight from the stored baseline:

```php
// phy_baseline.php — the divisor is "time since the user pressed the button"
   The rate divisor is floored at one minute so a baseline set seconds ago
```

The Health tab's rate, from the two ends of the ring:

```php
/* Per-PHY per-counter rates in events/hour between the OLDEST and NEWEST
   sample in $ring — not a sliding average, the two ends of whatever window
   the ring currently holds. */
function health_rates(array $ring): array {
    if (count($ring) < 2) return [];
    $oldest = $ring[0];
    $newest = $ring[count($ring) - 1];
    $dtSecs = ($newest['t'] ?? 0) - ($oldest['t'] ?? 0);
    if ($dtSecs < 60) return [];
    $dtHours = $dtSecs / 3600.0;

    $oldByIdx = [];
    $oldCount = [];
    foreach ($oldest['phys'] ?? [] as $p) { $oldByIdx[$p['idx']] = $p; $oldCount[$p['idx']] = ($oldCount[$p['idx']] ?? 0) + 1; }
    $newCount = [];
    foreach ($newest['phys'] ?? [] as $p) $newCount[$p['idx']] = ($newCount[$p['idx']] ?? 0) + 1;
```

(The `$oldCount` / `$newCount` bookkeeping is plan 049's duplicate-index guard,
merged 2026-08-05. It is not part of this plan and must survive it.)

Both render a bare `N/hr`. The PHY tab shows `Δ115 · 1.9/hr`, which at least
hints at "since something"; the Health tab shows `1.9/hr`-style values with no
period at all, and its all-clear reads *"No new cabling errors on any PHY (0
per hour)"* — true, but silent about *over what*.

```php
const HEALTH_RING_CAP   = 240;   // ~4h at the 60s page refresh; RAM, so no wear budget
```

That comment is also wrong and helped mislead the first diagnosis: nothing
refreshes the Health tab on a timer, so the ring's span is however long the
user's visits happen to straddle — 12.8 h here, minutes on another box.

## Scope

**In scope**:

- **Say the period, on both tabs.** The Health row's reason gains the window it
  measured (*"no new errors in the last 12.8 h"*); the PHY tab's rate is
  labelled as an average since the baseline, with when that was.
- **A "recent" rate beside the historical one on the PHY tab**, taken from the
  health ring, so a burst that ended days ago cannot read as current activity.
  This is the substantive fix — it makes the two tabs agree by showing both
  numbers in one place, rather than by changing either.
- **Do not claim `ok` from a trivially short window.** Still worth doing: at
  the 60-second arithmetic floor, "0/hr" is not evidence of a clean link. It
  was *not* the cause here, so it is a small item at the end, not the headline.
- Correct the `HEALTH_RING_CAP` comment.

**Out of scope**:

- Changing either rate's arithmetic. Both are correct.
- A background sampler to keep the ring warm. This plugin has no daemon by
  design (ARCHITECTURE.md) and this plan must not smuggle one in.
- Deciding *for* the user whether an old burst matters. Show both numbers and
  let them judge.

## Steps

### Step 1: Label the Health tab's window

`health_indicators()` receives the ring; it can compute the span. Put it in the
reason, via `lsi_age_str()` so it reads like the SMART table's "Collected 2 h
ago":

- clean: *"No new cabling errors on any PHY in the last 12.8 h"*
- dirty: *"PHY 5 loss of sync errors rising (0.4/hr over the last 12.8 h)"*

**Verify**: `tests/health_test.php` asserts the span reaches the reason for
both states; `tests/ajax_render_test.php` asserts it renders on the row.

### Step 2: Label the PHY tab's rate as an average since the baseline

The bar already prints *"Baseline set 2026-08-02 14:31"*. The per-counter cells
print `Δ115 · 1.9/hr` with no such context, and the top-offenders list prints a
bare `3.3/hr`.

Make the meaning explicit — a tooltip on the rate and a word in the offenders
header (*"errors/hr, average since baseline"*) is enough. **Do not** shorten
the number or hide it.

**Verify**: render test for the wording; no golden moves.

### Step 3: Show the recent rate beside it

The health ring already holds per-PHY counters for this controller over a much
more recent window. Read it (read-only — `health_store_read()`, never
`health_ingest()`, which must stay owned by the Health tab) and render, per
PHY:

```text
115   Δ115 · 1.9/hr since baseline · 0/hr in the last 12.8 h
```

A PHY whose historical average is non-zero and whose recent rate is zero is the
common, reassuring case, and today it is invisible.

Rules:

- No ring, or a ring spanning < 60 s → print nothing extra. Absence, not zero.
- The ring is per controller (`hbav_health_c<N>.json`) — index it by the
  controller the row belongs to, never by position in a list.

**Verify**: fixture test with a synthetic ring showing the recent column
appearing only when the ring is usable, and a case where recent > historical
(a fault that just started) still reads correctly.

### Step 4: Refuse `ok` from a window that proves nothing

At the 60-second floor a "0/hr" all-clear is not evidence. Keep
`health_rates()` as is (the arithmetic is sound and the value column can use
it); in `health_indicators()`, resolve `unknown` rather than `ok` when the
window is shorter than a threshold — 30 minutes is a reasonable first cut —
with a reason that says so: *"watched for 4 min — too short to rule out a slow
fault"*.

**Growth at any window length still warns immediately.** The floor applies only
to the all-clear. This is the load-bearing rule of the step.

**Verify**: three unit tests — short+clean → `unknown`, long+clean → `ok`,
short+dirty → warns anyway.

### Step 5: Fix the ring-cap comment

`// ~4h at the 60s page refresh` describes a cadence that does not exist. State
what actually happens: one sample per Health-tab render, so the span is however
often someone looks. A wrong comment about cadence is what made the 60-second
floor look sufficient in the first place, and what sent the first diagnosis of
this very issue down the wrong path.

## Test plan

- All of it is pure over injected inputs: synthetic rings and baselines, no
  hardware, no HTTP.
- No golden moves — nothing changes the parsers.
- `bash tests/run.sh` stays green.

## Done criteria

- [ ] The Health row states the window it measured over, in both states
- [ ] The PHY tab's rate is identifiable as an average since the baseline
- [ ] A PHY with a historical average and no recent activity says both
- [ ] Short window + no growth → `unknown`; long window + no growth → `ok`
- [ ] Growth warns immediately regardless of window length
- [ ] `HEALTH_RING_CAP`'s comment describes the real cadence
- [ ] On the maintainer's box: PHY 5 shows ~1.9/hr since baseline **and** 0/hr
      recent, and HBA Health says it watched ~12.8 h
- [ ] `bash tests/run.sh` → `--- all pass ---`

## STOP conditions

- Either rate's arithmetic changes.
- Plan 049's duplicate-index guards in `health_ingest()` / `health_rates()` are
  removed, weakened, or bypassed. They are the fix for issue #12 and are pinned
  by two mutation-verified tests in `tests/health_test.php`; if either of those
  tests goes red, you have broken something that is not yours to touch.
- The recent-rate lookup writes to the ring, or moves ingestion out of the
  Health tab.
- A real error rate is delayed, suppressed or downgraded by Step 4's threshold.
- The PHY tab's Δ or its historical rate is removed or hidden — the fix is
  *more* context, not less.

## Maintenance notes

- **The lesson is about the first diagnosis, not the bug.** The obvious story
  ("the Health tab hasn't watched long enough") was plausible, fitted the
  symptom, and was wrong; one command measuring the actual window killed it.
  The number a rate is divided by is as much a part of the reading as the
  count, and neither tab was showing it.
- **Two stores, two questions.** `/boot` baseline = "has anything happened
  since I fixed it?"; `/tmp` ring = "is anything happening now?". Both are
  worth having. Any future surface that shows one must say which it is.
- The health ring advances only when the Health tab is rendered — a deliberate
  consequence of having no sampler. Every window threshold in `health.php`
  really means "how often does this person open that tab".
