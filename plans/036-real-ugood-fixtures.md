# Plan 036: Replace the synthetic `UGood` fixtures with a reporter's real output

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 20d2142..HEAD -- tests/run.sh tests/fixtures/storcli source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_drives.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh`
> Expected output: **nothing**. Every excerpt below is quoted from `20d2142`
> (`dev` tip, 2026-08-02). Any difference is a STOP condition.
>
> **Baseline re-stamped twice.** Written against `4338f68`; plan 026 later
> appended a bundle block to `tests/run.sh`, and plan 038 added one `hba-gen1`
> check line. **The three `check` lines this plan quotes are still at lines 33,
> 56 and 57**, and the parsers and every fixture remain untouched.
>
> ## The reporter's output — already fetched and verified for you
>
> **Do not re-fetch, do not retype, do not reformat.** The verbatim text is
> saved at:
>
> `C:/Users/Joe/AppData/Local/Temp/claude/c--Users-Joe-Documents-GitHub-Unraid-HBAviewer/195733c9-54c9-4142-a60f-a02033ab0418/scratchpad/ugood_real.txt`
>
> (774 lines, 22,433 bytes. Provenance: issue #5, @t0ffemannen, comment
> `2026-08-01T19:59:16Z`. Re-fetchable with
> `gh issue view 5 --repo FugginOld/Unraid-HBAviewer --json comments -q '.comments[] | select(.createdAt=="2026-08-01T19:59:16Z") | .body'`
> — but only if the file is missing, and byte-compare it if you do.)
>
> **That one comment holds three separate storcli invocations back to back.**
> Slice it by line number; do not search for markers:
>
> | Lines | Invocation | Becomes |
> |---|---|---|
> | 1–56 | `/c0 show` — `Physical Drives = 8`, `PD LIST`, legend block | the overview fixture |
> | 58–73 | `/c0 show temperature` — `ROC temperature(Degree Celsius) 56` | the temperature fixture |
> | 75–774 | `/c0/sall show all` — eight `Drive /c0/sN :` blocks | the drives fixture |
>
> **Three traps that will otherwise waste a round:**
>
> 1. **Other comments on the same issue contain a different, single-drive
>    `PD LIST` with different spacing.** Selecting by author or by date alone
>    picks up the wrong one. The `createdAt` above is exact.
> 2. **Each per-drive block repeats its own `EID:Slt` row and legend.** So over
>    the whole comment `grep -c 'UGood-Unconfigured Good'` is **9**, not 1, and
>    `grep -cE '^ :[0-9]+ +[0-9]+ UGood'` is **16**, not 8. Within lines 1–56
>    those counts are 1 and 8. Do not "fix" a count that looks wrong until you
>    have checked which range you are counting.
> 3. **The drives section ends with raw hex inquiry dumps** (lines of
>    `20 20 20 … 80`). That is genuine `show all` output. Keep it — the plan's
>    rule is verbatim, and a trimmed fixture is no longer evidence.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW — test data only, no source change expected
- **Depends on**: none (017 is merged and confirmed)
- **Category**: test coverage
- **Planned at**: `20d2142`, 2026-08-02 (re-stamped; originally `4338f68`)
- **Requested by**: maintainer, after @t0ffemannen supplied real controller
  output on issue #5

## Why this matters

Plan 017 fixed the enclosure-less / IR-firmware case that made the Drives tab
empty on several reporters' cards. Its `UGood` fixture was **modelled on**
output pasted into the issue thread rather than captured from a machine.

@t0ffemannen has now supplied the genuine article from a SAS3008 running IR
firmware — the exact configuration the fix targets, and the system on which the
fix was confirmed working on 2026-08-01.

**The good news first: the synthetic fixture was correct.** Comparing the real
output against `tests/fixtures/storcli/drives_noencl_ugood.txt` shows the same
shape, the same column spacing, the same `ST4000VN008-2DR166` model and
`3.638 TB` size. This plan is not a correction. It closes two gaps in
*coverage*.

## The two gaps

### 1. The drives fixture has one drive; the real card has eight

```
$ grep -c '^Drive /c0/s[0-9]* :' tests/fixtures/storcli/drives_noencl_ugood.txt
1
```

A single-block fixture cannot catch a loop that mishandles the second, the
last, or a non-contiguous slot number. That is precisely the class of bug plan
017 existed to fix, so the regression test should exercise it.

The real card's slots are `s0`–`s7` with **`DID` values out of order** (`4` at
slot `s6`, `5` at `s4`), which is a useful extra: it pins that the parser keys
on slot, not on device id or row order.

### 2. There is no real overview fixture for this case

`storcli_overview.sh` is fed `/c0 show` plus `/c0 show temperature`:

```bash
check storcli-overview storcli_overview.json bash "$P/storcli_overview.sh" 80 < <(cat fixtures/storcli/overview_c0.txt fixtures/storcli/temp_c0.txt)
```

`overview_c0.txt` mentions `UGood` once. The real paste contains a full
`PD LIST` of **eight blank-EID `UGood` rows followed by the legend block**:

```
EID-Enclosure Device ID|Slt-Slot No|DID-Device ID|DG-DriveGroup
UGood-Unconfigured Good|UBad-Unconfigured Bad|Intf-Interface
```

**That legend line is the exact text that caused the MODE false-match plan 017
fixed** — a whole-output grep for the drive states matched the legend rather
than the data, so MODE was derived from a key rather than from any drive. The
suite has never run against a real one.

The same paste carries `ROC temperature(Degree Celsius) 56`, so one fixture
pair exercises mode detection, drive-state rollup and temperature together.

## Scope

**In scope**:

- Extend `tests/fixtures/storcli/drives_noencl_ugood.txt` to the reporter's
  full eight-drive output, or add a second fixture alongside it — **decide in
  Step 1 and say which and why.**
- Add a real overview fixture pair for the IR / `UGood` / enclosure-less case:
  the `/c0 show` body and the `/c0 show temperature` body, as two files
  matching the existing `overview_*.txt` / `temp_*.txt` split.
- Register the new checks in `tests/run.sh` following the existing `check`
  convention.
- Bless the new goldens **once**, after confirming by eye that each is correct.

**Out of scope**:

- **Any change to `storcli_drives.sh` or `storcli_overview.sh`.** This plan
  adds coverage to code that is already confirmed working on the reporter's
  hardware. If a new fixture makes an existing golden move, that is a finding,
  not something to fix here — see STOP conditions.
- The `drives_noencl_jbod.txt` fixture (the IT-firmware case). Unchanged.
- Any other tab, parser or renderer.

## Data — redaction already done by the reporter

The reporter blanked serial numbers and WWNs. Keep them blanked. Preserve
**field widths and column alignment exactly** — the parsers key on column
structure, so a fixture with re-flowed spacing tests something the real world
never produces.

The real output begins:

```
CLI Version = 007.3404.0000.0000 April 18, 2025
Operating system = Linux 6.18.38-Unraid
Controller = 0
Status = Success
Description = None

