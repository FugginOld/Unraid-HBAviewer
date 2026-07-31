# Plan 018: Five fixed temperature bands, and stop the rollup colouring the thermometer

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat d0d10fb..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/hba.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh source/usr/local/emhttp/plugins/hbaviewer/view.php source/usr/local/emhttp/plugins/hbaviewer/dashboard.php source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php source/usr/local/emhttp/plugins/hbaviewer/settings.php source/usr/local/emhttp/plugins/hbaviewer/config.php tests/run.sh`
> Expected output: **nothing**. Every excerpt below is quoted from `d0d10fb`.
> Any difference is a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MEDIUM — touches both parsers, both renderers, the config schema, and re-blesses goldens
- **Depends on**: none (independent of 017, which is still gathering evidence)
- **Category**: bug / UX
- **Planned at**: `d0d10fb`, 2026-07-30
- **Issue**: [#8](https://github.com/FugginOld/Unraid-HBAviewer/issues/8) — false-positive
  temperature warning, plus the maintainer's five-band redesign

## Why this matters

Issue #8 reports a "warning" temperature at 51 °C on a card rated to 55 °C. The
temperature was never the problem. Two defects combine:

**1. The thermometer is coloured by the whole-controller rollup.** `dashboard.php`
sets `--tc` from `lsi_status_color($status)`, and `$status` is the *worst* of
temperature, drive states, and PHY error counters. So a link-layer counter paints
the temperature amber and the user reasonably reads it as "the plugin thinks
51 °C is too hot".

**2. `phyerr > 0` is far too sensitive.** Any non-zero PHY error counter promotes
the rollup to `warn`. Those counters are cumulative since boot and never reset, so
one transient — a cable reseat, a hot-swap, link training — pins a card to amber
permanently.

Both are confirmed on the maintainer's own hardware. `phy-0:5` reports
`inv=5 disp=2 sync=1`, summing to 8, and the parser was driven with that value:

```text
temp     | phyerr=0   | phyerr=8 (real)
---------+------------+----------------
30C      | ok  GREEN  | warn  AMBER
51C      | ok  GREEN  | warn  AMBER
69C      | ok  GREEN  | warn  AMBER
```

Eight accumulated errors on one phy out of 21 mean that card can never show green
at any temperature. The bands themselves were verified correct at every boundary,
in both backends — the temperature logic was never broken.

Separately, the maintainer has specified a **five-band scale** to replace the
current three, with absolute cut-points rather than a threshold ± 10 °C.

## The design, as decided

### The bands (absolute, not derived from any setting)

| Band | Range | Colour | Treatment |
|---|---|---|---|
| `normal` | ≤ 65 °C | `#2ecc71` | existing green |
| `elevated` | 66–75 °C | `#f1c40f` | new step |
| `warning` | 76–85 °C | `#e67e22` | orange |
| `alert` | 86–95 °C | `#e74c3c` | existing red |
| `critical` | ≥ 96 °C | `#922b21` | **inverted** — white text on a solid fill |

Colours were measured against the plugin's real card surfaces (`#232323`,
`#1c1c1c`, `#2a2a2a`) with a contrast validator, not chosen by eye. All four
foreground steps clear 3:1 on all three surfaces; the worst is `alert` at 3.76:1
on `#2a2a2a`.

**Critical is not a fifth hue, and this is deliberate.** On a dark card there is no
red both darker than `alert` and still legible — `#c0392b` measures 2.89:1 and
`#922b21` only 1.94:1 as a foreground. `#922b21` is used as a *fill* behind white
text (8.11:1) instead. Where a stroke is unavoidable (the gauge arc), `critical`
uses `#ff5252` (4.93:1). **Do not "fix" this by making Critical a darker red** — it
will fail contrast and the reason is recorded here.

### The setting, repurposed

`ALERT_THRESHOLD` keeps its key, its type, and its clamp. It no longer sets a
temperature — it names **the first band at which the badge starts complaining**.
Stored as that band's floor:

| Stored value | Badge starts complaining at |
|---|---|
| `66` | Elevated |
| `76` | Warning *(new default)* |
| `86` | Alert |
| `96` | Critical |

**Legacy values keep working with no migration**, which is why the key stays an
int: any stored number maps to whichever band contains it. An existing `80` falls
inside the Warning band (76–85), so that install behaves as "complain from
Warning" — the closest match to its old behaviour.

