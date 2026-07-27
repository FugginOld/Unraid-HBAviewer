# Plan 001: Stop the Settings page claiming a notification that never fires

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 0346777..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/settings.php README.md`
> If either file changed since this plan was written, compare the "Current
> state" excerpts against the live code before proceeding; on a mismatch,
> treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: docs
- **Planned at**: commit `0346777`, 2026-07-26

## Execution record

**Status: DONE — approved on review, not yet merged.**

| Field | Value |
| ----- | ----- |
| Executed | 2026-07-26, by a dispatched executor subagent in an isolated worktree |
| Branch | `advisor/001-settings-notification-claim` |
| Commit | `6c7ac03` — "Correct the Alert Threshold help text" |
| Baseline | `0346777` (drift check ran clean; the `<small>` line matched this plan's excerpt exactly) |
| Diff | 1 file, 1 insertion, 1 deletion — `settings.php:130` only |

The change that landed:

```diff
-          <small>Unraid notification fires when temperature reaches this value.</small>
+          <small>The Overview badge and dashboard tile turn red at or above this temperature, and amber within 10 °C of it. HBAviewer does not send notifications.</small>
```

**Review findings (verified independently, not taken from the executor's report):**

- Scope clean. `git diff --stat 0346777..HEAD` shows exactly one file. Nothing
  outside the in-scope list; worktree had no uncommitted leftovers.
- The new sentence is factually true, which this plan names as the only thing a
  reviewer must check. Both backends use the same thresholds —
  `parse/storcli_overview.sh:51-53` and `parse/hba.sh:78-80` — and
  `view.php:8-13` confirms `alert` is `#e74c3c` (red) and `warn` is `#f39c12`
  (amber). The 10 °C band is `-ge $(( ALERT - 10 ))` in both.
- "HBAviewer does not send notifications" re-confirmed by sweeping the repo for
  `notify` call sites: zero. No cron entry in `hbaviewer.plg` either.
- Done criteria: the two greps pass, shell lint passes, `bash tests/run.sh`
  shell half passes (parser goldens + all 14 flash tests).

**Known gaps in the verification — neither blocks the change:**

- `php -l` was **not run**. No `php` binary on the workstation. The edit is a
  static string inside HTML, not PHP code, so the syntax risk is nil, but this
  is unrun rather than passed. Re-run `bash tests/run.sh` on the Unraid box,
  which has `php`, to close it properly.
- `bash tests/run.sh` exits 1 overall because the PHP half falls back to Docker
  and the daemon is not running. This is identical at the baseline commit —
  environmental, not a regression.

**Still outstanding**: merge to `dev`, and the optional visual check on the
Settings page. Nothing else.

## Why this matters

The Alert Threshold field on the Settings page tells the user "Unraid
notification fires when temperature reaches this value." No code in this
repository ever calls Unraid's notification helper. The threshold's only effect
is to colour a badge on the Overview card and the dashboard tile — both of
which the user must already be looking at.

The concrete cost: someone sets a threshold of 75 °C, believes they are covered
for a controller cooking itself in a hot rack, and receives nothing. An HBA
running hot for weeks is a real failure mode, and the plugin is currently
telling people it will warn them about it.

This plan makes the copy honest. It deliberately does **not** implement
notifications — that is a separate feature involving a cron job, notification
state to avoid spamming, and hardware to test against. Shipping truthful text
today is worth more than leaving a false promise in place while that feature is
designed.

## Current state

Files involved:

- `source/usr/local/emhttp/plugins/hbaviewer/settings.php` — the full settings
  form. The Alert Threshold row is at lines 127–135.
- `README.md` — the Configuration table. Already accurate; verify only.

The exact block to change, `settings.php:127-135`:

```php
      <div class="lu-s-row">
        <div class="lu-s-label">
          Alert Threshold (°C)
          <small>Unraid notification fires when temperature reaches this value.</small>
        </div>
        <div class="lu-s-control">
          <input type="number" name="threshold" value="<?= (int)$cfg['ALERT_THRESHOLD'] ?>" min="1" max="150">
        </div>
      </div>
```

The line to replace is the `<small>` on line 130 only.

What the threshold actually does, so the replacement text is accurate — from
`source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh:51-53`:

```bash
if   [ "$TEMP" -ge "$ALERT" ];          then RANK=2
elif [ "$TEMP" -ge $(( ALERT - 10 )) ]; then RANK=1
else RANK=0; fi
```

`RANK` 2 becomes status `alert` (red badge), 1 becomes `warn` (amber), 0 becomes
`ok` (green). So the threshold drives a **red** badge at or above the value and
an **amber** badge from 10 °C below it. That amber band is currently
undocumented anywhere in the UI and is worth mentioning.

`README.md:171` already describes this correctly and needs no change:

```markdown
| Alert Threshold | 80 °C | The badge turns red (ALERT) at or above this temperature. |
```

**Repo conventions that apply here:**

