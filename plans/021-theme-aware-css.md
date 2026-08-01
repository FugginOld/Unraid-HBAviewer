# Plan 021: Replace hardcoded panel colors with Unraid theme variables

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 533f010..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/dashboard.php source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php source/usr/local/emhttp/plugins/hbaviewer/settings.php source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php source/usr/local/emhttp/plugins/hbaviewer/view.php`
> Expected output: **nothing**. Every count below is from `533f010` (`dev` tip
> after plans 017/019/020 merged, 2026-07-31). Any difference is a STOP condition — re-run the grep in
> Step 1 before continuing.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW — pure CSS/PHP string substitution, no logic change
- **Depends on**: 020 — **satisfied**. It merged as `533f010`, so the HBA
  Health tab's CSS is present in `hbaviewer.php` and its palette in `view.php`,
  and this plan's sweep will cover them. This plan is re-baselined onto that
  commit and its counts re-measured; had it run first it would have converted
  files 020 was about to rewrite and silently skipped a whole tab.
- **Category**: bug / UX
- **Planned at**: `8286fe7`, 2026-07-31
- **Requested by**: external roadmap review — closes issue
  [#7](https://github.com/FugginOld/Unraid-HBAviewer/issues/7)
  ("Coloring need some loving with unRAID's dark theme")

## Why this matters

Every card, gauge, and status color in the plugin is a hardcoded hex literal
baked into inline `<style>` blocks. The plugin asserts its own palette
regardless of which theme Unraid is running, so panel backgrounds, borders
and muted text collide with the theme instead of following it.

**Correction to this plan's first draft**, which claimed dark "happens to
look fine" and located the problem on the light themes. Issue #7 is titled
*"Coloring need some loving with unRAID's dark theme"*, the maintainer runs
the dark theme and **cannot reproduce it**, and the reporter said they had
been "flipping between white and dark mode… quite in the middle". So the
specific broken combination was never established, and no theme should be
assumed safe.

That does not weaken the case — it strengthens the approach. A plugin that
follows the OS palette is correct on every theme, including whichever one
the reporter is actually on, without anyone having to reproduce their exact
setup first. Chasing one theme's hex values would have fixed one report;
reading the theme's own variables fixes the class.

Unraid's webGui already exposes theme colors as CSS custom properties on
`:root` (used throughout the stock `emhttp` pages). HBAviewer should read
those instead of asserting its own palette wherever the property maps
cleanly, and keep hardcoded literals only for values that are semantically
fixed regardless of theme (status red/amber/green — a "critical" card should
be red in every theme, that's the point of the color).

## Current state

**Re-measured at `533f010`** after plans 019 and 020 merged — the first draft's
table was taken at `8286fe7` and is superseded. 115 hex literals across the five
files:

| File | Count | Notable literals | Likely role |
|---|---|---|---|
| `hbaviewer.php` | 45 | `#e88` ×3, `#e74c3c` ×3, `#dddddd` ×3, `#2ecc71` ×3, `#fff` ×2, `#f5a623` ×2 | page chrome, muted text, status colours |
| `dashboard.php` | 25 | `#2ecc71` ×8, `#ddd` ×7, `#2a2a2a` ×5, `#d88` ×2, `#922b21` ×1 | status green, muted text, panel bg |
| `view.php` | 18 | `#e74c3c` ×3, `#2ecc71` ×3, `#f1c40f` ×2, `#e67e22` ×2, `#922b21` ×2, `#ff5252` ×1 | the full status + temperature-band palette |
| `settings.php` | 17 | `#dddddd` ×3, `#f5a623` ×2, `#e0a0a0`, `#d9901a`, `#8ccc8c` | muted text, accent, status |
| `ajax_info.php` | 10 | `#f39c12` ×3, `#e74c3c` ×2, `#2ecc71` ×2, `#922b21` ×1 | renderer output, not page chrome |

Reproduce with:

```bash
cd source/usr/local/emhttp/plugins/hbaviewer
for f in hbaviewer.php dashboard.php view.php settings.php ajax_info.php; do
  printf '%-16s %s\n' "$f" "$(grep -oE '#[0-9a-fA-F]{6}|#[0-9a-fA-F]{3}\b' "$f" | wc -l)"
done
```

**Note what 019 and 020 added, because it changes the sorting job below.**
`view.php` is now the single home of the temperature-band palette
(`#2ecc71`/`#f1c40f`/`#e67e22`/`#e74c3c`/`#922b21` plus the `#ff5252` stroke
variant) and the health-state palette. Those five band colours were chosen by
contrast measurement against the plugin's own dark card surfaces — see plan 018.
They are **semantic**, not chrome, and converting them to theme variables would
discard that work. `#922b21` in particular is only legible as a *fill behind
white text*; it fails contrast as a foreground.

Two categories are already visible from the counts alone:

1. **Panel/text chrome** (`#2a2a2a`, `#242424`, `#dddddd`/`#ddd`) — these are
   "dark card background" and "light muted text" literals that assume the
   dark theme is active. These should move to theme variables.
