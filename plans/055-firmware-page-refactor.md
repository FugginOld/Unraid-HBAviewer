# 055 — Move Firmware/BIOS Update off the tab strip onto its own page

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat c3be441..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php source/usr/local/emhttp/plugins/hbaviewer/flash.php`
> Expected output: **nothing**. Every line number and excerpt below is quoted
> from `c3be441` (`dev` tip, 2026-08-06). Any difference is a STOP condition —
> re-derive the line numbers before editing, because this plan moves large
> contiguous ranges by line and a drifted range will cut in the wrong place.
>
> **Worktree note**: a fresh worktree may be cut from `main`, not `dev`, and
> `git switch dev` FAILS inside a worktree because `dev` is checked out in the
> main tree. Run `git log --oneline -1`, then use the command in "Git workflow"
> below, which lands on the right base either way.

## Status

Not started. Requested by the maintainer on 2026-08-06, in the same session that
fixed the frame width (`1ac1487`) and the Overview fill (`c3be441`):

> "I am thinking once you enable the firmware/Bios flashing, it should just go
> to it's own frame, like Settings."

## Why this matters

Firmware flashing is the one destructive action in a plugin that is otherwise
entirely read-only. It currently sits as the tenth tab in the same strip as
Overview, SMART and Event Log — one stray click away from monitoring, on a page
someone leaves open. The tab button is already coloured `--crit` (`hbaviewer.php:82-83`)
precisely because it does not belong with its neighbours; this plan finishes
that thought instead of restating it in red.

There is a second, quieter reason. The flash tab is the only tab whose markup
depends on machine state read at page load — `$arrayStopped` and `$csrfToken`
are parsed from `/var/local/emhttp/var.ini` in `hbaviewer.php:16-23` on *every*
page render, including for users who never open the tab and, when
`ENABLE_FLASH` is off, for users who cannot. Moving it makes that read belong to
the page that needs it.

**This plan is a refactor. No behaviour, no guard, and no wording changes.**
The flashing logic in `flash.php` is not touched at all.

## The actual problem: shared chrome, not the flash tab

Moving the markup is the easy half and it is not where the work is. Everything
that makes the plugin *look* like the plugin lives inline in one file:

- `hbaviewer.php:26-533` — one `<style>` block, 507 lines. Design tokens under
  `#lu-wrap`, cards, tables, tabs, and the per-feature CSS for every tab.
- `hbaviewer.php:739-1837` — one `<script>` block, ~1100 lines, containing the
  flash JS alongside every other tab's.

A standalone page needs the tokens and the card/table rules or it renders
unstyled. So the real task is **extracting the shared chrome so two pages can
use it without a second copy**, and only then moving the flash pieces onto it.

**The fact that makes this tractable**, and the first thing to re-verify:

```bash
sed -n '26,533p' source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php | grep -c '<?php\|<?='
```

Expected: **`0`**. The entire style block is static CSS with no PHP
interpolation, so it can become a plain `.css` file that both pages `<link>`,
rather than a PHP include that has to be executed twice. If this ever returns
non-zero, STOP — the approach below changes and this plan needs rewriting.

## Current state — the five pieces to move

Line numbers are from `c3be441`.

### 1. Config gate and state read — `hbaviewer.php:14, 16-23`

```php
$enableFlash = $cfg['ENABLE_FLASH'];
// Array must be stopped before flashing. Read the state once (cheap, no hardware);
// the flash.php preflight is the authoritative gate — this banner is advisory.
$arrayStopped = false;
$csrfToken    = '';
if ($enableFlash) {
    $vi = @parse_ini_file('/var/local/emhttp/var.ini');
    $arrayStopped = is_array($vi) && strtoupper((string) ($vi['mdState'] ?? '')) === 'STOPPED';
    $csrfToken    = is_array($vi) ? (string) ($vi['csrf_token'] ?? '') : '';  // Unraid requires this on POST
}
```

**`$csrfToken` is NOT flash-only.** It feeds `flashCsrf` in the JS, which the
bay map, Locate and the PHY baseline reset all post with. It must stay in
`hbaviewer.php`. Only `$arrayStopped` and the `$enableFlash` gate move.
Getting this wrong silently breaks every write button on the main page — see
STOP conditions.

### 2. Flash CSS — `hbaviewer.php:238-281`

Opens at the `/* ── Firmware/BIOS flash tab ── */` banner (line 238) and runs to
`.lu-fack` (line 281). Includes `#flash-content`'s auto-fit grid (`bfbf002`).
Self-contained; nothing outside the flash tab uses `.lu-flash-*`, `.lu-fc`,
`.lu-fbtn` or `.lu-fack`. Verify before cutting:

