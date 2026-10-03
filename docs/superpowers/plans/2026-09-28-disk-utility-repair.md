# Disk Utility Phase 2a — Repair evidence (read-only) Implementation Plan

> **Status: COMPLETE — merged into `dev` 2026-10-03. VERIFIED ON GOLEM, 2026-10-03, reviewed.** Blocks A–H passed, including the sysfs serial agreeing with smartctl on all 21 SAS/SATA disks (NVMe and flash have no vpd_pg80 and record nothing), parity, pool and flash devices classed as assigned on real data, the web launcher passing `--badrange-state` with no ledger written on a clean run, and the Repair button exercised on a real non-CLEAN ("left asleep") verdict and absent on a CLEAN one. **Not verified on hardware:** a real cross-run `media` confirmation (two runs, `confirm_count` reaching 2, a row in the Repair table) and the chunk-rounding tolerance against real dmesg harvests — no Golem disk fails VERIFY today (`sdq` spot-checks `0:256` clean). First thing to run when a failing drive turns up; a bench drive `sas-bench` classifies MEDIA is the natural candidate. The Repair table branch (an unassigned disk) was exercised only in tests: every Golem device is assigned.

> **As built — deviations from the task text below:** the Repair screen has a static "Back to verdict" button above #diag-repair-body (renderDiagRepair emits no button but luTable's sort buttons); the Repair button passes the disk as a JSON string literal (htmlspecialchars(json_encode)); luDiagRepair checks r.ok, shows errors in the body and focuses #diag-repair-back; "Back to verdict" calls luDiagRepairBack(), which shows the verdict and focuses #diag-verdict-back; the reader also skips a non-numeric updated_ts; the ledger write is gated on awk's exit status; Task 9 ran the final review before the hardware blocks, and Block D ran the branch engine from a single raw download instead of a full install; hardware blocks use `smartctl -n standby` power mode, not `hdparm -C` (SAS); ledger file mode follows umask.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A read-only Repair screen in the Diagnose tab, fed by a new cross-run bad-range ledger that `drive_triage.sh` keeps per disk, where only a `media` result (VERIFY failed) counts toward "confirmed".

**Architecture:** `drive_triage.sh` gains `--badrange-state <path>`. Inside `triage_disk()`'s range loop each tested range is classified (`media`/`transport`/`intermittent`/`unresolved`) and appended to a per-run record file; at end of run, beside the existing `$STATE` write, one awk pass merges those records into the ledger TSV. `diagnose.php` passes a per-disk ledger path from `diag_badrange_path()`, and a new disk-scoped `action=repair` reads it (pure PHP) into `renderDiagRepair()`. The Verdict screen gets a server-rendered "Repair" button on any non-CLEAN verdict; `luDiagRepair()` switches to a third `#diag-repair` screen.

**Tech Stack:** bash + GNU awk engine, PHP 8 (no framework), vanilla ES5 JS (one IIFE, no build step). Tests: `tests/drive_triage_test.sh` (PATH-stubbed engine), `tests/diagnose_test.php`, `tests/diagnose_php_test.php` (source assertions over tokenizer-stripped code), `tests/diagnose_render_test.php`, `tests/diagnose_js_test.js` (node vm harness).

**Spec:** `docs/superpowers/specs/2026-09-28-disk-utility-repair-design.md` — the contract. The planning decisions below change its text; each is applied to the spec **in the task that implements it** (Tasks 3, 4, 5, 6, 7), never silently. Amended 2026-09-28 with four user-approved changes (serial key, assigned-anywhere, region-only chunk key, `/tmp` persistence deferred).

## Decisions made in this plan (read before any task)

1. **Ledger identity = the drive's SERIAL.** Every ledger row's first column is the drive's own serial number. Not the slot ID `$STATE` uses: on a `/dev/<disk>` run — every web-launched job and every harness test — the engine writes the slot line `manual<TAB><dev><TAB>DISK_OK<TAB>0<TAB>manual` (`drive_triage.sh:321-322`), so that ID is the literal `manual`. `$STATE` keys stay exactly as today; only the new ledger uses the serial, so the flag-absent byte-identical guard still holds. The PHP reader returns only rows filed under the disk's **current** serial, and nothing when the serial is unknown. The per-disk filename (`<DIAG_ROOT>/<disk>.badranges.tsv`) stays; the serial filter is what makes it safe when a drive takes over another's `sdX` name.
2. **Run id = the engine's `$STAMP`** (`YYYYmmdd-HHMMSS`, the run subdirectory name), not the web job id. The engine never receives the job id and CLI runs have none.
3. **Rows may carry columns past the seventh** (the spec's forward path adds columns). The engine passes them through on rewrite; the reader ignores them. Rows with fewer than seven columns or non-numeric `chunk_start`/`confirm_count` are dropped on engine rewrite and skipped by the reader.
4. **One run raises a chunk's count at most once.** Records for the same serial+chunk within one run fold to the worst class (`media` > `transport` > `unresolved` > `intermittent`). This is what makes `confirm_count` a count of *distinct* runs.
5. **The "failed only over the link" line counts rows with `last_class == transport` AND `confirm_count == 0`.** A row media-confirmed twice whose latest run was `transport` stays in the table; calling it "failed only over the link, not a repair candidate" would contradict the table beside it.
6. **The Repair button's visibility is decided server-side in `renderDiagVerdict()`**, tested in `diagnose_render_test.php`, not in the JS test the spec names: a reopened past verdict (`luDiagOpen`) never passes a verdict event through the browser, so the client cannot know it.
7. **"Assigned" means assigned anywhere, from ONE reader.** `diag_array_disk()` returns true for parity, parity2, any `diskN` and any pool member, false only for a disk Unraid has not assigned; an unreadable `disks.ini` still answers true. It asks `unraid_disk_roles()` (`render/baymap.php:27`), the existing, tested disks.ini reader behind the SMART tab and bay map. **Finding while tracing:** the Diagnose sidebar does NOT already classify correctly — the `drivelist` action (`diagnose.php:218-228`) has its own inline rule (`diskN` → "Disk N", section `parity` → "Parity", everything else → `''`), so `parity2` and pool members land in the sidebar's "Unassigned" group today. So Task 6 switches the `drivelist` action to `unraid_disk_roles()` too: the sidebar, the Verdict and the Repair screen then share one reader and cannot disagree. The verdict action's switch to the helper fixes the shipped Phase 1 bug where a parity or pool disk's MEDIA verdict said "This drive is not assigned to the array or a pool".
8. **The Repair screen has exactly one button, "Back to verdict"** (`luDiagShow('verdict')`), plus `luTable`'s column-sort buttons. Navigation, not action — without it the only way off the screen is starting a new job.
9. **The serial comes from sysfs, on BOTH sides — not from `smartctl -i -n standby`.** Engine (`br_serial`) and PHP (`diag_disk_serial()`) both read `/sys/block/<dev>/device/vpd_pg80`, the kernel's cached copy of VPD page 0x80 (unit serial number; the page `smartctl` prints as "Serial number" for SAS), skip its 4-byte header, keep printable ASCII, trim spaces. Why not smartctl as the amendment described: (a) the spec makes `action=repair` "pure PHP, no shell-out", and `docs/foreground-reads.md` keeps hardware reads out of request paths, so the PHP reader cannot run smartctl; (b) writer and reader must derive the key from one source with one normalization, or the filter silently hides every row; (c) a sysfs read sends no command to the drive at all, so it cannot wake one — stricter than `-n standby`, which still sends CHECK POWER MODE. No serial readable ⇒ the engine logs one line and records nothing for that disk; the reader shows nothing. Task 9 Block C checks on Golem that `vpd_pg80` exists and agrees with smartctl's serial. **If the user wants smartctl specifically, this is the decision to revisit** — it would need a second, shell-free way for PHP to learn the same value.
10. **`chunk_start` is a region key for cross-run matching only, never a write address.** A tested range starts `PAD` (2000) blocks below the first failing sector the kernel reported, so its chunk may precede the defect. Phase 2b must locate the exact failing LBA with a fresh fine-grained VERIFY before any write. A defect whose padded start straddles a chunk boundary between runs lands in two rows at 1 and never confirms — a known false negative, fail-safe. Spec updated in Task 3.

## Global Constraints

- Repo root: `cd /c/Users/Joe/Documents/GitHub/Unraid-HBAviewer`. Work on branch `disk-utility-repair` (created in Task 1). Never commit to `main`. **No `git push` without the user's explicit go-ahead** (Task 9 Step 1 asks for it).
- **Phase 2a ships no mutating path.** No new write to any device. No checkbox, typed-confirmation field or action button on the Repair screen. Nothing may invoke `sg_reassign`, `hdparm --write-sector`, `badblocks`, `sg_format`, `sg_sanitize`.
- **Only `media` increments `confirm_count`.** `transport`/`unresolved` rows are inserted at 0; `intermittent` never inserts; a row that stops failing is kept with `last_class=intermittent`; a clean `0:256` spot-check writes nothing.
- **`--badrange-state` absent ⇒ byte-identical behavior to today** (report, baseline, run-dir file set). Pinned in Task 1.
- `--badrange-state` under `/boot/*` is refused with exit 2, same guard `--state` got in `51f3a58`.
- `--reset-baseline` also deletes the ledger when `--badrange-state` is given. No second reset mechanism.
- ES5 in `diagnose_view.js` (`var`, `function`, no arrows/template strings/`let`/`const`). PHP style per file. No new dependencies. `render/` stays at **9** files (the renderer goes into the existing `render/diagnose.php`; `docs/install-verify.sh` is not touched).
- Commit after every task. Message: a sentence saying what changed and why, no `feat:`/`chore:` prefixes, ending with a blank line then `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Use `git commit -F - <<'EOF' … EOF`.
- **Every new backend assertion gets a mutation check** (the user's standing rule): after the task's commit, break the code as the task states, confirm exactly the named case(s) FAIL, then `git checkout -- <file>` and confirm `git status --short` is empty. Mutate only AFTER committing, so `git checkout` restores the implementation, not the pre-task file.
- **Known pre-existing failures on this Windows sandbox — the only acceptable ones:**
  - `bash tests/run.sh` ends `--- FAILURES ---` with exactly two `FAIL` lines, both from `config_test.php`: `FAIL  shell and PHP agree on the ALERT_THRESHOLD default` and `FAIL  the default is a real band floor`, plus `config: 2 FAILED`.
  - Because `tests/run_php.sh` chains its tests with `&&` and `config_test.php` is first, **none of the diagnose PHP tests run inside `run.sh` here.** Every task therefore runs them directly (command below). `cached_read_test.php` and `locate_test.php` also fail on Windows when run individually; they are not touched by this plan and are not run.
- **The three standard checks** (each task names which it runs; the commands are repeated in the task):
  - ENGINE: `(cd tests && bash drive_triage_test.sh 2>&1 | grep -E '^(FAIL|SKIP)|drive_triage:')` → exactly `drive_triage: all pass`.
  - DIAG: `for t in diagnose_test diagnose_php_test diagnose_render_test; do php tests/$t.php 2>&1 | grep -E '^(FAIL|Warning|Deprecated|Notice|Fatal error|Parse error)|: all pass|FAILED'; done; node tests/diagnose_js_test.js 2>&1 | grep -E '^FAIL|diagnose_js:'` → exactly the four lines `diagnose: all pass`, `diagnose_php: all pass`, `diagnose_render: all pass`, `diagnose_js: all pass`.
  - SUITE: `bash tests/run.sh 2>&1 | grep -E '^FAIL|FAILED$|^--- '` → exactly the four lines `FAIL  shell and PHP agree on the ALERT_THRESHOLD default`, `FAIL  the default is a real band floor`, `config: 2 FAILED`, `--- FAILURES ---`.
- Lint tier CI runs: `bash -n` on edited `.sh`, `php -l` on edited `.php`, `node --check` on edited `.js`. Each task lists its own.
- The ledger TSV format (after Task 3): `serial  chunk_start  confirm_count  first_run_id  last_run_id  last_class  updated_ts`, tab-separated, one row per (serial, chunk). The per-run record file `$RUN/badranges-run.tsv` (Task 2): `serial  chunk_start  class`.
- **Never wake a disk to read its serial.** The serial is a sysfs read (Decision 9); no new `smartctl` or SCSI command is added anywhere.
- **`$STATE` (the baseline) is untouched** — same keys (`manual` on `/dev/` runs), same format.

## Review Focus

Failure modes the spec implies but does not test, most likely first; each has its test in the owning task.

1. **A chunk media-confirmed twice whose latest run is `transport`.** Expected: it stays in the Repair table (it failed VERIFY twice) and is NOT counted in "N ranges failed only over the link". → Task 5, `a confirmed row whose latest run was transport stays in the table and out of the link count`.
2. **A hand-edited or CRLF ledger, or one with extra columns.** Expected: good rows still read, bad rows skipped, never fatal. → Task 4, CRLF + extra-column + malformed rows in one fixture.
3. **A multi-disk CLI sweep sharing one ledger file** — two disks failing on the SAME chunk, and a foreign row already on that chunk. Expected: each disk's row under its own serial, the foreign row byte for byte. → Task 3, `a CLI sweep files each disk under its own serial` and `another disk's row on the same chunk passes through byte for byte`.
4. **`--badrange-state` pointing into a directory that does not exist** (CLI typo). Expected: the run still finishes with exit 0, and creates nothing. → Task 3.
5. **A run that did not classify** (unknown verdict — died mid-way, or no sg3_utils) **and a disk name carrying a quote.** Expected: Repair is still offered (unclassified is not clean), and the name cannot break out of the `onclick`. → Task 7.

---

### Task 1: `--badrange-state` flag — parse, `/boot` refusal, reset, and the flag-absent regression guard

Plumbing only; no ledger is written yet. The regression guard lands first so every later engine task is checked against it.

**Read closely:** `source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh:138-186` (arg parse, `/boot` guards, `STATE`/reset) and `:276-285` (baseline preflight message); `tests/drive_triage_test.sh:17-62` (stubs, `run()`), `:115-125` (the `--state` precedent), `:199-216` (mutation-check shape, final `echo`). **Skim:** nothing else.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh` (lines ~139, ~154, ~176, ~186, ~285)
- Test: `tests/drive_triage_test.sh` (append above the final `echo`)

**Interfaces:**
- Consumes: nothing new.
- Produces: engine variable `BADRANGE_STATE` (empty = off); CLI `--badrange-state <path>`; report line `      bad-range ledger: <path>` when the flag is given. Test helpers used by Tasks 2–3: `LEDGER`, `SX` (sdX's fake serial, `SERIALX00001`), `mkvpd <dev> <serial>` (writes a fake `vpd_pg80` under `$WORK/sys`), `brun()` (honours `BR_BIN`, `BR_ENGINE`, `BR_SYS`; passes `TRIAGE_SYSFS`, which the engine starts reading in Task 2), `seed <sector>`.

- [ ] **Step 0: Branch**

Pass condition: `git status --short` prints nothing and the branch line reads `disk-utility-repair`.

```bash
cd /c/Users/Joe/Documents/GitHub/Unraid-HBAviewer
git status --short
git checkout -b disk-utility-repair dev && git branch --show-current
```

- [ ] **Step 1: Write the failing tests**

In `tests/drive_triage_test.sh`, insert directly above the final lines

```bash
echo
[ $fail -eq 0 ] && { echo "drive_triage: all pass"; exit 0; } || { echo "drive_triage: FAILURES"; exit 1; }
```

this block:

```bash
# ── --badrange-state: the Phase 2a bad-range ledger, flag plumbing. ─────────
LEDGER="$WORK/ledger.tsv"
# A fake sysfs holding each disk's VPD page 0x80 (unit serial number): a
# 4-byte header, then the serial, space-padded as real drives pad it. The
# length byte is 0x30 -- printable ('0') -- so a reader that fails to skip the
# header is caught instead of having the header stripped as non-printable.
mkvpd() { mkdir -p "$WORK/sys/block/$1/device"; printf '\000\200\000\060  %s  ' "$2" > "$WORK/sys/block/$1/device/vpd_pg80"; }
SX=SERIALX00001
mkvpd sdX "$SX"
# One flagged triage of sdX with the ledger on. BR_BIN swaps the stub dir (a
# box with no sg3_utils), BR_ENGINE the engine (a mutant) and BR_SYS the fake
# sysfs (a disk with no readable serial) without a second copy of this
# function. TRIAGE_SYSFS is ignored until Task 2 teaches the engine to read it.
brun() {
    : > "$ARGS"
    OUT="$WORK/brout"; rm -rf "$OUT"
    PATH="${BR_BIN:-$STUBDIR}:$PATH" TRIAGE_SKIP_ROOT_CHECK=1 TRIAGE_SKIP_DEV_CHECK=1 \
        TRIAGE_SKIP_SELFTEST_WAIT=1 TRIAGE_SYSFS="${BR_SYS:-$WORK/sys}" bash "${BR_ENGINE:-$DT}" --out "$OUT" \
        --badrange-state "$LEDGER" --auto-triage --all "$@" /dev/sdX 2>&1
}
seed() { echo "kernel: sd 0:0:0:0: [sdX] tag#0 FAILED dev sdX, sector $1 op 0x0" > "$STUB_DMESG"; }

# /boot is flash. Same refusal --out and --state already get, and it must fire
# before anything is created.
out=$(PATH="$STUBDIR:$PATH" bash "$DT" --out "$WORK/bootbr" --badrange-state /boot/x.tsv /dev/sdX 2>&1); rc=$?
[ $rc -eq 2 ] && has "--badrange-state under /boot is refused" "$out" "change --badrange-state" \
              || bad "--badrange-state under /boot is refused" "exit was $rc, not 2"
[ ! -e "$WORK/bootbr" ] && ok "and nothing is created before the refusal" \
                        || bad "and nothing is created before the refusal" "$WORK/bootbr exists"

# One physical-drive-identity reset clears the baseline AND the ledger.
printf 'manual\t10240\t2\tr1\tr2\tmedia\t1\n' > "$LEDGER"
out=$(brun --no-triage --reset-baseline)
[ ! -e "$LEDGER" ] && ok "--reset-baseline also clears the ledger it is given" \
                   || bad "--reset-baseline also clears the ledger it is given" "survived: $(cat "$LEDGER")"
has "the ledger path is named in the report when the flag is given" "$out" "bad-range ledger: $LEDGER"

# Flag absent = today's behavior, byte for byte -- the --state precedent
# (413864f). Same flagged MEDIA scenario both ways; the reports may differ ONLY
# in lines naming the ledger and the four lines carrying a time or the run's
# own path.
norm() { grep -v -E '^(started |results |finished |full report:)|bad-range ledger' "$1"; }
seed 12345
STUB_VERIFY_RC=1 STUB_READ_RC=1 TRIAGE_SKIP_DEV_CHECK=1 TRIAGE_SKIP_SELFTEST_WAIT=1 run --auto-triage --all >/dev/null
RA=$(ls -1d "$WORK/out"/*/ | head -1)
cp "${RA}report.txt" "$WORK/report-noflag.txt"
cut -f1,3- "$WORK/out/baseline.tsv" > "$WORK/state-noflag.tsv"
ls -1 "$RA" > "$WORK/files-noflag.txt"
hasnt "flag absent: the report never mentions the ledger" "$(cat "$WORK/report-noflag.txt")" "bad-range ledger"
leak=$(ls -R "$WORK/out" | grep -i badrange || true)
[ -z "$leak" ] && ok "flag absent: no ledger or ledger record is written anywhere" \
               || bad "flag absent: no ledger or ledger record is written anywhere" "$leak"
rm -f "$LEDGER"
STUB_VERIFY_RC=1 STUB_READ_RC=1 brun >/dev/null
RB=$(ls -1d "$WORK/brout"/*/ | head -1)
d=$(diff <(norm "$WORK/report-noflag.txt") <(norm "${RB}report.txt"))
[ -z "$d" ] && ok "the flag changes nothing in the report but its own ledger lines" \
            || bad "the flag changes nothing in the report but its own ledger lines" "$d"
d=$(diff "$WORK/state-noflag.tsv" <(cut -f1,3- "$WORK/brout/baseline.tsv"))
[ -z "$d" ] && ok "the flag changes nothing in the baseline" \
            || bad "the flag changes nothing in the baseline" "$d"
d=$(diff "$WORK/files-noflag.txt" <(ls -1 "$RB" | grep -v '^badranges-run\.tsv$'))
[ -z "$d" ] && ok "the flag adds no run file but its own record" \
            || bad "the flag adds no run file but its own record" "$d"
rm -f "$LEDGER"; : > "$STUB_DMESG"
```

- [ ] **Step 2: Run to verify it fails**

Run: `(cd tests && bash drive_triage_test.sh 2>&1 | grep -E '^FAIL|drive_triage:')`
Expected: `FAIL  --badrange-state under /boot is refused -- exit was 3, not 2` (today the flag is ignored and the root gate fires; any non-2 code is the same result), `FAIL  and nothing is created before the refusal`, `FAIL  --reset-baseline also clears the ledger it is given`, `FAIL  the ledger path is named in the report when the flag is given`, then `drive_triage: FAILURES`. The three regression-guard cases PASS already (the flag is ignored today) — that is expected; they are guards, not red tests.

- [ ] **Step 3: Implement**

In `drive_triage.sh`:

(a) After `STATE_OVERRIDE=""` (line ~139) add:

```bash
BADRANGE_STATE=""
```

(b) After the `--state)` case line add (the `)` spacing lines the value up with its neighbours):

```bash
        --badrange-state)  BADRANGE_STATE="$2"; shift ;;
