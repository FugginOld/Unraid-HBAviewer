# Handoff: Drive Bay Map — health-as-colour redesign (option 1b)

## Overview

Redesign of the physical bay map for a disk shelf / NAS chassis: a fixed grid of bays
(rows × columns) where each cell is one drive slot. The primary user task is **finding the
physical slot of a named device**; the secondary task is **spotting a problem drive at a glance**.

The current implementation renders every bay identically (green fill, uniform monospace text,
three alignment axes per card, no representation of empty slots). This redesign:

1. Makes colour mean something — bays are neutral until a state needs attention.
2. Renders every bay state, including **empty bays**, in the grid itself.
3. Adds a **temperature bar** so a hot row is visible without reading 24 numbers.
4. Fixes card hierarchy: device path is the anchor, serial drops to a label/value row.
5. Adds a **legend** above the grid.

## About the Design Files

`Drive Bay Map.dc.html` in this bundle is a **design reference written in HTML** — a prototype
showing intended look and behaviour. It is not production code to copy. Recreate option **1b**
in the target codebase using its existing environment (React/Vue/Svelte/etc.), component
primitives, and styling approach. The prototype uses inline styles purely because of its
authoring environment; in the real app, use whatever the codebase already uses (CSS modules,
Tailwind, styled-components…).

The file contains three options stacked vertically. **Only implement `1b`** (the middle one,
the one with the legend row). `1a` and `1c` are alternates the user did not pick — ignore them,
except as noted under "Deliberately out of scope".

## Fidelity

**High fidelity.** Colours, type sizes, weights, letter-spacing, radii and spacing below are
final and should be matched. Where the codebase already has an equivalent token (a semantic
`--color-danger`, a mono type ramp), prefer the existing token over the raw hex — the intent
matters more than the literal value.

---

## Screen: Bay map grid

**Purpose:** locate a drive by its slot, and see which bays need attention.

### Layout

- Container: panel background `#14181d`, `1px solid rgba(255,255,255,.08)`, radius `10px`,
  padding `20px`.
- **Legend row** at the top: `display:flex; align-items:center; gap:18px;`
  padding `0 2px 16px`, margin-bottom `16px`, bottom border `1px solid rgba(255,255,255,.07)`.
- **Grid:** `display:grid; grid-template-columns:repeat(<columns>, 236px); gap:10px;`
  Column count comes from the existing Rows/Columns controls. Bay cells are fixed-width
  (236px) so the grid reads as a chassis; the panel scrolls horizontally if needed rather
  than squeezing cells.
- A populated bay is ~140px tall (driven by content). An empty bay uses `min-height:140px`
  so rows stay even.

### Legend

Six items, each a `9×9px` swatch (radius `2px`) + label.
Label type: `500 10.5px/1 system-ui, sans-serif`, colour `#8b949e`, letter-spacing `.02em`.
Swatch/label pairs use `gap:7px`.

| Label | Swatch |
|---|---|
| Healthy | solid `#3fb950` |
| High temp | solid `#d29922` |
| Failed | solid `#f85149` |
| Resilvering | solid `#58a6ff` |
| Unassigned | solid `#6e7681` |
| Empty bay | transparent, `1px dashed #484f58` |

### Bay cell — populated

Shell: radius `6px`, `overflow:hidden`, and a **3px inset left rail** in the state colour via
`box-shadow: inset 3px 0 0 <stateColor>` (an inset shadow rather than a child element, so the
cell has no extra DOM and the rail follows the radius).

Per-state shell values:

| State | Background | Border | Inset rail |
|---|---|---|---|
| Healthy | `#1b1f24` | `1px solid rgba(255,255,255,.07)` | `#3fb950` |
| High temp | `#241f13` | `1px solid #d2992266` | `#d29922` |
| Failed | `#241618` | `1px solid #f8514966` | `#f85149` |
| Resilvering | `#141d2b` | `1px solid #58a6ff66` | `#58a6ff` |
| Unassigned | `#1b1f24` | `1px solid #6e768166` | `#6e7681` |

