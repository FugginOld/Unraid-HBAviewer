# Plan 029: Inlet temperature and Δ — a user-selected hwmon sensor

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 005588f..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/config.php source/usr/local/emhttp/plugins/hbaviewer/scripts/config.sh source/usr/local/emhttp/plugins/hbaviewer/settings.php source/usr/local/emhttp/plugins/hbaviewer/view.php`
> Expected output: **nothing**. Every excerpt below was re-verified against
> `005588f` (`dev` tip, 2026-08-03). Any difference is a STOP condition.
>
> **Reconciled 2026-08-03** (originally written against `96daac5`, 88 commits
> earlier). Every excerpt below was re-checked line by line and **all of them
> still hold verbatim**: `lsi_clamp()` is byte-identical, `lsi_config_read()`
> and `lsi_config_write()` still route every value through it,
> `scripts/config.sh` and `view.php` are untouched, `grep -rn 'hwmon'` over
> `source/` and `tests/` still returns nothing, and `INLET_SENSOR` does not
> exist yet. Two changes since the original writing, neither invalidating
> anything:
>
> 1. `LSI_SCHEMA` gained one more **int** key, `ENABLE_NOTIFY` — it sits in the
>    `...` elision in the excerpt below and does not weaken the premise that
>    every existing setting is an integer.
> 2. `settings.php` grew ~88 lines and is now a **two-column grid of `<h3>`
>    cards** inside one `<form method="post">` (line 151): *HBA Connection*
>    (156), *Display Panels* (215), *Notifications* (246), *Diagnostic Bundle*
>    (270), *Export / API* (298), *Advanced — Firmware Flashing* (306). The
>    three form conventions quoted below survive verbatim at lines 186, 197 and
>    219, inside the *HBA Connection* card. **Put the sensor picker in the
>    *HBA Connection* card**, below the alert threshold — it is a reading-source
>    setting, not a display toggle. Do not add a new top-level card for one
>    `<select>`.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: MEDIUM — not for the feature itself, which is read-only, but
  because it is the **first string-valued setting** this plugin has ever had.
  See "The blocker" below; that is the real work.
- **Depends on**: 020 (DONE) for the Health tab, if the Δ is surfaced there
- **Category**: feature
- **Planned at**: `96daac5`, 2026-08-01 — **reconciled to `005588f`, 2026-08-03**
- **Requested by**: maintainer. Renumbered out of the 021 slot when an
  external roadmap review claimed it; this is the original 021.

## What this is

An HBA at 72 °C means nothing on its own. In a 20 °C room it is a hot card; in
a 45 °C rack closet it is a well-cooled one doing its job. **Δ (delta) — the
gap between the controller and the air entering the case — is the number that
actually says whether cooling is working**, and it is the number that stays
comparable across seasons, rooms and machines.

So: let the user nominate an intake sensor, show `Inlet 24 °C · Δ 48` next to
the controller temperature, and leave it **off by default**.

## The blocker — read this before writing any code

**Every setting this plugin has is an integer, and the config layer enforces
that.** A hwmon sensor is identified by a string.

```php
// key => [default, min, max]   (SHOW_* are booleans expressed as 0/1)
const LSI_SCHEMA = [
    'HBA_PORT'        => [1,  1, 8],
    'ALERT_THRESHOLD' => [76, 1, 150],
    'SHOW_PCIE'       => [1,  0, 1],
    ...
];

function lsi_clamp(string $key, $val): int {
    [, $min, $max] = LSI_SCHEMA[$key];
    return max($min, min($max, (int)$val));
}
```

`lsi_config_read()` runs every value through `lsi_clamp()`; `lsi_config_write()`
does the same on the way out:

```php
foreach (LSI_SCHEMA as $k => $spec) {
    $lines[] = "$k=" . lsi_clamp($k, $raw[$k] ?? $spec[0]);
}
```

A sensor id such as `nct6798-isa-0290/SYSTIN` passed through `(int)` becomes
`0`. **Adding a row to `LSI_SCHEMA` is not enough** — the schema needs a notion
of type before this feature can store anything.

There is a second consumer to keep in step. `scripts/config.sh` **sources the
cfg file as bash**:

```bash
CFG="${LSI_CFG_PATH:-/boot/config/plugins/hbaviewer/hbaviewer.cfg}"
[ -f "$CFG" ] && source "$CFG"
```

Its header comment states the safety argument explicitly: *"The cfg is written
only by config.php (clamped) and the .plg (fixed), so sourcing it as bash is
safe."* **A string value voids that argument.** An unquoted value containing a
space, `$`, backtick or `;` becomes shell code sourced by every composer that
runs as root.

This is the single most important thing in this plan. Handle it in Step 1 and
do not proceed until the round-trip test passes.

## Why "just pick the intake sensor" does not work

The maintainer's own box was surveyed while planning this. It exposes **47
hwmon inputs**. Several report `-61 °C` or `0 °C` — they are unconnected
headers, not cold rooms. Nothing in sysfs marks which one smells outside air.

Worse, **the choice changes the verdict**. On that box, `SYSTIN` reads 55 °C,
which against a 69 °C controller gives Δ14 and reads as a cooling emergency. A
genuine intake probe on the same machine reads ~44 °C, giving Δ25 — merely
warm. Same hardware, same moment, opposite conclusions.

And `hwmon` indices are **not stable across reboots**: `/sys/class/hwmon/hwmon3`
may be the Nuvoton chip today and the k10temp tomorrow, because numbering
follows driver probe order.

Three consequences, all non-negotiable:

1. The sensor must be stored as **`chip/label`** (e.g. `nct6798-isa-0290/SYSTIN`),
   resolved to a path at read time. Never store an `hwmonN` index.
2. The feature must be **off by default**. A wrong Δ shown confidently is worse
   than no Δ.
3. The picker must show **live readings next to each candidate** so the user can
   see that a `-61 °C` entry is junk before choosing it.

## Current state

### `scripts/config.sh` — the shell view

```bash
#!/bin/bash
# Shell view of the lsiutil config. Sourced by every composer. Resolves the two
# keys shell actually needs; SHOW_* are PHP display toggles and stay out of here.
# The cfg is written only by config.php (clamped) and the .plg (fixed), so
# sourcing it as bash is safe. Defaults live once, here.