```

(c) After the `case "$STATE_OVERRIDE" in … esac` block (line ~174-176) add:

```bash
case "$BADRANGE_STATE" in
    /boot/*) echo "refusing to write to the flash drive. change --badrange-state." >&2; exit 2 ;;
esac
```

(d) Replace the line `[[ "$RESET_BASELINE" == "yes" ]] && rm -f "$STATE"` with:

```bash
[[ "$RESET_BASELINE" == "yes" ]] && rm -f "$STATE"
# A disk swap or rebuild invalidates bad-range evidence for the same reason it
# invalidates the baseline: one physical-drive-identity reset clears both.
[[ "$RESET_BASELINE" == "yes" && -n "$BADRANGE_STATE" ]] && rm -f "$BADRANGE_STATE"
```

(e) Directly after the preflight baseline block that ends

```bash
    warn "Run again in a few hours for meaningful deltas."
    HAVE_BASELINE=0
fi
```

add:

```bash
[[ -n "$BADRANGE_STATE" ]] && info "bad-range ledger: $BADRANGE_STATE"
```

- [ ] **Step 4: Run to verify it passes**

Run: `bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh && echo SYNTAX-OK`, then ENGINE: `(cd tests && bash drive_triage_test.sh 2>&1 | grep -E '^(FAIL|SKIP)|drive_triage:')`
Expected: `SYNTAX-OK`, then exactly `drive_triage: all pass`. If the report diff case fails on a line other than a ledger/time/path line, STOP — **NEEDS JUDGEMENT**; do not widen `norm()`.

Then SUITE: `bash tests/run.sh 2>&1 | grep -E '^FAIL|FAILED$|^--- '` → exactly the four known lines (Global Constraints).

- [ ] **Step 5: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh tests/drive_triage_test.sh
git commit -F - <<'EOF'
Add the --badrange-state flag to the triage engine: parsed, refused under /boot like --state, cleared by --reset-baseline, and pinned to change nothing when absent.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

- [ ] **Step 6: Mutation checks** (one at a time; restore after each)

Pass condition for each: the named FAIL line(s) appear, then `git status --short` is empty after the checkout.

```bash
E=source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh
sed -i '/change --badrange-state\./d' $E
(cd tests && bash drive_triage_test.sh 2>&1 | grep '^FAIL')   # expect: --badrange-state under /boot is refused; and nothing is created before the refusal
git checkout -- $E
sed -i 's/^\[\[ -n "\$BADRANGE_STATE" \]\] && info "bad-range ledger: /info "bad-range ledger: /' $E
(cd tests && bash drive_triage_test.sh 2>&1 | grep '^FAIL')   # expect: flag absent: the report never mentions the ledger
git checkout -- $E
sed -i '/&& rm -f "\$BADRANGE_STATE"$/d' $E
(cd tests && bash drive_triage_test.sh 2>&1 | grep '^FAIL')   # expect: --reset-baseline also clears the ledger it is given
git checkout -- $E
git status --short
```

If a sed changes nothing (no FAIL appears and `git diff` was empty before the checkout), the sed no longer matches the code — fix the sed to match Step 3's exact text, never the test.

---

### Task 2: Read the drive's serial, classify each tested range, record it for the run

`triage_disk()` today folds per-range results into disk-level `vfail`/`rfail`. This task keeps that untouched and additionally records each range's class, keyed by the drive's **serial** and the rounded chunk, in `$RUN/badranges-run.tsv` — only when `--badrange-state` is given, and only when a serial is readable.

**Read closely:** `drive_triage.sh:690-741` (`snap()`, start of `triage_disk()`, the range loop); `:400-420` (how the counter loop detects standby — `smartctl -n standby -i` — and marks the disk). **Skim:** `:614-640` (`build_ranges`: a range is `first_sector - PAD(2000)` : `span + 2*PAD`); `:479-486` (a sleeping disk with kernel-log evidence is still FLAGGED at score 60 — see Risks).

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh` (new `br_serial()` and `br_class()` above `SUMMARY=()` at ~706; `triage_disk()` ~707-741)
- Test: `tests/drive_triage_test.sh` (append below Task 1's block, above the final `echo`)

**Interfaces:**
- Consumes: `BADRANGE_STATE`, `brun`, `seed`, `SX`, `mkvpd`, `BR_BIN`, `BR_SYS` (Task 1).
- Produces:
  - `br_serial <dev>` → prints the serial from `${TRIAGE_SYSFS:-/sys}/block/<dev>/device/vpd_pg80` (skip 4-byte header, `LC_ALL=C tr -cd '[:print:]'`, trim leading/trailing spaces), or nothing. `TRIAGE_SYSFS` is a test-only gate like the existing `TRIAGE_SKIP_*` ones. **Task 4's `diag_disk_serial()` must normalize identically.**
  - `br_class <have_sg> <verify_failed> <read_failed>` → `media|transport|unresolved|intermittent`.
  - File `$RUN/badranges-run.tsv`, lines `serial<TAB>chunk_start<TAB>class`, `chunk_start = rs / CHUNK * CHUNK` (CHUNK=2048).
  - Report line `      bad-range ledger: no serial readable for /dev/<dev> -- nothing recorded for it` when the flag is given and no serial is readable.

- [ ] **Step 1: Write the failing tests**

Append below Task 1's block (still above the final `echo`):

```bash
# ── Per-range class, recorded for the ledger under the drive's serial. ─────
# sector 12345 -> build_ranges pads 2000 below -> range 10345:4000 -> chunk
# 10240 (5 x 2048). The key is sdX's serial from the fake sysfs -- NOT the
# slot ID $STATE uses, which on a /dev/ run is the literal "manual".
rec() { local d; d=$(ls -1d "$WORK/brout"/*/ 2>/dev/null | head -1); cat "${d}badranges-run.tsv" 2>/dev/null; }
# Exact equality, not a substring: a key with junk in front of the serial
# (an unskipped VPD header byte) still CONTAINS the serial.
is() { [ "$2" = "$3" ] && ok "$1" || bad "$1" "want '$3', got '$2'"; }
rm -f "$LEDGER"; seed 12345
STUB_VERIFY_RC=1 STUB_READ_RC=1 brun >/dev/null
is "VERIFY failed is recorded media, keyed by the drive's serial" "$(rec)" "$SX"$'\t10240\tmedia'
hasnt "and never by the slot ID" "$(rec)" "manual"
STUB_READ_RC=1 brun >/dev/null
is "VERIFY clean + READ failed is recorded transport" "$(rec)" "$SX"$'\t10240\ttransport'
brun >/dev/null
is "nothing failing is recorded intermittent" "$(rec)" "$SX"$'\t10240\tintermittent'
seed 12500    # range starts at 10500: exact-match would record 10500
brun >/dev/null
is "the range start rounds DOWN to its 2048-block chunk" "$(rec)" "$SX"$'\t10240\tintermittent'
: > "$STUB_DMESG"
brun >/dev/null
is "no evidence: the 0:256 spot-check is recorded at chunk 0" "$(rec)" "$SX"$'\t0\tintermittent'

# No readable serial: evidence that cannot be tied to a drive is not filed
# under one. One line says so; nothing is recorded.
seed 12345
out=$(BR_SYS="$WORK/nosys" STUB_VERIFY_RC=1 STUB_READ_RC=1 brun)
has "no serial: the report says so, once" "$out" "no serial readable for /dev/sdX"
[ -z "$(rec)" ] && ok "no serial: nothing is recorded" || bad "no serial: nothing is recorded" "$(rec)"

# No VERIFY available (HAVE_SG=0) + READ failed = unresolved. Needs a PATH with
# no sg_verify/sg_logs at all; a runner with real sg3_utils cannot force it.
NOSG="$WORK/nosg"; mkdir -p "$NOSG"
for b in smartctl sg_read blockdev dmesg; do cp "$STUBDIR/$b" "$NOSG/"; done
if PATH="$NOSG:$PATH" command -v sg_verify >/dev/null 2>&1 || PATH="$NOSG:$PATH" command -v sg_logs >/dev/null 2>&1; then
    echo "SKIP  unresolved class (real sg3_utils on PATH; HAVE_SG cannot be forced to 0)"
else
    seed 12345
    STUB_READ_RC=1 BR_BIN="$NOSG" brun >/dev/null
    is "no VERIFY available + READ failed is recorded unresolved" "$(rec)" "$SX"$'\t10240\tunresolved'
fi
rm -f "$LEDGER"; : > "$STUB_DMESG"
```

- [ ] **Step 2: Run to verify it fails**

Run: `(cd tests && bash drive_triage_test.sh 2>&1 | grep -E '^(FAIL|SKIP)|drive_triage:')`
Expected: seven FAIL lines — the five `is` record cases, `no serial: the report says so, once`, and `unresolved` — then `drive_triage: FAILURES`. `and never by the slot ID` and `no serial: nothing is recorded` PASS already (nothing is recorded yet). No SKIP on this sandbox (no real sg3_utils).

- [ ] **Step 3: Implement**

(a) Directly above the line `SUMMARY=()` (just after `snap()`), add:

```bash
# The drive's own serial, the bad-range ledger's row key: VPD page 0x80 (unit
# serial number) as the kernel cached it at scan. A sysfs read sends no command
# to the drive, so it can never wake a sleeping one -- stricter than
# smartctl -n standby, which still asks the drive its power state. NOT the
# slot ID $STATE uses (the literal "manual" on a /dev/ run) and NOT the sd
# letter (it shifts). diagnose_lib.php's diag_disk_serial() reads the same file
# with the same normalization; change one and every row silently disappears
# from the Repair screen. Prints nothing when the page is absent.
# Test-only: TRIAGE_SYSFS replaces /sys. Never set in production.
br_serial() {  # dev
    local f="${TRIAGE_SYSFS:-/sys}/block/$1/device/vpd_pg80"
    [[ -r "$f" ]] || return 0
    tail -c +5 "$f" | LC_ALL=C tr -cd '[:print:]' | sed 's/^ *//; s/ *$//'
}

# One tested range's class, for the bad-range ledger: the four-way split
# sas-bench's discriminate() makes, applied per range instead of per disk.
#   media        VERIFY failed (READ either way) -- the drive's own platters
#   transport    VERIFY clean, READ failed       -- the link, not the drive
#   unresolved   no VERIFY (HAVE_SG=0), READ failed
#   intermittent nothing failed this run
br_class() {  # have_sg verify_failed read_failed
    if [[ "$1" -eq 1 && "$2" -eq 1 ]]; then echo media
    elif [[ "$3" -eq 1 && "$1" -eq 1 ]]; then echo transport
    elif [[ "$3" -eq 1 ]]; then echo unresolved
    else echo intermittent
    fi
}
```

(b) In `triage_disk()`, directly after `local ranges vfail=0 rfail=0 vran=0` add:

```bash
    # Ledger key: the drive's serial (br_serial). No serial, no rows --
    # evidence that cannot be tied to a drive must not be filed under one.
    local brser=""
    if [[ -n "$BADRANGE_STATE" ]]; then
        brser="$(br_serial "$dev")"
        [[ -n "$brser" ]] || info "bad-range ledger: no serial readable for /dev/$dev -- nothing recorded for it"
    fi
```

(c) Replace the whole range loop (from `for r in "${RL[@]}"; do` through its `done`) with:

```bash
    for r in "${RL[@]}"; do
        local rs="${r%%:*}" rc="${r##*:}" rv=0 rr=0
        log "  LBA $rs +$rc"
        if [[ $HAVE_SG -eq 1 ]]; then
            vran=1
            if run_verify "$dev" "$rs" "$rc"; then
                ok "  VERIFY clean -- drive read these blocks internally"
            else
                bad "  VERIFY failed -- drive cannot read its own media here"; vfail=1; rv=1
            fi
        fi
        if run_read "$dev" "$rs" "$rc"; then
            ok "  READ   clean"
        else
            bad "  READ   failed -- blocks could not cross the link"; rfail=1; rr=1
        fi
        # Rounded DOWN to its CHUNK: a region key for matching the same defect
        # across runs, never a write address (the range starts PAD below the
        # failing sector, so this chunk may precede it).
        [[ -n "$brser" ]] && printf '%s\t%s\t%s\n' "$brser" \
            "$(( rs / CHUNK * CHUNK ))" "$(br_class "$HAVE_SG" "$rv" "$rr")" >> "$RUN/badranges-run.tsv"
    done
