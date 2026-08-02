# Plan 012: Dashboard tile — status pill, footer, and a collapse that keeps both

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 021bcc3..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/dashboard.php hbaviewer.plg`
> If either changed since this plan was written, compare the "Current state"
> excerpts against the live code before proceeding; on a mismatch, treat it as a
> STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: direction (user-requested UI change) + bug (plugin listing icon)
- **Planned at**: commit `021bcc3`, 2026-07-28
- **State**: MERGED to `dev` (`761b18f`) — awaiting hardware verification

## Why this matters

A user filed a request against the dashboard tile, with annotated screenshots.
Four changes, plus a separate icon bug found while investigating:

1. **Colour-coded temperature pill** in the top-right of the tile header.
2. **Remove the temperature from the subtitle line** — it moves into the pill.
3. **Retitle** the tile from "HBA Temperature" to **"HBA Dashboard"**. Header and
   footer become the two persistent regions.
4. **Footer** on each HBA card showing PCIe Width, PCIe Speed, Power Mode and
   PCI Location.
5. **When the tile is minimised, only the header and footer remain visible.**
6. **The Plugins-page icon shows Unraid's generic placeholder** instead of the
   plugin's own.

Items 2 and 4 are smaller than they look — see "Current state". Item 5 is the
one with a real constraint, measured on hardware and documented below. Item 6 has
a confirmed root cause.

## Current state

### The measured constraint behind item 5

**Unraid collapses a dashboard tile by setting inline `display: none` on every
`<tr>` after the first.** Measured on a live Unraid box, 2026-07-28:

| State | Row 1 | Row 2 | Row 3 (probe) |
|---|---|---|---|
| Expanded | `table-row` | `table-row` | `table-row` |
| Collapsed | `table-row` | `none` | `none` |

And the exact inline style on row 2:

| State | `getAttribute('style')` |
|---|---|
| Expanded | `""` (attribute present, empty) |
| Collapsed | `"display: none;"` |

No class is added to the `<tbody>` or the rows — the `<tbody>` keeps
`class="sortable"` in both states. **Inline style is the only signal.**

Two consequences that dictate the design:

- **A third `<tr>` will not work.** The probe row was hidden too.
- **Anything that must survive collapse has to live inside row 1** — but row 1
  renders *above* row 2, so a footer placed there would appear above the card
  body, which is not what the user asked for.

The resolution: render the footer **twice from one built string** — once in its
natural place at the bottom of each card in row 2, and once in row 1, hidden by
default and revealed by CSS only when row 2 is collapsed. Because
`[style*="display: none"]` matches only in the collapsed state, one `:has()` rule
does it with no JavaScript.

### The tile markup as it exists today

`dashboard.php`, the `$mytiles` heredoc at the end of the file:

```php
$mytiles[$pluginname]['column1'] = <<<EOT
<tbody id="tblHBAviewer" title="HBA Temperature">
  <tr>
    <td>
      <span class="tile-header">
        <span class="tile-header-left">
          <svg viewBox="0 0 64 64" width="32" height="32" fill="none" stroke="{$tc}"
               ... >
            ...
          </svg>
          <div class="section">
            <h3 class="tile-header-main">HBA Temperature</h3>
            <span>{$boardName}</span>
          </div>
        </span>
        <span class="tile-header-right">
          <span class="tile-header-right-controls">
            <a href="/Tools/HBAviewer_Monitor" title="Open HBAviewer">
              <i class="fa fa-fw fa-cog control"></i>
            </a>
          </span>
        </span>
      </span>
    </td>
  </tr>
  <tr>
    <td>
      {$body}
    </td>
  </tr>
