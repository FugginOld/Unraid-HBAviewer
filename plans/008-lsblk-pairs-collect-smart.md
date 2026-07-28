# Plan 008: Parse lsblk output by key, not by column position

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 0346777..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh`
> If that file changed since this plan was written, compare the "Current state"
> excerpt against the live code before proceeding; on a mismatch, treat it as a
> STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `0346777`, 2026-07-26

## Execution record

**Status: DONE — merged to `dev`. Ships in the next release.**

| Field | Value |
| ----- | ----- |
| Executed | 2026-07-27, by a dispatched executor subagent in an isolated worktree |
| Commit | `52982ee` — "Parse lsblk output by key so a blank serial can't shift the model" |
| Merged | `a6caee5` into `dev` |
| Diff | 1 file, 12 insertions, 2 deletions — `collect_smart.sh` only |

**Review findings (verified independently, not taken from the executor's report):**

The executor's environment had no `lsblk`, so the reviewer tested the **shipped
`kv()` function** — extracted from the committed file rather than
reimplemented — against a fixture containing the failing case:

```text
name=[sdb] serial=[ZA1ABCDE] model=[ST8000NM0055-1RM]
name=[sdc] serial=[]         model=[WD80EFAX-68LHPN0]      <- blank serial, model intact
name=[sde] serial=[K1234567] model=[HGST HUH721010AL4200]  <- embedded space preserved
```

Three rows; the WWN-less USB row correctly excluded, so the boot flash stays out
of the SMART table. The same row through the **old** positional read still
produces `serial=[WD80EFAX-68LHPN0] model=[]` — the defect this fixes.

All five done criteria pass, the `ponytail:` JSON-escaping note survives above
the hunk, `bash -n` clean across all shell files, `bash tests/run.sh` exits 0.

**A done criterion was fixed *before* dispatch, not after.** The WWN filter check
was written double-quoted with backslash escapes, which the shell unescapes into
a pattern containing a literal backslash — it matches nothing and always prints
`0`. Caught by pre-testing every criterion against a simulated post-fix file, and
corrected in `a026440` before the executor ran. The four preceding plans each
surfaced a bad criterion only at execution time; this one cost nothing.

**Two limits on the verification:**

- **Not tested against real `lsblk` output.** The `-P` format used in the fixture
  is reconstructed. Running `lsblk -S -P -o NAME,WWN,SERIAL,MODEL` on an Unraid
  box would confirm it in seconds.
- **The original bug may not be reproducible on a given machine** — it needs a
  drive that reports a blank serial. Without one you can confirm the rewrite
  still works, but not that it fixed anything observable.

## Why this matters

The background SMART collector reads four whitespace-separated `lsblk` columns
positionally. When a drive reports an **empty serial**, that column collapses and
every later field shifts left by one: the model string lands in the serial
variable and the model comes out empty.

This was reproduced directly while writing this plan. Feeding the positional
reader a row for a drive with no serial:

```
input:   sdc 0x5000c500deadbeef  WD80EFAX-68LHPN0
result:  serial=[WD80EFAX-68LHPN0]  model=[]
```

The consequence in the UI is a SMART table row showing the model number in the
Serial column and a dash under Model. It is cosmetic, it affects only drives
that do not report a serial, and it does not corrupt any stored data — hence the
low priority. But it is a real wrong answer on a diagnostic screen, and drives
that fail to report a serial are disproportionately the odd ones a user is
looking at the SMART tab *about*.

`lsblk` has a machine-readable output mode (`-P`, key/value pairs) that removes
the ambiguity entirely. This plan switches to it.

## Current state

File involved:

- `source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh` — the
  detached SMART collector. 36 lines total.

The whole file as it exists today, so you can see the structure you are editing:

```bash
#!/bin/bash
# Background SMART collector. smartctl is slow (~1s/drive) and this walks every
# HBA disk, so it's meant to be launched detached (nohup ... &) by the SMART tab
# endpoint; the tab polls the cache + progress file while this runs.
#
#   /tmp/lsiutil_smart.json           final cache  {"drives":[{dev,serial,model,smart}]}
#   /tmp/lsiutil_smart.json.progress  "12/24" while running (removed when done)
#
# -n standby: a sleeping drive is reported as such, never woken.
# ponytail: model/serial are alnum(+space); emitted into JSON without escaping.
# Add escaping if a drive ever ships a quote/backslash in those fields.

