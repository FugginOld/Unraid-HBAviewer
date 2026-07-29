# Plan 014: Tile title "HBAviewer", model in the subtitle, drop the footer's duplicate model

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat aaaddba..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/dashboard.php`
> If it changed since this plan was written, compare the "Current state" excerpts
> against the live code before proceeding; on a mismatch, treat it as a STOP
> condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: 013 (merged to `dev` as `3e1b97f`) — this edits the per-tile
  markup 013 introduced
- **Category**: direction (user-requested UI change)
- **Planned at**: commit `aaaddba`, 2026-07-29
- **Branch from**: `dev`

## Why this matters

Plan 013 split the dashboard into one tile per HBA and titled each tile with its
board model. Seeing it on hardware, the maintainer wants the naming reorganised:

> On the Dashboard Tile, I want the Title of the tile to be **HBAviewer**. Below
> HBAviewer, I want the **HBA Model # - Controller /c#**. In the bottom, remove
> the redundant HBA model so that the rest of the information fits on 1 line.

So: the plugin name goes back in the title slot, the card identity moves to the
subtitle where it gains the controller index, and the footer loses the model it
was duplicating — freeing horizontal room for the four PCIe fields to sit on one
line.

This is a pure presentation change. No backend, no data, no tests to add.

## Current state

`source/usr/local/emhttp/plugins/hbaviewer/dashboard.php`. Four places.

**1. The error tile (no controllers could be read), around line 97:**

```php
    $tiles[] = [
        'key'  => "{$pluginname}_err",
        'id'   => 'tblHBAviewerErr',
        'tc'   => lsi_status_color('alert'),
        'main' => 'HBA Dashboard',
        'sub'  => 'Unknown',
```

**2. The per-controller tile defaults, around line 110:**

```php
    $t = [
        'key'  => "{$pluginname}_c{$i}",
        'id'   => "tblHBAviewer{$i}",
        'tc'   => lsi_status_color('alert'),
        'main' => 'HBA Dashboard',
        'sub'  => "Controller /c{$i}",
```

**3. Where a successfully-read controller overrides them, around line 140:**

```php
    // Header identifies this one card, and the icon/pill carry its own status.
    $t['tc']   = $col;
    $t['main'] = $model;
    $t['sub']  = $portLabel;
```

`$portLabel` comes from `lsi_hba_view()`, which builds it as:

```php
$portLabel = $portName !== '' ? "$portName (lsiutil -p$port)" : "Controller /c$idx";
```

So on storcli (SAS3) cards `$portLabel` is **already** exactly `Controller /c0`,
which is what the request asks for. On lsiutil (SAS2) cards it is
`ioc0 (lsiutil -p1)` instead — that is correct for those cards and must not be
"fixed" into a fake controller index.

**4. The footer, around line 157:**

```php
    $t['foot'] = "<div class='lu-d-foot-row'><b>{$model}</b>" . implode('', $parts) . "</div>";
```

And the CSS rule that styles that `<b>`, around line 83:

```css
.lu-d-tile .lu-d-foot-row b { color:#f5a623; font-weight:600; margin-right:4px; }
```

**5. The `<tbody>` tooltip, line 191:**

```php
<tbody id="{$id}" class="lu-d-tile" title="HBA Dashboard">
```

## Commands you will need

```bash
bash tests/run.sh          # must end "--- all pass ---"
bash tests/run_php.sh      # must exit 0
```

There is **no local `php`** on this workstation. Lint through Docker, and note the
`MSYS_NO_PATHCONV=1` prefix is required on Git Bash or `-w /w` is mangled to `W:/`:

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "<abs-repo-path>:/w" -w /w php:8.2-cli \
  php -l source/usr/local/emhttp/plugins/hbaviewer/dashboard.php
```

## Scope

**In scope**: `source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` only.

**Out of scope** (do NOT touch):

- `view.php` — `lsi_hba_view()` and `$portLabel` are correct as they are. The
  subtitle is assembled in `dashboard.php` from what it already returns.
- The pill, the `:has()` collapsed-footer rule, the `$tiles` array, the per-tile
  `id`/`key` scheme, `Last read:` — all from plan 013 and all working.
- Any backend script, any test, any fixture or golden. This change alters no JSON
  and no parser output.
- `hbaviewer.php` (the Monitor page) and `ajax_info.php`. Dashboard tile only.

## Git workflow

- **`git switch -c advisor/014-tile-title-and-footer-tidy dev`** — cut from `dev`,
  not `main`. A worktree provisioned from `main` will not contain plan 013's tile
  markup and every excerpt above will mismatch.
- One commit. Short imperative subject, no conventional-commit prefix. Suggested:
  `Dashboard tile: title HBAviewer, model in subtitle, drop footer duplicate`
- Do not push and do not open a PR.

## Steps

### Step 1: Title becomes the plugin name

Change **both** `'main' => 'HBA Dashboard'` occurrences (the error tile and the
per-controller defaults) to:

```php
        'main' => 'HBAviewer',
```

And change the `<tbody>` tooltip on line 191 to match:

```php
<tbody id="{$id}" class="lu-d-tile" title="HBAviewer">
```

**Verify**: `grep -c 'HBA Dashboard' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `0`

**Verify**: `grep -c "'main' => 'HBAviewer'" source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `2`

### Step 2: Model and controller index move to the subtitle

In the successfully-read-controller block, **delete** the `$t['main']` assignment
— the title must stay `HBAviewer` — and build the subtitle from the model plus the
port label:

```php
    // Title stays the plugin name; the subtitle identifies which card this tile is.
    // $portLabel is already "Controller /cN" on storcli cards and
    // "ioc0 (lsiutil -pN)" on lsiutil ones — both are the right thing to show.
    $t['tc']  = $col;
    $t['sub'] = $model . ' - ' . $portLabel;
```

Note `$model` is **already** `htmlspecialchars()`-escaped further up
(`$model = htmlspecialchars($v['model']);`) and `$portLabel` likewise. Do **not**
escape either again — double-escaping would render `&amp;` in a card name.

**Verify**: `grep -c "\$t\['main'\] = " source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `0`

**Verify**: `grep -c "\$t\['sub'\]  *= \$model \. ' - ' \. \$portLabel;" source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `1`

### Step 3: Drop the footer's duplicate model

The model now appears in the subtitle, so the footer copy is redundant and is
eating the horizontal room the four PCIe fields need. Change:

```php
    $t['foot'] = "<div class='lu-d-foot-row'><b>{$model}</b>" . implode('', $parts) . "</div>";
```

to:

```php
    $t['foot'] = "<div class='lu-d-foot-row'>" . implode('', $parts) . "</div>";
```

That leaves no `<b>` anywhere in the file, so the CSS rule styling it is now dead.
Delete it:

```css
.lu-d-tile .lu-d-foot-row b { color:#f5a623; font-weight:600; margin-right:4px; }
```

**Verify no `<b>` remains**: `grep -c '<b>' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `0`

**Verify the dead rule is gone**: `grep -c 'foot-row b' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `0`

**Verify the fields still render**: `grep -c "implode('', \$parts)" source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `1`

### Step 4: Lint and suites

```bash
bash tests/run.sh
bash tests/run_php.sh
MSYS_NO_PATHCONV=1 docker run --rm -v "<abs-repo-path>:/w" -w /w php:8.2-cli \
  php -l source/usr/local/emhttp/plugins/hbaviewer/dashboard.php
```

All three must pass. Neither suite should change at all — this touches no parser
output — so **any** golden churn here means something went wrong; report it rather
than regenerating.

## Test plan

Nothing to automate: this changes only which existing, already-escaped strings
land in which markup slot. `tests/ajax_render_test.php` covers `ajax_info.php`,
not `dashboard.php`, and there is no existing dashboard-render test to extend.

**Operator will verify on hardware:**

1. Each tile's title reads **HBAviewer**.
2. The line below it reads e.g. **HBA 9400-16i - Controller /c0**, and the second
   tile shows its own model and `/c1`.
3. The footer shows PCIe Width, PCIe Speed, Power Mode, PCI Location **on one
   line**, with no model prefix.
4. Collapse still leaves header, pill and footer visible.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'HBA Dashboard' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `0`
- [ ] `grep -c "'main' => 'HBAviewer'" source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `2`
- [ ] `grep -c 'title="HBAviewer"' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `1`
- [ ] `grep -c "\$t\['main'\] = " source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `0`
- [ ] `grep -c '<b>' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `0`
- [ ] `grep -c 'foot-row b' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `0`
- [ ] `grep -c 'lu-d-pill' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `2` — pill untouched
- [ ] `grep -c 'lu-d-tile:has(> tr:nth-child(2)\[style\*="display: none"\])' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `1` — collapse rule untouched
- [ ] `bash tests/run.sh` ends `--- all pass ---`
- [ ] `bash tests/run_php.sh` exits 0
- [ ] `php -l` on `dashboard.php` reports no syntax errors
- [ ] `git status --porcelain` shows exactly one modified file, `dashboard.php`
- [ ] `git diff --stat dev..HEAD` shows exactly one file changed

## STOP conditions

Stop and report instead of improvising if:

- **Any golden changes.** This plan alters no parser output; churn means an
  unintended edit reached a script.
- **`$portLabel` turns out not to be `Controller /cN`** on the storcli path. The
  subtitle format depends on it. Report what it actually contains rather than
  hardcoding `"Controller /c{$i}"` — that would print a wrong, storcli-shaped
  label on SAS2 cards, which **cannot be hardware-tested** (the maintainer has no
  SAS2 card), so the error would ship unnoticed.
- **You find yourself editing `view.php`.** The subtitle is assembled in
  `dashboard.php`; `lsi_hba_view()` is correct.
- **The four PCIe fields still wrap onto two lines** after removing the model. Say
  so in your report — do not start adjusting `gap`, `font-size`, or abbreviating
  labels. Whether that trade is worth making is the maintainer's call, and it
  needs a real browser at a real tile width to judge.

## Maintenance notes

- **The subtitle is the only thing naming which card a tile belongs to** now that
  the title is a constant. If a future change trims it, the collapsed tile becomes
  ambiguous on a multi-HBA box — the footer no longer carries the model either.
- **`$model` and `$portLabel` are pre-escaped** at their assignment sites. Any
  future edit that re-derives the subtitle must not re-escape them.
- **The tooltip and the `<h3>` are deliberately the same string.** If the title
  ever becomes dynamic again, they should stay in sync.
- **What a reviewer should scrutinise**: that `$t['main']` is genuinely gone
  rather than reassigned somewhere below, and that the `:has()` collapse rule and
  the pill survived untouched — both are silent failures, not errors.