### Badge rank from the temperature band

Compare band index against the configured band index:

- below it → `ok`
- equal → `warn`
- above it → `alert`

At the new default (Warning): ≤75 `ok`, 76–85 `warn`, ≥86 `alert`.

### Two colours, two jobs

| Element | Coloured by | Source |
|---|---|---|
| Gauge arc, glow, temperature pill | the **temperature band** | new `temp_color` |
| Status badge (NORMAL/WARNING/ALERT) | the **rollup** (temp + drives + PHY) | existing `color` |

This separation is the actual fix for #8. The badge may legitimately read WARNING
because of PHY errors while the thermometer stays green — which is the truth, and
what the current code cannot express.

## Current state

Excerpts from `d0d10fb`.

### 1. `scripts/parse/hba.sh:86-94` — lsiutil rank

```bash
# ── 4. Status (temp-based when a sensor exists; otherwise ok — no false alarm) ─
if [ -n "$TEMP" ]; then
    if   [ "$TEMP" -ge "$ALERT" ];          then STATUS="alert"
    elif [ "$TEMP" -ge $(( ALERT - 10 )) ]; then STATUS="warn"
    else STATUS="ok"; fi
    TEMPJSON="$TEMP"
else
    STATUS="ok"; TEMPJSON='""'
fi
```

### 2. `scripts/parse/storcli_overview.sh:62-73` — storcli rank

```bash
# ── Health rollup: worst of temperature, drive states, and PHY errors ────────
# 0=ok 1=warn 2=alert
if   [ "$TEMP" -ge "$ALERT" ];          then RANK=2
elif [ "$TEMP" -ge $(( ALERT - 10 )) ]; then RANK=1
else RANK=0; fi
```

and, further down, the over-sensitive rule:

```bash
# Any PHY error counter on this controller is an early warning.
if [ "${PHYERR:-0}" -gt 0 ] && [ "$RANK" -lt 1 ]; then RANK=1; fi
```

### 3. `view.php:8-13` and `view.php:41-45`

```php
function lsi_status_color(string $s): string {
    return match ($s) { 'alert' => '#e74c3c', 'warn' => '#f39c12', default => '#2ecc71' };
}
function lsi_status_label(string $s): string {
    return match ($s) { 'alert' => 'ALERT', 'warn' => 'WARNING', default => 'NORMAL' };
}
```

```php
        'temp'       => $data['temp'] ?? '',
        'status'     => $status,
        'color'      => lsi_status_color($status),
        'label'      => lsi_status_label($status),
```

### 4. `dashboard.php` — one variable doing two jobs

`--tc` drives the gauge (line 44), its glow (46), the **badge** (58-59) and the
temperature pill (68-70):

```css
  background:conic-gradient(var(--tc,#2ecc71) calc(var(--pct,0)*1%), #2a2a2a 0);
  filter:drop-shadow(0 0 8px color-mix(in srgb, var(--tc,#2ecc71) 30%, transparent));
```

```css
  color:var(--tc,#2ecc71); background:color-mix(in srgb, var(--tc,#2ecc71) 16%, transparent);
  box-shadow:0 0 8px color-mix(in srgb, var(--tc,#2ecc71) 30%, transparent);
```

### 5. `ajax_info.php:229` — the monitor card

```php
$out .= '<div class="lu-card first" style="--tc:' . $v['color'] . ';--pct:' . ($v['temp'] !== '' ? (int) $v['temp'] : 0) . '" data-ctl="' . $i . '">'
```

### 6. `config.php:10-12` — the schema

```php
const LSI_SCHEMA = [
    'HBA_PORT'        => [1,  1, 8],
    'ALERT_THRESHOLD' => [80, 1, 150],
```

### 7. `settings.php:157-163` — the control

```php
          Alert Threshold (°C)
          <small>The Overview badge and dashboard tile turn red at or above this temperature, and amber within 10 °C of it. HBAviewer does not send notifications.</small>
        </div>
        <div class="lu-s-control">
          <input type="number" name="threshold" value="<?= (int)$cfg['ALERT_THRESHOLD'] ?>" min="1" max="150">
```

### 8. Repo conventions

