# Plan 016: Move "Last read" into the meta list, centre the status badge under the gauge, and give it a glow

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat baa0374..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/dashboard.php`
> If it changed since this plan was written, compare the "Current state" excerpts
> against the live code before proceeding; on a mismatch, treat it as a STOP
> condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: 015 (merged to `dev` as `8249575`)
- **Category**: direction (user-requested UI change)
- **Planned at**: commit `baa0374`, 2026-07-29
- **Branch from**: `dev`

## Why this matters

Three requests from the maintainer after seeing the tile on hardware:

> Now move "Last read:" under Alert Threshold:
>
> Then move the warning pill under the temperature gauge, center aligned.
>
> Also noticed that the warning pill does not have the amber glow, so make sure
> the warning pill still glows when normal and in warning

**"Warning pill" means the status badge** — the small `● WARNING` / `● OK` chip,
class `.lu-d-badge`. It is *not* the temperature pill (`.lu-d-pill`), which is a
different element that plan 015 just made collapsed-only. Do not confuse them;
both are pill-shaped and both are colour-driven.

**The "amber glow" is real and identifiable.** The temperature gauge has one and
the badge does not:

```css
.lu-d-tile .lu-d-circle {
  ...
  filter:drop-shadow(0 0 8px color-mix(in srgb, var(--tc,#2ecc71) 30%, transparent));
}
```

`.lu-d-badge` has no `box-shadow` and no `filter` — hence no glow. Confirmed:
`grep -c 'box-shadow'` over the whole file returns `0`, and `drop-shadow` returns
`1` (the gauge).

Both the gauge glow and the badge's colour come from `var(--tc)`, which is set
inline per controller from `lsi_status_color($status)` — green when ok, amber on
warn, red on alert. So **one shadow declaration covers all three states
automatically**; there is no per-state CSS to write. That is what satisfies "still
glows when normal and in warning".

## Current state

`source/usr/local/emhttp/plugins/hbaviewer/dashboard.php`.

### The markup being changed

```php
    $t['body'] = "
    <div class='lu-d-ctl'>
      <div class='lu-d-overview'>
        <div class='lu-d-circle' style='--tc:{$col};--pct:{$temp}'>
          <span class='v'>{$temp}</span>
          <span class='u'>°C</span>
        </div>
        <div class='lu-d-meta'>
          <p>Model: <span>{$model}</span></p>"
          . ($chip     ? "<p>Chip: <span>{$chip}</span></p>"         : '')
          . ($firmware ? "<p>Firmware: <span>{$firmware}</span></p>" : '')
          . ($bios     ? "<p>BIOS: <span>{$bios}</span></p>"         : '')
          . ($v['port_name'] !== '' ? "<p>lsiutil Port: <span>{$portLabel}</span></p>" : '')
          . ($mode     ? "<p>Mode: <span>{$mode}</span></p>"         : '')
          . ($drives   ? "<p>Drives: <span>{$drives} connected</span></p>" : '')
          . "<p>Alert Threshold: <span>{$threshold}°C</span></p>
          <span class='lu-d-badge' style='--tc:{$col}'>{$badge}</span>
        </div>
      </div>
    </div>"
    . $t['foot']
    . "<div class='lu-d-ts'>Last read: {$ts}</div>";
```

So today: the badge sits **inside** `.lu-d-meta`, after Alert Threshold; and
"Last read" is a separate `.lu-d-ts` div **after** the footer.

### The relevant CSS

```css
.lu-d-tile .lu-d-overview { display:flex; align-items:center; gap:16px; }
.lu-d-tile .lu-d-circle {
  position:relative; width:84px; height:84px; flex-shrink:0; border-radius:50%;
  background:conic-gradient(var(--tc,#2ecc71) calc(var(--pct,0)*1%), #2a2a2a 0);
  display:grid; place-items:center;
  filter:drop-shadow(0 0 8px color-mix(in srgb, var(--tc,#2ecc71) 30%, transparent));
}
.lu-d-tile .lu-d-meta { flex:1; }
.lu-d-tile .lu-d-meta p   { margin:3px 0; font-size:12px; color:#ddd; display:flex; justify-content:space-between; gap:10px; border-bottom:1px dashed #2a2a2a; padding-bottom:2px; }
.lu-d-tile .lu-d-meta span { color:#ddd; font-weight:500; font-family:ui-monospace,"SF Mono",Menlo,monospace; font-variant-numeric:tabular-nums; }
.lu-d-tile .lu-d-badge {
  display:inline-flex; align-items:center; gap:6px; margin-top:6px;
  padding:3px 11px; border-radius:20px;
  font-size:10px; font-weight:700; letter-spacing:0.05em;
  color:var(--tc,#2ecc71); background:color-mix(in srgb, var(--tc,#2ecc71) 16%, transparent);
}
.lu-d-tile .lu-d-badge::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; }
.lu-d-tile .lu-d-ts { font-size:10px; color:#ddd; text-align:right; margin-top:8px; font-family:ui-monospace,Menlo,monospace; }
```

### CRITICAL: `.lu-d-ts` has a second caller — do NOT delete the rule

The per-controller **error** tile also uses it, and that tile has no `.lu-d-meta`
to move the timestamp into:

```php
    if (isset($c['error'])) {
        $t['body'] = "<div class='lu-d-ctl'><span style='color:#d88'>Controller {$i}: "
                   . htmlspecialchars($c['error']) . "</span></div>"
                   . "<div class='lu-d-ts'>Last read: {$ts}</div>";
        $tiles[] = $t;
        continue;
    }
```

`grep -c 'lu-d-ts'` currently returns **3**: the CSS rule, the error tile, and the
normal tile. After this plan it must return **2** — the CSS rule and the error
tile. **Deleting the `.lu-d-ts` rule would leave the error tile's timestamp
unstyled**, and error tiles are hard to exercise, so it would ship unnoticed.

## Commands you will need

```bash
bash tests/run.sh          # must end "--- all pass ---"
bash tests/run_php.sh      # must exit 0
```

There is **no local `php`**. Lint through Docker — the `MSYS_NO_PATHCONV=1` prefix
is required on Git Bash or `-w /w` is mangled into `W:/`:

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "<abs-repo-path>:/w" -w /w php:8.2-cli \
  php -l source/usr/local/emhttp/plugins/hbaviewer/dashboard.php
```

`grep -c` **exits 1 when the count is 0**, which breaks `&&` chains. Separate
checks with `;`.

## Scope

**In scope**: `source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` only — its
CSS block and the `$t['body']` markup for the **successfully-read** controller.

**Out of scope** (do NOT touch):

- **The `.lu-d-ts` CSS rule** — still needed by the error tile. See above.
- **The error tile's body** (`if (isset($c['error']))`). Its `.lu-d-ts` div stays
  exactly as it is.
- **`.lu-d-pill` and its two rules** — that is the *temperature* pill, a different
  element, made collapsed-only by plan 015 and working. This plan does not touch it.
- **`.lu-d-foot-mini`, `.lu-d-foot-row`, and the `:has()` collapse rules.** Verified
  on hardware.
- **The gauge's own `filter:drop-shadow`.** It already glows; leave it alone. You
  are giving the badge a matching glow, not changing the gauge's.
- `$v['label']` / `lsi_status_label()` / `lsi_status_color()` in `view.php` — the
  badge's text and colour are correct.
- Any backend script, test, fixture or golden. This changes no JSON and no parser
  output.

## Git workflow

- **`git switch -c advisor/016-gauge-column-badge-glow dev`** — cut from `dev`, not
  `main`. A worktree from `main` has none of plans 012-015's tile markup and every
  excerpt above will mismatch.
- One commit. Short imperative subject, no conventional-commit prefix. Suggested:
  `Dashboard tile: Last read into meta, badge under the gauge with a glow`
- Do not push and do not open a PR.

## Steps

### Step 1: Add a column wrapper around the gauge and badge

New CSS rule. Put it directly after the `.lu-d-overview` rule so the two layout
rules sit together:

```css
/* Gauge column: the circle with the status badge centred beneath it. flex-shrink:0
   so the meta column's text never squeezes the gauge. */
.lu-d-tile .lu-d-gauge { display:flex; flex-direction:column; align-items:center; gap:8px; flex-shrink:0; }
```

**Verify**: `grep -c 'lu-d-gauge { display:flex; flex-direction:column; align-items:center' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `1`

### Step 2: Give the badge a glow, and drop its now-redundant top margin

In `.lu-d-badge`, add a `box-shadow` matching the gauge's glow, and remove
`margin-top:6px` — the wrapper's `gap:8px` now provides that spacing, and keeping
both would double it.

```css
.lu-d-tile .lu-d-badge {
  display:inline-flex; align-items:center; gap:6px;
  padding:3px 11px; border-radius:20px;
  font-size:10px; font-weight:700; letter-spacing:0.05em;
  color:var(--tc,#2ecc71); background:color-mix(in srgb, var(--tc,#2ecc71) 16%, transparent);
  box-shadow:0 0 8px color-mix(in srgb, var(--tc,#2ecc71) 30%, transparent);
}
```

`0 0 8px` at `30%` deliberately matches the gauge's `drop-shadow` so the two glows
look like one design rather than two guesses.

Use **`box-shadow`, not `filter:drop-shadow`**. The badge is an opaque rounded
rectangle, so `box-shadow` follows `border-radius` exactly and is cheaper to
composite. `filter` would also create a new containing block, which is
unnecessary here.

`var(--tc)` is set inline per controller, so this glows green when ok, amber on
warn and red on alert with no further CSS — which is what "still glows when normal
and in warning" asks for.

**Verify the glow exists**: `grep -c 'box-shadow:0 0 8px color-mix(in srgb, var(--tc,#2ecc71) 30%, transparent)' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `1`

**Verify the old margin is gone**: `grep -c 'margin-top:6px' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `0`

**Verify the gauge's own glow is untouched**: `grep -c 'filter:drop-shadow(0 0 8px color-mix(in srgb, var(--tc,#2ecc71) 30%, transparent))' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `1`

### Step 3: Restructure the body markup

Three edits inside the successfully-read controller's `$t['body']`:

1. Wrap the circle and the badge in the new `.lu-d-gauge` div.
2. Move the badge out of `.lu-d-meta` and into that wrapper, after the circle.
3. Move "Last read" into `.lu-d-meta` as a `<p>` row directly after Alert
   Threshold, and delete the trailing `.lu-d-ts` div from this tile.

The result:

```php
    $t['body'] = "
    <div class='lu-d-ctl'>
      <div class='lu-d-overview'>
        <div class='lu-d-gauge'>
          <div class='lu-d-circle' style='--tc:{$col};--pct:{$temp}'>
            <span class='v'>{$temp}</span>
            <span class='u'>°C</span>
          </div>
          <span class='lu-d-badge' style='--tc:{$col}'>{$badge}</span>
        </div>
        <div class='lu-d-meta'>
          <p>Model: <span>{$model}</span></p>"
          . ($chip     ? "<p>Chip: <span>{$chip}</span></p>"         : '')
          . ($firmware ? "<p>Firmware: <span>{$firmware}</span></p>" : '')
          . ($bios     ? "<p>BIOS: <span>{$bios}</span></p>"         : '')
          . ($v['port_name'] !== '' ? "<p>lsiutil Port: <span>{$portLabel}</span></p>" : '')
          . ($mode     ? "<p>Mode: <span>{$mode}</span></p>"         : '')
          . ($drives   ? "<p>Drives: <span>{$drives} connected</span></p>" : '')
          . "<p>Alert Threshold: <span>{$threshold}°C</span></p>
          <p>Last read: <span>{$ts}</span></p>
        </div>
      </div>
    </div>"
    . $t['foot'];
```

Note the last line: `. $t['foot'];` — the `. "<div class='lu-d-ts'>…"` that
followed it is gone **from this tile only**.

Wrapping `{$ts}` in `<span>` makes it inherit `.lu-d-meta span`, so the time
renders in the same tabular monospace as every other value in that list, and
`.lu-d-meta p`'s `justify-content:space-between` right-aligns it in line with them.

**Verify the timestamp moved**: `grep -c 'Last read: <span>{\$ts}</span>' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `1`

**Verify `.lu-d-ts` survives for the error tile only**: `grep -c 'lu-d-ts' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `2` (the CSS rule and the error tile)

**Verify the badge left the meta block** — the badge must no longer be the line
after Alert Threshold: `grep -A1 'Alert Threshold' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php | grep -c 'lu-d-badge'` → prints `0`

**Verify the badge is inside the gauge wrapper** — the line after the circle's
closing `</div>` should be the badge:
`grep -c "lu-d-gauge" source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `2` (the CSS rule and the markup div)

### Step 4: Lint and suites

```bash
bash tests/run.sh
bash tests/run_php.sh
MSYS_NO_PATHCONV=1 docker run --rm -v "<abs-repo-path>:/w" -w /w php:8.2-cli \
  php -l source/usr/local/emhttp/plugins/hbaviewer/dashboard.php
```

All three must pass, and **neither suite should change** — this touches only
`dashboard.php`, which no test renders. Golden churn is a STOP condition.

## Test plan

Nothing to automate. This is markup reordering plus one shadow declaration; no test
in the repo renders `dashboard.php` (`tests/ajax_render_test.php` covers
`ajax_info.php`), and asserting on a CSS literal would test the string rather than
the appearance.

**Operator will verify on hardware:**

1. **"Last read"** appears as the last row of the info list, directly under Alert
   Threshold, right-aligned and in the same monospace as the other values.
2. **The status badge sits under the temperature gauge, horizontally centred** on
   it — not in the info list.
3. **The badge glows**, in the same colour as its text and the gauge ring. Amber on
   a warning card; green on a healthy one.
4. Nothing below the info list is left over — no stray timestamp after the PCIe
   footer.
5. Collapse still works: header, temperature pill and footer remain.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'lu-d-gauge' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `2` — the CSS rule and the markup div
- [ ] `grep -c 'lu-d-gauge { display:flex; flex-direction:column; align-items:center' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `1`
- [ ] `grep -c 'box-shadow:0 0 8px color-mix(in srgb, var(--tc,#2ecc71) 30%, transparent)' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `1`
- [ ] `grep -c 'margin-top:6px' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `0`
- [ ] `grep -c 'filter:drop-shadow(0 0 8px color-mix(in srgb, var(--tc,#2ecc71) 30%, transparent))' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `1` — gauge glow untouched
- [ ] `grep -c 'Last read: <span>{\$ts}</span>' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `1`
- [ ] `grep -c 'lu-d-ts' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `2` — CSS rule kept, error tile kept, normal tile's div removed
- [ ] `grep -A1 'Alert Threshold' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php | grep -c 'lu-d-badge'` prints `0` — badge left the meta block
- [ ] `grep -c 'lu-d-badge' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `3` — base rule, `::before` rule, markup span
- [ ] `grep -c 'lu-d-pill' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `3` — temperature pill untouched
- [ ] `grep -c 'lu-d-tile:has(> tr:nth-child(2)\[style\*="display: none"\])' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `2` — both collapse rules intact
- [ ] `bash tests/run.sh` ends `--- all pass ---`
- [ ] `bash tests/run_php.sh` exits 0
- [ ] `php -l` on `dashboard.php` reports no syntax errors
- [ ] `git diff --stat dev..HEAD` shows exactly one file changed, `dashboard.php`
- [ ] `git status --porcelain` is empty after committing

## STOP conditions

Stop and report instead of improvising if:

- **Any golden file changes.** This plan alters no parser output.
- **You are about to delete the `.lu-d-ts` CSS rule.** The error tile still uses it.
  If `grep -c 'lu-d-ts'` reaches `1`, you have removed one caller too many.
- **You cannot make the badge glow visible without changing the gauge's shadow or
  the badge's background.** Report what you tried. Both are out of scope and the
  maintainer should judge the trade.
- **You are tempted to restyle the info list** because it gained a row, or to
  adjust `.lu-d-overview`'s `align-items:center` because the left column got
  taller. Out of scope. If the two columns look misaligned, say so in your report
  with a description; do not adjust it speculatively.
- **You are tempted to touch `.lu-d-pill`.** That is the temperature pill, not the
  badge. They are easy to confuse and it is working.

## Maintenance notes

- **`.lu-d-ts` now has exactly one caller**: the per-controller error tile. If that
  branch is ever removed or restructured, the rule becomes dead and can go with it.
  Until then, a "dead CSS" cleanup that deletes it will silently unstyle error
  tiles.
- **Three things now derive from `var(--tc)`**: the gauge ring and its drop-shadow,
  the badge's text/background/box-shadow, and the temperature pill's
  text/border/background. All are set from one inline `--tc` per controller, so a
  change to `lsi_status_color()` moves all of them together — which is the point.
- **The gauge glow and the badge glow are intentionally the same values**
  (`0 0 8px`, `30%`). If one is tuned, tune both or they will visibly diverge.
- **`.lu-d-gauge` uses `gap`, not margins.** Do not reintroduce `margin-top` on the
  badge; the spacing belongs to the container.
- **What a reviewer should scrutinise**: that `.lu-d-ts` is still present in the CSS
  *and* still used by the error tile (count exactly `2`), and that the badge moved
  rather than being duplicated — a copy-paste leaving one in the meta list and one
  under the gauge would render two badges and pass a naive `grep -c 'lu-d-badge'`
  of `4` rather than `3`.
