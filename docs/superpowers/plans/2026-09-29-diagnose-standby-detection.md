# Fix round for Task 16: the engine never detects a sleeping drive

Parent plan: `docs/superpowers/plans/2026-09-21-disk-utility-diagnose.md`, Task 16, Block F.
Fix-round convention as before (`413864f`, `e9a0867`, `a79710e`): one scoped branch off `dev`, TDD,
a ledger entry, a `reviewer` pass, merge into `dev`, then the hardware check.

## What was found, on Golem

Block F was recorded as PASS on 2026-09-28. It was not a valid pass:

- `hdparm -C` is not a power-state check for SAS drives. It sends an ATA CHECK POWER MODE that SAS
  drives reject (`SG_IO: bad/missing sense data ... 72 05 20`), then prints `standby` (HITACHI, WDC)
  or `unknown` (HGST, SEAGATE, TOSHIBA) regardless of state. Only the two SATA drives (`sdx`, `sdy`)
  answered truthfully. `sdd`, the Block F subject, was ACTIVE throughout; Unraid (`disks.ini`
  `spundown=0`) and `smartctl` agreed.
- With `sdd` genuinely spun down (`emcmd cmdSpindown=disk3`: `disks.ini spundown=1`, `sg_requests`
  "Standby condition activated by command", `smartctl -n standby` "Device is in STANDBY BY COMMAND
  mode, exit(2)"), a web Diagnose on `sdd` left it asleep — but the engine's slot row read `clean`,
  not `SLEEPING`, and the run wrote `smart-sdd.txt`, which only the awake path writes.

## Root cause

`drive_triage.sh:405`:

```bash
if smartctl -n standby -i -d auto "/dev/$dev" 2>&1 | grep -qi 'STANDBY\|SLEEP'; then
```

The script runs under `set -uo pipefail` (line 133). `smartctl -n standby` exits 2 exactly when the
drive is asleep, so for a sleeping drive the pipeline's status is smartctl's 2 even though `grep`
matched; for an awake drive smartctl exits 0 and `grep` finds nothing. **The check cannot return
true.** Every sleeping drive takes the awake path. It stays asleep only because the next call
(line 417, `smartctl -x -n standby`) also carries `-n standby` and declines — which makes the rest
of the path wrong:

1. The declined read is parsed as a SMART report with zero counters, so a sleeping drive is
   reported `clean` — "absence is not health", `docs/review-policy.md`.
2. The baseline write (line 889-897) keeps a sleeping drive's previous record only when
   `standby == 1`, so zeros overwrite the real baseline. The next awake run sees the drive's whole
   lifetime count as a delta; at `DELTA_FLOOR` (10) or more that falsely promotes it to ACTIVE.
3. A sleeping drive with kernel-log sectors (`nsect > 0`) is flagged and triaged, and
   `triage_disk()`'s `sg_verify`/`sg_read` have no standby guard, so they spin it up. (Once the
   detector works, line 484 flags it the same way, so fixing the detector alone does not close this.)

The test suite never exercised a sleeping drive: its `smartctl` stub always prints a SMART report
and exits 0. The only standby assertion (`tests/drive_triage_test.sh:195-212`) checks that every
call carries `-n standby` — which is true, and is why the drive was never woken on the sweep path.

Only this one line has the idiom (`grep` over `source/`); every other script relies on per-call
`-n standby` and does not run under `pipefail`.

## Decisions

- One detector, `tri_asleep <dev>`, used at the sweep (line 405) and at the top of `triage_disk()`.
  It captures smartctl's output first and matches it with a here-string — no pipeline, so
  `pipefail` cannot see smartctl's exit 2. It matches smartctl's own decline message,
  `^Device is in [A-Z_ ]+ mode`, which smartctl prints only when `-n` made it leave the drive alone,
  rather than any line mentioning "standby".
- `triage_disk()` re-checks right before any media command and, when `SKIP_STANDBY=yes`, leaves the
  drive asleep: one warn line, a `SUMMARY` entry, no `sg_verify`/`sg_read`/self-test. The re-check is
  in `triage_disk()` because that is the single entry every triage goes through, and a drive can
  spin down between the sweep and the triage.
- A web Diagnose names one disk. If it was asleep, nothing produces a verdict today, and the
  Verdict screen says "Unknown — this run did not classify". The engine now emits exactly one
  `STANDBY` verdict for a `/dev/...` run whose disk was left asleep and got no other verdict — one
  emission point at the end of the run, so a sleeping-and-flagged disk does not emit twice. The
  sidebar already ranks a `STANDBY` badge (`render/diagnose.php:255`); the Verdict screen gets words
  and next steps for it.