2. **Status semantics** (`#2ecc71` green / `#f39c12`,`#f5a623` amber /
   `#e74c3c`,`#ff5252` red / `#e88`,`#d88`,`#e0a0a0` desaturated red-ish
   variants) — these carry meaning (healthy/warning/critical) independent of
   theme and are candidates to **keep** as literals, or promote to plugin-
   local CSS variables (`--lu-status-ok`, `--lu-status-warn`,
   `--lu-status-crit`) defined once and consumed everywhere — which also
   deletes the duplication across five files.

## Scope

**In scope**:

- `dashboard.php`, `hbaviewer.php`, `ajax_info.php`, `settings.php`,
  `view.php` — the CSS blocks and inline `style=` attributes in each
- Introduce a small set of plugin-local CSS custom properties for the status
  palette (defined once, referenced everywhere) so five copies of
  `#2ecc71` become one definition
- Map panel/background/muted-text literals to Unraid's existing theme
  variables where a clean equivalent exists

**Out of scope**:

- Any change to which color represents which health state (that's the
  rollup/badge logic from plans 018–020, not this plan)
- Adding a plugin-specific theme switcher — this plan makes the plugin
  *follow* Unraid's theme, not add a second one
- `HBAviewer_*.page` files unless they carry their own literal `<style>`
  blocks distinct from what's already counted above (check in Step 1)

## Steps

### Step 1: Confirm Unraid's theme variable names

Unraid's webGui defines root-level custom properties per theme
(`white`/`black`/`azure`/`gray` as of recent 7.x). Before writing any
substitution, confirm the exact property names currently shipping — do not
guess from memory, they've changed across Unraid versions. Check:

- Unraid's own `/usr/local/emhttp/plugins/dynamix/styles/` or an installed
  box's rendered `:root` (`getComputedStyle(document.documentElement)` in
  devtools) — this is a **hardware/live-box check**, not something the
  executor can do from the repo alone.
- If no live box is available, search the Dynamix source for `--color-` or
  similar prefixed custom properties in the currently-targeted Unraid
  version (7.2+, per this repo's dashboard-tile requirement).

**This step gates everything after it.** Do not invent variable names —
record the confirmed list (or the fallback plan if none exist for a given
role) before Step 2.

### Step 2: Define the plugin's status palette once

Pick one file that's always loaded first on every relevant page (or add a
small shared `<style>` block emitted once — check whether `HBAviewer.page`,
`HBAviewer_Dashboard.page`, `HBAviewer_Monitor.page` already share a common
include point before duplicating). Define:

```css
:root {
  --lu-status-ok:   #2ecc71;
  --lu-status-warn: #f39c12; /* reconcile with #f5a623 — same role, two literals */
  --lu-status-crit: #e74c3c; /* reconcile with #ff5252 — same role, two literals */
}
```

Then replace every status-role literal in the five files with
`var(--lu-status-ok)` etc. This alone removes the five-way duplication
without touching semantics.

**Verify**: `grep -c '#2ecc71' source/usr/local/emhttp/plugins/hbaviewer/*.php` →
`0` everywhere except the single definition file.

### Step 3: Map chrome literals to theme variables

For each panel-background and muted-text literal identified in Step 1's
confirmed list, replace with the matching Unraid variable
(`var(--background, #2a2a2a)` — keep the literal as a CSS fallback so the
plugin still renders sanely if the variable is ever absent, e.g. on an older
Unraid).

**Verify**: `php -l` on every touched `.php` file → "No syntax errors
detected" for each.

### Step 4: Manual verification on both themes

The test suite has no visual assertions — this is the same posture plan 019
took with its layout changes (see its "Hardware verification" section). Not
a step the executor can pass programmatically.

## Test plan

- No PHP logic changes, so the existing `bash tests/run.sh` suite is a
  pure regression check — it must stay green with **zero** golden diffs.
- This plan has no automated visual test. The done criteria below are
  static (grep/lint) checks; the actual fix is judged on hardware.

## Done criteria

- [ ] Step 1's variable list confirmed against a real Unraid 7.2+ box (or
      documented as unavailable, with the fallback literals kept and a
      `ponytail:`-style comment explaining why)
- [ ] `grep -c '#2ecc71\|#f39c12\|#f5a623\|#e74c3c\|#ff5252' source/usr/local/emhttp/plugins/hbaviewer/*.php` →
      count equals only the single palette-definition occurrences, not five-per-file
- [ ] `php -l` clean on all five touched files
- [ ] `bash tests/run.sh` → `--- all pass ---`, no re-blessed goldens
- [ ] `git status --porcelain` shows only the five touched source files
      plus `plans/README.md`

## STOP conditions

- The drift check prints anything.
- Step 1 cannot confirm real Unraid variable names and the executor is
  tempted to guess a plausible-sounding property name. A wrong guess is
  worse than a hardcoded fallback — stop and report instead.
- Any status-color role changes what it represents (e.g. amber starting to
  mean something different) — this plan is a pure re-plumbing of *how* a
  color is expressed, never *which* color a state gets.

## Maintenance notes

- **Two amber literals and two red literals already exist for the same
  role** (`#f39c12`/`#f5a623`, `#e74c3c`/`#ff5252`). Step 2 is the one place
  this gets reconciled — pick one canonical value per role and note the
  other as historical drift in the commit message, not a second variable.
- **Keep literal fallbacks in `var(--x, #literal)` form.** Unraid users run
  a wide range of versions; a variable that doesn't exist yet must not
  render as unstyled/transparent.
