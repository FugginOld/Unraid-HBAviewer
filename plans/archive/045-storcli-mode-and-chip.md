# Plan 045: Stop the storcli backend guessing IR, and stop it losing the chip name

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Base check (run first)**:
> `git merge-base --is-ancestor dev HEAD && echo BASE-OK || echo BASE-STALE`
> If BASE-STALE, `git rebase dev` before doing anything. The drift check below
> only covers in-scope files and **cannot** detect a stale base.
>
> **Drift check**:
> `git diff --stat b61be96..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh tests/run.sh`
> Expected output: **nothing**. Every excerpt below was re-verified against
> `b61be96`.
>
> **Re-stamped 2026-08-04** from `095762e` after plan 041 merged. 041 touched
> `get_hba_info.sh` and `tests/run.sh`, but only in `ov_lsiutil` and the
> lsiutil golden cases — **both excerpts this plan quotes were re-checked and
> are byte-identical**, and `storcli_overview.sh` was not touched at all. The
> `storcli_overview.sh` call is still at `get_hba_info.sh:78`.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW-MED — two independent display defects in the storcli overview
  path. One golden moves **on purpose**; that is the point of Part A.
- **Depends on**: none
- **Category**: bug
- **Planned at**: `095762e`, 2026-08-04
- **Issue**: https://github.com/FugginOld/Unraid-HBAviewer/issues/10
- **Evidence**: `PaliKinG3`'s anonymised bundle, saved at
  `plans/assets/issue10-9305-16i-*.txt`

## Why this matters

Two separate defects, both proven by one reporter bundle from a
**SAS9305-16i**, both visible on the Overview card.

**Defect A — the card is IT-flashed and the plugin says IR.** The reporter
said so, and his bundle's own parsed output agrees the plugin got it wrong:

```
04-parsed/get_hba_info.json  ->  {"board_name": "SAS9305-16i", "mode": "IR", ...}
```

**Defect B — the chip is blank.** Same JSON: `"model": ""`. The Overview
"Chip:" row has nothing to show, on a card whose chip storcli names plainly.

## Current state

### Defect A — `parse/storcli_overview.sh:52-60`

```bash
DSTATES=$(printf '%s\n' "$input" | awk '/^[ \t]*[0-9]*:[0-9]+[ \t]/ { print $3 }')

# IT vs IR from those states, NOT from a whole-output grep: IT firmware reports
# JBOD, IR firmware reports UGood/UBad for a bare disk and Onln/Optl for a
# configured one. A grep over the raw text would match the legend line and call
# every card IR.
if   printf '%s\n' "$DSTATES" | grep -qiE '^JBOD$';                    then MODE="IT"
elif printf '%s\n' "$DSTATES" | grep -qiE '^(Onln|Optl|UGood|UBad)$';  then MODE="IR"
else MODE=""; fi
```

The comment's premise — "IR firmware reports UGood/UBad for a bare disk" — is
true but **not exclusive**. `UGood` means *unconfigured*, which is equally
true of a bare disk on an IT-only HBA. Three real captures:

| Card | Reporter | Drive states | Truth | Rule says |
|---|---|---|---|---|
| SAS9305-16i | `PaliKinG3` | `UGood` ×13 | **IT** (IT-only HBA; no IR firmware exists for it) | **IR — wrong** |
| HBA 9400-16i | maintainer | `JBOD` | IT | IT — right |
| LSI SAS3008 | `@t0ffemannen` | `UGood` ×8 | **unknown** | IR — unverified |

So `UGood` → IR is wrong on the one card whose truth is known, and unproven
on the other. `JBOD` → IT has never been wrong. No capture in this project
contains `Onln` or `Optl` at all.

**There is no field in `storcli /cN show` that names the personality.** The
full `show all` adds `Enable JBOD = Not Allowed`, but the overview path runs
the brief `show`, and that field is absent from it. Checked against the
reporter's own capture.

### Defect B — `get_hba_info.sh:78`

```bash
    printf '%s\n' "$out" | bash "$DIR/parse/storcli_overview.sh" "$ALERT" "$perr" "" "$width" "$speed" "$power"
```

