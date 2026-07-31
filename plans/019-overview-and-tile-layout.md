# Plan 019: Overview and dashboard-tile layout — band under the gauge, health pill up top, system time format

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 5941c97..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php source/usr/local/emhttp/plugins/hbaviewer/dashboard.php source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php source/usr/local/emhttp/plugins/hbaviewer/view.php source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/hba.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh`
> Expected output: **nothing**. Every excerpt below is quoted from `5941c97`
> (`dev` immediately after plan 018 merged). Any difference is a STOP condition.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: LOW-MEDIUM — mostly markup and CSS, plus one small JSON contract addition
- **Depends on**: 018 (DONE, merged as `5941c97`) — this plan moves elements 018 placed
- **Category**: UX
- **Planned at**: `5941c97`, 2026-07-30
- **Requested by**: maintainer, after eyeballing 018 on real hardware

## Why this matters

Plan 018 split the thermometer from the status badge, which was correct but left
both in awkward places: the temperature band landed in the meta list as a text row
(`Temp Band: ELEVATED`) rather than next to the gauge it describes, and the health
badge sits at the bottom of the meta list where it reads as just another field.

Four concrete complaints from using it on hardware:

1. The temperature band belongs **under the gauge**, not in the field list.
2. The Overview cards are too narrow: PCIe Width, PCIe Speed, Power Mode and PCI
   Location wrap onto two rows when they would fit on one in a wider card.
3. "Last read" floats in its own footer instead of sitting with the other fields,
   and the health pill should be prominent at the **top-left** of the card.
4. Timestamps are hardcoded 24-hour. They should follow the user's Unraid display
   settings.

Plus one defect 018 introduced that only shows up on screen: the meta row still
reads `Alert Threshold: 86 °C`. Under 018 that value is a **band selector**, not a
temperature, so the row now misleads — it reads as "warn me at 86" when it means
"start complaining once the Alert band is reached".

On the dashboard tile the health pill should be visible **always** — expanded or
collapsed — beside the gear icon, with the temperature pill next to it when the
tile is collapsed.

## Current state

Excerpts from `5941c97`.

### 1. Overview card — `ajax_info.php:235-256`

```php
        $out .= '<div class="lu-card first" style="--tc:' . $v['temp_stroke'] . ';--sc:' . $v['color'] . ';--pct:' . ($v['temp'] !== '' ? (int) $v['temp'] : 0) . '" data-ctl="' . $i . '">'
              . '<div class="lu-overview-row">'
              . '<div class="lu-circle" id="lu-circle-' . $i . '">'
              . '<span class="val" id="lu-val-' . $i . '">' . ($v['temp'] !== '' ? $v['temp'] : 'N/A') . '</span>'
              . '<span class="unit">' . ($v['temp'] !== '' ? '&deg;C' : 'no sensor') . '</span></div>'
              . '<div class="lu-meta">'
              . '<p>Model: <span>' . htmlspecialchars($v['model']) . '</span></p>'
              ...
              . '<p>Temp Band: ' . $tempChip . '</p>'
              . '<p>Alert Threshold: <span>' . $threshold . '&deg;C</span></p>'
              . '<span class="lu-badge" id="lu-badge-' . $i . '">' . $v['label'] . '</span>'
              . '</div></div>';
```

and the separate footer at `ajax_info.php:254`:

```php
        $out .= '<div class="lu-ts" id="lu-ts-' . $i . '">Last read: ' . date('H:i:s') . '</div></div>';
```

### 2. Overview CSS — `hbaviewer.php:80, 81, 104-106, 131`

```css
.lu-ov-grid .lu-card { flex: 1 1 360px; max-width: 640px; margin-bottom: 0; }
.lu-overview-row { display: flex; align-items: center; justify-content: flex-start; gap: 22px; }
.lu-pcie-row { display: flex; justify-content: center; gap: 24px; flex-wrap: wrap; }
.lu-pcie-item { font-size: 12.5px; color: var(--faint); }
.lu-ts     { font-size: 11px; color: var(--faint); font-family: var(--mono); text-align: right; margin-top: 12px; padding-top: 10px; border-top: 1px solid var(--border-soft); }
```

`max-width: 640px` is why the four PCIe fields wrap.

### 3. Dashboard tile body — `dashboard.php:177-186`

```php
      <div class='lu-d-overview'>
        <div class='lu-d-gauge'>
          <div class='lu-d-circle' style='--tc:{$tempCol};--pct:{$temp}'>
            <span class='v'>{$temp}</span>
            <span class='u'>°C</span>
          </div>
          <span class='lu-d-badge' style='--sc:{$col}'>{$badge}</span>
        </div>
