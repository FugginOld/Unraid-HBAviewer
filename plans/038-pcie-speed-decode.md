# Plan 038: Decode IOUnit Page 7 `PCIeSpeed` as an enum, not a bitmask

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat c8d4a5b..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/hba.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_health.sh tests/fixtures/hba_ioc.txt tests/fixtures/hba_ioc_notemp.txt`
> Expected output: **nothing**. Every excerpt below is quoted from `c8d4a5b`
> (tip of `dev`). Any difference is a STOP condition.

## Status

- **Priority**: **P1** — wrong hardware fact on the Overview, the dashboard
  tile and the Health tab. Closes issue #9.
- **Effort**: S
- **Risk**: LOW. Two `case` tables and two test fixtures. No UI, no PHP, no
  flash path.
- **Depends on**: nothing. Branch `advisor/038-pcie-speed-decode` from `dev`.
- **Category**: correctness / lsiutil backend
- **Planned at**: `c8d4a5b`, 2026-08-02
- **Reported by**: @jac2424 (issue #9), SAS9207-8i / SAS2308

## What changes

The lsiutil backend decodes MPI2 IO Unit Page 7's `PCIeSpeed` field with a
table that is **off by one position**, because it was written as if the field
were a bitmask like the `PCIeWidth` field next to it. It is an enum.

From the kernel's `drivers/scsi/mpt3sas/mpi/mpi2_cnfg.h` — the same header the
`PCIeWidth` values were correctly taken from:

```c
/*defines for IO Unit Page 7 PCIeWidth field */
#define MPI2_IOUNITPAGE7_PCIE_WIDTH_X1        (0x01)
#define MPI2_IOUNITPAGE7_PCIE_WIDTH_X2        (0x02)
#define MPI2_IOUNITPAGE7_PCIE_WIDTH_X4        (0x04)
#define MPI2_IOUNITPAGE7_PCIE_WIDTH_X8        (0x08)
#define MPI2_IOUNITPAGE7_PCIE_WIDTH_X16       (0x10)

/*defines for IO Unit Page 7 PCIeSpeed field */
#define MPI2_IOUNITPAGE7_PCIE_SPEED_2_5_GBPS  (0x00)
#define MPI2_IOUNITPAGE7_PCIE_SPEED_5_0_GBPS  (0x01)
#define MPI2_IOUNITPAGE7_PCIE_SPEED_8_0_GBPS  (0x02)
#define MPI2_IOUNITPAGE7_PCIE_SPEED_16_0_GBPS (0x03)
#define MPI2_IOUNITPAGE7_PCIE_SPEED_32_0_GBPS (0x04)
```

Width is a one-hot bitmask. Speed is a plain index. The shipped table treats
both as bitmasks, so every reported speed lands one generation low, and a
genuine Gen1 link (`0x00`) matches nothing and renders as an empty string —
the row silently disappears rather than reading "Gen1".

| Reported | Shipped table says | Truth |
|---|---|---|
| `0x00` | *(nothing rendered)* | Gen1 (2.5 GT/s) |
| `0x01` | Gen1 (2.5 GT/s) | Gen2 (5.0 GT/s) |
| `0x02` | Gen2 (5.0 GT/s) | **Gen3 (8.0 GT/s)** ← issue #9 |
| `0x04` | Gen3 (8.0 GT/s) | Gen5 (32.0 GT/s) |

### The evidence is a matched pair from one box

Issue #9's reporter is the same person and the same controller as issue #3's,
and `plans/README.md` records that box's verified output from plan 010:

```json
{"model":"SAS2308","board_name":"SAS9207-8i","pcie_width":"x4","pcie_speed":"Gen2 (5.0 GT/s)"}
```

Their `lspci` on the same card:

```
LnkCap: Port #0, Speed 8GT/s, Width x8, ASPM L0s, Exit Latency L0s <64ns
LnkSta: Speed 8GT/s, Width x4 (downgraded)
```

**Width matches (x4 both ways); speed is exactly one generation low.** That is
the whole diagnosis in one capture: the two fields sit side by side in the same
config page and are read by the same code, so a decode that gets one right and
the other consistently one notch low is an encoding mistake, not a hardware or
firmware quirk. The card negotiates 8 GT/s, reports `0x02`, and we print Gen2.

The `x4 (downgraded)` is real and is the user's own slot wiring — not our bug,
and not something this plan changes.

### Not in this plan

- **The storcli backend is correct.** `get_hba_info.sh`'s `ov_storcli` reads
  sysfs `current_link_speed` ("8.0 GT/s PCIe") and string-matches it. Untouched.
- **Issue #5** (PaliKinG3, PCIe fields blank on the storcli backend) is a
  different defect on that sysfs path, addressed by plan 013. Not this.

## Current state

### `parse/hba.sh:29-35` — Overview, dashboard tile, Performance tab

```bash
PCIE_SPEED_HEX=$(parse_hex "PCIeSpeed:")
case "${PCIE_SPEED_HEX,,}" in
    0x01) PCIE_SPEED="Gen1 (2.5 GT/s)" ;;
    0x02) PCIE_SPEED="Gen2 (5.0 GT/s)" ;;
    0x04) PCIE_SPEED="Gen3 (8.0 GT/s)" ;;
    *)    PCIE_SPEED="" ;;