The third argument is **hardcoded to an empty string**. Now read what the
parser says that argument is for (`parse/storcli_overview.sh:13, 33-46`):

```bash
CHIPARG="${3:-}"    # chip name from storcli AdapterType (covers every chipset; no ID map)
...
# Chip: prefer storcli's AdapterType (works for any SAS2/SAS3/SAS3.5 chipset);
# fall back to a small device-ID map only if AdapterType wasn't passed.
CHIP="$CHIPARG"
if [ -z "$CHIP" ]; then
    DEVID=$(val "Device Id")
    case "${DEVID,,}" in
        0xac) CHIP="SAS3416" ;;
        0xaf|0xad) CHIP="SAS3408" ;;
        0x97) CHIP="SAS3008" ;;
        0x87) CHIP="SAS2308" ;;
        0x72) CHIP="SAS2008" ;;
        *)    CHIP="" ;;
    esac
fi
```

`grep -rn AdapterType source/` returns **only these comments** — nothing in
the plugin ever extracts it. So the "preferred" path is dead and the
five-entry fallback map is the only path. The reporter's card is `0xC4`,
which is not in the map, so `CHIP=""`.

### The data the fix needs is already collected

`storcli show` (the global controller list, which `hba_each` already runs via
`storcli_count`) names the chip outright. Both real captures:

```
Ctl Model        AdapterType   VendId DevId SubVendId SubDevId PCI Address
  0 HBA 9400-16i   SAS3416(B0) 0x1000  0xAC    0x1000   0x3000 00:c1:00:00
  1 HBA 9400-8i    SAS3408(B0) 0x1000  0xAF    0x1000   0x3010 00:65:00:00

Ctl Model       AdapterType   VendId DevId SubVendId SubDevId PCI Address
  0 SAS9305-16i   SAS3224(A1) 0x1000  0xC4    0x1000   0x3190 00:01:00:00
```

**Do not parse this by column position.** `Model` contains a space on the
9400 (`HBA 9400-16i`) and not on the 9305, so AdapterType is the 4th
whitespace field on one and the 3rd on the other. A positional rule works on
whichever card you test and breaks on the other.

The robust rule, verified against both rows above: **cut the line at the
first `0x`, then take the last whitespace-separated token of what remains,
then strip any `(rev)` suffix.**

```
  0 HBA 9400-16i   SAS3416(B0) 0x1000 …   ->  "SAS3416(B0)"  ->  SAS3416
  0 SAS9305-16i   SAS3224(A1) 0x1000 …    ->  "SAS3224(A1)"  ->  SAS3224
  0 SAS9305-16i   SAS3224 0x1000 …        ->  "SAS3224"      ->  SAS3224
```

The third line is a hypothetical storcli build that omits the revision; the
rule handles it. If the rule finds nothing, leave `CHIP` empty and let the
existing device-ID map run — that is today's behaviour and is the safe
degradation.

### Repo conventions to match

- Parsers under `scripts/parse/` are pure filters; facts they cannot read
  from stdin arrive as positional args from the composer. That contract is
  already in place here — Part B just makes the composer honour it.
- Comments explain **why**, and name the hardware that disproved an earlier
  assumption. `parse/storcli_overview.sh:95-102` is the model:

```bash
# PHY error counters are CUMULATIVE SINCE BOOT and never reset, so ">0" flagged
# every card that had ever seen a transient — a cable reseat months ago pinned a
# healthy controller to amber forever (issue #8: 8 errors on one phy out of 21).
```

- Fixtures record provenance in a comment beside their `check` line in
  `tests/run.sh` — see the existing `storcli-overview-noencl-ugood` case.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Full suite | `bash tests/run.sh` | ends `--- all pass ---` |
| Regenerate goldens | `UPDATE=1 bash tests/run.sh` | `WROTE <name>` per case |
| Shell lint | `bash -n <file>` | exit 0, no output |

`UPDATE=1` rewrites **every** golden, not only changed ones, and can produce
trailing-newline-only churn in unrelated files. Review the diff and revert
anything that is not this plan's intended change.

