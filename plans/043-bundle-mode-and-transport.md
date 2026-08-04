# Plan 043: Capture IT/IR mode and drive transport in the diagnostic bundle, and guard the capture list against drift

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Base branch — UPDATED 2026-08-04: branch from `dev`.** Plan 041 is now
> **merged**, so `dev` contains the `hba_query -p"$PORT" -a 1,0` call that
> Step 1's guard test is designed to notice. The original instruction to
> branch from `advisor/041-sas2-it-ir-mode` is obsolete; that branch has been
> deleted. Run the base check first:
> `git merge-base --is-ancestor dev HEAD && echo BASE-OK || echo BASE-STALE`
> and `git rebase dev` if stale. If `grep -c 'a 1,0' source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh`
> returns `0`, you are not on a base containing 041 — that is a STOP condition.
>
> **Drift check (run first)**:
> `git diff --stat c44f030..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/bundle_support.sh`
> Expected output: **nothing**. Every excerpt below is quoted from `c44f030`.
> Any difference is a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW — two added read-only captures in a diagnostic collector,
  plus one new test. Nothing the plugin displays changes.
- **Depends on**: `plans/041-sas2-it-ir-mode.md` (see Base branch above)
- **Category**: dx | bug
- **Planned at**: `c44f030` (branch `advisor/041-sas2-it-ir-mode`), 2026-08-03

## Why this matters

The diagnostic bundle exists so a maintainer never has to hand-write a
command block for a reporter. On issue #10 that is exactly what happened
**twice**: once to get `lsiutil -a 1,0` (the IT/IR firmware personality),
and once to get the drive transport. Both were missing from the bundle, and
both were needed to diagnose the report.

`bundle_support.sh:347-351` states the rule the file is supposed to follow:

```bash
# ── Section 2: raw tool output, one file per command ─────────────────────────
# Derived by reading the composers (get_hba_info / get_phy_health /
# get_attached_drives / get_hba_health / get_event_log), not from a static list.
# A new composer means a new entry here; without one this script keeps working
# while quietly becoming incomplete.
```

"Quietly becoming incomplete" is precisely what happened — and **nothing in
the test suite enforces that rule**. `tests/anon_test.sh` builds its own
synthetic directory tree and never enumerates the real capture list;
`tests/bundle_php_test.php` covers the HTTP endpoint. A capture can go
missing forever without a single test turning red.

This plan adds the two missing captures **and** the cheap guard that would
have caught the omission, so the next composer to be added cannot silently
skip the bundle.

## Current state

### `bundle_support.sh:371-379` — the lsiutil captures and the lsblk line

```bash
if [ -x "$LSIUTIL" ]; then
    printf '0\n' | hba_query > "$B/02-raw/lsiutil_banner.txt" 2>&1
    run 02-raw/lsiutil_b.txt          hba_query -b
    run 02-raw/lsiutil_ioc.txt        hba_query -p"$PORT" -a 25,2,0,0
    run 02-raw/lsiutil_phy.txt        hba_query -p"$PORT" -a 20,12,0,0
    run 02-raw/lsiutil_osmap.txt      hba_query -p"$PORT" -a 42,0
    run 02-raw/lsiutil_eventlog.txt   hba_query -e -p"$PORT" -a 35,0
fi
run 02-raw/lsblk.txt lsblk -S -P -o NAME,WWN,SERIAL,MODEL
```

Two gaps, both confirmed by measurement on `c44f030`:

```
$ grep -rhoE '\-a [0-9]+(,[0-9]+)*' source/usr/local/emhttp/plugins/hbaviewer/scripts/*.sh | sort -u
-a 1,0
-a 20,12,0,0
-a 25,2,0,0
-a 35,0
-a 42,0

$ grep -oE '\-a [0-9]+(,[0-9]+)*' source/usr/local/emhttp/plugins/hbaviewer/scripts/bundle_support.sh | sort -u
-a 20,12,0,0
-a 25,2,0,0
-a 35,0
-a 42,0
```

`-a 1,0` is in a composer and not in the bundle. And the `lsblk` line carries
no `TRAN` column, so nothing in a bundle says whether a drive is SAS or SATA.

### `bundle_support.sh:287-291` — the `run` helper to use

```bash
run() {   # $1 = outfile, $2.. = command
    local out="$B/$1"; shift
    "$@" > "$out" 2>&1
    [ -s "$out" ] || printf '(no output from: %s)\n' "$*" > "$out"
}
```

A missing tool leaves an explanatory note rather than a zero-byte file. Use
`run` for the new capture — do not hand-roll redirection.

