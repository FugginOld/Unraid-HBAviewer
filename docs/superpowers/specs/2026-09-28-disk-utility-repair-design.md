# Disk Utility — Phase 2a: Repair evidence (read-only) — design

Source: `disk-utility-implementation.md` §3 (Tier 2), §5.3 (`Repair.dc.html` mockup), §6 (open
items). Parent: `docs/superpowers/specs/2026-09-21-disk-utility-diagnose-design.md`, which framed
three phases — Phase 1 (Diagnose, shipped), Phase 2 (Tier 2 targeted repair), Phase 3 (Tier 3
destructive). Phase 2 itself splits in two:

- **Phase 2a (this spec)** — the Repair screen as read-only evidence: verdict-driven guidance for
  array disks, a confirmed-bad-block table for unassigned disks, and the new cross-run tracking
  that table needs. No new mutating path. No new opt-in toggle.
- **Phase 2b (future, not this spec)** — the guarded execution flow on top of Phase 2a's table:
  `sg_reassign` / `hdparm --write-sector`, typed-serial confirmation, the preflight gate, the new
  Settings → Advanced toggle, the `sg_reassign` chipset-variance question, the `sg3_utils` version
  floor for the reassign path specifically. None of these are decided here; they are explicitly
  out of scope and listed again under "Deferred to Phase 2b" below so they are not silently
  reopened piecemeal.

Phase 2a ships no new mutating path — same footing as Phase 1, outside `docs/review-policy.md`'s
mutating-path scrutiny. Phase 2b will not be.

## Why this split

The proposal's Tier 2 mockup is one screen with both halves — evidence and the interactive
reassign flow — together. Building the interactive half (checklist selection, typed-serial field,
disabled-until-match button) with no real execution behind it yet reproduces a defect this
project has already made and flagged once: `#diag-op`/`#diag-standby` in the Diagnose "queue a
test" panel are controls that visibly exist and silently do nothing. A second set of dead-looking
controls is not a smaller version of Phase 2b, it is the same mistake with a different name. Phase
2a is therefore scoped to exactly the read-only evidence a later Phase 2b will need, with no
control that isn't wired to something real.

## The new piece: a cross-run bad-range ledger

Nothing today persists "this LBA range has failed VERIFY before." `drive_triage.sh`'s
`ranges-$dev.txt` is written fresh into each run's own stamped subdirectory
(`$OUTDIR/<run-id>/ranges-$dev.txt`) from that run's own dmesg harvest, and `KEEP_RUNS` trims old
run directories — so even if two runs both flagged the same physical defect, nothing today can say
they're the same range, and the evidence would vanish once the older run's directory is trimmed.

**New file, one per disk:** `<DIAG_ROOT>/<disk>.badranges.tsv`, written by `drive_triage.sh`
itself — the same file that already owns `<disk>.baseline.tsv` (`diag_baseline_path()`) and is the
one place that already understands chunk boundaries. This is deliberately the engine's own state,
not something `diagnose.php` derives or writes, so CLI and User Scripts callers populate it
identically to the web path — the same reason baseline tracking lives there and not in PHP.

**Format**, tab-separated, one row per confirmed-or-candidate chunk:

```
chunk_start  confirm_count  first_run_id  last_run_id  last_evidence  updated_ts
```

