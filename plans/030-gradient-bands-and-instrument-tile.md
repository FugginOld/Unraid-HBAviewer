# Plan 029: Gradient temperature bands, the instrument tile, and a Health gauge

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 0cbf845..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/view.php source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php source/usr/local/emhttp/plugins/hbaviewer/dashboard.php`
> Expected output: **nothing**. Every excerpt below is quoted from `0cbf845`
> (tip of `advisor/021-theme-aware-css`). Any difference is a STOP condition.

## Status

- **Priority**: P1
- **Effort**: L
- **Risk**: MEDIUM — changes a function signature that four call sites depend
  on, and replaces the Overview gauge element outright
- **Depends on**: **021** (theme-aware CSS). 021 lives on
  `advisor/021-theme-aware-css` at `0cbf845` and is **not merged to `dev`**.
  Branch from 021, not from `dev`.
- **Category**: feature / design
- **Planned at**: `0cbf845`, 2026-08-01
- **Requested by**: maintainer, after five rounds of visual review

## Decide this before Step 1 — the Health gauge has no number to show

The approved preview shows the Health gauge reading **89 / 100**. That score
does not exist in the codebase and this plan does not invent one silently.

`health_rollup()` returns a **state and a reason**, not a number:

```php
function health_rollup(array $indicators): array {
    ...
    return [$worst['state'], $worst['reason']];   // e.g. ['watch', 'invalid dwords 12/h on phy 3']
}
```

`health_rank()` maps states to `0..3`, which is an ordering, not a score:

```php
function health_rank(string $state): int {
    return ['ok' => 0, 'watch' => 1, 'warning' => 2, 'critical' => 3][$state] ?? -1;
}
```

Three honest options. **This plan implements (A)** unless the maintainer says
otherwise; it is the only one that adds no invented precision.

| | What the gauge reads | New logic needed |
|---|---|---|
| **(A) indicators passing** | `4 / 5`, arc at 80%, coloured by rollup state | none — counts existing states |
| (B) weighted score | `89 / 100` as previewed | a scoring model, arbitrary weights |
| (C) worst-severity arc | arc filled by `health_rank`, no number | none, but reads as a meter with 4 positions |

(B) is what the preview showed and it is the one to avoid unless the
maintainer explicitly wants it: it manufactures two significant figures out of
five categorical states, and a number that moves from 89 to 87 for reasons no
one can explain is worse than no number.

**If the maintainer picks (B), stop and get the weights in writing first.**

## Why this matters

Plan 018 established five temperature bands and validated the palette against
the plugin's own dark card surfaces. Plan 021 then made those surfaces follow
the Unraid theme — which invalidated the measurement. On the `white` and
`azure` themes the bands measure as low as **1.36:1**, and
`dashboard.php` renders one of them as body text, where the floor is 4.5:1.

The fix chosen after visual review is the technique Unraid's own Disk Stats
page uses: **each band is a dark→light gradient**, so every mark carries its
own internal contrast and stops depending on what is behind it. That removes
the theme-dependence entirely rather than maintaining two palettes.

## Current state

### `view.php` — the band palette and its now-false comment

```php
/* Temperature band -> colour. SEPARATE from lsi_status_color on purpose: the
   thermometer shows heat, the badge shows the whole-controller rollup (which also
   reflects drive and PHY problems). Conflating them is what made issue #8 read as
   a false temperature warning. Hexes are contrast-measured against the plugin's
   own card surfaces (#232323 / #1c1c1c / #2a2a2a); all clear 3:1.
   'critical' is a FILL behind white text, not a foreground — #922b21 measures
   1.94:1 as a stroke on a dark card and is unreadable. Do not "promote" it. */
function lsi_temp_color(string $band): string {
    return match ($band) {
        'critical' => '#922b21',
        'alert'    => '#e74c3c',
        'warning'  => '#e67e22',
        'elevated' => '#f1c40f',
        default    => '#2ecc71',
    };
}
/* Where a band must be drawn as a stroke or glow rather than a fill, critical
   needs a lighter red to stay legible (4.93:1 vs 1.94:1). */