```

### 4. Dashboard tile header — `dashboard.php:213-241`

The pill and the gear live in `tile-header-right-controls`; the health badge does
**not** appear here at all:

```php
        <span class="tile-header-right">
          <span class="tile-header-right-controls">
            {$pill}
            <a href="/Tools/HBAviewer_Monitor" title="Open HBAviewer">
              <i class="fa fa-fw fa-cog control"></i>
            </a>
          </span>
        </span>
```

`$pill` is the temperature pill, built at `dashboard.php:160-162`, and CSS shows it
only while collapsed (`dashboard.php:85`):

```css
.lu-d-tile:has(> tr:nth-child(2)[style*="display: none"]) .lu-d-pill { display:inline-flex; }
```

### 5. Timestamps — three hardcoded sites

```
dashboard.php:31   $ts = date('H:i:s');
ajax_info.php:254  ... date('H:i:s') ...
```

`dashboard.php:31` feeds both the tile meta row and the error tile.

### 6. The band table lives in shell — `parse/storcli_overview.sh` and `parse/hba.sh`

Both parsers already compute `CFG_BAND` (the band containing the configured
`ALERT` value) but only emit `temp_band`. Plan 018's maintenance note is explicit
that the band table must stay single-sourced in shell, so **PHP must not grow its
own temperature→band mapping**; the parser exposes what PHP needs instead.

### 7. Repo conventions

- `view.php` is the shared display module — "One home for status->color/label …
  Values are returned RAW; each consumer escapes for its own medium." A shared
  formatting helper belongs there, not duplicated in two renderers.
- Every hardware-sourced value is `htmlspecialchars`'d at the point of output
  (plan 007). Keep that posture.
- Deliberate simplifications carry a `ponytail:` comment naming the ceiling and
  the upgrade path.
- Goldens are re-blessed with `UPDATE=1 bash tests/run.sh` **only** for an
  intentional contract change. Step 2 is the one such change here.
- **No PHP test asserts Overview or tile markup** — verified with
  `grep -rn 'Temp Band\|Last read\|lu-badge\|Alert Threshold' tests/*.php`, which
  returns nothing. Layout moves will not break the suite; only Step 2 touches it.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Shell lint | `find source tests -name '*.sh' -print0 \| xargs -0 -r -n1 bash -n` | exit 0 |
| PHP lint | `find source tests -name '*.php' -print0 \| xargs -0 -r -n1 php -l` | exit 0 |
| Full suite | `bash tests/run.sh` | `--- all pass ---`, exit 0 |

`php` may be absent; `tests/run.sh` falls back to a `php:8.2-cli` Docker image.
For standalone `php -l` use the same image.

## Scope

**In scope**:

- `scripts/parse/storcli_overview.sh`, `scripts/parse/hba.sh` — emit `cfg_band`
- `view.php` — `lsi_time()`, and `cfg_band_label` in the view array
- `ajax_info.php` — Overview card re-layout
- `hbaviewer.php` — Overview CSS (card width, gauge column, card head, PCIe row)
- `dashboard.php` — tile body + header re-layout, and its CSS block
- `tests/expected/*` — re-blessed once for the `cfg_band` field

**Out of scope** (do NOT touch):

- **Anything to do with the HBA Health tab.** That is plan 020 — a new tab with
  sub-indicators, error *rates* and a history layer. Nothing here should
  anticipate it.
- **The band cut-points, colours, or the PHY floor.** Settled in 018 and verified
  on hardware. This plan moves elements; it does not re-tune them.
- **The Settings page.** Its copy was rewritten in 018 and is correct.
- **`lsi_status_color` / `lsi_temp_color` / `lsi_temp_stroke`.** Reuse them.
- **Adding a temperature→band map in PHP.** The parsers own the band table; Step 2
  is how PHP gets the answer.

## Git workflow

- Branch: `advisor/019-overview-and-tile-layout`, cut from `dev` (`5941c97`)
- Two or three commits; short imperative subjects, no conventional-commit prefix.
- Do NOT push or open a PR.

## Steps

### Step 1: A timestamp helper that follows Unraid's display settings

Add to `view.php`, after `lsi_band_label`:

```php
/* Timestamp in the user's configured format. Unraid stores the display
   preference in dynamix's config and also exposes $display to page scripts, so
   try the in-memory global first and fall back to reading the file. date()
   already renders in the system timezone — only the 12/24-hour choice needs
   resolving here, so a missing config degrades to the previous 24-hour output
   rather than guessing.
   ponytail: format string used as-is. Unraid writes PHP date() formats there; if
   a future release changes that, the guard below drops back to 24-hour. */
