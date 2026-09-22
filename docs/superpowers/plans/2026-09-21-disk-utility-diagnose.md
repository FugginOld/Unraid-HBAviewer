# Disk Utility Phase 1 — Diagnose Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** HBAviewer gains a read-only "Diagnose" job that runs the VERIFY-vs-READ media/transport discriminator on one disk, streams its progress live to the Monitor, and ends in an explained verdict.

**Architecture:** `drive_triage.sh` is vendored into `scripts/` as the diagnostic engine and given a `--events` flag that writes one JSON object per line beside its existing human report. A PHP endpoint (`diagnose.php`) launches it under `setsid` into `/tmp/hbaviewer/jobs/<job-id>/`, holding a per-disk lock and cancelling by process group — the launch/lock/cancel shape `flash.php` + `flash_hba.sh` already use. A second endpoint (`diagnose_stream.php`) tails the event file as Server-Sent Events with byte-offset resume. Two screens inside one new Monitor tab consume that stream: Live Job while it runs, Verdict when it finishes. Separately, two checks from `sas_error_monitor.sh` (kernel-log critical medium errors, grown-defect-list increases) are merged into the cron notification pipeline as `disk_alerts.php`, fixing that script's two bugs on the way in.

**Tech Stack:** bash composers + GNU awk parsers, PHP 8 (no framework), vanilla JS (one IIFE, no build step), golden-file shell tests via `tests/run.sh`, PHP unit tests via `tests/run_php.sh`, node runtime tests via `node tests/*_test.js`.

**Spec:** `docs/superpowers/specs/2026-09-21-disk-utility-diagnose-design.md`

## Global Constraints

- Work on `dev`. Never commit to or push `main`. Run from the repo root: `cd c:/Users/Joe/Documents/GitHub/Unraid-HBAviewer`.
- Full verification is `bash tests/run.sh`. It must print `--- all pass ---` at the end of **every** task.
- **Phase 1 ships no mutating path.** Every operation is a read: SMART/log pages, SCSI VERIFY, SCSI READ, SMART self-test. Nothing in this plan may write to a device, and nothing may write to `/boot` except `disk_alerts.php`'s small JSON state file (which sits beside `notify_state.json`, the existing precedent).
- **Job state lives in `/tmp/hbaviewer/jobs/`, never `/boot`.** It is ephemeral and must die with the boot.
- **`flash.php`'s launch/lock/cancel mechanics are the model, not the target.** `docs/review-policy.md` lists them as rejected-on-sight for simplification. Copy the *shape* (atomic `fopen($lock,'x')`, pure guards above the dispatch, detached launch, lock released by the job's own trailer). Do not edit, generalise, or share code with `flash.php`.
- **House pattern for a PHP endpoint:** pure functions at the top, `if (PHP_SAPI === 'cli') return;` in the middle, HTTP dispatch at the bottom. **Any `const` the CLI test runner needs must be declared ABOVE that guard** (`ARCHITECTURE.md`, "The written rules"; asserted by `tests/ajax_render_test.php:776`, `:785-787`).
- **No plugin-side CSRF check.** Unraid's `local_prepend.php` already enforces it. Adding one is marked do-not-re-attempt.
- **Never spin up a standby drive.** Every `smartctl` call added by this plan passes `-n standby`. This is a deliberate divergence from `scripts/read_smart.sh`'s SAS branch (which skips the guard because a SAS log-page read is electronics-only); the spec makes the rule absolute for the new code, and the divergence is asserted by test, not left to memory.
- **`-d auto` stays on every `smartctl` call** the engine already makes, so the SAT layer keeps translating for SATA drives behind the HBA. The engine's existing SATA fallbacks — `Reallocated_Sector_Ct`, `Current_Pending_Sector`, `UDMA_CRC_Error_Count` for the link-side counter, `Power_On_Hours` — stay exactly as they are; they are what makes a SATA drive behind the HBA go through the same VERIFY/READ discriminator.
- **Four things in `drive_triage.sh` are already correct and must be preserved, not redesigned:** state keyed by **disk ID** and never by `sd` letter (letters shift across reboots); the **live fleet median** with its factor-and-floor thresholds for link-error outlier detection; the separation of **lifetime SAS counters from deltas since the last run** (a raw invalid-DWORD total is nonzero on every healthy SAS drive and means nothing alone); and the rule that **non-medium error count never promotes a disk on its own** (some HGST/WD firmware runs it into the hundreds of thousands on a spotless link). Tasks 2–4 add to this file and change none of it. A diff that touches the `MEDIAN`/`THRESH` computation, the `$STATE` baseline format, or the `NONMED_NOTE` branch is out of scope for this plan.
- **Fixtures are evidence.** Prefer real captured tool output; mask identifiers length-preservingly. A hand-modelled fixture once encoded a PCIe Gen5 link on a 2012 SAS2 card.
- **Windows/NTFS forbids `:` in filenames.** Any fixture needing a SCSI or PCI address in a *path* is generated at runtime under `mktemp -d`, never committed. `:` inside a file's *contents* is fine.
- **A golden that moves is a finding.** Regenerate individual goldens by name; never run `UPDATE=1`.
- Register every new test in exactly one place: PHP tests in the `TESTS` list in `tests/run_php.sh`; shell and node tests as their own `bash X_test.sh; X_fail=$?` block in `tests/run.sh` **and** in the final `if` chain at `tests/run.sh:607`. A test missing from either silently never runs.
- Commit after every task. Message style: a sentence saying what changed and why. No `feat:`/`chore:` prefixes.
- Task 16 is a hardware-only verification that cannot run in the sandbox. It is a pre-merge blocker: the branch is not done until that output comes back.

---

### Task 1: Vendor the triage engine, unchanged, under test

The external `drive_triage.sh` becomes `scripts/drive_triage.sh` with **no behavioural change**, plus the stub harness every later task tests through. This task's value is the baseline: everything after it is a diff against a file already under test.

Source of truth: `C:\Users\Joe\.claude\drive_triage.sh` (794 lines, v3.0). Its `.txt` twin at `C:\Users\Joe\.claude\drive_triage.txt` is byte-identical — verified with `diff`, zero output. Copy the `.sh`.

**Files:**
- Create: `source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh`
- Create: `tests/drive_triage_test.sh`
- Modify: `tests/run.sh` (register the new test)

**Interfaces:**
- Produces: `bash scripts/drive_triage.sh --out DIR /dev/sdX` writes `DIR/<YYYYmmdd-HHMMSS>/report.txt`, `data.tsv`, `slots.tsv`. Exit 0 on a completed sweep, 2 on an unusable `--out`, 3 on a preflight failure (not root, no `smartctl`, unreadable `disks.ini`). Tasks 2–5 modify this file; Task 9 invokes it.

- [ ] **Step 1: Copy the engine in verbatim**

```bash
cp /c/Users/Joe/.claude/drive_triage.sh \
   source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh
chmod +x source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh
bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh && echo "syntax ok"
```

Expected: `syntax ok`.

Then make exactly three edits, and no others:

1. Line 43, `OUTDIR="/mnt/user/misc/drive-triage"` → `OUTDIR="/tmp/hbaviewer/triage"`. `/mnt/user` is unmounted whenever the array is stopped, and this runs from a plugin now, not from User Scripts.
2. Line 107, `VERSION="3.0"` → `VERSION="3.1"`.
3. Insert after line 36 (the closing `# ===...` of the banner):

```bash
# Vendored into HBAviewer from the standalone User Scripts version. The CLI
# contract is unchanged on purpose -- someone with this in User Scripts must be
# able to drop the plugin's copy in and see the same report. Everything the
# plugin needs beyond that is behind --events (see tri_emit).
```

- [ ] **Step 2: Write the failing test**

Create `tests/drive_triage_test.sh`. The engine shells out to `smartctl`, `sg_verify`, `sg_read`, `blockdev`, `mdcmd` and `dmesg`; all six are stubbed on `PATH`, the same approach `tests/read_smart_test.sh` uses.

```bash
#!/bin/bash
# Self-asserting checks for drive_triage.sh. The engine reads hardware through
# six binaries and nothing else, so stubbing those on PATH exercises the real
# control flow -- range building, classification, the report -- with no disk.
#
# Everything lives under mktemp -d: the run directories are named by timestamp
# and the fixtures carry SCSI addresses with colons in them, which NTFS cannot
# hold in a filename.
#   bash tests/drive_triage_test.sh   ->  "drive_triage: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
DT="../source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh"
fail=0
ok()  { echo "PASS  $1"; }
bad() { echo "FAIL  $1 -- $2"; fail=1; }
has()   { case "$2" in *"$3"*) ok "$1" ;; *) bad "$1" "want '$3'" ;; esac; }
hasnt() { case "$2" in *"$3"*) bad "$1" "did NOT want '$3'" ;; *) ok "$1" ;; esac; }

WORK=$(mktemp -d)
STUBDIR="$WORK/bin"; mkdir -p "$STUBDIR"
ARGS="$WORK/args"; : > "$ARGS"
trap 'rm -rf "$WORK"' EXIT

# Every stub records its own argv, so a test can assert which flags were used.
cat > "$STUBDIR/smartctl" <<'STUB'
#!/bin/bash
echo "smartctl $*" >> "$STUB_ARGS"
cat "$STUB_SMART"
STUB
cat > "$STUBDIR/sg_verify" <<'STUB'
#!/bin/bash
echo "sg_verify $*" >> "$STUB_ARGS"
exit "${STUB_VERIFY_RC:-0}"
STUB
cat > "$STUBDIR/sg_read" <<'STUB'
#!/bin/bash
echo "sg_read $*" >> "$STUB_ARGS"
exit "${STUB_READ_RC:-0}"
STUB
cat > "$STUBDIR/sg_logs" <<'STUB'
#!/bin/bash
exit 0
STUB
cat > "$STUBDIR/blockdev" <<'STUB'
#!/bin/bash
echo 1048576
STUB
cat > "$STUBDIR/dmesg" <<'STUB'
#!/bin/bash
cat "$STUB_DMESG" 2>/dev/null
STUB
chmod +x "$STUBDIR"/*

# A real SAS capture, already in the repo and already evidence.
export STUB_SMART="$PWD/fixtures/smart/sas_drive.txt"
export STUB_DMESG="$WORK/dmesg.txt"; : > "$STUB_DMESG"
export STUB_ARGS="$ARGS"

run() {  # remaining args go to the engine
    : > "$ARGS"
    OUT="$WORK/out"; rm -rf "$OUT"
    PATH="$STUBDIR:$PATH" EUID=0 bash "$DT" --out "$OUT" "$@" /dev/sdX 2>&1
}

# ── The CLI path still produces its report. ────────────────────────────────
out=$(run --no-triage)
RUNDIR=$(ls -1d "$WORK/out"/*/ 2>/dev/null | head -1)
[ -n "$RUNDIR" ] && ok "a run directory is created" || bad "a run directory is created" "none under $WORK/out"
[ -s "$RUNDIR/report.txt" ] && ok "report.txt is written" || bad "report.txt is written" "missing or empty"
has "the report names its version" "$(cat "$RUNDIR/report.txt")" "drive-triage v3.1"
has "the slot scan header is present" "$(cat "$RUNDIR/report.txt")" "SLOT SCAN"

# ── --out under /boot is refused outright: this must never touch the flash. ─
PATH="$STUBDIR:$PATH" bash "$DT" --out /boot/nope /dev/sdX >/dev/null 2>&1
[ $? -eq 2 ] && ok "refuses to write to /boot" || bad "refuses to write to /boot" "exit was not 2"

echo
[ $fail -eq 0 ] && { echo "drive_triage: all pass"; exit 0; } || { echo "drive_triage: FAILURES"; exit 1; }
```

- [ ] **Step 3: Run it to verify it fails**

Run: `bash tests/drive_triage_test.sh`
Expected: FAIL on `the report names its version` if step 1's version bump was skipped; otherwise all PASS. If every case passes on the first run that is the intended outcome here — this task's test is a *characterisation* test over code that already works, and its job starts in Task 2.

- [ ] **Step 4: Register the test in the suite**

In `tests/run.sh`, insert immediately above the `=== PHP tests ===` block:

```bash
echo
echo "=== drive triage engine tests ==="
bash drive_triage_test.sh; drive_triage_fail=$?
```

and add `&& [ $drive_triage_fail -eq 0 ]` to the final `if` chain at `tests/run.sh:607`.

- [ ] **Step 5: Run the full suite**

Run: `bash tests/run.sh`
Expected: `--- all pass ---`.

- [ ] **Step 6: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh tests/drive_triage_test.sh tests/run.sh
git commit -m "Vendor drive_triage.sh into scripts/ as the diagnostic engine, with a stubbed characterisation test. OUTDIR moves off /mnt/user, which is unmounted whenever the array is stopped."
```

---

### Task 2: `--events` — one JSON object per line

The engine gains a second output channel. The human report is untouched; the CLI / User Scripts path is untouched. `--events FILE` opens an append-only NDJSON sink and emits four record types.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh`
- Modify: `tests/drive_triage_test.sh`
- Create: `tests/expected/triage_events.ndjson`

**Interfaces:**
- Consumes: the engine from Task 1.
- Produces: `bash drive_triage.sh --events FILE …` appends lines to `FILE`. Exactly four `t` values, with these keys and no others:
  - `{"t":"phase","disk":"disk3","phase":"verify","lba_total":19532873728}` — `phase` is one of `preflight|baseline|targeted|surface|selftest|verdict`; `lba_total` is `0` where not applicable.
  - `{"t":"chunk","lba":1048576,"n":65536,"op":"verify","ms":42,"ok":true}` — `op` is `verify` or `read`; `ok` is a JSON boolean.
  - `{"t":"counter","key":"disp","before":210,"after":214}` — `key` is one of `uncorr|grown|nonmed|invdw|loss|disp`.
  - `{"t":"verdict","disk":"disk3","v":"TRANSPORT","why":"verify clean, read failed x3"}` — `v` is one of `MEDIA|TRANSPORT|CLEAN`.
  Task 9 writes this file into the job directory; Task 11 streams it; Task 13 renders it.

- [ ] **Step 1: Write the failing test**

Append to `tests/drive_triage_test.sh`, above the final `echo`:

```bash
# ── --events: a second, line-oriented channel. ─────────────────────────────
EV="$WORK/events.ndjson"
: > "$EV"
out=$(run --no-triage --events "$EV")
[ -s "$EV" ] && ok "--events writes an event file" || bad "--events writes an event file" "empty"

# Every line must be one complete JSON object -- the SSE reader in
# diagnose_stream.php splits on newlines and cannot recover from a wrapped one.
badline=0
while IFS= read -r l; do
    case "$l" in '{"t":"'*'}') ;; *) badline=1; echo "    offending line: $l" ;; esac
done < "$EV"
[ $badline -eq 0 ] && ok "every event line is one complete object" \
                   || bad "every event line is one complete object" "see above"

# Only the four documented types.
types=$(grep -o '"t":"[a-z]*"' "$EV" | sort -u | tr '\n' ' ')
case "$types" in
    *'"t":"phase"'*) ok "a phase event is emitted" ;;
    *) bad "a phase event is emitted" "types seen: $types" ;;
esac
unknown=$(grep -o '"t":"[a-z]*"' "$EV" | sort -u \
          | grep -v -E '"t":"(phase|chunk|counter|verdict)"' || true)
[ -z "$unknown" ] && ok "no undocumented event type" || bad "no undocumented event type" "$unknown"

# The human report is unaffected by the flag -- this is the CLI contract.
RUNDIR=$(ls -1d "$WORK/out"/*/ 2>/dev/null | head -1)
has "the report is still written alongside events" "$(cat "$RUNDIR/report.txt")" "SLOT SCAN"

# Without the flag, nothing is emitted anywhere.
: > "$EV"
run --no-triage >/dev/null
[ ! -s "$EV" ] && ok "no --events, no event output" || bad "no --events, no event output" "file grew"

# ── chunk and verdict events come from the triage path. ────────────────────
: > "$EV"
STUB_READ_RC=1 run --auto-triage --all --events "$EV" >/dev/null
has "a chunk event carries op and ms" "$(cat "$EV")" '"t":"chunk"'
has "a chunk event names its op"      "$(cat "$EV")" '"op":"verify"'
has "a verdict event is emitted"      "$(cat "$EV")" '"t":"verdict"'
has "verify clean + read failed reads TRANSPORT" "$(cat "$EV")" '"v":"TRANSPORT"'
```

- [ ] **Step 2: Run it to verify it fails**

Run: `bash tests/drive_triage_test.sh`
Expected: FAIL on `--events writes an event file` — the flag does not exist, so the engine prints `ignoring unrecognized argument: --events` and the file stays empty.

- [ ] **Step 3: Implement the emitter**

In `drive_triage.sh`, add to the CONFIG block after line 100 (`THROTTLE="0"`):

```bash
# Line-oriented event sink for the plugin's live view. Empty = off, which is
# the CLI / User Scripts path and must stay the default.
EVENTS=""
```

Add to the argument loop (after the `--out` case):

```bash
        --events)          EVENTS="$2"; shift ;;
```

Add below the `have()` helper (after line 161):

```bash
# One JSON object per line, appended. Everything the browser sees comes through
# here, so it is deliberately the only writer: a second emission path is a
# second place for the schema to drift.
#
# No jq. The values are integers, a fixed set of lowercase keywords, and one
# free-text reason -- so the only escaping this needs is on `why`, and doing it
# in shell keeps the engine's dependency list at sg3_utils + smartctl.
tri_esc() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g; s/[[:cntrl:]]/ /g'; }

tri_emit() {  # $1 = complete JSON object body, without braces
    [[ -n "$EVENTS" ]] || return 0
    printf '{%s}\n' "$1" >> "$EVENTS"
}

tri_phase() {  # disk phase lba_total
    tri_emit "\"t\":\"phase\",\"disk\":\"$(tri_esc "$1")\",\"phase\":\"$2\",\"lba_total\":${3:-0}"
}
tri_chunk() {  # lba n op ms ok(0|1)
    local b=false; [[ "$5" == "1" ]] && b=true
    tri_emit "\"t\":\"chunk\",\"lba\":$1,\"n\":$2,\"op\":\"$3\",\"ms\":$4,\"ok\":$b"
}
tri_counter() {  # key before after
    tri_emit "\"t\":\"counter\",\"key\":\"$1\",\"before\":$2,\"after\":$3"
}
tri_verdict() {  # disk v why
    tri_emit "\"t\":\"verdict\",\"disk\":\"$(tri_esc "$1")\",\"v\":\"$2\",\"why\":\"$(tri_esc "$3")\""
}

# Milliseconds elapsed since $1 (a value from tri_now_ms).
tri_now_ms() { printf '%s' "$(( $(date +%s%N) / 1000000 ))"; }
```

Emit at six sites, and only these six:

1. Immediately after `sect "PREFLIGHT"` (line 182): `tri_phase "-" preflight 0`
2. Immediately after `sect "COLLECTING COUNTERS"` (line 288): `tri_phase "-" baseline 0`
3. In `run_verify()` and `run_read()`, wrap each chunk. Replace the body of the `while` loop in `run_verify()` with:

```bash
    while [[ $pos -lt $count ]]; do
        n=$(( count - pos )); [[ $n -gt $CHUNK ]] && n=$CHUNK
        local t0 ms cok=1
        t0="$(tri_now_ms)"
        if ! timeout 120 sg_verify --16 --vrprotect=0 --lba=$(( start + pos )) \
            --count=$n "/dev/$dev" >> "$RUN/verify-$dev.txt" 2>&1; then
            rc=$?; fails=$(( fails + 1 )); cok=0
            echo "VERIFY FAIL lba=$(( start + pos )) count=$n rc=$rc" >> "$RUN/verify-fail-$dev.txt"
        fi
        ms=$(( $(tri_now_ms) - t0 ))
        tri_chunk "$(( start + pos ))" "$n" verify "$ms" "$cok"
        pos=$(( pos + n ))
        [[ "$THROTTLE" != "0" ]] && sleep "$THROTTLE"
    done
```

and the equivalent in `run_read()`, with `tri_chunk "$(( start + pos ))" "$n" read "$ms" "$cok"` and `cok=0` set inside each of the two failure branches.

4. In `triage_disk()`, immediately before the `IFS=',' read -ra RL` line: `tri_phase "$name" targeted 0`
5. In `triage_disk()`, inside the `SURFACE_SCAN` branch immediately after `total=$(( … ))`: `tri_phase "$name" surface "$total"`, and immediately before the `SHORT_SELFTEST` branch: `tri_phase "$name" selftest 0`
6. In `triage_disk()`, replace the counter-delta `printf` line with the printf followed by `tri_counter "$k" "$v1" "$v2"`, and in the three verdict branches add, respectively:

```bash
        tri_verdict "$name" TRANSPORT "verify clean, read failed"
        tri_verdict "$name" MEDIA "verify failed on the drive's own media"
        tri_verdict "$name" CLEAN "no fault reproduced"
```

with `tri_phase "$name" verdict 0` immediately above the `if [[ $vran -eq 1 …` line.

- [ ] **Step 4: Run the test to verify it passes**

Run: `bash tests/drive_triage_test.sh`
Expected: PASS on all cases, ending `drive_triage: all pass`.

- [ ] **Step 5: Run the full suite**

Run: `bash tests/run.sh`
Expected: `--- all pass ---`.

- [ ] **Step 6: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh tests/drive_triage_test.sh
git commit -m "Add --events to the triage engine: one JSON object per line for the live view, alongside the unchanged human report. Off by default, so the CLI path is untouched."
```

---

### Task 3: Surface-scan chunk size, and the sg3_utils gate

The full-surface path calls `sg_verify` in 2048-block chunks. On a 10 TB 512-byte-block disk that is ~9.5 million process launches and process-start overhead dominates. Surface scans move to 32768-block chunks. Targeted re-tests keep 2048, where per-chunk latency granularity is the point.

**Open item from the spec, resolved as graceful degradation rather than a hard requirement:** the minimum `sg3_utils` version for `sg_verify`/`sg_read` at the larger chunk size is unconfirmed. Rather than block on it, the engine reads `sg_verify --version`, and falls back to the old 2048 chunk for the surface path when the version cannot be parsed or is below the floor. An old box therefore scans slowly, exactly as it does today, instead of failing. The floor is `1.42`, the release where `sg_verify --16 --lba= --count=` and `sg_read bpt=` are all documented together. **Task 16 confirms the real version on hardware and is a pre-merge blocker** — if it comes back below 1.42 the floor is wrong, not the mechanism.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh`
- Modify: `tests/drive_triage_test.sh`

**Interfaces:**
- Consumes: `tri_phase`, `run_verify` from Task 2.
- Produces: `SURFACE_CHUNK` (default `32768`) and `tri_surface_chunk()`, which returns the chunk size the surface path should use — `$SURFACE_CHUNK` when `sg_verify --version` parses at or above `SG_MIN_VER`, `$CHUNK` otherwise. Nothing later consumes these directly.

- [ ] **Step 1: Write the failing test**

Append to `tests/drive_triage_test.sh`, above the final `echo`:

```bash
# ── Surface scans use big chunks; targeted re-tests keep small ones. ───────
cat > "$STUBDIR/sg_verify" <<'STUB'
#!/bin/bash
echo "sg_verify $*" >> "$STUB_ARGS"
case "$1" in --version) echo "sg_verify version: 1.48 20230228"; exit 0 ;; esac
exit "${STUB_VERIFY_RC:-0}"
STUB
chmod +x "$STUBDIR/sg_verify"

: > "$EV"
run --auto-triage --all --surface --events "$EV" >/dev/null
has "the surface phase is announced"   "$(cat "$EV")" '"phase":"surface"'
has "surface chunks are 32768 blocks"  "$(cat "$EV")" '"n":32768,"op":"verify"'
has "targeted chunks stay at 2048"     "$(grep '"phase":"targeted"' -A0 "$EV"; grep -m1 '"n":2048' "$EV")" '"n":2048'

# ── An sg3_utils too old for the big chunk degrades, it does not fail. ────
cat > "$STUBDIR/sg_verify" <<'STUB'
#!/bin/bash
echo "sg_verify $*" >> "$STUB_ARGS"
case "$1" in --version) echo "sg_verify version: 1.30 20101219"; exit 0 ;; esac
exit "${STUB_VERIFY_RC:-0}"
STUB
chmod +x "$STUBDIR/sg_verify"
: > "$EV"
out=$(run --auto-triage --all --surface --events "$EV")
hasnt "an old sg3_utils does not use the big chunk" "$(cat "$EV")" '"n":32768'
has   "and says why in the report"                  "$out" "sg3_utils"

# ── sg_verify that cannot report a version is treated as old. ─────────────
cat > "$STUBDIR/sg_verify" <<'STUB'
#!/bin/bash
echo "sg_verify $*" >> "$STUB_ARGS"
case "$1" in --version) exit 1 ;; esac
exit "${STUB_VERIFY_RC:-0}"
STUB
chmod +x "$STUBDIR/sg_verify"
: > "$EV"
run --auto-triage --all --surface --events "$EV" >/dev/null
hasnt "an unparseable version is treated as old" "$(cat "$EV")" '"n":32768'
```