DIR="$(dirname "$0")"
OUT="${LSI_SMART_CACHE:-/tmp/lsiutil_smart.json}"
PROG="$OUT.progress"
TMP="$OUT.tmp"

# HBA disks = SCSI block devices with a WWN (excludes USB sticks / no-WWN).
total=$(lsblk -S -o NAME,WWN -n 2>/dev/null | awk '$2 ~ /^0x/' | wc -l)

printf '{"drives":[' > "$TMP"
i=0
first=1
lsblk -S -o NAME,WWN,SERIAL,MODEL -n 2>/dev/null | awk '$2 ~ /^0x/' | while read -r name wwn serial model; do
    i=$(( i + 1 )); echo "$i/$total" > "$PROG"
    smart=$(bash "$DIR/read_smart.sh" "/dev/$name")
    [ -n "$smart" ] || smart='{}'
    [ "$first" -eq 1 ] || printf ',' >> "$TMP"
    first=0
    printf '{"dev":"/dev/%s","serial":"%s","model":"%s","smart":%s}' \
        "$name" "$serial" "$model" "$smart" >> "$TMP"
done
printf ']}' >> "$TMP"

mv -f "$TMP" "$OUT"
rm -f "$PROG"
```

The defect is the `read -r name wwn serial model` on line 24, combined with
`lsblk -n`'s space-padded columns.

**Why `-P` is the right instrument.** With `-P`, `lsblk` emits one
`KEY="value"` pair per field, quoted, so an empty field is unambiguous:

```
NAME="sdb" WWN="0x5000c500a1b2c3d4" SERIAL="ZA1ABCDE" MODEL="ST8000NM0055-1RM"
NAME="sdc" WWN="0x5000c500deadbeef" SERIAL="" MODEL="WD80EFAX-68LHPN0"
NAME="sdd" WWN="" SERIAL="USB123" MODEL="SanDisk Cruzer"
NAME="sde" WWN="0x5000cca2712a4b3c" SERIAL="K1234567" MODEL="HGST HUH721010AL4200"
```

The replacement code below was verified against exactly that sample. It reads
`sdc`'s serial as empty and its model as `WD80EFAX-68LHPN0` (correct), keeps
spaces inside `HGST HUH721010AL4200` intact, and still excludes `sdd`, the
WWN-less USB stick.

**Two properties of the current code that must be preserved:**

1. **The WWN filter is the HBA-disk selector.** `awk '$2 ~ /^0x/'` keeps only
   devices with a WWN, which is how USB sticks are excluded. The `-P` equivalent
   is a match on `WWN="0x`. Losing this would put the user's boot flash drive in
   the SMART table.
2. **The `while` loop runs in a pipeline subshell.** `i` and `first` are updated
   inside it and never read afterwards, and all output is appended to `$TMP`
   rather than echoed. That structure works and must stay — do not "fix" it into
   a process-substitution loop, which would change nothing useful and risks
   breaking the append ordering.

**Repo conventions that apply here:**

- Shell scripts start with `#!/bin/bash` and a header comment saying what the
  script produces and what its side files are. See the exemplar this file
  already follows, and `source/usr/local/emhttp/plugins/hbaviewer/scripts/read_smart.sh`
  for a compact one.
- Deliberate simplifications carry a `ponytail:` comment naming the ceiling and
  the upgrade path — this file already has one at lines 10–11 about JSON
  escaping. Leave it in place; it describes a different, still-open issue.
- Small helper functions are defined inline in the script that uses them — see
  `phy_sum()` in `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_metrics.sh:59-69`.

## Commands you will need