function lsi_time(?int $when = null): string {
    $when ??= time();
    $fmt = '';
    if (isset($GLOBALS['display']['time']) && is_string($GLOBALS['display']['time'])) {
        $fmt = trim($GLOBALS['display']['time']);
    }
    if ($fmt === '') {
        $cfg = @parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true);
        if (is_array($cfg) && isset($cfg['display']['time']) && is_string($cfg['display']['time'])) {
            $fmt = trim($cfg['display']['time']);
        }
    }
    // Only accept something that looks like a date() format: short, and made of
    // format characters. Anything else (a stray label, an empty quote) falls back.
    if ($fmt === '' || strlen($fmt) > 20 || !preg_match('/^[A-Za-z:\.\- ]+$/', $fmt)) {
        return date('H:i:s', $when);
    }
    return date($fmt, $when);
}
```

Then replace the three hardcoded sites:

- `dashboard.php:31` — `$ts = date('H:i:s');` → `$ts = lsi_time();`
- `ajax_info.php:254` — `date('H:i:s')` → `lsi_time()` (this line moves in Step 4;
  make the substitution wherever it ends up)

**Verify**: `grep -rn "date('H:i:s')" source/usr/local/emhttp/plugins/hbaviewer/` → prints **nothing**

**Verify**: the helper degrades safely when no config exists —

```bash
docker run --rm -v "<abs-worktree-path>:/w" -w /w php:8.2-cli php -r '
require "source/usr/local/emhttp/plugins/hbaviewer/view.php";
echo "no config  -> " . lsi_time(1750000000) . "\n";
$GLOBALS["display"]["time"] = "h:i:s a";
echo "12h config -> " . lsi_time(1750000000) . "\n";
$GLOBALS["display"]["time"] = "; rm -rf /";
echo "junk       -> " . lsi_time(1750000000) . "\n";'
```

**Verify**: the first and third lines print a 24-hour `HH:MM:SS`, and the second
prints a 12-hour time ending in `am`/`pm`. The junk case must **not** be passed to
`date()`.

### Step 2: Expose the configured band from the parsers

Both parsers already compute `CFG_BAND`. Emit it so PHP can label the row without
duplicating the band table.

In `scripts/parse/storcli_overview.sh`, extend the JSON line — keep every existing
field and its order, and add `cfg_band` immediately after `temp_band`:

```
"temp_band":"$TEMP_BAND","cfg_band":"$CFG_BAND","status":"$STATUS"
```

In `scripts/parse/hba.sh`, add to the heredoc immediately after the `temp_band`
line:

```
  "cfg_band": "${CFG_BAND}",
```

`hba.sh` computes `CFG_BAND` only inside the has-sensor branch. Initialise it
before that `if` so the no-sensor path still emits a value:

```bash
CFG_BAND=$(band_of "$ALERT")
```

and delete the now-duplicate assignment inside the branch.

**Verify**: both parsers emit it —

```bash
P=source/usr/local/emhttp/plugins/hbaviewer/scripts/parse
bash "$P/hba.sh" tests/fixtures/hba_ioc.txt tests/fixtures/hba_banner.txt tests/fixtures/hba_board.txt 86 | grep -o '"cfg_band": "[a-z]*"'
bash "$P/storcli_overview.sh" 86 0 < tests/fixtures/storcli/rollup_healthy.txt | grep -o '"cfg_band":"[a-z]*"'
```

→ both must print a band containing 86, i.e. `alert`.

**Verify**: the no-sensor path still emits the field —

```bash
bash "$P/hba.sh" tests/fixtures/hba_ioc_notemp.txt tests/fixtures/hba_banner.txt tests/fixtures/hba_board.txt 76 | grep -c cfg_band
```

→ `1`

Then re-bless once and inspect:

```bash
UPDATE=1 bash tests/run.sh
git diff -- tests/expected/
```

**Verify**: every changed line is a **new `cfg_band` field and nothing else**. Any
other field moving is a STOP condition.

### Step 3: Surface the band label in the view

In `view.php`, add to the array returned by `lsi_hba_view` (keep every existing key):

```php
        'cfg_band'       => $data['cfg_band'] ?? '',
        'cfg_band_label' => lsi_band_label($data['cfg_band'] ?? ''),