### `bundle_support.sh:431-449` — the bundle's own README

```bash
01-environment  kernel, Unraid + plugin version, tool presence, driver, proc_name
02-raw          raw storcli / lsiutil / lsblk output, one file per command
03-sysfs        scsi_host, sas_phy, sas_end_device and controller PCIe state
04-parsed       what each composer made of the above, plus hbaviewer.cfg
05-smart        smartctl -n standby -a per drive (only if requested)
```

The section descriptions stay accurate after this plan — **no README change
is required**, and you should not make one.

### `tests/run.sh` — the harness

Two kinds of case live here: `check <name> <expected-file> <command...>`
golden diffs, and plain shell test scripts invoked directly. Look at how
`tests/anon_test.sh` and `tests/flash_test.sh` are wired in, and follow
whichever pattern those use for a self-asserting script (they print their
own `PASS`/`FAIL` lines and exit non-zero on failure).

### Repo conventions to match

- Comments explain **why**. See `bundle_support.sh:362-364` for the tone:

```bash
        # eall/sall AND sall, both, always. They are complements, not
        # alternatives — that distinction is the entire content of plan 017,
        # and a bundle capturing only one would have been useless for #5/#6.
```

- Self-asserting test scripts are plain bash with `ok`/`bad` helpers and no
  framework. `tests/flash_test.sh:1-13` is the exemplar:

```bash
#!/bin/bash
# Self-asserting checks for flash_hba.sh: ...
#   bash tests/flash_test.sh   ->  "flash: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
fail=0
ok()  { echo "PASS  $1"; }
bad() { echo "FAIL  $1 -- $2"; fail=1; }
```

  and it ends:

```bash
echo
[ $fail -eq 0 ] && { echo "flash: all pass"; exit 0; } || { echo "flash: FAILURES"; exit 1; }
```

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Full suite | `bash tests/run.sh` | ends `--- all pass ---` |
| Shell lint | `bash -n <file>` | exit 0, no output |
| New test alone | `bash tests/bundle_coverage_test.sh` | ends `bundle-coverage: all pass`, exit 0 |

No package manager, no build step, nothing to install. `php` may not be on
PATH; the harness falls back for the PHP subset. Report which environment
you used.

## Scope

**In scope**:

- `source/usr/local/emhttp/plugins/hbaviewer/scripts/bundle_support.sh`
- `tests/bundle_coverage_test.sh` (create)
- `tests/run.sh` — one line wiring the new test in

**Out of scope** (do NOT touch):

- The bundle's `00-README.txt` heredoc at `bundle_support.sh:431-449` — the
  section descriptions are still accurate.
- `bundle_anon()` and everything above `# ── Section 1` — the anonymiser is
  a length-preserving text rewriter that handles new files generically. It
  needs no teaching about these two.
- `tests/anon_test.sh` — it builds its own synthetic tree on purpose; it is
  not an inventory of the real bundle and must not become one.
- **`bundle_support.sh:420`'s `grep 'WWN="0x'` drive filter** in the SMART
  section, and the identical filter in `scripts/collect_smart.sh`. Whether
  that silently drops a WWN-less SATA drive is a real open question, but it
  needs a reporter's `lsblk` output to answer and loosening it speculatively
  risks pulling USB sticks into the SMART tab. Adding `TRAN` to the section-2
  `lsblk` capture is what will *produce* the evidence to settle it — that is
  this plan's contribution to the question, not a fix.
- `scripts/parse/*.sh` — no parser changes here at all.

## Git workflow

- Branch: `advisor/043-bundle-mode-and-transport`, based on
  `advisor/041-sas2-it-ir-mode` (see Base branch note at the top).
- One commit. Imperative message matching `git log`, e.g.
  `Capture IT/IR mode and drive transport in the diagnostic bundle`.
- Do NOT push or open a PR.

## Steps

### Step 0: Record the pre-existing failure baseline

```
bash tests/run.sh 2>&1 | grep '^FAIL' | sort > /tmp/baseline-fails.txt
cat /tmp/baseline-fails.txt
```

Quote this list in your final report. No later run may add a name to it.

### Step 1: Write the guard test FIRST, and watch it fail

Create `tests/bundle_coverage_test.sh`. It enforces the rule
`bundle_support.sh` already states in prose: every distinct lsiutil
`-a <args>` invocation made by a composer must also be captured by the
bundle.

Required behaviour:

- Collect every `-a <digits[,digits...]>` token from
  `source/usr/local/emhttp/plugins/hbaviewer/scripts/*.sh`, **excluding
  `bundle_support.sh` itself**, sorted and de-duplicated.
