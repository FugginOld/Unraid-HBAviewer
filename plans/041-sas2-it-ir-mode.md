# Plan 041: Report IT/IR mode on the lsiutil (SAS2) backend

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 72bca3a..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/hba.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh tests/run.sh`
> Expected output: **nothing**. Every excerpt below is quoted from `72bca3a`
> (`dev` tip). Any difference is a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW — one new read-only lsiutil capture and a pure text parse.
  No mutating path, no hardware write, no change to any existing field's
  value.
- **Depends on**: none
- **Category**: bug
- **Planned at**: `72bca3a`, 2026-08-03
- **Issue**: https://github.com/FugginOld/Unraid-HBAviewer/issues/10

## Why this matters

On the storcli backend the overview card shows a `Mode: IT` / `Mode: IR`
row. On the lsiutil (SAS2 / 9200-series) backend it shows **nothing** —
`parse/hba.sh` never emits a `mode` key at all, so `view.php`'s
`$data['mode'] ?? ''` yields `''` and both renderers suppress the row.
The reporter on issue #10 (`jac2424`, SAS9207-8i) saw the next field where
Mode should be and reasonably asked whether Mode is a 93xx-only feature.

The information is available and cheap to get. lsiutil main-menu option 1
("Identify firmware, BIOS, and/or FCode") prints the firmware image name
with the personality as a suffix:

```
Firmware image's version is MPTFW-20.00.07.00-IT
```

`parse/hba.sh` already **quotes this exact line in a comment** (lines
62–64) as evidence for how it decodes the packed-hex firmware version — the
parser has been looking straight at the `-IT` suffix without reading it.
After this plan, SAS2 cards report their mode like SAS3 cards do.

## Current state

### Files

- `scripts/get_hba_info.sh` — the composer. Captures three lsiutil outputs
  to temp files and hands them to `parse/hba.sh`. Needs a fourth capture.
- `scripts/parse/hba.sh` — pure parser, takes the three capture files plus
  the alert threshold as positional args. Needs a fifth arg and a `mode`
  field in its JSON.
- `tests/run.sh` — golden-file harness for the parsers.
- `tests/fixtures/hba_*.txt` — captured lsiutil text, no hardware needed.
- `tests/expected/hba_*.json` — goldens for the four `hba.sh` cases.

### `scripts/get_hba_info.sh:101-107` — the capture block

```bash
    require_binary || return 1
    local IOC BANNER BOARD
    IOC=$(mktemp); BANNER=$(mktemp); BOARD=$(mktemp)
    trap 'rm -f "$IOC" "$BANNER" "$BOARD"' EXIT
    hba_query -p"$PORT" -a 25,2,0,0 2>/dev/null > "$IOC"
    printf '0\n' | hba_query        2>/dev/null > "$BANNER"
    hba_query -b                    2>/dev/null > "$BOARD"
    bash "$DIR/parse/hba.sh" "$IOC" "$BANNER" "$BOARD" "$ALERT"
```

`hba_query` is the wrapper from `scripts/lib.sh` that resolves the bundled
`hbaviewer.x86_64` (lsiutil 1.70) and applies `$LSIUTIL` test overrides.
Its header documents the calling convention:

```bash
#   hba_query -e -p"$PORT" -a 35,0      # expert-mode command
```

Menu option 1 is a **plain main-menu item, not an expert-mode one**, so the
new call takes `-p"$PORT" -a 1,0` with no `-e`.

### `scripts/parse/hba.sh:1-14` — the parser's signature

```bash
# Pure parser: overview JSON from three captured lsiutil text blocks.
# Overview genuinely has three sources, so this takes three files, not stdin:
#   $1  ioc     = lsiutil -pN -a 25,2,0,0   (temperature + PCIe + power)
#   $2  banner  = printf '0\n' | lsiutil     (chip model, firmware, port name)
#   $3  board   = lsiutil -b                 (product name, PCI location)
#   $4  alert   = alert threshold (int, for status classification)
#
# No hardware here — feed captured fixtures to test the whole shape.

