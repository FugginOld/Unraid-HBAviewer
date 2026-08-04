# Plan 042: Stop labelling SATA drives with SAS-only vocabulary in the SMART table

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 72bca3a..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/smart.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/read_smart.sh source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php tests/run.sh`
> Expected output: **nothing**. Every excerpt below is quoted from `72bca3a`
> (`dev` tip). Any difference is a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW — display labels plus one new pass-through JSON field. No
  behavioural change to any SMART read, no new hardware access.
- **Depends on**: none
- **Category**: bug
- **Planned at**: `72bca3a`, 2026-08-03

## Why this matters

The SMART tab renders one fixed header row for every drive, and one of its
columns is named in **SAS-only vocabulary**: "Grown Defects" is a SCSI
concept (`Elements in grown defect list`). For a SATA drive that column is
populated from the ATA attribute `Reallocated_Sector_Ct` — the *number* is
right and meaningful, but the *label* names something SATA drives do not
have. A user with SATA disks behind their HBA reads a column header for a
metric their drives don't report and reasonably concludes the plugin is
confused about their hardware.

Nothing about the data collection is broken. `read_smart.sh` is already
transport-aware and `parse/smart.sh` already parses both attribute styles
with fallbacks. The gap is purely that the transport is **known and then
discarded**, so the renderer cannot say which drive is which.

This is small, but it is the visible half of "does this plugin understand
SATA drives?" — and the answer, once the label is honest and the transport
is shown, is yes.

## Current state

### `scripts/read_smart.sh` — already knows the transport, then drops it

```bash
tran=$(lsblk -dno TRAN "$dev" 2>/dev/null | tr -d ' \n')
if [ "$tran" = "sas" ]; then
    smartctl -a "$dev" 2>/dev/null | bash "$DIR/parse/smart.sh"
else
    smartctl -n standby -a "$dev" 2>/dev/null | bash "$DIR/parse/smart.sh"
fi
```

`$tran` decides the spin-up policy and is then thrown away. Its header
comment explains why the policy differs:

```bash
#   SAS: log-page reads (health/temp/defects) are electronics-only and do NOT
#        spin up the platters, so read even a standby drive.
#   SATA (or unknown): an ATA SMART read can spin the disk up, so respect
#        -n standby and skip a sleeping drive.
```

### `scripts/parse/smart.sh` — parses both, emits no transport

Header:

```bash
# Pure filter: `smartctl -a /dev/sdX` text on stdin -> SMART summary JSON.
# Targets SAS drives (named fields, no SATA attribute table); also picks up the
# SATA overall-health line. Empty fields mean "not reported" (e.g. drive asleep
# under `-n standby`, or a SATA drive whose attributes we don't parse yet).
```

The two vocabularies and the fallback that merges them:

```awk
/Elements in grown defect list:/             { match($0,/:[ \t]*([0-9]+)/,m); defects=m[1] }
/Pending defect count:/                      { match($0,/count:[ \t]*([0-9]+)/,m); pending=m[1] }
...
NF>=10 && $1==5   && $2 ~ /Reallocated_Sector/ { sd=$10 }
NF>=10 && $1==197 && $2 ~ /Current_Pending/     { spd=$10 }
END {
    if (temp    == "") temp    = st    # fall back to SATA attributes
    if (poh     == "") poh     = sp
    if (defects == "") defects = sd
    if (pending == "") pending = spd
    printf "{\"health\":\"%s\",\"temp\":\"%s\",\"trip_temp\":\"%s\",\"power_on_hours\":\"%s\",\"defects\":\"%s\",\"pending\":\"%s\",\"nonmedium\":\"%s\"}", \
        health, temp, trip, poh, defects, pending, nonmed
}
```

So `defects` carries **grown defects on SAS and reallocated sectors on
SATA**. `pending` is genuinely common to both (`Pending defect count` /
`Current_Pending_Sector`) and needs no relabelling.

### `ajax_info.php:232` — the header row, and `:227` the cell it mislabels

```php
            $cell($s['defects'] ?? ''),
            $cell($s['pending'] ?? ''),
```

```php
    return luTable(['Device', 'Model', 'Serial', 'Health', 'Temp', 'Grown Defects', 'Pending', 'Power-On'], $rows);
```

### `scripts/collect_smart.sh:40-41` — the pass-through

```bash
    printf '{"dev":"/dev/%s","serial":"%s","model":"%s","smart":%s}' \
        "$name" "$serial" "$model" "$smart" >> "$TMP"
