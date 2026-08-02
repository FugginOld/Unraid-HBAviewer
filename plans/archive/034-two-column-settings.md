# Plan 034: Lay the Settings page out in two columns

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat cba200e..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/settings.php`
> Expected output: **nothing**. Every excerpt below is quoted from `cba200e`
> (tip of `advisor/032-health-row-icons`). Any difference is a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW — one file, CSS layout only
- **Depends on**: none. Touches only `settings.php`, which plans 031–033 do not,
  so it can branch from `dev` or from 032 without conflict.
- **Category**: design
- **Planned at**: `cba200e`, 2026-08-01
- **Requested by**: maintainer — "the settings stack is getting tall"

## What changes

`settings.php` is a single vertical stack of three `.lu-s-card` sections. Lay
them out in two columns so the page stops growing downward.

## Current state

### `settings.php` — three sections in one column

```html
<div id="lu-settings-wrap">
  <form method="post">

    <div class="lu-s-card">
      <h3>HBA Connection</h3>
      ...
    </div>

    <div class="lu-s-card">
      <h3>Display Panels</h3>
      ...
    </div>

    <div class="lu-s-card">
      <h3>Advanced — Firmware Flashing</h3>
      ...
    </div>

  </form>
</div>
```

Sections are at lines 136, 195 and 226. The form wraps all three and the Save
button sits after them, inside the form.

## The layout — and why not plain columns

Three sections into two columns does not divide evenly, and the third is the
dangerous one.

**Use CSS grid, two columns, with the Advanced/Flashing section spanning both.**

```
┌────────────────────┬────────────────────┐
│ HBA Connection     │ Display Panels     │
├────────────────────┴────────────────────┤
│ Advanced — Firmware Flashing            │
└─────────────────────────────────────────┘
```

Reasons, in order of importance:

1. **`Advanced — Firmware Flashing` is the destructive one.** It unlocks a tab
   that writes firmware to the card. Full width keeps it visually separate from
   the routine toggles rather than sitting alongside them as a peer, and stops
   it being the thing someone flips past while scanning a column.
2. It balances. Two short sections side by side, one wide section below, reads
   deliberately; a ragged third column does not.
3. **Do not use CSS `columns`** (multi-column layout). It would let a section
   break across a column boundary mid-control, which is a genuine usability bug
   on a form, not just an aesthetic one.

## Scope

**In scope**:

- A grid rule on the form (or a wrapper inside it) giving two equal columns
  with a sensible gap, and `grid-column: 1 / -1` on the Advanced section.
- A responsive collapse to one column on narrow viewports. Unraid's settings
  pages are viewed on tablets and split screens; a fixed two-column grid at
  600px wide is unusable. Use the existing breakpoint if `settings.php` or
  `hbaviewer.php` already defines one — **check before inventing a number.**
- Whatever `align-items` / `align-self` is needed so two side-by-side sections
  of unequal height do not stretch oddly.

**Out of scope** — do not touch:

- Any form field, name attribute, label, default, or `LSI_SCHEMA` entry.
  **This plan does not change what the settings do or how they save.**
- The save handler, the notice, or the danger-text colours (plan 021 set those
  deliberately after contrast measurement).
- `config.php`.
- The order of the three sections. Grid placement changes where they *sit*, not
  the DOM order, which is also the tab order — keep the DOM order as-is so
  keyboard navigation still runs Connection → Panels → Advanced.

## Steps

### Step 1: the grid

Add the rule to `#lu-settings-wrap form` (or a new wrapper div immediately
inside the form — **not** a wrapper around the form, which would break the
POST). Confirm the Save button still sits below the grid and spans it, rather
than being pulled into a cell.

### Step 2: the responsive collapse

Confirm the page is usable at a narrow width. State in your report which
breakpoint you used and whether it already existed in the file.

### Step 3: verify the form still saves

The grid must not change what posts. Load the page, toggle something, save, and
confirm the value persists — or, if no browser is available, confirm by reading
that no `name` attribute and no input moved outside `<form>`.

## Test plan

- `php -l source/usr/local/emhttp/plugins/hbaviewer/settings.php` → clean.
- `bash tests/run.sh` → `--- all pass ---`. The config tests cover the save
  path; if any fails, the markup change has broken the form.
- **No golden may move.**
- Confirm by grep that all three `.lu-s-card` divs are still inside `<form>`
  and that every `name=` attribute present before is still present.

## Done criteria

- [ ] Three sections render as two columns with Advanced spanning both
- [ ] Collapses to one column on a narrow viewport
- [ ] DOM order unchanged: Connection, then Panels, then Advanced
- [ ] Every input still inside `<form>`; no `name` attribute added, removed or
      renamed (`grep -o 'name="[^"]*"' settings.php | sort` identical before/after)
- [ ] `bash tests/run.sh` → `--- all pass ---`, `git diff -- tests/expected/` empty
- [ ] `php -l` clean

## STOP conditions

- The drift check prints anything.
- Any `name=` attribute changes — that silently breaks saving for that setting.
- Any input ends up outside `<form>`.
- CSS `columns` is used instead of grid.
- The danger-section text colours change.

## Maintenance notes

- **A fourth section would land badly.** The layout assumes exactly three, with
  the third spanning. Whoever adds one must decide whether it pairs with
  Advanced or pushes Advanced down — worth a comment at the grid rule saying so.
- **The Advanced section spans deliberately, not incidentally.** It is the one
  that unlocks firmware writes. If someone later "tidies" it into a normal
  cell, that is a regression in emphasis even though nothing functional breaks.