Product Name = LSI SAS3008
...
Board Name = LSI SAS3008
Physical Drives = 8

PD LIST :
=======

----------------------------------------------------------------------------
EID:Slt DID State DG      Size Intf Med SED PI SeSz Model                Sp 
----------------------------------------------------------------------------
 :0       0 UGood -   3.638 TB SATA HDD -   N  512B ST4000VN008-2DR166   -  
 :1       1 UGood -   3.638 TB SATA HDD -   N  512B ST4000VN008-2DR166   -  
 :2       2 UGood -   3.638 TB SATA HDD -   N  512B ST4000VN008-2DR166   -  
 :3       3 UGood -   3.638 TB SATA HDD -   N  512B ST4000VN008-2DR166   -  
 :4       5 UGood -  16.371 TB SATA HDD -   N  512B WDC  WUH721818ALE6L4 -  
 :5       6 UGood -  16.371 TB SATA HDD -   N  512B WDC  WUH721818ALE6L4 -  
 :6       4 UGood -  20.009 TB SATA HDD -   N  512B TOSHIBA MG10AFA22TE  -  
 :7       7 UGood -  16.371 TB SATA HDD -   N  512B WDC  WUH721818ALE6L4 -  
----------------------------------------------------------------------------
```

Note `WDC  WUH721818ALE6L4` contains a **double space inside the model name**.
Preserve it. A parser that splits on whitespace runs rather than on column
position would mis-field that row, and this fixture is the only thing that
would catch it.

The full text is in issue #5, comment by @t0ffemannen dated 2026-08-01. Take
it from there verbatim rather than retyping it — retyping is what this plan
exists to stop doing.

## Steps

### Step 1: decide extend vs. add

Extending the existing fixture changes an existing golden, which must then be
re-blessed — acceptable, but the diff must be reviewed line by line to confirm
every new drive appears and nothing else moved.

Adding a second fixture leaves the existing golden untouched, so any movement
in it is unambiguously a regression.

**Recommendation: add a second fixture** (`drives_noencl_ugood8.txt` or
similar) and leave the one-drive case in place. The single-drive case is a
legitimate edge in its own right, and keeping both means the existing golden
stays a stable control.

### Step 2: the fixtures

Save the reporter's output as the new files. Verify byte-for-byte that column
alignment survived the copy:

```bash
awk '{ print length }' tests/fixtures/storcli/<newfile> | sort -u | head
```

Compare against the same command on the issue text. Trailing whitespace is
significant here — storcli pads its columns.

### Step 3: register and bless

Add `check` lines to `tests/run.sh` mirroring lines 56–57's shape, then
`UPDATE=1 bash tests/run.sh` **once**, and read every new golden before
committing it.

**Verify**: `bash tests/run.sh` → `--- all pass ---`, and
`git diff -- tests/expected/` shows **only the new golden files**, no
modifications to existing ones.

## Done criteria

- [ ] An eight-drive `UGood` drives fixture exists and is registered
- [ ] A real `UGood` overview + temperature fixture pair exists and is registered
- [ ] New goldens show eight drives, `MODE` = IR, and temperature 56
- [ ] **No pre-existing golden modified** — only additions in `tests/expected/`
- [ ] No source file under `scripts/` changed
- [ ] `bash tests/run.sh` → `--- all pass ---`
- [ ] Column alignment and the double space in `WDC  WUH721818ALE6L4` preserved

## STOP conditions

- The drift check prints anything.
- **Any existing golden changes.** That would mean the real output parses
  differently from the synthetic one — a genuine finding about the parser, and
  it must be reported, not blessed away.
- Any file under `source/` appears in the diff.
- The fixture is retyped rather than copied, or column spacing is normalised.

## Maintenance notes

- **This is the only real-hardware `UGood` sample the project has.** If it is
  ever regenerated or reformatted, the value is lost. Treat it as evidence, not
  as editable test data.
- **Credit the reporter in the fixture header comment** if the file format
  allows one, so its provenance survives — a fixture whose origin is unknown
  gets "tidied" eventually.
- The related pre-P20 concern found in the same output (a SAS3 card reporting
  `FW Version = 13.00.00.00` would trip `hba.sh`'s `< 20` check if it ever
  reached the lsiutil path) is **not** part of this plan and remains unplanned.