```bash
grep -n 'lu-flash-\|lu-fc\b\|lu-fbtn\|lu-fack\|flash-content' source/usr/local/emhttp/plugins/hbaviewer/*.php | grep -v 'hbaviewer.php:2[3-8][0-9]:'
```

### 3. Tab button — `hbaviewer.php:622`

```php
<?php if ($enableFlash): ?><button class="lu-tab-btn" data-tab="flash" onclick="luTab('flash')">Firmware/BIOS Update</button><?php endif; ?>
```

Becomes an `<a>` alongside the existing Settings link. The `.lu-tab-btn[data-tab="flash"]`
colour rules at `hbaviewer.php:90-91` need re-pointing at the new anchor.

### 4. Tab pane markup — `hbaviewer.php:714-734`

The `<?php if ($enableFlash): ?>` block: the warning banner, the array-state
banner, and `<div id="flash-content">`. Moves whole.

### 5. Flash JS — `hbaviewer.php:1595-1702`

`flashCard()`, `luFlashInit`, `luFlashList`, `luFlashUpload`, `luFlashGo`,
`luFlashPoll`. Ends at the `luFlashPoll` closing `};` on line 1702, immediately
before the Performance tab comment on line 1704.

Also delete the dispatch line inside `luTab` at `hbaviewer.php:758`:

```js
if (!loaded['flash']) luFlashInit();
```

The new page calls `luFlashInit()` on load instead of on tab activation.

**`fesc()` moves too, and it does not live with the rest.** It is defined at
`hbaviewer.php:895` — seven hundred lines above the flash JS, next to the tab
plumbing, which reads like shared infrastructure. It is not: its only callers
are `1611`, `1613`, `1614`, `1615`, all inside `luFlashInit`. Confirm before
cutting, because a missed non-flash caller is a runtime `ReferenceError` on the
main page and nothing in the suite would catch it:

```bash
grep -n 'fesc(' source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php
```

Expected: exactly five hits — the definition at `895` and four calls in
`1611-1615`. Anything else and `fesc()` stays in `hbaviewer.php` and the new
page gets its own copy; duplicating a four-line escaper beats a third shared
file for it.

## Scope

**In**:

