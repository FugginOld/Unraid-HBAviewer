# Plan 037: Grey out flash Step 3 until the array is stopped

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 7a2f259..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php source/usr/local/emhttp/plugins/hbaviewer/flash.php`
> Expected output: **nothing**. Every excerpt below is quoted from `7a2f259`
> (tip of `advisor/035-js-tab-cards`). Any difference is a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW to implement, but it sits on the **flashing path** — the only
  place this plugin can permanently destroy hardware. Review accordingly.
- **Depends on**: **035** (`advisor/035-js-tab-cards`, unmerged). Branch from
  035, not from `dev` — this edits the flash-card markup 035 just restructured.
- **Category**: design / safety affordance
- **Planned at**: `7a2f259`, 2026-08-02
- **Requested by**: maintainer

## What changes

On the Firmware/BIOS tab, **Step 3 is visually disabled and non-interactive
while the array is running.** Steps 1 and 2 stay active.

Both exemptions are deliberate:

- **Step 1** only asks the flash tool to list what it can see on that
  controller. Read-only, useful for diagnosis regardless of array state, and
  the only thing a user can safely do while deciding whether to proceed.
- **Step 2** uploads an image to `/boot/config/plugins/hbaviewer/tools`. It
  touches no hardware. Leaving it enabled lets a user stage the image while the
  array is still serving, then stop the array and flash immediately — which
  **shortens array downtime**, the thing a maintainer actually cares about
  during a flash. Locking it would force all the fiddly file-picking to happen
  inside the outage window.

Step 3 is where hardware gets written. That is the one that locks.

## This is an affordance, not a control — read before starting

**The client-side disable is not a safety mechanism.** Anyone can re-enable a
disabled input from the browser console. The real gate is server-side and must
remain exactly as it is:

- `flash.php`'s `flash_array_stopped()` guard, which fails closed on a
  missing or unreadable `var.ini`
- the existing runtime check at `hbaviewer.php:695`:

```js
if (!flashArrayStopped) { alert('The array is not stopped. Stop it on the Main tab and reload this page.'); return; }
```

**Do not remove or weaken either.** After this plan the alert becomes a
belt-and-braces path that a normal user will never see — which is the point.
If you find yourself thinking "the button is disabled now, so this check is
redundant", stop and re-read this section.

## Current state

### `hbaviewer.php:17-21` — where the flag comes from

```php
$arrayStopped = false;
    $arrayStopped = is_array($vi) && strtoupper((string) ($vi['mdState'] ?? '')) === 'STOPPED';