## Scope

**In scope**:

- `source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh`
- `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh`
- `tests/run.sh`
- `tests/fixtures/storcli/` (new fixtures — create)
- `tests/expected/storcli_overview_noencl_ugood.json` (**changes on purpose**,
  Part A) and any new goldens

**Out of scope** (do NOT touch):

- `scripts/get_hba_health.sh` — it computes no mode and no chip.
- The temperature banding, PHY-error floor, drive-state alerting
  (`Failed|Offln|Missing|UBad|Foreign` → alert) and status ranking in
  `storcli_overview.sh`. Only the `MODE=` block and the `CHIP=` block change.
- `parse/hba.sh`'s lsiutil mode parsing. That is plan 041's territory and
  reads a completely different source (`MPTFW-…-IT`).
- The device-ID map itself — **leave all five entries**. It stays as the
  fallback for storcli builds whose controller list cannot be parsed.
- Anything under `flash.php` / `flash_hba.sh`.

## Git workflow

- Branch: `advisor/045-storcli-mode-and-chip`
- One commit. Imperative message matching `git log`.
- Do NOT push or open a PR.

## Steps

### Step 0: Record the pre-existing failure baseline

```
bash tests/run.sh 2>&1 | grep '^FAIL' | sort > /tmp/baseline-fails.txt
cat /tmp/baseline-fails.txt
```

Quote this in your report. No later run may add a name.

### Step 1: Add the reporter's card as a fixture

Create `tests/fixtures/storcli/overview_9305.txt` and
`tests/fixtures/storcli/temp_9305.txt` from the INLINED CAPTURES at the
bottom of this plan (the executor's dispatch message carries them; they are
also in the repo at `plans/assets/issue10-9305-16i-show.txt` and
`plans/assets/issue10-9305-16i-temperature.txt` if that path exists in your
worktree). The capture is already anonymised by the plugin's own bundler —
serials appear as `SERIAL0015`-style pseudonyms.

**Verify**:
```
grep -c 'Product Name = SAS9305-16i' tests/fixtures/storcli/overview_9305.txt   # -> 1
grep -c 'UGood' tests/fixtures/storcli/overview_9305.txt                        # -> 13
grep -c 'ROC temperature' tests/fixtures/storcli/temp_9305.txt                  # -> 1
```

### Step 2 (Part A): make the mode rule evidence-based

Replace only the `MODE=` conditional. The new rule reports a personality only
on **positive** evidence and says nothing otherwise:

```bash
# IT vs IR from drive states, and ONLY where a state actually proves one.
#   Onln/Optl -> IR: those drives are members of a configured RAID volume, so
#                    a RAID layer exists.
#   JBOD      -> IT: JBOD is the state IT firmware reports for a bare disk.
#   UGood/UBad -> NOTHING. "Unconfigured" is equally true of a bare disk on an
#                    IT-only HBA and on an IR card with no arrays. Issue #10:
#                    an IT-flashed SAS9305-16i (a card with no IR firmware in
#                    existence) reports 13x UGood and was being labelled IR.
#                    An empty mode hides the row, which beats a confident lie.
# storcli's brief `show` carries no personality field — `show all` has
# "Enable JBOD" but the overview path never runs it. Checked on the reporter's
# own capture; do not go looking for one here again.
if   printf '%s\n' "$DSTATES" | grep -qiE '^(Onln|Optl)$'; then MODE="IR"
elif printf '%s\n' "$DSTATES" | grep -qiE '^JBOD$';        then MODE="IT"
else MODE=""; fi
```

Order matters: a MegaRAID card with some JBOD and some array members must
report IR, so `Onln|Optl` is tested first.

**Verify**:
```
bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh && echo LINT-OK
P=source/usr/local/emhttp/plugins/hbaviewer/scripts/parse
bash $P/storcli_overview.sh 80 < <(cat tests/fixtures/storcli/overview_9305.txt tests/fixtures/storcli/temp_9305.txt) | grep -o '"mode":"[^"]*"'
```
→ `"mode":""`  (was `"mode":"IR"`)