esac
```

### `get_hba_health.sh:128-139` — Health tab, second copy of the same table

```bash
    # lsiutil reports current width/speed as a bitmask hex code (same decode
    # parse/hba.sh uses); it has no max_link_width/max_link_speed query at
    # all, so max stays 0/"" and host_link never false-flags a card it can't
    # fully read.
    width_hex=$(grep "PCIeWidth:" "$IOC" | grep -oE '0x[0-9A-Fa-f]+' | head -1)
    case "${width_hex,,}" in
        0x01) width=1 ;; 0x02) width=2 ;; 0x04) width=4 ;; 0x08) width=8 ;; 0x10) width=16 ;; *) width=0 ;;
    esac
    speed_hex=$(grep "PCIeSpeed:" "$IOC" | grep -oE '0x[0-9A-Fa-f]+' | head -1)
    case "${speed_hex,,}" in
        0x01) speed="2.5 GT/s" ;; 0x02) speed="5.0 GT/s" ;; 0x04) speed="8.0 GT/s" ;; *) speed="" ;;
    esac
```

This one feeds `health.php`'s `host_link` indicator. It cannot currently
false-flag a downtrain, because `max_speed` stays `""` on this backend and the
comparison needs both — but the **displayed** speed is wrong in the same way,
and the comment claiming a bitmask is the reason both tables are wrong.

### Why two tables and not one shared helper

`parse/hba.sh` is a **pure parser**: it takes three captured files as
arguments, sources nothing, and is invoked directly by `tests/run.sh` with no
plugin environment. `get_hba_health.sh` is a composer that sources `lib.sh`.
Hoisting a shared decoder into `lib.sh` would make the pure parser depend on
the plugin runtime and break its own test harness — a worse trade than two
tables. **Keep them in sync by cross-reference comment**, which this plan adds.

## Scope

**In scope**:

- `source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/hba.sh` — the speed table
- `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_health.sh` — the speed table and the wrong comment
- `tests/fixtures/hba_ioc.txt`, `tests/fixtures/hba_ioc_notemp.txt` — correct the fabricated hex
- `tests/fixtures/hba_ioc_gen1.txt` (new), `tests/expected/hba_gen1.json` (new), `tests/run.sh` — one new golden
- `plans/README.md` — status row

**Out of scope — do not touch**:

- The `PCIeWidth` tables in either file. They match the header and are
  confirmed by hardware (`x4` = `0x04` on the reporter's box).
- `CurrentPowerMode` in `parse/hba.sh:37-43` — see "Follow-ups".
- `get_hba_info.sh`'s `ov_storcli` sysfs decode.
- `health.php`, `view.php`, `ajax_info.php`, `dashboard.php` — the value flows
  through them as an opaque string.
- Any existing golden's contents. **A golden moving is a STOP condition**, not
  something to bless.

## Steps

### Step 1: fix `parse/hba.sh`

Replace the speed block quoted above with:

```bash
# IOUnit Page 7 PCIeSpeed is an ENUM (0,1,2,3,4), unlike PCIeWidth directly
# above it, which is a one-hot bitmask. Reading it as a bitmask reported every
# card one generation low and rendered nothing at all for Gen1 (issue #9).
# Values per mpi2_cnfg.h MPI2_IOUNITPAGE7_PCIE_SPEED_*. Compared numerically so
# a firmware that pads the field (0x0002) decodes the same as 0x02.
# Keep in sync with the same table in scripts/get_hba_health.sh.
PCIE_SPEED_HEX=$(parse_hex "PCIeSpeed:")
PCIE_SPEED=""
if [ -n "$PCIE_SPEED_HEX" ]; then
    case "$((16#${PCIE_SPEED_HEX#0x}))" in
        0) PCIE_SPEED="Gen1 (2.5 GT/s)"  ;;
        1) PCIE_SPEED="Gen2 (5.0 GT/s)"  ;;
        2) PCIE_SPEED="Gen3 (8.0 GT/s)"  ;;
        3) PCIE_SPEED="Gen4 (16.0 GT/s)" ;;
        4) PCIE_SPEED="Gen5 (32.0 GT/s)" ;;
    esac