```

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/view.php` → clean

### Step 4: Re-lay the Overview card

Replace the block quoted in "Current state 1" so that:

- the card opens with a **head** holding the health badge (top-left),
- the gauge and the temperature band form a **column**,
- `Temp Band:` disappears from the meta list,
- the threshold row names the band instead of pretending to be a temperature,
- `Last read` becomes the meta row directly after it,
- the separate `.lu-ts` footer div is removed.

```php
        $out .= '<div class="lu-card first" style="--tc:' . $v['temp_stroke'] . ';--sc:' . $v['color'] . ';--pct:' . ($v['temp'] !== '' ? (int) $v['temp'] : 0) . '" data-ctl="' . $i . '">'
              . '<div class="lu-card-head">'
              . '<span class="lu-badge" id="lu-badge-' . $i . '">' . $v['label'] . '</span>'
              . '</div>'
              . '<div class="lu-overview-row">'
              . '<div class="lu-gauge">'
              . '<div class="lu-circle" id="lu-circle-' . $i . '">'
              . '<span class="val" id="lu-val-' . $i . '">' . ($v['temp'] !== '' ? $v['temp'] : 'N/A') . '</span>'
              . '<span class="unit">' . ($v['temp'] !== '' ? '&deg;C' : 'no sensor') . '</span></div>'
              . '<span class="lu-temp-band">' . $tempChip . '</span>'
              . '</div>'
              . '<div class="lu-meta">'
```

Keep every existing meta `<p>` for Model / Chip / Firmware / BIOS / Driver / Mode /
Drives / lsiutil Port exactly as they are. Then replace the tail of the meta list —
the `Temp Band`, `Alert Threshold` and trailing `<span class="lu-badge">` — with:

```php
              . '<p>Badge at: <span>' . htmlspecialchars($v['cfg_band_label']) . ' (' . $threshold . '&deg;C+)</span></p>'
              . '<p>Last read: <span>' . lsi_time() . '</span></p>'
              . '</div></div>';
```

and delete the old `.lu-ts` footer line entirely, keeping the `</div>` that closed
the card:

```php
        $out .= '</div>';
```

Read the surrounding code before cutting — the `.lu-ts` line closes the card div
in the same statement, so the card must still be closed after the PCIe row block.

**Verify**: `grep -c 'lu-ts' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` → `0`

**Verify**: `grep -c 'Temp Band:' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` → `0`

**Verify**: `php -l` clean.

### Step 5: Overview CSS

In `hbaviewer.php`, replace the rules quoted in "Current state 2":

```css
/* Wider cards: four PCIe fields (Width, Speed, Power Mode, PCI Location) fit on
   one row at this width and wrapped at the old 640px. flex-basis rises with it so
   two cards still sit side by side on a wide screen, and flex-wrap keeps the
   narrow case working. */
.lu-ov-grid .lu-card { flex: 1 1 520px; max-width: 820px; margin-bottom: 0; }
.lu-overview-row { display: flex; align-items: center; justify-content: flex-start; gap: 22px; }
/* Gauge and its band label read as one unit — the band describes the number
   above it, which is not what a row buried in the field list conveyed. */
.lu-gauge { display: flex; flex-direction: column; align-items: center; gap: 8px; flex: 0 0 auto; }
.lu-temp-band { font-size: 11px; font-weight: 700; letter-spacing: 0.08em; font-family: var(--mono); }
/* Health pill sits top-left, above the gauge row, so the card's own state is the
   first thing read rather than the last field in a list. */
.lu-card-head { display: flex; align-items: center; justify-content: flex-start; margin-bottom: 12px; }
.lu-card-head .lu-badge { margin-top: 0; }
.lu-pcie-row { display: flex; justify-content: space-between; gap: 24px; flex-wrap: wrap; }
.lu-pcie-item { font-size: 12.5px; color: var(--faint); white-space: nowrap; }
```