- `source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php` (remove the five pieces)
- `source/usr/local/emhttp/plugins/hbaviewer/chrome.css` (new — extracted shared CSS)
- `source/usr/local/emhttp/plugins/hbaviewer/flash_view.php` (new — the page body)
- `source/usr/local/emhttp/plugins/hbaviewer/HBAviewer_Flash.page` (new)
- `hbaviewer.plg` (changelog entry only)
- `HOWTO.md` (the Firmware section's navigation wording)

**Out**:

- **`flash.php`.** Not one line. The endpoint, its preflight, the array-stopped
  gate and the CSRF handling are all unchanged. `tests/flash_php_test.php` and
  `tests/flash_test.sh` must stay untouched and green — that is the proof this
  refactor changed no behaviour.
- **Any flashing behaviour, warning wording, or safety gate.** Copy the markup
  across byte-for-byte. If a banner reads awkwardly on its own page, note it and
  leave it; re-wording a brick-your-card warning is its own decision.
- **`$csrfToken` in `hbaviewer.php`.** Stays. See piece 1.
- **Splitting the `<script>` block.** Only the flash functions move. A general
  JS extraction is a much larger job and is not needed here.
- **The `ENABLE_FLASH` setting itself** and `settings.php`. The toggle keeps its
  meaning; only what it gates moves.
- **Unraid menu placement bikeshedding.** Use `Menu="Utilities"` as
  `HBAviewer_Settings.page` does, so the two sit together.

## Git workflow

Branch from `dev` (`c3be441`), not `main`:

```bash
git log --oneline -1                              # expect c3be441 or a descendant
git switch -c advisor/055-firmware-page c3be441
```

One commit per step, message ending in `(plan 055)`.

## Steps

### Step 1: Extract the shared chrome to `chrome.css`

Re-run the zero-PHP check above. Then move `hbaviewer.php:27-532` (the block
*inside* the `<style>`/`</style>` tags) into a new `chrome.css`, and replace the
whole block in `hbaviewer.php` with:

```html
<link rel="stylesheet" href="/plugins/hbaviewer/chrome.css">
```

Nothing else changes in this step — the flash CSS moves out in Step 2, so this
commit is a pure extraction and the page must render identically.

**Verify**: load every tab and compare against a screenshot taken before the
change. `curl -s http://<box>/plugins/hbaviewer/chrome.css | head -3` returns
CSS, not a 404 or a login redirect.

> **If `chrome.css` 404s or is not served as `text/css`**, the webGui may not
> serve arbitrary static files from a plugin directory. Fall back to
> `chrome.php` — identical content wrapped in `<style>` tags, `require`d by both
> pages. That costs the browser cache and nothing else. Do not fight the
> webGui's static handling; take the fallback and note it in the commit.

### Step 2: Move the flash CSS out of `chrome.css` into the new page

Cut lines corresponding to the old `238-282` from `chrome.css`. They will be
inlined in `flash_view.php` (Step 3) — the flash rules are used by exactly one
page and do not belong in shared chrome.

### Step 3: Create `flash_view.php` and `HBAviewer_Flash.page`

`HBAviewer_Flash.page`, matching `HBAviewer_Settings.page`'s shape:

```
Menu="Utilities"
Title="HBAviewer Firmware"
Icon="icon.png"
Tag="hbaviewer-flash"
Type="xmenu"
---
<?PHP require_once "/usr/local/emhttp/plugins/hbaviewer/flash_view.php"; ?>
```

`flash_view.php` contains, in order: the `ENABLE_FLASH` gate and the
`$arrayStopped` / `$csrfToken` read from piece 1 (this page needs its **own**
CSRF token — it posts to `flash.php`), the `<link>` to `chrome.css`, the flash
CSS from Step 2, a `#lu-wrap` container, the markup from piece 4, and the JS
from piece 5 plus a `luFlashInit()` call on load.

**When `ENABLE_FLASH` is off**, render a short line saying flashing is disabled
and linking to Settings. Do **not** render the flash UI and rely on `flash.php`
to refuse — the page must not offer a control that cannot work.

### Step 4: Remove the five pieces from `hbaviewer.php`

In this order, re-deriving line numbers after each cut:

1. JS `1595-1702`, `fesc()` at `895`, and the `luTab` dispatch line `758`
2. Markup `714-734`
3. Tab button `622` → replace with an anchor beside the Settings link
4. `$arrayStopped` from `14-23`, **keeping `$csrfToken`**
5. Re-point the `--crit` colour rules at `90-91` from
   `.lu-tab-btn[data-tab="flash"]` to the new anchor's class

### Step 5: Changelog and docs

Add a `**Changed**` entry to `hbaviewer.plg` in the current unreleased section,
in the established voice — what moved, and why it is not a tab any more. Update
the Firmware/BIOS section of `HOWTO.md`, which currently tells the reader to
open a tab.

## Test plan

```bash
bash tests/run_php.sh      # expect 14/14 "all pass"
bash tests/run.sh          # shell suite
```

Then the JS syntax check this session used, adapted to whichever file holds the
script block after the split:

```bash
F=source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php
S=$(grep -n '^<script>$' "$F" | cut -d: -f1); E=$(grep -n '^</script>$' "$F" | cut -d: -f1)
sed -n "$((S+1)),$((E-1))p" "$F" | sed -E 's/<\?=[^>]*\?>/0/g' > /tmp/a.js
docker run --rm -v /tmp:/t node:20-alpine node --check /t/a.js
```

**On hardware**, with `ENABLE_FLASH` on:

1. Every main-page tab renders with correct styling — this is what Step 1 risks.
2. **The bay map still saves, Locate still starts, PHY baseline still resets.**
   All three post `flashCsrf`. If `$csrfToken` was dropped from `hbaviewer.php`
   they fail together, and this is the single most likely way to break the plugin.
3. Firmware page loads from Utilities, shows the warning and the correct
   array-state banner, lists controllers, and `Verify /cN` returns output.
4. `ENABLE_FLASH` off → the page says so and offers no controls; the tab strip
   has no firmware entry.
5. **Do not test an actual flash.** Verify/list is the boundary. A real flash
   needs the maintainer's own hardware and intent.

## STOP conditions

- The drift check prints anything.
- The zero-PHP check on `26,533` returns non-zero.
- `chrome.css` cannot be served — take the `chrome.php` fallback, but stop if
  *that* fails too.
- Any test in `run_php.sh` or `run.sh` that was green goes red. Especially
  `flash_php_test.php` — this plan does not touch `flash.php`, so a failure
  there means something moved that should not have.
- `grep -c csrfToken source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php`
  returns 0 after Step 4.
- The extraction changes any rendering. It is a move, not a redesign; if a tab
  looks different, something was dropped.

## Risks

**Highest: the CSRF token.** It reads like flash state, sits in the flash block,
and is used by three unrelated features. Dropping it breaks every write on the
main page while the page still loads and looks fine.

**Second: static file serving.** `chrome.css` is the clean answer but depends on
webGui behaviour not verified here. The fallback is known-good; take it early
rather than debugging nginx.

**Third: line drift.** This plan moves large ranges by number. Re-derive after
every cut — do not batch the deletions.

**Not a risk**: the flashing logic. It lives in `flash.php`, which this plan
does not open.