- Parsers are **pure stdin/file filters** — they do not source `lib.sh`. The band
  table is therefore duplicated in the two parsers by necessity; keep them
  byte-identical and cross-reference in comments.
- Error payloads are `{"error":"..."}`; PHP renders `$data['error']`.
- `view.php` is the single home for status→colour/label. Renderers keep their own
  markup and never re-derive.
- Deliberate simplifications carry a `ponytail:` comment naming the ceiling and
  the upgrade path (`get_metrics.sh:16-19`, `lib.sh:78-79`).
- Goldens are regenerated with `UPDATE=1 bash tests/run.sh` **only** for an
  intentional contract change — which this plan is. Every re-blessed file must be
  inspected in the diff.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Shell lint | `find source tests -name '*.sh' -print0 \| xargs -0 -r -n1 bash -n` | exit 0 |
| PHP lint | `find source tests -name '*.php' -print0 \| xargs -0 -r -n1 php -l` | exit 0 |
| Full suite | `bash tests/run.sh` | `--- all pass ---`, exit 0 |

`php` may be absent; `tests/run.sh` falls back to a `php:8.2-cli` Docker image.

## Scope

**In scope**:

- `scripts/parse/hba.sh` — bands, `temp_band`, rank from bands
- `scripts/parse/storcli_overview.sh` — same, plus the PHY floor
- `view.php` — `lsi_temp_color()`, `lsi_band_label()`, `temp_color` in the view array
- `dashboard.php` — split `--tc` (temperature) from `--sc` (status), Critical chip
- `ajax_info.php` — same split on the monitor card
- `hbaviewer.php` — **the Monitor page's CSS lives here, not in `ajax_info.php`.**
  `.lu-badge` reads `var(--tc, var(--good))`, which *was* the status colour before
  this plan and becomes the temperature colour after it. Both occurrences on that
  rule must move to `--sc`, mirroring `dashboard.php`'s `.lu-d-badge`. Leave
  `.lu-circle` on `--tc` — the gauge arc and glow are temperature, correctly.
  (Missed in the first draft of this plan; the executor caught it and stopped
  rather than editing out of scope, which is the behaviour to reward.)
  **Verify with `grep -o`, not `grep -c`** — both variables sit on a single CSS
  line, so `grep -c` reports `1` and looks like a failure:
  `grep -o -- '--sc' .../hbaviewer.php | wc -l` → `2`, and
  `grep -o -- '--tc' .../hbaviewer.php | wc -l` → `2` (the two `.lu-circle` uses).
- `settings.php` — the control becomes a band selector; help text rewritten
- `config.php` — default `80` → `76`, comment explaining the repurposed meaning
- `tests/run.sh` + `tests/expected/*` — boundary goldens, re-blessed existing goldens

**Out of scope**:

- **The drive-state scrape** (`^[0-9]+:[0-9]+`). It is broken for enclosure-less
  controllers and that belongs to plan 017 — same root cause as the empty Drives
  tab. Do not fix it here; the two plans would collide in the same file.
- **`status_reason`** (telling the user *why* the badge is amber). Genuinely
  useful, genuinely a separate change — new field plus two renderers.
- **Notifications.** HBAviewer does not send them; plan 001 removed that claim.
- **The `alert_threshold` JSON field name.** It keeps its name even though its
  meaning shifts, so no consumer breaks. Rename is not worth a contract break.

## Git workflow

- Branch: `advisor/018-temperature-bands`, cut from `dev`
- Two commits is natural: one for the shell/contract change, one for PHP/UI.
  Short imperative subjects, no conventional-commit prefix.
- Do NOT push or open a PR unless instructed.

## Steps

### Step 1: Add the band table to both parsers

The band table must be **identical** in both. In
`scripts/parse/storcli_overview.sh`, replace the rank block quoted in "Current
state 2" with:

