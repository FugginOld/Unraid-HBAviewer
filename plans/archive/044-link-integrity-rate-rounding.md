# Plan 044: Stop Link Integrity reporting "errors rising (0/hr)"

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat ce746a4..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/health.php source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php tests/health_test.php`
> Expected output: **nothing**. Every excerpt below is quoted from `ce746a4`
> (`dev` tip, 2026-08-03). Any difference is a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW — display formatting only. No state, threshold, rate
  arithmetic or rollup logic changes.
- **Depends on**: none
- **Category**: bug
- **Planned at**: `ce746a4`, 2026-08-03
- **Reported by**: maintainer, on his own hardware, with screenshots

## Why this matters

The HBA Health tab currently renders a self-contradiction. Observed on the
maintainer's 9400-16i:

```
Warning — PHY 5 loss of sync errors rising (0/hr)
Link Integrity                                0/hr
```

Something cannot be *rising* at zero per hour. A user reading that reasonably
concludes the indicator is broken and stops trusting the tab — which is worse
than the tab not existing, because it is the one screen that claims to tell
you whether the cabling is healthy.

**The detection is correct.** The PHY Health tab, reading the same counters,
shows the truth for that phy:

```
PHY 5   invalid dwords 70 (Δ70 · 2.0/hr)   disparity 37 (Δ37 · 1.1/hr)
        loss of sync   14 (Δ14 · 0.4/hr)   reset 0 (Δ0 · 0/hr)
```

Loss of sync at 0.4/hr is a genuine `warning` — the link actually dropped and
re-established 14 times. The indicator picked the right counter and the right
severity. It then printed the rate with `%.0f`, and `sprintf('%.0f', 0.4)` is
`"0"`.

This was reproduced exactly, byte-for-byte including the banner text, by
feeding those four real rates through `health_indicators()`.

## Current state

### `health.php:151-170` — the defect, both strings

```php
    // ── link_integrity: worst PHY, worst counter, its index names the reason ──
    if (empty($rates)) {
        $link_integrity = ['state' => 'unknown', 'reason' => 'Not enough samples yet', 'value' => '—'];
    } else {
        $labels = ['inv' => 'invalid dword', 'disp' => 'disparity', 'sync' => 'loss of sync', 'rst' => 'reset problem'];
        $worstState = 'ok'; $worstRank = 0; $worstReason = 'No error growth'; $worstValue = '0/hr';
        foreach ($rates as $r) {
            foreach ($labels as $k => $label) {
                $s = health_rate_state($k, $r[$k]);
                $rank = health_rank($s);
                if ($rank > $worstRank) {
                    $worstRank  = $rank;
                    $worstState = $s;
                    $worstReason = sprintf('PHY %s %s errors rising (%.0f/hr)', $r['idx'], $label, $r[$k]);
                    $worstValue  = sprintf('%.0f/hr', $r[$k]);
                }
            }
        }
        $link_integrity = ['state' => $worstState, 'reason' => $worstReason, 'value' => $worstValue];
    }
```

Both `%.0f` occurrences are the bug. Everything else in this block is correct
and must not change.

### `ajax_info.php:340` — the convention that already solves this

The PHY Health tab got it right. This is why that tab shows `0.4/hr`:

```php
         . ' &middot; ' . number_format($r, $r > 0 && $r < 10 ? 1 : 0) . '/hr</div>';