- Not waking the drive stays absolute. There is no "wake it and test" path here; the inert
  `#diag-standby` checkbox stays parked as before.

## Steps

`git checkout -b diagnose-standby dev`. All paths below are relative to the repo root.

### 1. Red: tests that reproduce Golem

`tests/fixtures/smart/sas_standby.txt` — new fixture, the reply captured on Golem (evidence, do not
edit to fit a test):

```
smartctl 7.5 2025-04-30 r5714 [x86_64-linux-6.18.38-Unraid] (local build)
Copyright (C) 2002-25, Bruce Allen, Christian Franke, www.smartmontools.org

Device is in STANDBY BY COMMAND mode, exit(2)
```

`tests/drive_triage_test.sh` — read closely lines 1-70 (stubs, `run_dt`-style helper), 100-130
(the `--state` cases, for how a state file is seeded and read), 185-215 (the standby assertion and
its mutant). Skim the rest.

- Make the `smartctl` stub behave like the real tool for a sleeping drive: when `STUB_ASLEEP=1` and
  its argv contains `-n standby`, print `$STUB_SMART_ASLEEP` and `exit 2`; when `STUB_ASLEEP=1` and
  argv lacks `-n standby`, append `WOKE smartctl $*` to `$STUB_ARGS` and print the normal report.
  Default (unset) behavior unchanged. Export
  `STUB_SMART_ASLEEP="$PWD/fixtures/smart/sas_standby.txt"`.
- Make the `sg_verify`/`sg_read` stubs append `WOKE ...` to `$STUB_ARGS` too when `STUB_ASLEEP=1`
  (a media command on a sleeping drive is the wake).
- New cases, each in its own fresh `--out` dir, using the existing harness and
  `TRIAGE_SKIP_ROOT_CHECK=1 TRIAGE_SKIP_DEV_CHECK=1`:
  - **A — a sleeping drive is seen as asleep.** Seed a `--state` file with one row for id `manual`
    carrying nonzero counters (copy the row shape the existing `--state` case writes; use
    `uncorr=7`). Run `STUB_ASLEEP=1 ... --state "$SF" --no-triage /dev/sdX`. Assert: the output has a
    slot row for `sdX` ending `SLEEPING`; no `smart-sdX.txt` exists anywhere under the run dir; the
    state file's `manual` row still carries `7` in the uncorr column. (On current code: row reads
    `clean`, the smart file exists, the row is zeroed — all three fail.)
  - **B — a sleeping, flagged drive is not woken.** Put one line matching
    `dev sdX, sector 12345` into `$STUB_DMESG` (read how `CAT_LOG` is built first — lines ~330-370 —
    and feed the sector line through whatever it reads). Run `STUB_ASLEEP=1 ... --auto-triage --all
    /dev/sdX`. Assert: `$ARGS` has no `WOKE` line and no `sg_verify`/`sg_read` line; output contains
    `left asleep`.
  - **C — exactly one STANDBY verdict.** Case B again with `--events "$EV"`. Assert
    `grep -c '"t":"verdict"' "$EV"` is `1` and that line contains `"v":"STANDBY"`. Then the
    unflagged variant (empty `$STUB_DMESG`): also exactly one `STANDBY` verdict.
  - **D — an awake drive is unchanged.** `STUB_ASLEEP` unset, same flagged setup as B: `sg_verify`
    and `sg_read` lines present in `$ARGS`, no `STANDBY` verdict. (Regression guard; passes before
    and after.)
- Red: `bash tests/drive_triage_test.sh 2>&1 | grep -E '^(FAIL|ok|bad)' | grep -iE 'asleep|sleep|standby|woke'`
  must show A, B and C failing. If any of them passes on current code, stop — it isn't
  discriminating.

### 2. Green: the engine

`source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh`. Read closely: 200-240 (event
helpers), 370-420 (sweep loop, standby branch), 476-486 (slot scan standby row), 690-725
(`triage_disk()` head), 836-860 (triage loop, SUMMARY). Skim the rest.