function lsi_temp_stroke(string $band): string {
    return $band === 'critical' ? '#ff5252' : lsi_temp_color($band);
```

The parenthetical about card surfaces is **stale as of plan 021** and must be
rewritten, not merely left in place — it is the comment that caused this whole
defect to go unnoticed.

### `hbaviewer.php` — the gauge is a full-circle conic ring, not an arc

```css
.lu-circle {
    position: relative; width: 108px; height: 108px; flex-shrink: 0; border-radius: 50%;
    background: conic-gradient(var(--tc, var(--good)) calc(var(--pct,0)*1%), var(--track) 0);
    display: grid; place-items: center;
    filter: drop-shadow(0 0 10px color-mix(in srgb, var(--tc, var(--good)) 32%, transparent));
    transition: background 0.4s;
}
.lu-circle::before { content: ""; position: absolute; inset: 7px; border-radius: 50%; background: radial-gradient(circle at 50% 40%, var(--surface-2), var(--surface)); border: 1px solid var(--border-soft); }
```

**The approved design is a half-circle arc.** This is a replacement of the
element, not a recolour. Note there is **no JavaScript** driving it — the whole
card is re-rendered server-side and the colour/percentage arrive as inline
custom properties, which makes the swap simpler than it looks.

### `hbaviewer.php` — the band meter segments

```css
.lu-band-seg.s0 { flex: 65; background: #2ecc71; }
.lu-band-seg.s1 { flex: 10; background: #f1c40f; }
.lu-band-seg.s2 { flex: 10; background: #e67e22; }
.lu-band-seg.s3 { flex: 10; background: #e74c3c; }
.lu-band-seg.s4 { flex: 15; background: #922b21; }
```

### `ajax_info.php` — the four consumers of the palette

```php
$isCrit   = ($v['temp_band'] ?? '') === 'critical';
$tempChip = $isCrit
    ? '<span style="background:' . lsi_temp_color('critical') . ';color:#fff;padding:2px 7px;border-radius:2px;font-weight:700">CRITICAL</span>'
    : '<span style="color:' . $v['temp_stroke'] . '">' . htmlspecialchars($v['temp_label']) . '</span>';
$out .= '<div class="lu-card first" style="--tc:' . $v['temp_stroke'] . ';--sc:' . $v['color'] . ';--pct:' . ($v['temp'] !== '' ? (int) $v['temp'] : 0) . '" data-ctl="' . $i . '">'
```

### `view.php` — `temp_color` is a dead field

```php
'temp_color'  => lsi_temp_color($data['temp_band'] ?? ''),
'temp_stroke' => lsi_temp_stroke($data['temp_band'] ?? ''),
```

`temp_stroke` has four consumers. **`temp_color` has none** — every site that
wants the flat critical fill calls `lsi_temp_color('critical')` directly.
Confirm this yourself with
`grep -rn "temp_color" source/usr/local/emhttp/plugins/hbaviewer/*.php`
before acting on it: expect exactly two hits, the definition and this emission.

Drop the `temp_color` key rather than porting it to a gradient. If it is kept
"just in case", it becomes a third representation of the palette that nothing
renders and nothing tests.

### `dashboard.php` — a second, near-duplicate gauge

```css
background:conic-gradient(var(--tc,#2ecc71) calc(var(--pct,0)*1%), #2a2a2a 0);
```

```php
<div class='lu-d-circle' style='--tc:{$tempCol};--pct:{$temp}'>
```

The dashboard tile has its own copy of the gauge with its own hardcoded
fallbacks. Both must move together or the two views will disagree.

### `view.php` — the theme signal is already available

Plan 019 established that dynamix exposes `$display` to page scripts (see the
comment block at `view.php:81`). `$display['theme']` carries `white`, `black`,
`azure`, or `gray`, and is the signal the tile treatment needs. **Do not
attempt to detect the theme in CSS** — no Unraid theme sets
`prefers-color-scheme`, and the theme variables that do differ
(`--shade-bg-color`) cannot be branched on from a stylesheet.

## The approved design

### Gradients — five bands, dark→light, used everywhere

| Band | Dark stop | Light stop |
|---|---|---|
| normal | `#0f7a1a` | `#41d141` |
| elevated | `#b8890a` | `#f5d020` |
| warning | `#a85410` | `#f09428` |
| alert | `#9c1810` | `#e8443a` |
| critical | `#6b0f0c` | `#b82820` |

Every mark also carries the vertical sheen the Unraid bars have:

```css
linear-gradient(180deg, rgba(255,255,255,.26), rgba(255,255,255,0) 55%, rgba(0,0,0,.13))
```

### The instrument tile — theme-conditional

| Theme | Tile background | Tile border | Gauge track | Number + band label |
|---|---|---|---|---|
| `white`, `azure` | `#6e6e6e` + inset top highlight | `1px solid #5c5c5c` | `#5a5a5a` | white |
| `black`, `gray` | transparent | `1px solid #2e2e2e` | `#3a3a3a` | the band's light stop |

The number changing colour between themes is deliberate and maintainer-
approved: on the filled panel white reads as part of the instrument; floating
on a dark card with no panel it loses its association with the arc.

## Scope

**In scope**:

- `view.php`: add `lsi_temp_gradient(string $band): array` returning
  `[darkStop, lightStop]`. **Keep `lsi_temp_color()`** — the critical chip is
  a flat fill behind white text and must stay flat. Rewrite the stale
  contrast comment.
- `view.php`: add `lsi_tile_is_light(): bool` (or equivalent) reading
  `$display['theme']`, defaulting to the dark treatment when absent.
- `hbaviewer.php`: replace `.lu-circle` with the half-circle gauge; convert
  `.lu-band-seg.s0…s4` to gradients; add the tile treatment rules.
- `ajax_info.php`: emit the half-circle gauge markup; emit both gradient stops
  as custom properties; wrap the gauge + band meter in the instrument tile.
- `dashboard.php`: the same gauge change for `.lu-d-circle`, so the tile and
  the Overview do not diverge.
- Health tab (in `ajax_info.php`): add the half-circle gauge above the existing
  indicator rows, per option (A) above; convert the indicator rows to gradient
  bars.

**Out of scope**:

- Any change to band **boundaries** (65/75/85/95) or to `band_of()` /
  `band_index()` in either parser — this plan is presentation only.
- Any change to `lsi_status_color()` or the health badge. The badge shows the
  whole-controller rollup and its separation from temperature is what fixed
  issue #8. Do not re-conflate them.
- The `PHYERR_FLOOR=100` logic.
- Any parser or shell script. If a `.sh` file appears in the diff, that is a
  STOP condition.

## Steps

### Step 1: `lsi_temp_gradient()` and the comment rewrite

```php
/* Temperature band -> [dark, light] gradient stops. Each band is a gradient,
   not a flat colour, so the mark carries its own internal contrast and reads
   on any surface. This replaced a flat palette that had been contrast-measured
   against the plugin's own dark cards — a measurement plan 021 invalidated the
   moment those cards started following the Unraid theme (bands fell to 1.36:1
   on `white`). Do not "simplify" these back to single hexes.
   lsi_temp_color() survives for the critical chip only, which is a flat fill
   behind white text and needs no gradient. */
function lsi_temp_gradient(string $band): array {
    return match ($band) {
        'critical' => ['#6b0f0c', '#b82820'],
        'alert'    => ['#9c1810', '#e8443a'],
        'warning'  => ['#a85410', '#f09428'],
        'elevated' => ['#b8890a', '#f5d020'],
        default    => ['#0f7a1a', '#41d141'],
    };
}
```

**Verify**: `php -l view.php` clean, and a direct call returns a two-element
array for every one of the five band names plus an unknown string.

### Step 2: the theme signal

```php
/* Which instrument-tile treatment to use. dynamix exposes $display to page
   scripts (see lsi_time's comment above). Absent or unrecognised -> dark
   treatment, which is what shipped before this plan. */
function lsi_tile_is_light(): bool {
    global $display;
    return in_array($display['theme'] ?? '', ['white', 'azure'], true);
}
```

**Verify**: unit-test all four theme names plus the missing-`$display` case.
The missing case must return `false`.

### Step 3: the half-circle gauge

Replace `.lu-circle` with an SVG arc. The geometry from the approved preview,
for a 0–110 °C scale:

```
viewBox 0 0 200 112, path  M20,100 A80,80 0 0 1 180,100
arc length = pi * 80 = 251.3
stroke-dashoffset = 251.3 * (1 - temp/110)
```

Emit the gradient per card with a `<linearGradient>` whose id is unique per
controller index — **ids must not collide when several controllers render on
one page**. Use `lu-grad-<i>`.

**Verify**: render two controllers at different temperatures and confirm each
gauge shows its own value and its own gradient; confirm the arc for a 0 °C
reading is empty rather than full (an off-by-one in the dashoffset shows up
exactly here).

### Step 4: band meter segments and the instrument tile

```css
.lu-band-seg.s0 { flex: 65; background: linear-gradient(90deg, #0f7a1a, #41d141); }
.lu-band-seg.s1 { flex: 10; background: linear-gradient(90deg, #b8890a, #f5d020); }
.lu-band-seg.s2 { flex: 10; background: linear-gradient(90deg, #a85410, #f09428); }
.lu-band-seg.s3 { flex: 10; background: linear-gradient(90deg, #9c1810, #e8443a); }
.lu-band-seg.s4 { flex: 15; background: linear-gradient(90deg, #6b0f0c, #b82820); }
```

The `flex` weights are **unchanged** and must stay in step with the label
percentages in `ajax_info.php` — there is an existing comment at both sites
saying so. Keep both comments.

### Step 5: the dashboard tile's copy

Apply the same gauge change to `.lu-d-circle`. Its hardcoded `#2ecc71` and
`#2a2a2a` fallbacks go away with the conic-gradient.

**Verify**: `grep -c 'conic-gradient' dashboard.php hbaviewer.php` → `0` for both.

### Step 6: the Health gauge

Per option (A): count indicators whose state is `ok`, render `N / 5`, fill the
arc to `N/5`, and colour it with `lsi_temp_gradient()`-style stops chosen from
the **rollup state** via a small map (ok → normal stops, watch → elevated,
warning → warning, critical → alert). Convert the four existing indicator rows
to gradient bars.

Note the Health tab currently renders **four** indicator rows
(`link_integrity`, `topology`, `host_link`, `controller`) even though plan 020
describes five sub-indicators. **Count what `health_indicators()` actually
returns; do not hardcode 5.**

## Test plan

- `lsi_temp_gradient()` — pure; assert two stops for all five bands and the
  unknown-band default.
- `lsi_tile_is_light()` — pure; assert all four themes plus missing `$display`.
- The Health gauge's count — pure; assert `0/N`, `N/N`, and a mixed case, plus
  the empty-indicators case (must not divide by zero).
- `bash tests/run.sh` → `--- all pass ---`. Existing goldens should **not**
  move: this plan changes presentation, not any JSON contract. **If a golden
  changes, stop** — that means a parser was touched.
- `php -l` on all four changed files.

## Done criteria

- [ ] `grep -c 'conic-gradient'` → `0` in both `hbaviewer.php` and `dashboard.php`
- [ ] `grep -c '#2ecc71\|#f1c40f\|#e67e22\|#e74c3c\|#ff5252'` → `0` across all
      `.php` files (the old flat palette is fully retired; `#922b21` survives
      only inside `lsi_temp_color`)
- [ ] Two controllers on one page render two independent gauges with
      non-colliding gradient ids
- [ ] `lsi_tile_is_light()` returns `false` when `$display` is unset
- [ ] `bash tests/run.sh` → `--- all pass ---`, `git diff -- tests/expected/` empty
- [ ] `php -l` clean on every touched file
- [ ] No `.sh` file in `git diff --name-only`

## STOP conditions

- The drift check prints anything.
- Any golden file changes.
- Any `scripts/` file appears in the diff.
- The band boundaries (65/75/85/95), the `flex` weights, or the label
  percentages change — this plan must not move a single band edge.
- The maintainer chooses scoring option (B) without supplying weights.
- `lsi_temp_color()` is deleted rather than kept for the critical chip.

## Maintenance notes

- **The comment in `view.php` about contrast being measured against the
  plugin's own cards is the reason this defect survived plan 021.** It stated a
  fact that a later plan silently invalidated. The replacement comment says
  *why* gradients are used, which stays true regardless of what the surface
  does next.
- **There are two gauges** — `hbaviewer.php`/`ajax_info.php` and
  `dashboard.php` — with independent copies of the same geometry. They have
  drifted before. A future change to one should check the other; consider
  extracting a shared renderer if a third appears.
- **The tile treatment is the one piece keyed to Unraid's theme names.** If
  Unraid adds a fifth theme, `lsi_tile_is_light()` is the single place to
  update, and its default (dark) is the safe fallback.