```

One decimal for a non-zero rate below 10, integer at 10 and above. So a rate
of `0.4` prints `0.4`, `2.0` prints `2.0`, `70` prints `70`, and a true zero
still prints `0` rather than `0.0`.

### `ajax_info.php` already requires `health.php`

```php
require_once __DIR__ . '/view.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inlet.php';
require_once __DIR__ . '/event_archive.php';
require_once __DIR__ . '/cached_read.php';
require_once __DIR__ . '/health.php';
```

So a helper defined in `health.php` is callable from `ajax_info.php` with no
new include. **Put the rule in one place and have both callers use it** —
this repo has twin-copy conventions elsewhere (`band_of` in
`parse/storcli_overview.sh` and `parse/hba.sh`, with a "keep both identical"
comment) but those are twins because a *shell* filter cannot call a *PHP*
function. Here both callers are PHP in the same require graph, so there is no
reason to duplicate and every reason not to: the duplicate is what drifted.

### Why the tests did not catch it

`tests/health_test.php` covers `health_rate_state()`'s thresholds thoroughly:

```php
check('50/hr invalid dword is watch',    health_rate_state('inv', 50)  === 'watch');
check('1/hr loss-of-sync is warning (no watch tier)', health_rate_state('sync', 1) === 'warning');
check('10/hr loss-of-sync is critical',               health_rate_state('sync', 10) === 'critical');
```

and asserts `link_integrity`'s **state**:

```php
check('link_integrity unknown on single sample', $indOne['link_integrity']['state'] === 'unknown');
```

**No test asserts the `value` or `reason` strings at all.** The state was
pinned; the rendered number never was. That is the actual coverage gap, and
fixing it is as much the point of this plan as the format specifier.

### Repo conventions to match

- Comments explain **why**. See `health.php:107-110` for the tone:

```php
/* Rate thresholds, events/hour. Loss-of-sync and phy-reset have NO watch
   tier on purpose: unlike invalid dwords, which trickle in from ordinary
   marginal signalling, those two mean the link actually dropped and
   re-established. */
```

- `tests/health_test.php` is a plain assert-and-echo script with a
  `check(string $name, bool $ok)` helper, ending `health: all pass`.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Full suite | `bash tests/run.sh` | ends `--- all pass ---` |
| PHP subset | `bash tests/run_php.sh` | all pass |
| PHP lint | `php -l <file>` | `No syntax errors detected` |

No package manager, no build step. `php` may not be on PATH; `run_php.sh`
falls back to `php:8.2-cli` via Docker — report which you used.

## Scope

**In scope**:

- `source/usr/local/emhttp/plugins/hbaviewer/health.php` — add the helper,
  use it for both strings
- `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` — **line 340
  only**, swapped to call the helper
- `tests/health_test.php`

**Out of scope** (do NOT touch):

- `health_rate_state()` thresholds, `health_rank()`, `health_rates()`,
  `health_ingest()`, `health_rollup()`. The detection is correct — the
  maintainer's box proves it picked the right phy, the right counter and the
  right severity. Only the printed number is wrong.
- The worst-of selection logic, including the fact that the reported rate can
  be *lower* than another counter's on the same phy (sync 0.4/hr outranks inv
  2.0/hr because loss-of-sync has no watch tier). That is deliberate — see
  Maintenance notes.
- `ajax_info.php:464`'s `number_format($o['rate_total'], 1)` in the Top
  Offenders table. Different column, always-one-decimal by choice, leave it.
- Any change to the Health tab's markup, icons, dots or gauge.

## Git workflow

- Branch: `advisor/044-link-integrity-rate-rounding`
- One commit. Imperative message matching `git log`, e.g.
  `Stop Link Integrity reporting a rising error rate as 0/hr`.
- Do NOT push or open a PR.

## Steps

### Step 0: Record the pre-existing failure baseline

```
bash tests/run.sh 2>&1 | grep '^FAIL' | sort > /tmp/baseline-fails.txt
cat /tmp/baseline-fails.txt
```

Quote this in your final report. No later run may add a name.

### Step 1: Add the shared helper to `health.php`

Place it next to `health_rate_state()`. Required behaviour and a comment
explaining why the precision is conditional:

```php
/* Events/hour as a display string. One decimal below 10, integer at or above
   it: a genuine 0.4/hr loss-of-sync is a `warning` (the link dropped and came
   back), and printing it with %.0f rendered the indicator as
   "errors rising (0/hr)" — a self-contradiction that reads as a broken tab.
   A true zero still prints "0/hr", not "0.0/hr". */