And the 9400 must be unchanged:
```
bash $P/storcli_overview.sh 80 < <(cat tests/fixtures/storcli/overview_c0.txt tests/fixtures/storcli/temp_c0.txt) | grep -o '"mode":"[^"]*"'
```
→ `"mode":"IT"`

### Step 3 (Part B): extract AdapterType in the composer

In `ov_storcli`, derive the chip from the global controller list and pass it
as the third argument. `storcli show` is one extra light call; if you can
reuse output the function already has, do — but do not restructure
`hba_each`.

Required parsing rule (see "The data the fix needs" above for why positional
fields are wrong):

```bash
# storcli's controller list names the chip outright, which beats a device-ID
# map that only knows five chips (issue #10: an 0xC4 / SAS3224 fell through it
# and the Overview showed no chip at all). Model contains a space on some cards
# ("HBA 9400-16i") and not others ("SAS9305-16i"), so the AdapterType column is
# NOT at a fixed field index — cut at the first 0x and take the last token
# before it, then drop any "(B0)" revision suffix.
chip=$("$STORCLI" show 2>/dev/null \
     | awk -v c="$1" '$1 == c { sub(/[[:space:]]*0x.*$/, ""); print $NF; exit }' \
     | sed 's/(.*)//')
```

Then pass it: replace the empty third argument with `"$chip"`.

**Verify**:
```
bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh && echo LINT-OK
grep -n 'storcli_overview.sh" "$ALERT" "$perr"' source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh
```
→ the third argument must no longer be `""`.

Test the extraction rule directly against both real layouts:
```
printf '  0 HBA 9400-16i   SAS3416(B0) 0x1000  0xAC    0x1000   0x3000 00:c1:00:00\n' \
  | awk -v c=0 '$1 == c { sub(/[[:space:]]*0x.*$/, ""); print $NF; exit }' | sed 's/(.*)//'
printf '  0 SAS9305-16i   SAS3224(A1) 0x1000  0xC4    0x1000   0x3190 00:01:00:00\n' \
  | awk -v c=0 '$1 == c { sub(/[[:space:]]*0x.*$/, ""); print $NF; exit }' | sed 's/(.*)//'
```
→ `SAS3416` and `SAS3224`

### Step 4: golden cases

Add to `tests/run.sh`, with provenance comments in the house style:

```bash
# Real `/c0 show` + `/c0 show temperature` from issue #10 (@PaliKinG3), an
# IT-FLASHED SAS9305-16i reporting 13x UGood. Before plan 045 this card was
# labelled IR: UGood means "unconfigured", not "IR firmware". Mode must be ""
# — no IR firmware exists for a 9305-16i, and an empty mode hides the row
# rather than stating a falsehood.
check storcli-overview-9305 storcli_overview_9305.json bash "$P/storcli_overview.sh" 80 < <(cat fixtures/storcli/overview_9305.txt fixtures/storcli/temp_9305.txt)
# AdapterType passed by the composer wins over the device-ID map (plan 045
# Part B). 0xC4 is deliberately NOT in that map — this is the case that map
# could never have handled.
check storcli-overview-chiparg storcli_overview_chiparg.json bash "$P/storcli_overview.sh" 80 0 "SAS3224" < <(cat fixtures/storcli/overview_9305.txt fixtures/storcli/temp_9305.txt)
```

Then regenerate and **read the diff**:

```
UPDATE=1 bash tests/run.sh
git diff tests/expected/
```

Exactly one pre-existing golden may change:
`storcli_overview_noencl_ugood.json`, `"mode":"IR"` → `"mode":""`. That is
Part A working — `@t0ffemannen`'s SAS3008 shows only `UGood`, so its
personality was never actually known. **Any other pre-existing golden
changing is a STOP condition.** Revert trailing-newline-only churn.

```
bash tests/run.sh 2>&1 | grep '^FAIL' | sort > /tmp/after-fails.txt
diff /tmp/baseline-fails.txt /tmp/after-fails.txt && echo "NO NEW FAILURES"
```