- Collect the same tokens from `bundle_support.sh`.
- For each composer token missing from the bundle list, emit a `FAIL` naming
  the token; otherwise `PASS`.
- Also assert the section-2 `lsblk` capture requests a `TRAN` column, since
  that is the transport signal and has no `-a` token to be caught by the
  loop above.
- Follow `tests/flash_test.sh`'s structure exactly: `cd "$(dirname "$0")"`,
  `ok`/`bad` helpers, a final `bundle-coverage: all pass` line and exit 0,
  or `bundle-coverage: FAILURES` and exit 1.
- Header comment explaining **why this test exists** — that the bundle's
  capture list is maintained by hand, that issue #10 needed two manual
  command blocks because `-a 1,0` and the drive transport were missing, and
  that nothing else in the suite notices when a composer gains a call the
  bundle does not mirror.

**Verify — the test must FAIL before you fix anything**:
```
bash tests/bundle_coverage_test.sh; echo "exit=$?"
```
→ must report a failure naming `-a 1,0` **and** the missing `TRAN`, and exit
`1`.

**If it passes at this point, STOP.** Either you are not on the 041 base
branch (so no composer calls `-a 1,0`) or the test is not actually checking
anything. Report which.

### Step 2: Add the `-a 1,0` capture

In the `if [ -x "$LSIUTIL" ]` block, after the `lsiutil_ioc.txt` line, add
the capture with a comment in the house style — it should record that this
is main-menu option 1, that it is a plain menu item rather than an expert
one (hence no `-e`), and that it carries the IT/IR personality that issue
#10 had to be collected by hand:

```bash
    # Main-menu option 1, "Identify firmware, BIOS, and/or FCode". Plain menu
    # item, NOT expert mode, so no -e. Carries the flashed firmware image name
    # whose suffix IS the IT/IR personality ("MPTFW-20.00.07.00-IT") — issue #10
    # needed this collected by hand because the bundle did not have it.
    run 02-raw/lsiutil_ident.txt      hba_query -p"$PORT" -a 1,0
```

**Verify**:
```
bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/bundle_support.sh && echo LINT-OK
grep -c 'lsiutil_ident.txt' source/usr/local/emhttp/plugins/hbaviewer/scripts/bundle_support.sh   # -> 1
```

### Step 3: Add `TRAN` to the lsblk capture

One line, `bundle_support.sh:379`. Add the transport column and a comment
saying why it is there:

```bash
# TRAN is the SAS-vs-SATA signal. read_smart.sh already branches on it (a SAS
# log-page read does not spin a drive up; an ATA one can), but nothing recorded
# it, so no bundle could answer "are these drives SATA?" without asking.
run 02-raw/lsblk.txt lsblk -S -P -o NAME,TRAN,WWN,SERIAL,MODEL
```

`-P` emits `key="value"` pairs, so adding a column cannot shift any other
field — unlike positional output. Do not reorder the existing columns.

**Also add the per-device SAS protocol.** `lsblk`'s `TRAN` reports the
*bus*, and on 2026-08-04 a reporter's capture showed every SATA drive behind
an HBA reading `TRAN=sas` — correct kernel behaviour, useless as a drive-type
signal. The authoritative per-drive answer lives in the `sas_device` class,
which section 3 does **not** currently capture (it captures `sas_end_device`,
a different class that carries link attributes rather than protocols):

```bash
# lsblk's TRAN is the BUS, not the drive: a SATA disk behind a SAS HBA reads
# "sas". The per-drive truth is target_port_protocols in the sas_device class
# (ssp = SAS, sata = SATA). Captured because a diagnosis on 2026-08-04 needed
# it and no bundle had it — sas_end_device, already dumped below, is a
# different class and does not carry it.
dump_attrs 03-sysfs/sas_device.txt /sys/class/sas_device/end_device-*
```

Put it beside the existing `dump_attrs` calls in section 3, not in section 2.

**Verify**:
```
bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/bundle_support.sh && echo LINT-OK
bash tests/bundle_coverage_test.sh; echo "exit=$?"
```
→ now `bundle-coverage: all pass`, exit `0`.

### Step 4: Wire the test into the suite

Add one line to `tests/run.sh` next to the other self-asserting shell test
scripts, matching however `anon_test.sh` / `flash_test.sh` are invoked there.
Read those lines first and copy the form exactly.

**Verify**:
```
bash tests/run.sh 2>&1 | grep -i 'bundle-coverage'
bash tests/run.sh 2>&1 | grep '^FAIL' | sort > /tmp/after-fails.txt
diff /tmp/baseline-fails.txt /tmp/after-fails.txt && echo "NO NEW FAILURES"
bash tests/run.sh 2>&1 | tail -2
```
→ the new test's pass line appears, no new failures, suite ends
`--- all pass ---`.