```

(`brser` is only ever non-empty when `--badrange-state` was given, so this line also carries the flag-absent guarantee.)

- [ ] **Step 4: Run to verify it passes**

Run: `bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh && echo SYNTAX-OK`, then ENGINE `(cd tests && bash drive_triage_test.sh 2>&1 | grep -E '^(FAIL|SKIP)|drive_triage:')`
Expected: `SYNTAX-OK`, then exactly `drive_triage: all pass`. Task 1's `the flag adds no run file but its own record` must still PASS (it filters `badranges-run.tsv`), and `flag absent: …` now guards this task's `if [[ -n "$BADRANGE_STATE" ]]`.

Then SUITE → exactly the four known lines.

- [ ] **Step 5: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh tests/drive_triage_test.sh
git commit -F - <<'EOF'
Record each triaged range's class (media, transport, unresolved, intermittent) per drive serial and 2048-block chunk when --badrange-state is given. The serial is read from sysfs VPD 0x80, which never wakes a disk; with no readable serial nothing is recorded.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

- [ ] **Step 6: Mutation checks**

```bash
E=source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh
sed -i 's/then echo transport/then echo media/' $E
(cd tests && bash drive_triage_test.sh 2>&1 | grep '^FAIL')   # expect: VERIFY clean + READ failed is recorded transport
git checkout -- $E
sed -i 's|"\$(( rs / CHUNK \* CHUNK ))"|"$rs"|' $E
(cd tests && bash drive_triage_test.sh 2>&1 | grep '^FAIL')   # expect: the range start rounds DOWN ...; plus the media/transport/intermittent cases (they expect 10240, get 10345)
git checkout -- $E
sed -i 's/^        \[\[ -n "\$brser" \]\] && printf/        printf/' $E
(cd tests && bash drive_triage_test.sh 2>&1 | grep '^FAIL')   # expect: no serial: nothing is recorded
git checkout -- $E
sed -i 's/^    if \[\[ -n "\$BADRANGE_STATE" \]\]; then$/    if true; then/' $E
(cd tests && bash drive_triage_test.sh 2>&1 | grep '^FAIL')   # expect: flag absent: the report never mentions the ledger (the no-serial line now prints on a no-flag run)
git checkout -- $E
sed -i 's/tail -c +5 "\$f" |/cat "$f" |/' $E
(cd tests && bash drive_triage_test.sh 2>&1 | grep '^FAIL')   # expect: every "$SX" record case (the printable '0' length byte leaks into the key)
git checkout -- $E
git status --short
```

---

### Task 3: Merge the run's records into the ledger at end of run

The ledger itself. One awk pass, beside the existing `$STATE` write. Also updates the spec's Format/Update-logic text to the decisions this implements.

**Read closely:** `drive_triage.sh:884-903` (end-of-run baseline write; `$NOW`; the `KEEP_RUNS` trim after it) and `:318-343` (the slot scan — `DISKS_INI`, the `/dev/` override line, the disks.ini awk). The spec's "The new piece" section (lines 32-104) and "Deferred to Phase 2b" (lines 217-229). **Skim:** nothing else.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh` (after `info "baseline saved for …"`, ~line 899; the `DISKS_INI=` line, ~319)
- Modify: `docs/superpowers/specs/2026-09-28-disk-utility-repair-design.md` (Format section, Update logic, Deferred to Phase 2b)
- Test: `tests/drive_triage_test.sh` (append below Task 2's block)

**Interfaces:**
- Consumes: `$RUN/badranges-run.tsv` (Task 2), `$NOW`, `$STAMP`, `BADRANGE_STATE`, `SX`, `mkvpd`.
- Produces: `$BADRANGE_STATE`, 7+ tab-separated columns `serial chunk_start confirm_count first_run_id last_run_id last_class updated_ts`; report line `      bad-range ledger: N row(s) saved` when written. Awk helper `function bump(c) { return c == "media" ? 1 : 0 }` is the ONE place the media-only rule lives (the in-suite mutant targets that exact text). Test-only engine gate `TRIAGE_DISKS_INI` (replaces the hardcoded disks.ini path so the suite can run a multi-disk sweep).

- [ ] **Step 1: Write the failing tests**

Append below Task 2's block:

```bash
# ── The ledger: only media confirms. ───────────────────────────────────────
row() { awk -F'\t' -v s="$SX" -v c="$1" '$1==s && $2==c' "$LEDGER" 2>/dev/null; }
col() { row "$1" | cut -f"$2"; }

rm -f "$LEDGER"; seed 12345
STUB_VERIFY_RC=1 STUB_READ_RC=1 brun >/dev/null
[ "$(col 10240 3)" = 1 ] && ok "a media range on a fresh ledger creates a row at confirm_count 1" \
                         || bad "a media range on a fresh ledger creates a row at confirm_count 1" "row: $(row 10240)"
[ "$(col 10240 6)" = media ] && ok "and records last_class media" || bad "and records last_class media" "row: $(row 10240)"
run1=$(col 10240 4)
# Each triage sleeps 1s (TRIAGE_SKIP_SELFTEST_WAIT), so the next run's
# second-resolution STAMP is guaranteed to differ from run1.
seed 12500    # shifted start offset, same 2048-block chunk
STUB_VERIFY_RC=1 STUB_READ_RC=1 brun >/dev/null
[ "$(wc -l < "$LEDGER")" -eq 1 ] && ok "a shifted start inside the same chunk updates the row, not a new one" \
                                 || bad "a shifted start inside the same chunk updates the row, not a new one" "$(cat "$LEDGER")"
[ "$(col 10240 3)" = 2 ] && ok "a second media run makes it 2" || bad "a second media run makes it 2" "row: $(row 10240)"
[ "$(col 10240 4)" = "$run1" ] && ok "first_run_id is kept" || bad "first_run_id is kept" "row: $(row 10240)"
[ -n "$(col 10240 5)" ] && [ "$(col 10240 5)" != "$run1" ] && ok "last_run_id moves to the new run" \
                        || bad "last_run_id moves to the new run" "row: $(row 10240)"
seed 12345
brun >/dev/null    # both stubs clean: the chunk stopped failing
[ "$(col 10240 3)" = 2 ] && [ "$(col 10240 6)" = intermittent ] \
    && ok "a media row that comes back clean keeps its count and becomes intermittent, not removed" \
    || bad "a media row that comes back clean keeps its count and becomes intermittent, not removed" "row: $(row 10240)"

# THE discriminating case: an implementation that counts every failure
# reaches 2 here.
rm -f "$LEDGER"; seed 12345
STUB_READ_RC=1 brun >/dev/null
STUB_READ_RC=1 brun >/dev/null
[ "$(col 10240 3)" = 0 ] && [ "$(col 10240 6)" = transport ] \
    && ok "two transport runs on one chunk stay at confirm_count 0" \
    || bad "two transport runs on one chunk stay at confirm_count 0" "row: $(row 10240)"

# Mutation check, in-suite like the standby one: an engine whose bump() counts
# every non-intermittent class must be caught by the case above. If the sed
# stops matching, the mutant IS the engine and this would pass vacuously.
MUTL="$WORK/mutant-ledger.sh"
sed 's/function bump(c) { return c == "media" ? 1 : 0 }/function bump(c) { return c != "intermittent" ? 1 : 0 }/' "$DT" > "$MUTL"
if cmp -s "$DT" "$MUTL"; then
    bad "the transport assertion is able to fail" "sed matched nothing -- the mutant is identical to the engine"
else
    rm -f "$LEDGER"; seed 12345
    STUB_READ_RC=1 BR_ENGINE="$MUTL" brun >/dev/null
    STUB_READ_RC=1 BR_ENGINE="$MUTL" brun >/dev/null
    [ "$(col 10240 3)" = 2 ] && ok "the transport assertion is able to fail (mutant counted two transport runs to 2)" \
                             || bad "the transport assertion is able to fail" "mutant left the count at '$(col 10240 3)'"
fi

# No evidence, clean spot-check: nothing written -- not even an empty file.
rm -f "$LEDGER"; : > "$STUB_DMESG"
brun >/dev/null
[ ! -e "$LEDGER" ] && ok "a clean 0:256 spot-check on a disk with no evidence writes no ledger" \
                   || bad "a clean 0:256 spot-check on a disk with no evidence writes no ledger" "$(cat "$LEDGER")"

# A CLI sweep shares one ledger across disks: another disk's row on the SAME
# chunk, carrying a later extra column, passes through byte for byte; a
# malformed line is dropped on rewrite, not fatal.
printf 'not a row\nWDC_OTHER_SERIAL\t10240\t2\tr1\tr2\tmedia\t1\textra\n' > "$LEDGER"
seed 12345
STUB_VERIFY_RC=1 STUB_READ_RC=1 brun >/dev/null
grep -qx $'WDC_OTHER_SERIAL\t10240\t2\tr1\tr2\tmedia\t1\textra' "$LEDGER" \
    && ok "another disk's row on the same chunk passes through byte for byte" \
    || bad "another disk's row on the same chunk passes through byte for byte" "$(cat "$LEDGER")"
[ "$(col 10240 3)" = 1 ] && ok "this disk gets its own row for that chunk" || bad "this disk gets its own row for that chunk" "$(cat "$LEDGER")"
! grep -q '^not a row' "$LEDGER" && ok "a malformed row is dropped on rewrite" || bad "a malformed row is dropped on rewrite" "$(cat "$LEDGER")"

# A ledger path in a directory that does not exist (a CLI typo) must not
# abort the run, and the engine must not create the directory.
LEDGER_KEEP="$LEDGER"; LEDGER="$WORK/nodir/ledger.tsv"; seed 12345
out=$(STUB_VERIFY_RC=1 brun); rc=$?
LEDGER="$LEDGER_KEEP"
[ $rc -eq 0 ] && has "a ledger path in a missing directory does not abort the run" "$out" "finished" \
              || bad "a ledger path in a missing directory does not abort the run" "exit $rc"
[ ! -e "$WORK/nodir" ] && ok "and the engine does not create that directory" \
                       || bad "and the engine does not create that directory" "$WORK/nodir exists"

# No readable serial: a media run on a fresh ledger writes no ledger at all.
rm -f "$LEDGER"; seed 12345
BR_SYS="$WORK/nosys" STUB_VERIFY_RC=1 STUB_READ_RC=1 brun >/dev/null
[ ! -e "$LEDGER" ] && ok "no serial: a media run writes no ledger" \
                   || bad "no serial: a media run writes no ledger" "$(cat "$LEDGER")"

# A CLI sweep (no /dev/ argument): two array disks, both failing on the SAME
# chunk, one ledger file. Each row must be filed under its own drive's serial
# -- never the slot IDs $STATE uses (SLOT_X/SLOT_Y here).
mkvpd sdY SERIALY00002
SWI="$WORK/disks.ini"
printf '[disk1]\nname="disk1"\ndevice="sdX"\nstatus="DISK_OK"\nid="SLOT_X"\n[disk2]\nname="disk2"\ndevice="sdY"\nstatus="DISK_OK"\nid="SLOT_Y"\n' > "$SWI"
printf 'kernel: sd 0:0:0:0: [sdX] tag#0 FAILED dev sdX, sector 12345 op 0x0\nkernel: sd 0:0:1:0: [sdY] tag#0 FAILED dev sdY, sector 12345 op 0x0\n' > "$STUB_DMESG"
rm -f "$LEDGER"; : > "$ARGS"; rm -rf "$WORK/sweep"
STUB_VERIFY_RC=1 STUB_READ_RC=1 PATH="$STUBDIR:$PATH" TRIAGE_SKIP_ROOT_CHECK=1 TRIAGE_SKIP_DEV_CHECK=1 \
    TRIAGE_SKIP_SELFTEST_WAIT=1 TRIAGE_SYSFS="$WORK/sys" TRIAGE_DISKS_INI="$SWI" \
    bash "$DT" --out "$WORK/sweep" --badrange-state "$LEDGER" --auto-triage --all >/dev/null 2>&1
grep -q "^$SX"$'\t10240\t1\t' "$LEDGER" && grep -q $'^SERIALY00002\t10240\t1\t' "$LEDGER" \
    && [ "$(wc -l < "$LEDGER")" -eq 2 ] \
    && ok "a CLI sweep files each disk under its own serial" \
    || bad "a CLI sweep files each disk under its own serial" "$(cat "$LEDGER" 2>&1)"
! grep -q '^SLOT_' "$LEDGER" 2>/dev/null && ok "and never under a slot ID" || bad "and never under a slot ID" "$(cat "$LEDGER")"
rm -f "$LEDGER"; : > "$STUB_DMESG"
```

- [ ] **Step 2: Run to verify it fails**

Run: `(cd tests && bash drive_triage_test.sh 2>&1 | grep -E '^FAIL|drive_triage:')`
Expected FAILs (no ledger is written yet): `a media range on a fresh ledger creates a row at confirm_count 1`, `and records last_class media`, `a shifted start …` (wc of a missing file), `a second media run makes it 2`, `last_run_id moves to the new run`, `a media row that comes back clean …`, `two transport runs on one chunk stay at confirm_count 0`, `the transport assertion is able to fail` (sed matched nothing), `this disk gets its own row for that chunk`, `a malformed row is dropped on rewrite`. Also FAIL: `a CLI sweep files each disk under its own serial` (no ledger, and without the `TRIAGE_DISKS_INI` gate the engine reads the real `/var/local/emhttp/disks.ini` and exits "cannot read"). Expected to PASS already (nothing writes the file yet): `first_run_id is kept` (empty = empty), `a clean 0:256 spot-check … writes no ledger`, `another disk's row … passes through byte for byte`, both missing-directory cases, `no serial: a media run writes no ledger`, and `and never under a slot ID`. Then `drive_triage: FAILURES`.

- [ ] **Step 3: Implement**

(a) Replace the line `DISKS_INI="/var/local/emhttp/disks.ini"` with:

```bash
# Test-only: TRIAGE_DISKS_INI points the slot scan at a fixture so the suite
# can run a multi-disk sweep without a real array. Never set in production.
DISKS_INI="${TRIAGE_DISKS_INI:-/var/local/emhttp/disks.ini}"
```

(b) Directly after the line `info "baseline saved for $(wc -l < "$STATE") disk(s)"` add:

```bash

# ============================================================================
# bad-range ledger (--badrange-state), one row per chunk that has ever failed:
#   serial chunk_start confirm_count first_run_id last_run_id last_class updated_ts
# Keyed by the drive's serial (br_serial) + chunk -- NOT $STATE's slot ID,
# which is "manual" on every /dev/ run. chunk_start is a region key for
# matching one defect across runs, never a write address. ONLY a media
# result raises confirm_count (bump): a transport range is healthy inside the
# drive, and remapping it would retire a good sector and leave the real fault
# -- cable, backplane, expander, HBA -- in place. One run folds its records per
# chunk to the worst class first, so it raises a count at most once. A chunk
# that stops failing keeps its row (last_class -> intermittent); intermittent
# never inserts, so a clean 0:256 spot-check writes nothing. Other disks' rows
# and any columns past the seventh pass through untouched; a malformed row is
# dropped. A run that never gets here (cancelled, killed) changes nothing.
# ============================================================================
BR_RUN="$RUN/badranges-run.tsv"
if [[ -n "$BADRANGE_STATE" && -s "$BR_RUN" ]]; then
    BR_IN="$BADRANGE_STATE"; [[ -f "$BR_IN" ]] || BR_IN=/dev/null
    # The ledger arrives on stdin ("-"): an operand containing "=" would be
    # taken by awk as a variable assignment and silently empty the file.
    awk -F'\t' -v OFS='\t' -v recs="$BR_RUN" -v run="$STAMP" -v now="$NOW" '
        function rank(c) { return c == "media" ? 3 : c == "transport" ? 2 : c == "unresolved" ? 1 : 0 }
        function bump(c) { return c == "media" ? 1 : 0 }
        FILENAME == recs {
            k = $1 OFS $2
            if (!(k in cls) || rank($3) > rank(cls[k])) cls[k] = $3
            next
        }
        NF < 7 || $2 !~ /^[0-9]+$/ || $3 !~ /^[0-9]+$/ { next }
        {
            k = $1 OFS $2
            if (k in cls) { $3 += bump(cls[k]); $5 = run; $6 = cls[k]; $7 = now; delete cls[k] }
            print
        }
        END { for (k in cls) if (cls[k] != "intermittent") print k, bump(cls[k]), run, run, cls[k], now }
    ' "$BR_RUN" - < "$BR_IN" > "$BADRANGE_STATE.new"
    if [[ -s "$BADRANGE_STATE.new" || -f "$BADRANGE_STATE" ]]; then
        mv -f "$BADRANGE_STATE.new" "$BADRANGE_STATE"
        info "bad-range ledger: $(wc -l < "$BADRANGE_STATE") row(s) saved"
    else
        rm -f "$BADRANGE_STATE.new"
    fi
fi
```

- [ ] **Step 4: Update the spec (same task — the spec is the contract)**

In `docs/superpowers/specs/2026-09-28-disk-utility-repair-design.md`, section "The new piece":

(a) Replace the code block line

```text
chunk_start  confirm_count  first_run_id  last_run_id  last_class  updated_ts
```

with

```text
serial  chunk_start  confirm_count  first_run_id  last_run_id  last_class  updated_ts
```

(b) Insert, as the first bullet directly above the bullet that starts ``- `chunk_start` —``:

```markdown
- `serial` — the drive's own serial number, read from the kernel's cached copy of its VPD page
  0x80 (`/sys/block/<dev>/device/vpd_pg80`; 4-byte header skipped, printable ASCII kept, spaces
  trimmed): a sysfs read that sends no command to the drive, so it can never wake a sleeping one.
  The engine writes it and the PHP reader filters on it, from the same source with the same
  normalization, so the two cannot disagree. Not the slot ID `$STATE` uses — the literal `manual`
  on every web-launched `/dev/<disk>` run — and not the sd letter, which shifts. `$STATE` itself is
  unchanged. A multi-disk CLI sweep sharing one ledger file files each disk under its own serial,
  and a drive that takes over another's device name (and so its per-disk file) sees none of the
  previous drive's rows. No readable serial, no rows: the engine logs one line and records nothing
  for that disk, and the reader shows nothing rather than an unfiltered table. (Added during
  planning, amended 2026-09-28: the spec originally had no identity column.)
```

(b2) Replace these exact six lines (the `chunk_start` bullet, spec lines 52-57)

```markdown
- `chunk_start` — the tested range's start LBA, rounded down to its containing chunk. Chunk size
  is the existing `CHUNK="2048"` constant (`drive_triage.sh`'s targeted re-test chunk) — matching
  the granularity the engine already re-tests at, and the granularity a future `sg_reassign` call
  operates on, rather than inventing a second chunk size. Rounding, not exact-match, because two
  runs' own dmesg harvests are not guaranteed to report byte-identical start offsets for the same
  physical defect.
```

with

```markdown
- `chunk_start` — the tested range's start LBA, rounded down to its containing chunk. Chunk size
  is the existing `CHUNK="2048"` constant (`drive_triage.sh`'s targeted re-test chunk), rather than
  inventing a second chunk size. **The key identifies a region for cross-run matching only.** A
  tested range starts `PAD` (2000) blocks below the first failing sector the kernel reported, so
  the chunk it rounds to may precede the defect entirely; it is never an address to write to (see
  "Deferred to Phase 2b"). Rounding, not exact-match, because two runs' own dmesg harvests are not
  guaranteed to report byte-identical start offsets for the same physical defect. **Known limit,
  fail-safe:** if two runs' padded starts fall on opposite sides of a chunk boundary, the one defect
  lands in two rows at `confirm_count` 1 each and never confirms — a false negative, never a false
  confirmation.
```

(c) Replace these exact two lines (spec lines 77-78)

```markdown
- `first_run_id` / `last_run_id` — the job-id string of the earliest and most recent run that
  tested this chunk. Kept as two scalars, not a list, on purpose (see "Forward path to a full
```

with

```markdown
- `first_run_id` / `last_run_id` — the engine's own run stamp (`$STAMP`, the `YYYYmmdd-HHMMSS`
  name of the run's subdirectory) of the earliest and most recent run that tested this chunk — not
  the web job id, which the engine never receives and CLI runs do not have. Kept as two scalars,
  not a list, on purpose (see "Forward path to a full
```

(d) After the ``- `updated_ts` — unix seconds, last write.`` bullet add:

```markdown
- Columns past the seventh are allowed — the forward path below adds columns, not a new shape.
  The engine passes them through when it rewrites a row and the PHP reader ignores them. A row
  with fewer than seven columns or a non-numeric `chunk_start`/`confirm_count` is dropped when the
  engine rewrites the file and skipped by the reader.
```

(e) In "**Update logic.**", directly after the line ending `that record:` insert as the first bullet:

```markdown
- within one run, several results for the same chunk fold to the worst (`media` > `transport` >
  `unresolved` > `intermittent`) first, so a single run raises a chunk's `confirm_count` at most
  once — which is what makes it a count of *distinct* runs;
```

(f) In "## Deferred to Phase 2b (explicitly not decided here)", directly after the last bullet (`- The full per-run audit trail noted above as a forward-compatible but unbuilt extension.`) add:

```markdown
- **Locating the write target — a hard rule, not an open question.** Before any write, a fresh
  fine-grained VERIFY over the confirmed region locates the exact failing LBA(s), and only those
  are ever written. Ledger `chunk_start` values are region keys for cross-run matching and are
  never written to directly. (Added 2026-09-28.)
- **Where the ledger and baseline persist.** Both live in `/tmp` today, so a reboot clears them,
  and flash (`/boot`) writes are refused by design. Phase 2b decides whether they move to a
  persistent non-flash location and what happens when none is available. Loss fails safe: lost
  evidence means "not confirmed", never "confirmed". (Added 2026-09-28.)
```

- [ ] **Step 5: Run to verify it passes**

Run: `bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh && echo SYNTAX-OK`, then ENGINE `(cd tests && bash drive_triage_test.sh 2>&1 | grep -E '^(FAIL|SKIP)|drive_triage:')`
Expected: `SYNTAX-OK`, then exactly `drive_triage: all pass` (which includes `PASS  the transport assertion is able to fail (mutant counted two transport runs to 2)`).

Then SUITE → exactly the four known lines.

- [ ] **Step 6: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh tests/drive_triage_test.sh docs/superpowers/specs/2026-09-28-disk-utility-repair-design.md
git commit -F - <<'EOF'
Keep the cross-run bad-range ledger: merge each run's per-range classes into --badrange-state at end of run, keyed by drive serial, where only a media result raises confirm_count. Spec updated for the serial column, the region-only chunk key and its boundary-straddle limit, the run-stamp run id, forward-compatible columns, and two Phase 2b items (fine-grained VERIFY before any write; where the ledger persists).

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

- [ ] **Step 7: Mutation checks** (the transport mutant is already in-suite)

```bash
E=source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh
sed -i 's/ if (cls\[k\] != "intermittent")//' $E
(cd tests && bash drive_triage_test.sh 2>&1 | grep '^FAIL')   # expect: a clean 0:256 spot-check on a disk with no evidence writes no ledger
git checkout -- $E
sed -i 's/; delete cls\[k\]//' $E
(cd tests && bash drive_triage_test.sh 2>&1 | grep '^FAIL')   # expect: a shifted start inside the same chunk updates the row, not a new one (and others reading the duplicated row)
git checkout -- $E
sed -i 's|/block/\$1/device/vpd_pg80|/block/sdX/device/vpd_pg80|' $E
(cd tests && bash drive_triage_test.sh 2>&1 | grep '^FAIL')   # expect: a CLI sweep files each disk under its own serial (both disks read sdX's serial -> one row)
git checkout -- $E
git status --short
```

---

### Task 4: PHP library — ledger path, serial, serial-filtered reader, and `diag_array_disk()` (fixes a Phase 1 bug)

Pure functions in the dispatch-free library, each directly testable. `diag_array_disk()` is NOT a verbatim extraction: it answers "assigned anywhere" through `unraid_disk_roles()`, which fixes the shipped Phase 1 bug where a parity, parity2 or pool disk with a MEDIA verdict was told "This drive is not assigned to the array or a pool". No dispatch changes yet: Task 6 switches the verdict action and the sidebar to this helper and its reader, which is when the fix reaches the screens.

**Read closely:** `source/usr/local/emhttp/plugins/hbaviewer/diagnose_lib.php:12-20` (const block) and `:73-79` (`diag_baseline_path`, the pattern); `render/baymap.php:20-45` (`unraid_disk_roles()` — the reader to reuse; note it returns `[]` for BOTH a missing and an unparseable file, so fail-closed needs its own check first, and its default parameter `UNRAID_DISKINI` is only defined in `ajax_info.php`, so always pass the path explicitly); `tests/ajax_render_test.php:811-833` (how roles are already tested — quoted `["parity"]` section names, `name=` lines); `diagnose.php:172-186` (the inline walk being replaced); `render/diagnose.php:145-168` (`diag_next_steps()` MEDIA wording); `tests/diagnose_test.php:1-50` and `:66-71`. **Skim:** the rest of `diagnose_lib.php` and `baymap.php` (functions only — requiring it has no side effects).

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/diagnose_lib.php` (const block ~15-16; `require_once` of `render/baymap.php` at the top; new functions after `diag_baseline_path()`, ~line 79)
- Modify: `docs/superpowers/specs/2026-09-28-disk-utility-repair-design.md` (Array-disk paragraph; Surface area)
- Test: `tests/diagnose_test.php` (after the baseline-path check, ~line 71)
- Test: `tests/diagnose_render_test.php` (one `require_once` at the top; one block above the final `echo`)

**Interfaces:**
- Consumes: the ledger format from Task 3; `br_serial`'s normalization from Task 2; `unraid_disk_roles(string $disksIni): array` (`render/baymap.php`, existing: `"/dev/sdp" => "Parity"`, `"/dev/nvme0n1" => "Cache"`, slots with `device=""` skipped).
- Produces:
  - `const DIAG_DISKS_INI = '/var/local/emhttp/disks.ini';` (in `diagnose_lib.php`, beside `DIAG_ROOT`)
  - `diag_badrange_path(string $disk, string $root = DIAG_ROOT): string` → `"$root/$disk.badranges.tsv"`
  - `diag_disk_serial(string $disk, string $sys = '/sys'): string` → the serial from `$sys/block/$disk/device/vpd_pg80` (skip 4 bytes, keep `\x20-\x7E`, trim spaces), `''` when absent, too short, or the name is invalid.
  - `diag_badranges_read(string $file, string $serial): array` → list, in file order, of `['chunk_start'=>int, 'confirm_count'=>int, 'first_run'=>string, 'last_run'=>string, 'last_class'=>string, 'updated'=>int]` for rows whose first column === `$serial`; `[]` when `$serial === ''` or the file is missing.
  - `diag_array_disk(string $disk, string $iniFile = DIAG_DISKS_INI): bool` → true for any device `unraid_disk_roles()` names (parity, parity2, diskN, pools); true when disks.ini is unreadable; false otherwise.

- [ ] **Step 1: Write the failing tests**

(a) In `tests/diagnose_test.php`, directly after

```php
check('a baseline path sits under the root, keyed by disk',
      diag_baseline_path('sdb', $root) === "$root/sdb.baseline.tsv");
```

insert:

```php

/* ── the bad-range ledger: a stable per-disk path ─────────────────────────
   Same sibling-of-the-job-dirs placement as the baseline, for the same reason:
   the ledger spans runs, so it must not live where diag_trim_runs() sweeps. */
check('a ledger path sits under the root, keyed by disk',
      diag_badrange_path('sdb', $root) === "$root/sdb.badranges.tsv");
check('and it is not the baseline file',
      diag_badrange_path('sdb', $root) !== diag_baseline_path('sdb', $root));

/* ── the drive's serial: sysfs VPD 0x80, never a command to the drive ─────
   Same source and normalization as drive_triage.sh's br_serial(). The length
   byte is 0x30 -- printable -- so a reader that fails to skip the 4-byte
   header is caught rather than having the header stripped as non-printable. */
$sys = "$root/sys";
@mkdir("$sys/block/sdb/device", 0777, true);
file_put_contents("$sys/block/sdb/device/vpd_pg80", "\x00\x80\x00\x30  SERIALB00001  ");
check('the serial is read from VPD 0x80, header skipped, padding trimmed',
      diag_disk_serial('sdb', $sys) === 'SERIALB00001');
check('no VPD page means no serial', diag_disk_serial('sdc', $sys) === '');
@mkdir("$sys/block/sdd/device", 0777, true);
file_put_contents("$sys/block/sdd/device/vpd_pg80", "\x00\x80\x00\x00");
check('a header-only page means no serial', diag_disk_serial('sdd', $sys) === '');
check('an invalid disk name reads nothing', diag_disk_serial('../sdb', $sys) === '');

/* ── the reader: this drive's rows only, forgiving about everything else ── */
$led = "$root/sdb.badranges.tsv";
check('a missing ledger is zero rows, not an error', diag_badranges_read($led, 'S1') === []);
file_put_contents($led, implode("\n", [
    "S1\t10240\t2\t20260101-000000\t20260102-000000\tmedia\t1767312000",
    "S1\t20480\t0\t20260101-000000\t20260102-000000\ttransport\t1767312000",
    "S1\tNaN\t1\ta\tb\tmedia\t1",                      // non-numeric chunk_start
    "S1\t30720\t1\ta\tb",                              // too few columns
    "S1\t40960\t1\ta\tb\tbogus\t1",                    // a class the engine never writes
    "S1\t51200\t1\ta\tb\tmedia\t1\textra",             // a later column: the forward path
    "S1\t61440\t1\ta\tb\tintermittent\t5\r",           // hand-edited on Windows
    "OTHER\t10240\t2\ta\tb\tmedia\t1",                 // a previous drive on this sdX name
    "\t71680\t2\ta\tb\tmedia\t1",                      // no serial at all
    "",
]));
$rows = diag_badranges_read($led, 'S1');
check('only this drive\'s well-formed rows are read', count($rows) === 4);
check('a row carries chunk_start and confirm_count as ints',
      $rows[0]['chunk_start'] === 10240 && $rows[0]['confirm_count'] === 2);
check('and its last class and last run',
      $rows[0]['last_class'] === 'media' && $rows[0]['last_run'] === '20260102-000000');
check('a trailing extra column does not reject a row', $rows[2]['chunk_start'] === 51200);
check('a CRLF line still reads, updated_ts intact',
      $rows[3]['last_class'] === 'intermittent' && $rows[3]['updated'] === 5);
// OTHER also has a row at 10240: unfiltered, this drive would show two.
check('a row filed under another serial is not shown',
      count(array_filter($rows, fn($r) => $r['chunk_start'] === 10240)) === 1
      && count(diag_badranges_read($led, 'OTHER')) === 1);
// Unknown serial must show NOTHING -- not the unfiltered file, and not the
// row whose key is empty, which an equality test alone would match.
check('an unknown serial reads nothing', diag_badranges_read($led, '') === []);
@unlink($led);

/* ── assigned-ness: assigned ANYWHERE, one reader ─────────────────────────
   Parity, parity2 and pool members are assigned: the old inline ^disk\d+$
   test called them unassigned, and a parity disk's MEDIA verdict got the
   unassigned-disk advice. An unreadable disks.ini must mean ASSIGNED, the
   stricter answer, which never suggests writing to the disk. */
check('an unreadable disks.ini means ASSIGNED', diag_array_disk('sdb', "$root/nope.ini") === true);
$iniF = "$root/disks.ini";
file_put_contents($iniF, "[\"parity\"]\nname=\"parity\"\ndevice=\"sdp\"\n"
                       . "[\"parity2\"]\nname=\"parity2\"\ndevice=\"sdq\"\n"
                       . "[\"disk1\"]\nname=\"disk1\"\ndevice=\"sdb\"\n"
                       . "[\"disk2\"]\nname=\"disk2\"\ndevice=\"\"\n"
                       . "[\"cache\"]\nname=\"cache\"\ndevice=\"nvme0n1\"\n");
check('an array data slot is assigned',   diag_array_disk('sdb', $iniF) === true);
check('parity is assigned',               diag_array_disk('sdp', $iniF) === true);
check('parity2 is assigned',              diag_array_disk('sdq', $iniF) === true);
check('a pool member is assigned',        diag_array_disk('nvme0n1', $iniF) === true);
check('a device in no slot is not',       diag_array_disk('sdz', $iniF) === false);
@unlink($iniF);
foreach (['sdb', 'sdd'] as $d) { @unlink("$sys/block/$d/device/vpd_pg80"); @rmdir("$sys/block/$d/device"); @rmdir("$sys/block/$d"); }
@rmdir("$sys/block"); @rmdir($sys);
```

(b) In `tests/diagnose_render_test.php`, directly below the existing `require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/render/diagnose.php';` add:

```php
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/diagnose_lib.php';
```

and directly above `echo $fails === 0 ? "diagnose_render: all pass\n" …` insert:

```php
/* ── the Phase 1 bug this phase fixes: parity and pool are ASSIGNED ─────────
   End to end through diag_array_disk(): a MEDIA verdict on a parity or pool
   disk must get the rebuild advice, never "not assigned to the array or a
   pool" -- which invites writing to a disk parity or the pool depends on. */
$pini = sys_get_temp_dir() . '/hbav_render_ini_' . getmypid() . '.ini';
file_put_contents($pini, "[\"parity\"]\nname=\"parity\"\ndevice=\"sdp\"\n"
                       . "[\"cache\"]\nname=\"cache\"\ndevice=\"sdc\"\n");
foreach (['sdp' => 'parity', 'sdc' => 'pool'] as $dev => $what) {
    $h = renderDiagVerdict(['disk' => $dev, 'verdict' => 'MEDIA', 'why' => '', 'events' => [],
        'sense' => [], 'max_cmd_age' => null, 'array_disk' => diag_array_disk($dev, $pini),
        'ports' => [], 'recent' => []]);
    check("a $what disk's MEDIA verdict gets the rebuild advice, not the unassigned one",
          str_contains($h, 'REBUILD') && !str_contains($h, 'not assigned to the array or a pool'));
}
@unlink($pini);

```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/diagnose_test.php 2>&1 | grep -E '^(FAIL|Fatal error|PHP Fatal)|all pass|FAILED' | head -3` → `… Fatal error: Uncaught Error: Call to undefined function diag_badrange_path()`.
Run: `php tests/diagnose_render_test.php 2>&1 | grep -E 'Fatal|FAILED|all pass' | head -2` → `… Call to undefined function diag_array_disk()`.

- [ ] **Step 3: Implement**

(a) In `diagnose_lib.php`, directly after the closing `*/` of the file's header comment (before the `/* Every const the CLI test runner reaches …` comment) add:

```php
/* unraid_disk_roles(): the one disks.ini reader the SMART tab, the bay map
   and (from Task 6) the Diagnose sidebar use. render/baymap.php holds only
   function declarations, so requiring it here has no side effects. */
require_once __DIR__ . '/render/baymap.php';
```

(b) In the const block, directly after `const DIAG_SCRIPTS = '/usr/local/emhttp/plugins/hbaviewer/scripts';` add:

```php
/* Unraid's slot assignments. Passed explicitly to unraid_disk_roles(): that
   function's own default, UNRAID_DISKINI, is declared only in ajax_info.php. */
const DIAG_DISKS_INI = '/var/local/emhttp/disks.ini';
```

(c) Directly after the closing `}` of `diag_baseline_path()` add:

```php