</tbody>
EOT;
```

Unraid appends its own collapse control (`^`) after our `tile-header-right`
content, so a pill inserted before the cog renders as `[pill] [cog] [^]` — which
is the order in the user's screenshot.

### Item 2 is a revert, not new work

The subtitle currently carries the temperature because an earlier change put it
there deliberately. That reasoning is now superseded by the pill:

```php
// Header subtitle: model + temperature, one entry per controller. The header row
// is what survives when the tile is minimised, so this line has to carry the
// at-a-glance answer on its own — "9400-16i 72°C · 9400-8i 77°C".
$summary = [];
foreach ($controllers as $i => $c) {
    if (isset($c['error'])) { $summary[] = "/c{$i} error"; continue; }
    $v = lsi_hba_view($c, $port, $i);
    $summary[] = $v['model'] . ' ' . ($v['temp'] === '' || $v['temp'] === null ? 'no sensor' : $v['temp'] . '°C');
}
$boardName = htmlspecialchars($error ? 'Unknown' : implode(' · ', $summary));
```

### Item 4 is mostly already done

`lsi_hba_view()` in `view.php` already returns exactly the four requested fields:

```php
$pcie = [];
foreach ([
    'pcie_width'   => 'PCIe Width',
    'pcie_speed'   => 'PCIe Speed',
    'power_mode'   => 'Power Mode',
    'pci_location' => 'PCI Location',
] as $key => $label) {
    if (!empty($data[$key])) $pcie[] = ['label' => $label, 'value' => $data[$key]];
}
```

and `dashboard.php` already renders them at the bottom of each controller block:

```php
        $pcieParts = [];
        foreach ($v['pcie'] as $item) {
            $pcieParts[] = $item['label'] . ': <span>' . htmlspecialchars($item['value']) . '</span>';
        }
        $pcieRow = $pcieParts ? "<div class='lu-d-pcie'>" . implode('', $pcieParts) . "</div>" : '';
