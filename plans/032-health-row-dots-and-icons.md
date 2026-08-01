# Plan 032: Health rows — status dots and Tabler icons

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat b4000b5..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php source/usr/local/emhttp/plugins/hbaviewer/view.php`
> Expected output: **nothing**. Every excerpt below is quoted from `b4000b5`
> (tip of `advisor/031-health-thermal-row`). Any difference is a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW — presentation only
- **Depends on**: **031** (`advisor/031-health-thermal-row`, unmerged). **Branch
  from 031, not from `dev`** — this plan needs the five-row list 031 introduced,
  including `thermal`, because every row gets an icon.
- **Category**: design
- **Planned at**: `b4000b5`, 2026-08-01
- **Requested by**: maintainer, with a reference HTML mockup

## What changes

On the HBA Health tab's indicator rows:

1. The 30×9px gradient **bar** becomes an 8px round **dot**.
2. A **Tabler icon** sits between the dot and the label, one per indicator.

Everything else on the row — label, value, spacing, order — stays.

## The contrast constraint — do not use flat dot colours

The maintainer's reference mockup fills each dot with a flat status colour.
Measured against the plugin's two card surfaces (`#e8e8e8` white theme,
`#1c1c1c` dark), **three of the five fail** the 3:1 floor for a small
graphical object on light themes:

| dot | flat colour | on `#e8e8e8` | on `#1c1c1c` |
|---|---|---|---|
| ok | `#0ca30c` | **2.74 FAIL** | 5.08 |
| watch | `#fab219` | **1.50 FAIL** | 9.29 |
| warning | `#ec835a` | **2.15 FAIL** | 6.46 |
| critical | `#d03b3b` | 3.92 | 3.55 |
| unknown | `#7a7a7a` | 3.50 | 3.97 |

This is exactly the defect plan 030 fixed. `.lu-ind-bar`'s own comment records
it:

```css
/* Was a flat coloured dot; a gradient bar carries its own internal contrast and
   is readable on any theme surface. --gd/--gl set inline per row from
   lsi_health_gradient(). */
```

**So the dot keeps the gradient fill, only the shape changes.** Reuse
`lsi_health_gradient()` and the existing `--gd`/`--gl` inline custom properties
exactly as they are set today. Do not introduce a flat-colour palette, and do
not add a new colour constant anywhere.

## Current state

### `ajax_info.php` — the row loop, as of `b4000b5`

```php
$out .= '<div class="lu-indicator-rows">';
foreach (['thermal' => 'Thermal', 'link_integrity' => 'Link Integrity', 'topology' => 'Topology', 'host_link' => 'Host Link', 'controller' => 'Read Health'] as $key => $label) {
    $row = $ind[$key] ?? ['state' => 'unknown', 'value' => '—'];
    [$bDark, $bLight] = lsi_health_gradient($row['state']);
    $out .= '<div class="lu-indicator-row">'
          . '<span class="lu-ind-bar" style="--gd:' . $bDark . ';--gl:' . $bLight . '"></span>'
```

Keep the comment block above this loop (it records why every returned key must
appear here) and keep the `lsi_health_gradient()` call.

### `hbaviewer.php:281-290` — the CSS to change

```css
.lu-ind-bar {
    width: 30px; height: 9px; border-radius: 3px; flex: 0 0 auto;
    background: linear-gradient(180deg, rgba(255,255,255,.26), rgba(255,255,255,0) 55%, rgba(0,0,0,.13)),
                linear-gradient(90deg, var(--gd), var(--gl));
}
.lu-indicator-label { color: var(--faint); flex: 1; }
.lu-indicator-value { color: var(--text); font-family: var(--mono); font-variant-numeric: tabular-nums; text-align: right; }
```

Row container, for spacing context:

```css
.lu-indicator-row { display: flex; align-items: center; gap: 10px; padding: 7px 2px; border-bottom: 1px dashed var(--border-soft); font-size: 12.5px; }
```

### `hbaviewer.php:302` — the page shell

```html
<div id="lu-wrap">
```

## Where the sprite goes — this matters

The icon sprite must be emitted **once, in `hbaviewer.php`'s page shell**, not
in `ajax_info.php`'s output.

`ajax_info.php` re-renders the Health tab on every poll and its HTML replaces
the pane's contents. A sprite emitted there would be re-inserted on every
refresh — wasted bytes, duplicate DOM ids, and `<use>` resolving against
whichever copy won. Put it immediately inside `#lu-wrap` at
`hbaviewer.php:302`, where it is parsed once and persists across every poll.

## Scope

**In scope**:

- Replace `.lu-ind-bar` with `.lu-ind-dot`: `width: 8px; height: 8px;
  border-radius: 50%;` keeping the same two-layer gradient background so the
  `--gd`/`--gl` mechanism is unchanged.