function health_rate_str(float $rate): string {
    // A non-zero rate must never render as zero. health_rates() measures
    // oldest-to-newest across the whole ring, and samples are only appended
    // when the Health tab renders — so opening the tab a day apart gives a
    // ~24h window in which ONE loss-of-sync event is 0.04/hr: still a
    // `warning`, but it rounds to "0.0/hr". Floor it rather than chasing the
    // problem with more decimals, which just moves it to 0.004.
    if ($rate > 0 && $rate < 0.05) return '<0.1/hr';
    return number_format($rate, $rate > 0 && $rate < 10 ? 1 : 0) . '/hr';
}
```

**Verify**:
```
php -r "require 'source/usr/local/emhttp/plugins/hbaviewer/health.php';
foreach ([0, 0.4, 1.0, 2.0, 9.99, 10, 70, 500.4] as \$r)
    printf('%-7s -> %s%s', \$r, health_rate_str(\$r), PHP_EOL);"
```
→ `0 -> 0/hr`, `0.4 -> 0.4/hr`, `1 -> 1.0/hr`, `2 -> 2.0/hr`, `9.99 -> 10.0/hr`,
`10 -> 10/hr`, `70 -> 70/hr`, `500.4 -> 500/hr`

Also check the floor boundary, which is where the first version of this plan
was wrong: `0.001 -> <0.1/hr`, `0.04 -> <0.1/hr`, `0.049 -> <0.1/hr`,
`0.05 -> 0.1/hr`, and `0 -> 0/hr` (a true zero must NOT be floored).

### Step 2: Use it for both strings in `health_indicators()`

Replace only the two `sprintf` lines. Keep the surrounding loop, the ranking,
and the `$worstReason`/`$worstValue` defaults exactly as they are:

```php
                    $worstReason = sprintf('PHY %s %s errors rising (%s)', $r['idx'], $label, health_rate_str($r[$k]));
                    $worstValue  = health_rate_str($r[$k]);
```

Note the format specifier for the rate changes from `%.0f/hr` to `%s`,
because the helper already appends the unit.

**Verify** — reproduce the maintainer's exact case:
```
php -r "require 'source/usr/local/emhttp/plugins/hbaviewer/health.php';
\$rates = [['idx'=>5,'inv'=>2.0,'disp'=>1.1,'sync'=>0.4,'rst'=>0.0]];
\$ring  = [['t'=>0,'temp'=>70,'temp_band'=>'elevated','drives'=>16,'read_ok'=>true,
  'link'=>['width'=>8,'max_width'=>8,'speed'=>'8.0 GT/s','max_speed'=>'8.0 GT/s'],'phys'=>[]]];
\$li = health_indicators(\$ring, \$rates, time())['link_integrity'];
echo \$li['state'], ' | ', \$li['reason'], ' | ', \$li['value'], PHP_EOL;"
```
→ `warning | PHY 5 loss of sync errors rising (0.4/hr) | 0.4/hr`

The state must still be `warning` and the counter must still be loss of sync.
If either changed, you have altered detection logic — STOP.

### Step 3: Remove the duplicate in `ajax_info.php`

Line 340 only. Swap the inline expression for the helper so there is exactly
one definition of the rule:

```php
         . ' &middot; ' . health_rate_str($r) . '/hr</div>';