- After `tri_verdict()` (line ~222), make it record that a verdict was sent, and add the detector:

  ```bash
  VERDICT_SENT=0
  tri_verdict() {  # disk v why
      VERDICT_SENT=1
      tri_emit "\"t\":\"verdict\",\"disk\":\"$(tri_esc "$1")\",\"v\":\"$2\",\"why\":\"$(tri_esc "$3")\""
  }

  # Is the drive asleep? `smartctl -n standby` exits 2 exactly when it is, so
  # this must not be a `smartctl | grep -q` pipeline: under `set -o pipefail`
  # that pipeline returns smartctl's 2 and reads "asleep" as false -- which is
  # how this check never fired on a real sleeping drive (Golem, sdd, Task 16
  # Block F). Capture first, then match smartctl's own decline message, which
  # it prints only when -n made it leave the drive alone.
  tri_asleep() {  # dev
      local out
      out="$(smartctl -n standby -i -d auto "/dev/$1" 2>&1)"
      grep -qE '^Device is in [A-Z_ ]+ mode' <<< "$out"
  }
  ```

  (Replace the existing `tri_verdict` body; keep its argument contract.)
- Line ~375: `declare -A LBS_OF HOST_OF STANDBY_OF`.
- Line ~404-408: replace the pipeline with
  ```bash
  if [[ "$SKIP_STANDBY" == "yes" ]] && tri_asleep "$dev"; then
      standby=1; STANDBY_OF["$dev"]=1
  fi
  ```
  (keep `standby=0` above it). Confirm the sweep loop reads its input by redirection, not a pipe, so
  `STANDBY_OF` survives the loop; if it is a pipe, say so and stop.
- In `triage_disk()`, immediately after the `sect "TRIAGE: ..."` line:
  ```bash
      # Re-check here, not only at the sweep: this is the one entry every triage
      # goes through, and sg_verify/sg_read below have no standby guard of their
      # own. A drive can also spin down between the sweep and this point.
      if [[ "$SKIP_STANDBY" == "yes" ]] && tri_asleep "$dev"; then
          warn "$name (/dev/$dev) is in standby -- left asleep, not triaged (VERIFY and READ would spin it up)"
          SUMMARY+=("$name ($dev): left asleep -- not triaged")
          STANDBY_OF["$dev"]=1
          return 0
      fi
  ```
- Immediately before `sect "SUMMARY"` (line ~854):
  ```bash
  # A web Diagnose names one disk. If it was left asleep, nothing above sent a
  # verdict, and the Verdict screen would report "did not classify". Say why,
  # once, here -- one emission point, so a sleeping disk that was also flagged
  # does not emit twice.
  if [[ -n "$DEV_OVERRIDE" && "$VERDICT_SENT" -eq 0 \
        && -n "${STANDBY_OF[$(basename "$DEV_OVERRIDE")]:-}" ]]; then
      tri_verdict manual STANDBY "left asleep -- Diagnose never spins a disk up"
  fi
  ```
- Green: `bash tests/drive_triage_test.sh 2>&1 | tail -3` ends with the suite's all-pass line, and
  `grep -c '^FAIL'` over its output is `0`. `bash -n` on the script is clean.
- Mutation checks (apply, confirm, `git checkout -- <file>`, confirm green again):
  1. Restore line 405's original pipeline in place of `tri_asleep "$dev"` → A fails.
  2. Delete the `triage_disk()` re-check → B fails (a `WOKE sg_verify` line appears), A still passes.
  3. Delete the end-of-run `STANDBY` emission → C fails.
  4. Change `tri_asleep`'s `grep -qE ... <<< "$out"` back to `smartctl ... | grep -qE ...` → A fails.

### 3. The Verdict screen and the live log

`source/usr/local/emhttp/plugins/hbaviewer/render/diagnose.php`:

- `diag_verdict_words()`: add before the fallthrough
  ```php
          case 'STANDBY': return [
              'title' => 'Left asleep — not tested',
              'lead'  => 'The drive was in standby when this run reached it. Diagnose never spins a disk up, so no block was read and this run says nothing about the drive\'s health.',
          ];
  ```
- `diag_next_steps()`: add, before the `CLEAN` branch,
  ```php
      if ($verdict === 'STANDBY') {
          return [
              'Spin the drive up first — from the Main page, or by reading a file from it — then run Diagnose again.',
              'It was left asleep on purpose: HBAviewer never wakes a sleeping drive, including to test it.',
          ];
      }
  ```

`source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js` line ~211: the verdict log line's
severity becomes `ev.v === 'CLEAN' ? 'ok' : (ev.v === 'STANDBY' ? 'warn' : 'crit')`.