| Purpose         | Command                                                              | Expected on success        |
|-----------------|----------------------------------------------------------------------|----------------------------|
| Shell lint      | `bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh` | exit 0             |
| Lint everything | `find source tests -name '*.sh' -print0 \| xargs -0 -r -n1 bash -n`  | exit 0                     |
| Full test suite | `bash tests/run.sh`                                                  | `--- all pass ---`, exit 0 |
| Check lsblk -P  | `lsblk -S -P -o NAME,WWN,SERIAL,MODEL \| head -3`                    | `KEY="value"` pairs        |

`lsblk -P` has been in util-linux for many years and is present on Unraid's
Slackware base. Step 1 verifies it on your machine before you depend on it.

## Scope

**In scope**:

- `source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh`

**Out of scope** (do NOT touch, even though they look related):

- The JSON escaping deferral at `collect_smart.sh:10-11`. A drive model
  containing `"` or `\` still produces malformed JSON. That is a real, separate
  bug — deliberately marked as a known shortcut in this repo's own convention —
  and fixing it means changing how the shell emits JSON, which wants its own
  plan and its own golden tests. Leave the `ponytail:` comment exactly where it
  is; it documents the still-open issue.
- `source/usr/local/emhttp/plugins/hbaviewer/scripts/read_smart.sh` — it takes a
  `/dev/X` path and is unaffected.
- `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` and its
  `renderSmartTable()` — the consumer is correct; it renders whatever the
  collector wrote.
- The progress-file handling. That is `plans/002-smart-tab-stale-progress.md`.
  If 002 has already landed, this plan does not conflict with it — the two touch
  different files.
- Other `lsblk` call sites: `ajax_info.php:64` (`lsblk -S -o NAME,SERIAL -n`,
  filtered by an exact `awk` match on the serial, so a blank column cannot
  mis-attribute) and `read_smart.sh:13` (`lsblk -dno TRAN`, one field). Both are
  safe from this defect. Leave them.

## Git workflow

- Branch: `advisor/008-lsblk-pairs-collect-smart`
- One commit. Message style matches this repo's history — short imperative
  subject, no conventional-commit prefix. Suggested:
  `Parse lsblk output by key so a blank serial can't shift the model`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Confirm `lsblk -P` behaves as expected on this machine

```bash
lsblk -S -P -o NAME,WWN,SERIAL,MODEL | head -5
```

**Verify**: output is `KEY="value"` pairs, one line per SCSI device.

If your machine has no SCSI devices the output will be empty — that is fine and
does not block the plan, but note it in your report, because it means you cannot
do the end-to-end check in Step 4 and must rely on the fixture check in Step 3.

If `lsblk` rejects `-P`, STOP and report — the whole approach depends on it.

### Step 2: Rewrite the collector's device loop

Replace `collect_smart.sh:18-32` (from the `# HBA disks = ...` comment through
the `done`) with:

```bash
# HBA disks = SCSI block devices with a WWN (excludes USB sticks / no-WWN).
# -P (key="value" pairs) not positional columns: a drive with an empty SERIAL
# collapses its column in the padded output, which silently shifted MODEL into
# the serial field and left the model blank.
kv() {   # $1 = lsblk -P line, $2 = key -> the unquoted value
    printf '%s\n' "$1" | sed -n "s/.*\b$2=\"\([^\"]*\)\".*/\1/p"
}

total=$(lsblk -S -P -o NAME,WWN 2>/dev/null | grep -c 'WWN="0x')

printf '{"drives":[' > "$TMP"
i=0
first=1
lsblk -S -P -o NAME,WWN,SERIAL,MODEL 2>/dev/null | grep 'WWN="0x' | while IFS= read -r line; do
    name=$(kv "$line" NAME)
    serial=$(kv "$line" SERIAL)
    model=$(kv "$line" MODEL)
    i=$(( i + 1 )); echo "$i/$total" > "$PROG"
    smart=$(bash "$DIR/read_smart.sh" "/dev/$name")
    [ -n "$smart" ] || smart='{}'
    [ "$first" -eq 1 ] || printf ',' >> "$TMP"
    first=0
    printf '{"dev":"/dev/%s","serial":"%s","model":"%s","smart":%s}' \
        "$name" "$serial" "$model" "$smart" >> "$TMP"
done
printf ']}' >> "$TMP"
```

