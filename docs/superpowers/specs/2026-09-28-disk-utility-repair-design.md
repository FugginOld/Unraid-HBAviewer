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

**Format**, tab-separated, one row per chunk that has ever failed a check:

```
serial  chunk_start  confirm_count  first_run_id  last_run_id  last_class  updated_ts
```

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
- `last_class` — the range's class from its most recent triage, one of four:

  | class | VERIFY | READ | meaning |
  | --- | --- | --- | --- |
  | `media` | failed | any | the drive cannot read its own platters here |
  | `transport` | clean | failed | fine inside the drive, failed crossing the link |
  | `intermittent` | clean (or not run) | clean | flagged before, did not recur this run |
  | `unresolved` | not run (`HAVE_SG=0`) | failed | failed, but no VERIFY to say where |

  The same four-way split `sas-bench`'s `discriminate()` uses (`sas_test_tool.sh:468-476`), applied
  per range rather than per disk. `triage_disk()` today folds its per-range results into two
  disk-level flags (`vfail`/`rfail`); the per-range result already exists inside its range loop
  (`drive_triage.sh:725-741`) and is recorded there instead of being discarded.
- `confirm_count` — how many *distinct* runs classified this chunk `media`. **Only `media` counts.**
  A `transport` range is healthy inside the drive; remapping it would retire a good sector and
  leave the actual fault (cable, backplane, expander, HBA) in place. An `intermittent` or
  `unresolved` result is not evidence of a bad sector either. Phase 2a's "confirmed" bar is
  `confirm_count >= 2` (the proposal's own stated precondition), so a confirmed row means "failed
  VERIFY on two separate runs", which is the precondition Phase 2b's repair gate will check.
- `first_run_id` / `last_run_id` — the engine's own run stamp (`$STAMP`, the `YYYYmmdd-HHMMSS`
  name of the run's subdirectory) of the earliest and most recent run that tested this chunk — not
  the web job id, which the engine never receives and CLI runs do not have. Kept as two scalars,
  not a list, on purpose (see "Forward path to a full
  audit trail" below).
- `updated_ts` — unix seconds, last write.
- Columns past the seventh are allowed — the forward path below adds columns, not a new shape.
  The engine passes them through when it rewrites a row and the PHP reader ignores them. A row
  with fewer than seven columns or a non-numeric `chunk_start`/`confirm_count` is dropped when the
  engine rewrites the file and skipped by the reader.

**Update logic.** Inside `triage_disk()`'s range loop, each tested range's class is recorded for
this run. At the end of the run, beside the existing `$STATE` write, the ledger is updated from
that record:

- within one run, several results for the same chunk fold to the worst (`media` > `transport` >
  `unresolved` > `intermittent`) first, so a single run raises a chunk's `confirm_count` at most
  once — which is what makes it a count of *distinct* runs;
- a range whose class is `media`, `transport` or `unresolved` either updates its chunk's row
  (`last_class`, `last_run_id`, `updated_ts`, and `confirm_count + 1` only if the class is
  `media`) or inserts a new row (`confirm_count` 1 if `media`, else 0);
- a range whose class is `intermittent` updates an existing row's `last_class`/`last_run_id`/
  `updated_ts` and never inserts one. In particular the no-evidence fallback (`0:256` spot-check,
  `drive_triage.sh:715-720`) writes nothing when it comes back clean.

A chunk that has stopped failing is not removed. Its row stays, its `last_class` becomes
`intermittent`, and its `confirm_count` keeps the history — silently deleting it would erase
exactly the evidence that says a defect was real on earlier runs.

**New flag**, mirroring `--state`: `--badrange-state <path>`. Empty/unset by default, matching the
existing `EVENTS=""` / no-`--state` convention that keeps CLI and User Scripts behavior unchanged
when the flag isn't passed. `diagnose.php`'s launcher passes a fixed path from a new
`diag_badrange_path($disk)` helper in `diagnose_lib.php`, same shape as `diag_baseline_path()`.

**Reset**: tied to the existing `--reset-baseline` flag, not a second mechanism. A disk swap or a
full rebuild invalidates old bad-range evidence for exactly the same reason it invalidates the
baseline — one physical-drive-identity reset clears both files together.

### Forward path to a full audit trail

A fuller picture is wanted later (every failed chunk, every run, not just a tally). This shape is deliberately compatible with that without a rewrite: `first_run_id`/`last_run_id`
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
and inventing one here is exactly the kind of scope creep Phase 2a is trying to avoid. Uses
`diag_array_disk()`, one small helper both the `verdict` and `repair` actions call, so the two
cannot disagree. It answers "assigned anywhere" — parity, parity2, any `diskN`, or a pool member —
by asking `unraid_disk_roles()`, the disks.ini reader the SMART tab, bay map and Diagnose sidebar
use; false only for a disk Unraid has not assigned, and true when `disks.ini` is unreadable. This
replaces the `verdict` action's inline `^disk\d+$` test, which classed parity and pool disks as
unassigned and gave a parity disk's MEDIA verdict the unassigned-disk advice — a Phase 1 bug fixed
here (amended 2026-09-28).

**Unassigned disk:** a plain table of every `confirm_count >= 2` row from that disk's
`badranges.tsv` — start LBA, block count (fixed at the chunk size), media-confirm count, last
class, last-seen run. **No checkboxes, no typed-confirmation field, no action button** (the screen's only button is navigation, "Back to verdict", which performs no action on the drive). This is the
whole scope cut from the mockup described above. Below the table, one line counts any rows whose
`last_class` is `transport` and whose `confirm_count` is 0 ("N ranges failed only over the link —
these point at the cable, slot or HBA, not the drive, and are not repair candidates"), so a range
the user saw flagged on the Verdict screen does not silently disappear from Repair without a
reason. A row already confirmed by two `media` runs stays in the table even if its latest run was
`transport` — it did fail VERIFY twice, so "failed only over the link" would be false for it.
(Narrowed during planning, 2026-09-28.)

**Empty state:** an unassigned disk with no confirmed rows shows a plain note that says which case
it is — no media-class range yet (a TRANSPORT verdict, or only intermittent results), or media
ranges seen on only one run so far (`confirm_count` 1, needs a second confirming run) — with a
pointer back to the Diagnose tab to run one. Not a blank screen, and not a false "no issues"
message. An unassigned disk whose serial cannot be read shows neither a table nor a ledger-derived
note: one plain line saying its bad-range history cannot be matched to it, so nothing is shown
rather than evidence that may belong to another drive (amended 2026-09-28).

## Surface area

- `drive_triage.sh`: new `--badrange-state <path>` flag; the ledger read/update logic at the
  existing end-of-run write point; `--reset-baseline` also clears the badrange file when a path is
  given.
- `diagnose_lib.php`: `diag_badrange_path($disk)` (mirrors `diag_baseline_path()`); a small
  `diag_array_disk($disk)` helper (assigned anywhere, via `unraid_disk_roles()`) replacing
  `verdict`'s inline `disks.ini` check, so `verdict` and the new `repair` action share one
  implementation; `diag_disk_serial($disk)`, the sysfs VPD 0x80 serial read; and
  `diag_badranges_read($file, $serial)`, the ledger row reader, which returns only rows filed under
  that serial and nothing when the serial is unknown.
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

- `tests/drive_triage_test.sh`: new golden cases for the ledger, driven by the existing stubbed
  `sg_verify`/`sg_read` so each class can be forced:
  - a `media` range on a fresh ledger creates a row at `confirm_count` 1; a second `media` run on
    the same chunk (with a start offset shifted inside the same 2048-block window) makes it 2 and
    updates `last_run_id`/`last_class`;
  - a `transport` range creates a row at `confirm_count` 0, and a second `transport` run leaves it
    at 0. This is the discriminating case: an implementation that counts every failure reaches 2
    here;
  - a `media` row that comes back `intermittent` keeps its `confirm_count` and changes only
    `last_class`; it is not removed;
  - a clean `0:256` spot-check on a disk with no evidence writes no row;
  - `--reset-baseline` with `--badrange-state` given clears the ledger;
  - the flag being absent leaves behavior byte-identical to today (regression guard, matching the
    precedent set for `--state`'s own backward-compatibility test in the Task 16
    baseline-persistence fix round).
- `tests/diagnose_test.php`: `diag_badrange_path()` and the extracted `diag_array_disk()` helper,
  directly callable, no mocks needed (same style as the existing `diag_baseline_path()`/
  `diag_slice()` tests).
- `tests/diagnose_php_test.php`: source-assertion pins for the new `repair` action's dispatch
  shape and its reuse of `diag_array_disk()` (not a re-implementation), same style as the existing
  pins on `diagnose.php`'s other actions.
- `tests/diagnose_render_test.php`: `renderDiagRepair()` with injected data — no real disk, no
  real job, matching `renderDiagVerdict()`'s existing test shape. Cases: array-disk guidance (and
  no table, even when the ledger has confirmed rows); unassigned-disk table listing only
  `confirm_count >= 2` rows; a `transport` row absent from the table but counted in the link line;
  both empty-state variants (no media range yet, media seen on one run only).
- `tests/diagnose_js_test.js`: the new Verdict-screen "Repair" link's visibility rule (shown only
  on a non-CLEAN verdict) and `luDiagRepair()`'s navigation call.
- Hardware verification, same "Commands I run myself" treatment Task 16 gave Phase 1:
  - on Golem, a clean run (e.g. `sdq`, whose triage currently has no recorded failing sectors and
    spot-checks `0:256` clean) leaves no ledger row — confirms the no-evidence path writes nothing;
  - a real cross-run `media` update (two runs, `confirm_count` reaches 2, the Repair table shows it)
    needs a disk that actually fails VERIFY. Golem may not have one today. If not, this is the one
    check the plan records as not verified on hardware rather than claims, and it is the first
    thing to run when a failing drive turns up — a bench drive already classified MEDIA by
    `sas-bench` is the natural candidate. Only a ledger written by a real run proves the
    chunk-rounding tolerance is right for real dmesg harvests.

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
- **Locating the write target — a hard rule, not an open question.** Before any write, a fresh
  fine-grained VERIFY over the confirmed region locates the exact failing LBA(s), and only those
  are ever written. Ledger `chunk_start` values are region keys for cross-run matching and are
  never written to directly. (Added 2026-09-28.)
- **Where the ledger and baseline persist.** Both live in `/tmp` today, so a reboot clears them,
  and flash (`/boot`) writes are refused by design. Phase 2b decides whether they move to a
  persistent non-flash location and what happens when none is available. Loss fails safe: lost
  evidence means "not confirmed", never "confirmed". (Added 2026-09-28.)

## Follow-ups outside this phase (read-only, engine-side)

Found while comparing against `sas-bench` (the Fedora bench tool), which already checks both. Each
is a small Tier 0 addition to `drive_triage.sh`, planned as its own change rather than folded into
Phase 2a:

- **Format check.** Refurbished SAS drives often ship with 520/528-byte sectors or T10 protection
  information, which alone can make a healthy disk error out under Linux/Unraid. Detect it from
  `/sys/block/<dev>/queue/logical_block_size` and `sg_readcap --16`'s `prot_en`. The fix
  (`sg_format --fmtpinfo=0`) is destructive and belongs to Phase 3; the detection is a read.
- **Uncorrected write errors.** Unraid disables a disk when a write fails, and the drive logs those
  itself (SCSI error counter log page 0x02). `snap()` tracks uncorrected *reads* only. Adding
  `uncorr_write` gives the Verdict screen the most direct answer to "why did Unraid disable this
  disk", even when a read-only triage finds nothing today.

## Out of scope (this phase and the next)

- `array_rebalance.sh` — excluded from this plugin entirely, all phases, per the original proposal.
- Tier 3 destructive operations (`badblocks -w`, `sg_format`, `sg_sanitize`) — Phase 3, not touched
  by this spec.