Tests:
- `tests/diagnose_render_test.php`: a `STANDBY` verdict with no chunk events renders the
  "Left asleep" title, both next steps, all three evidence cards reading `not run` (not `clean`,
  not `none`), and the "No range was tested" note. Mutation: remove the `STANDBY` case from
  `diag_verdict_words()` → the title check fails.
- `tests/diagnose_js_test.js`: applying `{t:'verdict', v:'STANDBY', why:'x'}` logs with the `warn`
  class. Mutation: revert the ternary → fails.
- `php tests/diagnose_render_test.php | tail -1`, `node tests/diagnose_js_test.js | tail -1`: all pass.

### 4. Docs

- `ARCHITECTURE.md`, "Where the sharp edges are", append:
  ```markdown
  - **`smartctl -n standby` exits 2 when the drive is asleep — never pipe it into
    `grep -q` under `pipefail`.** The pipeline's status is smartctl's 2, so the
    check reads "awake" for every sleeping drive. `drive_triage.sh` shipped that way
    and treated every sleeping drive as awake: zeroed counters reported as clean, a
    zeroed baseline, and a flagged drive spun up by `sg_verify`. `tri_asleep()`
    captures the output first and matches smartctl's own `Device is in ... mode`
    decline message.
  - **`hdparm -C` is not a power-state check for SAS drives.** It sends an ATA
    command SAS drives reject, then prints `standby` or `unknown` whatever the real
    state. Use `smartctl -n standby` or `sg_requests`; Unraid's own
    `disks.ini` `spundown` agrees with both.
  ```
- `HOWTO.md`, `## Diagnose`: where it says standby drives are left asleep, add one sentence: a
  Diagnose on a sleeping drive ends with a "Left asleep — not tested" result, and the drive has to
  be spun up to be tested.

### 5. Verify and commit

- `bash tests/run.sh 2>&1 | grep -E '^FAIL'`: exactly the two known `config_test.php` lines.
- `bash tests/drive_triage_test.sh`, `php tests/diagnose_test.php`, `php tests/diagnose_php_test.php`,
  `php tests/diagnose_render_test.php`, `node tests/diagnose_js_test.js`: each all-pass (run directly;
  `run_php.sh` stops at `config_test.php` on this sandbox).
- One commit, message `Fix: the Diagnose engine never detected a sleeping drive`, body summarising
  the root cause and the three consequences, trailer
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Ledger entry in `.superpowers/sdd/2026-09-21-disk-utility-diagnose/progress.md`: the invalid
  Block F pass, the `hdparm` finding, root cause, commit hash.

### 6. Review and merge

`reviewer` on `git diff dev..diagnose-standby`. Read closely: `tri_asleep`, the `triage_disk()`
re-check, the end-of-run emission. Ask it to independently confirm the `pipefail` mechanism (not
trust this plan), mutation-test the detector, and check that no other `smartctl` call in the engine
now behaves differently for an awake drive. Ruling on findings: NEEDS JUDGEMENT. Then
`git checkout dev && git merge diagnose-standby --no-edit`. No push without the user's go-ahead.

### 7. Hardware (Commands I run myself — one block per message)

1. Deploy `dev` per ARCHITECTURE.md "Testing a branch on real hardware"; `install-verify.sh` PASS and
   `grep -c tri_asleep` on the installed `drive_triage.sh` ≥ 3.
2. (State change, own message.) Remove `sdd`'s zeroed baseline from the invalid run, then spin
   `disk3` down: `rm -f /tmp/hbaviewer/jobs/sdd.baseline.tsv; /usr/local/sbin/emcmd cmdSpindown=disk3`.
3. Confirm asleep: `disks.ini` `spundown=1`, `sg_requests` standby, `smartctl -n standby` decline.
4. Diagnose `sdd` from the UI. Pass: `job.log` slot row `SLEEPING`; no `smart-sdd.txt` in the run
   dir; `events.ndjson` has exactly one verdict, `STANDBY`; the Verdict screen reads "Left asleep —
   not tested"; afterwards `sdd` is still asleep by all three measures. This is Block F, run for
   real.
5. Not reproducible on Golem: a sleeping drive with kernel-log sector errors (no drive has any).
   Recorded as covered by tests B and C only.
6. Then correct the parent plan's status header (Block F: invalid first pass, re-verified after this
   fix, commit hash) and the ledger.