Delete the `.lu-ts` rule — nothing references that class any more.

**Verify**: `grep -c 'lu-ts' source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php` → `0`

**Verify**: `grep -c 'lu-gauge\|lu-card-head\|lu-temp-band' source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php` → `4`

### Step 6: Re-lay the dashboard tile

**6a — band under the gauge, badge out of the body.** Replace the block quoted in
"Current state 3" with:

```php
      <div class='lu-d-overview'>
        <div class='lu-d-gauge'>
          <div class='lu-d-circle' style='--tc:{$tempCol};--pct:{$temp}'>
            <span class='v'>{$temp}</span>
            <span class='u'>°C</span>
          </div>
          <span class='lu-d-temp-band'>{$tempChip}</span>
        </div>
```

and remove the `Temp Band` meta row from the tile's field list, replacing the pair
of rows with:

```php
          . "<p>Badge at: <span>{$cfgBandLabel} ({$threshold}°C+)</span></p>
          <p>Last read: <span>{$ts}</span></p>
```

Define `$cfgBandLabel` next to the other escaped locals near `dashboard.php:145`:

```php
    $cfgBandLabel = htmlspecialchars($v['cfg_band_label'] ?? '');
```

**6b — the health pill moves to the header, permanently.** Build it alongside the
temperature pill at `dashboard.php:160`:

```php
    // Health pill lives in the tile header beside the gear, visible whether the
    // tile is expanded or collapsed — it is the one thing worth seeing without
    // opening the tile. The temperature pill sits to its left and, as before,
    // only appears while collapsed (expanded, the gauge shows the same number
    // far larger).
    $t['health'] = '<span class="lu-d-health" style="--sc:' . $col . '">' . $badge . '</span>';
```

Add `'health' => ''` to **both** tile initialisers (the error tile at
`dashboard.php:100-110` and the per-controller one at `dashboard.php:113-121`), and
for the error tile set it to the alert colour with the label `UNREADABLE` so a
failed card still shows state.

In the emission loop, pull it out alongside the others:

```php
    $pill = $t['pill']; $health = $t['health']; $foot = $t['foot']; $body = $t['body'];
```

and place it between the temperature pill and the gear:

```php
          <span class="tile-header-right-controls">
            {$pill}
            {$health}
            <a href="/Tools/HBAviewer_Monitor" title="Open HBAviewer">
              <i class="fa fa-fw fa-cog control"></i>
            </a>
          </span>
```

**6c — CSS.** In the tile's `<style>` block, add the two new rules. `.lu-d-health`
mirrors `.lu-d-badge` but is **always** displayed:

```css
.lu-d-tile .lu-d-health {
  display:inline-flex; align-items:center; gap:6px;
  padding:3px 11px; border-radius:20px;
  font-size:10px; font-weight:700; letter-spacing:0.05em;
  color:var(--sc,#2ecc71); background:color-mix(in srgb, var(--sc,#2ecc71) 16%, transparent);
  box-shadow:0 0 8px color-mix(in srgb, var(--sc,#2ecc71) 30%, transparent);
}
.lu-d-tile .lu-d-health::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; }
.lu-d-tile .lu-d-temp-band { font-size:10px; font-weight:700; letter-spacing:0.06em; font-family:ui-monospace,"SF Mono",Menlo,monospace; }
```

The body's `.lu-d-badge` is now unused — delete its rule and its `::before`, and
confirm nothing still emits that class.

**Verify**: `grep -c 'lu-d-badge' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → `0`

**Verify**: `grep -c 'lu-d-health' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → `4`
(two CSS rules, the builder, the emission)

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → clean

### Step 7: Lint and full suite

**Verify**: `find source tests -name '*.sh' -print0 | xargs -0 -r -n1 bash -n` → exit 0

**Verify**: `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` → exit 0

**Verify**: `bash tests/run.sh` → `--- all pass ---`, exit 0, with **no golden diff
beyond Step 2's** — `git diff -- tests/expected/` must show only `cfg_band`
additions.

## Test plan