## Test plan

- New goldens: `storcli-overview-9305` (mode `""`, chip from the ID-map
  fallback) and `storcli-overview-chiparg` (chip `SAS3224` from the passed
  AdapterType).
- Changed golden: `storcli_overview_noencl_ugood.json` mode `IR` → `""`.
- Unchanged: every 9400-based golden keeps `"mode":"IT"` and its existing chip.
- **Mutation check** — after the suite is green, run each, confirm, restore,
  and **report all four**:
  1. Revert the MODE block to the old rule → `storcli-overview-9305` and
     `storcli-overview-noencl-ugood` must both fail.
  2. Reorder the new rule to test `JBOD` before `Onln|Optl` → report whether
     any case fails. **Expect none** — no fixture contains both, so this
     records a real coverage gap rather than a pass. Say so plainly.
  3. Change the awk extraction to a positional `$3` → the 9400 line in Step
     3's direct test must yield `9400-16i` instead of `SAS3416`.
  4. Pass `""` as the third argument in the composer again → nothing in the
     suite fails, because the goldens call the parser directly. Report this:
     it means **the composer's argument wiring has no test**, which is exactly
     how Defect B survived. Do not add one by restructuring the composer;
     just record it.

## Done criteria

- [ ] `bash -n` clean on both modified shell files
- [ ] The 9305 fixture yields `"mode":""` and the 9400 fixtures still yield
      `"mode":"IT"`
- [ ] `bash $P/storcli_overview.sh 80 0 "SAS3224" < 9305 fixture` yields
      `"model":"SAS3224"`
- [ ] The composer's third argument to `storcli_overview.sh` is no longer `""`
- [ ] Step 3's two direct awk tests print `SAS3416` and `SAS3224`
- [ ] `git diff tests/expected/` shows **only** `storcli_overview_noencl_ugood.json`
      changed among pre-existing goldens, and only its `mode` field
- [ ] `bash tests/run.sh` adds no failure name absent from the Step 0 baseline
- [ ] The five device-ID map entries are all still present
- [ ] `git status --short` lists only files from the In-scope list

## STOP conditions

- The base check prints BASE-STALE and `git rebase dev` conflicts.
- The drift check prints anything.
- Any pre-existing golden other than `storcli_overview_noencl_ugood.json`
  changes, or that one changes in any field other than `mode`.
- You conclude a personality field *does* exist in brief `storcli show`.
  It does not on the reporter's card — if you think you have found one, stop
  and show the line rather than building on it.
- You are tempted to delete the device-ID map now that AdapterType is passed.
  Keep it: a storcli build whose list output does not parse still needs it.
- You are tempted to make `UGood` mean IT instead of IR. It means neither.
  The whole point of Part A is that this state carries no personality
  information at all.

## Maintenance notes

- **`mode` is now three-valued: `IT`, `IR`, or absent.** Absent means "no
  positive evidence", not "not applicable". Both renderers already hide an
  empty mode, so nothing downstream needs changing — but anything that later
  consumes `mode` (an export field, a notification) must treat `""` as
  unknown rather than defaulting it.
- **`@t0ffemannen`'s SAS3008 golden loses its `IR` label and that is correct.**
  It was never verified; it was the old rule's output being frozen as an
  expectation. If a reporter ever confirms an actual IR-firmware card, add it
  as a fixture with `Onln`/`Optl` states — this project currently has no
  capture containing either.
- **The composer→parser argument list is untested end to end.** Defect B was a
  hardcoded `""` sitting next to a comment describing what should have been
  there, and no test could see it because every golden invokes the parser
  directly. Any new positional argument is exposed to the same failure.
- **Part B removes the device-ID map's importance but not the map.** As more
  cards appear the map will fall further behind; AdapterType will not.

## INLINED CAPTURES

The executor's dispatch message includes the verbatim contents of
`plans/assets/issue10-9305-16i-show.txt` and
`plans/assets/issue10-9305-16i-temperature.txt` for Step 1. If they are absent
from both the message and the worktree, STOP — do not hand-author a fixture
for a card nobody here owns.
