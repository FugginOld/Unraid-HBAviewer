# HBAviewer — Disk Utility Implementation

Status: proposal / mockup review
Mockup: https://claude.ai/artifact/Qke4UyWCTQiX2czvUmZN2n

## 1. Purpose

Add a Disk Utility tab to the HBA Monitor that diagnoses SAS/SATA drive
issues using the VERIFY-vs-READ discriminator, gives real-time feedback
on long-running scans, and — for confirmed problems — offers a
guarded, tiered path to fixing them. This extends HBAviewer's existing
read-only monitoring (PHY health, SMART, event log) rather than
replacing any of it.

## 2. Source scripts — how each one is used

Three scripts were reviewed as candidate source material.

| Script | Disposition | Why |
|---|---|---|
| `drive_triage.sh` | **Becomes the core diagnostic engine.** Refactored into the plugin's PHP/backend, keeping its method. | Correctly separates media faults from transport faults: SCSI VERIFY reads a block inside the drive with nothing crossing the SAS link; SCSI READ moves the same block over the wire. Verify-clean + read-fail = transport; verify-fail = media. Already keys state by disk ID (not `sd` letter, which shifts across reboots), already computes a fleet median for link-error outlier detection, already separates lifetime SAS counters (meaningless in isolation) from deltas since last run (the number that matters). |
| `sas_error_monitor.sh` | **Merged into the plugin's existing SMART/notification pipeline, not shipped standalone.** | Overlaps almost entirely with what HBAviewer's SMART tab and notification system already do. Two real bugs found in review: (1) it tracks new kernel-log lines by counting total lines, so once the kernel ring buffer wraps, the line count stops growing and new events are silently missed — needs to track `/dev/kmsg` sequence numbers instead; (2) it calls `smartctl -a` without `-n standby`, which spins up sleeping drives, contradicting HBAviewer's "never spin up a standby drive" guarantee. Two of its checks are worth keeping as signals feeding the existing pipeline: kernel-log "critical medium error" detection, and grown-defect-list increase alerts. |
| `array_rebalance.sh` | **Left out of this plugin.** | It's a solid, well-guarded data-mover, but it writes user data and changes the array's write method. HBAviewer's identity is read-only except for one heavily gated path (firmware flashing). Bundling a second write path dilutes that guarantee. Better as its own small plugin or left as a User Script. |

## 3. Risk tiers

Every operation is classified by risk, mirroring the guardrail pattern
already used for firmware flashing (opt-in toggle, explicit
confirmation, server-side gate, single-flight lock).

| Tier | Operations | Gate |
|---|---|---|
| **0 — Observe** | SMART/log pages (0x02/0x03/0x05 error counters, 0x15 background scan results, 0x18 SAS PHY), grown defect list, pending/reallocated sectors (SATA), kernel-log sense-key harvest | None — always available |
| **1 — Drive-internal tests** | SMART short/long self-test, SCSI background media scan (`sg_start` / log page 0x15), targeted VERIFY vs READ, full-surface VERIFY | Array not mid-rebuild; one job per disk at a time |
| **2 — Targeted repair** | SAS `sg_reassign` on confirmed bad LBAs; SATA rewrite of a pending sector (`hdparm --write-sector`) | **Unassigned disks only**, or array stopped; typed confirmation (drive serial) |
| **3 — Destructive** | `badblocks -w`, `sg_format`, `sg_sanitize` | Unassigned disks only; typed disk serial; separate opt-in toggle, off by default |

Key rule baked into Tier 2: **on an array (assigned) disk, direct
sector repair is blocked outright.** Writing a block straight to
`/dev/sdX` on an assigned disk bypasses parity — the next parity
check flags it as a mismatch, and a future rebuild could reintroduce
bad data. The safe fix for a bad sector on an array disk is a
**rebuild** (onto the same disk, which rewrites every sector and
remaps pending ones, or onto a replacement). The Repair screen
enforces this distinction in the UI, not just in a doc: array disks
get rebuild/replace guidance, unassigned disks get the guarded
reassign flow.

SATA drives behind the HBA go through the same VERIFY/READ
discriminator — the Linux SAT layer translates the SCSI commands to
ATA — using `-d sat` for SMART and `UDMA_CRC_Error_Count` as the
link-side counter (the triage script already has this fallback).

## 4. Architecture

### 4.1 Job runner

- Launched via a PHP endpoint with `setsid`, so a job survives the
  browser tab closing.
- One lock file per disk under `/tmp/hbaviewer/jobs/<job-id>/` — never
  under `/boot`.
- Cancel kills the whole process group, not just the parent.
- Reuses the pattern already built for the firmware-flash tab's live
  log, rather than a new mechanism.

### 4.2 Engine output — structured events

`drive_triage.sh` is refactored into an engine that emits one JSON
event per line (in addition to its existing human-readable report),
gated behind a new `--events` flag so the CLI / User Scripts path is
unchanged:

```json
{"t":"phase","disk":"disk3","phase":"verify","lba_total":19532873728}
{"t":"chunk","lba":1048576,"n":65536,"op":"verify","ms":42,"ok":true}
{"t":"counter","key":"disp","before":210,"after":214}
{"t":"verdict","disk":"disk3","v":"TRANSPORT","why":"verify clean, read failed x3"}
```

### 4.3 Streaming to the browser