```

### `hbaviewer.php:614` — how it reaches the browser

```js
var flashArrayStopped = <?= $arrayStopped ? 'true' : 'false' ?>;
```

Read once at page render. **This plan does not add live polling** — the
existing array-state banner already tells the user to stop the array *and
reload the page*, and matching that behaviour keeps one story rather than two.

### `hbaviewer.php:636-650` — the three steps, built in JavaScript

```js
+ '<div class="lu-fstep"><label class="step">Step 1 — verify the flash tool sees THIS card (controller /c'+i+' only)</label>'
+   '<button class="lu-fbtn" onclick="luFlashList('+i+')">Verify /c'+i+'</button>'
+   '<pre id="flash-list-'+i+'" style="display:none"></pre></div>'
+ '<div class="lu-fstep"><label class="step">Step 2 — upload the model-correct image (+ optional BIOS / tool)</label>'
+   'Firmware (.bin/.rom): <input type="file" id="flash-fw-'+i+'"><br><br>'
+   'BIOS (optional, .rom): <input type="file" id="flash-bios-'+i+'"><br><br>'
+   'Flash tool if not installed (sas2flash/sas3flash): <input type="file" id="flash-tool-'+i+'"> '
+   '<button class="lu-fbtn" onclick="luFlashUpload('+i+')">Upload</button> '
+   '<span id="flash-up-'+i+'" style="font-size:12px"></span></div>'
+ '<div class="lu-fstep"><label class="step">Step 3 — confirm &amp; flash</label>'
+   '<label class="lu-fack"><input type="checkbox" id="flash-ack-'+i+'"> I understand a wrong image can permanently brick this controller.</label>'
+   'Type <strong>FLASH</strong>: <input type="text" id="flash-confirm-'+i+'" placeholder="FLASH"> '
+   '<button class="lu-fbtn danger" onclick="luFlashGo('+i+')">Flash /c'+i+'</button></div>'
```

Each step is a `.lu-fstep` div. That is the hook to disable.

## Scope

**In scope** — `hbaviewer.php` only:

- A `.lu-fstep.is-locked` CSS state: reduced opacity, `pointer-events: none`,
  and a `cursor: not-allowed` on a wrapper so the block reads as unavailable.
- Applying that class to the **Step 3** div only, when `flashArrayStopped`
  is false.
- Setting the `disabled` attribute on the inputs and button inside Step 3 —
  the acknowledgement checkbox, the FLASH text field, and the Flash button.
  `pointer-events: none` alone leaves them keyboard-reachable, which would be a
  worse trap than leaving them enabled.
- A short explanatory line inside the locked region saying *why* it is locked
  and what to do — "Stop the array on the Main tab, then reload this page."
  A greyed control with no explanation is a support ticket.

**Out of scope** — do not touch:

- `flash.php`, `flash_array_stopped()`, `flash_hba.sh`, the single-flight
  lock, or any upload/flash handler.
- The `!flashArrayStopped` alert at line 695. **It stays.**
- The array-state banner at the top of the tab.
- Step 1 and `luFlashList`.
- **Step 2 and `luFlashUpload`** — uploading stays available while the array
  runs, deliberately.
- Any other tab.
- Live polling of array state.

## Steps

### Step 1: the CSS state

Add next to the existing `.lu-fstep` rules. Match the file's single-line
formatting in that block.

```css
.lu-fstep.is-locked { opacity: 0.45; pointer-events: none; }
.lu-fstep.is-locked .lu-flock { opacity: 1; }
```

The second rule keeps the explanatory line legible while the controls around
it are dimmed — a greyed-out explanation of why things are greyed out is
self-defeating.

**Check the resulting contrast**: at 0.45 opacity the step labels must still
be readable on both the light and dark themes. Plan 021 established that this
plugin's cards follow the Unraid theme, so test both. If 0.45 is too faint on
the light theme, raise it and say what you chose.

### Step 2: apply the class and disable the controls

When `flashArrayStopped` is false, add `is-locked` to the **Step 3** div, add
`disabled` to its checkbox, text input and button, and insert the explanation
line.

**Do not disable anything in Step 1 or Step 2.** If you find yourself locking
Step 2 "for consistency", re-read "What changes" — that costs array downtime
for no safety gain.

### Step 3: confirm the server gate is untouched

```bash
git diff dev..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/flash.php
```

Expected: **empty**. If `flash.php` appears in the diff at all, that is a STOP
condition.

Also confirm by reading that line 695's alert survives verbatim.

## Test plan

This tab is built in JavaScript, so the PHP render tests cannot reach it.
**Say so in your report rather than letting a green suite imply coverage.**

- `bash tests/run.sh` → `--- all pass ---` (proves only that nothing leaked
  into the PHP renderers).
- `git diff -- tests/expected/` empty.
- `php -l` clean.
- Exercise the changed function under a DOM shim with `flashArrayStopped` both
  true and false, and assert: with it false, **only Step 3** carries
  `is-locked` and its three controls carry `disabled`, while Step 1's Verify
  button and **all of Step 2's file inputs and Upload button remain enabled**;
  with it true, no `is-locked` and nothing disabled.

## Done criteria

- [ ] Array running: Step 3 dimmed, its three controls `disabled`,
      explanation visible and legible
- [ ] Array running: Step 1's Verify button AND Step 2's upload still work
- [ ] Array stopped: all three steps fully active, no `is-locked` anywhere
- [ ] `flash.php` unchanged (`git diff` empty for that file)
- [ ] The `!flashArrayStopped` alert at line 695 still present
- [ ] `bash tests/run.sh` → `--- all pass ---`, `git diff -- tests/expected/` empty
- [ ] `php -l` clean
- [ ] `git diff --name-only` lists only `hbaviewer.php` and plans

## STOP conditions

- The drift check prints anything.
- `flash.php` or anything under `scripts/` appears in the diff.
- The line-695 alert is removed, weakened, or made conditional on the new
  disabled state.
- Step 1 or Step 2 is disabled. Only Step 3 locks.
- Controls are hidden with `pointer-events: none` but left keyboard-reachable
  without `disabled`.
- A golden moves.

## Maintenance notes

- **The disabled state is cosmetic; the server guard is the control.** Anyone
  reviewing a future change here should confirm that removing the CSS entirely
  would still leave flashing blocked while the array runs. If that ever stops
  being true, the safety model has been inverted.
- **The flag is read once at page render.** A user who stops the array must
  reload. That matches the banner's existing wording; if either changes, both
  should.
- **Steps 1 and 2 are deliberately never locked.** Step 1 is read-only.
  Step 2 writes only to the plugin's own tools directory, and keeping it open
  lets a user stage the image before taking the array down — a real reduction
  in downtime. A future "lock the whole tab for consistency" change would
  remove capability and buy no safety, since the server guard is what actually
  blocks flashing.