Four details that matter:

1. `IFS= read -r line` — reads the whole line unsplit. Without clearing `IFS`
   you are back to whitespace splitting, which is the bug.
2. `grep 'WWN="0x'` replaces `awk '$2 ~ /^0x/'` and preserves the USB exclusion.
3. `grep -c` replaces the `awk | wc -l` pair for the total; it does the same job
   in one process.
4. The `wwn` variable is no longer extracted, because nothing used it — it was
   only ever a positional placeholder. The filtering now happens in `grep`.

**Verify**: `bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh` → exit 0

**Verify**: `grep -c 'read -r name wwn serial model' source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh` → prints `0`

### Step 3: Prove the parsing against the blank-serial case

Test the `kv` helper against a fixture that includes the failing row, without
needing real hardware.

```bash
cat > /tmp/lsblk_sample.txt <<'EOF'
NAME="sdb" WWN="0x5000c500a1b2c3d4" SERIAL="ZA1ABCDE" MODEL="ST8000NM0055-1RM"
NAME="sdc" WWN="0x5000c500deadbeef" SERIAL="" MODEL="WD80EFAX-68LHPN0"
NAME="sdd" WWN="" SERIAL="USB123" MODEL="SanDisk Cruzer"
NAME="sde" WWN="0x5000cca2712a4b3c" SERIAL="K1234567" MODEL="HGST HUH721010AL4200"
EOF

kv() { printf '%s\n' "$1" | sed -n "s/.*\b$2=\"\([^\"]*\)\".*/\1/p"; }
grep 'WWN="0x' /tmp/lsblk_sample.txt | while IFS= read -r line; do
    printf 'name=[%s] serial=[%s] model=[%s]\n' \
      "$(kv "$line" NAME)" "$(kv "$line" SERIAL)" "$(kv "$line" MODEL)"
done
```

**Verify**: exactly this output, three lines:

```
name=[sdb] serial=[ZA1ABCDE] model=[ST8000NM0055-1RM]
name=[sdc] serial=[] model=[WD80EFAX-68LHPN0]
name=[sde] serial=[K1234567] model=[HGST HUH721010AL4200]
```

Three things this proves: `sdc` (blank serial) keeps its model in the right
field, `sde` keeps the space inside its model, and `sdd` (no WWN) is excluded.

**Verify**: `grep -c 'WWN="0x' /tmp/lsblk_sample.txt` → prints `3`

Clean up: `rm -f /tmp/lsblk_sample.txt`

### Step 4: End-to-end run against a temp cache

`collect_smart.sh` honours `LSI_SMART_CACHE`, so it can be run without
disturbing the real cache. This calls `smartctl` on every SCSI disk, so it takes
roughly a second per drive.

```bash
LSI_SMART_CACHE=/tmp/hbav_smart_test.json \
  bash source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh
echo "exit=$?"
cat /tmp/hbav_smart_test.json
```

**Verify**: exit 0, the file contains a JSON object starting `{"drives":[` and
ending `]}`, and no drive entry has a model string sitting in its `serial` field.

**Verify**: the progress file is cleaned up —
`test ! -f /tmp/hbav_smart_test.json.progress` → exit 0

Clean up: `rm -f /tmp/hbav_smart_test.json`

If your machine has no SCSI disks, this produces `{"drives":[]}` — that is a
valid pass for the mechanics, but say so in your report so the reviewer knows
the real-hardware path was not exercised.

### Step 5: Lint and full suite

**Verify**: `find source tests -name '*.sh' -print0 | xargs -0 -r -n1 bash -n` → exit 0

**Verify**: `bash tests/run.sh` → `--- all pass ---`, exit 0

## Test plan

**No new committed automated tests.** Be clear on why, so nobody assumes this
was an oversight: `collect_smart.sh` is a composer that shells out to `lsblk`
and `smartctl`. The repo's golden-test harness covers *pure* filters under
`scripts/parse/` that read stdin — this script reads the system. Making it
fixture-testable means extracting the drive-enumeration step into a parse
filter, which is a larger restructuring than a Low-priority field-shifting bug
justifies.