- `chunk_start` — the failing range's start LBA, rounded down to its containing chunk. Chunk size
  is the existing `CHUNK="2048"` constant (`drive_triage.sh`'s targeted re-test chunk) — matching
  the granularity the engine already re-tests at, and the granularity a future `sg_reassign` call
  operates on, rather than inventing a second chunk size. Rounding, not exact-match, because two
  runs' own dmesg harvests are not guaranteed to report byte-identical start offsets for the same
  physical defect.
- `confirm_count` — how many *distinct* runs have flagged this chunk. Phase 2a's "confirmed" bar
  for display is `confirm_count >= 2` (the proposal's own stated precondition).
- `first_run_id` / `last_run_id` — the job-id string of the earliest and most recent run that
  flagged this chunk. Kept as two scalars, not a list, on purpose (see "Forward path to a full
  audit trail" below).
- `last_evidence` — `vfail`, `rfail`, or `both`, from that chunk's most recent triage. Enough for
  Phase 2a's table; not a substitute for the Verdict screen's own detailed per-range evidence,
  which stays where it is.
- `updated_ts` — unix seconds, last write.

**Update logic**, at the same point `drive_triage.sh` already writes `$STATE` (end of a run, after
`triage_disk()` has populated `$RUN/ranges-$dev.txt` candidates for the *next* run — read the
existing code path closely here, this is describing where in the script the write happens, not
introducing a new phase): for each range in this run's own failure set, compute its chunk_start,
and either bump the matching row's `confirm_count`/`last_run_id`/`last_evidence`/`updated_ts`, or
insert a new row at `confirm_count = 1`. A chunk that has stopped failing is not removed — a
disk whose defects "stopped climbing between runs" (the proposal's own "watch" case) is a real,
useful state to show, and silently deleting the row would erase exactly the evidence that
distinction needs.

**New flag**, mirroring `--state`: `--badrange-state <path>`. Empty/unset by default, matching the
existing `EVENTS=""` / no-`--state` convention that keeps CLI and User Scripts behavior unchanged
when the flag isn't passed. `diagnose.php`'s launcher passes a fixed path from a new
`diag_badrange_path($disk)` helper in `diagnose_lib.php`, same shape as `diag_baseline_path()`.

**Reset**: tied to the existing `--reset-baseline` flag, not a second mechanism. A disk swap or a
full rebuild invalidates old bad-range evidence for exactly the same reason it invalidates the
baseline — one physical-drive-identity reset clears both files together.

### Forward path to a full audit trail

You said you'll want the fuller picture later (every failed chunk, every run, not just a tally).
This shape is deliberately compatible with that without a rewrite: `first_run_id`/`last_run_id`
already exist as separate columns rather than being collapsed into one, and a later addition can
introduce a companion per-chunk history file (or a `run_ids` column holding a bounded list) that
the tally file's own read/write logic doesn't need to change to accommodate — the tally stays the
fast-path summary the Repair screen actually needs, and a history file/table becomes an additive
lookup for whoever wants drill-down. Not built now; noted so Phase 2b doesn't have to touch this
file's shape to add it.

## The Repair screen

Third screen in the same Diagnose tab, alongside Live Job and Verdict — same screen-chaining
pattern (`luDiagShow`/`luTab('diagnose')`) already used for Live→Verdict. Entry point: a "Repair"
link on the Verdict screen, shown only when the verdict is not `CLEAN`.

**Array (assigned) disk:** a blocked-action explanation ("direct sector repair isn't offered for
array disks — it bypasses parity") followed by the three safe options as plain text: replace,
rebuild onto itself, or keep in service and watch. All three are always shown together, as options
to weigh rather than a recommendation the screen picks for you — trying to conditionally suppress
"watch" based on whether the defect count has "stopped climbing" would need a new trend signal this
spec hasn't designed (the ledger tracks confirmed chunks, not a per-run history of the total count),
and inventing one here is exactly the kind of scope creep Phase 2a is trying to avoid. Reuses the
`$arrayDisk` boolean the `verdict` action already computes from `disks.ini` — extracted into a
small shared helper so the two call sites cannot disagree, not duplicated.

**Unassigned disk:** a plain table of every `confirm_count >= 2` row from that disk's
`badranges.tsv` — start LBA, block count (fixed at the chunk size), confirm count, last evidence,
last-seen run. **No checkboxes, no typed-confirmation field, no action button.** This is the whole
scope cut from the mockup described above.

**Empty state:** an unassigned disk with no confirmed rows (a MEDIA/TRANSPORT verdict from a
single run, `confirm_count` still at 1) shows a plain note that repair evidence needs a second
confirming run, with a pointer back to the Diagnose tab to run one — not a blank screen, and not a
false "no issues" message.

## Surface area

- `drive_triage.sh`: new `--badrange-state <path>` flag; the ledger read/update logic at the
  existing end-of-run write point; `--reset-baseline` also clears the badrange file when a path is
  given.
- `diagnose_lib.php`: `diag_badrange_path($disk)` (mirrors `diag_baseline_path()`); a small
  `diag_array_disk($disk)` helper extracted from `verdict`'s existing inline `disks.ini` check, so
  `verdict` and the new `repair` action share one implementation.
- `diagnose.php`: new `action=repair` — disk-scoped (takes `disk`, not `job`, since this reads
  ledger state rather than one job's event file), pure PHP, no shell-out (it only reads a TSV and
  `disks.ini`).
- `render/diagnose.php`: `renderDiagRepair()`, same "PHP turns data into HTML fragment" shape as
  `renderDiagVerdict()`.
- `diagnose_view.js`: `luDiagRepair()` navigation function and the Verdict screen's new "Repair"
  link, wired the same way `luDiagOpen()` already switches screens.
- Docs: `HOWTO.md`'s Diagnose section currently states plainly "repair is not in this release" —
  narrow that to "repair *execution*"; document the new Repair screen and what "confirmed" means.
  `ARCHITECTURE.md` gains a `badranges.tsv` line next to the existing `baseline.tsv` description in
  "Where the sharp edges are"-adjacent documentation of `diagnose.php`'s state files.

## Error handling

- Missing `badranges.tsv` (no bad-range history yet, or `RESET_BASELINE` just ran): treated as zero
  rows, not an error — the empty state above.
- A malformed row (wrong column count, non-numeric `chunk_start`): skipped, not fatal — matches
  this codebase's existing convention of failing individual rows closed rather than the whole read
  (see `diag_slice()`'s own handling of a partial trailing line).
- Invalid or non-existent disk name on the `repair` action: reuses `diag_disk_valid()` /
  `diag_disk_valid() && is_file("/sys/block/$disk/dev")`, same as every other `diagnose.php`
  action — one validator, not a second one for this endpoint.

## Testing

- `tests/drive_triage_test.sh`: new golden cases for the ledger — a fresh run creates a row at
  count 1; a second run on the same chunk (allowing for a slightly different start offset within
  the same 2048-block window) increments to 2 and updates `last_run_id`/`last_evidence`; a chunk
  that stops failing keeps its row unchanged rather than being removed; `--reset-baseline` with
  `--badrange-state` given clears it; the flag being absent leaves behavior byte-identical to
  today (regression guard, matching the precedent set for `--state`'s own backward-compatibility
  test in the Task 16 baseline-persistence fix round).
- `tests/diagnose_test.php`: `diag_badrange_path()` and the extracted `diag_array_disk()` helper,
  directly callable, no mocks needed (same style as the existing `diag_baseline_path()`/
  `diag_slice()` tests).
- `tests/diagnose_php_test.php`: source-assertion pins for the new `repair` action's dispatch
  shape and its reuse of `diag_array_disk()` (not a re-implementation), same style as the existing
  pins on `diagnose.php`'s other actions.
- `tests/diagnose_render_test.php`: `renderDiagRepair()`'s three states (array-disk guidance,
  unassigned-disk table, empty state) with injected data — no real disk, no real job, matching
  `renderDiagVerdict()`'s existing test shape.
- `tests/diagnose_js_test.js`: the new Verdict-screen "Repair" link's visibility rule (shown only
  on a non-CLEAN verdict) and `luDiagRepair()`'s navigation call.
- Hardware verification: a real cross-run ledger update (run Diagnose twice on a disk with a real
  flagged range, confirm `confirm_count` reaches 2 and the Repair screen's table shows it) — same
  "Commands I run myself" treatment Task 16 gave Phase 1, since a ledger written by a real
  `drive_triage.sh` run against real hardware is the only thing that actually proves the chunk-
  rounding tolerance is right for this fleet's dmesg harvest.

## Deferred to Phase 2b (explicitly not decided here)

- The guarded execution flow: `sg_reassign` (SAS) / `hdparm --write-sector` (SATA), the preflight
  gate (not assigned, not mounted, no other job running, every selected LBA at `confirm_count >=
  2`), typed-serial confirmation, the mutating endpoint itself.
- Whether `sg_reassign` support varies meaningfully across the SAS2/SAS3/SAS3.5 chipset table, or
  gates purely on `sg3_utils` presence.
- The new Settings → Advanced opt-in toggle for Tier 2 (separate toggle vs. riding on the existing
  firmware-flash toggle).
- The minimum `sg3_utils` version needed specifically for `sg_reassign` (distinct from `SG_MIN_VER`,
  which gates the surface-scan `sg_verify`/`sg_read` chunk size and was already confirmed at
  `1.30` in Task 16 Block A — that confirmation says nothing about `sg_reassign` support).
- The full per-run audit trail noted above as a forward-compatible but unbuilt extension.

## Out of scope (this phase and the next)

- `array_rebalance.sh` — excluded from this plugin entirely, all phases, per the original proposal.
- Tier 3 destructive operations (`badblocks -w`, `sg_format`, `sg_sanitize`) — Phase 3, not touched
  by this spec.