- [ ] **Step 2: Run it to verify it fails**

Run: `bash tests/drive_triage_test.sh`
Expected: FAIL on `surface chunks are 32768 blocks` — every chunk is still 2048.

- [ ] **Step 3: Implement**

In the CONFIG block, replace the `CHUNK`/`THROTTLE` lines (98–100) with:

```bash
# Targeted re-test of an already-flagged range: small chunks, because
# per-chunk latency is the signal there.
CHUNK="2048"
# Full surface: big chunks. At 2048 blocks a 10 TB 512B-block disk is roughly
# 9.5 MILLION sg_verify launches and process start-up dominates the scan.
SURFACE_CHUNK="32768"
THROTTLE="0"

# sg3_utils floor for the big-chunk surface path. 1.42 is where
# `sg_verify --16 --lba= --count=` and `sg_read bpt=` are all documented
# together. Below it -- or when the tool will not say -- the surface scan falls
# back to CHUNK and takes as long as it does today. Degrading is the right
# failure here: a slow scan is a scan, a refused one is nothing.
SG_MIN_VER="1.42"
```

Add below `tri_now_ms()`:

```bash
# "1.48" -> 1048, "1.9" -> 1009: a sortable integer, so 1.9 does not compare
# above 1.42 the way a string would.
tri_ver_num() { awk -F. '{ printf "%d%03d", $1, $2 }' <<< "${1%%[!0-9.]*}"; }

tri_surface_chunk() {
    local v
    v="$(sg_verify --version 2>/dev/null | grep -o '[0-9]\+\.[0-9]\+' | head -1)"
    if [[ -n "$v" ]] && [[ "$(tri_ver_num "$v")" -ge "$(tri_ver_num "$SG_MIN_VER")" ]]; then
        printf '%s' "$SURFACE_CHUNK"
        return 0
    fi
    warn "sg3_utils ${v:-unreadable} is below $SG_MIN_VER -- surface scan falls back to $CHUNK-block chunks (slower, same result)"
    printf '%s' "$CHUNK"
}
```

`run_verify()` takes the chunk as an optional fourth argument so the surface path can pass a different one without a global. Change its first line to:

```bash
    local dev="$1" start="$2" count="$3" chunk="${4:-$CHUNK}" pos=0 n rc fails=0
```

and inside the loop replace `[[ $n -gt $CHUNK ]] && n=$CHUNK` with `[[ $n -gt $chunk ]] && n=$chunk`.

In `triage_disk()`, replace the `SURFACE_SCAN` block with:

```bash
    if [[ "$SURFACE_SCAN" == "yes" && $HAVE_SG -eq 1 ]]; then
        local total step schunk
        total=$(( $(blockdev --getsz "/dev/$dev") / ( ${LBS_OF[$dev]:-512} / 512 ) ))
        schunk="$(tri_surface_chunk)"
        warn "full-surface VERIFY of $total blocks in $schunk-block chunks -- hours"
        tri_phase "$name" surface "$total"
        step=$(( total / 20 ))
        for i in $(seq 0 19); do
            run_verify "$dev" $(( i * step )) "$step" "$schunk" || vfail=1
            info "  $(( (i+1) * 5 ))% ($(date +%H:%M:%S))"
        done
    fi
```

The `tri_phase "$name" surface "$total"` line added in Task 2 is now inside this block; remove the Task 2 copy if it was placed above `warn`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `bash tests/drive_triage_test.sh`
Expected: `drive_triage: all pass`.

- [ ] **Step 5: Run the full suite**

Run: `bash tests/run.sh`
Expected: `--- all pass ---`.

- [ ] **Step 6: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh tests/drive_triage_test.sh
git commit -m "Surface scans move to 32768-block chunks; 2048 stays for targeted re-tests where per-chunk latency is the signal. An sg3_utils below 1.42 degrades to the old chunk size rather than refusing the scan."
```

---

### Task 4: `-n standby` on every `smartctl` call in the engine

Three call sites in the engine pass `-n never`, which spins up a sleeping drive. `SKIP_STANDBY` only gates the *probe*; the reads that follow do not honour it. The spec makes the guard absolute.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh`
- Modify: `tests/drive_triage_test.sh`

**Interfaces:**
- Consumes: the engine from Task 3.
- Produces: no new functions. The behavioural contract — no `smartctl` invocation from this file carries `-n never` — is asserted by test and by the mutation check below.

- [ ] **Step 1: Write the failing test**

Append to `tests/drive_triage_test.sh`, above the final `echo`:

```bash
# ── Never spin up a sleeping drive. ────────────────────────────────────────
run --auto-triage --all >/dev/null
smart_calls=$(grep -c '^smartctl ' "$ARGS")
[ "$smart_calls" -gt 0 ] && ok "smartctl was actually called ($smart_calls times)" \
                         || bad "smartctl was actually called" "zero calls -- the assertion below would pass vacuously"
hasnt "no smartctl call passes -n never" "$(cat "$ARGS")" '-n never'
nostandby=$(grep '^smartctl ' "$ARGS" | grep -v -- '-n standby' || true)
[ -z "$nostandby" ] && ok "every smartctl call passes -n standby" \
                    || bad "every smartctl call passes -n standby" "$nostandby"

# Mutation check: prove the assertion above can fail. A copy of the engine with
# the guard removed must be caught by exactly these two cases and nothing else.
MUT="$WORK/mutant.sh"
sed 's/-n standby/-n never/g' "$DT" > "$MUT"
: > "$ARGS"
PATH="$STUBDIR:$PATH" bash "$MUT" --out "$WORK/mout" --auto-triage --all /dev/sdX >/dev/null 2>&1
mutleft=$(grep '^smartctl ' "$ARGS" | grep -v -- '-n standby' || true)
[ -n "$mutleft" ] && ok "the standby assertion is able to fail (mutant caught)" \
                  || bad "the standby assertion is able to fail" "mutant passed -- the assertion proves nothing"
```

- [ ] **Step 2: Run it to verify it fails**

Run: `bash tests/drive_triage_test.sh`
Expected: FAIL on `no smartctl call passes -n never`, listing the `-x -d auto -n never` invocations.

- [ ] **Step 3: Implement**

Three edits, all in `drive_triage.sh`:

1. In the counter-collection loop, replace `smartctl -x -d auto -n never "/dev/$dev" > "$RUN/smart-$dev.txt" 2>&1` with `smartctl -x -d auto -n standby "/dev/$dev" > "$RUN/smart-$dev.txt" 2>&1`.
2. In `snap()`, the same substitution on its `smartctl -x` line.
3. In `triage_disk()`, the two self-test calls become `smartctl -n standby -t short -d auto "/dev/$dev"`, `smartctl -n standby -l selftest -d auto "/dev/$dev"` and `smartctl -n standby -t long -d auto "/dev/$dev"`.

Add above the `snap()` definition:

```bash
# -n standby on EVERY call, including the ones inside a triage that has already
# decided the disk is awake. HBAviewer's standing guarantee is that nothing it
# does wakes a sleeping disk, and a guard that holds only on the probe is a
# guard the next edit removes without noticing. This is deliberately stricter
# than scripts/read_smart.sh, which skips the flag on the SAS bus because a
# log-page read is electronics-only; that exception is not extended here.
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `bash tests/drive_triage_test.sh`
Expected: `drive_triage: all pass`, including `the standby assertion is able to fail (mutant caught)`.

- [ ] **Step 5: Run the full suite**

Run: `bash tests/run.sh`
Expected: `--- all pass ---`.

- [ ] **Step 6: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh tests/drive_triage_test.sh
git commit -m "Pass -n standby on every smartctl call in the triage engine, not just the standby probe. Includes a mutation check proving the assertion can fail."
```

---

### Task 5: Count-based event-file retention

The engine already trims run directories to `KEEP_RUNS`. Job directories under `/tmp/hbaviewer/jobs/` are a second pile with the same growth problem and no trimming. Retention is count-based per disk, matching the knob the script already exposes, and the count becomes a settings key so it is one number in one place.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/config.php`
- Create: `source/usr/local/emhttp/plugins/hbaviewer/diagnose.php` (pure half only — the dispatch arrives in Task 10)
- Create: `tests/diagnose_test.php`
- Modify: `tests/run_php.sh`
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/settings.php`

**Interfaces:**
- Produces:
  - `DIAG_ROOT` = `'/tmp/hbaviewer/jobs'`, `DIAG_SCRIPTS` = `'/usr/local/emhttp/plugins/hbaviewer/scripts'` (consts, above the dispatch guard).
  - `diag_disk_valid(string $disk): bool` — true for `/^[a-z0-9]{2,32}\z/`. Every later task validates with this and nothing else.
  - `diag_job_id(string $disk, int $now): string` — `"$disk-$now"`. Filename-safe by construction; no `:`.
  - `diag_job_dir(string $jobId, string $root = DIAG_ROOT): string`.
  - `diag_trim_runs(string $disk, int $keep, string $root = DIAG_ROOT): array` — deletes all but the newest `$keep` job directories for `$disk`, newest by directory mtime, and returns the removed paths sorted. `$keep < 1` removes nothing.
  - Config key `DIAG_KEEP_RUNS` => `[14, 1, 90]`.
  Tasks 9–11 consume all of these.

- [ ] **Step 1: Write the failing test**

Create `tests/diagnose_test.php`:

```php
<?PHP
/* Runnable checks for diagnose.php's pure half: identity, the per-disk
   retention sweep, the lock, the preflight and the SSE slice. Nothing here
   touches /tmp/hbaviewer, a real disk or a real job -- every path is injected.
     php tests/diagnose_test.php  ->  "diagnose: all pass" (exit 0) */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/diagnose.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

$root = sys_get_temp_dir() . '/hbav_diag_' . getmypid();
@mkdir($root, 0777, true);

/* ── identity ──────────────────────────────────────────────────────────── */
check('a plain device name is valid',  diag_disk_valid('sdb'));
check('an nvme name is valid',         diag_disk_valid('nvme0n1'));
check('an empty name is refused',      !diag_disk_valid(''));
// The name becomes a directory component under DIAG_ROOT and an argument to a
// script running as root. Anything that can climb out, carry a shell
// metacharacter, or hold the ':' NTFS forbids in a path is refused HERE and
// nowhere else -- one validator, so two call sites cannot disagree.
check('a traversal is refused',        !diag_disk_valid('../etc'));
check('a slash is refused',            !diag_disk_valid('sd/b'));
check('a colon is refused',            !diag_disk_valid('0:0:2:0'));
check('a space is refused',            !diag_disk_valid('sd b'));
check('an uppercase name is refused',  !diag_disk_valid('SDB'));
check('a job id carries no separator of its own',
      diag_job_id('sdb', 1700000000) === 'sdb-1700000000');
check('a job dir sits under the root it was given',
      diag_job_dir('sdb-17', $root) === "$root/sdb-17");

/* ── retention: newest $keep per disk, and only that disk ──────────────── */
$mk = function (string $id, int $age) use ($root) {
    @mkdir("$root/$id", 0777, true);
    file_put_contents("$root/$id/events.ndjson", 'x');
    touch("$root/$id", 1_700_000_000 - $age);
};
$wipe = function () use ($root) {
    foreach (glob("$root/*") ?: [] as $d) {
        foreach (glob("$d/*") ?: [] as $f) @unlink($f);
        @rmdir($d);
    }
};
$wipe();
$mk('sdb-1', 400); $mk('sdb-2', 300); $mk('sdb-3', 200); $mk('sdb-4', 100);
$mk('sdc-1', 500);                        // another disk, must be untouched
$removed = diag_trim_runs('sdb', 2, $root);
check('trimming keeps the newest N', is_dir("$root/sdb-4") && is_dir("$root/sdb-3"));
check('trimming removes the oldest', !is_dir("$root/sdb-1") && !is_dir("$root/sdb-2"));
check('trimming names what it removed', $removed === ["$root/sdb-1", "$root/sdb-2"]);
// A sweep keyed on a bare prefix eats a neighbour: glob("sdb*") does not match
// sdc, but the same code written as glob("sd*") does, and deleting another
// disk's history is not a tidy-up. Asserted so the shape of the match is
// pinned rather than assumed.
check('trimming leaves other disks alone', is_dir("$root/sdc-1"));
check('keep below 1 removes nothing',
      diag_trim_runs('sdb', 0, $root) === [] && is_dir("$root/sdb-3"));
check('a disk with no runs is not an error', diag_trim_runs('sdz', 2, $root) === []);
check('an invalid disk name trims nothing',  diag_trim_runs('../', 1, $root) === []);

$wipe(); @rmdir($root);
echo $fails === 0 ? "diagnose: all pass\n" : "diagnose: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/diagnose_test.php`
Expected: a fatal — `Failed opening required '.../diagnose.php'`.

- [ ] **Step 3: Implement**

Create `source/usr/local/emhttp/plugins/hbaviewer/diagnose.php`:

```php
<?PHP
/* HBAviewer disk-diagnose job endpoint — read-only.
 *
 * Phase 1 of the Disk Utility. Every operation this launches is a read: SMART
 * and log pages, SCSI VERIFY, SCSI READ, a SMART self-test. Nothing here
 * writes to a device, which is why it is NOT behind flash.php's opt-in toggle.
 *
 * The launch/lock/cancel SHAPE is flash.php's, deliberately: pure guards above
 * the dispatch, an atomic fopen('x') single-flight claim, a detached job that
 * releases its own lock. It shares no CODE with flash.php -- that file is the
 * only mutating surface in the plugin, and folding a read path into it widens
 * the thing docs/review-policy.md exists to protect.
 *
 * Job state is /tmp, never /boot. A triage run is worth nothing after a reboot
 * and flash wear is worth avoiding (contrast bay_map.json, which is the one
 * thing here that cannot be regenerated).
 */

/* Every const the CLI test runner reaches must be declared ABOVE the dispatch
   guard: functions are hoisted, top-level consts are not. See ARCHITECTURE.md
   -- a const beside its callers blanked the SMART tab once. */
const DIAG_ROOT    = '/tmp/hbaviewer/jobs';
const DIAG_SCRIPTS = '/usr/local/emhttp/plugins/hbaviewer/scripts';

/* Lowercase alnum only. That covers every name Linux gives a physical block
   device (sdb, nvme0n1) and excludes a traversal, a shell metacharacter, and
   the ':' NTFS cannot hold in a path. Device-mapper names are excluded on
   purpose: this diagnoses disks behind an HBA, not mappings over them. */
function diag_disk_valid(string $disk): bool {
    return (bool) preg_match('/^[a-z0-9]{2,32}\z/', $disk);
}

/* "sdb-1700000000". The timestamp is both the ordering key and the uniqueness
   key; two jobs for one disk in the same second cannot happen because the
   per-disk lock refuses the second (see diag_claim_lock). */
function diag_job_id(string $disk, int $now): string {
    return $disk . '-' . $now;
}

function diag_job_dir(string $jobId, string $root = DIAG_ROOT): string {
    return $root . '/' . $jobId;
}

/* Keep the newest $keep job directories for ONE disk; delete the rest and say
   which. Count-based, matching the KEEP_RUNS trimming drive_triage.sh already
   does -- an age-based scheme would be a second retention idea in a plugin
   that has one.
   Matched on the exact "<disk>-<digits>" shape rather than trusting the glob:
   the glob is a prefix match, so a careless pattern reaches a neighbouring
   disk's runs, and deleting those is not a tidy-up. */
function diag_trim_runs(string $disk, int $keep, string $root = DIAG_ROOT): array {
    if ($keep < 1 || !diag_disk_valid($disk)) return [];
    $dirs = [];
    foreach (glob("$root/$disk-*", GLOB_ONLYDIR) ?: [] as $d) {
        if (!preg_match('/^' . preg_quote($disk, '/') . '-\d+\z/', basename($d))) continue;
        $dirs[$d] = (int) @filemtime($d);
    }
    if (count($dirs) <= $keep) return [];
    arsort($dirs);                              // newest first
    $doomed = array_slice(array_keys($dirs), $keep);
    foreach ($doomed as $d) {
        foreach (glob("$d/*") ?: [] as $f) @unlink($f);
        @rmdir($d);
    }
    sort($doomed);
    return $doomed;
}
```

In `config.php`, add to the schema map immediately below `'LOCATE_MAX_SECS'`:

```php
    /* Diagnose job directories kept per disk under /tmp/hbaviewer/jobs.
       Count-based, the same shape as drive_triage.sh's own KEEP_RUNS. */
    'DIAG_KEEP_RUNS'  => [14, 1, 90],
```

In `settings.php`, add a number input for `DIAG_KEEP_RUNS` in the same fieldset as `LOCATE_MAX_SECS`, labelled `Diagnose runs kept per disk:` with help text `How many completed Diagnose runs to keep for each disk. Older ones are removed when a new job starts. They live in RAM and are gone at reboot either way.`

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/diagnose_test.php`
Expected: `diagnose: all pass`.

- [ ] **Step 5: Register the test**

In `tests/run_php.sh`, add `diagnose_test.php` to the `TESTS` list, on the line holding `locate_test.php`.

- [ ] **Step 6: Run the full suite and commit**

Run: `bash tests/run.sh`
Expected: `--- all pass ---`.

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/diagnose.php \
        source/usr/local/emhttp/plugins/hbaviewer/config.php \
        source/usr/local/emhttp/plugins/hbaviewer/settings.php \
        tests/diagnose_test.php tests/run_php.sh
git commit -m "Add diagnose.php's identity and retention helpers plus a DIAG_KEEP_RUNS setting. Retention is count-based per disk, matching the engine's own KEEP_RUNS rather than inventing an age-based scheme."
```

---

### Task 6: `/dev/kmsg` sequence numbers, not line counts

`sas_error_monitor.sh` tracks new kernel messages by counting `dmesg` lines. Once the ring buffer wraps the count stops growing and every later event is silently missed — on exactly the busy, erroring box where they matter. The merged version reads `/dev/kmsg`, whose every record carries a monotonic sequence number.

Source: `C:\Users\Joe\Documents\sas_error_monitor.sh` (146 lines). Its `.txt` twin at `C:\Users\Joe\.claude\sas_error_monitor.txt` is byte-identical — verified with `diff`, zero output.

`/dev/kmsg` record format is `<priority>,<seq>,<timestamp_us>,<flag>[,key=val…];<message>`, with continuation lines beginning with a space. The parser keys on field 2.

**Files:**
- Create: `source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/kmsg.sh`
- Create: `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_kmsg.sh`
- Create: `tests/fixtures/kmsg_medium.txt`
- Create: `tests/expected/kmsg_medium.json`, `tests/expected/kmsg_since.json`, `tests/expected/kmsg_empty.json`
- Modify: `tests/run.sh`

**Interfaces:**
- Produces: `bash scripts/parse/kmsg.sh [since_seq]` — a pure filter, `/dev/kmsg` text on stdin, one JSON object on stdout:
  `{"max_seq":4812,"events":[{"seq":4807,"dev":"sdf","text":"critical medium error, dev sdf, sector 1234567"}]}`
  `events` holds only records with a sequence **above** `since_seq` (default `0`) whose text matches `critical medium error`, case-insensitively. `max_seq` is the highest sequence in the whole input, matched or not, and `0` on empty input. `dev` is the `dev <name>` the kernel names, or `""`.
  `bash scripts/get_kmsg.sh [since_seq]` is the composer: reads `/dev/kmsg` non-blockingly and pipes it through the parser.
  Task 7 consumes the parser's JSON shape; Task 8 calls the composer.

- [ ] **Step 1: Write the fixture**

Real `/dev/kmsg` output is the evidence here. Create `tests/fixtures/kmsg_medium.txt` from a capture (`dd if=/dev/kmsg iflag=nonblock 2>/dev/null | head -40`), masking serials length-preservingly. If no box is reachable when this task runs, use the exact record shapes below — and **Task 16 replaces the file with a real capture before merge**:

```
6,4805,1122334455,-;sd 0:0:5:0: [sdf] tag#0 Sense Key : Medium Error [current]
6,4806,1122334456,-;sd 0:0:5:0: [sdf] tag#0 Add. Sense: Unrecovered read error
3,4807,1122334457,-;critical medium error, dev sdf, sector 1234567 op 0x0:(READ) flags 0x0 phys_seg 1 prio class 2
6,4808,1122334999,-;sd 0:0:2:0: [sdc] Attached SCSI disk
3,4812,1122335000,-;critical medium error, dev sdc, sector 98765 op 0x0:(READ) flags 0x0 phys_seg 1 prio class 2
```

- [ ] **Step 2: Add the failing golden checks**

In `tests/run.sh`, in the "stdin filters" block beside the other parser checks (the existing `$P` already points at `scripts/parse` — do not add a second path variable):

```bash
# /dev/kmsg, not dmesg line counts. sas_error_monitor.sh tracked new kernel
# messages by counting lines, so once the ring buffer wrapped the count stopped
# growing and every later medium error was silently missed. The sequence number
# in field 2 is monotonic across a wrap and is the only cursor that survives it.
check kmsg-medium  kmsg_medium.json  bash "$P/kmsg.sh"      < fixtures/kmsg_medium.txt
# Resuming from a cursor: everything at or below it is already reported. The
# MAXIMUM is unfiltered, or a quiet window would leave the cursor stuck.
check kmsg-since   kmsg_since.json   bash "$P/kmsg.sh" 4807 < fixtures/kmsg_medium.txt
check kmsg-empty   kmsg_empty.json   bash "$P/kmsg.sh"      < /dev/null
```

- [ ] **Step 3: Run it to verify it fails**

Run: `bash tests/run.sh 2>&1 | grep -E '^(PASS|FAIL) +kmsg'`
Expected: three `FAIL` lines — the parser does not exist, so `check` compares empty output against goldens that do not exist either.

- [ ] **Step 4: Implement the parser and composer**

Create `source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/kmsg.sh`:

```bash
#!/bin/bash
# Pure filter: /dev/kmsg text on stdin, one JSON object on stdout.
#
#   bash kmsg.sh [since_seq] < /dev/kmsg
#   {"max_seq":4812,"events":[{"seq":4807,"dev":"sdf","text":"..."}]}
#
# WHY SEQUENCE NUMBERS. The script this replaces counted dmesg lines and
# resumed from the count. A kernel ring buffer WRAPS: the line count stops
# growing while the content keeps changing, so every event after the first wrap
# went unreported -- on exactly the busy, erroring box where they matter.
# /dev/kmsg stamps each record with a monotonic sequence in field 2, which
# survives a wrap and restarts only at boot (where starting from 0 is right).
#
# max_seq is the highest sequence in the INPUT, matched or not. Filtering it to
# matched records would freeze the cursor through every quiet period and
# re-report the same error on the next run that found one.
#
# Continuation lines begin with a space, belong to the record above and carry
# no sequence of their own; they are skipped.
# No hardware access, no environment, no side effects -- the parser contract.
awk -v since="${1:-0}" '
    BEGIN { max = 0; n = 0 }
    /^[ \t]/ { next }
    {
        semi = index($0, ";")
        if (semi == 0) next
        head = substr($0, 1, semi - 1)
        msg  = substr($0, semi + 1)
        split(head, f, ",")
        seq = f[2] + 0
        if (seq > max) max = seq
        if (seq <= since + 0) next
        if (tolower(msg) !~ /critical medium error/) next
        dev = ""
        if (match(msg, /dev [a-z0-9]+/)) dev = substr(msg, RSTART + 4, RLENGTH - 4)
        gsub(/\\/, "\\\\", msg); gsub(/"/, "\\\"", msg)
        gsub(/[\r\n\t]/, " ", msg)
        ev[n++] = "{\"seq\":" seq ",\"dev\":\"" dev "\",\"text\":\"" msg "\"}"
    }
    END {
        out = ""
        for (i = 0; i < n; i++) out = out (i ? "," : "") ev[i]
        printf "{\"max_seq\":%d,\"events\":[%s]}", max, out
    }
'
```

Create `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_kmsg.sh`:

```bash
#!/bin/bash
# Composer: read /dev/kmsg NON-BLOCKING and hand it to the parser.
#
# iflag=nonblock is load-bearing. /dev/kmsg is a stream: a plain `cat` returns
# the buffer and then BLOCKS forever waiting for the next kernel message, which
# from a cron job is a process that never exits.
#
#   bash get_kmsg.sh [since_seq]
DIR="$(dirname "$0")"
dd if=/dev/kmsg iflag=nonblock bs=64k 2>/dev/null | bash "$DIR/parse/kmsg.sh" "${1:-0}"
```

- [ ] **Step 5: Generate the three goldens by name and read them**

```bash
cd tests
K=../source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/kmsg.sh
printf '%s' "$(bash $K      < fixtures/kmsg_medium.txt)" > expected/kmsg_medium.json
printf '%s' "$(bash $K 4807 < fixtures/kmsg_medium.txt)" > expected/kmsg_since.json
printf '%s' "$(bash $K      < /dev/null)"                > expected/kmsg_empty.json
cd ..
cat tests/expected/kmsg_medium.json; echo; cat tests/expected/kmsg_since.json; echo; cat tests/expected/kmsg_empty.json; echo
```

Expected, exactly:
- `kmsg_medium.json` — `"max_seq":4812`, `events` holds **two** objects (`sdf`/4807 and `sdc`/4812). The two Sense Key lines are not medium-error records and must not appear.
- `kmsg_since.json` — `"max_seq":4812` still, and `events` holds **one** object (`sdc`/4812).
- `kmsg_empty.json` — exactly `{"max_seq":0,"events":[]}`.

If any of those three differs, the parser is wrong — do not bless the output. The `printf '%s' "$(…)"` wrapper strips the trailing newline; `tests/run.sh`'s golden-hygiene check fails any golden that carries one.

- [ ] **Step 6: Run the goldens and the full suite**

Run: `bash tests/run.sh 2>&1 | grep -E '^(PASS|FAIL) +kmsg'`
Expected: three `PASS` lines.

Run: `bash tests/run.sh`
Expected: `--- all pass ---`.

- [ ] **Step 7: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/kmsg.sh \
        source/usr/local/emhttp/plugins/hbaviewer/scripts/get_kmsg.sh \
        tests/fixtures/kmsg_medium.txt tests/expected/kmsg_medium.json \
        tests/expected/kmsg_since.json tests/expected/kmsg_empty.json tests/run.sh
git commit -m "Add a /dev/kmsg parser and composer that track kernel medium errors by sequence number. The script this replaces counted dmesg lines, so every event after a ring-buffer wrap was silently missed."
```

---

### Task 7: `disk_alerts.php` — grown-defect increases and medium errors

The second surviving check, as pure functions in the shape `notify.php` already uses: `*_read(?string $path)` / `*_write(array, ?string $path)`, a const default path, the path always injectable, the shell-out injected as `$send`.

**Files:**
- Create: `source/usr/local/emhttp/plugins/hbaviewer/disk_alerts.php`
- Create: `tests/disk_alerts_test.php`
- Modify: `tests/run_php.sh`

**Interfaces:**
- Consumes: the parser payload shape from Task 6.
- Produces:
  - `DISK_ALERT_STATE` = `'/boot/config/plugins/hbaviewer/disk_alerts.json'`, `DISK_ALERT_BIN` = `'/usr/local/emhttp/webGui/scripts/notify'`.
  - `disk_alert_state_read(?string $path = null): array` → `['seq'=>int,'defects'=>[id=>int]]`; `disk_alert_state_write(array $state, ?string $path = null): void`.
  - `disk_alert_defect_rises(array $previous, array $current): array` → one `['disk'=>string,'from'=>int,'to'=>int]` per increase.
  - `disk_alert_run(array $defects, array $kmsg, ?callable $send = null, ?string $path = null, ?int $now = null): array` → `['defects'=>[…], 'medium'=>[…], 'seq'=>int]`.
  Task 8 calls `disk_alert_run()`.

- [ ] **Step 1: Write the failing test**

Create `tests/disk_alerts_test.php`:

```php
<?PHP
/* Runnable checks for disk_alerts.php -- the two checks salvaged from
   sas_error_monitor.sh, and the two bugs they must not carry in.
     php tests/disk_alerts_test.php  ->  "disk_alerts: all pass" (exit 0) */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/disk_alerts.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}
$state = sys_get_temp_dir() . '/hbav_da_' . getmypid() . '.json';
@unlink($state);

/* ── grown defect list: only an INCREASE is news ───────────────────────── */
check('an increase is a rise',
      disk_alert_defect_rises(['ata-X' => 4], ['ata-X' => 7])
      === [['disk' => 'ata-X', 'from' => 4, 'to' => 7]]);
check('an unchanged count is not a rise',
      disk_alert_defect_rises(['ata-X' => 4], ['ata-X' => 4]) === []);
// A defect list that came back smaller is not a drive healing. It is a read
// this code cannot explain, and notifying on it trains the user to ignore the
// channel that carries the real one.
check('a decrease is not a rise',
      disk_alert_defect_rises(['ata-X' => 9], ['ata-X' => 4]) === []);
// First sighting is history, not an event -- the rule notify_transitions()
// applies to a newly installed card, for the same reason.
check('a first sighting is not a rise',
      disk_alert_defect_rises([], ['ata-X' => 12]) === []);
check('a vanished disk is not a rise',
      disk_alert_defect_rises(['ata-X' => 4], []) === []);

/* ── the run: state in, notifications out, state back ──────────────────── */
$sent = [];
$send = function (string $s, string $d, string $i) use (&$sent) { $sent[] = [$s, $d, $i]; };

@unlink($state);
$sent = [];
$r = disk_alert_run(['ata-X' => 4], ['max_seq' => 100, 'events' => []], $send, $state, 1_700_000_000);
check('a first run notifies nothing',         $sent === []);
check('a first run still records the cursor', $r['seq'] === 100);

$sent = [];
$r = disk_alert_run(['ata-X' => 6], ['max_seq' => 140, 'events' => []], $send, $state, 1_700_000_100);
check('the second run notifies the rise', count($sent) === 1);
check('the notification names the disk',  str_contains($sent[0][0], 'ata-X'));
check('a defect rise is a warning',       $sent[0][2] === 'warning');

/* ── kernel medium errors ──────────────────────────────────────────────── */
$sent = [];
$r = disk_alert_run(['ata-X' => 6], ['max_seq' => 160, 'events' => [
        ['seq' => 155, 'dev' => 'sdf', 'text' => 'critical medium error, dev sdf, sector 1234567'],
     ]], $send, $state, 1_700_000_200);
check('a medium error notifies',    count($sent) === 1);
check('a medium error is an alert', $sent[0][2] === 'alert');
check('the cursor advances past it', $r['seq'] === 160);

// The cursor is the whole point: without it one bad sector notifies every ten
// minutes for as long as the box is up.
$sent = [];
disk_alert_run(['ata-X' => 6], ['max_seq' => 160, 'events' => []], $send, $state, 1_700_000_300);
check('a cursor already past the event is silent', $sent === []);

// A reboot restarts /dev/kmsg at 0, leaving the stored cursor AHEAD of the
// whole buffer -- which would silence the channel until the box organically
// passed the old number. Hours of no alerts, feature still ticked.
$sent = [];
$r = disk_alert_run(['ata-X' => 6], ['max_seq' => 8, 'events' => [
        ['seq' => 5, 'dev' => 'sdf', 'text' => 'critical medium error, dev sdf, sector 9'],
     ]], $send, $state, 1_700_000_400);
check('a sequence reset is detected and not swallowed', count($sent) === 1);
check('and the cursor follows the buffer back down',    $r['seq'] === 8);

@unlink($state);
echo $fails === 0 ? "disk_alerts: all pass\n" : "disk_alerts: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/disk_alerts_test.php`
Expected: a fatal — `Failed opening required '.../disk_alerts.php'`.

- [ ] **Step 3: Implement**

Create `source/usr/local/emhttp/plugins/hbaviewer/disk_alerts.php`:

```php
<?PHP
/* HBAviewer per-disk alerts — the two checks worth keeping from the standalone
 * sas_error_monitor.sh, merged into the cron notification pipeline rather than
 * shipped as a second script that overlaps the SMART tab.
 *
 *   1. Kernel "critical medium error" records, cursored by /dev/kmsg SEQUENCE
 *      NUMBER. The original counted dmesg lines, so once the ring buffer
 *      wrapped the count stopped growing and every later event was missed.
 *   2. An INCREASE in a disk's grown defect list. A raw count is history; the
 *      change is the event.
 *
 * Everything except disk_alert_send() is pure over injected inputs, so
 * tests/disk_alerts_test.php covers the whole decision path with no /boot, no
 * hardware and no notify binary. Store shape matches notify.php and
 * phy_baseline.php: *_read(?string $path) / *_write(array, ?string $path), a
 * const default, path always injectable.
 *
 * Disks are keyed by their stable /dev/disk/by-id name, never by sd letter --
 * sd letters shift across reboots, so a letter-keyed baseline subtracts two
 * different disks from each other and calls the difference an increase.
 */

const DISK_ALERT_STATE = '/boot/config/plugins/hbaviewer/disk_alerts.json';
const DISK_ALERT_BIN   = '/usr/local/emhttp/webGui/scripts/notify';

/* {"seq": int, "defects": {disk_id: int}} */
function disk_alert_state_read(?string $path = null): array {
    $path ??= DISK_ALERT_STATE;
    $s = is_file($path) ? (json_decode((string) @file_get_contents($path), true) ?: []) : [];
    return ['seq' => (int) ($s['seq'] ?? 0), 'defects' => (array) ($s['defects'] ?? [])];
}
function disk_alert_state_write(array $state, ?string $path = null): void {
    $path ??= DISK_ALERT_STATE;
    @mkdir(dirname($path), 0755, true);
    @file_put_contents($path, json_encode([
        'seq'     => (int) ($state['seq'] ?? 0),
        'defects' => (array) ($state['defects'] ?? []),
    ]));
}

/* [disk_id => count] twice; one row per INCREASE. Three things are
   deliberately not a rise: a disk absent from $previous (first sighting is
   history), an unchanged count, and a DECREASE — a defect list that shrank is
   a read this code cannot explain rather than a drive that healed. */
function disk_alert_defect_rises(array $previous, array $current): array {
    $out = [];
    foreach ($current as $disk => $now) {
        if (!isset($previous[$disk])) continue;
        $was = (int) $previous[$disk];
        if ((int) $now > $was) $out[] = ['disk' => (string) $disk, 'from' => $was, 'to' => (int) $now];
    }
    return $out;
}

function disk_alert_send(string $subject, string $description, string $importance): void {
    shell_exec(DISK_ALERT_BIN
        . ' -e ' . escapeshellarg('HBAviewer')
        . ' -s ' . escapeshellarg($subject)
        . ' -d ' . escapeshellarg($description)
        . ' -i ' . escapeshellarg($importance)
        . ' >/dev/null 2>&1');
}

/* $defects: [disk_id => grown-defect count], collected with -n standby by the
   caller. $kmsg: the decoded payload from scripts/parse/kmsg.sh.
   Returns what it fired and the cursor it stored. */
function disk_alert_run(array $defects, array $kmsg, ?callable $send = null,
                        ?string $path = null, ?int $now = null): array {
    $send ??= 'disk_alert_send';
    $now  ??= time();
    $prev   = disk_alert_state_read($path);

    $maxSeq = (int) ($kmsg['max_seq'] ?? 0);
    /* A reboot restarts /dev/kmsg at 0, so a stored cursor can sit AHEAD of the
       whole buffer. Left alone that silences medium-error reporting until the
       box organically passed the old number -- hours or days of nothing, with
       the setting still ticked. A max_seq below the cursor is the only evidence
       of the reset available here, and acting on it costs at most one repeat of
       an error that is still present. */
    $cursor = $maxSeq < $prev['seq'] ? 0 : $prev['seq'];

    $medium = [];
    foreach ((array) ($kmsg['events'] ?? []) as $e) {
        if ((int) ($e['seq'] ?? 0) <= $cursor) continue;
        $medium[] = $e;
        $send('Critical medium error on ' . (($e['dev'] ?? '') !== '' ? $e['dev'] : 'an unknown device'),
              (string) ($e['text'] ?? ''), 'alert');
    }

    $rises = disk_alert_defect_rises($prev['defects'], $defects);
    foreach ($rises as $r) {
        $send($r['disk'] . ' grown defect list increased',
              'Was ' . $r['from'] . ', now ' . $r['to']
            . '. The drive has remapped more sectors since the last check.', 'warning');
    }

    disk_alert_state_write(['seq' => $maxSeq, 'defects' => $defects], $path);
    return ['defects' => $rises, 'medium' => $medium, 'seq' => $maxSeq];
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/disk_alerts_test.php`
Expected: `disk_alerts: all pass`.

- [ ] **Step 5: Register and run the full suite**

Add `disk_alerts_test.php` to the `TESTS` list in `tests/run_php.sh`, on the line holding `notify_test.php`.

Run: `bash tests/run.sh`
Expected: `--- all pass ---`.

- [ ] **Step 6: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/disk_alerts.php tests/disk_alerts_test.php tests/run_php.sh
git commit -m "Add disk_alerts.php: kernel medium errors cursored by kmsg sequence, and grown-defect-list increases. Pure over injected inputs in notify.php's store shape, with the cursor reset on a reboot so the channel cannot silence itself."
```

---

### Task 8: Wire the disk alerts into the cron

The collector — the only impure part — plus its hook in `notify_check.php`.

**Files:**
- Create: `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_defects.sh`
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/notify_check.php`
- Create: `tests/get_defects_test.sh`
- Modify: `tests/run.sh`
- Modify: `docs/foreground-reads.md`

**Interfaces:**
- Consumes: `disk_alert_run()` (Task 7), `scripts/get_kmsg.sh` (Task 6).
- Produces: `bash scripts/get_defects.sh` → `{"ata-WDC_X":12,"ata-HGST_Y":0}`. A disk in standby, or one reporting no defect line, is **omitted** rather than recorded as `0`. The `by-id` directory is injectable as `$DEFECTS_BYID` so the test can hold a real symlink farm.

- [ ] **Step 1: Write the failing test**

Create `tests/get_defects_test.sh`:

```bash
#!/bin/bash
# get_defects.sh makes two decisions worth pinning: it never wakes a sleeping
# disk, and it OMITS a disk it could not measure rather than recording a zero.
# Both are stubbed on PATH, the tests/read_smart_test.sh approach.
#   bash tests/get_defects_test.sh   ->  "get_defects: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
GD="../source/usr/local/emhttp/plugins/hbaviewer/scripts/get_defects.sh"
fail=0
ok()  { echo "PASS  $1"; }
bad() { echo "FAIL  $1 -- $2"; fail=1; }
has()   { case "$2" in *"$3"*) ok "$1" ;; *) bad "$1" "want '$3' in: $2" ;; esac; }
hasnt() { case "$2" in *"$3"*) bad "$1" "did NOT want '$3' in: $2" ;; *) ok "$1" ;; esac; }

WORK=$(mktemp -d); STUBDIR="$WORK/bin"; mkdir -p "$STUBDIR"
ARGS="$WORK/args"; : > "$ARGS"
trap 'rm -rf "$WORK"' EXIT

cat > "$STUBDIR/lsblk" <<'STUB'
#!/bin/bash
printf 'sdb\nsdc\n'
STUB
cat > "$STUBDIR/smartctl" <<'STUB'
#!/bin/bash
echo "smartctl $*" >> "$STUB_ARGS"
case "$*" in
    *sdb*) echo "Elements in grown defect list: 12" ;;
    *sdc*) echo "Device is in STANDBY mode, exit(2)"; exit 2 ;;
esac
STUB
chmod +x "$STUBDIR"/*
export STUB_ARGS="$ARGS"

# The by-id farm is built at runtime under mktemp -d: it holds symlinks, and
# the plan's Windows/NTFS rule keeps generated paths out of the repo.
BYID="$WORK/by-id"; mkdir -p "$BYID" "$WORK/dev"
: > "$WORK/dev/sdb"; : > "$WORK/dev/sdc"
ln -s "$WORK/dev/sdb" "$BYID/ata-TESTDISK_0001"
ln -s "$WORK/dev/sdc" "$BYID/ata-TESTDISK_0002"

out=$(PATH="$STUBDIR:$PATH" DEFECTS_BYID="$BYID" bash "$GD")

has   "a measurable disk is reported by its stable id" "$out" '"ata-TESTDISK_0001":12'
hasnt "a standby disk is omitted, not zeroed"          "$out" 'ata-TESTDISK_0002'
has   "the output is a JSON object"                    "$out" '{'
smart_calls=$(grep -c '^smartctl ' "$ARGS")
[ "$smart_calls" -gt 0 ] && ok "smartctl was actually called ($smart_calls times)" \
                         || bad "smartctl was actually called" "zero -- the next assertion would pass vacuously"
nostandby=$(grep '^smartctl ' "$ARGS" | grep -v -- '-n standby' || true)
[ -z "$nostandby" ] && ok "every smartctl call passes -n standby" \
                    || bad "every smartctl call passes -n standby" "$nostandby"

echo
[ $fail -eq 0 ] && { echo "get_defects: all pass"; exit 0; } || { echo "get_defects: FAILURES"; exit 1; }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `bash tests/get_defects_test.sh`
Expected: FAIL on every case — the script does not exist, so `$out` is empty.

- [ ] **Step 3: Implement**

Create `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_defects.sh`:

```bash
#!/bin/bash
# Grown defect count per disk, keyed by the /dev/disk/by-id name.
#
#   bash get_defects.sh   ->  {"ata-WDC_X":12,"ata-HGST_Y":0}
#
# KEYED BY STABLE ID, not by sd letter: sd letters shift across reboots, so a
# letter-keyed baseline subtracts two different disks from each other and calls
# the difference an increase.
#
# -n standby on every call. A sleeping disk is left asleep and OMITTED from the
# output -- not recorded as 0. Zero is a measurement; absence is not health,
# and a sleeping disk re-entering the baseline at 0 makes its real count look
# like an increase the next time it happens to be awake.
BYID="${DEFECTS_BYID:-/dev/disk/by-id}"

