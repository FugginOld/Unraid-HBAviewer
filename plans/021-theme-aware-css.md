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

### Step 1: DONE — Unraid's theme variables, confirmed on a live box

**This step was completed on the maintainer's Unraid box on 2026-07-31. Do not
repeat it; do not substitute names other than these.**

Unraid defines its palette in
`/usr/local/emhttp/plugins/dynamix/styles/default-color-palette.css`, with
per-theme overrides in `styles/themes/{white,black,azure,gray}.css`. The generic
semantic variables are **redefined in all four themes**, which is what makes
binding to them worthwhile — verified:

```text
--background-color   azure: gray-150   black: gray-900   gray: black   white: gray-100
--text-color         azure: cyan-400   black: gray-100   gray: cyan-300 white: gray-900
--border-color       azure: cyan-400   black: gray-600   gray: cyan-300 white: gray-200
--dashboard-background-color  redefined in all four
```

Note `white` and `azure` set **light** backgrounds. That is the mechanism behind
issue #7: the plugin paints `#1c1c1c` panels regardless, so on those themes its
cards sit as dark blocks on a light page.

The variables worth binding to, all confirmed present:

| Role | Unraid variable |
|---|---|
| Page / panel background | `--background-color`, `--alt-background-color`, `--shade-bg-color` |
| Body text | `--text-color`, `--alt-text-color`, `--disabled-text-color` |
| Borders | `--border-color`, `--alt-border-color` |
| Tables | `--table-background-color`, `--table-header-background-color`, `--table-border-color`, `--hover-table-row-background-color` |
| Dashboard tile | `--dashboard-background-color`, `--dashboard-border-color` |
| Header / footer | `--header-background-color`, `--header-text-color`, `--footer-background-color`, `--footer-text` |

**Ignore the `--dynamix-*` family** (`--dynamix-jquery-ui-*`,
`--dynamix-tablesorter-*`, `--dynamix-awesomplete-*`, `--dynamix-sb-*`,
`--dynamix-tooltipster-*`). Those style Unraid's own widgets; the plugin renders
none of them and binding to them would couple it to internals it does not use.

### Step 1b: The change is far smaller than 115 substitutions

`hbaviewer.php` **already has a token layer** — the Monitor page funnels its
chrome through plugin-local custom properties on `#lu-wrap`:

```css
#lu-wrap {
    --bg:#161616; --surface:#1c1c1c; --surface-2:#232323;
    --border:#333333; --border-soft:#2a2a2a;
    --text:#dddddd; --muted:#dddddd; --faint:#dddddd;
    --accent:#f5a623; --accent-2:#88aaff; --track:#2a2a2a;
    --good:#2ecc71; --warn:#f39c12; --crit:#e74c3c;
```

So the whole Monitor page converts by **rebinding roughly ten declarations**, not
by rewriting every call site. Use `var(--unraid-name, #current-literal)` so the
fallback preserves today's appearance exactly wherever a variable is missing:

```css
    --bg:        var(--background-color, #161616);
    --surface:   var(--dashboard-background-color, #1c1c1c);
    --border:    var(--border-color, #333333);
    --text:      var(--text-color, #dddddd);
```

Keep `--good`/`--warn`/`--crit` **as literals** — see the semantics note in
"Current state".

`dashboard.php` is the harder half: it has no token layer and uses `#1c1c1c`,
`#2a2a2a`, `#ddd`, `#fff` inline (its `--tc`/`--sc` variables carry *status*,
not chrome, and must not be repurposed). Give it the same small token block
rather than substituting each literal in place.

### Step 1c (superseded — kept for context): how the names were confirmed

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

**CORRECTED 2026-07-31 — the first draft of this step would have broken plan
018.** It proposed "reconciling" `#ff5252` into `#e74c3c` and `#f5a623` into
`#f39c12` as duplicate literals for one role. They are not duplicates:

| Literal | Role | Why it cannot be merged |
|---|---|---|
| `#2ecc71` | band `normal`, status ok | — |
| `#f1c40f` | band `elevated` | added by 018 |
| `#e67e22` | band `warning` | added by 018 |
| `#e74c3c` | band `alert`, status alert | — |
| `#922b21` | band `critical` | **fill only** — 1.94:1 as a foreground, unreadable |
| `#ff5252` | `critical` **stroke** | the gauge arc; `#922b21` fails as a stroke |
| `#f5a623` | accent (tab underline, headings) | not a status colour at all |

Those five band values were chosen by contrast measurement against the plugin's
own card surfaces (plan 018), and `lsi_temp_color()` / `lsi_temp_stroke()` in
`view.php` already centralise them. **Do not merge, rename or theme any of them.**

What this step should actually do is narrower: `view.php` is already the single
definition point for the band and health palettes, so there is no five-way
duplication left to remove there. The remaining duplication is the *chrome*
literals in `dashboard.php`, which has no token layer — fixed by Step 3, not by
a status-palette rewrite.