IOC=$(cat "$1" 2>/dev/null)
BANNER=$(cat "$2" 2>/dev/null)
BOARD=$(cat "$3" 2>/dev/null)
ALERT="${4:-80}"
```

### `scripts/parse/hba.sh:60-66` — the comment that already quotes the target line

```bash
# Firmware: the banner prints the version as four packed HEX bytes, so
# "14000700" is 0x14.0x00.0x07.0x00 = 20.00.07.00 — a P20 card. lsiutil itself
# confirms the decode when you pick menu option 1:
#   "Current active firmware version is 14000700 (20.00.07)"
#   "Firmware image's version is MPTFW-20.00.07.00-IT"
```

### `scripts/parse/hba.sh:125-142` — the emitted JSON (no `mode` key)

```bash
cat <<EOF
{
  "temp": $TEMPJSON,
  "model": "${MODEL:-Unknown}",
  "firmware": "${FW_VER}",
  "fw_old": $FW_OLD,
  "port_name": "${PORT_NAME:-ioc0}",
  "board_name": "${BOARD_NAME:-}",
  "pci_location": "${PCI_BUS:-0}:${PCI_DEV:-0}",
  "pcie_width": "${PCIE_WIDTH}",
  "pcie_speed": "${PCIE_SPEED}",
  "power_mode": "${POWER_MODE}",
  "alert_threshold": $ALERT,
  "temp_band": "${TEMP_BAND}",
  "cfg_band": "${CFG_BAND}",
  "status": "$STATUS"
}
EOF
```

### The consumer contract — already correct, do not change it

`view.php:226`:

```php
        'mode'       => $data['mode']        ?? '',   // IT/IR (storcli)
```

`ajax_info.php:278` and `dashboard.php:240` both render the row **only when
the string is non-empty**:

```php
              . ($v['mode']   !== '' ? '<p>Mode: <span>' . htmlspecialchars($v['mode']) . '</span></p>' : '')
```

So emitting `"mode": ""` when the suffix can't be read keeps today's exact
behaviour (row hidden), and emitting `"IT"` makes the row appear. **No PHP
change is needed in this plan.** Update the `// IT/IR (storcli)` comment in
`view.php` only — see Step 5.

### Repo conventions to match

- Parsers under `scripts/parse/` are **pure text filters** — no hardware
  access, no `mktemp`, no network. All I/O happens in the composer.
  `parse/storcli_overview.sh:1-8` states this contract explicitly.
- Comments explain **why**, especially where a naive approach already
  failed. See `parse/hba.sh:29-34` (PCIe speed enum vs bitmask, issue #9)
  and `parse/storcli_overview.sh:54-57` (why MODE is not a whole-output
  grep). Match that density and tone — a bare `# parse mode` is not the
  house style.
- Fixtures record their provenance in `tests/run.sh` comments next to the
  `check` line. See the existing example at `tests/run.sh:36-39`:

```bash
# Real `/c0 show` + `/c0 show temperature` from issue #5 (@t0ffemannen,
# SAS3008/IR firmware): eight blank-EID UGood rows in PD LIST followed by the
# legend block whose "UGood-Unconfigured Good|..." text is the exact string
# that false-matched MODE before plan 017. ROC temperature 56.
```

### The real capture this plan is built on

`plans/assets/issue10-sas2-mode-9207-8i.txt` is the verbatim output posted
by `jac2424` on issue #10 from a **SAS9207-8i (SAS2308, mpt2sas,
firmware 20.00.07, IT-flashed)**. It contains no serials, WWNs, or
identifiers. The section this plan needs is the `-a 1,0` block:

```
===== port 1 : -a 1,0 =====

LSI Logic MPT Configuration Utility, Version 1.70, July 30, 2013

1 MPT Port found

     Port Name         Chip Vendor/Type/Rev    MPT Rev  Firmware Rev  IOC
 1.  ioc0              LSI Logic SAS2308 D1      200      14000700     0

Main menu, select an option:  [1-99 or e/p/w or 0 to quit] 1

Current active firmware version is 14000700 (20.00.07)
Firmware image's version is MPTFW-20.00.07.00-IT
  LSI Logic
  Not Packaged Yet
x86 BIOS image's version is MPT2BIOS-7.39.02.00 (2015.08.03)
EFI BIOS image's version is 7.27.01.01

Main menu, select an option:  [1-99 or e/p/w or 0 to quit] 0
```