id_of() {  # sd name -> by-id name, or empty
    local dev="$1" link
    for link in "$BYID"/*; do
        [ -e "$link" ] || continue
        case "$(basename "$(readlink -f "$link")")" in
            "$dev") basename "$link"; return 0 ;;
        esac
    done
}

first=1
printf '{'
for dev in $(lsblk -S -d -n -o NAME 2>/dev/null); do
    n="$(smartctl -n standby -a "/dev/$dev" 2>/dev/null \
         | awk '/Elements in grown defect/ { gsub(/[^0-9]/, "", $NF); print $NF; exit }')"
    case "$n" in ''|*[!0-9]*) continue ;; esac
    id="$(id_of "$dev")"
    [ -n "$id" ] || continue
    [ $first -eq 1 ] || printf ','
    first=0
    printf '"%s":%s' "$id" "$n"
done
printf '}'
```

In `scripts/notify_check.php`, inside the existing `if ($doNotify) { … }` block, immediately after the `lsi_notify_run(...)` line:

```php
    /* Per-disk alerts: kernel medium errors and grown-defect increases, from
       the two checks worth keeping in sas_error_monitor.sh. Under
       ENABLE_NOTIFY alongside the controller-health check rather than a third
       toggle -- both answer "tell me when a disk problem appears", and the
       TRACK_HISTORY split was about two DIFFERENT features sharing a switch,
       not about splitting one.
       Both composers are bounded: one smartctl per disk, and a non-blocking
       read of a kernel buffer. Cron may block -- no request exists
       (docs/foreground-reads.md). */
    require_once "$plugin/disk_alerts.php";
    $defects = json_decode((string) shell_exec(
        'bash ' . escapeshellarg(__DIR__ . '/get_defects.sh') . ' 2>/dev/null'), true);
    $kmsg = json_decode((string) shell_exec(
        'bash ' . escapeshellarg(__DIR__ . '/get_kmsg.sh') . ' 2>/dev/null'), true);
    /* BOTH reads must have parsed. An unreadable defect list is not "every
       disk has zero defects": running with [] rewrites the baseline empty, so
       the next good read is a first sighting for every disk and the increase
       that mattered is gone. Same shape as the is_array($data) guard above. */
    if (is_array($defects) && is_array($kmsg)) disk_alert_run($defects, $kmsg);
```

In `docs/foreground-reads.md`, extend the **User-initiated — blocking is the point** section, where the existing cron sites already sit:

```
`scripts/notify_check.php` disk alerts — `get_defects.sh` (one
`smartctl -n standby` per disk) and `get_kmsg.sh` (`dd iflag=nonblock` on
`/dev/kmsg`, which never waits for a message that has not arrived). Cron, so
there is no request to hold. Both are bounded by the disk count and the buffer
size respectively; neither talks to a controller.
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `bash tests/get_defects_test.sh`
Expected: `get_defects: all pass`.

- [ ] **Step 5: Register and run the full suite**

In `tests/run.sh`, above the `=== PHP tests ===` block:

```bash
echo
echo "=== grown defect collector tests ==="
bash get_defects_test.sh; get_defects_fail=$?
```

and add `&& [ $get_defects_fail -eq 0 ]` to the final `if` chain at the bottom of the file.

Run: `bash tests/run.sh`
Expected: `--- all pass ---`.

- [ ] **Step 6: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/get_defects.sh \
        source/usr/local/emhttp/plugins/hbaviewer/scripts/notify_check.php \
        tests/get_defects_test.sh tests/run.sh docs/foreground-reads.md
git commit -m "Feed kernel medium errors and grown-defect increases into the existing notification cron. Disks are keyed by stable id, and a sleeping disk is omitted rather than baselined at zero."
```

---

### Task 9: The job runner's guards — lock, preflight, cancel, slice

All pure, all above the dispatch guard, all unit-tested before anything can launch a process. Modelled on `flash_preflight()` / `flash_claim_lock()`: every gate fails **closed** on a missing input.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/diagnose.php`
- Modify: `tests/diagnose_test.php`

**Interfaces:**
- Consumes: `diag_disk_valid()`, `diag_job_dir()` (Task 5).
- Produces:
  - `diag_lock_path(string $disk, string $root = DIAG_ROOT): string` — `"$root/$disk.lock"`. **Per disk, not per job**: the Tier 1 gate is "one job per disk at a time", and a per-job lock could never express that.
  - `diag_claim_lock(string $lock): bool` — atomic `fopen($lock,'x')`. True only if this caller now owns it.
  - `diag_preflight(array $in): array` → `['ok'=>bool,'error'=>string]`. Keys read: `disk`, `resync`, `locked`, `exists`. Every one fails closed on absence.
  - `diag_pgid(string $dir): ?int` — the process-group id the job recorded, or null.
  - `diag_cancel(string $dir, callable $kill): bool` — calls `$kill(-$pgid)` once and returns whether a pgid was found. The negative is the whole point.
  - `diag_slice(string $file, int $offset, int $maxBytes): array` → `['bytes'=>string,'offset'=>int,'eof'=>bool]` — reads from a byte offset, never returns a partial trailing line, and clamps a nonsense offset.
  Task 10 uses the first five; Task 11 uses `diag_slice()`.

- [ ] **Step 1: Write the failing test**

Append to `tests/diagnose_test.php`, immediately before the `$wipe(); @rmdir($root);` cleanup:

```php
/* ── the lock is per DISK, and claiming is atomic ──────────────────────── */
$lock = diag_lock_path('sdb', $root);
check('the lock is named for the disk, not the job', $lock === "$root/sdb.lock");
@unlink($lock);
check('the first claim wins',   diag_claim_lock($lock) === true);
// fopen('x') and not is_file()-then-touch(): the latter lets two concurrent
// requests both pass the check and both launch a job at the same disk.
check('the second claim loses',  diag_claim_lock($lock) === false);
@unlink($lock);
check('and wins again once released', diag_claim_lock($lock) === true);
@unlink($lock);

/* ── preflight: every gate fails closed ────────────────────────────────── */
$base = ['disk' => 'sdb', 'resync' => 0, 'locked' => false, 'exists' => true];
check('a clean request passes', diag_preflight($base)['ok'] === true);

$r = diag_preflight(['disk' => 'sdb', 'resync' => 0, 'locked' => false, 'exists' => false]);
check('a device that is not there is refused', $r['ok'] === false);
check('and the refusal names the device',      str_contains($r['error'], 'sdb'));

check('an invalid disk name is refused',
      diag_preflight(array_merge($base, ['disk' => '../etc']))['ok'] === false);
check('an empty disk name is refused',
      diag_preflight(array_merge($base, ['disk' => '']))['ok'] === false);
// Tier 1 gate from the risk table: the array must not be mid-rebuild. Reading
// every block of a disk that parity is currently reconstructing competes with
// the rebuild for the same spindle and the same link.
check('a running parity op is refused',
      diag_preflight(array_merge($base, ['resync' => 1]))['ok'] === false);
check('an existing job on this disk is refused',
      diag_preflight(array_merge($base, ['locked' => true]))['ok'] === false);

// Absence is refusal, not permission. flash_preflight's 'card' gate defaulted
// to allow once and it was the most dangerous gate in the plugin; this one is
// far cheaper but the rule is the rule, and a caller that forgets to pass a
// key must not get a pass.
foreach (['disk', 'resync', 'locked', 'exists'] as $k) {
    $missing = $base; unset($missing[$k]);
    check("omitting '$k' fails closed", diag_preflight($missing)['ok'] === false);
}

/* ── cancel kills the GROUP ────────────────────────────────────────────── */
$jd = diag_job_dir('sdb-42', $root);
@mkdir($jd, 0777, true);
$killed = [];
$kill = function (int $sig) use (&$killed) { $killed[] = $sig; return true; };
check('no pgid recorded means nothing to cancel', diag_cancel($jd, $kill) === false);
check('and nothing was signalled',                $killed === []);

file_put_contents("$jd/pgid", "12345\n");
check('a recorded pgid is read back', diag_pgid($jd) === 12345);
$killed = [];
check('cancel reports it signalled', diag_cancel($jd, $kill) === true);
// NEGATIVE. setsid puts the engine in its own process group and the engine
// spawns sg_verify/sg_read/smartctl children; signalling the parent alone
// leaves an sg_verify holding the disk with nothing left to reap it.
check('cancel signals the whole process GROUP', $killed === [-12345]);

file_put_contents("$jd/pgid", "not a number\n");
check('a corrupt pgid file is no pgid', diag_pgid($jd) === null);
// A pgid of 0 or 1 would mean "signal this process group" or init. Refuse.
file_put_contents("$jd/pgid", "0\n");
check('pgid 0 is refused', diag_pgid($jd) === null);
file_put_contents("$jd/pgid", "1\n");
check('pgid 1 is refused', diag_pgid($jd) === null);

/* ── the SSE slice: resume from a byte offset ──────────────────────────── */
$ev = "$jd/events.ndjson";
file_put_contents($ev, "{\"t\":\"phase\"}\n{\"t\":\"chunk\"}\n");
$s = diag_slice($ev, 0, 4096);
check('from zero the slice is the whole file', $s['bytes'] === "{\"t\":\"phase\"}\n{\"t\":\"chunk\"}\n");
check('and the offset advances to the end',    $s['offset'] === filesize($ev));
check('and it reports eof',                    $s['eof'] === true);

$s2 = diag_slice($ev, 14, 4096);
check('resuming mid-file returns only what is new', $s2['bytes'] === "{\"t\":\"chunk\"}\n");

// A slice that stops mid-line hands the browser half a JSON object, and the
// client cannot tell a truncated line from a malformed one. The offset must
// come back at the last NEWLINE, so the partial line is re-read next time.
file_put_contents($ev, "{\"t\":\"phase\"}\n{\"t\":\"par");
$s3 = diag_slice($ev, 0, 4096);
check('a partial trailing line is withheld', $s3['bytes'] === "{\"t\":\"phase\"}\n");
check('and the offset stops at the newline',  $s3['offset'] === 14);
check('and it is not eof',                    $s3['eof'] === false);

// An offset past the end is a client that reconnected to a file which was
// trimmed or replaced. Restart it rather than returning garbage or failing.
$s4 = diag_slice($ev, 999999, 4096);
check('an offset past the end restarts from zero', $s4['offset'] === 14 && $s4['bytes'] !== '');
check('a negative offset is clamped to zero',      diag_slice($ev, -5, 4096)['offset'] === 14);
check('a missing file is empty, not an error',
      diag_slice("$jd/nope.ndjson", 0, 4096) === ['bytes' => '', 'offset' => 0, 'eof' => true]);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/diagnose_test.php`
Expected: a fatal — `Call to undefined function diag_lock_path()`.

- [ ] **Step 3: Implement**

Append to `diagnose.php`, **above** any dispatch guard (there is none yet):

```php
/* ONE LOCK PER DISK, not per job. The Tier 1 gate is "one job per disk at a
   time", and a lock named for the job could never express that -- two jobs on
   one disk would take two different locks and both win. */
function diag_lock_path(string $disk, string $root = DIAG_ROOT): string {
    return $root . '/' . $disk . '.lock';
}

/* Claim the single-flight lock ATOMICALLY. 'x' fails when the file already
   exists, so of two concurrent requests exactly one can win -- unlike
   is_file()-then-touch(), which lets both pass the gate and launch a job at
   the same disk. Returns true if THIS caller now owns it, in which case it
   must release it on any later refusal. */
function diag_claim_lock(string $lock): bool {
    @mkdir(dirname($lock), 0755, true);
    $fh = @fopen($lock, 'x');
    if ($fh === false) return false;
    fclose($fh);
    return true;
}

/* Pure preflight for a diagnose request. Returns [ok=>bool, error=>string].
   The handler injects real values; tests inject fakes.
   EVERY gate fails closed on a missing input. flash_preflight's 'card' gate
   defaulted to allow once and was the most dangerous gate in the plugin; this
   path writes nothing, but "absent means refused" is cheaper to keep true
   everywhere than to re-reason about per gate. */
function diag_preflight(array $in): array {
    if (!array_key_exists('disk', $in) || !diag_disk_valid((string) $in['disk']))
        return ['ok' => false, 'error' => 'Invalid disk name.'];
    if (!array_key_exists('exists', $in) || empty($in['exists']))
        return ['ok' => false, 'error' => 'No block device /dev/' . $in['disk']
                                        . ' — it may have been pulled or renamed. Reload the Drives tab.'];
    /* Tier 1 gate from the spec's risk table. Reading every block of a disk
       that parity is currently reconstructing competes with the rebuild for
       the same spindle and the same link, and slows the window in which the
       array has no redundancy. Fails closed on an unreadable array state for
       the same reason flash_array_stopped() does. */
    if (!array_key_exists('resync', $in) || (int) $in['resync'] !== 0)
        return ['ok' => false, 'error' => 'A parity check or rebuild is running. Diagnose competes with it for the same disk — wait for it to finish.'];
    if (!array_key_exists('locked', $in) || !empty($in['locked']))
        return ['ok' => false, 'error' => 'A Diagnose job is already running on this disk.'];
    return ['ok' => true, 'error' => ''];
}

/* The process-group id the launcher recorded. Null on anything unusable.
   0 and 1 are refused explicitly: kill(-0) signals the CALLER's own process
   group -- the php-fpm pool -- and kill(-1) signals every process the user can
   reach. Both are catastrophic and both are what a truncated or half-written
   pgid file most easily produces. */
function diag_pgid(string $dir): ?int {
    $raw = @file_get_contents("$dir/pgid");
    if ($raw === false) return null;
    $raw = trim((string) $raw);
    if (!preg_match('/^\d+\z/', $raw)) return null;
    $pgid = (int) $raw;
    return $pgid > 1 ? $pgid : null;
}

/* Cancel = signal the whole PROCESS GROUP, negative pid. The engine is
   launched under setsid so it leads its own group, and it spawns
   sg_verify/sg_read/smartctl children that hold the disk open. Signalling the
   parent alone leaves an sg_verify running with nothing left to reap it, and
   the lock released under a job that is still reading. */
function diag_cancel(string $dir, callable $kill): bool {
    $pgid = diag_pgid($dir);
    if ($pgid === null) return false;
    $kill(-$pgid);
    return true;
}

/* Read the event file from a byte offset. The file is the source of truth, not
   anything held in the PHP worker -- the same rule cached_read() follows, so a
   reconnecting browser resumes instead of restarting.
   NEVER returns a partial trailing line: the client splits on newlines and
   cannot tell a truncated object from a malformed one, so the returned offset
   stops at the last newline and the partial line is re-read next time. */
function diag_slice(string $file, int $offset, int $maxBytes): array {
    if (!is_file($file)) return ['bytes' => '', 'offset' => 0, 'eof' => true];
    $size = (int) filesize($file);
    /* An offset past the end is a client reconnecting to a file that was
       trimmed or replaced under it. Restart from zero: a fresh full read is
       correct and cheap, and returning nothing would leave the view frozen. */
    if ($offset < 0 || $offset > $size) $offset = 0;
    $fh = @fopen($file, 'rb');
    if ($fh === false) return ['bytes' => '', 'offset' => $offset, 'eof' => true];
    fseek($fh, $offset);
    $buf = (string) fread($fh, max(0, $maxBytes));
    fclose($fh);
    $nl = strrpos($buf, "\n");
    if ($nl === false) return ['bytes' => '', 'offset' => $offset, 'eof' => false];
    $buf = substr($buf, 0, $nl + 1);
    $next = $offset + strlen($buf);
    return ['bytes' => $buf, 'offset' => $next, 'eof' => $next >= $size];
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/diagnose_test.php`
Expected: `diagnose: all pass`.

- [ ] **Step 5: Run the full suite and commit**

Run: `bash tests/run.sh`
Expected: `--- all pass ---`.

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/diagnose.php tests/diagnose_test.php
git commit -m "Add the diagnose job runner's pure guards: a per-disk atomic lock, a fail-closed preflight, process-group cancel, and a byte-offset event slice that never hands out a partial line."
```

---

### Task 10: The dispatch — `setsid` launch, status, cancel

The HTTP half. Four actions, all below the guard.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/diagnose.php`
- Create: `tests/diagnose_php_test.php`
- Modify: `tests/run_php.sh`
- Modify: `docs/foreground-reads.md`, `ARCHITECTURE.md`

**Interfaces:**
- Consumes: everything from Tasks 5 and 9, plus `scripts/drive_triage.sh --events` from Task 2.
- Produces the HTTP contract the JS in Tasks 13–15 calls:
  - `POST diagnose.php action=start&disk=sdb` → `{"ok":true,"job":"sdb-1700000000"}` or `{"error":"…"}`.
  - `GET  diagnose.php?action=status&job=<id>` → `{"running":bool,"exit":int|null,"done":"success"|"error"|null,"disk":"sdb"}`.
  - `POST diagnose.php action=cancel&job=<id>` → `{"ok":bool}`.
  - `GET  diagnose.php?action=list` → `{"jobs":[{"job":"sdb-17","disk":"sdb","running":false,"mtime":17}]}`, newest first.

- [ ] **Step 1: Write the failing test**

Create `tests/diagnose_php_test.php`. This is a **source** test: it pins the properties of the dispatch that no in-process call can reach, the way `flash_php_test.php` does for the flash path.

```php
<?PHP
/* Source assertions for diagnose.php's dispatch. The dispatch shells out and
   sets headers, so it cannot be called in-process -- but four of its
   properties are exactly the kind that regress silently, and prose does not
   hold them.
     php tests/diagnose_php_test.php  ->  "diagnose_php: all pass" (exit 0) */

$src = (string) file_get_contents(
    __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/diagnose.php');
// Comments are stripped before matching: this file argues about setsid and
// about kill -PGID in its own prose, and a comment naming a flag must not be
// able to satisfy an assertion about the code using it.
$code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src);

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

check('the CLI guard exists, so the test runner gets the functions',
      str_contains($code, "if (PHP_SAPI === 'cli') return;"));

// Every const the CLI runner reaches must be ABOVE the guard. Same rule
// ajax_render_test.php pins for ajax_info.php; a const beside its callers
// blanked the SMART tab once.
$guardAt = strpos($code, "if (PHP_SAPI === 'cli') return;");
foreach (['DIAG_ROOT', 'DIAG_SCRIPTS', 'DIAG_SSE_MAX_SECS'] as $c) {
    $at = strpos($code, "const $c");
    check("const $c is declared above the dispatch guard",
          $at !== false && $guardAt !== false && $at < $guardAt);
}

// setsid, not bare nohup. nohup detaches from the terminal but leaves the job
// in the caller's process group, so there is no group of its own to signal and
// cancel degrades to killing the shell while sg_verify keeps reading.
check('the job is launched under setsid', str_contains($code, 'setsid'));
check('the launcher records the job process group',
      str_contains($code, 'pgid'));

// The cancel path must reach diag_cancel(), which is where the NEGATIVE pid
// lives. A dispatch that called posix_kill($pid, …) directly would pass every
// other assertion here.
check('cancel goes through diag_cancel()', str_contains($code, 'diag_cancel('));
check('the dispatch does not kill a bare pid',
      !preg_match('/kill\s+\'?\s*\.\s*\$pid\b/', $code));

// The engine is invoked with --events, or the whole live view has nothing to
// read, and with the disk as an explicit /dev path.
check('the engine is invoked with --events', str_contains($code, '--events'));
check('every engine argument is escaped',
      substr_count($code, 'escapeshellarg') >= 4);

// Read-only phase: nothing here may reach the Tier 2/3 tools, whether by
// accident or by a later edit that thought it was helping.
foreach (['sg_reassign', 'write-sector', 'badblocks', 'sg_format', 'sg_sanitize'] as $t) {
    check("the dispatch never invokes $t", !str_contains($code, $t));
}

// Job artifacts are RAM. /boot is flash and a triage run is worthless after a
// reboot; writing runs there would wear the stick for nothing.
check('nothing under /boot is written', !str_contains($code, '/boot'));

echo $fails === 0 ? "diagnose_php: all pass\n" : "diagnose_php: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/diagnose_php_test.php`
Expected: FAIL on `the CLI guard exists`, the three const-position checks, `the job is launched under setsid`, and `cancel goes through diag_cancel()` — the dispatch does not exist yet.

- [ ] **Step 3: Implement**

Add to `diagnose.php`, with the const beside the others **above** the guard:

```php
/* How long one SSE connection is allowed to hold a php-fpm worker before it
   closes and the browser reconnects. See diagnose_stream.php. */
const DIAG_SSE_MAX_SECS = 55;

/* Is the array mid-parity-op? mdResync is nonzero during a check or rebuild.
   Fails closed the way flash_array_stopped() does: an unreadable state is a
   refusal, not a pass. */
function diag_resync(string $varini = '/var/local/emhttp/var.ini'): int {
    if (!is_file($varini)) return 1;
    $ini = @parse_ini_file($varini);
    if (!is_array($ini)) return 1;
    return (int) ($ini['mdResync'] ?? 1);
}
```

Then append the dispatch:

```php
/* ── HTTP dispatch (served only; skipped under the CLI test runner) ────────── */
if (PHP_SAPI === 'cli') return;

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
/* CSRF is enforced by Unraid's platform layer; a token-less POST never reaches
   here. A plugin-side check was added once, denied every settings save, and is
   marked do-not-re-attempt. */
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$cfg    = lsi_config_read();
@mkdir(DIAG_ROOT, 0755, true);

/* A job id is "<disk>-<digits>". Validated on the way IN, because it becomes a
   directory name and the disk half of it becomes a kill target. */
function diag_job_valid(string $jobId): bool {
    return (bool) preg_match('/^[a-z0-9]{2,32}-\d{1,20}\z/', $jobId);
}
function diag_job_disk(string $jobId): string {
    return substr($jobId, 0, (int) strrpos($jobId, '-'));
}

if ($action === 'start') {
    $disk = (string) ($_POST['disk'] ?? '');
    $lock = diag_lock_path($disk, DIAG_ROOT);

    /* Claim single-flight BEFORE the gate, so the check and the claim cannot be
       interleaved by a second request. Any refusal below hands the lock back.
       Unlike flash.php there is no expensive hardware read to keep outside the
       claim window -- every input here is a stat or a small file read. */
    $owned = diag_disk_valid($disk) && diag_claim_lock($lock);

    $pf = diag_preflight([
        'disk'   => $disk,
        'exists' => diag_disk_valid($disk) && is_file("/sys/block/$disk/dev"),
        'resync' => diag_resync(),
        'locked' => !$owned,
    ]);
    if (!$pf['ok']) {
        if ($owned) @unlink($lock);
        echo json_encode(['error' => $pf['error']]);
        exit;
    }

    $now = time();
    $job = diag_job_id($disk, $now);
    $dir = diag_job_dir($job, DIAG_ROOT);
    @mkdir($dir, 0755, true);

    /* Trim BEFORE the new run, not after: trimming after would have to exclude
       the run it just made, and the count the user set would be off by one in
       whichever direction the next reader assumed. */
    diag_trim_runs($disk, (int) lsi_clamp('DIAG_KEEP_RUNS', $cfg['DIAG_KEEP_RUNS']), DIAG_ROOT);

    /* setsid, not a bare nohup. nohup detaches from the terminal but leaves the
       job in the CALLER's process group -- there would be no group of its own
       to signal, and Cancel would kill the wrapper shell while sg_verify kept
       reading the disk. setsid makes the engine a group leader, and the
       launcher records that group so diag_cancel() can signal all of it.
       $$ inside the setsid'd shell IS the new group id, because setsid makes
       that shell the leader. */
    $cmd = 'bash ' . escapeshellarg(DIAG_SCRIPTS . '/drive_triage.sh')
         . ' --out ' . escapeshellarg($dir)
         . ' --events ' . escapeshellarg("$dir/events.ndjson")
         . ' --auto-triage --all'
         . ' ' . escapeshellarg("/dev/$disk");
    $inner = 'echo $$ > ' . escapeshellarg("$dir/pgid") . '; '
           . $cmd . ' > ' . escapeshellarg("$dir/job.log") . ' 2>&1; '
           . 'echo $? > ' . escapeshellarg("$dir/status") . '; '
           . 'rm -f ' . escapeshellarg($lock);
    shell_exec('setsid sh -c ' . escapeshellarg($inner) . ' >/dev/null 2>&1 &');

    echo json_encode(['ok' => true, 'job' => $job, 'disk' => $disk]);
    exit;
}

if ($action === 'status') {
    $job = (string) ($_GET['job'] ?? $_POST['job'] ?? '');
    if (!diag_job_valid($job)) { echo json_encode(['error' => 'Invalid job.']); exit; }
    $disk = diag_job_disk($job);
    $dir  = diag_job_dir($job, DIAG_ROOT);
    $stf  = "$dir/status";
    $running = is_file(diag_lock_path($disk, DIAG_ROOT));
    $exit    = is_file($stf) ? (int) trim((string) @file_get_contents($stf)) : null;
    $res = ['running' => $running, 'disk' => $disk,
            'exit' => $running ? null : $exit, 'done' => null];
    if (!$running && $exit === 0)        $res['done'] = 'success';
    elseif (!$running && $exit !== null) $res['done'] = 'error';
    echo json_encode($res);
    exit;
}

if ($action === 'cancel') {
    $job = (string) ($_POST['job'] ?? '');
    if (!diag_job_valid($job)) { echo json_encode(['ok' => false, 'error' => 'Invalid job.']); exit; }
    $dir  = diag_job_dir($job, DIAG_ROOT);
    $disk = diag_job_disk($job);
    /* Signal the GROUP. posix_kill is not guaranteed present in Unraid's PHP
       build, so this goes through /bin/kill, which takes the negative pid the
       same way. diag_cancel() is what puts the minus sign there -- it is not
       spelled at this call site on purpose, so one place owns it. */
    $sent = diag_cancel($dir, function (int $target): void {
        shell_exec('kill ' . escapeshellarg((string) $target) . ' 2>/dev/null');
    });
    /* Release the lock even when no pgid was found: a job directory with no
       pgid is a launch that died before recording one, and leaving the lock
       would refuse every later job on that disk until reboot -- the orphaned
       lock flash.php's ordering comment exists to avoid. */
    @unlink(diag_lock_path($disk, DIAG_ROOT));
    echo json_encode(['ok' => $sent]);
    exit;
}

if ($action === 'list') {
    $jobs = [];
    foreach (glob(DIAG_ROOT . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        $job = basename($d);
        if (!diag_job_valid($job)) continue;
        $disk = diag_job_disk($job);
        $jobs[] = ['job' => $job, 'disk' => $disk, 'mtime' => (int) @filemtime($d),
                   'running' => is_file(diag_lock_path($disk, DIAG_ROOT))];
    }
    usort($jobs, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    echo json_encode(['jobs' => $jobs]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action.']);
```

- [ ] **Step 4: Run both PHP tests to verify they pass**

Run: `php tests/diagnose_php_test.php && php tests/diagnose_test.php`
Expected: `diagnose_php: all pass` then `diagnose: all pass`. The second must still pass — the dispatch is below the guard, so requiring the file under the CLI runner reaches none of it.

- [ ] **Step 5: Document the new call sites**

In `docs/foreground-reads.md`, add to **The plugin's own endpoints — block their own request**:

```
| `diagnose.php` `start` | `setsid` launch of `drive_triage.sh` — detached, returns immediately. The read itself never happens in the request. |
| `diagnose.php` `cancel` | `/bin/kill` on a process group. Signals and returns; no wait. |
```

and to the **Detached** table:

```
| `diagnose.php` (start) | `setsid sh -c … &` — the triage job. Must outlive the request AND own its own process group, so Cancel has something to signal. |
```

In `ARCHITECTURE.md`, add two rows to the endpoint table after `flash.php`:

```
| `diagnose.php` | The read-only disk-diagnose job runner: `start` / `status` / `cancel` / `list`. Launches `scripts/drive_triage.sh --events` under `setsid` into `/tmp/hbaviewer/jobs/<job-id>/`, one lock per disk, cancel by process group. Not a mutating path — every operation it starts is a read. |
| `diagnose_stream.php` | Server-Sent Events over one job's event file, resumed by byte offset. Bounded to `DIAG_SSE_MAX_SECS` per connection so a stream cannot hold a php-fpm worker indefinitely. |
```

- [ ] **Step 6: Register, run the suite, commit**

Add `diagnose_php_test.php` to the `TESTS` list in `tests/run_php.sh`, beside `diagnose_test.php`.

Run: `bash tests/run.sh`
Expected: `--- all pass ---`.

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/diagnose.php tests/diagnose_php_test.php \
        tests/run_php.sh docs/foreground-reads.md ARCHITECTURE.md
git commit -m "Add the diagnose dispatch: setsid launch so the job owns a process group, status, cancel by group, and a job list. Source assertions pin setsid, the const positions and the absence of every Tier 2/3 tool."
```

---

### Task 11: The SSE endpoint, with a bounded window

The event file is the source of truth. The client reconnects with a byte offset, so reopening the tab mid-scan resumes rather than restarting — the same rule `cached_read()` follows: the foreground never blocks on the producer.

**The design decision the spec leaves open:** an SSE connection held for the length of a surface scan holds a php-fpm worker for hours, which is precisely the failure `docs/foreground-reads.md` was written after. So the stream is **bounded**: it ends after `DIAG_SSE_MAX_SECS`, and `EventSource` reconnects automatically carrying `Last-Event-ID` — which is the byte offset. The resume mechanism the spec asks for is what makes the bound free.

**Files:**
- Create: `source/usr/local/emhttp/plugins/hbaviewer/diagnose_stream.php`
- Modify: `tests/diagnose_php_test.php`

**Interfaces:**
- Consumes: `diag_slice()`, `diag_job_valid()`, `DIAG_SSE_MAX_SECS`, `diag_lock_path()`.
- Produces: `GET diagnose_stream.php?job=<id>&offset=<bytes>` — `text/event-stream`. Each frame is `id: <new byte offset>` then one `data:` line per event line, then a blank line. A final `event: end` frame is sent when the job is over. `Last-Event-ID` overrides `offset` when present, because that is what the browser sends on an automatic reconnect.

- [ ] **Step 1: Write the failing test**

Append to `tests/diagnose_php_test.php`, before the final `echo`:

```php
/* ── the SSE endpoint ──────────────────────────────────────────────────── */
$ssrc = (string) file_get_contents(
    __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/diagnose_stream.php');
$scode = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $ssrc);

check('the stream declares the SSE content type',
      str_contains($scode, 'text/event-stream'));
// Every frame carries its byte offset as the SSE id, because that id is what
// the browser sends back as Last-Event-ID on an automatic reconnect. Without
// it the resume depends on the client remembering, and a reload forgets.
check('every frame carries the offset as its SSE id', str_contains($scode, 'id: '));
check('a reconnect is honoured via Last-Event-ID',
      str_contains($scode, 'HTTP_LAST_EVENT_ID'));
check('the stream reads through diag_slice()', str_contains($scode, 'diag_slice('));

// BOUNDED. An unbounded stream holds a php-fpm worker for the length of a
// surface scan -- hours -- which is the exact shape of the incident
// docs/foreground-reads.md was written after. EventSource reconnects by
// itself, so ending the response costs the client nothing.
check('the stream is bounded by DIAG_SSE_MAX_SECS',
      str_contains($scode, 'DIAG_SSE_MAX_SECS'));
check('and the bound is actually compared against elapsed time',
      preg_match('/DIAG_SSE_MAX_SECS/', $scode)
      && preg_match('/(time\(\)|microtime)/', $scode));

// A stream that never yields to the poll interval spins a core. A stream that
// ignores a disconnected client keeps doing it after the tab closed.
check('the loop sleeps between polls',       preg_match('/usleep|sleep\(/', $scode));
check('a disconnected client ends the loop', str_contains($scode, 'connection_aborted'));
check('the job id is validated before use',  str_contains($scode, 'diag_job_valid('));
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/diagnose_php_test.php`
Expected: a warning and failures — `file_get_contents(...diagnose_stream.php): Failed to open stream`, then every new check FAILs.

- [ ] **Step 3: Split the pure half out into `diagnose_lib.php`**

`diagnose_stream.php` needs `diag_slice()` and three other helpers, and it **cannot** `require diagnose.php` to get them: under a web SAPI that file's dispatch executes on require and answers the request with a 400 before a byte of the stream is written. So the pure half moves into a file that has no dispatch at all and both endpoints require that.

1. Create `source/usr/local/emhttp/plugins/hbaviewer/diagnose_lib.php` with a `<?PHP` opener, this header, and then — **moved unchanged from `diagnose.php`** — the consts `DIAG_ROOT`, `DIAG_SCRIPTS`, `DIAG_SSE_MAX_SECS` and the functions `diag_disk_valid`, `diag_job_id`, `diag_job_dir`, `diag_trim_runs`, `diag_lock_path`, `diag_claim_lock`, `diag_preflight`, `diag_pgid`, `diag_cancel`, `diag_slice`, `diag_resync`, `diag_job_valid`, `diag_job_disk`:

```php
<?PHP
/* HBAviewer diagnose — the pure half, shared by diagnose.php (the job
 * endpoint) and diagnose_stream.php (the SSE stream).
 *
 * NO DISPATCH LIVES HERE, and that is the point. diagnose.php's dispatch
 * executes on require under a web SAPI, so an endpoint that required it to
 * borrow one helper would answer its own request with a 400 before writing a
 * byte. A file with nothing but declarations is safe to require from anywhere,
 * including the CLI test runner.
 */
```

2. In `diagnose.php`, delete those definitions and put `require_once __DIR__ . '/diagnose_lib.php';` at the very top, above its own guard. The guard and the dispatch stay exactly as Task 10 left them.
3. In `tests/diagnose_test.php`, change the `require_once` target to `diagnose_lib.php`. Nothing else in that file changes.
4. Write `diagnose_stream.php`:

```php
<?PHP
/* HBAviewer diagnose event stream — Server-Sent Events over one job's event
 * file.
 *
 * THE FILE IS THE SOURCE OF TRUTH, not anything held in this worker. A
 * reconnecting browser hands back the byte offset it reached and picks up from
 * there, so reopening the tab mid-scan resumes the live view instead of
 * restarting it — cached_read()'s rule in a different shape.
 *
 * WHY THE STREAM IS BOUNDED. A surface scan runs for hours, and an SSE
 * response held open for hours holds a php-fpm worker for hours: the exact
 * shape of the incident docs/foreground-reads.md was written after. The
 * response ends after DIAG_SSE_MAX_SECS and EventSource reconnects on its own,
 * carrying Last-Event-ID. The resume the design needs anyway is what makes the
 * bound free.
 *
 * diagnose_lib.php, not diagnose.php: that file's dispatch executes on require
 * under a web SAPI and would answer this request with a 400 before a byte of
 * the stream was written.
 */

require_once __DIR__ . '/diagnose_lib.php';

const DIAG_SSE_POLL_US = 400000;   // 0.4s: live enough for a chunk event,
                                   // slow enough that an idle stream is not a
                                   // spinning core.
const DIAG_SSE_CHUNK   = 65536;

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
/* nginx buffers a proxied response by default, which holds every frame until
   the buffer fills -- a "live" view that arrives in one lump at the end. */
header('X-Accel-Buffering: no');

$job = (string) ($_GET['job'] ?? '');
if (!diag_job_valid($job)) { echo "event: error\ndata: invalid job\n\n"; exit; }

$dir  = diag_job_dir($job, DIAG_ROOT);
$disk = diag_job_disk($job);
$file = "$dir/events.ndjson";

/* Last-Event-ID is what the browser sends on an AUTOMATIC reconnect, and it
   wins over the query string: the query string is what the page opened with,
   which after a reconnect is stale by definition. */
$offset = (int) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? $_GET['offset'] ?? 0);

