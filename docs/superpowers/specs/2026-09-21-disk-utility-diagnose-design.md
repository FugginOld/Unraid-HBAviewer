# Disk Utility — Phase 1: Diagnose (Tier 0/1) — design

Source: `disk-utility-implementation.md` (proposal, mockups reviewed:
https://claude.ai/artifact/Qke4UyWCTQiX2czvUmZN2n). This spec narrows that
proposal to Phase 1 — diagnosis only, no repair — and is the first of three
phases:

- **Phase 1 (this spec)** — Tier 0 (observe) and Tier 1 (drive-internal
  tests): job runner, event streaming, Live Job and Verdict screens.
- **Phase 2** — Tier 2 targeted repair (`sg_reassign`, `hdparm
  --write-sector`), the Repair screen, the new opt-in toggle.
- **Phase 3** — Tier 3 destructive operations (`badblocks -w`, `sg_format`,
  `sg_sanitize`), same toggle plus its own separate opt-in.

Phase 1 ships no new mutating path. Every operation in scope here is a read:
SMART/log pages, SCSI VERIFY, SCSI READ, SMART self-test. That keeps this
phase outside `docs/review-policy.md`'s mutating-path scrutiny — Phase 2 will
not be.

## Source scripts

Three scripts were reviewed as candidate source material; their disposition
is decided for the whole feature, not just Phase 1:

| Script | Disposition |
|---|---|
| `drive_triage.sh` | Becomes the core diagnostic engine, refactored into the plugin's backend. Phase 1 work. |
| `sas_error_monitor.sh` | Not shipped standalone. Two of its checks (kernel-log "critical medium error" detection, grown-defect-list increase alerts) are merged into the existing SMART/notification pipeline. Phase 1 work — see "Two bugs fixed in the merge" below. |
| `array_rebalance.sh` | Left out of this plugin entirely, all phases. It writes user data and changes the array's write method; HBAviewer's identity is read-only except for the one heavily gated firmware-flash path, and a second write path dilutes that guarantee. Better as its own plugin or a User Script. |

### Two bugs fixed in the merge

`sas_error_monitor.sh` has two defects that must not carry into the merged
pipeline:

1. It tracks new kernel-log lines by counting total lines. Once the kernel
   ring buffer wraps, the count stops growing and new events are silently
   missed. The merged version tracks `/dev/kmsg` sequence numbers instead.
2. It calls `smartctl -a` without `-n standby`, which spins up sleeping
   drives — contradicting HBAviewer's "never spin up a standby drive"
   guarantee (see `docs/foreground-reads.md` and the Locate/`-n standby`
   convention elsewhere in the codebase). The merged version passes
   `-n standby` on every `smartctl` call this pipeline makes.

## Architecture

### Job runner

- Launched via a PHP endpoint with `setsid`, so a job survives the browser
  tab closing — the pattern `flash.php` already uses for the flash log, not
  a new mechanism.
- One lock file per disk under `/tmp/hbaviewer/jobs/<job-id>/` — never under
  `/boot`; this is ephemeral job state, not the kind of thing that must
  outlive a reboot (contrast `bay_map.json`).
- Cancel kills the whole process group, not just the parent.
- One job per disk at a time (Tier 1 gate from the risk-tier table); the
  array must not be mid-rebuild.

### Engine output — structured events

`drive_triage.sh` is refactored into an engine that emits one JSON event per
line, in addition to its existing human-readable report, gated behind a new
`--events` flag so the CLI / User Scripts path is unchanged:

```json
{"t":"phase","disk":"disk3","phase":"verify","lba_total":19532873728}
{"t":"chunk","lba":1048576,"n":65536,"op":"verify","ms":42,"ok":true}
{"t":"counter","key":"disp","before":210,"after":214}
{"t":"verdict","disk":"disk3","v":"TRANSPORT","why":"verify clean, read failed x3"}
```

The script already keys state by disk ID (not `sd` letter, which shifts
across reboots), computes a fleet median for link-error outlier detection,
and separates lifetime SAS counters (meaningless in isolation) from deltas
since last run. `--events` adds the line-oriented emission; it does not
change what the script measures.

SATA drives behind the HBA go through the same VERIFY/READ discriminator —
the Linux SAT layer translates the SCSI commands to ATA — using `-d sat` for
SMART and `UDMA_CRC_Error_Count` as the link-side counter, a fallback the
triage script already has.

### Surface-scan chunk size

The current script's full-surface path calls `sg_verify` in 2048-block
chunks. On a 10 TB, 512-byte-block disk that is roughly 9.5 million process
launches — process-start overhead would dominate the scan time. Full-surface
scans move to 32–64K-block chunks; small chunks stay reserved for
re-testing a specific already-flagged range, where fine-grained per-chunk
latency is the point.

**Open item:** confirm the minimum `sg3_utils` version needed for
`sg_verify`/`sg_read` at the larger chunk size before this ships. Track
against the same chipset/version table `use_storcli` and firmware detection
already maintain, rather than inventing a second one.

### Streaming to the browser

A Server-Sent Events endpoint tails the event file. The client reconnects
with a byte offset, so reopening the tab mid-scan resumes the live view
instead of starting over — the file itself is the source of truth, not
anything held in the PHP worker, consistent with `cached_read()`'s "the
foreground request never blocks on the producer" rule.

### Entry points

A "Diagnose" action is added to each row in the existing Drives tab and each
entry in Top Offenders, scoping a new triage job to that one disk.

### Event-file retention

Count-based, matching `drive_triage.sh`'s existing `KEEP_RUNS` trimming —
keep the last N event files per disk, same knob the script already exposes,
rather than introducing an age-based scheme the rest of the plugin has no
precedent for.

## UI — two of the three mockup screens

Both laid out on the reviewed mockup canvas
(https://claude.ai/artifact/Qke4UyWCTQiX2czvUmZN2n); Repair (`Repair.dc.html`)
is Phase 2 and out of scope here.

### Live job (`Main.dc.html`)

The primary view while a Tier 1 job is running.

- **Header strip** — disk identity, dev path, model, host/phy, what the
  running job is doing in plain language, live status dot, Pause/Resume and
  Cancel.
- **Phase pills** — Preflight → Baseline snapshot → Targeted VERIFY/READ →
  Surface VERIFY → SMART short test → Verdict, each shown as done / active /
  queued.
- **Progress row** — percent, current LBA, throughput, elapsed, remaining.
- **Surface map** — one cell per scanned chunk, colored by read latency
  (<5 ms / <20 ms / <50 ms / <150 ms / <500 ms / ≥500 ms / unreadable),
  filled in left-to-right as the scan proceeds. A detected cluster of slow
  or failing chunks is called out above the map as a "hot zone" with its LBA
  range.
- **Chunk latency histogram** — same bucket scheme as horizontal bars, so a
  forming weak region is visible before the map alone would show it.
- **Counter deltas panel** — the same media-vs-path counters
  `drive_triage.sh` tracks (grown defect list, uncorrected verify/read,
  running disparity, invalid DWORD, loss of DWORD sync), updating live, with
  a one-line running interpretation ("media counters moved, path counters
  flat — points to MEDIA, not the cable").
- **Event stream** — timestamped log tail, color-coded by severity,
  auto-scrolling.
- **New-job panel** (sidebar) — pick a Tier 1 operation to queue next, with
  a "leave standby drives asleep" toggle honored by default.
- **Drive list** (sidebar) — all drives worst-first, verdict badges (MEDIA /
  TRANSPORT / SCANNING / CLEAN / STANDBY), unassigned drives broken out in
  their own group.

### Verdicts (`Verdict.dc.html`)

The result screen for a completed Tier 1 job — shown here for a TRANSPORT
verdict on disk4.

- **Verdict banner** — the verdict in large type plus a one-line
  plain-language explanation ("The drive read every tested block cleanly on
  its own. When those same blocks had to cross the SAS link, two of three
  ranges failed. Replacing this drive would not fix it.").
- **Three evidence cards** — SCSI VERIFY result, SCSI READ result, counter
  movement during the triage window — the same three signals
  `drive_triage.sh` uses to classify, shown as the reasoning rather than
  just the conclusion.
- **Tested ranges table** — start LBA, block count, VERIFY result, READ
  result, and the kernel sense-key/`cmd_age` evidence for each range, plus a
  note that commands hanging over a minute indicate a link timeout rather
  than a media retry (drive-internal recovery gives up in 7–30 s).
- **Next-steps card** — numbered, concrete actions (move the drive to a
  different bay/cable, re-run triage, how to read whether the fault
  followed the slot or the drive). For a MEDIA verdict on an array disk,
  this card states the rebuild/replace guidance in plain language, since
  Phase 2's guarded reassign flow does not exist yet to offer as an
  alternative — this is read-only advice, not a control, so it belongs in
  Phase 1.
- **Sidebar** — counter deltas for the run, an expander/topology diagram
  showing which drives share the same port (isolating a single-drive fault
  from a shared-path fault), and other recent verdicts with links to
  re-open or act on each.

## Scope

In:

- `drive_triage.sh` refactor: `--events` flag, kmsg-sequence-based tracking
  merged in from `sas_error_monitor.sh`, `-n standby` on every `smartctl`
  call, 32–64K-block surface-scan chunking.
- Job runner: launch endpoint, per-disk lock file, process-group cancel.
- SSE endpoint with byte-offset resume.
- Live Job and Verdict screens, Diagnose entry points on Drives tab and Top
  Offenders.
- Count-based event-file retention.

Out (later phases):

- Any Tier 2/3 code or UI (Repair screen, `sg_reassign`, `hdparm
  --write-sector`, `badblocks -w`, `sg_format`, `sg_sanitize`).
- The new Settings → Advanced opt-in toggle (Phase 2).
- `sg_reassign` chipset-variance research — only relevant once Phase 2
  needs it.

Out (permanently):

- `array_rebalance.sh` and anything like it — see Source scripts, above.

## Verification

- Golden tests: feed `drive_triage.sh --events` a fixture, diff the emitted
  JSON event lines against `tests/expected/`, same pattern the existing
  parsers use.
- PHP unit tests: job-runner lock acquisition/release, process-group cancel,
  SSE byte-offset resume logic, retention trimming — pure functions per the
  house pattern (`if (PHP_SAPI === 'cli') return;`).
- Mutation-test the standby guard: confirm a fixture run without `-n
  standby` is what the golden would have caught, so the assertion is proven
  able to fail.
- Hardware verification (per this repo's own testing-on-hardware
  convention): confirm on real SAS/SATA hardware behind an HBA that (a) a
  Diagnose job survives closing the browser tab, (b) reopening mid-scan
  resumes the live view via SSE offset rather than restarting, (c) Cancel
  kills the whole process group, and (d) a standby drive stays asleep
  through a Tier 0 observe pass. This is a box-only check and stays open
  until that output comes back.