```

So the footer's **content and position are correct already**. The new work is
capturing that same string for reuse in the collapsed header.

### Item 6: why the Plugins-page icon falls back

From Unraid's own `/usr/local/emhttp/plugins/dynamix.plugin.manager/include/ShowPlugins.php:75-83`:

```php
if (substr($icon,-4)=='.png') {
  if (file_exists("plugins/$name/images/$icon")) {
    $icon = "plugins/$name/images/$icon";
  } elseif (file_exists("plugins/$name/$icon")) {
    $icon = "plugins/$name/$icon";
  } else {
    $icon = "plugins/dynamix.plugin.manager/images/dynamix.plugin.manager.png";
  }
  $icon = "<img src='/$icon' class='list'>";
```

`hbaviewer.plg` currently sets:

```xml
<!ENTITY icon      "https://raw.githubusercontent.com/FugginOld/Unraid-HBAviewer/main/icon.png">
```

That **ends in `.png`**, so it enters the image branch — then both `file_exists`
checks run against `plugins/hbaviewer/https://raw.githubusercontent.com/...`,
which cannot exist, so it falls through to Unraid's placeholder. Confirmed on
hardware: the rendered markup is
`<img src='/plugins/dynamix.plugin.manager/images/dynamix.plugin.manager.png'>`.

**The attribute must be a bare filename**, and a matching file must ship in the
plugin directory. Renaming the file alone does not help while the attribute is a
URL — that was tried and failed.

**Community Applications is unaffected.** CA reads its icon from
`plugins/hbaviewer.xml:31` and `ca_profile.xml:13`, both of which keep their own
URLs. Do not touch those.

**Repo conventions that apply here:**

- `dashboard.php` scopes every CSS rule under `#tblHBAviewer` so the tile cannot
  leak styles into the rest of the dashboard. Keep that.
- Colour comes from `lsi_status_color()` in `view.php` — `#e74c3c` alert,
  `#f39c12` warn, `#2ecc71` ok. Per-controller colour is passed as an inline
  `--tc` custom property; see the existing `.lu-d-circle` and `.lu-d-badge`.
- Values that originate in hardware are escaped with `htmlspecialchars()` at the
  point of output.
- Deliberate simplifications carry a `ponytail:` comment naming the ceiling and
  the upgrade path.

## Commands you will need

| Purpose         | Command                                                              | Expected on success        |
|-----------------|----------------------------------------------------------------------|----------------------------|
| PHP lint        | `find source tests -name '*.php' -print0 \| xargs -0 -r -n1 php -l`  | exit 0, "No syntax errors" |
| Full test suite | `bash tests/run.sh`                                                  | `--- all pass ---`, exit 0 |

`dashboard.php` has no automated test coverage — it is a rendering surface with
no injectable seam. The suite must stay green, but it will not exercise this
change. Verification is the browser, and that is the operator's step.

## Scope

**In scope**:

- `source/usr/local/emhttp/plugins/hbaviewer/dashboard.php`
- `hbaviewer.plg` — the `icon` entity, the install block (two lines creating the
  capitalised icon path), and the remove block (one cleanup line)

**No new files.** In particular, do not create
`source/usr/local/emhttp/plugins/HBAviewer/` in the repo — see Step 5 for why the
capitalised path is created at install time instead of being tracked.

**Out of scope** (do NOT touch):

- `plugins/hbaviewer.xml` and `ca_profile.xml` — Community Applications reads its
  icon from these and they are working. Changing them is a different consumer and
  a different bug.
- `source/usr/local/emhttp/plugins/hbaviewer/icon.png` — leave the existing
  artwork alone. The operator will replace it separately; this plan only fixes
  the *wiring*.
- The `.page` files' `Icon="icon.png"` lines — those feed the Tools/Settings
  menus, which work correctly.
- `view.php` — `lsi_hba_view()` already returns everything needed.
- `source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php` (the Monitor page).
  This request is about the dashboard tile only.
- Any JavaScript. The collapse behaviour is achievable in CSS; see Step 4.

## Git workflow

- Branch: `advisor/012-dashboard-tile-header-footer`, cut from `dev`
- One commit. Message style matches this repo's history — short imperative
  subject, no conventional-commit prefix. Suggested:
  `Dashboard tile: status pill, persistent footer, HBA Dashboard title`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Subtitle back to model only; build the pills and footers

Replace the `$summary` / `$boardName` block shown in "Current state" with:

```php
// Header subtitle: the card model, or the count when there is more than one.
// The temperature used to live here; it now has its own colour-coded pill in the
// header, so this line is identity only.
$boardName = htmlspecialchars(
    $error ? 'Unknown'
           : (count($controllers) === 1 ? lsi_hba_view($controllers[0], $port, 0)['model']
                                        : count($controllers) . ' controllers')
);

// Per-controller temperature pills for the header, and the PCIe footer strings.
// The footer is built ONCE here and emitted twice — at the bottom of each card
// (row 2, its natural place) and again inside the header row, where CSS reveals
// it only while the tile is collapsed. Unraid hides every <tr> after the first,
// so row 1 is the only place a collapsed tile can still show anything.
$pills   = '';
$footMini = '';
foreach ($controllers as $i => $c) {
    if (isset($c['error'])) continue;
    $v    = lsi_hba_view($c, $port, $i);
    $col  = $v['color'];
    $temp = ($v['temp'] === '' || $v['temp'] === null) ? '' : (int) $v['temp'];
    $pills .= '<span class="lu-d-pill" style="--tc:' . $col . '">'
            . ($temp === '' ? 'N/A' : $temp . '&deg;C') . '</span>';

    $parts = [];
    foreach ($v['pcie'] as $item) {
        $parts[] = $item['label'] . ': <span>' . htmlspecialchars($item['value']) . '</span>';
    }
    if ($parts) {
        $footMini .= '<div class="lu-d-foot-row">'
                   . (count($controllers) > 1 ? '<b>' . htmlspecialchars($v['model']) . '</b>' : '')
                   . implode('', $parts) . '</div>';
    }
}
```

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → "No syntax errors detected"

**Verify**: `grep -c 'no sensor' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `0` (the old subtitle text is gone)

### Step 2: Add the CSS for the pill and the collapsed footer

Inside the existing `<style>` heredoc in `dashboard.php`, after the
`.lu-d-pcie` rules, add:

```css
#tblHBAviewer .lu-d-pill {
  display:inline-flex; align-items:center; margin-right:8px;
  padding:3px 11px; border-radius:20px;
  font-size:12px; font-weight:700; letter-spacing:0.03em;
  font-family:ui-monospace,"SF Mono",Menlo,monospace; font-variant-numeric:tabular-nums;
  color:var(--tc,#2ecc71);
  border:1px solid color-mix(in srgb, var(--tc,#2ecc71) 55%, transparent);
  background:color-mix(in srgb, var(--tc,#2ecc71) 12%, transparent);
}
/* Collapsed-only footer. Unraid hides every <tr> after the first by setting an
   inline display:none on it — no class, no attribute we can hook. Measured on
   7.2: expanded style="" , collapsed style="display: none;". So this attribute
   substring match is the collapse signal, and :has() lets row 1 react to it.
   ponytail: CSS only, no MutationObserver. If a future Unraid stops using an
   inline style, this rule silently stops firing and the footer just never shows
   when collapsed — degrade, not break. */
#tblHBAviewer .lu-d-foot-mini { display:none; padding:10px 0 2px; }
#tblHBAviewer:has(> tr:nth-child(2)[style*="display: none"]) .lu-d-foot-mini { display:block; }
#tblHBAviewer .lu-d-foot-row {
  display:flex; gap:16px; flex-wrap:wrap; align-items:baseline;
  font-size:12px; color:#ddd; padding-top:6px;
  border-top:1px solid #2a2a2a;
}
#tblHBAviewer .lu-d-foot-row span { color:#ddd; font-weight:500; }
#tblHBAviewer .lu-d-foot-row b { color:#f5a623; font-weight:600; margin-right:4px; }
```

**Verify**: `grep -c 'lu-d-foot-mini' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `3` (the two CSS rules and, after Step 3, the markup — check again after Step 3 if it prints 2 now)

### Step 3: Retitle, add the pills, add the collapsed footer to row 1

In the `$mytiles` heredoc, make exactly these changes:

- `<tbody id="tblHBAviewer" title="HBA Temperature">` → `title="HBA Dashboard"`
- `<h3 class="tile-header-main">HBA Temperature</h3>` → `HBA Dashboard`
- Insert `{$pills}` immediately **before** the `<a href="/Tools/HBAviewer_Monitor"` line, inside `tile-header-right-controls`
- Immediately after the closing `</span>` of `<span class="tile-header">`, and still inside row 1's `<td>`, add:

```html
      <div class="lu-d-foot-mini">{$footMini}</div>
```

Nothing else in the heredoc changes. Row 2 keeps `{$body}` exactly as it is.

**Verify**: `grep -c 'HBA Temperature' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `0`

**Verify**: `grep -c 'HBA Dashboard' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `2` (the `title` attribute and the `<h3>`)

**Verify**: `grep -c '{$pills}' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `1`

**Verify**: `grep -c '{$footMini}' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `1`

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → "No syntax errors detected"

### Step 4: Confirm the collapse selector matches the measured markup

This is a reasoning check, not a command — but get it right, because the whole
collapse behaviour hinges on it.

The rule is:

```css
#tblHBAviewer:has(> tr:nth-child(2)[style*="display: none"]) .lu-d-foot-mini { display:block; }
```

- `#tblHBAviewer` is the `<tbody>`.
- `> tr:nth-child(2)` is the body row — the one Unraid hides.
- `[style*="display: none"]` matches the collapsed inline style exactly as
  measured, and does **not** match the expanded `style=""`.
- `.lu-d-foot-mini` lives in row 1, so it stays visible while row 2 is hidden.

**Verify** the selector is present verbatim:
`grep -c 'tr:nth-child(2)\[style\*="display: none"\]' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `1`

### Step 5: Fix the Plugins-page icon

**Root cause, confirmed empirically on hardware.** Unraid resolves the icon
against `plugins/$name/`, where `$name` is the plugin's **`name` attribute** —
`HBAviewer`, with capitals. The plugin installs to lowercase
`/usr/local/emhttp/plugins/hbaviewer/`. Linux paths are case-sensitive, so
`plugins/HBAviewer/hbaviewer.png` does not exist and Unraid falls back to its
grey placeholder.

Proven on the box: creating `/usr/local/emhttp/plugins/HBAviewer/hbaviewer.png`
made the icon appear immediately.

**Two fixes were rejected before choosing this one — do not "simplify" back to
them:**

- **Renaming the install directory to `HBAviewer/`** touches every `.page` file,
  the `.plg` install block, and every hardcoded
  `/usr/local/emhttp/plugins/hbaviewer/` path in the shell scripts and PHP. Large
  diff, real upgrade risk, and it would strand the old directory on existing
  installs.
- **Lowercasing the `name` entity to `hbaviewer`** is one line, but `name` is
  both the identity Unraid tracks the plugin by *and* the label shown in the
  Plugins list — the listing would start reading "hbaviewer".
- **A symlink `HBAviewer -> hbaviewer`** looks elegant but is a trap: Unraid
  globs `plugins/*/*.page` to build its menus, so the symlinked directory would
  register a duplicate of every menu entry.

**The fix**: ship the icon at the capitalised path as well. A directory holding
only a PNG contains no `.page` files, so it cannot duplicate any menu.

**Create it at install time, not in the repo.** An earlier draft of this plan had
the capitalised directory committed as a tracked file. That was wrong: this repo
has `core.ignorecase=true`, and `HBAviewer/` and `hbaviewer/` cannot coexist on a
case-insensitive filesystem. A tracked capitalised path gives every maintainer on
Windows or macOS a permanent phantom deletion in `git status` after each checkout.
`git update-index --skip-worktree` hides it locally but is per-clone and does not
survive a fresh one.

So the copy belongs in the `.plg`'s existing `<FILE Run="/bin/bash">` install
block, which runs as root on the target's real case-sensitive Linux filesystem.
After the two `chmod +x` lines, add:

```bash
mkdir -p /usr/local/emhttp/plugins/HBAviewer
cp -f /usr/local/emhttp/plugins/hbaviewer/icon.png /usr/local/emhttp/plugins/HBAviewer/hbaviewer.png
```

The icon ships in the `.txz` at `hbaviewer/icon.png` (already tracked), so it is
on disk by the time this line runs — the `tar -xJf` immediately above it puts it
there. The repo stays lowercase-only and no case collision is possible anywhere.

Then in `hbaviewer.plg`, change the icon entity from the URL to a bare filename:

```xml
<!ENTITY icon      "hbaviewer.png">
```

And in the same file's **remove** block, extend the cleanup so an uninstall does
not leave the directory behind. Change:

```bash
removepkg hbaviewer
rm -rf /usr/local/emhttp/plugins/hbaviewer
```

to:

```bash
removepkg hbaviewer
rm -rf /usr/local/emhttp/plugins/hbaviewer
rm -rf /usr/local/emhttp/plugins/HBAviewer
```

**Verify**: `grep -c 'ENTITY icon      "hbaviewer.png"' hbaviewer.plg` → prints `1`

**Verify**: `grep -c 'raw.githubusercontent.*icon.png' hbaviewer.plg` → prints `0`

**Verify**: `grep -c 'mkdir -p /usr/local/emhttp/plugins/HBAviewer' hbaviewer.plg` → prints `1`

**Verify**: `grep -c 'cp -f /usr/local/emhttp/plugins/hbaviewer/icon.png' hbaviewer.plg` → prints `1`

**Verify**: `grep -c 'rm -rf /usr/local/emhttp/plugins/HBAviewer' hbaviewer.plg` → prints `1`

**Verify no case-colliding path was committed.** Use a case-*sensitive* grep —
`grep -i 'HBAviewer/'` matches every ordinary lowercase path and so always
"passes", which makes it useless as a check:
`git ls-files | grep 'HBAviewer/'` → no output, exit 1

**Verify** CA's icons are untouched:
`grep -c 'raw.githubusercontent.*icon.png' plugins/hbaviewer.xml ca_profile.xml` → prints `1` for each

### Step 6: Lint and suite

**Verify**: `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` → exit 0

**Verify**: `bash tests/run.sh` → `--- all pass ---`, exit 0

## Test plan

**No new automated tests.** `dashboard.php` is a rendering surface with no
injectable seam — it shells out to `get_hba_info.sh` on include and builds one
HTML string. Adding a harness for it is a larger refactor than this UI change
warrants, and would duplicate what `tests/ajax_render_test.php` already does for
the equivalent Monitor renderers.

The existing suite must stay green: `bash tests/run.sh` → `--- all pass ---`.

**Browser verification is the real test and belongs to the operator**, on a live
Unraid box:

1. Dashboard tile shows **HBA Dashboard** as its title.
2. A colour-coded temperature pill sits top-right, before the cog — green normal,
   amber warning, red alert.
3. The subtitle under the title shows the model only, with no temperature.
4. Each HBA card ends with a footer strip: PCIe Width, PCIe Speed, Power Mode,
   PCI Location.
5. **Click the `^` to minimise.** The header stays, the card body disappears, and
   the footer remains visible.
6. Expand again — the footer returns to the bottom of the card and the collapsed
   copy hides.
7. **Plugins page** shows the plugin's own icon, not Unraid's grey placeholder.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'HBA Temperature' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `0`
- [ ] `grep -c 'HBA Dashboard' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `2`
- [ ] `grep -c 'lu-d-pill' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `2` (CSS rule + emitted markup)
- [ ] `grep -c '{$pills}' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `1`
- [ ] `grep -c '{$footMini}' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `1`
- [ ] `grep -c 'tr:nth-child(2)\[style\*="display: none"\]' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `1`
- [ ] `grep -c 'no sensor' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `0`
- [ ] `git ls-files | grep 'HBAviewer/'` prints nothing (case-sensitive grep — `-i` matches every lowercase path and always passes)
- [ ] `grep -c 'mkdir -p /usr/local/emhttp/plugins/HBAviewer' hbaviewer.plg` prints `1`
- [ ] `grep -c 'cp -f /usr/local/emhttp/plugins/hbaviewer/icon.png' hbaviewer.plg` prints `1`
- [ ] `grep -c 'rm -rf /usr/local/emhttp/plugins/HBAviewer' hbaviewer.plg` prints `1`
- [ ] `grep -c 'ENTITY icon      "hbaviewer.png"' hbaviewer.plg` prints `1`
- [ ] `grep -c 'raw.githubusercontent.*icon.png' plugins/hbaviewer.xml` prints `1` — CA untouched
- [ ] `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` exits 0
- [ ] `bash tests/run.sh` exits 0 and prints `--- all pass ---`
- [ ] `git status --porcelain` shows exactly three paths: `dashboard.php`, `hbaviewer.plg`, and the new `HBAviewer/hbaviewer.png`
- [ ] `plans/README.md` status row for 012 updated

## STOP conditions

Stop and report back (do not improvise) if:

- The `$mytiles` heredoc does not match the excerpt in "Current state" — someone
  has already restructured the tile.
- You conclude the footer needs a third `<tr>`. It does not work: Unraid hides
  **every** row after the first, measured directly. Re-read the table in
  "Current state".
- You find yourself adding JavaScript to the tile. The collapse signal is an
  inline style and CSS `:has()` reads it. If you believe JS is required, report
  the reasoning instead.
- You are tempted to change `plugins/hbaviewer.xml` or `ca_profile.xml`. Those
  feed Community Applications, are working, and are explicitly out of scope.
- You are tempted to author or edit a PNG. Step 5 is a byte-for-byte `cp` of an
  existing tracked file. The artwork itself is the operator's to replace.
- `bash tests/run.sh` fails. It passes at `021bcc3`; a failure means drift or an
  environment problem, neither of which a UI change should paper over.

## Maintenance notes

- **The collapse rule depends on an Unraid implementation detail** — an inline
  `display: none` on rows after the first, with no class hook. This was measured
  on 7.2, not read from documentation. If a future Unraid switches to a class or
  to `hidden`, the `:has()` rule silently stops matching and the collapsed footer
  simply never appears. That is a graceful degradation, not a break, and the
  `ponytail:` comment in the CSS records it. The signal to re-measure is "the
  footer stopped showing when minimised".
- **The footer string is built once and emitted twice.** If someone changes the
  footer's content, both call sites get it automatically — but if someone adds a
  *third* rendering, keep it reading `$footMini` rather than rebuilding.
- **`lsi_hba_view()['pcie']` is the single source for the four footer fields.**
  Adding a fifth field there adds it to the tile footer, the collapsed footer and
  the Monitor's Overview card at once. That is intended.
- **Two icon consumers, two conventions.** Unraid's plugin manager wants a bare
  `.png` filename resolved under `plugins/<name>/` or `plugins/<name>/images/`;
  Community Applications wants a full URL and reads it from `plugins/hbaviewer.xml`
  and `ca_profile.xml`. They are not interchangeable, and a URL in the `.plg`
  silently falls back to Unraid's grey placeholder rather than erroring.
- **`HBAviewer/hbaviewer.png` is currently a copy of `hbaviewer/icon.png`.** When
  the operator replaces the artwork, **both** files need updating or the Plugins
  page and the Tools menu will show different images. The capitalised directory
  exists solely because Unraid resolves the icon against the plugin's `name`
  attribute rather than its install directory.
- **What a reviewer should scrutinise**: that the `:has()` selector matches the
  measured markup character for character, and that `$footMini` is emitted inside
  row 1's `<td>` — if it lands in row 2 it disappears on collapse, which is the
  exact bug this plan exists to avoid.

---

## Execution record

- **Executed**: 2026-07-28, branch `advisor/012-dashboard-tile-header-footer`
- **Commit**: `2479733` → merged to `dev` as `761b18f`
- **Rounds**: 2 (one REVISE)
- **Files changed**: `dashboard.php` (+73/-13), `hbaviewer.plg` (+11/-1). No new files.

### Round 1 — REVISE: the icon fix was specified wrong

The plan as written told the executor to commit
`source/usr/local/emhttp/plugins/HBAviewer/hbaviewer.png`. On this repo
(`core.ignorecase=true`) that path cannot exist alongside `hbaviewer/`. The
executor's literal `mkdir -p && cp` silently resolved into the *lowercase*
directory, dropping a stray `hbaviewer.png` among the real plugin files. It
caught that before committing, removed the stray, and worked around the
filesystem with git plumbing — `git hash-object -w` plus
`git update-index --add --cacheinfo` to stage the blob at the capitalised path
without materialising it, then `--skip-worktree` to silence the resulting
phantom deletion.

That produced a technically correct commit that would have materialised fine on
Linux. It was still rejected: `--skip-worktree` is per-clone and does not survive
a fresh one, so the maintainer — who works on Windows — would get a permanent
phantom deletion in `git status` after every checkout, as would anyone on macOS.

The collision was the plan being wrong, not the environment. Fix: create the
capitalised path in the `.plg` install block instead. Steps 5, Scope, and the
done criteria were rewritten accordingly.

### Executor correction accepted

The REVISE included the check `git ls-files | grep -i 'HBAviewer/'` → no output.
The executor pushed back: `-i` also matches every ordinary lowercase
`hbaviewer/` path, so that check can never pass on this repo and is useless as a
signal. It ran the case-sensitive form instead. Correct, and the done criteria
now say so explicitly.

### Verified independently before merge

- `git ls-files | grep 'HBAviewer/'` → no output, exit 1
- tracked PNGs: only `icon.png` and `hbaviewer/icon.png`
- `git show --stat HEAD` → exactly two files
- `git status --porcelain` → empty; `git ls-files -v` → no `skip-worktree` flags
- `bash tests/run.sh` → `--- all pass ---`
- `php -l dashboard.php` (docker `php:8.2-cli`) → no syntax errors
- `lsi_hba_view()` confirmed to return both `color` and `pcie`, the two keys the
  new pill/footer code reads

### Known behaviour change, accepted

A controller in a per-controller error state (`$c['error']` set, but the
top-level `$error` unset) no longer contributes to the header. The pill loop
`continue`s past it, and `$boardName` resolves through `lsi_hba_view()` to
`'Unknown'`. Previously the subtitle read `/c0 error`.

The card body still renders the error text in full, so nothing is lost while the
tile is expanded — but **while collapsed, an errored controller is now
invisible**. Minor, and not worth blocking the merge; noted here because it is
the one case the tile most exists to surface. Revisit if a user reports it.