CFG="${LSI_CFG_PATH:-/boot/config/plugins/hbaviewer/hbaviewer.cfg}"
[ -f "$CFG" ] && source "$CFG"
PORT="${HBA_PORT:-1}"
ALERT="${ALERT_THRESHOLD:-80}"
```

### `settings.php` — the form convention

```php
<input type="number" name="port" value="<?= (int)$cfg['HBA_PORT'] ?>" min="1" max="8">
<select name="threshold">
<input type="checkbox" name="show_pcie" <?= lu_checked((int)$cfg['SHOW_PCIE']) ?>>
```

Follow this shape. The sensor picker is a `<select>` whose options are built
from a discovery function, plus an explicit "Off" option that stores the empty
string.

### There is no hwmon code anywhere in the plugin

`grep -rn 'hwmon'` over the plugin tree returns **nothing**. This is entirely
new surface — there is no existing helper to extend and no established
convention to match. Follow the house style in `scripts/parse/*.sh`: a pure
filter with an injectable root so it can be fixture-tested.

## Scope

**In scope**:

- A typed config schema. Minimum viable: a third element per row giving the
  type (`'int'` / `'str'`), with `lsi_clamp()` untouched for ints and a new
  sanitiser for strings.
- **Shell-safe cfg emission** — see Step 1.
- `INLET_SENSOR` (string, default `''` = off) and nothing else. Do **not** add
  a separate `SHOW_INLET` boolean; empty string already means off, and two keys
  encoding one state will drift.
- A discovery function over `/sys/class/hwmon/*/` returning
  `[{chip, label, path, reading}]`, with an injectable root for tests.
- A resolver: `chip/label` → current path → reading, tolerant of the path
  having moved since the choice was made.
- The picker in `settings.php`, showing each candidate's live reading.
- Display of `Inlet N °C · Δ M` on the Overview card, only when a sensor is
  configured and currently readable.

**Out of scope**:

- Any alerting, banding or colouring on Δ. This plan shows a number. Deciding
  what Δ is "bad" needs data across many machines that this project does not
  have — and plan 018's bands are about the controller, not the room.
- Recording Δ history, or feeding it into plan 020's Health rollup. Tempting
  and premature: a rollup input that is off by default on most installs would
  make the rollup mean different things on different boxes.
- Any attempt to auto-detect the intake sensor. See "Why 'just pick' does not
  work" — the maintainer's own box proves it cannot be done reliably.
- Migrating any existing setting to string storage.

## Steps

### Step 1: make the config layer safe for strings — do this first, alone

Add the type to the schema and a string path through read/write. The shell
safety is the part that matters:

```php
/* Values are sourced as bash by scripts/config.sh, so a string value must be
   emitted single-quoted with embedded quotes escaped. Without this, a value
   containing a space, `$`, backtick or `;` becomes shell code running as root
   in every composer. */
function lsi_cfg_quote(string $v): string {
    return "'" . str_replace("'", "'\\''", $v) . "'";
}
```

And restrict what can be stored at all — a sensor id has a known shape:

```php
/* chip/label, both from a fixed charset. Anything else -> '' (off). This is a
   whitelist, not an escape: it is the primary defence, and lsi_cfg_quote is
   the backstop. */