/* The bad-range ledger drive_triage.sh keeps under --badrange-state: one row
   per 2048-block chunk that has ever failed a check, across runs. A stable
   per-disk sibling of the job directories, like the baseline and for the same
   reason -- it spans runs, so diag_trim_runs() must never reach it. */
function diag_badrange_path(string $disk, string $root = DIAG_ROOT): string {
    return $root . '/' . $disk . '.badranges.tsv';
}

/* The drive's serial: VPD page 0x80 (unit serial number) as the kernel cached
   it at scan. A sysfs read sends no command to the drive, so it cannot wake
   one, and it is not a shell-out. SAME source and normalization as
   drive_triage.sh's br_serial(), which keys the ledger rows: skip the 4-byte
   page header, keep printable ASCII, trim spaces. Change one side alone and
   every row silently disappears from the Repair screen. '' when unreadable. */
function diag_disk_serial(string $disk, string $sys = '/sys'): string {
    if (!diag_disk_valid($disk)) return '';
    $raw = @file_get_contents("$sys/block/$disk/device/vpd_pg80");
    if (!is_string($raw) || strlen($raw) <= 4) return '';
    return trim((string) preg_replace('/[^\x20-\x7E]/', '', substr($raw, 4)), ' ');
}

/* THIS drive's ledger rows, in file order. Columns: serial, chunk_start,
   confirm_count, first_run_id, last_run_id, last_class, updated_ts -- and any
   later ones, ignored (the forward path adds columns, not a new shape).
   Filtered to $serial: the file is named by sd letter, and a drive that takes
   over that letter must not inherit its predecessor's evidence. An unknown
   serial reads NOTHING, never the unfiltered file. A missing file is zero
   rows. A malformed row -- too few columns, a non-numeric chunk or count, a
   class the engine never writes -- is skipped, not fatal: the
   fail-closed-per-row rule diag_slice() follows. */