fi
```

Two deliberate choices, do not "simplify" them away:

- **Numeric comparison, not string match.** Issue #3's reporter posted
  `IOCTemperature: 0x002F` from a direct run of the bundled binary, so a
  zero-padded field is a real possibility on some firmware. A string `case`
  silently yields `""` there; `$((16#…))` does not. The `-n` guard exists
  because `$((16#))` on an empty string is a fatal arithmetic error, which
  would take the whole parser down on a card that reports no such field.
- **Gen4 and Gen5 are listed even though no MPI2 card can report them.**
  They are in the header, they cost two lines, and `0x04` previously rendered
  as "Gen3" — leaving it unmapped would keep a stale fixture or an odd firmware
  reading plausibly instead of visibly wrong.

### Step 2: fix `get_hba_health.sh`

Same correction, in that file's vocabulary (bare `"8.0 GT/s"`, no `Gen` prefix
— that string goes into the `link.speed` JSON field consumed by `health.php`,
**do not change its format**):

```bash
    # lsiutil has no max_link_width/max_link_speed query, so max stays 0/""
    # and host_link never false-flags a card it can't fully read.
    # PCIeWidth is a one-hot bitmask; PCIeSpeed is an enum (mpi2_cnfg.h,
    # MPI2_IOUNITPAGE7_*). They are NOT the same encoding — see plan 038.
    # Keep the speed table in sync with scripts/parse/hba.sh.
    width_hex=$(grep "PCIeWidth:" "$IOC" | grep -oE '0x[0-9A-Fa-f]+' | head -1)
    case "${width_hex,,}" in
        0x01) width=1 ;; 0x02) width=2 ;; 0x04) width=4 ;; 0x08) width=8 ;; 0x10) width=16 ;; *) width=0 ;;
    esac
    speed_hex=$(grep "PCIeSpeed:" "$IOC" | grep -oE '0x[0-9A-Fa-f]+' | head -1)
    speed=""
    if [ -n "$speed_hex" ]; then
        case "$((16#${speed_hex#0x}))" in
            0) speed="2.5 GT/s" ;; 1) speed="5.0 GT/s"  ;; 2) speed="8.0 GT/s" ;;
            3) speed="16.0 GT/s" ;; 4) speed="32.0 GT/s" ;;
        esac
    fi
```

The `width` table is quoted unchanged so the diff is reviewable in place —
**do not edit it**.

### Step 3: correct the two fabricated fixtures

Both `PCIeSpeed` values in the fixtures were reverse-engineered from the wrong
table, so they describe hardware that cannot exist. Fix the *inputs* and the
goldens stay exactly where they are — which is the point.

**`tests/fixtures/hba_ioc.txt`**: `PCIeSpeed: 0x04` → `0x02`.

That fixture is a SAS9207-8i (see `tests/expected/hba_normal.json`:
`"board_name": "SAS9207-8i"`), a PCIe 3.0 card. Under the correct table `0x04`
claims 32 GT/s — Gen5, on a 2012 SAS2 card. `0x02` is what the real card
reports, per issue #9's capture, and keeps the golden's
`"pcie_speed": "Gen3 (8.0 GT/s)"` true.

**`tests/fixtures/hba_ioc_notemp.txt`**: `PCIeSpeed: 0x02` → `0x01`.

That case exists to cover a card with no temperature sensor; its golden says
Gen2 and there is no reason to change what it represents. `0x01` is Gen2.

### Step 4: add the regression golden

Steps 1–3 together leave every existing golden byte-identical, so on their own
they add **no** regression protection. This case is what pins the fix.

New fixture `tests/fixtures/hba_ioc_gen1.txt` — same as `hba_ioc.txt` but at
Gen1, the value the old table could not represent at all:

```
IOUnit Page 7:

  IOCTemperature:                   0x2f
  PCIeWidth:                        0x08
  PCIeSpeed:                        0x00
  CurrentPowerMode:                 0x00
```

New line in `tests/run.sh`, next to the other `hba-*` cases:

```bash
# PCIeSpeed is an enum, not a bitmask (plan 038): 0x00 is Gen1, and under the
# old bitmask table it matched nothing and rendered an empty string.
check hba-gen1     hba_gen1.json     bash "$P/hba.sh" fixtures/hba_ioc_gen1.txt fixtures/hba_banner.txt fixtures/hba_board.txt 80
```

Create the expected file by capturing that exact command's output — **not**
with `UPDATE=1`, which rewrites *every* golden in the suite and would hide a
real regression elsewhere:

```bash
cd tests
bash ../source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/hba.sh \
  fixtures/hba_ioc_gen1.txt fixtures/hba_banner.txt fixtures/hba_board.txt 80 \
  > expected/hba_gen1.json
```

Then **read the file** and confirm `"pcie_speed": "Gen1 (2.5 GT/s)"` before
committing it. A golden captured from broken code is worse than no golden.
Note the runner writes goldens with no trailing newline; capturing this way
matches that.

## Test plan

- `bash tests/run.sh` → `--- all pass ---`, including the new `hba-gen1`.
- `git diff -- tests/expected/` shows **only the new** `hba_gen1.json`. No
  existing golden may move.
- **Mutation check** — restore the old bitmask table in `parse/hba.sh` alone
  and re-run: `hba-gen1` must fail **and** `hba-normal` / `hba-notemp` /
  `hba-p16` must fail too (they now carry corrected fixtures). Revert. If
  `hba-gen1` passes with the old table, the fixture or the golden is wrong.
- `bash -n` clean on both changed shell files.
- **`get_hba_health.sh`'s lsiutil branch has no automated coverage** — there is
  no lsiutil IOC stub in the suite, only the events stub. Say so in your report
  rather than letting a green run imply the Health tab was tested. Verify that
  file by reading the diff against `parse/hba.sh`'s table, value for value.

## Done criteria

- [ ] `parse/hba.sh` maps `0x00→Gen1, 0x01→Gen2, 0x02→Gen3, 0x03→Gen4, 0x04→Gen5`
- [ ] `get_hba_health.sh` maps the same five to `2.5/5.0/8.0/16.0/32.0 GT/s`,
      keeping the bare-number string format
- [ ] Neither `PCIeWidth` table changed
- [ ] `hba_ioc.txt` = `0x02`, `hba_ioc_notemp.txt` = `0x01`, new
      `hba_ioc_gen1.txt` = `0x00`
- [ ] `bash tests/run.sh` → `--- all pass ---`
- [ ] `git diff -- tests/expected/` lists only `hba_gen1.json` (added)
- [ ] Mutation check behaves as described above
- [ ] `git diff --name-only` lists only: the two scripts, three fixtures, one
      new golden, `tests/run.sh`, and `plans/`

## STOP conditions

- The drift check prints anything.
- Any existing file under `tests/expected/` changes.
- A `PCIeWidth` table changes.
- `health.php`, `view.php` or any renderer appears in the diff — the string
  passes through them untouched and any change there is out of scope.
- `UPDATE=1 bash tests/run.sh` is run at any point.
- The `link.speed` JSON string gains a `Gen…` prefix. `health.php` consumes
  that field; `parse/hba.sh`'s `pcie_speed` is the display string and the two
  formats are deliberately different.

## Follow-ups this plan does not do

- **`CurrentPowerMode` — SETTLED 2026-08-02, leave it alone.** @jac2424's capture
  from a running SAS2308 reads `CurrentPowerMode: 0x00` (and
  `PreviousPowerMode: 0x00`, `PowerManagementCapabilities: 0x0000010C`) on a card
  that is plainly operational. So `0x00` is what an MPI2.0 card reports for a
  live controller, the MPI2.5 `PM_MODE_UNAVAILABLE` reading does not apply here,
  and the shipped `0x00 → Full` mapping produces a truthful display. **Do not
  "fix" it to the MPI2.5 values** — that would print "unavailable" for every
  healthy SAS2 card. The original reasoning is kept below for whoever revisits
  this on an MPI2.5 card, where the answer may differ.
- ~~**`CurrentPowerMode` is probably decoded wrong too.**~~ `parse/hba.sh:37-43`
  maps `0x00→Full, 0x08→Reduced, 0x10→Standby`; the header's
  `MPI25_IOUNITPAGE7_PM_MODE_*` values are `0x04→Full, 0x05→Reduced,
  0x06→Standby` under mask `0x07`, and `0x00` is `PM_MODE_UNAVAILABLE` — the
  value our table calls "Full". But those defines are **MPI2.5-only**, and a
  SAS2 (MPI2.0) card may legitimately report `0x00`, in which case printing
  "Full" for a live card is defensible. **No user report, no capture — do not
  guess.** Settle it by asking issue #9's reporter for
  `hbaviewer.x86_64 -p1 -a 25,2,0,0` on their running card: if it shows
  `CurrentPowerMode: 0x04` while we print "Full", our table is wrong; if `0x00`,
  it is a MPI2.0 field and should stay.
- **That same capture would replace `hba_ioc.txt` with real output** rather
  than the corrected-but-still-synthetic file this plan leaves. Same argument
  as plan 036 makes for the `UGood` fixtures.
- **The width tables still string-match padded hex** — and the hazard is now
  **confirmed real, not hypothetical.** The same capture shows lsiutil printing
  fields at *different* widths in one block: `PCIeWidth: 0x04` and
  `CurrentPowerMode: 0x00` at two digits, but `IOCTemperature: 0x0030` and
  `BoardTemperature: 0x0000` at four. So a firmware that pads `PCIeWidth` the
  way this one pads `IOCTemperature` would decode to width 0 and render nothing.
  Still left alone here — this card prints `0x04`, so there is no live bug, and
  the numeric comparison this plan introduced for speed is the pattern to copy
  when someone does hit it. Temperature was never at risk: it goes through
  `$((16#…))` already.

## Maintenance notes

- **Width and speed live one line apart in the same config page and use
  different encodings.** That adjacency is what produced this bug, and it will
  produce it again. Both tables now say so in a comment; keep the comments if
  the code moves.
- **The lsiutil path has never run on the maintainer's hardware** (see
  `plans/README.md`, "Scope limit on every verified-on-hardware claim"). The
  only real-world exercise it gets is issue #9's reporter — which is exactly
  how this bug was found. Flag the change in the release notes and ask them to
  confirm the Overview reads Gen3 after upgrading.