```bash
# ── Temperature band (absolute, NOT derived from the setting) ────────────────
# Five fixed bands. ALERT no longer means "the temperature that is bad"; it names
# the first band at which the badge complains (see hba_temp_band's twin in
# parse/hba.sh — keep both copies identical). Cut-points are the card-independent
# ones the maintainer specified; per-card limits are not worth a config knob.
#   normal <=65 | elevated 66-75 | warning 76-85 | alert 86-95 | critical >=96
band_of() {   # $1 = temperature in C -> band name
    if   [ "$1" -le 65 ]; then echo normal
    elif [ "$1" -le 75 ]; then echo elevated
    elif [ "$1" -le 85 ]; then echo warning
    elif [ "$1" -le 95 ]; then echo alert
    else echo critical; fi
}
band_index() { case "$1" in normal) echo 0;; elevated) echo 1;; warning) echo 2;; alert) echo 3;; *) echo 4;; esac; }

TEMP_BAND=$(band_of "$TEMP")
# The configured band = whichever band contains the stored ALERT value. Storing a
# band floor (66/76/86/96) is the normal case; any legacy value (e.g. 80) still
# lands in a sensible band, so no config migration is needed.
CFG_BAND=$(band_of "$ALERT")

# Badge rank: below the configured band = ok, at it = warn, above it = alert.
ti=$(band_index "$TEMP_BAND"); ci=$(band_index "$CFG_BAND")
if   [ "$ti" -gt "$ci" ]; then RANK=2
elif [ "$ti" -eq "$ci" ]; then RANK=1
else RANK=0; fi
```

**Verify**: `bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh` → exit 0

### Step 2: Give the PHY rollup a floor

In the same file, replace the PHY rule quoted in "Current state 2" with:

```bash
# PHY error counters are CUMULATIVE SINCE BOOT and never reset, so ">0" flagged
# every card that had ever seen a transient — a cable reseat months ago pinned a
# healthy controller to amber forever (issue #8: 8 errors on one phy out of 21).
# A failing link produces counts in the thousands to millions, so a floor
# separates the two cases cheaply.
# ponytail: static floor. The honest signal is the RATE of change, which needs
# per-read history we don't keep; add that if a real fault ever slips under 100.
PHYERR_FLOOR=100
if [ "${PHYERR:-0}" -ge "$PHYERR_FLOOR" ] && [ "$RANK" -lt 1 ]; then RANK=1; fi
```

**Verify**: `grep -c 'PHYERR_FLOOR' source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh` → `2`

**Verify**: the reported case is now green —

```bash
sed 's/^ROC temperature(Degree Celsius).*/ROC temperature(Degree Celsius) 51/' \
  tests/fixtures/storcli/rollup_healthy.txt \
  | bash source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh 76 8
```

→ must contain `"status":"ok"` and `"temp_band":"normal"` (after Step 3 adds the field).

### Step 3: Emit `temp_band` from both parsers

In `storcli_overview.sh`, add the field to the JSON (keep every existing field and
its order; append before `"status"`):

```
"alert_threshold":$ALERT,"temp_band":"$TEMP_BAND","status":"$STATUS"
```

In `scripts/parse/hba.sh`, replace the status block from "Current state 1" with the
same band logic — **copy `band_of` and `band_index` verbatim from Step 1** — and
handle the no-sensor case:

```bash
if [ -n "$TEMP" ]; then
    TEMP_BAND=$(band_of "$TEMP")
    CFG_BAND=$(band_of "$ALERT")
    ti=$(band_index "$TEMP_BAND"); ci=$(band_index "$CFG_BAND")
    if   [ "$ti" -gt "$ci" ]; then STATUS="alert"
    elif [ "$ti" -eq "$ci" ]; then STATUS="warn"
    else STATUS="ok"; fi
    TEMPJSON="$TEMP"
else
    # No sensor (many SAS2008/9211 cards): no band, and never a false alarm.
    STATUS="ok"; TEMPJSON='""'; TEMP_BAND=""
fi
```

and add `"temp_band": "${TEMP_BAND}",` to its JSON heredoc, immediately before
`"status"`.

**Verify**: both parsers emit the field —

```bash
bash source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/hba.sh \
  tests/fixtures/hba_ioc.txt tests/fixtures/hba_banner.txt tests/fixtures/hba_board.txt 76 | grep -c temp_band
```

→ `1`

### Step 4: Prove every boundary, in both backends

This is the check that matters most — the cut-points are the whole feature. Run:

```bash
P=source/usr/local/emhttp/plugins/hbaviewer/scripts/parse
for t in 65 66 75 76 85 86 95 96; do
  s=$(sed "s/^ROC temperature(Degree Celsius).*/ROC temperature(Degree Celsius) $t/" \
        tests/fixtures/storcli/rollup_healthy.txt | bash "$P/storcli_overview.sh" 76 0 \
      | sed -n 's/.*"temp_band":"\([a-z]*\)".*/\1/p')
  printf '%3sC -> %s\n' "$t" "$s"
done
```