Content padding: `10px 12px 11px`. Four stacked blocks:

**1. Identity row** — `display:flex; align-items:center; gap:7px; margin-bottom:8px`
- Slot chip: text `1-1`; `600 9.5px/1 ui-monospace, Menlo, monospace`; colour `#8b949e`;
  background `rgba(255,255,255,.07)`; padding `3px 5px`; radius `3px`; letter-spacing `.04em`.
- Device path: text `/dev/sda`; `600 14px/1 ui-monospace, Menlo, monospace`; colour `#e6edf3`;
  letter-spacing `-.01em`. **This is the anchor of the card** — largest mono element.
- Status chip, pushed right with `margin-left:auto`:
  `600 8.5px/1 system-ui, sans-serif`; padding `3px 5px`; radius `3px`; letter-spacing `.08em`;
  `color: <stateColor>`; `background: <stateColor>22` (13% alpha).
  Labels: `HEALTHY`, `HIGH TEMP`, `FAILED`, `RESILVER`, `UNASSIGNED`.

**2. Capacity row** — `display:flex; align-items:baseline; gap:8px; margin-bottom:7px`
- Value: `600 16px/1 system-ui, sans-serif`; colour `#e6edf3`; letter-spacing `-.02em`
  (e.g. `12.733`).
- Unit `TB`: `400 9.5px/1 system-ui`; colour `#6e7681`; letter-spacing `.06em`.
- Temperature, `margin-left:auto`: `600 11.5px/1 ui-monospace, Menlo, monospace`, colour from
  the heat scale below (e.g. `44°C`).

**3. Temperature bar** — track `height:3px; radius:2px; background:rgba(255,255,255,.07);
overflow:hidden; margin-bottom:11px`. Fill: `height:100%; radius:2px;`
`width` = `clamp(6, ((temp - 30) / 25) * 100, 100)` percent, `background` = heat colour.
For a **resilvering** drive the fill is an animated stripe instead of a flat colour:
```css
background-image: repeating-linear-gradient(115deg,#58a6ff 0 5px,rgba(88,166,255,.35) 5px 10px);
background-size: 14px 100%;
animation: rebuild .7s linear infinite;
/* @keyframes rebuild { from { background-position:0 0 } to { background-position:14px 0 } } */
```
Respect `prefers-reduced-motion: reduce` — drop the animation, keep the stripe.

**4. Reference rows** — `display:grid; grid-template-columns:42px 1fr; row-gap:4px;
align-items:baseline`. Three rows: `PORT`, `MODEL`, `SERIAL`.
- Label: `500 8.5px/1.4 system-ui, sans-serif`; colour `#586069`; letter-spacing `.09em`.
- Value: `400 10.5px/1.4 ui-monospace, Menlo, monospace`;
  colour `#8b949e` for port/model, `#6e7681` for serial;
  `overflow:hidden; text-overflow:ellipsis; white-space:nowrap`.

This grid is what fixes the original problem: all values share **one left edge**, so the eye
scans a column instead of hunting centred text. Nothing in the cell is centre-aligned.

### Bay cell — empty

- `background:transparent; border:1px dashed #30363d; border-radius:6px; min-height:140px`
- Contents centred, `flex-direction:column; gap:6px`:
  - slot id, `600 9.5px/1 ui-monospace`, colour `#484f58`, letter-spacing `.04em`
  - word `EMPTY BAY`, `500 10px/1 system-ui`, colour `#3d444d`, letter-spacing `.12em`
- No rail, no chip, no metrics.

### Heat scale (temperature colour)

Driven off a configurable `warnTemp` threshold (default **45 °C**):

```
temp >= warnTemp + 4  ->  #f85149   (critical)
temp >= warnTemp      ->  #d29922   (warning)
temp >= warnTemp - 3  ->  #c9a227   (elevated)
otherwise             ->  #8b949e   (normal — deliberately not green)
```

Normal temperatures are **grey, not green**: a green number reads as a signal and there is
nothing to signal. Only the rail and status chip carry "healthy".