If a plugin-local status variable is still wanted for the handful of inline
`style="color:#..."` uses, define it **from** `view.php`'s existing functions
rather than as a second source of truth, and leave the values untouched.

**Verify**: `grep -c 'lsi_temp_color\|lsi_temp_stroke' source/usr/local/emhttp/plugins/hbaviewer/view.php` → unchanged from before this plan

**Verify**: the six band/status literals above still appear in `view.php` with
their current values — `grep -c '#922b21\|#ff5252\|#f1c40f\|#e67e22' source/usr/local/emhttp/plugins/hbaviewer/view.php` → `6`

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

- [ ] Every chrome token in `#lu-wrap` reads `var(--unraid-name, #literal)` —
      `grep -c 'var(--background-color\|var(--text-color\|var(--border-color' source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php` → at least `3`
- [ ] `dashboard.php` has a token block and no bare chrome literals —
      `grep -c '#1c1c1c\|#2a2a2a' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → `0`
      outside that block
- [ ] **The band palette is untouched** —
      `grep -c '#922b21\|#ff5252\|#f1c40f\|#e67e22' source/usr/local/emhttp/plugins/hbaviewer/view.php` → `6`
- [ ] Every substitution keeps a literal fallback: no `var(--x)` without a
      second argument in any touched file
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

## Outcome, and the light/dark standard this plan established

*Added 2026-08-01, after execution. This section records what shipped and the
design standard agreed with the maintainer — it is the reference for every
future surface, so read it before adding any card, tile or tab.*

### What this plan actually changed

Chrome tokens in `#lu-wrap`, `#lu-settings-wrap` and `.lu-d-tile` now derive
from Unraid's own variables rather than literals:

```css
--bg:        var(--background-color, #161616);
--surface:   var(--shade-bg-color, #1c1c1c);
--surface-2: color-mix(in srgb, var(--shade-bg-color, #232323) 92%, var(--text-color, #dddddd) 8%);
--border:    var(--border-color, #333333);
--text:      var(--text-color, #dddddd);
```

Two mapping errors were caught and corrected during execution, and both are
traps for anyone extending this:

- `--dashboard-background-color` is `gray-700` (`#303030`) in **all four**
  themes. It looks like a surface token and is useless as one.
- `--alt-background-color` is **not defined by any theme file**. Binding to it
  yields transparent.

`--shade-bg-color` is the one that genuinely flips (`#e8e8e8` white /
`#212121` black). Use it.

Eight hardcoded pastels were also retired in favour of three text tokens —
`--crit-text`, `--good-text`, `--warn-text`, each `color-mix(in srgb, <role>
50%, var(--text-color))` — because a colour lightened to sit on a dark card
measures 1.36–2.24:1 once the card follows the theme.

### The standard for every future surface

| | Light themes (`white`, `azure`) | Dark themes (`black`, `gray`) |
|---|---|---|
| Card / tile background | theme-derived (`--shade-bg-color`) | theme-derived |
| Instrument tile | filled `#6e6e6e`, `1px solid #5c5c5c`, inset top highlight | **transparent**, `1px solid #2e2e2e` |
| Gauge track | `#5a5a5a` | `#3a3a3a` |
| Gauge number, band label | white | the band's light gradient stop |
| Status marks (bands, meters, bars) | dark→light gradient | identical gradient |

The instrument tile matches the gray of Unraid's own Disk Stats tile, so the
plugin reads as part of the platform rather than a foreign panel.

### The one thing this plan broke

**Making the card surfaces theme-aware invalidated plan 018's contrast
measurement.** 018 measured the five band colours against the plugin's own
fixed dark cards; once those cards follow the theme, the same values fall to
**1.36:1** on `white`, and `dashboard.php` renders one of them as body text
where the floor is 4.5:1.

The resolution is **plan 030**: every band becomes a dark→light gradient, so
each mark carries its own internal contrast and no longer depends on the
surface behind it. That is why the table above specifies gradients rather than
flat colours, and why a single flat palette is not an option — a colour
readable as text on both surfaces is arithmetically impossible (it would need
luminance ≤0.140 for the light card and ≥0.227 for the dark one).

**Do not add a flat status colour to any new surface.** Use
`lsi_temp_gradient()` once 030 lands; until then, use the three text tokens.

## Maintenance notes

- **The look-alike literals are not duplicates.** `#e74c3c` (alert band) vs
  `#ff5252` (critical *stroke*), and `#f39c12` (legacy warn) vs `#f5a623`
  (accent), read as accidental drift and are not. Plan 018 measured the band
  values against the plugin's card surfaces; `#922b21` in particular is legible
  only as a fill behind white text. Merging any pair silently undoes that.
  `view.php`'s `lsi_temp_color()` / `lsi_temp_stroke()` are the single source of
  truth — extend those, never a parallel palette.
- **Keep literal fallbacks in `var(--x, #literal)` form.** Unraid users run
  a wide range of versions; a variable that doesn't exist yet must not
  render as unstyled/transparent.