What stands in for it:

- **Step 3** is the real regression test, run by hand against a fixture that
  contains the exact failing case. Its expected output is stated exactly, so it
  is a pass/fail check rather than a judgement call. Record the result.
- **Step 4** exercises the whole script end to end against a temp cache.
- The existing suite must stay green: `bash tests/run.sh` → `--- all pass ---`.

If someone later extracts drive enumeration into
`scripts/parse/lsblk_drives.sh`, the sample from Step 3 becomes the fixture and
this gets a proper golden test. That is the upgrade path, not a requirement here.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'lsblk -S -P' source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh` prints `2`
- [ ] `grep -c 'read -r name wwn serial model' source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh` prints `0`
- [ ] `grep -c 'IFS= read -r line' source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh` prints `1`
- [ ] `grep -c 'WWN="0x' source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh` prints `2` (the count and the filter — both keep the USB exclusion)

> **Corrected before execution.** This criterion was originally written with
> double quotes and backslash escapes (`grep -c "WWN=\\\\\"0x"`), which the shell
> unescapes into a pattern containing a literal backslash — it matches nothing
> and always prints `0`. **Use single quotes**, as above: the pattern contains a
> double quote and no backslash, so single-quoting it needs no escaping at all.
> Verified against a simulated post-fix file: the single-quoted form prints `2`.
- [ ] `grep -c 'ponytail:' source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh` prints `1` — the JSON-escaping deferral note is still there
- [ ] Step 3 produced exactly the three expected lines
- [ ] `bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh` exits 0
- [ ] `find source tests -name '*.sh' -print0 | xargs -0 -r -n1 bash -n` exits 0
- [ ] `bash tests/run.sh` exits 0 and prints `--- all pass ---`
- [ ] `git status --porcelain` shows exactly one modified file: `source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh` (plus `plans/README.md`)
- [ ] `plans/README.md` status row for 008 updated

## STOP conditions

Stop and report back (do not improvise) if:

- `lsblk -S -P -o NAME,WWN,SERIAL,MODEL` errors or produces something other than
  `KEY="value"` pairs. The whole approach rests on it.
- Step 3's output differs in any way from the three expected lines — especially
  if `sdd` appears (USB exclusion broken) or `sde`'s model is truncated at the
  space (quoting broken).
- Step 4 produces JSON that fails to parse. Check with
  `php -r 'var_dump(json_decode(file_get_contents("/tmp/hbav_smart_test.json"), true) !== null);'`
  → must print `bool(true)`. If a drive on your system has a `"` in its model,
  you will have hit the **separate**, out-of-scope JSON-escaping bug documented
  at `collect_smart.sh:10-11`. Report it as a distinct finding; do not fix it here.
- You find yourself removing or editing the `ponytail:` JSON-escaping comment.
  That issue is still open and the comment must survive this change.

## Maintenance notes

- **`lsblk -P` is now the house style for parsing lsblk in this codebase.** Two
  other call sites still use positional output — `ajax_info.php:64` and
  `read_smart.sh:13` — and both are safe today: the first matches an exact
  serial with `awk` rather than reading by position, and the second requests a
  single field. If either ever grows a second optional column, it needs the same
  treatment.
- **The `kv` helper runs one `sed` per field per drive** — three processes per
  drive. That is invisible next to `smartctl`, which costs about a second per
  drive and dominates the runtime entirely. Do not optimise it.
- **Still open in this file**: model and serial are interpolated into JSON
  without escaping quotes or backslashes (`collect_smart.sh:10-11`). A drive
  reporting a `"` in its model produces malformed JSON and blanks the entire
  SMART tab — a worse failure than the one this plan fixed. It needs its own
  plan; the `ponytail:` comment is the tracking marker.
- **What a reviewer should scrutinise**: that `IFS=` is present on the `read`,
  and that the WWN filter survived the rewrite in **both** places (the count and
  the loop). Losing the filter puts the user's USB boot flash in the SMART
  table; losing `IFS=` silently reintroduces the original bug.