**Verify**: prints exactly

```
 65C -> normal
 66C -> elevated
 75C -> elevated
 76C -> warning
 85C -> warning
 86C -> alert
 95C -> alert
 96C -> critical
```

An off-by-one anywhere here is a STOP condition — re-read Step 1 rather than
adjusting the expectation.

### Step 5: Colour and label the bands in `view.php`

Add next to `lsi_status_color`:

```php
/* Temperature band -> colour. SEPARATE from lsi_status_color on purpose: the
   thermometer shows heat, the badge shows the whole-controller rollup (which also
   reflects drive and PHY problems). Conflating them is what made issue #8 read as
   a false temperature warning. Hexes are contrast-measured against the plugin's
   own card surfaces (#232323 / #1c1c1c / #2a2a2a); all clear 3:1.
   'critical' is a FILL behind white text, not a foreground — #922b21 measures
   1.94:1 as a stroke on a dark card and is unreadable. Do not "promote" it. */
function lsi_temp_color(string $band): string {
    return match ($band) {
        'critical' => '#922b21',
        'alert'    => '#e74c3c',
        'warning'  => '#e67e22',
        'elevated' => '#f1c40f',
        default    => '#2ecc71',
    };
}
/* Where a band must be drawn as a stroke or glow rather than a fill, critical
   needs a lighter red to stay legible (4.93:1 vs 1.94:1). */
function lsi_temp_stroke(string $band): string {
    return $band === 'critical' ? '#ff5252' : lsi_temp_color($band);
}
function lsi_band_label(string $band): string {
    return match ($band) {
        'critical' => 'CRITICAL', 'alert' => 'ALERT', 'warning' => 'WARNING',
        'elevated' => 'ELEVATED', default => 'NORMAL',
    };
}
```

and extend the returned array in `lsi_hba_view` (keep every existing key):