@set_time_limit(0);
while (ob_get_level() > 0) ob_end_flush();

$start = time();
while (true) {
    $s = diag_slice($file, $offset, DIAG_SSE_CHUNK);
    if ($s['bytes'] !== '') {
        $offset = $s['offset'];
        /* The id is the offset. That is the entire resume mechanism: the
           browser stores it and hands it back as Last-Event-ID, so the server
           holds no per-client state at all. */
        echo 'id: ' . $offset . "\n";
        foreach (explode("\n", rtrim($s['bytes'], "\n")) as $line) {
            echo 'data: ' . $line . "\n";
        }
        echo "\n";
        flush();
    }

    /* The job is over when its lock is gone AND the slice reached the end.
       Both, in that order: the engine can release the lock a moment before the
       last line is flushed to disk, and ending on the lock alone truncates the
       verdict off the live view. */
    $running = is_file(diag_lock_path($disk, DIAG_ROOT));
    if (!$running && $s['eof']) {
        echo "event: end\ndata: " . $offset . "\n\n";
        flush();
        exit;
    }

    if (connection_aborted()) exit;
    /* Bounded: end the response and let EventSource come back. The client sees
       a reconnect, not an end -- only `event: end` means the job finished. */
    if (time() - $start >= DIAG_SSE_MAX_SECS) exit;
    usleep(DIAG_SSE_POLL_US);
}
```

- [ ] **Step 4: Update the moved-function assertions**

In `tests/diagnose_php_test.php`, replace the three const-position checks with:

```php
$lib = (string) file_get_contents(
    __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/diagnose_lib.php');
// The pure half is its own file with NO dispatch, so both endpoints and the
// test runner can require it without side effects. diagnose.php's dispatch
// executing on require is what made diagnose_stream.php answer 400 before it
// wrote a byte.
check('the shared library has no dispatch guard, because it has no dispatch',
      !str_contains($lib, "PHP_SAPI"));
foreach (['DIAG_ROOT', 'DIAG_SCRIPTS', 'DIAG_SSE_MAX_SECS'] as $c) {
    check("const $c lives in the dispatch-free library", str_contains($lib, "const $c"));
}
$reqAt   = strpos($code, "require_once __DIR__ . '/diagnose_lib.php'");
check('diagnose.php requires the library above its dispatch guard',
      $reqAt !== false && $guardAt !== false && $reqAt < $guardAt);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php tests/diagnose_test.php && php tests/diagnose_php_test.php`
Expected: `diagnose: all pass` then `diagnose_php: all pass`.

Run: `php -l source/usr/local/emhttp/plugins/hbaviewer/diagnose_stream.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Run the full suite and commit**

Run: `bash tests/run.sh`
Expected: `--- all pass ---`.

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/diagnose_lib.php \
        source/usr/local/emhttp/plugins/hbaviewer/diagnose.php \
        source/usr/local/emhttp/plugins/hbaviewer/diagnose_stream.php \
        tests/diagnose_test.php tests/diagnose_php_test.php
git commit -m "Split the diagnose pure half into diagnose_lib.php and add the SSE endpoint over it. The stream is bounded to 55s per connection and resumes from the byte offset it hands back as the SSE id, so it cannot pin a php-fpm worker for the length of a surface scan."
```

---

### Task 12: The Diagnose tab and the Live Job markup

One new tab in the existing Monitor, holding both screens as two sibling panes toggled by JS. Not a new `.page`: the spec says these screens are added to the HBA Monitor, and a second `.page` would mean a second `Menu=` decision, a second CSS link and a second array-state read for two views that share all of their data.

Every element listed in the spec's Live Job section appears here as an empty container with a stable id; Task 13 fills them.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php`
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/chrome.css`
- Modify: `tests/view_test.php`

**Interfaces:**
- Produces the DOM contract Tasks 13–15 bind to. Ids, exactly these:
  `diag-live`, `diag-verdict` (the two sibling screens);
  `diag-head` (header strip), `diag-dot` (live status dot), `diag-pause`, `diag-cancel`;
  `diag-pills` (phase pills), `diag-progress` (percent / LBA / throughput / elapsed / remaining);
  `diag-hotzone`, `diag-map` (surface map), `diag-hist` (chunk latency histogram);
  `diag-counters` (counter deltas panel), `diag-interp` (the one-line running interpretation);
  `diag-stream` (event stream);
  `diag-newjob` (new-job sidebar panel), `diag-standby` (the "leave standby drives asleep" checkbox, **checked by default**), `diag-drives` (drive-list sidebar).
  Also the global `var luDiagJob = '';` in `hbaviewer.php`'s inline `<script>`.

- [ ] **Step 1: Write the failing test**

Append to `tests/view_test.php`, before its final tally:

```php
/* ── the Diagnose tab (plan 2026-09-21) ────────────────────────────────── */
$hb = (string) file_get_contents(
    __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php');

check('the Diagnose tab button exists',  str_contains($hb, "luTab('diagnose')"));
check('its pane exists',                 str_contains($hb, 'id="tab-diagnose"'));
check('the tab button is a real tab, not a link',
      str_contains($hb, 'id="tabbtn-diagnose" aria-controls="tab-diagnose"'));

// Every container the JS writes into. A typo here renders a page that looks
// perfectly normal and does nothing at all -- the same failure mode
// view_test.php's <script src> check exists for.
foreach (['diag-live', 'diag-verdict', 'diag-head', 'diag-dot', 'diag-pause',
          'diag-cancel', 'diag-pills', 'diag-progress', 'diag-hotzone',
          'diag-map', 'diag-hist', 'diag-counters', 'diag-interp',
          'diag-stream', 'diag-newjob', 'diag-standby', 'diag-drives'] as $id) {
    check("the Live Job markup carries #$id", str_contains($hb, 'id="' . $id . '"'));
}

// Honoured BY DEFAULT, per the spec. A toggle that protects a sleeping disk
// and defaults off protects nothing.
check('leave standby drives asleep is checked by default',
      (bool) preg_match('/id="diag-standby"[^>]*\bchecked\b/', $hb));

// The inline <script> must declare it, above the <script src>: the static .js
// reads it as a global and there is no templating step. $csrfToken is read
// unconditionally already; this rides the same block.
check('the job global is declared in the inline block',
      str_contains($hb, 'var luDiagJob'));
$inlineAt = strpos($hb, 'var luDiagJob');
$srcAt    = strpos($hb, 'src="/plugins/hbaviewer/diagnose_view.js');
check('the inline block sits above the diagnose script tag',
      $inlineAt !== false && $srcAt !== false && $inlineAt < $srcAt);

// The CSS classes the JS applies must exist, or the surface map renders as a
// column of unstyled divs and the latency buckets carry no meaning at all.
$css = (string) file_get_contents(
    __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/chrome.css');
foreach (['lu-diag-map', 'lu-diag-cell', 'lu-b5', 'lu-b20', 'lu-b50',
          'lu-b150', 'lu-b500', 'lu-b500p', 'lu-bbad', 'lu-diag-hist',
          'lu-diag-pill'] as $cls) {
    check("chrome.css defines .$cls", str_contains($css, '.' . $cls));
}
// This plugin is a guest inside the Dynamix webGui: it inherits the user's
// theme through tokens.css, ships no fonts and adds no framework. A hard-coded
// hex in a new rule is a colour no theme can reach.
$diagCss = (string) (strstr($css, '/* Diagnose') ?: '');
check('the new rules use theme variables, not literal hex',
      $diagCss !== '' && !preg_match('/:\s*#[0-9a-fA-F]{3,8}\s*;/', $diagCss));
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/view_test.php`
Expected: FAIL on `the Diagnose tab button exists` and every check after it.

- [ ] **Step 3: Implement the tab and markup**

In `hbaviewer.php`, add the tab button immediately after the SMART button (so it sits beside the other per-drive views, before Event Log):

```php
  <?php /* Diagnose: the read-only media-vs-transport triage. A TAB and not a
           page, unlike Firmware -- there is nothing dangerous to gate behind a
           danger notice here, every operation it starts is a read, and it
           shares the Drives payload the strip already loads. */ ?>
  <button class="lu-tab-btn" type="button" role="tab" id="tabbtn-diagnose" aria-controls="tab-diagnose" aria-selected="false" tabindex="-1" data-tab="diagnose" onclick="luTab('diagnose')">Diagnose</button>
```

Add the pane after the SMART pane:

```php
<!-- ── Diagnose tab: two screens, Live Job and Verdict ─────────────────────
     Both live in one pane and are toggled by luDiagShow() rather than being
     two routes: they render from the same job and the same event file, and a
     verdict is a finished live view, not a different page. -->
<div id="tab-diagnose" class="lu-tab-pane" role="tabpanel" aria-labelledby="tabbtn-diagnose">

  <div id="diag-live" class="lu-diag-screen">
    <div class="lu-card first">
      <!-- Header strip: identity, dev path, model, host/phy, what the job is
           doing in plain language, the live dot, and the two controls. -->
      <div class="lu-tab-toolbar">
        <div id="diag-head"><span class="lu-muted">No job running. Pick a drive on the Drives tab and press Diagnose.</span></div>
        <span>
          <span id="diag-dot" class="lu-diag-dot" role="img" aria-label="No job running"></span>
          <button class="lu-refresh-btn" id="diag-pause"  type="button" onclick="luDiagPause()"  disabled>Pause</button>
          <button class="lu-refresh-btn" id="diag-cancel" type="button" onclick="luDiagCancel()" disabled>Cancel</button>
        </span>
      </div>
      <!-- Preflight -> Baseline snapshot -> Targeted VERIFY/READ -> Surface
           VERIFY -> SMART short test -> Verdict, each done/active/queued. -->
      <div id="diag-pills" class="lu-diag-pills"></div>
      <!-- percent, current LBA, throughput, elapsed, remaining -->
      <div id="diag-progress" class="lu-diag-progress"></div>
    </div>

    <div class="lu-card">
      <!-- A cluster of slow or failing chunks is named above the map with its
           LBA range: the map shows where, the callout says what to do with it. -->
      <div id="diag-hotzone" class="lu-diag-hotzone"></div>
      <div id="diag-map" class="lu-diag-map" role="img" aria-label="Surface scan map, one cell per scanned chunk, coloured by read latency"></div>
      <!-- Same buckets as the map, as bars: a forming weak region shows in the
           distribution before it is visible as a shape on the map. -->
      <div id="diag-hist" class="lu-diag-hist"></div>
    </div>

    <div class="lu-diag-cols">
      <div class="lu-card">
        <!-- grown defect list, uncorrected verify/read, running disparity,
             invalid DWORD, loss of DWORD sync -- media side vs path side. -->
        <div id="diag-counters"></div>
        <p id="diag-interp" class="lu-muted"></p>
      </div>
      <div class="lu-card">
        <div id="diag-stream" class="lu-diag-stream" role="log" aria-live="polite" aria-label="Diagnose event stream"></div>
      </div>
      <div class="lu-card">
        <div id="diag-newjob">
          <label for="diag-op">Queue a test</label>
          <select id="diag-op">
            <option value="targeted">Targeted VERIFY / READ</option>
            <option value="surface">Full-surface VERIFY</option>
            <option value="selftest">SMART short self-test</option>
          </select>
          <!-- Checked by default. HBAviewer's standing guarantee is that it
               never wakes a sleeping disk, so the protective setting is the
               one you have to turn OFF. -->
          <label><input type="checkbox" id="diag-standby" checked> Leave standby drives asleep</label>
        </div>
        <!-- Worst-first, with MEDIA / TRANSPORT / SCANNING / CLEAN / STANDBY
             badges; unassigned drives in their own group. -->
        <div id="diag-drives"></div>
      </div>
    </div>
  </div>

  <div id="diag-verdict" class="lu-diag-screen" hidden></div>
</div>
```

In the inline `<script>` block, beside `luTempUnit`:

```php
    /* The job the two Diagnose screens are showing. Declared HERE, in the
       inline block above the <script src>, because diagnose_view.js reads it
       as a global and there is no templating step -- the same load-bearing
       split luCsrf depends on. */
    var luDiagJob = '';
```

and the script tag after `hbaviewer.js`:

```php
<script src="/plugins/hbaviewer/diagnose_view.js?v=<?= (int) @filemtime(__DIR__ . '/diagnose_view.js') ?>"></script>
```

- [ ] **Step 4: Implement the CSS**

Append to `chrome.css`. Every colour comes from a theme variable; the plugin ships no fonts and adds no framework.

```css
/* Diagnose — the surface map, the latency histogram and the phase pills.
   Colour is the signal in the map, so the buckets are ordered light-to-dark
   through the existing warn/crit ramp rather than through a new palette.
   The map is also labelled with role=img and an aria-label, and every cell
   carries a title, because a grid of coloured squares is not readable to a
   screen reader and "the drive is fine" is not a thing to convey in hue only. */
.lu-diag-screen  { display: block; }
.lu-diag-cols    { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 10px; }
.lu-diag-pills   { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0; }
.lu-diag-pill    { font-size: 11px; padding: 2px 8px; border-radius: 10px;
                   border: 1px solid var(--border); color: var(--text); }
.lu-diag-pill.done   { opacity: .6; }
.lu-diag-pill.active { border-color: var(--warn-text); color: var(--warn-text); font-weight: 600; }
.lu-diag-pill.queued { opacity: .4; }
.lu-diag-progress    { font-variant-numeric: tabular-nums; font-size: 12px; }
.lu-diag-hotzone     { font-size: 12px; color: var(--warn-text); min-height: 1.2em; }
.lu-diag-map     { display: flex; flex-wrap: wrap; gap: 1px; }
.lu-diag-cell    { width: 8px; height: 8px; background: var(--border); }
.lu-b5    { background: var(--ok-bg); }
.lu-b20   { background: var(--ok-text); }
.lu-b50   { background: var(--warn-bg); }
.lu-b150  { background: var(--warn-text); }
.lu-b500  { background: var(--crit-bg); }
.lu-b500p { background: var(--crit-text); }
.lu-bbad  { background: var(--crit-text); outline: 1px solid var(--text); }
.lu-diag-hist    { display: flex; flex-direction: column; gap: 2px; margin-top: 8px;
                   font-size: 11px; font-variant-numeric: tabular-nums; }
.lu-diag-stream  { max-height: 320px; overflow-y: auto; font-family: monospace;
                   font-size: 11px; white-space: pre-wrap; }
.lu-diag-dot     { display: inline-block; width: 9px; height: 9px; border-radius: 50%;
                   background: var(--border); vertical-align: middle; margin-right: 6px; }
.lu-diag-dot.running { background: var(--warn-text); }
/* Reduced motion must preserve the signal, not remove it: the dot stops
   pulsing and stays the running colour, rather than becoming indistinguishable
   from the idle one. */
@media (prefers-reduced-motion: no-preference) {
    .lu-diag-dot.running { animation: lu-diag-pulse 1.6s ease-in-out infinite; }
}
@keyframes lu-diag-pulse { 50% { opacity: .35; } }
```

If any variable named above is not already defined in `tokens.css`, use the nearest one that is — check with `grep -n '^\s*--' source/usr/local/emhttp/plugins/hbaviewer/tokens.css` before writing the block, and do not add a new token: `design-system/MASTER.md` is derived from these files, and a token invented here would describe a UI that does not exist elsewhere.

- [ ] **Step 5: Create the JS file as a stub so the page is not broken**

`tests/view_test.php` checks every `<script src>` resolves to a file that ships. Create `source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js` with just:

```javascript
/* HBAviewer Diagnose screens — Live Job and Verdict. Filled in by the next
   task; this file exists now so the <script src> added to hbaviewer.php
   resolves (tests/view_test.php fails on one that does not). */
'use strict';
```

- [ ] **Step 6: Run the test, the suite, and commit**

Run: `php tests/view_test.php`
Expected: the new checks PASS and the existing ones are unchanged.

Run: `node --check source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js`
Expected: no output.

Run: `bash tests/run.sh`
Expected: `--- all pass ---`.

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php \
        source/usr/local/emhttp/plugins/hbaviewer/chrome.css \
        source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js tests/view_test.php
git commit -m "Add the Diagnose tab and the Live Job markup: header strip, phase pills, progress row, surface map, latency histogram, counter panel, event stream and the two sidebar panels. Colour comes from theme variables only, and the map carries a text label because hue is not readable."
```

---

### Task 13: Live Job behaviour — the SSE client

The file that turns the event stream into the screen. One IIFE, no modules, no build step, matching `flash_view.js`.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js`
- Create: `tests/diagnose_js_test.js`
- Modify: `tests/run.sh`

**Interfaces:**
- Consumes: the DOM ids from Task 12; `diagnose.php` `start`/`status`/`cancel`; `diagnose_stream.php`.
- Produces, on `window`:
  - `luDiagnose(dev)` — start a job for `/dev/<dev>`, switch to the tab, open the stream. Task 15 calls this.
  - `luDiagPause()`, `luDiagCancel()`, `luDiagShow(which)` — `which` is `'live'` or `'verdict'`.
  - `luDiagBucket(ms, ok)` → one of `lu-bbad`, `lu-b5`, `lu-b20`, `lu-b50`, `lu-b150`, `lu-b500`, `lu-b500p`. Exported for the test and reused by Task 14.
  - `luDiagHotZone(cells, minRun)` → `{from, to, n}` or `null` — the longest run of slow-or-failed chunks at least `minRun` long.
  - `luDiagInterp(deltas)` → the one-line running interpretation string.
  - `luDiagApply(ev)` — apply one decoded event object to the DOM. The test drives this directly.

- [ ] **Step 1: Write the failing test**

Create `tests/diagnose_js_test.js`, following `tests/flash_js_test.js`'s shape — `vm`, a hand-rolled DOM stub, no jsdom.

```javascript
/* Runtime checks for diagnose_view.js. Three things here cannot be asserted
 * from the source text, and all three are the ones that would be wrong
 * silently:
 *
 *   - the latency bucket boundaries. Off by one bucket and the surface map is
 *     a picture of a different disk; every source assertion still passes.
 *   - the hot-zone detector. A run detector that reports every single slow
 *     chunk as a zone makes the callout useless, and one that needs the whole
 *     map to be slow never fires.
 *   - the media-vs-path interpretation. It is the one sentence that tells the
 *     user whether to buy a drive or a cable.
 *
 *   node tests/diagnose_js_test.js   ->  "diagnose_js: all pass" (exit 0)
 */
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

let fails = 0;
const check = (name, ok) => { console.log((ok ? 'PASS  ' : 'FAIL  ') + name); if (!ok) fails++; };

const SRC = path.join(__dirname,
    '../source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js');

/* ── the smallest DOM this file can run against ─────────────────────────── */
const els = new Map();
function mkEl(id) {
    const el = { id, style: {}, textContent: '', value: '', checked: false,
                 hidden: false, disabled: false, _html: '', children: [],
                 classList: { _s: new Set(),
                              add(...c) { c.forEach(x => this._s.add(x)); },
                              remove(...c) { c.forEach(x => this._s.delete(x)); },
                              contains(c) { return this._s.has(c); } },
                 setAttribute() {}, scrollTo() {},
                 appendChild(c) { el.children.push(c); } };
    Object.defineProperty(el, 'innerHTML', {
        get() { return el._html; }, set(v) { el._html = String(v); el.children = []; },
    });
    return el;
}
const ids = ['diag-live','diag-verdict','diag-head','diag-dot','diag-pause','diag-cancel',
             'diag-pills','diag-progress','diag-hotzone','diag-map','diag-hist',
             'diag-counters','diag-interp','diag-stream','diag-newjob','diag-standby',
             'diag-drives'];
ids.forEach(i => els.set(i, mkEl(i)));

const fetches = [];
const sandbox = {
    console,
    window: {},
    luCsrf: 'TOKEN',
    luDiagJob: '',
    document: {
        getElementById: (id) => els.get(id) || null,
        createElement: (t) => mkEl('created-' + t),
        querySelectorAll: () => [],
        querySelector: () => null,
    },
    fetch: (url, opts) => {
        fetches.push({ url, body: opts && opts.body ? String(opts.body) : '' });
        return Promise.resolve({ json: () => Promise.resolve({ ok: true, job: 'sdb-1', disk: 'sdb' }) });
    },
    EventSource: function (url) { this.url = url; this.close = () => {}; },
    setTimeout: (fn) => fn && 0,
    luTab: () => {},
};
sandbox.window = sandbox;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(SRC, 'utf8'), sandbox);

/* ── latency buckets: the boundaries the spec names, exactly ────────────── */
const B = sandbox.luDiagBucket;
check('an unreadable chunk is its own bucket', B(12, false) === 'lu-bbad');
// Boundaries are EXCLUSIVE upper bounds -- "<5 ms", not "<=5 ms". 5 belongs to
// the next bucket up, and getting this backwards shifts the whole map.
check('4 ms is the fastest bucket',   B(4, true)   === 'lu-b5');
check('5 ms is not',                  B(5, true)   === 'lu-b20');
check('19 ms is still the 20 bucket', B(19, true)  === 'lu-b20');
check('20 ms moves up',               B(20, true)  === 'lu-b50');
check('49 ms is the 50 bucket',       B(49, true)  === 'lu-b50');
check('50 ms moves up',               B(50, true)  === 'lu-b150');
check('149 ms is the 150 bucket',     B(149, true) === 'lu-b150');
check('150 ms moves up',              B(150, true) === 'lu-b500');
check('499 ms is the 500 bucket',     B(499, true) === 'lu-b500');
check('500 ms is the slowest bucket', B(500, true) === 'lu-b500p');
check('a huge latency stays in the slowest bucket', B(99999, true) === 'lu-b500p');

/* ── hot zones: a CLUSTER, not a single slow chunk ──────────────────────── */
const HZ = sandbox.luDiagHotZone;
const cell = (lba, ms, ok) => ({ lba, ms, ok });
check('no slow chunks means no zone',
      HZ([cell(0,2,true), cell(64,3,true)], 3) === null);
// One slow chunk on a healthy disk is noise -- a seek, a queued write
// elsewhere, a background scan. Calling it a hot zone makes the callout
// something the user learns to ignore.
check('a single slow chunk is not a zone',
      HZ([cell(0,2,true), cell(64,900,true), cell(128,2,true)], 3) === null);
const z = HZ([cell(0,2,true), cell(64,900,true), cell(128,800,true),
              cell(192,700,true), cell(256,2,true)], 3);
check('three consecutive slow chunks are a zone', z !== null);
check('the zone starts at the first slow chunk',  z && z.from === 64);
check('and ends at the last',                     z && z.to === 192);
check('and reports how many',                     z && z.n === 3);
// A failed chunk counts toward a run even if it returned fast: an unreadable
// block is worse than a slow one, and a run detector keyed only on latency
// would split a zone in half around the one block that failed outright.
const z2 = HZ([cell(0,900,true), cell(64,1,false), cell(128,800,true)], 3);
check('a failed chunk counts toward the run', z2 !== null && z2.n === 3);

/* ── the interpretation sentence ────────────────────────────────────────── */
const I = sandbox.luDiagInterp;
check('media moved, path flat points at MEDIA',
      /MEDIA/.test(I({ grown: 2, uncorr: 1, disp: 0, invdw: 0, loss: 0 })));
check('path moved, media flat points at the path',
      /TRANSPORT|path|cable/i.test(I({ grown: 0, uncorr: 0, disp: 14, invdw: 9, loss: 0 })));
// Both moving is genuinely ambiguous and must read that way. A sentence that
// picks a side here sends someone to buy the wrong part.
check('both moving says so rather than guessing',
      /both/i.test(I({ grown: 2, uncorr: 0, disp: 14, invdw: 0, loss: 0 })));
check('nothing moving says nothing moved',
      /no counter|nothing/i.test(I({ grown: 0, uncorr: 0, disp: 0, invdw: 0, loss: 0 })));

/* ── applying events to the DOM ─────────────────────────────────────────── */
const A = sandbox.luDiagApply;
A({ t: 'phase', disk: 'sdb', phase: 'surface', lba_total: 1000 });
check('a phase event marks the pill active',
      els.get('diag-pills')._html.includes('active'));
A({ t: 'chunk', lba: 0, n: 64, op: 'verify', ms: 900, ok: true });
check('a chunk event adds a cell to the map',
      els.get('diag-map')._html.includes('lu-diag-cell')
      || els.get('diag-map').children.length > 0);
A({ t: 'counter', key: 'disp', before: 210, after: 214 });
check('a counter event renders the delta',
      els.get('diag-counters')._html.includes('214')
      || els.get('diag-counters')._html.includes('+4'));
A({ t: 'verdict', disk: 'sdb', v: 'TRANSPORT', why: 'verify clean, read failed x3' });
check('a verdict event switches to the verdict screen',
      els.get('diag-verdict').hidden === false && els.get('diag-live').hidden === true);

/* ── starting a job ─────────────────────────────────────────────────────── */
fetches.length = 0;
sandbox.luDiagnose('sdb');
check('starting a job posts to diagnose.php',
      fetches.length === 1 && fetches[0].url.includes('diagnose.php'));
check('and names the disk, not a /dev path',
      fetches[0].body.includes('disk=sdb') && !fetches[0].body.includes('%2Fdev'));
check('and sends Unraid\'s CSRF token',  fetches[0].body.includes('csrf_token=TOKEN'));

console.log();
if (fails === 0) { console.log('diagnose_js: all pass'); process.exit(0); }
console.log('diagnose_js: FAILURES'); process.exit(1);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `node tests/diagnose_js_test.js`
Expected: `TypeError: sandbox.luDiagBucket is not a function` — the stub file exports nothing.

- [ ] **Step 3: Implement**

Replace `diagnose_view.js` with the full implementation. The five pure functions come first, then the stream wiring:

```javascript
/* HBAviewer Diagnose screens — Live Job and Verdict.
 *
 * One IIFE, no modules, no build step, the same shape as flash_view.js. The
 * globals it reads (luCsrf, luDiagJob) are declared in hbaviewer.php's inline
 * <script> ABOVE this file's <script src>; that split is load-bearing and is
 * the whole reason there is no templating step here.
 *
 * The event file is the source of truth. This file holds a byte offset and
 * nothing else durable: reload the tab mid-scan and the stream resumes from
 * that offset rather than replaying or restarting.
 */
'use strict';
(function () {

    function fesc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function el(id) { return document.getElementById(id); }

    /* ── pure: the latency buckets ───────────────────────────────────────── */
    /* EXCLUSIVE upper bounds, matching how the spec writes them ("<5 ms").
       An unreadable chunk is not the slowest bucket -- it is a different fact,
       and folding it into ">=500 ms" would hide a failure inside a slowness. */
    var BUCKETS = [[5, 'lu-b5'], [20, 'lu-b20'], [50, 'lu-b50'],
                   [150, 'lu-b150'], [500, 'lu-b500']];
    window.luDiagBucket = function (ms, ok) {
        if (!ok) return 'lu-bbad';
        for (var i = 0; i < BUCKETS.length; i++) {
            if (ms < BUCKETS[i][0]) return BUCKETS[i][1];
        }
        return 'lu-b500p';
    };

    /* ── pure: hot zones ─────────────────────────────────────────────────── */
    /* The LONGEST run of consecutive slow-or-failed chunks, if it reaches
       minRun. A single slow chunk on a healthy disk is noise -- a seek, a
       queued write elsewhere, the drive's own background scan -- and reporting
       it as a zone is how a callout becomes something people scroll past.
       A FAILED chunk counts toward the run whatever its latency: an unreadable
       block is worse than a slow one, and keying the run on latency alone
       splits a zone around the one block that failed outright. */
    var SLOW_MS = 150;
    window.luDiagHotZone = function (cells, minRun) {
        var best = null, run = null, i, c, hot;
        for (i = 0; i < cells.length; i++) {
            c = cells[i];
            hot = (c.ok === false) || (c.ms >= SLOW_MS);
            if (hot) {
                if (run === null) run = { from: c.lba, to: c.lba, n: 1 };
                else { run.to = c.lba; run.n++; }
                if (best === null || run.n > best.n) best = { from: run.from, to: run.to, n: run.n };
            } else {
                run = null;
            }
        }
        return (best && best.n >= minRun) ? best : null;
    };

    /* ── pure: the one sentence that matters ─────────────────────────────── */
    /* MEDIA counters are the drive's own platters; PATH counters are the wire.
       Both moving is genuinely ambiguous, and saying so is the honest answer:
       picking a side there sends someone to buy the wrong part. */
    window.luDiagInterp = function (d) {
        var media = (d.grown || 0) + (d.uncorr || 0);
        var path  = (d.disp || 0) + (d.invdw || 0) + (d.loss || 0);
        if (media > 0 && path === 0)
            return 'Media counters moved (+' + media + '), path counters flat — points to MEDIA, not the cable.';
        if (path > 0 && media === 0)
            return 'Path counters moved (+' + path + '), media counters flat — points to the cable, backplane or HBA, not the platters.';
        if (media > 0 && path > 0)
            return 'Both media (+' + media + ') and path (+' + path + ') counters moved — ambiguous. Re-run after moving the drive to a different bay and cable.';
        return 'No counter moved during this run.';
    };

    /* ── state: everything durable is the offset ─────────────────────────── */
    var PHASES = [['preflight', 'Preflight'], ['baseline', 'Baseline snapshot'],
                  ['targeted', 'Targeted VERIFY/READ'], ['surface', 'Surface VERIFY'],
                  ['selftest', 'SMART short test'], ['verdict', 'Verdict']];
    var st = { phase: '', cells: [], deltas: {}, es: null, offset: 0,
               paused: false, started: 0, lbaTotal: 0, lba: 0 };

    function drawPills() {
        var seen = false, out = '';
        for (var i = 0; i < PHASES.length; i++) {
            var cls = 'queued';
            if (PHASES[i][0] === st.phase) { cls = 'active'; seen = true; }
            else if (!seen) cls = 'done';
            out += '<span class="lu-diag-pill ' + cls + '">' + fesc(PHASES[i][1]) + '</span>';
        }
        el('diag-pills').innerHTML = out;
    }

    function drawMap() {
        var out = '', i, c;
        for (i = 0; i < st.cells.length; i++) {
            c = st.cells[i];
            out += '<span class="lu-diag-cell ' + luDiagBucket(c.ms, c.ok)
                 + '" title="LBA ' + c.lba + ' +' + c.n + ' · ' + c.ms + ' ms'
                 + (c.ok ? '' : ' · UNREADABLE') + '"></span>';
        }
        el('diag-map').innerHTML = out;

        var z = luDiagHotZone(st.cells, 3);
        el('diag-hotzone').textContent = z
            ? 'Hot zone: ' + z.n + ' consecutive slow or failing chunks, LBA ' + z.from + '–' + z.to
            : '';
    }

    function drawHist() {
        var order = ['lu-b5', 'lu-b20', 'lu-b50', 'lu-b150', 'lu-b500', 'lu-b500p', 'lu-bbad'];
        var label = { 'lu-b5': '<5 ms', 'lu-b20': '<20 ms', 'lu-b50': '<50 ms',
                      'lu-b150': '<150 ms', 'lu-b500': '<500 ms',
                      'lu-b500p': '≥500 ms', 'lu-bbad': 'unreadable' };
        var counts = {}, i, b, max = 1, out = '';
        for (i = 0; i < st.cells.length; i++) {
            b = luDiagBucket(st.cells[i].ms, st.cells[i].ok);
            counts[b] = (counts[b] || 0) + 1;
            if (counts[b] > max) max = counts[b];
        }
        for (i = 0; i < order.length; i++) {
            var n = counts[order[i]] || 0;
            out += '<div><span style="display:inline-block;width:72px">' + label[order[i]] + '</span>'
                 + '<span class="lu-diag-cell ' + order[i]
                 + '" style="width:' + Math.round(180 * n / max) + 'px"></span> ' + n + '</div>';
        }
        el('diag-hist').innerHTML = out;
    }

    function drawCounters() {
        var keys = [['grown', 'Grown defect list'], ['uncorr', 'Uncorrected verify/read'],
                    ['disp', 'Running disparity'], ['invdw', 'Invalid DWORD'],
                    ['loss', 'Loss of DWORD sync']];
        var out = '', i, k, d;
        for (i = 0; i < keys.length; i++) {
            k = keys[i][0];
            d = st.deltas[k];
            out += '<div>' + fesc(keys[i][1]) + ': '
                 + (d === undefined ? '<span class="lu-muted">—</span>'
                    : d.after + ' (' + (d.after - d.before >= 0 ? '+' : '')
                      + (d.after - d.before) + ')') + '</div>';
        }
        el('diag-counters').innerHTML = out;

        var flat = {};
        for (k in st.deltas) if (st.deltas.hasOwnProperty(k))
            flat[k] = st.deltas[k].after - st.deltas[k].before;
        el('diag-interp').textContent = luDiagInterp(flat);
    }

    function drawProgress() {
        var secs = st.started ? Math.round((Date.now() - st.started) / 1000) : 0;
        var pct  = st.lbaTotal > 0 ? Math.min(100, Math.round(100 * st.lba / st.lbaTotal)) : 0;
        var rate = secs > 0 ? Math.round(st.lba / secs) : 0;
        /* Remaining is omitted, not guessed, until there is a rate to divide
           by: a countdown from an undefined throughput is a number that looks
           measured and is not. Absence is not health, and it is not progress
           either. */
        var left = (rate > 0 && st.lbaTotal > st.lba)
            ? Math.round((st.lbaTotal - st.lba) / rate) + ' s remaining'
            : 'remaining unknown';
        el('diag-progress').textContent = pct + '% · LBA ' + st.lba
            + (st.lbaTotal ? ' of ' + st.lbaTotal : '')
            + ' · ' + rate + ' blocks/s · ' + secs + ' s elapsed · ' + left;
    }

    function logLine(text, sev) {
        var s = el('diag-stream');
        var t = new Date().toLocaleTimeString();
        s.innerHTML += '<div class="lu-' + (sev || 'muted') + '">' + fesc(t + '  ' + text) + '</div>';
        s.scrollTo(0, 1e9);
    }

    window.luDiagShow = function (which) {
        el('diag-live').hidden    = (which !== 'live');
        el('diag-verdict').hidden = (which !== 'verdict');
    };

    /* One decoded event -> the screen. Exported because this is the whole
       rendering contract and a test that cannot call it has to assert on the
       stream plumbing instead, which is the part least likely to be wrong. */
    window.luDiagApply = function (ev) {
        if (!ev || !ev.t) return;
        if (ev.t === 'phase') {
            st.phase = ev.phase;
            if (ev.lba_total) st.lbaTotal = ev.lba_total;
            if (!st.started) st.started = Date.now();
            drawPills(); drawProgress();
            logLine('phase: ' + ev.phase, 'muted');
        } else if (ev.t === 'chunk') {
            st.cells.push({ lba: ev.lba, n: ev.n, ms: ev.ms, ok: ev.ok !== false });
            st.lba = ev.lba + ev.n;
            drawMap(); drawHist(); drawProgress();
            if (ev.ok === false) logLine(ev.op + ' FAILED at LBA ' + ev.lba + ' +' + ev.n, 'crit');
        } else if (ev.t === 'counter') {
            st.deltas[ev.key] = { before: ev.before, after: ev.after };
            drawCounters();
        } else if (ev.t === 'verdict') {
            logLine('verdict: ' + ev.v + ' — ' + ev.why, ev.v === 'CLEAN' ? 'ok' : 'crit');
            /* No arguments: the verdict screen is rendered server-side from the
               job id in luDiagJob, because it needs the sense keys and the
               cmd_age this event does not carry. Passing `ev` here would imply
               otherwise and would go unused. */
            window.luDiagRenderVerdict();
            luDiagShow('verdict');
            el('diag-dot').classList.remove('running');
            el('diag-pause').disabled = true;
            el('diag-cancel').disabled = true;
        }
    };

    function openStream() {
        if (st.es) st.es.close();
        /* The offset rides the URL on the FIRST connect only. Every automatic
           reconnect after that carries Last-Event-ID, which the server prefers
           -- so a dropped connection resumes exactly where it stopped without
           this file tracking anything the browser already knows. */
        st.es = new EventSource('/plugins/hbaviewer/diagnose_stream.php?job='
            + encodeURIComponent(luDiagJob) + '&offset=' + st.offset);
        st.es.onmessage = function (m) {
            if (st.paused) return;
            if (m.lastEventId) st.offset = parseInt(m.lastEventId, 10) || st.offset;
            var ev = null;
            try { ev = JSON.parse(m.data); } catch (e) { return; }
            luDiagApply(ev);
        };
        st.es.addEventListener('end', function () {
            st.es.close(); st.es = null;
            el('diag-dot').classList.remove('running');
            el('diag-pause').disabled = true;
            el('diag-cancel').disabled = true;
            logLine('job finished', 'ok');
        });
    }

    /* Pause is a VIEW control, not a job control. Phase 1 has no way to
       suspend an sg_verify mid-command and pretending otherwise would leave
       the disk being read while the screen said "paused". This freezes the
       rendering and says so; the stream keeps its offset, so resuming catches
       up rather than skipping. */
    window.luDiagPause = function () {
        st.paused = !st.paused;
        el('diag-pause').textContent = st.paused ? 'Resume' : 'Pause';
        logLine(st.paused ? 'view paused — the job keeps running' : 'view resumed', 'muted');
    };

    window.luDiagCancel = function () {
        if (!luDiagJob) return;
        fetch('/plugins/hbaviewer/diagnose.php', { method: 'POST',
            body: new URLSearchParams({ action: 'cancel', job: luDiagJob, csrf_token: luCsrf }) })
          .then(function (r) { return r.json(); })
          .then(function (d) { logLine(d.ok ? 'cancelled' : 'nothing to cancel', 'warn'); })
          .catch(function () { logLine('cancel request failed', 'crit'); });
    };

    /* The disk NAME, never a /dev path: diagnose.php validates
       /^[a-z0-9]{2,32}$/ and a "/dev/sdb" would be refused. One spelling on
       both sides of the wire. */
    window.luDiagnose = function (dev) {
        var disk = String(dev || '').replace(/^\/dev\//, '');
        st = { phase: '', cells: [], deltas: {}, es: null, offset: 0,
               paused: false, started: 0, lbaTotal: 0, lba: 0 };
        el('diag-map').innerHTML = '';
        el('diag-stream').innerHTML = '';
        el('diag-hotzone').textContent = '';
        luDiagShow('live');
        if (typeof luTab === 'function') luTab('diagnose');
        el('diag-head').innerHTML = 'Diagnosing <code>/dev/' + fesc(disk) + '</code>';
        return fetch('/plugins/hbaviewer/diagnose.php', { method: 'POST',
            body: new URLSearchParams({ action: 'start', disk: disk, csrf_token: luCsrf }) })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d.error) { logLine('refused: ' + d.error, 'crit'); return; }
            luDiagJob = d.job;
            el('diag-dot').classList.add('running');
            el('diag-pause').disabled = false;
            el('diag-cancel').disabled = false;
            logLine('job ' + d.job + ' started', 'ok');
            openStream();
          })
          .catch(function () { logLine('request failed', 'crit'); });
    };

})();
```

`window.luDiagRenderVerdict` is defined in Task 14. Until that task lands, add this one-line shim at the bottom of the IIFE so the file runs standalone, and **delete it in Task 14**:

```javascript
    /* Replaced by render/diagnose.php's client half in the next task. */
    if (!window.luDiagRenderVerdict) window.luDiagRenderVerdict = function () {};
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `node tests/diagnose_js_test.js`
Expected: `diagnose_js: all pass`.

- [ ] **Step 5: Register and run the full suite**

In `tests/run.sh`, beside the other JS test blocks:

```bash
echo
echo "=== diagnose JS runtime tests ==="
node diagnose_js_test.js; diagnose_js_fail=$?
```

and add `&& [ $diagnose_js_fail -eq 0 ]` to the final `if` chain.

Run: `bash tests/run.sh`
Expected: `--- all pass ---`.

- [ ] **Step 6: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js tests/diagnose_js_test.js tests/run.sh
git commit -m "Implement the Live Job screen: SSE client with byte-offset resume, latency-bucketed surface map, histogram, hot-zone detection, live counter deltas and the media-vs-path interpretation. Runtime tests pin the bucket boundaries and the run detector."
```

---

### Task 14: The Verdict screen

The verdict needs more than the event stream carries: the per-range kernel sense keys and `cmd_age` evidence, the expander port map, and the other recent verdicts. All of that is already in the job directory as files the engine wrote. So this is server-rendered — `Bash reads hardware, awk turns text into JSON, PHP turns JSON into HTML` — and reaches the browser as an HTML fragment, the same shape `ajax_info.php?type=overview_html` uses.

**Files:**
- Create: `source/usr/local/emhttp/plugins/hbaviewer/render/diagnose.php`
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/diagnose.php` (one new action)
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js`
- Create: `tests/diagnose_render_test.php`
- Modify: `tests/run_php.sh`, `tests/diagnose_php_test.php`

**Interfaces:**
- Consumes: `diag_job_valid()`, `diag_job_dir()`, `diag_slice()` (Task 11's library); the job directory's `events.ndjson`, `sense-<dev>.txt`, `ranges-<dev>.txt`, `before-<dev>.txt`, `after-<dev>.txt`, `data.tsv`.
- Produces:
  - `diag_events_decode(string $ndjson): array` — the event lines as decoded arrays; an unparseable line is skipped, not fatal.
  - `diag_verdict_words(string $v): array` → `['title'=>string,'lead'=>string]`. The plain-language explanation per verdict.
  - `diag_evidence_cards(array $events): array` → three rows, `[['title','result','detail'], …]` for SCSI VERIFY, SCSI READ, counter movement.
  - `diag_ranges_rows(array $events, array $sense, ?int $maxCmdAge): array` — one row per tested range: start LBA, block count, VERIFY result, READ result, sense-key/`cmd_age` evidence.
  - `diag_next_steps(string $verdict, bool $arrayDisk): array` — the numbered actions. For `MEDIA` on an array disk this is where the rebuild/replace guidance lives.
  - `renderDiagVerdict(array $in): string` — the whole screen. `$in` keys: `disk`, `verdict`, `why`, `events`, `sense`, `max_cmd_age`, `array_disk`, `ports`, `recent`.
  - `renderDiagDriveList(array $drives, array $verdicts): string` — the sidebar drive list, worst-first, with `MEDIA|TRANSPORT|SCANNING|CLEAN|STANDBY` badges and unassigned drives in their own group.
  - HTTP: `GET diagnose.php?action=verdict&job=<id>` → the Verdict screen fragment; `GET diagnose.php?action=drivelist` → the sidebar fragment.
  - JS: `luDiagRenderVerdict()` and `luDiagDrives()`, both zero-argument and both returning the fetch promise; `luDiagOpen(job)`.

Also modify: `source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js` (one line in `luTab()`).

- [ ] **Step 1: Write the failing test**

Create `tests/diagnose_render_test.php`:

```php
<?PHP
/* Runnable checks for render/diagnose.php. Pure functions over a decoded job,
   so the whole Verdict screen is testable with no /tmp, no job and no disk.
     php tests/diagnose_render_test.php  ->  "diagnose_render: all pass" */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/render/diagnose.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

/* ── decoding is forgiving ─────────────────────────────────────────────── */
$nd = "{\"t\":\"phase\",\"phase\":\"targeted\"}\n{ broken\n{\"t\":\"verdict\",\"v\":\"MEDIA\"}\n";
$ev = diag_events_decode($nd);
check('a valid line decodes',            count($ev) === 2);
// A truncated last line is normal -- the engine appends and the reader can
// arrive mid-write. Throwing there would blank the screen over a byte.
check('a broken line is skipped, not fatal', $ev[1]['v'] === 'MEDIA');
check('an empty file decodes to nothing',    diag_events_decode('') === []);

/* ── the verdict banner reads as prose, not as a code ──────────────────── */
$w = diag_verdict_words('TRANSPORT');
check('TRANSPORT has a title',  $w['title'] !== '');
// The spec's own sentence: the point is that replacing the drive will not fix
// it, and the banner has to say so or the user replaces the drive.
check('TRANSPORT says replacing the drive would not fix it',
      str_contains(strtolower($w['lead']), 'would not fix'));
check('MEDIA points at the platters',
      str_contains(strtolower(diag_verdict_words('MEDIA')['lead']), 'platters')
      || str_contains(strtolower(diag_verdict_words('MEDIA')['lead']), 'media'));
check('CLEAN says nothing reproduced',
      str_contains(strtolower(diag_verdict_words('CLEAN')['lead']), 'not reproduce')
      || str_contains(strtolower(diag_verdict_words('CLEAN')['lead']), 'no fault'));
// An unknown verdict must render as unknown, never as clean. Absence is not
// health, and a green banner over an unreadable run is a lie.
$u = diag_verdict_words('WAT');
check('an unknown verdict is not rendered as clean',
      $u['title'] !== diag_verdict_words('CLEAN')['title']);

/* ── three evidence cards: the reasoning, not just the conclusion ──────── */
$events = diag_events_decode(
    "{\"t\":\"chunk\",\"lba\":0,\"n\":64,\"op\":\"verify\",\"ms\":9,\"ok\":true}\n" .
    "{\"t\":\"chunk\",\"lba\":0,\"n\":64,\"op\":\"read\",\"ms\":900,\"ok\":false}\n" .
    "{\"t\":\"counter\",\"key\":\"disp\",\"before\":210,\"after\":214}\n" .
    "{\"t\":\"verdict\",\"disk\":\"sdb\",\"v\":\"TRANSPORT\",\"why\":\"verify clean, read failed\"}\n");
$cards = diag_evidence_cards($events);
check('there are exactly three evidence cards', count($cards) === 3);
check('the first card is SCSI VERIFY',  str_contains($cards[0]['title'], 'VERIFY'));
check('the second card is SCSI READ',   str_contains($cards[1]['title'], 'READ'));
check('the third card is counter movement',
      str_contains(strtolower($cards[2]['title']), 'counter'));
check('a clean verify reads as clean',  str_contains(strtolower($cards[0]['result']), 'clean'));
check('a failed read reads as failed',  str_contains(strtolower($cards[1]['result']), 'fail'));

/* ── the tested-ranges table ───────────────────────────────────────────── */
$rows = diag_ranges_rows($events, ['0x3' => 4], 92);
check('one row per tested range',            count($rows) === 1);
check('the row names the start LBA',         in_array('0', array_map('strval', $rows[0]), true));
check('the sense key evidence is carried',   str_contains(implode(' ', $rows[0]), '0x3'));
// Over a minute is a LINK timeout, not a media retry: drive-internal recovery
// gives up in 7-30 s. Saying so on the row is the difference between the user
// suspecting the drive and suspecting the cable.
check('a cmd_age over a minute is called a timeout',
      str_contains(strtolower(implode(' ', $rows[0])), 'timeout'));
$fast = diag_ranges_rows($events, [], 8);
check('a short cmd_age is not called a timeout',
      !str_contains(strtolower(implode(' ', $fast[0])), 'timeout'));

/* ── next steps, and the array-disk rule ───────────────────────────────── */
$steps = diag_next_steps('TRANSPORT', false);
check('TRANSPORT next steps are numbered actions', count($steps) >= 3);
check('they include moving the drive to another bay or cable',
      str_contains(strtolower(implode(' ', $steps)), 'cable'));
check('and explain how to read whether the fault followed the slot',
      str_contains(strtolower(implode(' ', $steps)), 'slot'));

// Phase 2's guarded reassign flow does not exist yet, so a MEDIA verdict on an
// ARRAY disk must state the rebuild/replace path in plain language. Writing a
// block straight to an assigned disk bypasses parity, and the next parity
// check flags it as a mismatch -- so "repair the sector" is never the answer
// here, whatever Phase 2 eventually offers for unassigned disks.
$ma = diag_next_steps('MEDIA', true);
check('MEDIA on an array disk gives rebuild/replace guidance',
      str_contains(strtolower(implode(' ', $ma)), 'rebuild'));
check('and never offers to repair the sector directly',
      !str_contains(strtolower(implode(' ', $ma)), 'sg_reassign')
      && !str_contains(strtolower(implode(' ', $ma)), 'write-sector'));
$mu = diag_next_steps('MEDIA', false);
check('MEDIA on an unassigned disk differs from the array case', $mu !== $ma);

/* ── the whole screen ──────────────────────────────────────────────────── */
$html = renderDiagVerdict([
    'disk' => 'sdb', 'verdict' => 'TRANSPORT', 'why' => 'verify clean, read failed x3',
    'events' => $events, 'sense' => ['0x3' => 4], 'max_cmd_age' => 92,
    'array_disk' => true, 'ports' => ['0x500...abc' => ['sdb', 'sdc']],
    'recent' => [['job' => 'sdc-1', 'disk' => 'sdc', 'verdict' => 'CLEAN']],
]);
check('the screen names the disk',            str_contains($html, 'sdb'));
check('the screen carries the verdict',       str_contains($html, 'TRANSPORT'));
check('the screen carries the tested ranges', str_contains($html, 'VERIFY'));
check('the screen carries the topology',      str_contains($html, 'sdc'));
check('the screen lists recent verdicts',     str_contains($html, 'sdc-1'));
// Every value here came off a disk or out of a kernel log. A model string with
// an angle bracket in it is the whole reason luTable and every renderer in
// this repo escape.
$evil = renderDiagVerdict([
    'disk' => '<img src=x>', 'verdict' => 'MEDIA', 'why' => '"><script>bad()</script>',
    'events' => [], 'sense' => [], 'max_cmd_age' => null,
    'array_disk' => false, 'ports' => [], 'recent' => [],
]);
check('the disk name is escaped', !str_contains($evil, '<img src=x>'));
check('the reason is escaped',    !str_contains($evil, '<script>bad()'));

/* ── the drive list sidebar ────────────────────────────────────────────── */
$drives = [
    ['dev' => 'sdb', 'role' => 'Disk 1'],
    ['dev' => 'sdc', 'role' => ''],
    ['dev' => 'sdd', 'role' => 'Disk 2'],
];
$list = renderDiagDriveList($drives, ['sdb' => 'CLEAN', 'sdc' => 'MEDIA', 'sdd' => 'STANDBY']);
check('the drive list renders every drive',
      str_contains($list, 'sdb') && str_contains($list, 'sdc') && str_contains($list, 'sdd'));
// Worst-first: the reason the list exists is to put the drive you should look
// at next at the top, so an alphabetical list defeats the feature.
check('MEDIA sorts above CLEAN', strpos($list, 'sdc') < strpos($list, 'sdb'));
check('unassigned drives are their own group',
      str_contains(strtolower($list), 'unassigned'));

echo $fails === 0 ? "diagnose_render: all pass\n" : "diagnose_render: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/diagnose_render_test.php`
Expected: a fatal — `Failed opening required '.../render/diagnose.php'`.

- [ ] **Step 3: Implement the renderer**

Create `source/usr/local/emhttp/plugins/hbaviewer/render/diagnose.php`. It follows the repo's renderer conventions: pure over its arguments, `htmlspecialchars` on everything, `luTable()` for tables, no `shell_exec`.

```php
<?php
/* Diagnose Verdict screen. Pure over a decoded job, so the whole screen is
 * testable with no /tmp, no job and no disk -- the same property every other
 * render/*.php has.
 *
 * SERVER-RENDERED, unlike the Live Job screen, because the verdict needs more
 * than the event stream carries: the per-range kernel sense keys, the worst
 * cmd_age, the expander port map and the other recent verdicts all live as
 * files the engine wrote. Reaches the browser as an HTML fragment, the shape
 * ajax_info.php?type=overview_html already uses.
 *
 * THE VERDICT IS SHOWN AS REASONING, not as a conclusion. Three evidence cards
 * and a per-range table, because "TRANSPORT" on its own is a label the user has
 * no way to check and every reason to distrust.
 */
require_once __DIR__ . '/table.php';

/* One decoded array per parseable line. A broken line is SKIPPED, not fatal:
   the engine appends while this reads, so arriving mid-write is normal and
   throwing there would blank the screen over one byte. */
function diag_events_decode(string $ndjson): array {
    $out = [];
    foreach (explode("\n", $ndjson) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $d = json_decode($line, true);
        if (is_array($d) && isset($d['t'])) $out[] = $d;
    }
    return $out;
}

/* Plain language, in the banner, above the label. An unknown verdict renders
   as unknown and never as clean -- absence is not health, and a green banner
   over a run that did not finish is a lie with a pill on it. */
function diag_verdict_words(string $v): array {
    switch ($v) {
        case 'TRANSPORT': return [
            'title' => 'TRANSPORT — the link, not the drive',
            'lead'  => 'The drive read every tested block cleanly on its own. When those same blocks had to cross the SAS link, the read failed. Replacing this drive would not fix it.',
        ];
        case 'MEDIA': return [
            'title' => 'MEDIA — the drive itself',
            'lead'  => 'The drive could not read its own platters at the tested blocks. The fault is in the media, not on the wire.',
        ];
        case 'CLEAN': return [
            'title' => 'No fault reproduced',
            'lead'  => 'Every tested block read cleanly, internally and over the link. The fault did not reproduce — re-run under load, or enable the full-surface scan.',
        ];
    }
    return [
        'title' => 'Unknown — this run did not classify',
        'lead'  => 'The job did not produce a verdict. Read the event stream: it either did not finish, or sg3_utils was missing and the VERIFY-vs-READ discriminator was unavailable.',
    ];
}

/* The three signals drive_triage.sh classifies on, shown as the reasoning.
   Chunks are summarised per op rather than listed: the per-chunk detail is the
   surface map's job, and repeating it here buries the comparison that matters. */
function diag_evidence_cards(array $events): array {
    $n = ['verify' => 0, 'read' => 0];
    $f = ['verify' => 0, 'read' => 0];
    $media = 0; $path = 0;
    foreach ($events as $e) {
        if ($e['t'] === 'chunk' && isset($n[$e['op'] ?? ''])) {
            $n[$e['op']]++;
            if (($e['ok'] ?? true) === false) $f[$e['op']]++;
        } elseif ($e['t'] === 'counter') {
            $d = (int) ($e['after'] ?? 0) - (int) ($e['before'] ?? 0);
            if ($d <= 0) continue;
            if (in_array($e['key'] ?? '', ['grown', 'uncorr'], true)) $media += $d;
            else                                                       $path  += $d;
        }
    }
    $word = function (int $total, int $failed): string {
        if ($total === 0) return 'not run';
        return $failed === 0 ? "clean ($total chunks)" : "FAILED ($failed of $total chunks)";
    };
    return [
        ['title'  => 'SCSI VERIFY',
         'result' => $word($n['verify'], $f['verify']),
         'detail' => 'The drive reads and checks the blocks internally. Nothing crosses the SAS link, so a failure here is the media.'],
        ['title'  => 'SCSI READ',
         'result' => $word($n['read'], $f['read']),
         'detail' => 'The same blocks moved over the wire. Clean VERIFY with a failing READ is a transport fault.'],
        ['title'  => 'Counter movement',
         'result' => $media === 0 && $path === 0 ? 'none'
                     : "media +$media · path +$path",
         'detail' => 'Media counters are the platters (grown defects, uncorrected reads); path counters are the wire (running disparity, invalid DWORD, loss of sync).'],
    ];
}

/* One row per tested range. $sense is [sense_key => count] harvested from the
   kernel log; $maxCmdAge is the worst cmd_age in seconds, or null.
   Commands hanging over a minute are called a TIMEOUT explicitly: the drive's
   own recovery gives up in 7-30 s, so a longer hang is the link giving up, not
   the media retrying -- and that distinction is the whole point of the screen. */
const DIAG_CMD_AGE_TIMEOUT = 60;

function diag_ranges_rows(array $events, array $sense, ?int $maxCmdAge): array {
    $ranges = [];
    foreach ($events as $e) {
        if (($e['t'] ?? '') !== 'chunk') continue;
        $key = (string) ($e['lba'] ?? 0);
        if (!isset($ranges[$key])) {
            $ranges[$key] = ['lba' => (int) ($e['lba'] ?? 0), 'n' => 0,
                             'verify' => null, 'read' => null];
        }
        $ranges[$key]['n'] = max($ranges[$key]['n'], (int) ($e['n'] ?? 0));
        $op = $e['op'] ?? '';
        if ($op === 'verify' || $op === 'read') {
            $okSoFar = $ranges[$key][$op];
            $thisOk  = ($e['ok'] ?? true) !== false;
            $ranges[$key][$op] = $okSoFar === null ? $thisOk : ($okSoFar && $thisOk);
        }
    }
    $keys = array_keys($sense);
    $evidence = $keys === [] ? '—' : ('sense ' . implode(', ', $keys));
    if ($maxCmdAge !== null) {
        $evidence .= ' · worst cmd_age ' . $maxCmdAge . 's';
        if ($maxCmdAge > DIAG_CMD_AGE_TIMEOUT) {
            $evidence .= ' — over a minute, so a LINK TIMEOUT rather than a media retry'
                       . ' (drive-internal recovery gives up in 7–30 s)';
        }
    }
    $rows = [];
    ksort($ranges, SORT_NUMERIC);
    foreach ($ranges as $r) {
        $say = fn(?bool $v) => $v === null ? 'not run' : ($v ? 'clean' : 'FAILED');
        $rows[] = [(string) $r['lba'], (string) $r['n'],
                   $say($r['verify']), $say($r['read']), $evidence];
    }
    return $rows;
}

/* Numbered, concrete actions.
 *
 * THE ARRAY-DISK RULE. Writing a block straight to an assigned disk bypasses
 * parity: the next parity check flags it as a mismatch and a future rebuild
 * could reintroduce bad data. The safe fix for a bad sector on an array disk
 * is a REBUILD -- onto the same disk, which rewrites every sector and remaps
 * the pending ones, or onto a replacement. Phase 2's guarded reassign flow
 * does not exist yet and will never apply to an assigned disk anyway, so this
 * card states the rebuild path in plain language. It is read-only advice, not
 * a control, which is what makes it Phase 1 work. */
function diag_next_steps(string $verdict, bool $arrayDisk): array {
    if ($verdict === 'TRANSPORT') {
        return [
            'Move this drive to a different bay, on a different cable, and re-run Diagnose.',
            'If the errors follow the SLOT, the fault is the cable, backplane, expander or HBA — not the drive.',
            'If the errors follow the DRIVE, the fault is that drive\'s own SAS interface electronics, and the platters are still fine.',
            'Check the PHY Health tab for other drives sharing the same port: several drives hot on one path is a shared fault, not four failing disks.',
        ];
    }
    if ($verdict === 'MEDIA' && $arrayDisk) {
        return [
            'This is an ARRAY disk. Do not write to it directly — a sector written straight to an assigned disk bypasses parity, the next parity check flags it as a mismatch, and a future rebuild could reintroduce bad data.',
            'The safe repair is a REBUILD. Rebuilding onto the same disk rewrites every sector and remaps the pending ones; rebuilding onto a replacement retires the drive.',
            'Rebuild onto a replacement if the defect count is still climbing between runs. Re-run Diagnose in a few hours and compare: a count that stops moving is a drive you can keep watching, a count that keeps moving is a drive on its way out.',
            'Back up anything not covered by parity before starting, and do not start a rebuild while another disk is disabled.',
        ];
    }
    if ($verdict === 'MEDIA') {
        return [
            'This drive is not assigned to the array or a pool, so there is nothing for parity to disagree with.',
            'Plan its replacement. The drive cannot read its own platters at these blocks, and a defect list that keeps growing between runs is a drive on its way out.',
            'Re-run Diagnose in a few hours and compare the counts before deciding: one bad block that never moves again is a different drive from one gaining blocks every run.',
        ];
    }
    if ($verdict === 'CLEAN') {
        return [
            'Nothing reproduced. Re-run while the array is under load — an intermittent link fault often needs traffic to appear.',
            'Enable the full-surface VERIFY for the next run if you have hours to spare: it tests every block rather than the ranges the kernel log already named.',
            'Re-check the PHY Health tab\'s baseline. A counter rising slowly is invisible in a single run and obvious against a baseline.',
        ];
    }
    return [
        'This run produced no verdict. Read the event stream above for where it stopped.',
        'If sg3_utils is missing, the VERIFY-vs-READ discriminator is unavailable and the job can only say THAT a disk failed, not why. Install it and re-run.',
    ];
}

function renderDiagVerdict(array $in): string {
    $disk   = (string) ($in['disk'] ?? '');
    $v      = (string) ($in['verdict'] ?? '');
    $w      = diag_verdict_words($v);
    $events = (array) ($in['events'] ?? []);

    $out = '<div class="lu-card first">'
         . '<h3>' . htmlspecialchars($w['title']) . ' — <code>/dev/'
         . htmlspecialchars($disk) . '</code></h3>'
         . '<p>' . htmlspecialchars($w['lead']) . '</p>'
         . '<p class="lu-muted" style="font-size:12px">'
         . htmlspecialchars((string) ($in['why'] ?? '')) . '</p></div>';

    $out .= '<div class="lu-diag-cols">';
    foreach (diag_evidence_cards($events) as $c) {
        $out .= '<div class="lu-card"><h4>' . htmlspecialchars($c['title']) . '</h4>'
              . '<p><strong>' . htmlspecialchars($c['result']) . '</strong></p>'
              . '<p class="lu-muted" style="font-size:12px">' . htmlspecialchars($c['detail']) . '</p></div>';
    }
    $out .= '</div>';

    $rows = diag_ranges_rows($events, (array) ($in['sense'] ?? []),
                             $in['max_cmd_age'] === null ? null : (int) $in['max_cmd_age']);
    $out .= '<div class="lu-card"><h4>Tested ranges</h4>';
    $out .= $rows === []
        ? '<p class="lu-muted">No range was tested in this run.</p>'
        : luTable(['Start LBA', 'Blocks', 'VERIFY', 'READ', 'Kernel evidence'],
                  array_map(fn($r) => array_map('htmlspecialchars', $r), $rows));
    $out .= '</div>';

    $out .= '<div class="lu-card"><h4>What to do next</h4><ol>';
    foreach (diag_next_steps($v, !empty($in['array_disk'])) as $s) {
        $out .= '<li>' . htmlspecialchars($s) . '</li>';
    }
    $out .= '</ol></div>';

    /* Which drives share a port. A single hot drive on a port is a lane or a
       cable; a whole hot expander is the cable to it or the expander itself --
       and that is not visible from one drive's verdict. */
    $out .= '<div class="lu-card"><h4>Drives sharing this path</h4>';
    $ports = (array) ($in['ports'] ?? []);
    if ($ports === []) {
        $out .= '<p class="lu-muted">No expander reported — these drives are direct-attached.</p>';
    } else {
        foreach ($ports as $addr => $devs) {
            $out .= '<p><code>' . htmlspecialchars((string) $addr) . '</code>: '
                  . htmlspecialchars(implode(', ', array_map('strval', (array) $devs))) . '</p>';
        }
    }
    $out .= '</div>';

    $out .= '<div class="lu-card"><h4>Recent verdicts</h4>';
    $recent = (array) ($in['recent'] ?? []);
    if ($recent === []) {
        $out .= '<p class="lu-muted">No other completed runs are kept.</p>';
    } else {
        foreach ($recent as $r) {
            $job = (string) ($r['job'] ?? '');
            $out .= '<p><button class="lu-refresh-btn" type="button" onclick="luDiagOpen(\''
                  . htmlspecialchars($job, ENT_QUOTES) . '\')">'
                  . htmlspecialchars($job) . '</button> '
                  . htmlspecialchars((string) ($r['disk'] ?? '')) . ' — '
                  . htmlspecialchars((string) ($r['verdict'] ?? 'unknown')) . '</p>';
        }
    }
    $out .= '</div>';
    return $out;
}

/* Worst-first, because the whole point of the list is to put the drive you
   should look at next at the top. Unassigned drives are their own group: a
   disk the array does not know about is a different kind of fact from Disk 1,
   and mixing them implies the column means one thing when it means two. */
const DIAG_BADGE_RANK = ['MEDIA' => 0, 'TRANSPORT' => 1, 'SCANNING' => 2,
                         'CLEAN' => 3, 'STANDBY' => 4];

function renderDiagDriveList(array $drives, array $verdicts): string {
    $group = ['assigned' => [], 'unassigned' => []];
    foreach ($drives as $d) {
        $dev = (string) ($d['dev'] ?? '');
        if ($dev === '') continue;
        $badge = (string) ($verdicts[$dev] ?? 'CLEAN');
        $row   = ['dev' => $dev, 'role' => (string) ($d['role'] ?? ''), 'badge' => $badge,
                  'rank' => DIAG_BADGE_RANK[$badge] ?? 9];
        $group[$row['role'] === '' ? 'unassigned' : 'assigned'][] = $row;
    }
    $sorter = fn(array $a, array $b) => $a['rank'] === $b['rank']
        ? strcmp($a['dev'], $b['dev'])
        : $a['rank'] <=> $b['rank'];
    usort($group['assigned'],   $sorter);
    usort($group['unassigned'], $sorter);

    $render = function (string $heading, array $rows): string {
        if ($rows === []) return '';
        $out = '<p class="lu-muted" style="font-size:12px;margin:6px 0 2px">'
             . htmlspecialchars($heading) . '</p>';
        foreach ($rows as $r) {
            $out .= '<p><button class="lu-refresh-btn" type="button" onclick="luDiagnose(\''
                  . htmlspecialchars($r['dev'], ENT_QUOTES) . '\')">Diagnose</button> '
                  . '<code>' . htmlspecialchars($r['dev']) . '</code> '
                  . ($r['role'] !== '' ? htmlspecialchars($r['role']) . ' ' : '')
                  . '<span class="lu-diag-pill">' . htmlspecialchars($r['badge']) . '</span></p>';
        }
        return $out;
    };
    return $render('Array and pool', $group['assigned'])
         . $render('Unassigned', $group['unassigned']);
}
```

- [ ] **Step 4: Add the `verdict` action and the client half**

In `diagnose.php`'s dispatch, before the final 400, add:

```php
if ($action === 'verdict') {
    $job = (string) ($_GET['job'] ?? '');
    if (!diag_job_valid($job)) { echo json_encode(['error' => 'Invalid job.']); exit; }
    header('Content-Type: text/html; charset=utf-8');
    require_once __DIR__ . '/render/diagnose.php';
    $dir  = diag_job_dir($job, DIAG_ROOT);
    $disk = diag_job_disk($job);
    /* The whole event file, not a slice: this runs once, when a job ends or a
       past verdict is reopened, and the file is the size of a scan's chunk
       count -- not a stream to keep up with. */
    $events = diag_events_decode((string) @file_get_contents("$dir/events.ndjson"));
    /* sense-<dev>.txt is "  4 Sense Key : 0x3" per line, the engine's own
       uniq -c output. */
    $sense = [];
    foreach (explode("\n", (string) @file_get_contents("$dir/sense-$disk.txt")) as $l) {
        if (preg_match('/^\s*(\d+)\s+Sense Key : (0x[0-9a-f]+)/', $l, $m)) {
            $sense[$m[2]] = (int) $m[1];
        }
    }
    $age = null;
    if (preg_match_all('/cmd_age=(\d+)/', (string) @file_get_contents("$dir/dmesg-$disk.txt"), $m)) {
        $age = max(array_map('intval', $m[1]));
    }
    /* Assigned-ness decides which next-steps card the screen shows, and
       getting it wrong in the permissive direction would offer array advice
       for an unassigned disk or, worse, the reverse. Read from Unraid's own
       disks.ini, and treat an unreadable file as ASSIGNED -- the stricter of
       the two cards, which never suggests writing to the disk. */
    $ini = @parse_ini_file('/var/local/emhttp/disks.ini', true);
    $arrayDisk = true;
    if (is_array($ini)) {
        $arrayDisk = false;
        foreach ($ini as $name => $sec) {
            if (($sec['device'] ?? '') === $disk && preg_match('/^disk\d+$/', (string) $name)) {
                $arrayDisk = true; break;
            }
        }
    }
    $recent = [];
    foreach (glob(DIAG_ROOT . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        $j = basename($d);
        if (!diag_job_valid($j) || $j === $job) continue;
        $ve = diag_events_decode((string) @file_get_contents("$d/events.ndjson"));
        $vv = 'unknown';
        foreach ($ve as $e) if (($e['t'] ?? '') === 'verdict') $vv = (string) ($e['v'] ?? 'unknown');
        $recent[] = ['job' => $j, 'disk' => diag_job_disk($j), 'verdict' => $vv,
                     'mtime' => (int) @filemtime($d)];
    }
    usort($recent, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    $verdict = ''; $why = '';
    foreach ($events as $e) {
        if (($e['t'] ?? '') === 'verdict') {
            $verdict = (string) ($e['v'] ?? ''); $why = (string) ($e['why'] ?? '');
        }
    }
    echo renderDiagVerdict([
        'disk' => $disk, 'verdict' => $verdict, 'why' => $why, 'events' => $events,
        'sense' => $sense, 'max_cmd_age' => $age, 'array_disk' => $arrayDisk,
        'ports' => [], 'recent' => array_slice($recent, 0, 5),
    ]);
    exit;
}
```

Also add a `drivelist` action, which is what fills the Live Job screen's `#diag-drives` sidebar — `renderDiagDriveList()` has no other caller and an uncalled renderer is a renderer nobody notices breaking:

```php
if ($action === 'drivelist') {
    header('Content-Type: text/html; charset=utf-8');
    require_once __DIR__ . '/render/diagnose.php';
    /* Assigned-ness and the /dev name both come from Unraid's own disks.ini,
       the same file the engine reads its slots from -- not from a second
       enumeration that could disagree with it about which disk is Disk 1. */
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
    /* A disk with a live lock is SCANNING; one with a finished run carries its
       verdict; one with neither is CLEAN only in the sense of "nothing has
       been tested", which is why the badge set has no fourth state for it --
       the drive list is a launcher, and CLEAN here means "no finding on
       record", stated the same way everywhere. */
    $verdicts = [];
    foreach ($drives as $d) {
        if (is_file(diag_lock_path($d['dev'], DIAG_ROOT))) { $verdicts[$d['dev']] = 'SCANNING'; continue; }
        $newest = null; $newestAt = -1;
        foreach (glob(DIAG_ROOT . '/' . $d['dev'] . '-*', GLOB_ONLYDIR) ?: [] as $jd) {
            if (!diag_job_valid(basename($jd))) continue;
            $at = (int) @filemtime($jd);
            if ($at > $newestAt) { $newestAt = $at; $newest = $jd; }
        }
        if ($newest === null) continue;
        foreach (diag_events_decode((string) @file_get_contents("$newest/events.ndjson")) as $e) {
            if (($e['t'] ?? '') === 'verdict') $verdicts[$d['dev']] = (string) ($e['v'] ?? '');
        }
    }
    echo renderDiagDriveList($drives, $verdicts);
    exit;
}
```

In `diagnose_view.js`, **delete the `luDiagRenderVerdict` shim** from Task 13 and replace it with:

```javascript
    /* The verdict screen is server-rendered: it needs the per-range sense keys,
       the worst cmd_age and the other recent runs, none of which the event
       stream carries. One fetch, once, when the job ends. */
    window.luDiagRenderVerdict = function () {
        return fetch('/plugins/hbaviewer/diagnose.php?action=verdict&job='
                     + encodeURIComponent(luDiagJob))
          .then(function (r) { return r.text(); })
          .then(function (h) { el('diag-verdict').innerHTML = h; })
          .catch(function () {
            el('diag-verdict').textContent = 'Could not load the verdict — the run is on disk, reload the tab.';
          });
    };

    /* Reopening a past verdict. Switches screens without starting anything:
       every button on the Verdict screen is read-only. */
    window.luDiagOpen = function (job) {
        luDiagJob = job;
        luDiagShow('verdict');
        if (typeof luTab === 'function') luTab('diagnose');
        return luDiagRenderVerdict();
    };

    /* The sidebar drive list, worst-first with verdict badges. Server-rendered
       for the same reason the verdict screen is: the badges come from each
       disk's newest job directory, which only the server can see. */
    window.luDiagDrives = function () {
        return fetch('/plugins/hbaviewer/diagnose.php?action=drivelist')
          .then(function (r) { return r.text(); })
          .then(function (h) { el('diag-drives').innerHTML = h; })
          .catch(function () {
            el('diag-drives').textContent = 'Could not load the drive list.';
          });
    };
```

In Task 13's `luDiagnose()`, add `luDiagDrives();` immediately after the `luDiagShow('live');` line, and in the `end` event listener, so the badges refresh when a job finishes. In `luDiagApply()`'s verdict branch, add it after `luDiagShow('verdict');` for the same reason.

The `luTab` hook fills it on first view: add to `hbaviewer.js`'s `luTab()`, beside the other per-tab first-activation loads, `if (name === 'diagnose' && typeof luDiagDrives === 'function') luDiagDrives();`. Read `luTab()` before editing — it already has this shape for the other tabs, and the guard on `typeof` matters because `hbaviewer.js` loads before `diagnose_view.js`.

Add to `tests/diagnose_php_test.php`, before its final `echo`:

```php
check('the verdict action renders server-side',
      str_contains($code, "action === 'verdict'")
      && str_contains($code, 'renderDiagVerdict('));
// An unreadable disks.ini must mean ASSIGNED, the stricter card -- it is the
// one that never suggests writing to the disk. The permissive default here
// would offer sector-level advice for an array member.
check('assigned-ness defaults to true when disks.ini cannot be read',
      str_contains($code, '$arrayDisk = true;'));
// renderDiagDriveList has exactly one caller. An uncalled renderer is one
// nobody notices breaking, and this is the surface every Diagnose job is
// started from.
check('the drive list has an action that renders it',
      str_contains($code, "action === 'drivelist'")
      && str_contains($code, 'renderDiagDriveList('));

$js = (string) file_get_contents(
    __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js');
check('the client fetches the drive list',  str_contains($js, 'luDiagDrives'));
check('the verdict renderer takes no arguments, matching its caller',
      str_contains($js, 'window.luDiagRenderVerdict = function ()')
      && str_contains($js, 'window.luDiagRenderVerdict();'));
$hbjs = (string) file_get_contents(
    __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js');
// hbaviewer.js loads BEFORE diagnose_view.js, so the hook must be guarded on
// typeof or the first render of any tab throws before the strip works.
check('luTab fills the drive list, guarded on typeof',
      str_contains($hbjs, "typeof luDiagDrives === 'function'"));
```

- [ ] **Step 5: Run the tests**

Run: `php tests/diagnose_render_test.php && php tests/diagnose_php_test.php && node tests/diagnose_js_test.js`
Expected: `diagnose_render: all pass`, `diagnose_php: all pass`, `diagnose_js: all pass`.

- [ ] **Step 6: Register, run the suite, commit**

Add `diagnose_render_test.php` to the `TESTS` list in `tests/run_php.sh`.

Run: `bash tests/run.sh`
Expected: `--- all pass ---`.

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/render/diagnose.php \
        source/usr/local/emhttp/plugins/hbaviewer/diagnose.php \
        source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js \
        source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js \
        tests/diagnose_render_test.php tests/diagnose_php_test.php tests/run_php.sh
git commit -m "Add the Verdict screen and the sidebar drive list: banner, three evidence cards, tested-ranges table with kernel sense-key and cmd_age evidence, next-steps card, shared-path list and recent verdicts. A MEDIA verdict on an array disk gets rebuild guidance and never a sector-level suggestion."
```

---

### Task 15: Diagnose entry points on Drives rows and Top Offenders

A Diagnose button on each Drives row, and one on each Top Offenders entry. Both call `luDiagnose(dev)`.

The Top Offenders half needs one additive change to a pure function: `phy_top_offenders()` returns a `drive` **label** (`"12 · /dev/sdf"`), which is for reading, not for passing to an endpoint that validates `/^[a-z0-9]{2,32}$/`. It gains a `dev` key carrying just the bare name, or `null` where none resolved.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/render/drives.php`
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/render/phy.php`
- Modify: `tests/ajax_render_test.php`

**Interfaces:**
- Consumes: `luDiagnose(dev)` (Task 13), `drive_dev_name()` (existing, `render/drives.php`).
- Produces:
  - `diag_cell(array $d, array $devBySerial): string` in `render/drives.php` — the Diagnose cell for one drive row. An em dash with a `role="img"` label where no `/dev` name resolved, matching how `$locCell` handles a missing address.
  - `phy_top_offenders()` rows gain `'dev' => ?string`. Existing keys are unchanged.

- [ ] **Step 1: Write the failing test**

Append to `tests/ajax_render_test.php`, before its final tally:

```php
/* ── Diagnose entry points (plan 2026-09-21) ───────────────────────────── */
$dv = ['backend' => 'storcli', 'controllers' => [['drives' => [
    ['slot' => '8:1', 'model' => 'ST10', 'serial' => 'SER1', 'state' => 'Onln',
     'size' => '10TB', 'sas_address' => '0x5', 'link' => '12G', 'firmware' => 'A1',
     'port' => '0'],
    ['slot' => '8:2', 'model' => 'ST10', 'serial' => 'NOSUCH', 'state' => 'Onln',
     'size' => '10TB', 'sas_address' => '0x6', 'link' => '12G', 'firmware' => 'A1',
     'port' => '1'],
]]]];
$h = renderDrivesTables($dv, ['SER1' => '/dev/sdf']);
check('the Drives table has a Diagnose column', str_contains($h, 'Diagnose'));
// The BARE name, never the /dev path: diagnose.php validates
// /^[a-z0-9]{2,32}$/ and would refuse "/dev/sdf". One spelling on both sides.
check('the Diagnose button passes the bare device name',
      str_contains($h, "luDiagnose('sdf')"));
check('and never the /dev path', !str_contains($h, "luDiagnose('/dev/sdf')"));
// No /dev name resolved means there is nothing to diagnose. Offering a button
// that cannot work is the failure the Locate cell already avoids by saying why.
check('a drive with no /dev name gets no button, and says why',
      substr_count($h, 'luDiagnose(') === 1
      && str_contains($h, 'No device name for this drive'));

/* Top offenders rows carry a bare dev for the same reason. */
$offPhys  = [['phy' => 0, 'inv' => 100, 'disp' => 0, 'sync' => 0, 'reset' => 0]];
$offDelta = [0 => ['rate' => ['inv' => 5.0, 'disp' => 0.0, 'sync' => 0.0, 'reset' => 0.0],
                   'reset' => false]];
$offDrv   = [['phy' => 0, 'serial' => 'SER1', 'slot' => '8:1', 'sas_address' => '0x5']];
$off = phy_top_offenders($offPhys, $offDelta, $offDrv, 5, ['SER1' => '/dev/sdf']);
check('a top-offenders row carries a bare dev', ($off[0]['dev'] ?? null) === 'sdf');
check('and keeps its human label',              str_contains((string) $off[0]['drive'], 'sdf'));
// A PHY whose drive could not be identified must carry null, not a guess: the
// value ends up as an argument to a root script.
$offNone = phy_top_offenders($offPhys, $offDelta, [], 5, []);
check('an unidentified drive carries a null dev', ($offNone[0]['dev'] ?? 'x') === null);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/ajax_render_test.php`
Expected: FAIL on `the Drives table has a Diagnose column` and the four checks after it.

- [ ] **Step 3: Implement the Drives column**

In `render/drives.php`, add above `renderDrivesTables()`:

```php
/* The Diagnose cell for one drive row. Offered only where a /dev name
   resolved: the job is scoped to a block device and there is nothing to
   diagnose without one, so the cell says why rather than presenting a button
   that cannot work -- the same choice $locCell makes for a missing SCSI
   address.
   The BARE name is passed, never the /dev path: diagnose.php validates
   /^[a-z0-9]{2,32}$/ and would refuse the path. One spelling, both sides. */
function diag_cell(array $d, array $devBySerial): string {
    $dev = drive_dev_name($d, $devBySerial);
    if ($dev === null) {
        return '<span class="lu-muted" role="img" aria-label="No device name for this drive"'
             . ' title="No device name for this drive">—</span>';
    }
    $bare = preg_replace('~^/dev/~', '', $dev);
    /* The name came from lsblk or from the sysfs join, so it cannot carry a
       quote -- htmlspecialchars is the belt, and the alnum filter is the
       braces: anything else would be refused server-side anyway, and a button
       that posts a value the server rejects is worse than no button. */
    if (!preg_match('/^[a-z0-9]{2,32}\z/', (string) $bare)) {
        return '<span class="lu-muted" role="img" aria-label="Unrecognised device name"'
             . ' title="Unrecognised device name">—</span>';
    }
    return sprintf('<button class="lu-refresh-btn" onclick="luDiagnose(\'%s\')">Diagnose</button>',
                   htmlspecialchars((string) $bare, ENT_QUOTES));
}
```

In both branches of `renderDrivesTables()`, append `diag_cell($d, $devBySerial)` as the last cell of each `$rows[]` entry and `'Diagnose'` as the last column header — so the storcli header becomes `['Device', 'Unraid', 'Encl:Slot', 'Port', 'Model', 'Serial', 'State', 'Size', 'SAS Address', 'Link', 'Firmware', 'SMART', 'Locate', 'Diagnose']` and the lsiutil one `['Device', 'Unraid', 'Bus:Tgt', 'Port', 'SAS Address', 'Locate', 'Diagnose']`.

- [ ] **Step 4: Implement the Top Offenders entry point**

In `render/phy.php`, inside `phy_top_offenders()`'s `$rows[] = [...]`, add one key:

```php
            /* The bare device name, for the Diagnose button. Separate from
               'drive' on purpose: that one is a LABEL ("8:1 · /dev/sdf") built
               for reading, and handing a label to an endpoint that validates
               /^[a-z0-9]{2,32}$/ would refuse every row. Null, never a guess --
               the value becomes an argument to a script running as root. */
            'dev'        => (function () use ($drives, $p, $devBySerial): ?string {
                $d = phy_drive($drives, $p);
                if ($d === null) return null;
                $n = drive_dev_name($d, $devBySerial);
                if ($n === null) return null;
                $bare = (string) preg_replace('~^/dev/~', '', $n);
                return preg_match('/^[a-z0-9]{2,32}\z/', $bare) ? $bare : null;
            })(),
```

and in the top-offenders rendering block, append a fifth cell to each row:

```php
                        $o['dev'] !== null
                            ? '<button class="lu-refresh-btn" onclick="luDiagnose(\''
                              . htmlspecialchars($o['dev'], ENT_QUOTES) . '\')">Diagnose</button>'
                            : '<span class="lu-muted" role="img" aria-label="Drive not identified"'
                              . ' title="Drive not identified">—</span>',
```

with `'Diagnose'` appended to that table's header array.

- [ ] **Step 5: Run the test and the suite**

Run: `php tests/ajax_render_test.php`
Expected: every check PASSes, including the pre-existing ones — the new column is appended, so no existing assertion about a header or a cell moves.

Run: `bash tests/run.sh`
Expected: `--- all pass ---`. **If a golden in `tests/expected/` moves here, stop**: no Overview golden renders either of these two tables, so a moved golden means something else changed and that is a finding, not a regeneration.

- [ ] **Step 6: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/render/drives.php \
        source/usr/local/emhttp/plugins/hbaviewer/render/phy.php tests/ajax_render_test.php
git commit -m "Add Diagnose entry points to the Drives rows and the PHY Top Offenders list. Both pass the bare device name the endpoint validates, and both say why rather than offering a button where no device resolved."
```

---

### Task 16: Docs, and the hardware verification that gates the merge

Everything in this plan is fixture- and stub-tested. Four properties can only be observed on a real box behind a real HBA, and one open item from the spec is still unconfirmed. **This task keeps the branch open until that output comes back.** "Couldn't verify" is not "verified."

**Files:**
- Modify: `ARCHITECTURE.md`
- Modify: `HOWTO.md`
- Modify: `docs/superpowers/plans/2026-09-21-disk-utility-diagnose.md` (this file — its status header)
- Possibly modify: `tests/fixtures/kmsg_medium.txt`, `source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh`

**Interfaces:** none. This task changes documentation and either confirms or corrects `SG_MIN_VER`.

- [ ] **Step 1: Document the feature in `HOWTO.md`**

Add a `## Diagnose` section covering: what the tab does, that every operation is a read, that a job survives closing the tab, that Cancel kills the whole job, that Pause freezes the *view* and not the job, that standby drives are left asleep, and that a first run only establishes a baseline so the second run is the one with meaningful deltas. State plainly that repair is not in this release.

- [ ] **Step 2: Record the sharp edges in `ARCHITECTURE.md`**

Append to **Where the sharp edges are**:

```markdown
- **An SSE stream holds a php-fpm worker for as long as it is open.** A surface
  scan runs for hours, so `diagnose_stream.php` ends its response after
  `DIAG_SSE_MAX_SECS` (55) and lets `EventSource` reconnect, carrying the byte
  offset back as `Last-Event-ID`. The resume the design needs anyway is what
  makes the bound free — and an unbounded version is the same shape as the
  incident `docs/foreground-reads.md` was written after.
- **`diagnose_lib.php` exists because a dispatch executes on `require`.**
  `diagnose_stream.php` needs four of `diagnose.php`'s pure helpers, and
  requiring that file under a web SAPI runs its dispatch, which answers the
  request with a 400 before the stream writes a byte. The pure half therefore
  lives in a file with no dispatch at all.
- **The Diagnose lock is per DISK, not per job.** The Tier 1 gate is "one job
  per disk at a time", and a lock named for the job cannot express it — two
  jobs on one disk would take two different locks and both win.
- **Cancel signals the process GROUP, negative pid.** The engine runs under
  `setsid` so it leads its own group, and it spawns `sg_verify`/`sg_read`
  children that hold the disk open. `diag_pgid()` refuses 0 and 1 explicitly:
  `kill -0` signals the caller's own process group — the php-fpm pool — and
  `kill -1` signals everything the user can reach, and both are what a
  truncated pgid file most easily produces.
- **`-n standby` is absolute on the Diagnose and disk-alert paths**, and that is
  deliberately stricter than `scripts/read_smart.sh`, which skips the flag on
  the SAS bus because a log-page read is electronics-only. The exception is not
  extended: a guard that holds only on the probe is a guard the next edit
  removes without noticing. Asserted, with a mutation check, in
  `tests/drive_triage_test.sh`.
```

- [ ] **Step 3: Post the hardware verification block**

This runs on the box, not in the sandbox. **What the output must show for this step to pass** is stated per command; post one block, wait for the output, then post the next.

Block A — the sg3_utils version, which is the spec's open item:

```bash
sg_verify --version 2>&1 | head -2
smartctl --version | head -1
```

Pass condition: `sg_verify` reports a version **at or above 1.42**. If it reports lower, `SG_MIN_VER` in `drive_triage.sh` is wrong for this fleet — lower it to the reported version and re-run `bash tests/run.sh`, whose sg-gate cases will then need their stubbed versions adjusted below the new floor. If `sg_verify` is absent entirely, the engine's existing `HAVE_SG` path already degrades and the surface-chunk gate is moot.

Block B — a real `/dev/kmsg` capture to replace the hand-written fixture:

```bash
dd if=/dev/kmsg iflag=nonblock bs=64k 2>/dev/null | head -60
```

Pass condition: real records in `<prio>,<seq>,<ts>,<flag>;<msg>` shape. Mask serials length-preservingly, replace `tests/fixtures/kmsg_medium.txt`, regenerate the three `kmsg_*` goldens by name (never `UPDATE=1`), and confirm `max_seq` still equals the highest sequence in the file. If the capture holds no `critical medium error` line, keep the hand-written lines for those two records and splice the real surrounding records around them — say so in the fixture's first comment line.

Block C — the job survives the tab, and Cancel kills the group:

```bash
# start a job through the endpoint, then close the browser tab
pgrep -a -f drive_triage.sh
ps -o pid,pgid,stat,cmd -p "$(pgrep -f drive_triage.sh | head -1)"
ls -l /tmp/hbaviewer/jobs/*/
```

Pass condition: `drive_triage.sh` is still running after the tab closed; its `PGID` equals its own `PID` (proof `setsid` made it a group leader, which is what makes group-cancel work); the job directory holds `events.ndjson`, `pgid` and a growing `job.log`; and `/tmp/hbaviewer/jobs/<disk>.lock` exists.

Block D — Cancel really takes the children:

```bash
# press Cancel in the UI, then immediately:
pgrep -a -f 'drive_triage.sh|sg_verify|sg_read'
ls /tmp/hbaviewer/jobs/*.lock 2>&1
```

Pass condition: **no** `drive_triage.sh`, `sg_verify` or `sg_read` process remains, and the lock file is gone. A surviving `sg_verify` means the signal went to the parent only.

Block E — resume, not restart:

```bash
# with a job running: note the event count, reload the Monitor tab, then:
wc -l /tmp/hbaviewer/jobs/*/events.ndjson
```

Pass condition: the file keeps growing monotonically across the reload, and the Live Job screen's surface map still shows the cells from before the reload rather than starting empty. A map that starts over means the offset resume is not working.

Block F — a standby drive stays asleep:

```bash
# pick a spun-down disk, note its state, run a Tier 0 pass, check again:
smartctl -n standby -i /dev/sdX | grep -i 'standby\|sleep\|power mode'
hdparm -C /dev/sdX
```

Pass condition: `hdparm -C` reports `standby` **both** before and after. A disk that reads `active/idle` afterwards means something in the path dropped the guard.

- [ ] **Step 4: Close the task**

When every block above has come back passing, add the status header to the top of this plan file, immediately below the `#` heading, matching the precedent in `2026-08-22-dashboard-blocking-read.md`:

```markdown
> **Status: COMPLETE.** All 16 tasks. Hardware verification blocks A–F confirmed on <box>, <date>. `sg_verify` reported <version>.
```

- [ ] **Step 5: Commit**

```bash
git add ARCHITECTURE.md HOWTO.md docs/superpowers/plans/2026-09-21-disk-utility-diagnose.md \
        tests/fixtures/kmsg_medium.txt tests/expected/kmsg_medium.json \
        tests/expected/kmsg_since.json tests/expected/kmsg_empty.json
git commit -m "Document the Diagnose feature and its sharp edges, and record the hardware verification. Replaces the hand-written kmsg fixture with a real capture."
```

---

## Notes and open items

**The source scripts' `.sh` / `.txt` pairs both matched.** `drive_triage.sh` vs `drive_triage.txt` (794 lines each) and `sas_error_monitor.sh` vs `sas_error_monitor.txt` (146 lines each) are byte-identical — `diff` produced no output for either pair. There is nothing in the `.txt` copies the `.sh` copies lack, so no discrepancy needed flagging and Task 1 copies the `.sh` as instructed.

**The spec's `sg3_utils` open item is resolved as graceful degradation, not as a hard gate.** Task 3 sets `SG_MIN_VER="1.42"` and falls back to the existing 2048-block chunk when the tool reports less or will not report at all, so an old box scans at today's speed rather than refusing. Task 16 Block A confirms the real version and says what to change if the floor is wrong. It is tracked against `sg_verify --version` — the tool's own answer — rather than against the chipset table, because the chunk size is a property of `sg3_utils` and not of the controller; the chipset table would be the right home only if the limit turned out to be per-HBA, which Block A is what would reveal.

**Pause is a view control, and the UI says so.** The spec's header strip lists Pause/Resume, but Phase 1 has no way to suspend an `sg_verify` mid-command, and a button that says "paused" over a disk still being read is worse than no button. `luDiagPause()` freezes the rendering, keeps the offset, and logs "view paused — the job keeps running". If genuine job suspension is wanted it is `SIGSTOP`/`SIGCONT` on the process group, which is a mutating-adjacent signal decision and belongs in its own task, not folded in here.

**The new-job sidebar panel selects an operation but does not queue a second job.** Phase 1's gate is one job per disk at a time, so "queue next" would need a queue, which nothing else in this plugin has. The panel's select and the standby toggle are wired to the *next* `luDiagnose()` call; a real queue is out of scope and is not implied anywhere in the UI.

**The Verdict screen's topology diagram is rendered as a shared-path list, not as a diagram.** `renderDiagVerdict()`'s `ports` section names which drives share an expander address, which is the fact the spec wants from it (isolating a single-drive fault from a shared-path fault). An SVG of the topology would need the expander tree the storcli2 backend does not report at all (`ARCHITECTURE.md`: `storcli2_overview.sh` omits `topology`), so it would render on one backend and not the other. `diagnose.php`'s `verdict` action currently passes `'ports' => []`, which renders the direct-attached message; populating it from `get_attached_drives.sh`'s `expander` field is a clean follow-up and is deliberately not in this plan.