function lsi_sanitise_sensor(string $v): string {
    return preg_match('~^[A-Za-z0-9._-]{1,64}/[A-Za-z0-9._ -]{1,64}$~', $v) ? $v : '';
}
```

`scripts/config.sh` needs no change if the quoting is correct — but **prove
that** rather than assuming it.

**Verify**, and do not move on until all four pass:

```bash
# round-trips intact
php -r "require 'config.php'; lsi_config_write(['INLET_SENSOR'=>'nct6798-isa-0290/SYSTIN'], '/tmp/t.cfg'); print_r(lsi_config_read('/tmp/t.cfg'));"
# the injection attempt stores empty, not code
php -r "require 'config.php'; lsi_config_write(['INLET_SENSOR'=>'x; touch /tmp/PWNED'], '/tmp/t2.cfg');"
bash -c 'source /tmp/t2.cfg; echo \"sourced ok\"'; test ! -f /tmp/PWNED && echo "SAFE" || echo "INJECTED - STOP"
# existing int keys unchanged
php -r "require 'config.php'; var_dump(lsi_config_read('/tmp/t.cfg')['HBA_PORT']);"   # int(1)
```

### Step 2: discovery

```bash
# scripts/parse/hwmon_list.sh — pure: sysfs root on $1 (default /sys/class/hwmon)
# -> one line per input:  chip<TAB>label<TAB>path<TAB>millidegrees
```

Label resolution order per `tempN_input`: `tempN_label` if present, else the
`name` of the chip plus the input index. Chip name comes from `<hwmon>/name`.

**Verify** against a fixture directory built under `/tmp` — including an input
whose `tempN_label` is absent, one reading `-61000`, and one reading `0`. All
three must appear in the output; **filtering happens in the UI, not here**, so
the user can see the junk and avoid it.

### Step 3: resolve and read

`chip/label` → scan the root → matching `tempN_input` → °C. Must return "not
readable" rather than a wrong number when the chip has vanished (driver
unloaded, hardware removed).

**Verify**: a fixture where the stored chip is absent returns the not-readable
result and does not fall back to an arbitrary other sensor. That fallback is
the single worst failure mode here — it would silently show a Δ computed from
whatever sensor happened to be first.

### Step 4: the picker

A `<select name="inlet_sensor">` listing `chip/label` with the current reading
in the option text, e.g. `nct6798-isa-0290/SYSTIN — 55 °C`. First option is
`Off` with value `''`, selected by default.

Put a one-line hint under it: readings below 0 °C or above 80 °C are almost
certainly unconnected headers.

### Step 5: display

`Inlet 24 °C · Δ 48` on the Overview card, next to the controller temperature.
Render nothing at all — not "n/a", not a dash — when no sensor is configured or
the configured one is unreadable.

Δ is `controller − inlet`, integer, no decimals. If inlet exceeds controller,
show the negative rather than clamping: it means the sensor is misidentified,
and hiding that hides the mistake.

## Test plan

- `lsi_sanitise_sensor()` — valid id, id with a space in the label, `../`
  traversal attempt, shell metacharacters, over-length, empty.
- `lsi_cfg_quote()` — round-trip through `bash -c 'source ...'` for each of the
  above; assert no file is created and the variable reads back byte-identical.
- Discovery — fixture root with a labelled input, an unlabelled one, a negative
  reading and a zero reading.
- Resolve — happy path; chip absent; label absent but chip present.
- Δ arithmetic — normal, negative, and inlet-unreadable.
- `bash tests/run.sh` → `--- all pass ---`. **No existing golden may move**:
  this feature is additive and off by default, so every existing fixture must
  produce byte-identical output.

## Done criteria

- [ ] `INLET_SENSOR=''` by default, and a fresh install shows no inlet UI
- [ ] The injection test in Step 1 prints `SAFE`
- [ ] A cfg containing a string value still sources cleanly in `bash -n`
- [ ] Existing int settings round-trip unchanged (`HBA_PORT`, `ALERT_THRESHOLD`)
- [ ] Stored value is `chip/label`; `grep -rn 'hwmon[0-9]' source/` finds no
      stored index anywhere
- [ ] Sensor vanishing yields "not readable", never a different sensor's value
- [ ] `bash tests/run.sh` → `--- all pass ---`, `git diff -- tests/expected/` empty
- [ ] `php -l` / `bash -n` clean on every touched file

## STOP conditions

- The drift check prints anything.
- The Step 1 injection test creates `/tmp/PWNED`, or the cfg fails to source.
  **Do not continue to Step 2 with a partial fix** — every composer sources this
  file as root.
- Any existing golden changes.
- An `hwmonN` index is stored in the cfg rather than `chip/label`.
- The resolver falls back to any sensor other than the configured one.
- Δ acquires a colour, a band, or a health-rollup input — explicitly out of scope.

## Maintenance notes

- **This is the plugin's first string setting, and it changes an invariant.**
  `scripts/config.sh`'s "sourcing it as bash is safe" comment rests on every
  value being an int. Update that comment to name the new invariant — values
  are shell-quoted on write — so the next person does not reason from a
  premise that has silently changed.
- **`chip/label` is a contract with the user's saved config.** If label
  resolution ever changes, previously-saved sensors stop resolving. Any change
  there needs a migration or a clear "your sensor was not found" state.
- **Δ is deliberately unjudged.** If a future plan wants to band it, that needs
  real data from many machines; the maintainer's own box shows a 11 °C spread
  between two defensible sensor choices on identical hardware.