- The `<small>` helper texts in this file are one short sentence, sentence case,
  ending in a period. See the neighbouring rows at `settings.php:107` ("How
  HBAviewer reads controller information.") and `settings.php:119` ("Run lsiutil
  without arguments to list ports. Usually 1.").
- HTML entities are used for symbols rather than literal characters in some
  places (`&amp;`, `&#9888;`), but the degree sign appears as a literal `°` in
  this file — see `settings.php:129`. Match the file: literal `°` is fine.

## Commands you will need

| Purpose        | Command                                                              | Expected on success        |
|----------------|----------------------------------------------------------------------|----------------------------|
| PHP lint       | `find source tests -name '*.php' -print0 \| xargs -0 -r -n1 php -l`  | exit 0, "No syntax errors" |
| Shell lint     | `find source tests -name '*.sh' -print0 \| xargs -0 -r -n1 bash -n`  | exit 0                     |
| Full test suite| `bash tests/run.sh`                                                  | `--- all pass ---`, exit 0 |

If `php` is not installed locally, `tests/run.sh` falls back to a
`php:8.2-cli` Docker container automatically. If neither `php` nor Docker is
available, the shell half of the suite still runs and must still pass; note in
your report that the PHP half could not run locally.

## Scope

**In scope** (the only file you should modify):

- `source/usr/local/emhttp/plugins/hbaviewer/settings.php`

**Out of scope** (do NOT touch, even though they look related):

- Anything that would actually implement notifications — no new cron entry, no
  call to `/usr/local/emhttp/webGui/scripts/notify`, no new config key, no
  change to `hbaviewer.plg`. That is deliberately deferred; adding it here would
  make this a large, hardware-dependent change instead of a one-line one.
- `source/usr/local/emhttp/plugins/hbaviewer/config.php` — the `ALERT_THRESHOLD`
  schema entry is correct and its range (1–150) stays as it is.
- `README.md` — verified accurate above; changing it is out of scope.
- The badge colours and the `RANK` thresholds in
  `scripts/parse/storcli_overview.sh` and `scripts/parse/hba.sh`. The behaviour
  is fine; only the description of it is wrong.

## Git workflow

- Branch: `advisor/001-settings-notification-claim`
- One commit. Message style matches this repo's history — a short imperative
  subject line, no conventional-commit prefix. Recent examples from `git log`:
  `Add ca_profile.xml for HBAviewer plugin metadata`, `Update category in
  HBAviewer.xml`. Suggested: `Correct the Alert Threshold help text`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Replace the false claim with what the threshold actually does

In `source/usr/local/emhttp/plugins/hbaviewer/settings.php`, replace line 130:

```php
          <small>Unraid notification fires when temperature reaches this value.</small>
```

with:

```php
          <small>The Overview badge and dashboard tile turn red at or above this temperature, and amber within 10 °C of it. HBAviewer does not send notifications.</small>
```

Change nothing else in the file — not the label, not the input, not the
surrounding markup.

**Verify**: `grep -c 'does not send notifications' source/usr/local/emhttp/plugins/hbaviewer/settings.php`
→ prints `1`

**Verify**: `grep -rn 'notification fires' source/` → no matches, exit code 1

### Step 2: Confirm no other file makes the same claim

Search the whole plugin source and the plugin manifest for any other wording
that promises notifications or alerts being sent.

```bash
grep -rniE 'notif|will (alert|warn|email|page) you' \
  --include='*.php' --include='*.sh' --include='*.page' --include='*.plg' \
  --include='*.md' . | grep -v chart.umd
```

Expect exactly one hit: the new text you added in Step 1. The `grep -v
chart.umd` filter excludes the vendored Chart.js bundle, which is a downloaded
build artifact and not part of this repository.

If you find any *other* file making a notification promise, fix the wording
there too using the same honest phrasing, and note it in your report.

**Verify**: the command above returns only the line from Step 1 (plus any
additional lines you fixed and listed in your report).

### Step 3: Lint and run the suite

Nothing about this change is behavioural, so the suite should be untouched by
it. Run it anyway to confirm you broke nothing.

**Verify**: `bash tests/run.sh` → ends with `--- all pass ---`, exit code 0

## Test plan

No new automated tests. This change is a static string in a PHP template; there
is no test harness in this repo that renders `settings.php`, and building one
for a help-text change would cost far more than it protects.

The existing suite (`tests/run.sh`) must continue to pass unchanged — that is
the regression check.

Manual verification, if you have an Unraid box available (optional, not
required to close this plan): open **User Utilities → HBAviewer** and confirm
the Alert Threshold help text reads correctly and the page still renders and
saves.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -rn 'notification fires' source/` returns no matches
- [ ] `grep -c 'does not send notifications' source/usr/local/emhttp/plugins/hbaviewer/settings.php` prints `1`
- [ ] `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` exits 0
- [ ] `bash tests/run.sh` exits 0 and prints `--- all pass ---`
- [ ] `git status --porcelain` shows exactly one modified file: `source/usr/local/emhttp/plugins/hbaviewer/settings.php` (plus `plans/README.md` for the status row)
- [ ] `plans/README.md` status row for 001 updated

## STOP conditions

Stop and report back (do not improvise) if:

- The `<small>` line at `settings.php:130` does not match the excerpt in
  "Current state" — someone has already edited this text and you need to know
  what they intended before overwriting it.
- Your grep in Step 2 finds that notifications **are** in fact implemented
  somewhere (a call to `notify`, a cron entry in `hbaviewer.plg`, a systemd
  timer). In that case the finding behind this plan is wrong: the copy may be
  accurate and the feature merely broken. Report what you found; do not change
  the text.
- `bash tests/run.sh` fails. It passes at commit `0346777` for the shell half;
  a failure means either drift or an environment problem, and neither should be
  papered over by a docs change.

## Maintenance notes

- **This plan intentionally leaves a feature gap.** Real alerting — a cron job
  that reads the existing `status` rollup from `get_hba_info.sh` and calls
  `/usr/local/emhttp/webGui/scripts/notify` — is tracked as a direction item,
  not as a bug. If and when it lands, this help text must be updated again, and
  that change should be made in the same commit as the feature so the two can
  never drift apart.
- The 10 °C amber band is currently duplicated in two parsers
  (`scripts/parse/storcli_overview.sh:52` and `scripts/parse/hba.sh:79`). If
  anyone makes that band configurable, this help text hardcodes the number and
  will need to become dynamic.
- **What a reviewer should scrutinise**: only that the new sentence is factually
  true. Compare it against the `RANK` logic quoted in "Current state". A
  reviewer should not need to check anything else — the diff is one line.