## Test plan

- **New**: `tests/bundle_coverage_test.sh`, structured after
  `tests/flash_test.sh`. Cases: every composer `-a` token is captured by the
  bundle; the `lsblk` capture requests `TRAN`.
- **The test is written before the fix and observed failing** (Step 1). A
  guard test that has never been seen red proves nothing.
- **Mutation check** — after the suite is green, run each of these, confirm
  the named result, restore, and **report all three**:
  1. Remove the `-a 1,0` capture line from `bundle_support.sh` →
     `bundle-coverage` must FAIL naming `-a 1,0`.
  2. Remove `TRAN` from the `lsblk` line → `bundle-coverage` must FAIL
     naming the transport column.
  3. Add a **fake** composer call — append the literal line
     `# hba_query -p"$PORT" -a 99,0` as a *comment* inside
     `scripts/get_hba_info.sh` → report whether the test catches it. Either
     answer is acceptable and worth knowing: catching it means the guard is
     comment-blind (slightly noisy but safe), missing it means the guard
     only sees live calls. **Say which, do not "fix" it.** Restore
     afterwards.

## Done criteria

ALL must hold:

- [ ] `bash -n` clean on `bundle_support.sh` and `tests/bundle_coverage_test.sh`
- [ ] `bash tests/bundle_coverage_test.sh` exits 0 and ends
      `bundle-coverage: all pass`
- [ ] Step 1's evidence recorded: the test was observed **failing** before
      Steps 2–3, naming both gaps
- [ ] `bash tests/run.sh` adds no failure name absent from the Step 0
      baseline, and ends `--- all pass ---`
- [ ] `grep -oE '\-a [0-9]+(,[0-9]+)*' source/usr/local/emhttp/plugins/hbaviewer/scripts/bundle_support.sh | sort -u`
      lists all five tokens: `-a 1,0`, `-a 20,12,0,0`, `-a 25,2,0,0`,
      `-a 35,0`, `-a 42,0`
- [ ] `grep -c 'TRAN' source/usr/local/emhttp/plugins/hbaviewer/scripts/bundle_support.sh` → at least 1
- [ ] `git diff --stat` shows **no change** to the `00-README.txt` heredoc,
      to `bundle_anon`, or to `tests/anon_test.sh`
- [ ] `git status --short` lists only files from the In-scope list

## STOP conditions

Stop and report — do not improvise — if:

- The drift check prints anything, or the excerpts above do not match the
  live file.
- The guard test **passes** at the end of Step 1, before any fix. That means
  you are on the wrong base branch or the test checks nothing.
- `bash tests/run.sh` produces a failure name not in the Step 0 baseline.
- Adding `TRAN` to the `lsblk` line changes any other captured field. It
  should not — `-P` is key/value, not positional — but if it does, stop.
- You find yourself wanting to change the `grep 'WWN="0x'` drive filter in
  either `bundle_support.sh` or `collect_smart.sh`. Explicitly out of scope;
  this plan collects the evidence that question needs, it does not answer it.
- You conclude the guard test should also verify storcli commands, or the
  `04-parsed` composer list, or that every composer has a bundle entry by
  name. All defensible, all wider than this plan. Note the idea in your
  report and leave it.

## Maintenance notes

- **The guard is deliberately narrow**: it matches lsiutil `-a` argument
  tokens and one `lsblk` column. It does not verify storcli coverage,
  because storcli commands are free-form subcommands with no equally crisp
  token to key on. If a storcli capture ever goes missing the same way, that
  is the moment to widen it — with a real omission to test against, not
  speculatively.
- **Why a token match rather than running the bundle**: `bundle_support.sh`
  touches real hardware and writes a tarball. A static check over the source
  costs nothing, runs everywhere, and catches the exact failure mode that
  actually occurred. A full integration run would catch more and would never
  run in CI on a box with no HBA.
- **Once this and 041 are both merged**, a reporter's bundle answers the
  IT/IR question without a manual command block, and `02-raw/lsblk.txt`
  answers the SAS-vs-SATA question. The remaining manual ask on issue #10 is
  `storcli /cN show all` from a 9305-16i — the bundle *already* captures
  that (`02-raw/storcli_cN_show_all.txt`), so that reporter needs only to
  attach a bundle.
- **What a reviewer should scrutinise**: that the guard test was genuinely
  observed failing first, and that the `lsblk` column list gained `TRAN`
  without reordering the existing columns.