Note the file also proves `-a 1,0` **fails cleanly on a port that does not
exist** (`ERROR:  No such port.`) — the parse must yield `""` for that text,
not a false reading.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Full suite | `bash tests/run.sh` | ends `--- all pass ---` |
| Shell lint | `bash -n <file>` | exit 0, no output |
| Regenerate goldens | `UPDATE=1 bash tests/run.sh` | `WROTE <name>` per case |

There is no package manager, no build step, and nothing to install.

> If `php` is not on PATH in your environment, `tests/run.sh` still runs the
> shell golden cases; the PHP subset is invoked through `tests/run_php.sh`.
> Report which environment you used. **Some golden cases unrelated to this
> plan may fail in a bare container that lacks GNU coreutils/`awk` features
> the parsers assume — establish the failure list on untouched `HEAD` FIRST
> (Step 0) and compare against it, rather than expecting a clean run.**

## Scope

**In scope** (the only files you may modify):

- `source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/hba.sh`
- `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh`
- `source/usr/local/emhttp/plugins/hbaviewer/view.php` — **one comment only**
- `tests/fixtures/hba_ident_it.txt` (create)
- `tests/fixtures/hba_ident_ir.txt` (create)
- `tests/fixtures/hba_ident_noport.txt` (create)
- `tests/run.sh`
- `tests/expected/hba_*.json` (regenerated, reviewed)

**Out of scope** (do NOT touch, even though they look related):

