# Plan 035: Per-controller cards on the two JavaScript-built tabs

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 4338f68..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php`
> Expected output: **nothing**. Every excerpt below is quoted from `4338f68`
> (`dev` tip, 2026-08-01). Any difference is a STOP condition.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: LOW-MEDIUM — the Firmware/BIOS tab is the plugin's only destructive
  surface. This plan changes its *container markup only*, never its logic, but
  a reviewer should confirm that.
- **Depends on**: 033 (DONE, merged) — this completes it
- **Category**: design
- **Planned at**: `4338f68`, 2026-08-01
- **Requested by**: maintainer — "Performance tab did not split"

## Why this exists — plan 033 was incomplete

Plan 033 gave each HBA its own card on Health, PHY, Drives and Events. It
**missed Performance and Firmware/BIOS**, and the reason is worth recording so
the same survey error is not repeated:

033's investigation grepped the PHP renderers in `ajax_info.php` for
`lsi_controllers` / `foreach ($ctls`. Both of these tabs build their
per-controller boxes in **JavaScript**, inside `hbaviewer.php`, so neither
appeared. They were then wrongly assumed to have no controller split at all.

**Both do loop controllers.** SMART genuinely does not, and remains correctly
excluded — it renders a flat drive list from `$data['drives']`.

## Current state

### Performance — `hbaviewer.php`, the pane

```html
<div id="tab-perf" class="lu-tab-pane">
  <div class="lu-card first">
    <div class="lu-tab-toolbar">
      <span style="font-size:12px;color:var(--text);">Real-time throughput / IOPS / %util / latency / PHY-error-rate / temp &middot; sampled ~2s in your browser (last ~5&nbsp;min; resets on reload)</span>
    </div>
    <div id="perf-content"><div class="lu-loading">Waiting for first samples…</div></div>
  </div>
</div>
```

### Performance — `perfBuild()`, which already loops controllers

```js
ctls.forEach(function (c) {
    var box = document.createElement('div'); box.className = 'lu-perf-ctl';
    var h = document.createElement('h4'); h.textContent = 'Controller /c' + c.idx; box.appendChild(h);
    var grid = document.createElement('div'); grid.className = 'lu-perf-grid';
    ...
    box.appendChild(grid); host.appendChild(box); perfCharts[c.idx] = cells;
});
```

Existing CSS:

```css
.lu-perf-ctl { margin-bottom: 22px; }
.lu-perf-ctl h4 { margin: 0 0 10px; color: var(--accent); font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; }
```

`.lu-perf-ctl` is the box that must become a card.

### Firmware/BIOS — the per-controller boxes

```js
if (c.error) return '<div class="lu-fc"><h4>Controller /c'+i+'</h4><div class="lu-error">'+fesc(c.error)+'</div></div>';
...
return '<div class="lu-fc" data-ctl="'+i+'" data-chip="'+fesc(chip)+'">'
```

Note the **error branch omits `data-ctl`**. `flashCard(i)` looks up
`.lu-fc[data-ctl="'+i+'"]`, so an errored controller's box is already
unreachable by that selector today. Adding `data-ctl` to it is in scope; see
Step 3 for the caveat.

`.lu-fc` already has its own border and padding:

```css
.lu-fc { border: 1px solid var(--border-soft); border-radius: 10px; padding: 16px 18px; margin-bottom: 16px; background: var(--bg); }
```

### Firmware/BIOS — the pane has non-controller content first

```html
<div id="tab-flash" class="lu-tab-pane">
  <div class="lu-card first">
    <div class="lu-flash-warn">
      <strong>&#9888; Firmware / BIOS flashing.</strong> A wrong or mismatched image
      will <strong>permanently brick</strong> your controller. ...
    </div>
    <div class="lu-flash-array <?= $arrayStopped ? 'ok' : 'bad' ?>">
```

**This tab is not like the other five.** Before any controller box there is a
danger banner and an array-state banner. Those are page-level warnings, not
per-controller content, and they must stay prominent.

## Scope

**In scope**:

- Performance: drop the pane's single `.lu-card first`, move `.lu-tab-toolbar`
  to pane level (033 already added `.lu-tab-pane > .lu-tab-toolbar { padding: 0 20px; }`
  — reuse it, do not add a second rule), and give each `.lu-perf-ctl` the card
  treatment plus `data-ctl`.
- Firmware/BIOS: keep the danger banner and array-state banner **together in
  their own card at the top**, then one card per controller below.
- `data-ctl` on the flash error branch.

**Out of scope** — do not touch:

- Any flashing logic: `flash.php`, the upload handlers, `flashCard()`'s
  behaviour beyond the selector fix, the array-stopped gate, the confirmation
  flow. **This plan moves containers. It must not change what any button does.**
- The chart code: `perfChart`, `perfCell`, `luMetricsRender`, the sampling
  interval, `perfPrev`, or any series definition.
- SMART, Overview, Health, PHY, Drives, Events — all already correct.
- `ajax_info.php`. This plan is `hbaviewer.php` only.

## Steps

### Step 1: Performance

Give the box the same classes the PHP renderers use:

```js
var box = document.createElement('div');
box.className = 'lu-perf-ctl lu-card first';
box.setAttribute('data-ctl', c.idx);
```

Then drop `.lu-perf-ctl`'s own `margin-bottom: 22px` — `.lu-card` already
carries `margin-bottom: 16px`, and keeping both double-spaces the cards.
Confirm that by reading `.lu-card` before deleting anything.

**Verify**: with two controllers, two `.lu-card` boxes with distinct
`data-ctl`; the six charts still render inside each and still update.

### Step 2: Firmware/BIOS — the banners

Keep the danger banner and the array-state banner in the pane's existing
`.lu-card first`, and close that card **before** the controller list begins.
The banners keep their current prominence; only the controller boxes move out.

### Step 3: Firmware/BIOS — the controller boxes

Add `lu-card first` alongside `lu-fc` (keep `lu-fc` — its child selectors style
the inputs), and add `data-ctl` to the error branch.

**Caveat to check before changing the error branch**: `flashCard(i)` currently
cannot find an errored controller's box. Confirm no code path *relies* on that
returning `null` as an implicit "this controller can't be flashed" guard. If
anything does, adding `data-ctl` would make a previously-unreachable box
reachable — **stop and report rather than guessing.**

`.lu-fc` will now have both its own border and `.lu-card`'s. Resolve by
removing the duplicated properties from `.lu-fc`, not by suppressing
`.lu-card`.

## Test plan

These are JS-built, so the PHP render tests cannot reach them. That is a real
limit — say so rather than implying coverage.

- `bash tests/run.sh` → `--- all pass ---` (nothing should change; if anything
  does, the diff escaped `hbaviewer.php`'s markup).
- `git diff -- tests/expected/` empty.
- `php -l` clean.
- **Manual, on hardware, with two controllers**: Performance shows two cards
  with live charts in each; Firmware/BIOS shows the banners in one card and two
  controller cards below; the upload and flash controls still target the right
  controller.
- **Explicitly confirm the flash buttons still work per controller.** This is
  the one tab where a wrong `data-ctl` could send an image to the wrong card.

## Done criteria

- [ ] Performance renders one card per controller, charts live in each
- [ ] Firmware/BIOS renders banners in their own card, then one card per controller
- [ ] `data-ctl` present on every controller box on both tabs, including the
      flash error branch
- [ ] No double margin and no double border on either tab
- [ ] `git diff --name-only` lists only `hbaviewer.php` and plans
- [ ] `bash tests/run.sh` → `--- all pass ---`, `git diff -- tests/expected/` empty
- [ ] `php -l` clean

## STOP conditions

- The drift check prints anything.
- Any file other than `hbaviewer.php` (and plans) appears in the diff.
- Any change to flashing behaviour, the array-stopped gate, or chart sampling.
- Something depends on `flashCard()` returning `null` for errored controllers
  (see Step 3).
- A golden moves.

## Maintenance notes

- **Six tabs are per-controller; four are built in PHP and two in JavaScript.**
  That split is why 033 missed these. Anyone changing card structure must check
  both `ajax_info.php` and `hbaviewer.php`'s JS.
- **SMART is the only tab that is legitimately not per-controller**, and there
  is a test pinning that. If SMART ever gains a controller dimension, that test
  is the thing that will fail and it should be updated deliberately.