function diag_badranges_read(string $file, string $serial): array {
    if ($serial === '') return [];
    $rows = [];
    foreach (explode("\n", (string) @file_get_contents($file)) as $line) {
        $c = explode("\t", rtrim($line, "\r"));
        if (count($c) < 7 || $c[0] !== $serial || !ctype_digit($c[1]) || !ctype_digit($c[2])
            || !in_array($c[5], ['media', 'transport', 'intermittent', 'unresolved'], true)) continue;
        $rows[] = ['chunk_start' => (int) $c[1], 'confirm_count' => (int) $c[2],
                   'first_run'   => $c[3],       'last_run'      => $c[4],
                   'last_class'  => $c[5],       'updated'       => (int) $c[6]];
    }
    return $rows;
}

/* Has Unraid assigned $disk ANYWHERE -- parity, parity2, a diskN slot, or a
   pool? Asked of unraid_disk_roles(), the same reader the SMART tab, bay map
   and Diagnose sidebar use, so no two screens can disagree about one disk.
   Called by the verdict AND repair actions. Wrong in the permissive direction,
   a screen would offer unassigned-disk content for a disk parity or a pool
   depends on, so an unreadable disks.ini answers ASSIGNED -- checked here
   first, because unraid_disk_roles() returns [] for both "unreadable" and
   "nothing assigned". Replaces the verdict action's old inline ^disk\d+$
   test, which called parity and pool disks unassigned (a Phase 1 bug). */
function diag_array_disk(string $disk, string $iniFile = DIAG_DISKS_INI): bool {
    if (!is_array(@parse_ini_file($iniFile, true))) return true;
    return isset(unraid_disk_roles($iniFile)["/dev/$disk"]);
}
```

- [ ] **Step 4: Update the spec**

In `docs/superpowers/specs/2026-09-28-disk-utility-repair-design.md`:

(a) In "## The Repair screen", "**Array (assigned) disk:**" paragraph, replace the exact text running from `Reuses the` (end of spec line 128) through `not duplicated.` (line 130):

```markdown
Reuses the
`$arrayDisk` boolean the `verdict` action already computes from `disks.ini` — extracted into a
small shared helper so the two call sites cannot disagree, not duplicated.
```

with

```markdown
Uses
`diag_array_disk()`, one small helper both the `verdict` and `repair` actions call, so the two
cannot disagree. It answers "assigned anywhere" — parity, parity2, any `diskN`, or a pool member —
by asking `unraid_disk_roles()`, the disks.ini reader the SMART tab, bay map and Diagnose sidebar
use; false only for a disk Unraid has not assigned, and true when `disks.ini` is unreadable. This
replaces the `verdict` action's inline `^disk\d+$` test, which classed parity and pool disks as
unassigned and gave a parity disk's MEDIA verdict the unassigned-disk advice — a Phase 1 bug fixed
here (amended 2026-09-28).
```

(b) In "## Surface area", replace these exact three lines (spec 151-153)

```markdown
- `diagnose_lib.php`: `diag_badrange_path($disk)` (mirrors `diag_baseline_path()`); a small
  `diag_array_disk($disk)` helper extracted from `verdict`'s existing inline `disks.ini` check, so
  `verdict` and the new `repair` action share one implementation.
```

with

```markdown
- `diagnose_lib.php`: `diag_badrange_path($disk)` (mirrors `diag_baseline_path()`); a small
  `diag_array_disk($disk)` helper (assigned anywhere, via `unraid_disk_roles()`) replacing
  `verdict`'s inline `disks.ini` check, so `verdict` and the new `repair` action share one
  implementation; `diag_disk_serial($disk)`, the sysfs VPD 0x80 serial read; and
  `diag_badranges_read($file, $serial)`, the ledger row reader, which returns only rows filed under
  that serial and nothing when the serial is unknown.
```

- [ ] **Step 5: Run to verify it passes**

Run: `php -l source/usr/local/emhttp/plugins/hbaviewer/diagnose_lib.php`, then DIAG (Global Constraints) → exactly the four `all pass` lines (`diagnose_php_test.php`'s `the shared library has no dispatch guard` must still PASS — `baymap.php` is required, not inlined); then SUITE → exactly the four known lines.

- [ ] **Step 6: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/diagnose_lib.php tests/diagnose_test.php tests/diagnose_render_test.php docs/superpowers/specs/2026-09-28-disk-utility-repair-design.md
git commit -F - <<'EOF'
Add diag_badrange_path(), diag_disk_serial() (sysfs VPD 0x80, the same key the engine writes) and a ledger reader that returns only the current drive's rows. Phase 1 fix: diag_array_disk() answers "assigned anywhere" through unraid_disk_roles(), so a parity, parity2 or pool disk is no longer told it is unassigned.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

- [ ] **Step 7: Mutation checks**

```bash
L=source/usr/local/emhttp/plugins/hbaviewer/diagnose_lib.php
# The OLD rule, ^disk\d+$ ("Disk N" is the only label a diskN slot gets):
sed -i 's|return isset(unraid_disk_roles(\$iniFile)\["/dev/\$disk"\]);|return str_starts_with(unraid_disk_roles($iniFile)["/dev/$disk"] ?? "", "Disk ");|' $L
php tests/diagnose_test.php | grep '^FAIL'          # expect exactly: parity is assigned; parity2 is assigned; a pool member is assigned
php tests/diagnose_render_test.php | grep '^FAIL'   # expect exactly: a parity disk's MEDIA verdict ...; a pool disk's MEDIA verdict ...
git checkout -- $L
sed -i 's/if (!is_array(@parse_ini_file(\$iniFile, true))) return true;/if (!is_array(@parse_ini_file($iniFile, true))) return false;/' $L
php tests/diagnose_test.php | grep '^FAIL'   # expect: an unreadable disks.ini means ASSIGNED
git checkout -- $L
sed -i 's/if (count(\$c) < 7 || \$c\[0\] !== \$serial ||/if (count($c) < 7 ||/' $L
php tests/diagnose_test.php | grep '^FAIL'   # expect: only this drive's well-formed rows are read; a row filed under another serial is not shown
git checkout -- $L
sed -i "s/    if (\$serial === '') return \[\];//" $L
php tests/diagnose_test.php | grep '^FAIL'   # expect: an unknown serial reads nothing (the empty-key row leaks)
git checkout -- $L
sed -i 's/substr(\$raw, 4)/$raw/' $L
php tests/diagnose_test.php | grep '^FAIL'   # expect: the serial is read from VPD 0x80, header skipped, padding trimmed
git checkout -- $L
git status --short
```

---

### Task 5: `renderDiagRepair()` — the Repair screen, read-only

**Read closely:** `source/usr/local/emhttp/plugins/hbaviewer/render/diagnose.php:92-249` (`DIAG_CMD_AGE_TIMEOUT` const placement, `diag_next_steps()` array-disk wording, `renderDiagVerdict()` card/table/escaping shape); `render/table.php:10-30` (`luTable` — headers become `<button class="lu-sort" onclick="luSort(this)">`, cells are NOT escaped by luTable). `tests/diagnose_render_test.php` whole file (143 lines). The spec's "## The Repair screen" section. **Skim:** `renderDiagDriveList()`.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/render/diagnose.php` (append after `renderDiagVerdict()`, before the `DIAG_BADGE_RANK` block)
- Modify: `docs/superpowers/specs/2026-09-28-disk-utility-repair-design.md` (the link-line rule)
- Test: `tests/diagnose_render_test.php` (append above the final `echo`)

**Interfaces:**
- Consumes: row shape from `diag_badranges_read()` and the serial from `diag_disk_serial()` (Task 4).
- Produces: `const DIAG_CONFIRM_MIN = 2;`, `const DIAG_LEDGER_CHUNK = 2048;`, `renderDiagRepair(array $in): string` with keys `disk` (string), `array_disk` (bool), `serial` (string; `''` = unknown), `rows` (array, already filtered to that serial). Its only non-sort handler is `luDiagShow('verdict')`.

- [ ] **Step 1: Write the failing tests**

In `tests/diagnose_render_test.php`, insert directly above `echo $fails === 0 ? "diagnose_render: all pass\n" …` (i.e. below the block Task 4 added):

```php
/* ── the Repair screen (Phase 2a): evidence, never a control ───────────── */
$ledger = [
    ['chunk_start' => 10240, 'confirm_count' => 2, 'first_run' => 'r1', 'last_run' => '20260102-000000', 'last_class' => 'media',        'updated' => 1],
    ['chunk_start' => 20480, 'confirm_count' => 1, 'first_run' => 'r1', 'last_run' => 'r1',              'last_class' => 'media',        'updated' => 1],
    ['chunk_start' => 30720, 'confirm_count' => 0, 'first_run' => 'r1', 'last_run' => 'r2',              'last_class' => 'transport',    'updated' => 1],
    ['chunk_start' => 4096,  'confirm_count' => 3, 'first_run' => 'r0', 'last_run' => 'r3',              'last_class' => 'intermittent', 'updated' => 1],
    ['chunk_start' => 8192,  'confirm_count' => 2, 'first_run' => 'r0', 'last_run' => 'r4',              'last_class' => 'transport',    'updated' => 1],
];
$rep = renderDiagRepair(['disk' => 'sdc', 'array_disk' => false, 'serial' => 'S1', 'rows' => $ledger]);
check('unassigned: every confirm_count >= 2 row is listed',
      str_contains($rep, '<td>10240</td>') && str_contains($rep, '<td>4096</td>') && str_contains($rep, '<td>8192</td>'));
check('unassigned: a single-run media row is not', !str_contains($rep, '20480'));
check('unassigned: a never-confirmed transport row is not in the table', !str_contains($rep, '30720'));
check('but it is counted in the link line', str_contains($rep, '1 range failed only over the link'));
// A row media-confirmed twice whose LATEST run was transport did fail VERIFY
// twice: it belongs in the table, and "failed only over the link" is false for it.
check('a confirmed row whose latest run was transport stays in the table and out of the link count',
      str_contains($rep, '<td>8192</td>') && !str_contains($rep, '2 ranges failed only over the link'));
check('rows are ordered by start LBA',
      strpos($rep, '<td>4096</td>') < strpos($rep, '<td>8192</td>')
      && strpos($rep, '<td>8192</td>') < strpos($rep, '<td>10240</td>'));
check('the block count is the chunk size', str_contains($rep, '<td>2048</td>'));
preg_match_all('/onclick="([A-Za-z]+)\(/', $rep, $m);
check('the only handlers are navigation and column sort -- no repair control',
      array_diff(array_unique($m[1]), ['luDiagShow', 'luSort']) === []
      && !preg_match('/<(input|select|textarea)\b/', $rep));
check('nothing on the screen names a repair tool',
      !str_contains($rep, 'sg_reassign') && !str_contains($rep, 'write-sector'));

$arr = renderDiagRepair(['disk' => 'sdb', 'array_disk' => true, 'rows' => $ledger]);
check('array disk: direct sector repair is refused, and why', str_contains(strtolower($arr), 'bypasses parity'));
check('array disk: all three options are shown together',
      str_contains($arr, 'Replace') && str_contains($arr, 'Rebuild onto itself')
      && str_contains($arr, 'Keep in service and watch'));
check('array disk: no table, even when the ledger has confirmed rows',
      !str_contains($arr, '<table') && !str_contains($arr, '10240'));
preg_match_all('/onclick="([A-Za-z]+)\(/', $arr, $m);
check('array disk: no control beyond navigation', array_diff(array_unique($m[1]), ['luDiagShow']) === []);

$none = renderDiagRepair(['disk' => 'sdc', 'array_disk' => false, 'serial' => 'S1', 'rows' => [$ledger[2]]]);
check('empty state: no media range yet says so', str_contains(strtolower($none), 'no media-class range'));
check('and is not a false all-clear', !str_contains(strtolower($none), 'no issues'));
check('and still counts the transport row', str_contains($none, '1 range failed only over the link'));
$one = renderDiagRepair(['disk' => 'sdc', 'array_disk' => false, 'serial' => 'S1', 'rows' => [$ledger[1]]]);
check('empty state: media on one run only says a second run is needed',
      str_contains(strtolower($one), 'one run only') && str_contains(strtolower($one), 'second run'));
$zero = renderDiagRepair(['disk' => 'sdc', 'array_disk' => false, 'serial' => 'S1', 'rows' => []]);
check('an empty ledger is the no-media note, not a blank screen',
      str_contains(strtolower($zero), 'no media-class range'));
check('both empty states point back to Diagnose',
      str_contains($none, 'Run Diagnose on this drive again') && str_contains($one, 'Run Diagnose on this drive again'));
// Unknown serial: the history cannot be matched to this drive, so it shows
// NOTHING -- not a table (possibly another drive's), and not the no-media note
// (which would read as a finding about this drive).
$nos = renderDiagRepair(['disk' => 'sdc', 'array_disk' => false, 'serial' => '', 'rows' => $ledger]);
check('unknown serial: says the history cannot be matched',
      str_contains(strtolower($nos), 'serial could not be read'));
check('unknown serial: no table and no ledger-derived note',
      !str_contains($nos, '<table') && !str_contains($nos, '10240')
      && !str_contains(strtolower($nos), 'no media-class range') && !str_contains($nos, 'failed only over the link'));

$evilR = renderDiagRepair(['disk' => '<img src=x>', 'array_disk' => false, 'serial' => 'S1', 'rows' => [
    ['chunk_start' => 1, 'confirm_count' => 2, 'first_run' => 'a', 'last_run' => '<script>bad()</script>',
     'last_class' => 'media', 'updated' => 1]]]);
check('repair: the disk name is escaped', !str_contains($evilR, '<img src=x>'));
check('repair: ledger text is escaped',   !str_contains($evilR, '<script>bad()'));

// The "Blocks" column is the engine's CHUNK. Two languages, one number.
$sh = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh');
check("the displayed block count is the engine's own CHUNK",
      preg_match('/^CHUNK="(\d+)"/m', $sh, $cm) === 1 && (int) $cm[1] === DIAG_LEDGER_CHUNK);

```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/diagnose_render_test.php 2>&1 | grep -E 'Fatal|FAILED|all pass' | head -2`
Expected: `… Fatal error: Uncaught Error: Call to undefined function renderDiagRepair()`.

- [ ] **Step 3: Implement**

In `render/diagnose.php`, directly after the closing `}` of `renderDiagVerdict()` (before the `/* Worst-first, because …` comment of `DIAG_BADGE_RANK`), add:

```php

/* ── Repair screen (Phase 2a) ─────────────────────────────────────────────
 * READ-ONLY EVIDENCE. No checkbox, no typed confirmation, no action button: a
 * control that visibly exists and silently does nothing is the defect
 * #diag-op/#diag-standby already are, and Phase 2b's guarded execution flow is
 * what such controls will be wired to. The one button here navigates back.
 *
 * $in keys: disk (string), array_disk (bool, diag_array_disk()), serial
 * (string, diag_disk_serial(); '' = unknown), rows (array, diag_badranges_read()
 * -- already filtered to that serial). "Confirmed" = failed SCSI VERIFY on
 * DIAG_CONFIRM_MIN separate runs; only a media result ever raises
 * confirm_count (the engine's rule, drive_triage.sh's ledger block). */
const DIAG_CONFIRM_MIN  = 2;
const DIAG_LEDGER_CHUNK = 2048;   // drive_triage.sh CHUNK -- pinned equal by test