```

`$smart` is embedded **verbatim**, so any field `parse/smart.sh` adds
reaches the renderer with no change to this file. `collect_smart.sh` is
therefore **out of scope**.

### Repo conventions to match

- Parsers under `scripts/parse/` are pure filters. Where one needs a fact it
  cannot read from stdin, the **composer passes it as a positional
  argument** — established by `parse/storcli_overview.sh:11-16`:

```bash
ALERT="${1:-80}"
PHYERR="${2:-0}"    # total sysfs phy error counters for this controller (from composer)
CHIPARG="${3:-}"    # chip name from storcli AdapterType (covers every chipset; no ID map)
```

  Follow that pattern exactly: transport becomes `$1` of `parse/smart.sh`.
- Comments explain **why**, not what.
- PHP renderers escape everything through `htmlspecialchars` — see the
  surrounding rows in `renderSmartTable`.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Full suite | `bash tests/run.sh` | ends `--- all pass ---` |
| PHP subset | `bash tests/run_php.sh` | all pass |
| PHP lint | `php -l <file>` | `No syntax errors detected` |
| Shell lint | `bash -n <file>` | exit 0, no output |
| Regenerate goldens | `UPDATE=1 bash tests/run.sh` | `WROTE <name>` per case |

No package manager, no build step.

> Some golden cases unrelated to this plan may fail in a container lacking
> GNU coreutils/`awk` features the parsers assume. Establish the failure
> list on untouched `HEAD` first (Step 0) and compare against it.

## Scope

**In scope**:

- `source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/smart.sh`
- `source/usr/local/emhttp/plugins/hbaviewer/scripts/read_smart.sh`
- `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` —
  `renderSmartTable` only
- `tests/run.sh`
- `tests/expected/smart_sas.json`, `tests/expected/smart_sata.json`
  (regenerated)
- `tests/ajax_render_test.php`

**Out of scope** (do NOT touch):

- `scripts/collect_smart.sh` — embeds the SMART JSON verbatim; a new field
  flows through with no edit. In particular **do not touch its
  `grep 'WWN="0x'` device filter**. That filter is deliberate and
  documented (`# HBA disks = SCSI block devices with a WWN (excludes USB
  sticks / no-WWN)`); whether it also excludes WWN-less SATA drives is a
  separate open question that needs a real report, not a speculative fix.
- The SMART **collection** logic in `read_smart.sh` — the `sas` vs
  everything-else spin-up branch stays exactly as it is. This plan only
  makes it *report* what it already decided.
- The Drives tab tables (`ajax_info.php:588` / `:601`) — different
  renderers, different data, not this plan.
- `parse/smart.sh`'s existing field names (`defects`, `pending`, …). They
  are consumed by name in `ajax_info.php`; renaming them is a wider change
  than this plan justifies.

## Git workflow

- Branch: `advisor/042-sata-aware-smart-table`
- One commit. Imperative message, matching `git log` style, e.g.
  `Label the SMART table honestly for SATA drives`.
- Do NOT push or open a PR.

## Steps

### Step 0: Record the pre-existing failure baseline

```
bash tests/run.sh 2>&1 | grep '^FAIL' | sort > /tmp/baseline-fails.txt
cat /tmp/baseline-fails.txt
```

Quote this list in your final report. No later run may add a name to it.

### Step 1: Emit the transport from `parse/smart.sh`

Add a positional arg, documented in the header block in the same style as
`storcli_overview.sh`:

```bash
TRAN="${1:-}"   # "sas" | "sata" | "" — from lsblk, injected by read_smart.sh
```

Pass it into awk with `-v` and add it to the emitted JSON as the **last**
field (appending keeps every existing consumer's key lookup valid):

```awk
printf "{\"health\":\"%s\",...,\"nonmedium\":\"%s\",\"transport\":\"%s\"}", \
    health, ..., nonmed, tran