- **The suite cannot see layout.** Every change here except Step 2 is markup and
  CSS, and no PHP test asserts either (verified — see "Repo conventions"). The
  regression net is the existing suite staying green plus the `php -l` gate.
- **Step 1's helper is the one piece of real logic** and gets a direct check
  covering all three paths: no config, a 12-hour config, and a junk value that
  must not reach `date()`.
- **Step 2 is the only contract change** and is covered by the existing goldens
  once re-blessed, plus the two direct parser checks and the no-sensor case.
- **Everything visual is verified by the maintainer on hardware** — see below. Do
  not claim the layout is correct; claim only that it lints, renders without a PHP
  error, and the suite is green.

## Done criteria

- [ ] `grep -rn "date('H:i:s')" source/usr/local/emhttp/plugins/hbaviewer/` prints nothing
- [ ] Step 1's three-case check printed 24h / 12h / 24h as described
- [ ] Both parsers print `cfg_band` = `alert` for an `ALERT` of 86
- [ ] The no-sensor fixture still emits `cfg_band`
- [ ] `git diff -- tests/expected/` shows **only** `cfg_band` additions
- [ ] `grep -c 'lu-ts' ajax_info.php` → `0`, and `grep -c 'lu-ts' hbaviewer.php` → `0`
- [ ] `grep -c 'Temp Band:' ajax_info.php` → `0`
- [ ] `grep -c 'lu-gauge\|lu-card-head\|lu-temp-band' hbaviewer.php` → `4`
- [ ] `grep -c 'lu-d-badge' dashboard.php` → `0`; `grep -c 'lu-d-health' dashboard.php` → `4`
- [ ] Both lints exit 0; `bash tests/run.sh` exits 0 with `--- all pass ---`
- [ ] `git status --porcelain` lists only the six in-scope source files plus `tests/expected/*`

## STOP conditions

- The drift check prints anything.
- A re-blessed golden changes a field that is not `cfg_band`.
- You find yourself adding a temperature→band mapping in PHP. Step 2 exists so
  that the band table stays single-sourced in shell; a PHP copy would be a third
  place to keep in step.
- You find yourself building any part of the HBA Health tab, sub-indicators, error
  *rates*, or a history/persistence layer. That is plan 020 and it is not started.
- Removing `.lu-d-badge` breaks the tile because something still emits it — find
  the emitter rather than restoring the rule.
- `lsi_time()` passes an unvalidated config string to `date()`. The guard is not
  optional; a config value is user-controlled input to a formatter.

## Hardware verification (maintainer, not the executor)

The suite cannot judge any of this. On a real box, with the branch patched in:

1. **Overview** — health pill top-left of each card; band label under the gauge;
   PCIe Width / Speed / Power Mode / PCI Location all on **one** row; `Badge at:`
   and `Last read` as the last two meta rows; no footer timestamp.
2. **Dashboard tile, expanded** — health pill beside the gear; band under the
   gauge; no badge in the body.
3. **Dashboard tile, collapsed** — temperature pill *and* health pill both in the
   header, temperature to the left of health.
4. **Time format** — with Unraid's Display Settings set to 12-hour, both the
   Overview and the tile show 12-hour times.

## Maintenance notes

- **`--tc` is temperature, `--sc` is status.** Set in three files
  (`dashboard.php`, `ajax_info.php`, `hbaviewer.php`) — plan 018's review found
  the hard way that the Monitor page's CSS lives in `hbaviewer.php` while its
  markup is built in `ajax_info.php`. Any change to what these mean must grep all
  three.
- **The band table stays in shell.** PHP receives `temp_band` and `cfg_band` and
  only maps them to colour and label. Resist adding a PHP copy.
- **`alert_threshold` in the JSON is a band floor, not a temperature.** The
  Overview now says `Badge at: Alert (86 °C+)` rather than `Alert Threshold:
  86 °C`, which is the honest phrasing of what 018 made it mean.
- **`lsi_time()` reads user config.** It is guarded because a format string from
  a config file is untrusted input to `date()`. Keep the guard if the lookup is
  ever extended to the date portion as well.
- **What a reviewer should scrutinise**: that no PHP band map crept in, that the
  golden diff is `cfg_band` only, and that the error tile still shows a health
  pill (a card that cannot be read must not render as healthy).