function renderDiagRepair(array $in): string {
    $disk = (string) ($in['disk'] ?? '');
    $rows = (array) ($in['rows'] ?? []);
    $out  = '<div class="lu-card first"><div class="lu-tab-toolbar"><h3>Repair — <code>/dev/'
          . htmlspecialchars($disk) . '</code></h3>'
          . '<button class="lu-refresh-btn" type="button" onclick="luDiagShow(\'verdict\')">Back to verdict</button></div>';

    /* THE ASSIGNED-DISK RULE, same as diag_next_steps(): a block written
       straight to a disk Unraid has assigned -- array, parity or pool --
       bypasses parity or the pool's own redundancy. All three safe options,
       always together -- suppressing "watch" would need a per-run trend signal
       this phase does not have, so the screen does not pick one. */
    if (!empty($in['array_disk'])) {
        return $out
            . '<p>Direct sector repair isn\'t offered for disks Unraid has assigned — array, parity or pool: it bypasses parity (or the pool\'s own redundancy). A block written straight to an assigned disk is invisible to parity: the next parity check flags it as a mismatch, and a later rebuild could bring the bad data back.</p></div>'
            . '<div class="lu-card"><h4>Options to weigh</h4><ul>'
            . '<li><strong>Replace</strong> — rebuild onto a new drive and retire this one.</li>'
            . '<li><strong>Rebuild onto itself</strong> — rewrites every sector through parity, which lets the drive remap the ones it cannot read.</li>'
            . '<li><strong>Keep in service and watch</strong> — re-run Diagnose in a few hours and compare the counts between runs.</li>'
            . '</ul><p class="lu-muted" style="font-size:12px">All three are shown on purpose: which one fits depends on how the drive behaves across runs, and this screen does not pick for you.</p></div>';
    }

    /* The ledger is filed by drive serial. Without this drive's serial there
       is no way to tell its history from a previous occupant's of the same
       sdX name, so show nothing rather than something that may be wrong. */
    if ((string) ($in['serial'] ?? '') === '') {
        return $out . '<p class="lu-muted">This drive\'s serial could not be read, so its bad-range history cannot be matched to it. Nothing is shown rather than evidence that may belong to another drive.</p></div>';
    }

    $cnt = fn(array $r): int => (int) ($r['confirm_count'] ?? 0);
    $confirmed = array_values(array_filter($rows, fn($r) => $cnt($r) >= DIAG_CONFIRM_MIN));
    usort($confirmed, fn($a, $b) => (int) ($a['chunk_start'] ?? 0) <=> (int) ($b['chunk_start'] ?? 0));
    $single = count(array_filter($rows, fn($r) => $cnt($r) === 1));
    /* Transport AND never media-confirmed: a row that failed VERIFY on two
       runs stays a repair candidate whatever its latest run said. */
    $link = count(array_filter($rows, fn($r) => ($r['last_class'] ?? '') === 'transport' && $cnt($r) === 0));

    $out .= '<p>Blocks this drive failed to read internally (SCSI VERIFY) on at least '
          . DIAG_CONFIRM_MIN . ' separate runs. Repair execution is not in this release — this is the evidence it will act on.</p></div>'
          . '<div class="lu-card"><h4>Confirmed failing ranges</h4>';
    if ($confirmed !== []) {
        $out .= luTable(['Start LBA', 'Blocks', 'Media confirmations', 'Last class', 'Last seen run'],
                  array_map(fn($r) => array_map('htmlspecialchars', [
                      (string) (int) ($r['chunk_start'] ?? 0), (string) DIAG_LEDGER_CHUNK,
                      (string) $cnt($r), (string) ($r['last_class'] ?? ''), (string) ($r['last_run'] ?? ''),
                  ]), $confirmed))
              . '<p class="lu-muted" style="font-size:12px">Start LBA is where the tested range began, rounded down to a '
              . DIAG_LEDGER_CHUNK . '-block chunk.</p>';
    } elseif ($single > 0) {
        $out .= '<p class="lu-muted">No confirmed ranges yet. ' . $single . ' range' . ($single === 1 ? ' has' : 's have')
              . ' failed VERIFY on one run only — a second run that fails on the same blocks confirms them. Run Diagnose on this drive again from the Drives tab.</p>';
    } else {
        $out .= '<p class="lu-muted">No media-class range yet: no recorded run found this drive unable to read its own platters. A TRANSPORT verdict or intermittent results do not count toward repair. Run Diagnose on this drive again from the Drives tab if the fault recurs.</p>';
    }
    if ($link > 0) {
        $out .= '<p class="lu-muted">' . $link . ' range' . ($link === 1 ? '' : 's')
              . ' failed only over the link — these point at the cable, slot or HBA, not the drive, and are not repair candidates.</p>';
    }
    return $out . '</div>';
}
```

- [ ] **Step 4: Update the spec's link-line rule**

In the spec's "## The Repair screen", "**Unassigned disk:**" paragraph, replace these exact three lines

```markdown
`last_class` is `transport` ("N ranges failed only over the link — these point at the cable, slot
or HBA, not the drive, and are not repair candidates"), so a range the user saw flagged on the
Verdict screen does not silently disappear from Repair without a reason.
```

with

```markdown
`last_class` is `transport` and whose `confirm_count` is 0 ("N ranges failed only over the link —
these point at the cable, slot or HBA, not the drive, and are not repair candidates"), so a range
the user saw flagged on the Verdict screen does not silently disappear from Repair without a
reason. A row already confirmed by two `media` runs stays in the table even if its latest run was
`transport` — it did fail VERIFY twice, so "failed only over the link" would be false for it.
(Narrowed during planning, 2026-09-28.)
```

Then, in the "**Empty state:**" paragraph, replace its last line — exactly `message.` (spec line ~143) — with:

```markdown
message. An unassigned disk whose serial cannot be read shows neither a table nor a ledger-derived
note: one plain line saying its bad-range history cannot be matched to it, so nothing is shown
rather than evidence that may belong to another drive (amended 2026-09-28).
```

- [ ] **Step 5: Run to verify it passes**

Run: `php -l source/usr/local/emhttp/plugins/hbaviewer/render/diagnose.php`, then DIAG → exactly the four `all pass` lines (Task 4's parity/pool render checks included); SUITE → exactly the four known lines; and `ls source/usr/local/emhttp/plugins/hbaviewer/render/*.php | wc -l` → `9`.

- [ ] **Step 6: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/render/diagnose.php tests/diagnose_render_test.php docs/superpowers/specs/2026-09-28-disk-utility-repair-design.md
git commit -F - <<'EOF'
Add renderDiagRepair(): assigned-disk guidance with all three safe options, or the confirmed-range table for any other disk, with no repair control and nothing at all when the drive's serial is unknown. The link line excludes rows already media-confirmed; spec updated for both.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

- [ ] **Step 7: Mutation checks**

```bash
R=source/usr/local/emhttp/plugins/hbaviewer/render/diagnose.php
sed -i 's/\$cnt(\$r) >= DIAG_CONFIRM_MIN/$cnt($r) >= 1/' $R
php tests/diagnose_render_test.php | grep '^FAIL'   # expect: unassigned: a single-run media row is not
git checkout -- $R
sed -i "s/=== 'transport' && \$cnt(\$r) === 0/=== 'transport'/" $R
php tests/diagnose_render_test.php | grep '^FAIL'   # expect: but it is counted in the link line; a confirmed row whose latest run was transport ...
git checkout -- $R
sed -i 's/^const DIAG_LEDGER_CHUNK = 2048;/const DIAG_LEDGER_CHUNK = 4096;/' $R
php tests/diagnose_render_test.php | grep '^FAIL'   # expect: the block count is the chunk size; the displayed block count is the engine's own CHUNK
git checkout -- $R
sed -i "s/    if ((string) (\$in\['serial'\] ?? '') === '') {/    if (false) {/" $R
php tests/diagnose_render_test.php | grep '^FAIL'   # expect: unknown serial: says the history cannot be matched; unknown serial: no table and no ledger-derived note
git checkout -- $R
git status --short
```

---

### Task 6: `diagnose.php` — pass the ledger path, one assigned-ness reader everywhere, add `action=repair`

This is where the Phase 1 parity/pool fix reaches the screens: the verdict action and the sidebar `drivelist` action both switch to the shared reader.

**Read closely:** `source/usr/local/emhttp/plugins/hbaviewer/diagnose.php:34-87` (start/launcher), `:148-210` (verdict) and `:212-250` (drivelist — its inline `role` rule sends `parity2` and pool members to the sidebar's "Unassigned" group); `render/diagnose.php:251-299` (`renderDiagDriveList()` — groups by `role === ''`); `tests/diagnose_php_test.php:1-60` (the tokenizer-stripped `$code`, `$lib`) and `:115-135`, `:192-205`. **Skim:** the rest of `diagnose.php`.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/diagnose.php` (launcher ~76, verdict ~172-186, new action after verdict, drivelist ~215-228)
- Modify: `docs/superpowers/specs/2026-09-28-disk-utility-repair-design.md` (Surface area, `diagnose.php` bullet)
- Test: `tests/diagnose_php_test.php` (replace the check at ~198-199; add pins)

**Interfaces:**
- Consumes: `diag_badrange_path()`, `diag_disk_serial()`, `diag_badranges_read()`, `diag_array_disk()`, `DIAG_DISKS_INI`, `unraid_disk_roles()` (Task 4); `renderDiagRepair()` (Task 5).
- Produces: `GET /plugins/hbaviewer/diagnose.php?action=repair&disk=<name>` → HTML fragment; invalid or absent disk → HTTP 400, body `Invalid disk.`. Engine launched with `--badrange-state <DIAG_ROOT>/<disk>.badranges.tsv`. Sidebar roles become `unraid_disk_roles()` labels (`Disk 3`, `Parity`, `Parity 2`, `Cache`, a pool's own name).

- [ ] **Step 1: Write the failing tests**

In `tests/diagnose_php_test.php`:

(a) Directly after the check `'the start action passes a stable per-disk --state path, escaped'` (ends `escapeshellarg(diag_baseline_path("));`) add:

```php
// The ledger spans runs exactly like the baseline, so it gets the same stable
// per-disk path, never one inside --out.
check('the start action passes a stable per-disk --badrange-state path, escaped',
      str_contains($code, "--badrange-state ' . escapeshellarg(diag_badrange_path("));
```

(b) Replace

```php
// An unreadable disks.ini must mean ASSIGNED, the stricter card -- it is the
// one that never suggests writing to the disk. The permissive default here
// would offer sector-level advice for an array member.
check('assigned-ness defaults to true when disks.ini cannot be read',
      str_contains($code, '$arrayDisk = true;'));
```

with

```php
// Assigned-ness has ONE implementation, diag_array_disk() -- its fail-closed
// default on an unreadable disks.ini is tested directly in diagnose_test.php.
// Verdict and repair both call it; neither re-walks disks.ini inline.
check('the library owns assigned-ness', str_contains($lib, 'function diag_array_disk('));
check('verdict and repair both call diag_array_disk()',
      substr_count($code, 'diag_array_disk($disk)') === 2);
check('the inline assigned-ness walk is gone from the dispatch',
      !str_contains($code, '$arrayDisk = true;'));

// The repair action: disk-scoped, validated like start, pure PHP.
$repairAt = strpos($code, "action === 'repair'");
$repairEnd = $repairAt === false ? false : strpos($code, 'if ($action ===', $repairAt + 1);
$repair = $repairAt === false ? '' : substr($code, $repairAt, $repairEnd === false ? null : $repairEnd - $repairAt);
check('the repair action renders server-side',
      $repair !== '' && str_contains($repair, 'renderDiagRepair('));
check('the repair action is disk-scoped and validated like start',
      str_contains($repair, "\$_GET['disk']")
      && str_contains($repair, 'diag_disk_valid($disk) && is_file("/sys/block/$disk/dev")'));
check('the repair action reads the ledger through the library, filtered to the drive\'s serial',
      str_contains($repair, '$serial = diag_disk_serial($disk);')
      && str_contains($repair, 'diag_badranges_read(diag_badrange_path($disk, DIAG_ROOT), $serial)'));
check('the repair action never shells out',
      $repair !== '' && !preg_match('/shell_exec|\bexec\(|passthru|proc_open|popen|\bsystem\(/', $repair));

// The sidebar's roles come from the same reader diag_array_disk() asks, so the
// sidebar can never file a disk under "Unassigned" that Verdict/Repair treat
// as assigned (parity2 and pools did exactly that under the inline rule).
check('the drive list takes its roles from unraid_disk_roles()',
      str_contains($code, 'unraid_disk_roles(DIAG_DISKS_INI)'));
check('no inline diskN rule is left anywhere in the dispatch',
      !str_contains($code, 'disk\d+'));
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/diagnose_php_test.php 2>&1 | grep -E '^FAIL|FAILED'`
Expected: exactly these nine FAIL lines, then `diagnose_php: 9 FAILED`: `the start action passes a stable per-disk --badrange-state path, escaped`, `verdict and repair both call diag_array_disk()`, `the inline assigned-ness walk is gone from the dispatch`, `the repair action renders server-side`, `the repair action is disk-scoped and validated like start`, `the repair action reads the ledger through the library, filtered to the drive's serial`, `the repair action never shells out`, `the drive list takes its roles from unraid_disk_roles()`, `no inline diskN rule is left anywhere in the dispatch`. (`the library owns assigned-ness` already PASSES — Task 4 added the function.)

- [ ] **Step 3: Implement**

In `diagnose.php`:

(a) In the start action, directly after `         . ' --state ' . escapeshellarg(diag_baseline_path($disk, DIAG_ROOT))` add:

```php
         . ' --badrange-state ' . escapeshellarg(diag_badrange_path($disk, DIAG_ROOT))
```

(b) In the verdict action, replace the whole block from `    /* Assigned-ness decides which next-steps card the screen shows, and` through the closing `    }` of `if (is_array($ini)) { … }` (15 lines) with:

```php
    /* Assigned-ness decides which next-steps card the screen shows.
       diag_array_disk() is the one implementation -- the repair action and
       the sidebar's reader agree with it -- and it counts parity, parity2
       and pool members as assigned, which this inline walk did not. */
    $arrayDisk = diag_array_disk($disk);
```

(c) Directly after the verdict action's closing `}` (before `if ($action === 'drivelist') {`) add:

```php
if ($action === 'repair') {
    /* Disk-scoped, not job-scoped: the bad-range ledger spans runs. Pure PHP,
       no shell-out -- it reads one TSV, disks.ini and one sysfs attribute (the
       drive's cached VPD 0x80 serial), nothing else. Read only, like every
       action here; repair EXECUTION is Phase 2b. Validated the same way start
       validates, by the one validator. Rows are filtered to the drive's
       CURRENT serial, so a drive that took over this sdX name sees none of
       its predecessor's evidence, and an unknown serial shows nothing. */
    $disk = (string) ($_GET['disk'] ?? '');
    if (!(diag_disk_valid($disk) && is_file("/sys/block/$disk/dev"))) {
        http_response_code(400); echo 'Invalid disk.'; exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    require_once __DIR__ . '/render/diagnose.php';
    $serial = diag_disk_serial($disk);
    echo renderDiagRepair([
        'disk'       => $disk,
        'array_disk' => diag_array_disk($disk),
        'serial'     => $serial,
        'rows'       => diag_badranges_read(diag_badrange_path($disk, DIAG_ROOT), $serial),
    ]);
    exit;
}

```

(d) In the drivelist action, replace

```php
    $ini = @parse_ini_file('/var/local/emhttp/disks.ini', true);
    $drives = [];
    foreach (is_array($ini) ? $ini : [] as $name => $sec) {
        $dev = (string) ($sec['device'] ?? '');
        if ($dev === '' || !diag_disk_valid($dev)) continue;
        /* "disk3" is an array slot; a pool member or an unassigned device is
           not, and lands in the sidebar's own group. */
        $drives[] = ['dev' => $dev,
                     'role' => preg_match('/^disk\d+$/', (string) $name) ? 'Disk ' . preg_replace('/\D/', '', (string) $name)
                             : (((string) $name === 'parity') ? 'Parity' : '')];
    }
```

with

```php
    /* Roles come from unraid_disk_roles(), the disks.ini reader the SMART tab
       and bay map already use ("Disk 3", "Parity 2", "Cache", a pool's own
       name) -- and the one diag_array_disk() asks, so the sidebar and the
       Verdict/Repair screens cannot disagree about which disks are assigned.
       The old inline rule knew only diskN and "parity", so parity2 and every
       pool member landed in the "Unassigned" group. */
    $drives = [];
    foreach (unraid_disk_roles(DIAG_DISKS_INI) as $path => $label) {
        $dev = substr($path, strlen('/dev/'));
        if (!diag_disk_valid($dev)) continue;
        $drives[] = ['dev' => $dev, 'role' => $label];
    }
```

(Behaviour change, intended: parity2 and pool members move from the sidebar's "Unassigned" group to "Array and pool", under their Unraid names. The drive list has only ever listed disks.ini devices, so nothing genuinely unassigned was ever in that group.)

- [ ] **Step 4: Update the spec's `diagnose.php` bullet**

In "## Surface area", replace these exact three lines

```markdown
- `diagnose.php`: new `action=repair` — disk-scoped (takes `disk`, not `job`, since this reads
  ledger state rather than one job's event file), pure PHP, no shell-out (it only reads a TSV and
  `disks.ini`).
```

with

```markdown
- `diagnose.php`: new `action=repair` — disk-scoped (takes `disk`, not `job`, since this reads
  ledger state rather than one job's event file), pure PHP, no shell-out (it only reads a TSV,
  `disks.ini` and one sysfs attribute, the drive's cached VPD 0x80 serial, and shows only rows
  filed under that serial). The `drivelist` action's sidebar roles switch to the same
  `unraid_disk_roles()` reader `diag_array_disk()` uses, so the sidebar and the Verdict/Repair
  screens cannot disagree about which disks are assigned (amended 2026-09-28).
```

- [ ] **Step 5: Run to verify it passes**

Run: `php -l source/usr/local/emhttp/plugins/hbaviewer/diagnose.php`, then DIAG → exactly the four `all pass` lines (existing pins `every engine argument is escaped`, `nothing under /boot is written` and `the drive list has an action that renders it` must still PASS); SUITE → exactly the four known lines.

- [ ] **Step 6: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/diagnose.php tests/diagnose_php_test.php docs/superpowers/specs/2026-09-28-disk-utility-repair-design.md
git commit -F - <<'EOF'
Launch the engine with a per-disk --badrange-state path and add the disk-scoped, shell-free action=repair, which shows only rows filed under the drive's current serial. Phase 1 fix: the verdict and the sidebar drive list now take assigned-ness from the one shared reader, so parity2 and pool disks are no longer treated or grouped as unassigned.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

- [ ] **Step 7: Mutation checks**

```bash
D=source/usr/local/emhttp/plugins/hbaviewer/diagnose.php
sed -i "s/'array_disk' => diag_array_disk(\$disk),/'array_disk' => false,/" $D
php tests/diagnose_php_test.php | grep '^FAIL'   # expect: verdict and repair both call diag_array_disk()
git checkout -- $D
sed -i "s/^    echo renderDiagRepair(\[/    shell_exec('true'); echo renderDiagRepair([/" $D
php tests/diagnose_php_test.php | grep '^FAIL'   # expect: the repair action never shells out
git checkout -- $D
sed -i 's/diag_badrange_path(\$disk, DIAG_ROOT), \$serial)/diag_badrange_path($disk, DIAG_ROOT), "")/' $D
php tests/diagnose_php_test.php | grep '^FAIL'   # expect: the repair action reads the ledger through the library, filtered to the drive's serial
git checkout -- $D
git status --short
```

---

### Task 7: The Verdict screen's Repair button, `luDiagRepair()`, and the `#diag-repair` screen

**Read closely:** `render/diagnose.php:182-216` (`renderDiagVerdict()` through the "What to do next" card); `diagnose_view.js:182-185` (`luDiagShow`) and `:331-351` (`luDiagRenderVerdict`, `luDiagOpen`); `hbaviewer.php:156-223` (Diagnose tab markup); `tests/diagnose_js_test.js:25-85` (DOM stub, `ids`, fetch mock — it has no `text()`) and `:300-352` (end of `tail()`). **Skim:** the rest of `diagnose_view.js`.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/render/diagnose.php` (`renderDiagVerdict()` next-steps card)
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js` (`luDiagShow`, new `luDiagRepair` after `luDiagOpen`)
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php` (comment ~156-159, new div after `#diag-verdict`)
- Modify: `docs/superpowers/specs/2026-09-28-disk-utility-repair-design.md` (Testing: where the visibility rule is tested)
- Test: `tests/diagnose_render_test.php`, `tests/diagnose_js_test.js`

**Interfaces:**
- Consumes: `action=repair` (Task 6), `renderDiagRepair()`'s `luDiagShow('verdict')` button (Task 5).
- Produces: `window.luDiagRepair(disk)` → returns the fetch promise; `luDiagShow('repair')`; element `#diag-repair.lu-diag-screen`. Verdict HTML carries `onclick="luDiagRepair('<disk>')"` iff verdict `!== 'CLEAN'`.

- [ ] **Step 1: Write the failing tests**

(a) `tests/diagnose_render_test.php` — append above the final `echo` (below Task 5's block):

```php
/* ── the Verdict screen's way into Repair ──────────────────────────────── */
$vIn = fn(string $v, string $disk = 'sdb') => [
    'disk' => $disk, 'verdict' => $v, 'why' => '', 'events' => [], 'sense' => [],
    'max_cmd_age' => null, 'array_disk' => false, 'ports' => [], 'recent' => []];
check('a non-CLEAN verdict offers the Repair screen for its disk',
      str_contains(renderDiagVerdict($vIn('MEDIA')), "luDiagRepair('sdb')")
      && str_contains(renderDiagVerdict($vIn('TRANSPORT')), "luDiagRepair('sdb')"));
check('a CLEAN verdict does not', !str_contains(renderDiagVerdict($vIn('CLEAN')), 'luDiagRepair('));
// A run that did not classify is not clean -- absence is not health.
check('an unclassified run still offers it', str_contains(renderDiagVerdict($vIn('')), 'luDiagRepair('));
check('the disk in the Repair onclick is quote-escaped',
      str_contains(renderDiagVerdict($vIn('MEDIA', "a'b")), "luDiagRepair('a&#039;b')"));

```

(b) `tests/diagnose_js_test.js`:
- In the `ids` array, change `'diag-live','diag-verdict',` to `'diag-live','diag-verdict','diag-repair',`.
- Inside `async function tail()`, directly after the last `check('a confirmed start landing while resume\'s list fetch is in flight is not overwritten', …);` and before the closing `}` of `tail`, add:

```js

    /* ── the Repair screen (Phase 2a) ─────────────────────────────────── */
    fetches.length = 0;
    sandbox.luDiagJob = 'sdb-9';
    const fetchBeforeRepair = sandbox.fetch;
    sandbox.fetch = (url, opts) => {
        fetches.push({ url, body: opts && opts.body ? String(opts.body) : '' });
        return Promise.resolve({ text: () => Promise.resolve('<p>REPAIR sdc</p>') });
    };
    await sandbox.luDiagRepair('sdc');
    sandbox.fetch = fetchBeforeRepair;
    check('Repair fetches the disk-scoped repair action once',
          fetches.length === 1 && fetches[0].url.includes('action=repair')
          && fetches[0].url.includes('disk=sdc'));
    check('and it is a GET: nothing is posted', fetches[0].body === '');
    check('Repair shows only the repair screen',
          els.get('diag-repair').hidden === false && els.get('diag-verdict').hidden === true
          && els.get('diag-live').hidden === true);
    check('the fragment lands in #diag-repair', els.get('diag-repair')._html.includes('REPAIR sdc'));
    check('Repair leaves the page\'s job alone', sandbox.luDiagJob === 'sdb-9');
    sandbox.luDiagShow('verdict');
    check('Back to verdict hides the repair screen again',
          els.get('diag-repair').hidden === true && els.get('diag-verdict').hidden === false);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/diagnose_render_test.php | grep -E '^FAIL|FAILED'` → expect `a non-CLEAN verdict offers the Repair screen for its disk`, `an unclassified run still offers it`, `the disk in the Repair onclick is quote-escaped`, then `diagnose_render: 3 FAILED`.
Run: `node tests/diagnose_js_test.js 2>&1 | grep -E '^FAIL|TypeError|diagnose_js:'` → expect `TypeError: sandbox.luDiagRepair is not a function` and `diagnose_js: FAILURES`.

- [ ] **Step 3: Implement**

(a) `render/diagnose.php`, in `renderDiagVerdict()`, replace

```php
    $out .= '</ol></div>';

    /* Which drives share a port.
```

with

```php
    $out .= '</ol>';
    /* The way into the Repair screen: this disk's bad-block evidence across
       runs. Not offered on CLEAN -- nothing reproduced -- and offered on an
       unclassified run, because "did not classify" is not clean. Decided here,
       not in the client: a reopened past verdict never passes its verdict
       event through the browser. */
    if ($v !== 'CLEAN') {
        $out .= '<p><button class="lu-refresh-btn" type="button" onclick="luDiagRepair(\''
              . htmlspecialchars($disk, ENT_QUOTES) . '\')">Repair</button> '
              . '<span class="lu-muted">Bad-block evidence for this drive, across runs.</span></p>';
    }
    $out .= '</div>';

    /* Which drives share a port.
```

(b) `diagnose_view.js` — replace `luDiagShow`:

```js
    window.luDiagShow = function (which) {
        el('diag-live').hidden    = (which !== 'live');
        el('diag-verdict').hidden = (which !== 'verdict');
        el('diag-repair').hidden  = (which !== 'repair');
    };
```

and directly after the closing `};` of `window.luDiagOpen` add:

```js

    /* The Repair screen: read-only bad-block evidence for one DISK, from the
       ledger the engine keeps across runs -- so it takes a disk, not a job,
       and leaves luDiagJob alone (the verdict it was opened from is still the
       page's job, and "Back to verdict" returns to it). A GET: nothing here
       changes anything. */
    window.luDiagRepair = function (disk) {
        luDiagShow('repair');
        if (typeof luTab === 'function') luTab('diagnose');
        return fetch('/plugins/hbaviewer/diagnose.php?action=repair&disk='
                     + encodeURIComponent(disk))
          .then(function (r) { return r.text(); })
          .then(function (h) { el('diag-repair').innerHTML = h; })
          .catch(function () {
            el('diag-repair').textContent = 'Could not load the repair evidence — reload the tab.';
          });
    };
```

(c) `hbaviewer.php` — replace the comment

```html
<!-- ── Diagnose tab: two screens, Live Job and Verdict ─────────────────────
     Both live in one pane and are toggled by luDiagShow() rather than being
     two routes: they render from the same job and the same event file, and a
     verdict is a finished live view, not a different page. -->
```

with

```html
<!-- ── Diagnose tab: three screens, Live Job, Verdict and Repair ───────────
     All live in one pane and are toggled by luDiagShow() rather than being
     separate routes: Live and Verdict render from the same job and the same
     event file, and a verdict is a finished live view, not a different page.
     Repair is disk-scoped (the bad-range ledger spans runs) and read-only. -->
```

and directly after `  <div id="diag-verdict" class="lu-diag-screen" hidden></div>` add:

```html
  <div id="diag-repair" class="lu-diag-screen" hidden></div>
```

(d) Spec, "## Testing", replace the bullet

```markdown
- `tests/diagnose_js_test.js`: the new Verdict-screen "Repair" link's visibility rule (shown only
  on a non-CLEAN verdict) and `luDiagRepair()`'s navigation call.
```

with

```markdown
- `tests/diagnose_js_test.js`: `luDiagRepair()`'s navigation call — one GET to `action=repair`,
  only the Repair screen shown, `luDiagJob` untouched. The "Repair" link's visibility rule (shown
  only on a non-CLEAN verdict) is tested in `tests/diagnose_render_test.php` instead: the Verdict
  screen is server-rendered and `renderDiagVerdict()` is where the verdict is known — including for
  a reopened past verdict, whose verdict event never passes through the browser. (Moved during
  planning, 2026-09-28.)
```

- [ ] **Step 4: Run to verify it passes**

Run: `php -l source/usr/local/emhttp/plugins/hbaviewer/render/diagnose.php && php -l source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php && node --check source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js && echo LINT-OK`
Expected: two `No syntax errors detected` lines and `LINT-OK`.
Then the ES5 guard — expected: no output (verified empty on the pre-task file):

```bash
grep -nE '=>|\blet |\bconst ' source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js
```

Then DIAG → exactly the four `all pass` lines; SUITE → exactly the four known lines. `view_test.php` (which checks `hbaviewer.php`'s script tags) sits behind `config_test.php` in `run_php.sh`'s `&&` chain and never runs here, so run it directly: `php tests/view_test.php 2>&1 | tail -1` → `view: all pass`.

- [ ] **Step 5: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/render/diagnose.php source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php tests/diagnose_render_test.php tests/diagnose_js_test.js docs/superpowers/specs/2026-09-28-disk-utility-repair-design.md
git commit -F - <<'EOF'
Offer a Repair button on every non-CLEAN verdict and add the third Diagnose screen it opens, loaded by luDiagRepair() from the disk-scoped repair action. Spec updated: the button's visibility is tested where the verdict is rendered.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

- [ ] **Step 6: Mutation checks**

```bash
R=source/usr/local/emhttp/plugins/hbaviewer/render/diagnose.php
J=source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js
sed -i "s/if (\$v !== 'CLEAN') {/if (true) {/" $R
php tests/diagnose_render_test.php | grep '^FAIL'   # expect: a CLEAN verdict does not
git checkout -- $R
sed -i "/el('diag-repair').hidden  = (which !== 'repair');/d" $J
node tests/diagnose_js_test.js 2>&1 | grep '^FAIL'   # expect: Repair shows only the repair screen
git checkout -- $J
git status --short
```

---

### Task 8: Documentation — HOWTO and ARCHITECTURE

**Read closely:** `HOWTO.md:86-135` (`## Diagnose`); `ARCHITECTURE.md:119` (the `diagnose.php` row, which describes `baseline.tsv`) and `:498-523` (the Diagnose sharp-edge bullets). **Skim:** nothing else.

**Files:**
- Modify: `HOWTO.md`, `ARCHITECTURE.md`

**Interfaces:** none.

- [ ] **Step 1: HOWTO — narrow "no repair" to "no repair execution"**

In `HOWTO.md`, replace

```markdown
self-test — nothing here ever writes to the device. Phase 1 has
no repair or reassignment action: the Verdict screen recommends next steps
(move the drive, re-run under load, rebuild through Unraid's own array
tools) but performs none of them.
```

with

```markdown
self-test — nothing here ever writes to the device. Repair *execution* is
not in this release: the Verdict screen recommends next steps (move the
drive, re-run under load, rebuild through Unraid's own array tools) but
performs none of them, and the Repair screen shows evidence, not actions.
```

- [ ] **Step 2: HOWTO — document the Repair screen**

Directly after the last paragraph of `## Diagnose` (its final line, ~134, is `Diagnose again in a few hours for deltas that mean anything.`) and before `## Map your drive bays`, add:

````markdown

**Repair shows evidence, not actions.** Every verdict that is not CLEAN
carries a **Repair** button. For a disk Unraid has assigned — array, parity
(including the second parity) or a pool — the Repair screen explains why
direct sector repair is not offered — it bypasses parity or the pool's own
redundancy — and lists the three safe options together: replace, rebuild
onto itself, or keep in service and watch. They are options to weigh; the
screen does not pick one. (Earlier releases treated parity2 and pool disks
as unassigned, both here and in the sidebar's grouping; they now get the
assigned-disk advice.) For an unassigned disk it lists the drive's
**confirmed** failing ranges: 2048-
block chunks where SCSI VERIFY failed — the drive could not read its own
platters — on **two separate runs**. A range that failed only over the link
(VERIFY clean, READ failed) never counts toward confirmation, because
remapping it would retire a good sector and leave the cable, slot or HBA
fault in place; those are counted in a line of their own instead. A chunk
that stops failing keeps its row and its history. Nothing on the screen
writes to the disk, and there is no control on it beyond navigation.

A range's Start LBA identifies the region a defect was found in, for
matching it across runs; it is not the exact bad block. Repair execution,
when it comes, will locate the exact block with a fresh VERIFY first.

The bad-range history is kept beside the baseline in `/tmp/hbaviewer/jobs/`
(`sdX.badranges.tsv`), so a reboot starts it over — losing it only ever
means "not confirmed yet". Every row is filed under the drive's own serial
number, read without waking the drive, so a drive that later takes over the
same `sdX` name sees none of the previous drive's history. If a drive's
serial cannot be read, its Repair screen says so and shows nothing.
````

- [ ] **Step 3: ARCHITECTURE — the state file, next to `baseline.tsv`**

In `ARCHITECTURE.md`'s endpoints table, `diagnose.php` row, replace the sentence end `so it survives the job-directory retention sweep instead of being deleted with the run that wrote it. Not a mutating path — every operation it starts is a read. |` with:

```markdown
so it survives the job-directory retention sweep instead of being deleted with the run that wrote it. The bad-range ledger is the same kind of state: `--badrange-state` points it at `/tmp/hbaviewer/jobs/<disk>.badranges.tsv` (`diag_badrange_path()`), one row per 2048-block chunk that ever failed a check, and `?action=repair` reads it for the Repair screen — disk-scoped, pure PHP, no shell-out, rows filtered to the drive's serial. Not a mutating path — every operation it starts is a read. |
```

- [ ] **Step 4: ARCHITECTURE — four sharp edges**

Append to `## Where the sharp edges are` (after the `-n standby` bullet, the last one):

```markdown
- **The bad-range ledger is keyed by the drive's serial, read from sysfs on
  BOTH sides.** `drive_triage.sh` (`br_serial`) writes, and
  `diag_disk_serial()` filters on, `/sys/block/<dev>/device/vpd_pg80` — the
  kernel's cached VPD page 0x80, so no command reaches the drive and a
  sleeping one stays asleep — normalized the same way (skip the 4-byte header,
  printable ASCII, trim spaces). Change the source or the normalization on one
  side alone and every row silently vanishes from the Repair screen. It is
  NOT the slot ID `$STATE` uses: on every web-launched `/dev/<disk>` run that
  ID is the literal `manual`. The per-disk file is still named by sd letter;
  the reader filters it to the current drive's serial, so a drive that takes
  over a name sees none of its predecessor's rows, and an unreadable serial
  shows nothing rather than everything.
- **`chunk_start` in the ledger is a region key, never a write address.** A
  tested range starts `PAD` (2000) blocks below the failing sector the kernel
  reported, so its chunk can precede the defect. Any future write path must
  locate the exact LBA with a fresh fine-grained VERIFY first.
- **"Assigned" means assigned anywhere, and has one reader.**
  `diag_array_disk()`, the Diagnose sidebar and the SMART tab all go through
  `unraid_disk_roles()`: parity, parity2, `diskN` and pool members are
  assigned, and an unreadable `disks.ini` counts as assigned. The Diagnose
  screens' old inline `^disk\d+$` test called parity and pool disks
  unassigned and gave a parity disk's MEDIA verdict the unassigned-disk
  advice.
- **Only a `media` result raises a bad-range row's `confirm_count`.** A
  `transport` range is healthy inside the drive; counting it would let two
  cable faults "confirm" a sector for remapping, retiring a good block and
  leaving the fault in place. Asserted, with an in-suite mutation check, in
  `tests/drive_triage_test.sh`.
```

- [ ] **Step 5: Verify**

Run: `grep -c 'badranges.tsv' HOWTO.md ARCHITECTURE.md; grep -c 'no repair or reassignment' HOWTO.md`
Expected: `HOWTO.md:1` (or more), `ARCHITECTURE.md:1` (or more), and `0`.
Then SUITE → exactly the four known lines.

- [ ] **Step 6: Commit**

```bash
git add HOWTO.md ARCHITECTURE.md
git commit -F - <<'EOF'
Document the Repair screen, what "confirmed" means, where the bad-range ledger lives, and the sharp edges it adds: the sysfs serial key shared by writer and reader, the region-only chunk key, one reader for assigned-ness (the parity/pool fix), and media-only confirmation.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 9: Hardware verification on Golem — Commands I run myself

Everything above is stub- and fixture-tested. This task keeps the branch open until the output comes back. **Post ONE block, stop, wait for the pasted output, then post the next.** No pasted output means the block did not run. Nothing about Golem is known unless it appears in pasted output. A block that changes state is posted alone.

**Read closely:** `ARCHITECTURE.md:385-417` ("Testing a branch on real hardware"); `docs/install-verify.sh:25-40` and `:88-160` (its `note OK|FAIL` line format and final `=== PASS` line).

**Files:**
- Modify: `docs/superpowers/plans/2026-09-28-disk-utility-repair.md` (this file — status header, Step 10)

**Interfaces:** none.

- [ ] **Step 1: Ask for the push go-ahead (sandbox)**

The box fetches the branch from GitHub, so it must be pushed. Ask the user verbatim: "Task 9 needs `disk-utility-repair` on origin for the Golem deploy. OK to `git push -u origin disk-utility-repair`?" Wait for an explicit yes. Then:

Pass condition: the output ends with `branch 'disk-utility-repair' set up to track 'origin/disk-utility-repair'.`

```bash
git push -u origin disk-utility-repair 2>&1 | tail -2
```

If the user declines: stop. **NEEDS JUDGEMENT** — there is no other documented deploy path.

- [ ] **Step 2: Block A — deploy (changes state; alone)**

Pass condition: the build tail shows no `ERROR`; the install-verify lines contain no `FAIL`, include `OK     render/ holds 9 files` and `OK     installed tree matches the package byte for byte`, and end with `=== PASS -- now open the plugin in the browser and click every tab. ===`.

```bash
cd /tmp && rm -rf hbav-build && mkdir hbav-build && cd hbav-build
curl -fsSL https://github.com/FugginOld/Unraid-HBAviewer/archive/refs/heads/disk-utility-repair.tar.gz | tar xz --strip-components=1
bash build.sh 2>&1 | tail -3
cp releases/hbaviewer.txz /tmp/hbaviewer.txz
bash docs/install-verify.sh 2>&1 | grep -E '^(OK|FAIL)|=== PASS'
```

- [ ] **Step 3: Block B — the change reached the box (read-only)**

Pass condition: every count is `1` or more, and `diagnose_lib.php` shows `4`.

```bash
P=/usr/local/emhttp/plugins/hbaviewer
grep -c -- '--badrange-state' $P/scripts/drive_triage.sh $P/diagnose.php
grep -c 'br_serial()' $P/scripts/drive_triage.sh
grep -c 'function renderDiagRepair' $P/render/diagnose.php
grep -c 'window.luDiagRepair' $P/diagnose_view.js
grep -c 'id="diag-repair"' $P/hbaviewer.php
grep -c 'unraid_disk_roles(DIAG_DISKS_INI)' $P/diagnose.php
grep -cE 'function diag_(array_disk|badrange_path|badranges_read|disk_serial)\(' $P/diagnose_lib.php
```

- [ ] **Step 4: Block C — assigned disks, serial source agreement, sdq's state, no stale ledger (read-only; wakes nothing)**

Everything here is a file read, `smartctl -n standby` (skips a sleeping disk) or `hdparm -C` (asks the power state, does not spin up).

Pass condition:
- a list of `[section] device` pairs;
- one line per device: `vpd=[…]` is non-empty for **sdq** and for every SAS/SATA device in the list (an NVMe pool device may show `vpd=[]` — record it, see Risks); where `smart=[…]` is also non-empty, the two are **equal**. A mismatch is not fatal to correctness — both engine and PHP use the vpd value — but it means the key differs from the serial the user sees in SMART: stop, **NEEDS JUDGEMENT**;
- `hdparm` reports `active/idle` for sdq;
- the `ls` prints `No such file or directory` (no ledger exists yet).

From this output, record: **ARRAY_DEV** = the device of the first `["diskN"]` section; **PARITY_DEV** = the device of `["parity"]` if listed; **POOL_DEV** = the device of any other non-`diskN`, non-`parity*` section if listed; **SDQ_ASSIGNED** = yes if `sdq` appears anywhere in the list, else no. If sdq is in `standby`, stop: the engine leaves it asleep and tests nothing — ask the user whether to wait (**NEEDS JUDGEMENT**).

```bash
awk -F= '/^\[/{s=$0} /^device=/{gsub(/"/,"",$2); if ($2!="") print s, $2}' /var/local/emhttp/disks.ini
for d in sdq $(awk -F= '/^device=/{gsub(/"/,"",$2); if ($2!="") print $2}' /var/local/emhttp/disks.ini); do
    printf '%s vpd=[%s] smart=[%s]\n' "$d" \
        "$(tail -c +5 /sys/block/$d/device/vpd_pg80 2>/dev/null | LC_ALL=C tr -cd '[:print:]' | sed 's/^ *//; s/ *$//')" \
        "$(smartctl -i -n standby -d auto /dev/$d 2>/dev/null | awk -F': *' '/^Serial [Nn]umber/{print $2; exit}')"
done
hdparm -C /dev/sdq 2>&1 | tail -1
ls -l /tmp/hbaviewer/jobs/*.badranges.tsv 2>&1 | head -3
```

- [ ] **Step 5: Block D — a real run updates a seeded ledger correctly (scratch dir only; alone)**

Runs the installed engine read-only against sdq with a scratch ledger holding one row under **sdq's own serial** (chunk 0, confirmed once) and one under another serial on the same chunk. Takes ~3 minutes (SMART short self-test).

Pass condition, all of:
- `serial=[…]` is non-empty and equals sdq's `vpd=[…]` from Block C (if empty: stop, **NEEDS JUDGEMENT**);
- the filtered report shows `ranges: 0:256`, `VERIFY clean`, `READ   clean`, `VERDICT: no fault reproduced`, `bad-range ledger: /tmp/hbav-hw/sdq.badranges.tsv` and `bad-range ledger: 2 row(s) saved`, and NO `no serial readable` line;
- `cat -A` shows exactly two lines: `OTHERDISK^I0^I2^Iseed1^Iseed2^Imedia^I1$` unchanged, and `<sdq serial>^I0^I1^Iseed1^I<YYYYmmdd-HHMMSS>^Iintermittent^I<10-digit epoch>$` (count kept at 1, first run kept, class now intermittent);
- `stat` prints `644 root:root`;
- the run record prints `<sdq serial>^I0^Iintermittent$`.

If VERIFY or READ fails on sdq, that is a real finding: paste it, stop — **NEEDS JUDGEMENT**.

```bash
mkdir -p /tmp/hbav-hw && rm -rf /tmp/hbav-hw/*
S=$(tail -c +5 /sys/block/sdq/device/vpd_pg80 | LC_ALL=C tr -cd '[:print:]' | sed 's/^ *//; s/ *$//'); echo "serial=[$S]"
printf '%s\t0\t1\tseed1\tseed1\tmedia\t1\nOTHERDISK\t0\t2\tseed1\tseed2\tmedia\t1\n' "$S" > /tmp/hbav-hw/sdq.badranges.tsv
bash /usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh --out /tmp/hbav-hw/run \
    --state /tmp/hbav-hw/sdq.baseline.tsv --badrange-state /tmp/hbav-hw/sdq.badranges.tsv \
    --auto-triage --all /dev/sdq 2>&1 | grep -E 'ranges:|VERIFY (clean|failed)|READ +(clean|failed)|VERDICT|bad-range ledger|SLEEPING'
cat -A /tmp/hbav-hw/sdq.badranges.tsv
stat -c '%a %U:%G' /tmp/hbav-hw/sdq.badranges.tsv
cat -A /tmp/hbav-hw/run/*/badranges-run.tsv
```

- [ ] **Step 6: Block E — the web path: a clean run writes no ledger (read-only after a UI action)**

First, in the browser: Tools → Disk Utilities → HBAviewer → Drives tab → **Diagnose** on sdq; wait for the Verdict screen (~3 minutes). Note whether the Verdict screen shows a **Repair** button (it must NOT, for a CLEAN verdict). Then run:

Pass condition: `sdq.baseline.tsv` is listed and `sdq.badranges.tsv` is NOT; the report line reads `bad-range ledger: /tmp/hbaviewer/jobs/sdq.badranges.tsv` (proves the launcher passed the flag), with no `row(s) saved` and no `no serial readable` line; the job dir is `755 root:root`; and the user reports no Repair button on the CLEAN verdict.

```bash
ls /tmp/hbaviewer/jobs/ | grep -E '^sdq\.(badranges|baseline)'
grep -h 'bad-range ledger' $(ls -1dt /tmp/hbaviewer/jobs/sdq-*/ | head -1)*/report.txt
stat -c '%a %U:%G %n' /tmp/hbaviewer/jobs
```

- [ ] **Step 7: Block F — assigned-ness and the Repair screen on real disks.ini, real sysfs and the real ledger path (read-only)**

Covers every disks.ini device (so parity and pool are checked on real data, not just ARRAY_DEV) plus sdq.

Pass condition: no `Warning`/`Notice`/`Fatal` line; **every** disks.ini device line reads `array=true parity-msg=1 table=0` (this is the Phase 1 parity/pool fix on real data — a `PARITY_DEV` or `POOL_DEV` line with `array=false` is a FAIL); the `sdq` line reads `serial=[<sdq serial>] array=false parity-msg=0 no-media-note=1 no-serial-note=0 table=0` if SDQ_ASSIGNED=no, or like the disks.ini lines if yes.

```bash
cd /usr/local/emhttp/plugins/hbaviewer && php -r '
require "diagnose_lib.php"; require "render/diagnose.php";
$devs = array_map(fn($p) => substr($p, 5), array_keys(unraid_disk_roles(DIAG_DISKS_INI)));
foreach (array_unique(array_merge(["sdq"], $devs)) as $d) {
    $s = diag_disk_serial($d);
    $h = renderDiagRepair(["disk" => $d, "array_disk" => diag_array_disk($d), "serial" => $s,
                           "rows" => diag_badranges_read(diag_badrange_path($d), $s)]);
    echo $d, " serial=[", $s, "] array=", var_export(diag_array_disk($d), true),
         " parity-msg=", (int) str_contains($h, "bypasses parity"),
         " no-media-note=", (int) str_contains($h, "No media-class range"),
         " no-serial-note=", (int) str_contains($h, "serial could not be read"),
         " table=", (int) str_contains($h, "<table"), "\n";
}' 2>&1
```

- [ ] **Step 8: Block G — the screens in the browser (manual, no command)**

In the browser, on the Diagnose tab:
1. Look at the sidebar drive list. Pass: parity (and parity 2, and any pool disk, if present) are listed under **"Array and pool"** with their Unraid names ("Parity 2", "Cache", …), not under "Unassigned".
2. Open devtools → Console and run `luDiagRepair('sdq')`, then `luDiagRepair('ARRAY_DEV')`, then `luDiagRepair('PARITY_DEV')` if one was recorded (the Step 4 devices). Pass, as reported by the user: sdq shows "Repair — /dev/sdq" with the no-media-class-range note (or the assigned-disk text if SDQ_ASSIGNED=yes); ARRAY_DEV and PARITY_DEV show the "bypasses parity" text and the three options; none shows a checkbox, text field or action button; "Back to verdict" returns to the Verdict screen.

- [ ] **Step 9: Block H — clean up the scratch dir (changes state; alone)**

Pass condition: prints `gone`.

```bash
rm -rf /tmp/hbav-hw /tmp/hbav-build && [ ! -e /tmp/hbav-hw ] && echo gone
```

- [ ] **Step 10: Record the result and commit (sandbox)**

Replace this plan's status header line with (fill `<date>` and anything that did not pass):

```markdown
> **Status: VERIFIED ON GOLEM, <date>, pending review (Task 10).** Blocks A–H passed, including the sysfs serial agreeing with smartctl and parity/pool disks classed as assigned on real data. **Not verified on hardware:** a real cross-run `media` confirmation (two runs, `confirm_count` reaching 2, a row in the Repair table) and the chunk-rounding tolerance against real dmesg harvests — no Golem disk fails VERIFY today (`sdq` spot-checks `0:256` clean). First thing to run when a failing drive turns up; a bench drive `sas-bench` classifies MEDIA is the natural candidate. Also not exercised on hardware: the Repair button on a real non-CLEAN verdict, unless one existed on Golem during Block G.
```

```bash
git add docs/superpowers/plans/2026-09-28-disk-utility-repair.md
git commit -F - <<'EOF'
Record the Phase 2a hardware verification on Golem, and the two-run media confirmation that could not be exercised there.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 10: Review, then merge into `dev`

- [ ] **Step 1: Dispatch `reviewer`** (do not pass `model`; the agent pins its own)

Prompt it with, verbatim:

> Review branch `disk-utility-repair` against `dev`: `git diff dev...disk-utility-repair`. Read `docs/review-policy.md` first, then the spec `docs/superpowers/specs/2026-09-28-disk-utility-repair-design.md` and this plan's "Decisions made in this plan". **Read closely:** the `drive_triage.sh` diff (flag parse, `/boot` guard, reset, `br_serial` — sysfs only, never a command to the drive — `br_class`, the range loop's no-serial-no-rows guard, the end-of-run awk ledger merge — check media-only counting, per-run fold, pass-through of other serials' rows and extra columns, the no-file-on-clean-run path, and that behavior is unchanged when `--badrange-state` is absent; the test-only `TRIAGE_SYSFS`/`TRIAGE_DISKS_INI` gates); the `diagnose_lib.php` diff (`diag_disk_serial()` must normalize exactly like `br_serial`; `diag_badranges_read()` must return nothing for an unknown serial; `diag_array_disk()` fail-closed on unreadable disks.ini and assigned-anywhere via `unraid_disk_roles()`); the `diagnose.php` diff (repair action validation, no shell-out, serial filter; verdict and drivelist both on the shared reader); `renderDiagRepair()` and the Verdict-screen button in `render/diagnose.php` (escaping, no controls, unknown-serial branch). **Skim:** tests, `diagnose_view.js`, `hbaviewer.php`, docs, spec and plan edits. Phase 2a is read-only: flag anything that writes to a device, wakes a disk, or adds a control without behavior. One line per finding: `file:line`, defect, proposed fix. Do not write a report file.

- [ ] **Step 2: Rule on each finding — NEEDS JUDGEMENT**

Per `docs/review-policy.md` and the arbitration order in the user's CLAUDE.md: a failing test refutes a finding; correctness over concision; findings are fixed now, filed as issues, or rejected with a reason — in this session, never committed as a report. A fix to `drive_triage.sh`, `diagnose_lib.php` or `diagnose.php` re-runs ENGINE/DIAG/SUITE and the affected Task 9 blocks (push needs a fresh go-ahead).

- [ ] **Step 3: Merge into `dev`**

Pass condition: the merge completes without conflict, then SUITE shows exactly the four known lines and DIAG exactly the four `all pass` lines.

```bash
git checkout dev
git merge --no-ff disk-utility-repair -F - <<'EOF'
Merge disk-utility-repair: Disk Utility Phase 2a, the read-only Repair screen and the serial-keyed cross-run bad-range ledger behind it, plus the Phase 1 fix classing parity and pool disks as assigned.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
bash tests/run.sh 2>&1 | grep -E '^FAIL|FAILED$|^--- '
```

Then DIAG. Update the status header to `> **Status: COMPLETE.** …` (keeping the not-verified sentence), commit on `dev`. Do not push `dev` without the user's go-ahead.

---

## Risks and open items

- **Serial source differs from the approved amendment's wording.** The amendment said the engine reads the serial with `smartctl -i -n standby`; this plan reads sysfs `vpd_pg80` on both sides (Decision 9), because the Repair action may not shell out and writer and reader must share one source. Block C verifies on Golem that it matches smartctl's serial. Revisit only with a shell-free PHP source for the same value.
- **Sd-letter reuse is handled by the serial key.** A drive that takes over another's `sdX` name reads the same per-disk file but sees none of the old drive's rows. The converse — a drive that moves to a NEW name without a reboot — finds its history in the old name's file and so shows none until it re-accumulates: fails safe (not confirmed), never wrong.
- **No serial → no evidence.** A device without a readable VPD 0x80 page (NVMe namespaces, some USB bridges) records nothing and its Repair screen says the serial could not be read. Fail-safe. Block C lists which Golem devices are affected.
- **Pool members get parity-worded advice.** With parity2 and pools now "assigned", a pool member's MEDIA verdict uses `diag_next_steps()`'s array text (parity, rebuild). That is the safe direction (never "write to it"), but pools recover by their own means (btrfs/zfs), not a parity rebuild. The Repair screen's first sentence names pools; `diag_next_steps()` wording is unchanged. Follow-up.
- **The recorded chunk may not contain the failing sector — by design, a region key.** `chunk_start` is the tested range's start rounded down, and a range starts `PAD=2000` blocks below the first failing sector. Spec (Task 3) now says so and makes "fresh fine-grained VERIFY before any write; never write a ledger LBA" a hard Phase 2b rule. The screen's footnote says what Start LBA means.
- **Boundary straddle — known, fail-safe false negative.** A defect whose padded start lands on different sides of a 2048 boundary on two runs becomes two rows at 1 each and never confirms. Never a false confirmation. Only a ledger written from real dmesg harvests (not available on Golem) will show how often.
- **Ledger dies on reboot** (it lives in `/tmp`, like the baseline). Accepted for 2a; where it should persist, given no `/boot` writes, is deferred to Phase 2b in the spec. Loss fails safe: lost evidence = not confirmed.
- **A sleeping disk with kernel-log evidence being triaged and woken** was fixed before Phase 2a (`triage_disk()` re-checks standby at entry) and is no longer a risk.
- **A cancelled or killed run changes nothing** — the merge sits at end of run. Intended (a partial run is not a confirmation), not tested.
- **Single-run media rows are hidden when confirmed rows exist.** The spec's empty state explains `confirm_count` 1 only when the table is empty; with confirmed rows present, a range seen once on the latest MEDIA verdict does not appear anywhere on Repair. Spec gap, left as specified.