A Server-Sent Events endpoint tails the event file. The client
reconnects with a byte offset, so reopening the tab mid-scan resumes
the live view instead of starting over.

### 4.4 Surface-scan chunk size

The current script's full-surface path calls `sg_verify` in 2048-block
chunks. On a 10 TB, 512-byte-block disk that's roughly 9.5 million
process launches — process-start overhead would dominate the scan
time. Full-surface scans should use 32–64K-block chunks; small
chunks stay reserved for re-testing a specific already-flagged range,
where fine-grained per-chunk latency is the point.

### 4.5 Entry points

A "Diagnose" action is added to each row in the existing Drives tab
and each entry in Top Offenders, scoping a new triage job to that one
disk.

## 5. UI — the three mockup screens

All three are laid out as one canvas
(https://claude.ai/artifact/Qke4UyWCTQiX2czvUmZN2n); use Play on any
artboard to click between them.

### 5.1 Live job (`Main.dc.html`)

The primary view while a Tier 1 job is running.

- **Header strip** — disk identity, dev path, model, host/phy,
  what the running job is doing in plain language, live status dot,
  Pause/Resume and Cancel.
- **Phase pills** — Preflight → Baseline snapshot → Targeted
  VERIFY/READ → Surface VERIFY → SMART short test → Verdict, each
  shown as done / active / queued.
- **Progress row** — percent, current LBA, throughput, elapsed,
  remaining.
- **Surface map** — one cell per scanned chunk, colored by read
  latency (<5 ms / <20 ms / <50 ms / <150 ms / <500 ms / ≥500 ms /
  unreadable), filled in left-to-right as the scan proceeds. A
  detected cluster of slow or failing chunks is called out above the
  map as a "hot zone" with its LBA range.
- **Chunk latency histogram** — same bucket scheme as horizontal
  bars, so a forming weak region is visible before the map alone
  would show it.
- **Counter deltas panel** — the same media-vs-path counters
  `drive_triage.sh` tracks (grown defect list, uncorrected
  verify/read, running disparity, invalid DWORD, loss of DWORD sync),
  updating live, with a one-line running interpretation ("media
  counters moved, path counters flat — points to MEDIA, not the
  cable").
- **Event stream** — timestamped log tail, color-coded by severity,
  auto-scrolling.
- **New-job panel** (sidebar) — pick a Tier 1 operation to queue
  next, with a "leave standby drives asleep" toggle honored by
  default.
- **Drive list** (sidebar) — all drives worst-first, verdict badges
  (MEDIA / TRANSPORT / SCANNING / CLEAN / STANDBY), unassigned drives
  broken out in their own group.

### 5.2 Verdicts (`Verdict.dc.html`)

The result screen for a completed Tier 1 job — shown here for a
TRANSPORT verdict on disk4.

- **Verdict banner** — the verdict in large type plus a one-line
  plain-language explanation ("The drive read every tested block
  cleanly on its own. When those same blocks had to cross the SAS
  link, two of three ranges failed. Replacing this drive would not
  fix it.").
- **Three evidence cards** — SCSI VERIFY result, SCSI READ result,
  counter movement during the triage window — the same three signals
  `drive_triage.sh` uses to classify, shown as the reasoning rather
  than just the conclusion.
- **Tested ranges table** — start LBA, block count, VERIFY result,
  READ result, and the kernel sense-key/`cmd_age` evidence for each
  range, plus a note that commands hanging over a minute indicate a
  link timeout rather than a media retry (drive-internal recovery
  gives up in 7–30 s).
- **Next-steps card** — numbered, concrete actions (move the drive to
  a different bay/cable, re-run triage, how to read whether the fault
  followed the slot or the drive).
- **Sidebar** — counter deltas for the run, an expander/topology
  diagram showing which drives share the same port (isolating a
  single-drive fault from a shared-path fault), and other recent
  verdicts with links to re-open or act on each.

### 5.3 Repair (`Repair.dc.html`)

Tier 2, shown as two side-by-side cases to make the array-vs-unassigned
distinction explicit.

- **Left: disk5, an array member, MEDIA verdict.** A blocked-action
  card explains why direct sector repair isn't offered for array
  disks, followed by the safe options: replace, rebuild onto itself,
  or keep in service and watch (only reasonable once the defect count
  stops climbing between runs).
- **Right: `sdk`, an unassigned drive with confirmed bad LBAs.** The
  guarded reassign flow: a checklist of specific bad blocks with their
  evidence (sense key, number of confirmed runs) to select; a
  pre-flight checklist (not assigned to the array/pool, not mounted,
  no other job running on the disk, every selected LBA failed VERIFY
  on ≥2 separate runs); a typed-serial confirmation field; the action
  button stays disabled until the typed serial matches.

## 6. Open items for implementation

- Confirm whether `sg_reassign` support varies meaningfully across the
  SAS2/SAS3/SAS3.5 chipset table already maintained for firmware
  detection, or whether it's uniform enough to gate purely on
  `sg3_utils` presence.
- Decide event-file retention/rotation alongside the existing
  `KEEP_RUNS` trimming in `drive_triage.sh`.
- Decide whether Tier 2/3 get their own opt-in toggle in Settings →
  Advanced (recommended, matching the firmware-flash pattern) or ride
  on the same toggle as firmware flashing.
- Confirm minimum `sg3_utils` version needed for the chunked
  `sg_verify`/`sg_read` calls at the larger surface-scan chunk size.