### Toolbar (above the panel)

The existing toolbar keeps Rows / Columns / Unlock, with two changes:
- Drop the sentence "Locked — the layout cannot be changed until you unlock it." Convey lock
  state on the control itself: disabled inputs plus the lock glyph already read as locked; a
  tooltip on the Unlock button is enough. That band of full-width prose is the single largest
  block of dead space in the current screen.
- The unlock affordance should be the primary control in the row (it is the only thing you can
  press while locked).

---

## Interactions & Behavior

Carried over from the existing implementation — this redesign does not change behaviour:

- **Click a bay → select it.** Selected state: replace the cell border with
  `1px solid rgba(255,255,255,.3)`. Selection must not use a state colour, or it becomes
  ambiguous with health.
- **Identify (blink LED)** and **drag to reposition** stay as they are today.
- Transitions: `background .16s ease, border-color .16s ease` on the cell.
- Only the resilver stripe animates. Nothing else moves.

## State Management

Per-bay data needed for rendering:

```
slot        "2-3"                      row-column id
device      "/dev/sdc"
port        1                          rendered as "Port 1"
capacityTB  "9.095"
model       "HUH721010AL4200"
serial      "7JJPE1RG"
tempC       51
state       "healthy" | "warning" | "failed" | "resilvering" | "unassigned" | "empty"
```

`state` should come from the backend health signal, not be derived in the view — except that a
drive over `warnTemp` may be promoted to `warning` client-side if the backend does not report it.

View-level state: `selectedSlot`, `warnTemp` (user setting, default 45).

## Design Tokens

**Surfaces**
```
panel bg            #14181d
panel border        rgba(255,255,255,.08)
bay bg (neutral)    #1b1f24
bay border          rgba(255,255,255,.07)
bay bg warning      #241f13
bay bg failed       #241618
bay bg resilver     #141d2b
empty bay border    #30363d  (dashed)
track               rgba(255,255,255,.07)
chip bg             rgba(255,255,255,.07)
page bg             #0d1013
```

**Status**
```
healthy      #3fb950
warning      #d29922
elevated     #c9a227
failed       #f85149
resilvering  #58a6ff
unassigned   #6e7681
```
State tints are the status colour at `22` alpha (chip background) and `66` alpha (border).

**Text**
```
primary        #e6edf3
secondary      #8b949e
tertiary       #6e7681
label          #586069
empty slot id  #484f58
empty word     #3d444d
```

**Type** — two families only: `ui-monospace, Menlo, monospace` for machine values
(device path, slot, port, model, serial, temperature) and `system-ui, sans-serif` for
human labels and the capacity number.
```
device path     600 14px/1   mono   -.01em
capacity        600 16px/1   sans   -.02em
temperature     600 11.5px/1 mono
slot chip       600 9.5px/1  mono   .04em
status chip     600 8.5px/1  sans   .08em
field label     500 8.5px/1.4 sans  .09em
field value     400 10.5px/1.4 mono
legend label    500 10.5px/1  sans  .02em
```

**Geometry**
```
bay width       236px      grid gap        10px
bay radius      6px        chip radius     3px
panel radius    10px       bar radius      2px
bay padding     10px 12px 11px
rail            inset 3px 0 0
```

## Deliberately out of scope

Two things from the prototype the user did not select — mention only if they come up:
- Option `1c`'s search field (filter by device/serial/model/port, dimming non-matches).
- Drag-to-reposition is not re-specified here; keep the existing implementation.

## Assets

None. No icons or images are required — every element is type, colour, or a rectangle. The
existing lock glyph on the Unlock button stays.

## Files

- `1b-bay-map.png` — rendered screenshot of the target design (2× resolution).
- `Drive Bay Map.dc.html` — the design reference. Three options stacked vertically; **implement
  the second one (`1b`)**, identifiable by the legend row above its grid.
- `Screenshot 2026-08-04 194601.png` — the current implementation, for before/after comparison.