```

Add a comment saying why the field exists, in the house style — something
that captures this:

> `defects` means two different things depending on the bus: grown defects
> (SAS log page) or reallocated sectors (ATA attribute 5). Both are "sectors
> the drive permanently retired", which is why one field carries both — but
> the UI cannot label the column honestly without knowing which bus it came
> from, so the transport travels with the data.

**Verify**:
```
bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/smart.sh && echo LINT-OK
P=source/usr/local/emhttp/plugins/hbaviewer/scripts/parse
bash $P/smart.sh sas  < tests/fixtures/smart/sas_drive.txt  | grep -o '"transport":"[^"]*"'
bash $P/smart.sh sata < tests/fixtures/smart/sata_drive.txt | grep -o '"transport":"[^"]*"'
bash $P/smart.sh      < tests/fixtures/smart/sata_drive.txt | grep -o '"transport":"[^"]*"'
```
→ `"transport":"sas"`, `"transport":"sata"`, `"transport":""`

### Step 2: Pass the transport through `read_smart.sh`

The variable already exists; hand it to the parser in both branches. Note
the **`else` branch must pass `$tran`, not the literal `sata`** — `lsblk`
reports `usb`, `nvme` and empty for other devices, and claiming those are
SATA would be a new lie in place of the old one.

```bash
if [ "$tran" = "sas" ]; then
    smartctl -a "$dev" 2>/dev/null | bash "$DIR/parse/smart.sh" "$tran"
else
    smartctl -n standby -a "$dev" 2>/dev/null | bash "$DIR/parse/smart.sh" "$tran"
fi
```

**Verify**: `bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/read_smart.sh` → exit 0

### Step 3: Make the table honest

In `renderSmartTable` (`ajax_info.php`, around lines 205–232):

1. Add a **Type** cell per row, immediately after the Model cell, rendering
   the transport upper-cased, or the muted dash when it is empty:

```php
            ($s['transport'] ?? '') !== '' ? htmlspecialchars(strtoupper($s['transport'])) : $dash,
```

   (`$dash` and the `$cell` helper are already defined above the loop — use
   them, do not re-declare.)

2. Rename the mislabelled header and add the new one. The `defects` column
   header must stop claiming a SAS-only metric:

```php
    return luTable(['Device', 'Model', 'Type', 'Serial', 'Health', 'Temp', 'Reallocated', 'Pending', 'Power-On'], $rows);
