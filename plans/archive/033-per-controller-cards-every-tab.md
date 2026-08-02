# Plan 033: One card per HBA on every per-controller tab

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat cba200e..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php`
> Expected output: **nothing**. Every excerpt below is quoted from `cba200e`
> (tip of `advisor/032-health-row-icons`). Any difference is a STOP condition.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: LOW-MEDIUM — touches every tab's outer markup, so a mistake is
  visible everywhere at once
- **Depends on**: **032** (`advisor/032-health-row-icons`, unmerged). Branch
  from 032, not from `dev`.
- **Category**: design
- **Planned at**: `cba200e`, 2026-08-01
- **Requested by**: maintainer

## What changes

The Overview tab already gives each HBA its own card. Every other
per-controller tab stacks all controllers inside **one** card. Make them match
the Overview.

**Four tabs change: Health, PHY, Drives, Events.**

**SMART does not.** `renderSmartTable()` takes `$data['drives']` and emits a
single flat table with no controller loop at all — there is nothing to split.
Leave it, and leave Performance and Firmware/BIOS alone for the same reason.
Confirm this yourself before starting:

```php
function renderSmartTable(array $data): string {
    $drives = $data['drives'] ?? [];
```

## Current state

### `hbaviewer.php` — each pane wraps everything in one card

```html
<div id="tab-health" class="lu-tab-pane">
  <div class="lu-card first">
    <div class="lu-tab-toolbar">
      <span style="font-size:12px;color:var(--text);">Thermal, link integrity, topology, host link, and read health — each judged independently</span>
      <button class="lu-refresh-btn" onclick="luReloadTab('health')">Refresh</button>
    </div>
    <div id="health-content"><div class="lu-loading">Loading…</div></div>
  </div>
</div>
```

`tab-phy`, `tab-drives` and `tab-events` follow the identical shape. The single
`.lu-card first` is what makes every controller share one card.

### `hbaviewer.php` — the Overview pane, which is the target shape

```html
<div id="tab-overview" class="lu-tab-pane active">
  <div id="overview-content"><div class="lu-loading">Loading HBA information… (first read can take up to 60 seconds)</div></div>
</div>
```

**No card in the shell.** The renderer emits the cards.

### `ajax_info.php` — how the Overview renderer emits one card per controller

```php
foreach (lsi_controllers($data) as $i => $c) {
    ...
    $out .= '<div class="lu-card first" style="--td:' . $gDark . ';--tl:' . $gLight . ';--sc:' . $v['color'] . '" data-ctl="' . $i . '">'
```

This is the pattern the four renderers must adopt. Note it also sets
`data-ctl` — keep doing that, it is how a card is identified per controller.

### The four renderers that need cards

All four already loop controllers and already emit a per-controller heading:

| function | line | loop |
|---|---|---|
| `renderPhyTables` | 304 | `foreach ($ctls as $i => $ctl)` at 309 |
| `renderDrivesTables` | 361 | at 366 |
| `renderEventsTables` | 433 | at 438 |
| `renderHealthTables` | 502 | at 506 |

**Read each one fully before editing it.** They differ in how they handle the
empty and error cases, and those paths must keep working — an error for one
controller must not swallow the others' cards.

## The toolbar decision

Each of the four panes has a `.lu-tab-toolbar` (description + Refresh button)
that currently lives *inside* the card being removed. It has to go somewhere.

**Put it directly in the pane, above the cards, with no card wrapper.** The
description and the Refresh button are about the *tab*, not about any one
controller, so wrapping them in their own card would imply they belong to the
first HBA. A bare toolbar row reads as a tab header, which is what it is.

If `.lu-tab-toolbar` relies on the card for padding or a background, give it
its own minimal rule rather than reinstating a card — check the existing CSS
before assuming either way.

## Scope

**In scope**:

- `hbaviewer.php`: remove the `.lu-card first` wrapper from `tab-health`,
  `tab-phy`, `tab-drives`, `tab-events`. The toolbar and the content div stay,
  the toolbar moving to pane level.
- `ajax_info.php`: in `renderPhyTables`, `renderDrivesTables`,
  `renderEventsTables` and `renderHealthTables`, wrap **each controller's**
  section in `<div class="lu-card first" data-ctl="N">`.
- Any `.lu-tab-toolbar` CSS needed now it is no longer inside a card.
- Whatever spacing rule makes stacked cards sit apart consistently — check
  what `.lu-card` already does with `margin-bottom` before adding anything.

**Out of scope** — do not touch:

- `renderSmartTable`, the SMART tab, the Performance tab, the Firmware/BIOS tab.
- The Overview tab or `renderOverviewCards` — it is already correct and is the
  reference, not a target.
- Any data, parser, state or indicator logic. **This plan changes markup and
  CSS only.** If `health.php`, `view.php` or anything under `scripts/` appears
  in the diff, you have gone out of scope.
- The dashboard tile.

## Steps

### Step 1: one tab first, end to end

Do **Health** completely — shell, renderer, CSS — and confirm it renders two
separate cards before touching the other three. The four are structurally
identical, so a mistake made once will otherwise be made four times.

### Step 2: the remaining three

Apply the same change to PHY, Drives and Events.

**Watch the error paths.** Each renderer has a branch for a controller that
errored or returned nothing. After this change that branch must still emit its
own card, or an errored controller will render bare text between two cards.

### Step 3: check the empty case

A tab with zero controllers must not render an empty card, and must keep
whatever "nothing found" message it shows today.

## Test plan

Follow `tests/ajax_render_test.php`'s existing convention — CLI harness, not
PHPUnit.

- For each of the four renderers, with a **two-controller** fixture: assert two
  `lu-card` occurrences and two distinct `data-ctl` values.
- Assert an errored controller still produces its own card.
- Assert the zero-controller case produces no card.
- `bash tests/run.sh` → `--- all pass ---`.
- **Goldens**: these renderers may have goldens. If one moves, **STOP and
  report** — do not bless it. A moved golden here means the change reached
  further than markup, and re-blessing would hide that.
- `php -l` clean on both touched files.

## Done criteria

- [ ] Health, PHY, Drives and Events each render one card per controller
- [ ] Overview and SMART are byte-identical to before (`git diff` shows no
      change to `renderOverviewCards` or `renderSmartTable`)
- [ ] `grep -c 'lu-card first' hbaviewer.php` drops by exactly 4
- [ ] Two-controller fixtures produce two `data-ctl` values per tab
- [ ] An errored controller still gets its own card
- [ ] `bash tests/run.sh` → `--- all pass ---`, `git diff -- tests/expected/` empty
- [ ] `php -l` clean

## STOP conditions

- The drift check prints anything.
- **Any golden changes** — stop and report rather than re-blessing.
- `health.php`, `view.php`, or any `scripts/` file appears in the diff.
- `renderSmartTable` or `renderOverviewCards` is modified.
- An errored controller renders without a card.

## Maintenance notes

- **The Overview renderer is now the reference implementation for five
  renderers, not one.** If its card markup changes, the other four should
  follow, and a reviewer should check they did.
- **`.lu-card first` appears in both the shell and the renderers.** The `first`
  modifier controls a border radius; confirm what it does when several cards
  stack, since it was written when only the Overview stacked them.