```

**Careful**: the helper already appends `/hr`, so the trailing `. '/hr'` on
that line must be **removed**, not kept. The rendered output must be
byte-identical to before.

**Verify**:
```
php -l source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php
bash tests/run_php.sh 2>&1 | tail -3
```
→ lint clean, and **no `ajax_render` assertion may change** — this step is a
pure refactor with identical output.

### Step 4: Close the coverage gap

Add to `tests/health_test.php`, following the existing `check(...)` style.
The assertions that would have caught this:

- a sub-1.0 sync rate renders `0.4/hr` in **both** `value` and `reason`, and
  the reason contains no `(0/hr)`
- state for that case is still `warning` and names loss of sync
- a rate of exactly `0` still renders `0/hr`, not `0.0/hr`
- a rate above 10 renders as an integer (`70/hr`)
- `health_rate_str()` directly across the boundary: `9.99` → `10.0/hr`,
  `10` → `10/hr`

**Verify**:
```
bash tests/run.sh 2>&1 | tail -2
bash tests/run.sh 2>&1 | grep '^FAIL' | sort > /tmp/after-fails.txt
diff /tmp/baseline-fails.txt /tmp/after-fails.txt && echo "NO NEW FAILURES"
```

## Test plan

- New assertions in `tests/health_test.php` as listed in Step 4, modelled on
  the existing `check('link_integrity unknown on single sample', …)` line.
- **Mutation check** — after the suite is green, run each, confirm the named
  result, restore, and **report all three**:
  1. Revert `health_rate_str` to `number_format($rate, 0) . '/hr'` → the
     sub-1.0 `value` and `reason` assertions must both fail.
  2. Change the boundary to `$rate > 0 && $rate <= 10` → the `10 -> 10/hr`
     assertion must fail.
  3. Drop the `$rate > 0` guard so a true zero renders `0.0/hr` → the
     zero-renders-`0/hr` assertion must fail.

## Done criteria

ALL must hold:

- [ ] `php -l` clean on `health.php` and `ajax_info.php`
- [ ] Step 2's reproduction prints
      `warning | PHY 5 loss of sync errors rising (0.4/hr) | 0.4/hr`
- [ ] No `sprintf('%.0f'` remains in `health.php`. **Do not grep for the bare
      string `%.0f`** — the helper's required comment quotes the old bug, so a
      bare grep correctly returns 1. Check for the *format call*, not the text:
      `grep -c "sprintf('%.0f" source/usr/local/emhttp/plugins/hbaviewer/health.php` → `0`
- [ ] `grep -c "number_format(\$r, \$r > 0" source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` → `0`
      (the duplicate is gone, not merely shadowed)
- [ ] `bash tests/run.sh` adds no failure name absent from the Step 0 baseline
- [ ] `git diff --stat -- tests/expected/` is **empty** — no golden moves
- [ ] `git status --short` lists only files from the In-scope list

## STOP conditions

Stop and report — do not improvise — if:

- The drift check prints anything, or the excerpts above do not match.
- Step 2's reproduction returns a state other than `warning`, or names a
  counter other than loss of sync. That means detection logic moved, which
  this plan must not touch.
- Any `tests/expected/` golden changes.
- Any `ajax_render` assertion changes in Step 3 — that step must be output-identical.
- You conclude the thresholds in `health_rate_state()` are wrong. They may
  well be worth revisiting, but not here: the maintainer's box shows them
  producing the right verdict, and a threshold change is a behaviour change
  needing its own evidence.

## Maintenance notes

- **The reported rate is the worst *counter's* rate, which can be numerically
  lower than another counter on the same phy.** On the maintainer's box,
  sync 0.4/hr outranks inv 2.0/hr because loss-of-sync has no watch tier
  (`health.php:107-110` explains why). So "Link Integrity 0.4/hr" sitting
  beside a Top Offenders row reading "3.5/hr" is correct — one is the worst
  single counter, the other is that phy's total. If this confuses users again,
  the fix is a clearer label, **not** changing which rate is reported.
- **`health_rate_str()` is now the single definition of rate formatting.**
  If a third caller appears, use it; do not reintroduce an inline
  `number_format`.
- **The coverage gap was the real defect.** Every threshold in
  `health_rate_state()` had a test; not one assertion looked at what the user
  actually reads. When adding an indicator, assert the rendered `value` and
  `reason`, not just `state`.