```

   `Reallocated` is true on both buses (SAS grown defects *are* retired
   sectors; SATA attribute 5 is literally `Reallocated_Sector_Ct`).
   `Pending` was already correct for both and does not change.

3. The cell order in `$rows[]` must match the header order exactly — Type
   goes third, after Model, before Serial.

**Verify**:
```
php -l source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php
```
→ `No syntax errors detected`

### Step 4: Extend the tests

**`tests/run.sh`** — the two existing SMART cases at lines 70–71 currently
pass no argument:

```bash
check smart-sas        smart_sas.json       bash "$P/smart.sh" < fixtures/smart/sas_drive.txt
check smart-sata       smart_sata.json      bash "$P/smart.sh" < fixtures/smart/sata_drive.txt
```

Change them to pass the transport, and **add a third case** that pins the
unknown-transport path:

```bash
check smart-sas        smart_sas.json       bash "$P/smart.sh" sas  < fixtures/smart/sas_drive.txt
check smart-sata       smart_sata.json      bash "$P/smart.sh" sata < fixtures/smart/sata_drive.txt
# No transport passed (lsblk reported usb/nvme/nothing): the field must be
# empty so the UI shows a dash, never a guessed bus.
check smart-notran     smart_notran.json    bash "$P/smart.sh"      < fixtures/smart/sata_drive.txt
```

**`tests/ajax_render_test.php`** — the existing SMART assertions start at
line 313 (`$h = renderSmartTable([...])`). Read that block first and follow
its structure. Add assertions that:

- a drive with `'transport' => 'sata'` renders `SATA` **and** the header
  contains `Reallocated`;
- a drive with `'transport' => 'sas'` renders `SAS`;
- a drive with **no** `transport` key renders the muted dash and does not
  emit the string `SATA` — the empty case must not fall back to a guess;
- the header **no longer** contains `Grown Defects`.

Then regenerate and compare:

```
UPDATE=1 bash tests/run.sh
git diff tests/expected/
bash tests/run.sh 2>&1 | grep '^FAIL' | sort > /tmp/after-fails.txt
diff /tmp/baseline-fails.txt /tmp/after-fails.txt && echo "NO NEW FAILURES"
```

**Verify — read the golden diff.** `smart_sas.json` and `smart_sata.json`
must change by the added `"transport"` field and **nothing else**.

### Step 5: Refresh the stale header comment in `parse/smart.sh`

Its first line says the filter "Targets SAS drives … also picks up the SATA
overall-health line", which undersells what it now does — it parses four
SATA attributes and reports which bus it read. Correct it to describe both
paths and the transport field. Comment only; no logic change.

**Verify**: `bash -n` still clean; `bash tests/run.sh` failure list unchanged.

## Test plan

- **New golden case**: `smart-notran` — pins that an unknown transport
  yields `""`, not a defaulted `sata`.
- **Updated goldens**: `smart_sas.json` / `smart_sata.json` gain
  `"transport"`.
- **New PHP render assertions** in `tests/ajax_render_test.php`, modelled on
  the existing `renderSmartTable` block at line 313.
- **Mutation check** — after the suite is green, confirm the new assertions
  bite. Run each, confirm the named case fails, restore, and **report all
  three results**:
  1. Make `read_smart.sh`'s `else` branch pass the literal `sata` instead of
     `$tran` → the "no transport renders a dash" PHP assertion must fail.
     (If it does not, that assertion is testing the parser's default rather
     than the renderer — fix the assertion.)
  2. Revert the header string to `Grown Defects` → the "header no longer
     contains Grown Defects" assertion must fail.
  3. Drop `"transport"` from the awk `printf` → `smart-sas`, `smart-sata`
     and `smart-notran` must all fail.

## Done criteria

ALL must hold:

- [ ] `php -l` clean on `ajax_info.php`; `bash -n` clean on both shell files
- [ ] `bash tests/run.sh` adds **no failure name** absent from the Step 0
      baseline
- [ ] `bash $P/smart.sh sata < tests/fixtures/smart/sata_drive.txt` emits
      `"transport":"sata"`
- [ ] `bash $P/smart.sh < tests/fixtures/smart/sata_drive.txt` emits
      `"transport":""`
- [ ] `grep -c 'Grown Defects' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php`
      → `0`
- [ ] `git diff tests/expected/smart_sas.json` shows only the added
      `"transport"` field
- [ ] `git diff --stat source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh`
      is **empty**
- [ ] `git status --short` lists only files from the In-scope list

## STOP conditions

Stop and report — do not improvise — if:

- The drift check prints anything, or the excerpts above do not match the
  live files.
- Regenerating the goldens changes any SMART field other than the added
  `"transport"`.
- `bash tests/run.sh` produces a failure name not in the Step 0 baseline.
- You conclude the fix requires renaming the `defects` JSON key, or
  splitting it into `grown_defects` / `reallocated`. That is a larger
  change with more consumers; this plan deliberately keeps one field and
  fixes the **label**. If you believe the split is necessary, stop and make
  the case rather than doing it.
- You find yourself editing `collect_smart.sh`'s `WWN="0x` filter because a
  SATA drive might lack a WWN. That is a real open question but it is
  **not this plan**, it needs a reporter's `lsblk -S -P -o NAME,WWN` output
  to confirm, and changing the filter speculatively risks pulling USB
  sticks into the SMART tab.
- `lsblk -dno TRAN` turns out to return something other than `sas`/`sata`
  for a drive behind an LSI HBA in your test environment. Report what it
  returned; do not add a translation table on a guess.

## Maintenance notes

- **`defects` is deliberately one field carrying two bus-specific metrics.**
  Both mean "sectors the drive permanently retired", which is why they share
  a column and why `Reallocated` is an honest header for both. If a future
  change ever needs them distinguished numerically (not just labelled),
  that is the moment to split the key — and to update every consumer.
- **`transport` comes from `lsblk`, not from the HBA.** It reflects how the
  kernel sees the block device. A SATA drive behind a SAS expander still
  reports `sata`, which is the answer users want.
- **Open, deliberately deferred**: `collect_smart.sh` selects drives with
  `grep 'WWN="0x'`. The stated intent is excluding USB sticks. Whether any
  real HBA-attached SATA drive reports no WWN and is therefore silently
  missing from the SMART tab is unresolved — it needs a report with
  `lsblk -S -P -o NAME,WWN` from a user who sees a drive missing, not a
  speculative loosening of the filter.
- **What a reviewer should scrutinise**: that the `else` branch passes
  `$tran` rather than a hardcoded `sata` (the whole point is not replacing
  one wrong label with another), and that the header array and the
  `$rows[]` cell order stayed in sync — a mismatch there shifts every column
  silently.