- `scripts/parse/storcli_overview.sh` — the storcli backend's IT/IR
  inference is **separately known to be wrong** (it reports IR for an
  IT-flashed 9305-16i, also issue #10) and is being handled apart from
  this plan on evidence that has not arrived yet. Changing it here would
  collide with that work. This plan touches the lsiutil path only.
- Any `ajax_info.php` / `dashboard.php` rendering logic — the consumers
  already handle a `mode` string correctly.
- `scripts/lib.sh` — `hba_query` is used as-is.
- The `fw_old` / P20 logic in `parse/hba.sh:89-94` — related-looking
  (it reads the same firmware version) but correct and unrelated.

## Git workflow

- Branch: `advisor/041-sas2-it-ir-mode`
- One commit is fine. Message style matches `git log` — imperative, no
  prefix convention, e.g. `Report IT/IR mode on the lsiutil (SAS2) backend`.
- Do NOT push or open a PR.

## Steps

### Step 0: Record the pre-existing failure baseline

Before changing anything:

```
bash tests/run.sh 2>&1 | grep '^FAIL' | sort > /tmp/baseline-fails.txt
cat /tmp/baseline-fails.txt
```

**Verify**: the command completes. Whatever it lists (possibly nothing) is
your baseline. Every later run must produce **the same list** — no new
names. Quote this list in your final report.

### Step 1: Create the three fixtures

**`tests/fixtures/hba_ident_it.txt`** — copy the `-a 1,0` block verbatim
from `plans/assets/issue10-sas2-mode-9207-8i.txt`, starting at the
`LSI Logic MPT Configuration Utility` line that follows
`===== port 1 : -a 1,0 =====` and ending at the final
`Main menu, select an option:` line. Do **not** include the `=====` banner
lines — those came from the reporter's wrapper script, not from lsiutil.

**`tests/fixtures/hba_ident_ir.txt`** — the same text with `-IT` changed to
`-IR` on the `Firmware image's version` line, and **nothing else changed**.

> **This fixture is synthetic and must be labelled as such.** No IR-firmware
> SAS2 capture exists in this project. Put this exact comment on the `check`
> line in `tests/run.sh` (fixtures themselves are raw tool text and carry no
> comments):
>
> ```bash
> # SYNTHETIC: hba_ident_ir.txt is hba_ident_it.txt with the one suffix
> # changed IT->IR. No real IR-firmware SAS2 capture exists in this project;
> # this pins the IR branch's shape, NOT that real IR output looks like this.
> ```

**`tests/fixtures/hba_ident_noport.txt`** — copy the `port 2 : -a 1,0`
block from the same asset (the `ERROR:  No such port.` case), again without
the `=====` banner lines. This is real output, not synthetic.

**Verify**:
```
grep -c 'MPTFW-20.00.07.00-IT' tests/fixtures/hba_ident_it.txt   # -> 1
grep -c 'MPTFW-20.00.07.00-IR' tests/fixtures/hba_ident_ir.txt   # -> 1
grep -c 'No such port'         tests/fixtures/hba_ident_noport.txt  # -> 1
grep -c 'MPTFW'                tests/fixtures/hba_ident_noport.txt  # -> 0
diff <(sed 's/-IR$/-IT/' tests/fixtures/hba_ident_ir.txt) tests/fixtures/hba_ident_it.txt && echo IDENTICAL
```
The last one must print `IDENTICAL` — proving the IR fixture differs by
exactly that one suffix.

### Step 2: Teach `parse/hba.sh` to read the suffix

Add a fifth positional arg and update the header block to document it,
matching the existing four-line style:

```bash
#   $5  ident   = lsiutil -pN -a 1,0        (firmware image name -> IT/IR)
```

Read it alongside the others:

```bash
IDENT=$(cat "$5" 2>/dev/null)
```

`$5` is optional — `cat ""` yields empty, so a caller passing four args
still works and produces `"mode": ""`.

Then, in the `── 2. Banner` section near the existing firmware comment, add
the parse. Required shape and behaviour:

```bash
# ── Firmware personality (IT vs IR) ──────────────────────────────────────────
# lsiutil main-menu option 1 names the flashed firmware image, and the suffix
# IS the personality:
#   "Firmware image's version is MPTFW-20.00.07.00-IT"
# Anchored on that exact sentence and on the END of the token, NOT a bare grep
# for "IT" — the same block prints "MPT2BIOS-..." and free text ("LSI Logic",
# "Not Packaged Yet"), and a loose match would call every card IT. A port that
# does not exist prints "ERROR:  No such port." with no MPTFW line at all, and
# must yield "" so the UI hides the row rather than inventing a mode.
MODE=$(printf '%s\n' "$IDENT" \
    | grep -m1 -oE "Firmware image's version is MPTFW-[0-9.]+-(IT|IR)" \
    | grep -oE '(IT|IR)$')
```

Add `"mode"` to the emitted JSON. Place it **immediately after
`"firmware"`** so it reads next to the version it qualifies:

```bash
  "firmware": "${FW_VER}",
  "mode": "${MODE}",
  "fw_old": $FW_OLD,
```

**Verify** — the parser in isolation, before wiring the composer:
```
bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/hba.sh && echo LINT-OK
P=source/usr/local/emhttp/plugins/hbaviewer/scripts/parse
bash $P/hba.sh tests/fixtures/hba_ioc.txt tests/fixtures/hba_banner.txt \
  tests/fixtures/hba_board.txt 80 tests/fixtures/hba_ident_it.txt | grep -o '"mode": "[^"]*"'
```
→ `"mode": "IT"`

Repeat with `hba_ident_ir.txt` → `"mode": "IR"`, with
`hba_ident_noport.txt` → `"mode": ""`, and with the argument omitted
entirely → `"mode": ""`.

### Step 3: Capture the fourth block in the composer

In `scripts/get_hba_info.sh`, extend the capture block. The `trap` must
clean up the new temp file too — forgetting it leaks a file per refresh,
and this function runs on every page load.

```bash
    local IOC BANNER BOARD IDENT
    IOC=$(mktemp); BANNER=$(mktemp); BOARD=$(mktemp); IDENT=$(mktemp)
    trap 'rm -f "$IOC" "$BANNER" "$BOARD" "$IDENT"' EXIT
    hba_query -p"$PORT" -a 25,2,0,0 2>/dev/null > "$IOC"
    printf '0\n' | hba_query        2>/dev/null > "$BANNER"
    hba_query -b                    2>/dev/null > "$BOARD"
    # Main-menu option 1 = "Identify firmware, BIOS, and/or FCode". Plain menu
    # item, NOT expert mode, so no -e. Read-only: it reports what is flashed.
    hba_query -p"$PORT" -a 1,0      2>/dev/null > "$IDENT"
    bash "$DIR/parse/hba.sh" "$IOC" "$BANNER" "$BOARD" "$ALERT" "$IDENT"
```

Note the arg order: `$ALERT` stays fourth, `$IDENT` is appended fifth.

**Verify**: `bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh` → exit 0, no output.

### Step 4: Add golden cases and regenerate

`tests/run.sh` currently has four `hba.sh` cases at lines 146–154. They pass
four args and must keep working unchanged (proving the optional-`$5`
contract). Add three new cases after them, with the provenance comments:

```bash
# Real `lsiutil -a 1,0` from issue #10 (@jac2424, SAS9207-8i / SAS2308,
# mpt2sas, firmware 20.00.07 IT-flashed). The personality is the suffix on
# "Firmware image's version is MPTFW-20.00.07.00-IT".
check hba-mode-it  hba_mode_it.json  bash "$P/hba.sh" fixtures/hba_ioc.txt fixtures/hba_banner.txt fixtures/hba_board.txt 80 fixtures/hba_ident_it.txt
# SYNTHETIC: hba_ident_ir.txt is hba_ident_it.txt with the one suffix
# changed IT->IR. No real IR-firmware SAS2 capture exists in this project;
# this pins the IR branch's shape, NOT that real IR output looks like this.
check hba-mode-ir  hba_mode_ir.json  bash "$P/hba.sh" fixtures/hba_ioc.txt fixtures/hba_banner.txt fixtures/hba_board.txt 80 fixtures/hba_ident_ir.txt
# Real "ERROR:  No such port." from the same capture: no MPTFW line -> mode ""
# so the UI hides the row instead of guessing.
check hba-mode-noport hba_mode_noport.json bash "$P/hba.sh" fixtures/hba_ioc.txt fixtures/hba_banner.txt fixtures/hba_board.txt 80 fixtures/hba_ident_noport.txt
```

Then regenerate every golden:

```
UPDATE=1 bash tests/run.sh
git diff --stat tests/expected/
git diff tests/expected/
```

**Verify — read the diff, do not skim it.** The ONLY change to the four
pre-existing `hba_*.json` goldens must be the added `"mode": ""` line. If
any other field's value moved, that is a STOP condition. The three new
golden files must show `"mode": "IT"`, `"mode": "IR"`, `"mode": ""`.

Then:
```
bash tests/run.sh 2>&1 | grep '^FAIL' | sort > /tmp/after-fails.txt
diff /tmp/baseline-fails.txt /tmp/after-fails.txt && echo "NO NEW FAILURES"
```

### Step 5: Correct the stale comment in `view.php`

One line, `view.php:226`. The `(storcli)` note is now wrong:

```php
        'mode'       => $data['mode']        ?? '',   // IT/IR — storcli, and lsiutil via MPTFW suffix
```

Change nothing else in this file.

**Verify**: `git diff --stat source/usr/local/emhttp/plugins/hbaviewer/view.php` → `1 file changed, 1 insertion(+), 1 deletion(-)`

## Test plan

- **New golden cases** in `tests/run.sh`, following the structure of the
  existing `hba-normal` / `hba-p16` cases directly above them:
  - `hba-mode-it` — real IT capture → `"mode": "IT"`
  - `hba-mode-ir` — synthetic IR variant → `"mode": "IR"`
  - `hba-mode-noport` — real "No such port" error text → `"mode": ""`
- **Regression coverage already present**: the four existing `hba.sh` cases
  call the parser with only four args. Their continued passing (with
  `"mode": ""`) is what proves the fifth arg is genuinely optional and that
  no existing field changed.
- **Mutation check** — after the suite is green, confirm the new cases have
  teeth. Run each of these, confirm the named case fails, then restore:
  1. Change the parser's `grep -oE '(IT|IR)$'` to `grep -oE '(IT|IR)'`
     (drop the anchor) → must still pass (both are equivalent here); if it
     fails, your fixture differs from the plan's and you should re-check
     Step 1.
  2. Delete the `MODE=$(...)` assignment so `MODE` is empty → `hba-mode-it`
     and `hba-mode-ir` must both fail, `hba-mode-noport` must still pass.
  3. Replace the anchored `grep -m1 -oE "Firmware image's version is ..."`
     with a bare `grep -oE '(IT|IR)'` over `$IDENT` → `hba-mode-noport`
     must fail (the "Utility" / "Configuration" text contains no `IT`, but
     `hba_ident_noport.txt` contains `MPT Rev`… confirm empirically and
     **report the actual result**, whichever way it goes).

  Report all three results.

## Done criteria

ALL must hold:

- [ ] `bash -n` clean on both modified shell scripts
- [ ] `bash tests/run.sh` produces **no failure names absent from the Step 0
      baseline**
- [ ] `bash $P/hba.sh <ioc> <banner> <board> 80 tests/fixtures/hba_ident_it.txt`
      emits `"mode": "IT"`
- [ ] The same call with the fifth argument **omitted** emits `"mode": ""`
      (backward compatibility)
- [ ] `git diff tests/expected/` shows the four pre-existing `hba_*.json`
      goldens changed by the added `"mode"` line and **nothing else**
- [ ] `grep -n '"mode"' source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/hba.sh`
      returns exactly one line
- [ ] `git status --short` lists only files from the In-scope list
- [ ] `git diff 72bca3a..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh`
      is **empty** (the out-of-scope storcli parser is untouched)

## STOP conditions

Stop and report — do not improvise — if:

- The drift check prints anything, or the excerpts in "Current state" do
  not match the live files.
- Regenerating the goldens changes **any field other than the added
  `"mode"`** in the four pre-existing `hba_*.json` files. That means the
  fifth argument perturbed something it should not have.
- `bash tests/run.sh` produces a failure name that is **not** in the Step 0
  baseline.
- You find yourself wanting to change `parse/storcli_overview.sh`, or to
  make the two backends share an IT/IR helper. The storcli side has a known
  separate defect awaiting reporter data; unifying them now would bake that
  defect into both paths. Out of scope — stop and say so.
- You cannot produce the `hba_ident_ir.txt` fixture as a **single-suffix
  edit** of the real capture (the `diff` check in Step 1 fails). Do not
  hand-author IR output from imagination.
- `hba_query -a 1,0` turns out to need expert mode (`-e`) after all. This
  plan asserts it does not, on the evidence that the reporter's capture ran
  it without `-e` and got the firmware block back. If reality disagrees,
  stop and report rather than adding `-e` — the flag changes what other
  menu items do.

## Maintenance notes

- **The fourth lsiutil call runs on every uncached overview refresh.**
  `get_hba_info.sh` caches for 60s (see its header), so the added cost is
  one extra read-only lsiutil invocation per minute per controller. If
  overview latency ever becomes a complaint, this is one of four calls to
  look at — not a reason to skip it now.
- **`hba_ident_ir.txt` is synthetic.** If a real IR-firmware SAS2 capture
  ever arrives from a reporter, replace the fixture with it and delete the
  SYNTHETIC comment. Until then nobody should treat that golden as evidence
  of what IR output looks like — only of what the parser does with the
  suffix.
- **The storcli backend's IT/IR inference is a separate, open defect.**
  `parse/storcli_overview.sh:58-60` infers mode from drive states
  (`JBOD` → IT, `UGood`/`UBad`/`Onln`/`Optl` → IR). Issue #10's other
  reporter has an **IT-flashed 9305-16i reporting `UGood`**, which that
  rule calls IR. `UGood` means "unconfigured", which is true of a bare disk
  on an IT card *and* on an IR card with no arrays — so drive state cannot
  distinguish the two. Resolving it needs a real `storcli /cN show all`
  from that reporter. Do not "fix" it by copying this plan's approach:
  storcli output carries no `MPTFW-…-IT` string.
- **What a reviewer should scrutinise**: that the `MODE` grep is anchored to
  the full sentence (a loose `IT` match over that block is the obvious
  failure mode), that the `trap` in `get_hba_info.sh` cleans up all four
  temp files, and that the golden diff really is one added line per file.