```php
        'temp_band'   => $data['temp_band'] ?? '',
        'temp_color'  => lsi_temp_color($data['temp_band'] ?? ''),
        'temp_stroke' => lsi_temp_stroke($data['temp_band'] ?? ''),
        'temp_label'  => lsi_band_label($data['temp_band'] ?? ''),
```

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/view.php` → no syntax errors

**Verify**: `grep -c 'lsi_temp_color\|lsi_temp_stroke\|lsi_band_label' source/usr/local/emhttp/plugins/hbaviewer/view.php` → `7`
(three definitions + four uses)

### Step 6: Split the two colours in the renderers

In `dashboard.php`, the gauge, glow and pill keep `--tc` — now fed from
`temp_stroke` — and the **badge** moves to a new `--sc`:

```css
  color:var(--sc,#2ecc71); background:color-mix(in srgb, var(--sc,#2ecc71) 16%, transparent);
  box-shadow:0 0 8px color-mix(in srgb, var(--sc,#2ecc71) 30%, transparent);
```

Set both variables wherever the tile's inline style is built, e.g.
`--tc:<temp_stroke>;--sc:<status color>`. The two error tiles that currently pass
`lsi_status_color('alert')` set **both** to that colour — an unreadable card is a
status problem, not a heat reading.

Apply the same split at `ajax_info.php:229`: `--tc` from `temp_stroke`, `--sc`
from `color`.

**Verify**: `grep -c '\-\-sc' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → at least `3`

**Verify**: `php -l` clean on both files.

### Step 7: The Critical chip

Where the temperature band is displayed as a label (the tile badge area and the
monitor card), `critical` renders as an inverted chip rather than coloured text:

```php
$isCrit = ($v['temp_band'] ?? '') === 'critical';
// white on the dark-red fill measures 8.11:1; the same red as text measures 1.94:1
$tempChip = $isCrit
    ? '<span style="background:' . lsi_temp_color('critical') . ';color:#fff;padding:2px 7px;border-radius:2px;font-weight:700">CRITICAL</span>'
    : '<span style="color:' . $v['temp_stroke'] . '">' . htmlspecialchars($v['temp_label']) . '</span>';
```

Use the repo's existing escaping posture — every hardware-sourced value stays
`htmlspecialchars`'d (plan 007).

**Verify**: `php -l` clean.

### Step 8: Repurpose the setting

In `config.php`, change the default and document the new meaning:

```php
    // Not a temperature any more: the FIRST BAND at which the badge complains,
    // stored as that band's floor (66 elevated / 76 warning / 86 alert / 96
    // critical). Kept as an int with the old key and clamp so existing configs
    // need no migration — any legacy value maps to whichever band contains it.
    'ALERT_THRESHOLD' => [76, 1, 150],
```

In `settings.php`, replace the number input and its help text with a band selector:

```php
          Badge Sensitivity
          <small>Temperature colours are fixed (Normal &le;65, Elevated 66&ndash;75, Warning 76&ndash;85, Alert 86&ndash;95, Critical &gt;95 &deg;C). This chooses the first band at which the Overview badge and dashboard tile start reporting a problem. HBAviewer does not send notifications.</small>
        </div>
        <div class="lu-s-control">
          <select name="threshold">
<?php
$bands = [66 => 'Elevated (66 °C and above)', 76 => 'Warning (76 °C and above)',
          86 => 'Alert (86 °C and above)',    96 => 'Critical (above 95 °C)'];
// Select the band containing the stored value, so a legacy 80 shows "Warning".
$curr = (int) $cfg['ALERT_THRESHOLD'];
$sel  = 96; foreach (array_keys($bands) as $floor) { if ($curr < $floor) { break; } $sel = $floor; }
if ($curr < 66) $sel = 66;
foreach ($bands as $floor => $label) {
    printf('<option value="%d"%s>%s</option>', $floor, $floor === $sel ? ' selected' : '', htmlspecialchars($label));
}
?>
          </select>
```

**Verify**: `grep -c 'Alert Threshold' source/usr/local/emhttp/plugins/hbaviewer/settings.php` → `0`

**Verify**: the legacy mapping is right —

```bash
php -r '
$bands=[66,76,86,96];
foreach ([50,66,70,80,86,90,96,120] as $curr) {
  $sel=96; foreach ($bands as $f) { if ($curr < $f) break; $sel=$f; }
  if ($curr < 66) $sel=66;
  echo "stored $curr -> band floor $sel\n";
}'
```

→ must print `50 -> 66`, `66 -> 66`, `70 -> 66`, `80 -> 76`, `86 -> 86`,
`90 -> 86`, `96 -> 96`, `120 -> 96`.

### Step 9: Boundary goldens

Add to `tests/run.sh`, after the existing `rollup-*` checks:

```bash
# Band cut-points are the whole feature of plan 018 — one golden per boundary, so
# an off-by-one in either direction fails loudly. 76 = "complain from Warning".
for t in 65 66 75 76 85 86 95 96; do
    check "band-$t" "band_$t.json" bash -c \
      "sed 's/^ROC temperature(Degree Celsius).*/ROC temperature(Degree Celsius) $t/' fixtures/storcli/rollup_healthy.txt | bash '$P/storcli_overview.sh' 76 0"
done
# PHY floor: 8 errors (the real-world case from issue #8) must NOT warn; 100 must.
check phy-under-floor phy_under_floor.json bash -c "bash '$P/storcli_overview.sh' 76 8   < fixtures/storcli/rollup_healthy.txt"
check phy-over-floor  phy_over_floor.json  bash -c "bash '$P/storcli_overview.sh' 76 100 < fixtures/storcli/rollup_healthy.txt"
```

Generate the expected files with `UPDATE=1 bash tests/run.sh`, then **read every
one** and confirm the `temp_band` matches Step 4's table and that
`phy_under_floor.json` says `"status":"ok"`.

**Verify**: `ls tests/expected/band_*.json | wc -l` → `8`

**Verify**: `grep -l '"status":"warn"' tests/expected/phy_over_floor.json` prints the file,
and `grep -c '"status":"ok"' tests/expected/phy_under_floor.json` → `1`

### Step 10: Re-bless the existing goldens, and inspect the diff

Adding `temp_band` changes every overview golden. This is the one intentional
re-bless in this plan:

```bash
UPDATE=1 bash tests/run.sh
git diff -- tests/expected/
```

**Verify**: every changed line in that diff is *either* a new `temp_band` field
*or* a `status` value that moved because the bands changed. Any other field
changing is a STOP condition.

**Verify**: `bash tests/run.sh` → `--- all pass ---`, exit 0.

### Step 11: Lint and full suite

**Verify**: `find source tests -name '*.sh' -print0 | xargs -0 -r -n1 bash -n` → exit 0

**Verify**: `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` → exit 0

**Verify**: `bash tests/run.sh` → `--- all pass ---`, exit 0

## Test plan

- **Eight boundary goldens** are the core: 65/66, 75/76, 85/86, 95/96. They fail on
  any off-by-one, which is the only realistic way to get this feature wrong.
- **Two PHY-floor goldens** lock issue #8 shut: 8 errors stays `ok`, 100 warns.
- **Step 4** is a fast manual sweep before the goldens exist.
- **Step 8's** PHP one-liner covers the legacy-value mapping, including both ends.
- No new fixture files — every case is a `sed` over `rollup_healthy.txt`, which
  keeps the temperature the only variable.

## Done criteria

- [ ] `grep -c 'PHYERR_FLOOR' .../parse/storcli_overview.sh` prints `2`
- [ ] `grep -c 'ALERT - 10' .../parse/storcli_overview.sh .../parse/hba.sh` prints `0` for both
- [ ] Step 4 printed the exact eight-line band table
- [ ] Step 8's PHP snippet printed the exact eight legacy mappings
- [ ] `ls tests/expected/band_*.json | wc -l` → `8`
- [ ] `phy_under_floor.json` contains `"status":"ok"`; `phy_over_floor.json` contains `"status":"warn"`
- [ ] `grep -c 'Alert Threshold' .../settings.php` → `0`
- [ ] `grep -c '\-\-sc' .../dashboard.php` ≥ `3`
- [ ] `git diff -- tests/expected/` shows only `temp_band` additions and band-driven `status` changes
- [ ] Both lints exit 0; `bash tests/run.sh` exits 0 with `--- all pass ---`
- [ ] `git status --porcelain` lists only the eight in-scope files plus new `tests/expected/*`
- [ ] `plans/README.md` row for 018 updated

## STOP conditions

- The drift check prints anything.
- **Step 4's band table is off by one anywhere.** Fix the code, never the
  expectation.
- A re-blessed golden changes a field that is neither `temp_band` nor `status`.
- You find yourself making `critical` a darker foreground red. It fails contrast;
  the measurements are in "The design, as decided".
- You find yourself fixing the `^[0-9]+:[0-9]+` drive-state scrape. That is plan
  017's territory and the two would conflict in the same file.
- The two parsers' `band_of` / `band_index` copies differ in any way.

## Maintenance notes

- **The band table exists twice**, in `parse/hba.sh` and `parse/storcli_overview.sh`,
  because parsers are pure filters that do not source `lib.sh`. They must stay
  byte-identical; change one, change the other in the same commit. If a third
  backend ever appears, that is the moment to move it into a sourced helper and
  accept the coupling.
- **`alert_threshold` in the JSON no longer means a temperature.** The name was
  kept deliberately to avoid a contract break. Anything reading that field as
  "the temperature at which the card is too hot" is now wrong.
- **`temp_color` vs `color` is the fix for #8** and must not be re-merged. If a
  future change wants one colour for the whole tile, the answer is to drop the
  badge, not to repaint the thermometer.
- **The two pages keep their CSS in different files, and this bites.** The
  dashboard tile's styles are inline in `dashboard.php`, but the Monitor page's
  are in `hbaviewer.php` while its *markup* is built in `ajax_info.php`. A change
  to what a CSS variable means therefore has to be made in three files, and the
  first draft of this plan listed only two — which silently inverted the Monitor
  badge. Any future change to `--tc` / `--sc` semantics must grep both
  `dashboard.php` and `hbaviewer.php`, not just the file that sets the variable.
- **`PHYERR_FLOOR` is a heuristic.** The honest signal is rate of change, which
  needs history the plugin does not keep. If a genuinely failing link is ever
  reported under 100 accumulated errors, that is the trigger to build the history,
  not to lower the constant toward zero.
- **What a reviewer should scrutinise**: the eight boundary goldens (that they
  assert the band, not just that the file parses), that `critical` is never used
  as a foreground stroke, and that the re-blessed golden diff contains nothing
  unexpected.