- Add the six-symbol Tabler sprite to `hbaviewer.php` inside `#lu-wrap`,
  `width="0" height="0" style="position:absolute" aria-hidden="true"`.
- Add `<svg class="lu-ind-icon" aria-hidden="true"><use href="#lu-i-KEY"/></svg>`
  between the dot and the label in the row loop.
- Icon CSS: `width: 15px; height: 15px; flex: none; color: var(--faint);` with
  `fill: none; stroke: currentColor;` so it inherits the label's ink. Use
  your judgement between 14px and 16px against the row's 12.5px text; state
  what you chose.
- **Keep the MIT attribution comment** for Tabler Icons next to the sprite.
  The icons are third-party and the notice must travel with them.

**Out of scope** — do not touch:

- `lsi_health_gradient()`, `health.php`, or any indicator state logic.
- The gauge, the band meter, the temperature pill, the header sentence.
- The Overview card's rows or any other tab.
- The `alert-triangle` icon from the mockup — it belongs to the mockup's own
  status pill, which this plan does not add. Ship only the five row icons.

## Steps

### Step 1: the sprite

Add to `hbaviewer.php` immediately inside `#lu-wrap`. **Prefix every id `lu-i-`**
so nothing can collide with Unraid's own page markup — the plugin renders
inside the webGui's DOM, not a standalone page.

Five symbols, keyed to the five indicators:

| indicator key | sprite id | Tabler icon |
|---|---|---|
| `thermal` | `lu-i-thermal` | temperature |
| `link_integrity` | `lu-i-link` | plug-connected |
| `topology` | `lu-i-topology` | server-2 |
| `host_link` | `lu-i-hostlink` | topology-star-3 |
| `controller` | `lu-i-controller` | cpu |

Copy the `<symbol>` bodies **verbatim** from the maintainer's reference file at
`plans/assets/hba-health-card.html` if present; otherwise from the plan's
mockup as supplied. Do not redraw or simplify the paths.

**Verify**: `grep -c 'lu-i-' hbaviewer.php` → at least 10 (five symbol
definitions plus five `<use>` references once Step 2 lands).

### Step 2: the row loop

```php
$out .= '<div class="lu-indicator-row">'
      . '<span class="lu-ind-dot" style="--gd:' . $bDark . ';--gl:' . $bLight . '"></span>'
      . '<svg class="lu-ind-icon" aria-hidden="true"><use href="#lu-i-' . $key . '"/></svg>'
```

`$key` is already the loop key and maps 1:1 to the sprite ids above — no lookup
table needed, but **confirm `host_link` resolves to `lu-i-hostlink`** (no
underscore) or add the one mapping needed. A mismatch renders an empty icon
slot silently, which is the likeliest bug here.

**Verify**: render a fixture and confirm five distinct `<use href>` values.

### Step 3: CSS

Replace the `.lu-ind-bar` rule with `.lu-ind-dot`, keeping the gradient. Update
the comment above it — it currently says "was a flat coloured dot; a gradient
bar…". After this plan it is a gradient *dot*, and the comment must say why the
gradient survived the shape change, or the next person will flatten it.

## Test plan

- Render test: five rows, each with a dot, an icon, a label and a value, in
  order. Assert the five `<use href>` values are the five expected ids.
- Assert `.lu-ind-bar` no longer appears in rendered output.
- `bash tests/run.sh` → `--- all pass ---`.
- **No golden may move.** If one does, STOP.
- `php -l` clean on both touched files.

## Done criteria

- [ ] `grep -c 'lu-ind-bar' source/usr/local/emhttp/plugins/hbaviewer/*.php` → `0`
- [ ] The sprite appears exactly once, in `hbaviewer.php`, not in `ajax_info.php`
- [ ] Five `<use href="#lu-i-*">` render, all resolving to a defined symbol
- [ ] The dot still uses `--gd`/`--gl` from `lsi_health_gradient()` — no flat
      colour literal is introduced anywhere
- [ ] Tabler MIT attribution present next to the sprite
- [ ] `bash tests/run.sh` → `--- all pass ---`, `git diff -- tests/expected/` empty
- [ ] `php -l` clean

## STOP conditions

- The drift check prints anything.
- Any golden changes.
- A flat status colour literal is introduced for the dots — that regresses
  plan 030 on light themes, measurably, and is the one thing this plan exists
  to avoid.
- The sprite is emitted from `ajax_info.php`.
- `health.php` or `lsi_health_gradient()` appears in the diff.

## Maintenance notes

- **The gradient is load-bearing, not decoration.** Three of the five flat
  status colours fail contrast on the `white`/`azure` themes; the gradient is
  what makes an 8px mark legible on any surface. The comment above the CSS rule
  is the only thing preventing a future "simplify this to a solid colour"
  change, so keep it accurate.
- **Icons are third-party (Tabler, MIT).** If more are added, take them from
  the same set for visual consistency and keep the notice.
