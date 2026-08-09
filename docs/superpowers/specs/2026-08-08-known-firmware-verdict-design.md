# Known-firmware verdict — design

**Status:** design approved, unimplemented
**Date:** 2026-08-08
**Supersedes in part:** `plans/firmware index/files/FIRMWARE-INDEX.md` (untracked)

---

## 1. Goal

Tell a user whether the HBA in their server is running current IT firmware,
from a hand-maintained index bundled with the plugin.

One sentence of scope, because the source design covers far more: **this spec
is the verdict and the data behind it.** The mirrored firmware repository,
`manifest.json`, the download links and the "latest not present locally" state
from `FIRMWARE-INDEX.md` §1, §7 and §8 are out of scope and get their own spec
if they are ever wanted. Nothing here fetches a firmware image or touches the
network.

## 2. Decisions

Four choices were made before this was written. They are recorded with their
reasons because each one closed off a plausible alternative.

**Verdict per detected controller, not a reference table.** A table of boards
the user does not own answers a question nobody asked. The verdict sits against
the card in front of them.

**Topology is derived from sysfs.** `FIRMWARE-INDEX.md` §2.6 suppresses the
comparison on any board with a separate multipath track unless topology is
known to be `internal`, and lists `SAS9305-24i` among eight affected boards —
so as specified the feature would render **nothing** on most cards, including
the 9305-24i in the bundle that motivated it. §10 calls topology detection the
"highest-value unknown". It is answerable: see §4.

**The index ships bundled, with no fetch and no cache.** The source design
argues for fetching from GitHub because firmware facts move on Broadcom's
schedule. They do not, for the entries that matter: P16 and P20 are marked
`terminal: true` and by definition receive no new version. The non-terminal
branches (P21/P24/P28) carry `observed-floor` confidence, which never drives a
hard verdict. One file read, no cache, no schema negotiation; a wrong entry is
fixed by a release.

**The verdict renders on the Overview card as well as the firmware page.** The
firmware page is `Cond`-gated on `ENABLE_FLASH`, which is off by default —
both diagnostic bundles collected so far have `ENABLE_FLASH=0`. Firmware-page
only would be invisible to nearly everyone.

## 3. The index — `data/known-firmware.json`

Bundled in the `.txz` and added to the `.plg` file list. Shape unchanged from
the draft: 13 boards keyed by **board name** (one chip maps to multiple boards
with incompatible images), plus `no_it_firmware`, `branches`, `multipath_track`
and the three confidence tiers.

Three corrections, each settled by real hardware in the collected bundles.

### 3.1 `SAS3324` and `SAS3316` are typos that propagated

`build-firmware-manifest.py` maps 9305-24i to chip `SAS3324`;
`known-firmware.json` says `SAS3224`; `unverified_chips` lists **both**
`SAS3316` and `SAS3324` as "believed to be RAID-on-Chip rather than IOC;
confirm before treating as IT-capable."

The live card reports `Adapter Type = SAS3224(A1)`. The JSON is right, the
Python is wrong, and both `unverified_chips` entries are ghosts of the same
slip. Correct the Python; delete the entries.

### 3.2 `SAS3224` is confirmed IT-capable

`lsiutil_ident.txt` on the reporter's card reads
`Firmware image's version is MPTFW-15.00.00.00-IT`, and storcli reports an
`IT System Overview` with 15 JBOD drives behind it. It is an IOC running IT
firmware, not RAID-on-Chip. This closes one of §10's open questions outright.

`SAS3216` (9305-16i) is treated the same way by symmetry and marked
**inferred**, not confirmed — no card has been seen.

### 3.3 `notes` must render

The index already carries per-board prose and it is the most useful field in
the file. The `SAS9300-8i` note reads *"Versions below 16.00.12.00 have a
controller-reset bug affecting SATA drives."* A user on a SATA-heavy card wants
to read that sentence, not just see an amber pill.

## 4. New composer fields

Two facts the plugin does not collect today. Both belong in `get_hba_info.sh`
rather than PHP: composers read sysfs, PHP consumes JSON.

### 4.1 `topology`

Evaluated **per controller**, scoped to that card's own scsi host number `H`.
A box with two HBAs, one behind an expander, must not have the expander
suppress the other card's verdict.

```text
/sys/class/sas_expander/expander-H:*    none for this H
  AND
/sys/class/sas_device/end_device-H:*    every entry two-component
                                        (end_device-H:N, never end_device-H:N:M)
  -> "internal"

anything else, or unreadable            -> "unknown"
```

A card with no SAS devices at all — nothing attached — yields `unknown`, not
`internal`: an empty tree proves nothing about topology.

The two-vs-three-component distinction is **already the repo's rule** for
separating a card's own PHYs from an expander's — `_phys_json` in
`get_hba_health.sh` uses `case "${idx#phy-}" in *:*:*) continue` for issue #12.
Same test, new consumer; do not write a second one.

An external multipath configuration cannot present as "no expander, all
direct-attached". The failure direction is safe: an internal backplane expander
yields `unknown` and suppresses a verdict that might have been fine, which
`FIRMWARE-INDEX.md` explicitly calls harmless.

### 4.2 `subvendor_id`

Load-bearing, and the most important suppression in the design. IBM M1015 and
Dell H200/H310 are common on Unraid, carry a non-Broadcom SubVendor ID, and
reaching the generic version is a **crossflash, not an upgrade** — a different
and riskier operation. A wrong verdict here does real harm.

- storcli reports `SubVendor Id` directly.
- lsiutil does not. sysfs publishes `subsystem_vendor` for any PCI device, and
  the plugin can already resolve a card's PCI directory from its scsi_host via
  `_pci_dir_of_host`, which exists from issue #14. Reuse it.

Generic Broadcom signature is `0x1000`. Anything else → `oem_out_of_scope`.

### 4.3 Bundle coverage

`03-sysfs/pci.txt` captures `vendor` and `device` but **not**
`subsystem_vendor`. Once the plugin reads a field, the bundle must capture it —
that is the rule `bundle_coverage_test.sh` exists to enforce. Add the capture,
and add the assertion that fails without it.

## 5. Lookup layer — `firmware_index.php`

At the plugin root, house pattern: pure functions at the top,
`if (PHP_SAPI === 'cli') return;` in the middle, dispatch below. Identical in
shape to `phy_baseline.php`, `event_archive.php` and `bay_map.php`, which is
what lets the test runner `require` the file and reach the functions without
triggering dispatch.

This departs from `FIRMWARE-INDEX.md` §5, which proposes a class under a new
`include/` directory. The logic is the same; only the packaging changes. A
class would be the only class in the plugin and the only file under `include/`,
for no gain.

### 5.1 Gate order

Every early return is a suppression carrying a `reason` string.

| # | Condition | Status |
|---|---|---|
| 1 | index unreadable | `unknown` |
| 2 | subvendor present and not `0x1000` | `oem_out_of_scope` |
| 3 | chip matches a `no_it_firmware` entry | `no_it_firmware` |
| 4 | board not in index | `unknown` |
| 5 | board `it_capable: false` | `no_it_firmware` |
| 6 | board in `multipath_track` and topology not `internal` | `suppressed` |
| 7 | board has `rom_profiles` and profile unresolved | `suppressed` |
| 8 | compare detected against track version | `current` / `behind` / `ahead` |

`normalize()` collapses both naming conventions to one key: strip a leading
`sas`/`hba`, drop non-alphanumerics, lowercase. `SAS9305-24i` → `930524i`,
`HBA 9400-16i` → `940016i`.

Version compare is dotted-quad, integer per field, shorter side zero-padded.
**Never applied to NVDATA**, whose format varies (`24.00.00.22` on 9400,
hex-style `0F.0b.91.xx` on 9405W multipath profiles).

## 6. Rendering

### Overview card — always visible, one row

```text
Firmware    15.00.00.00    ▲ 16.00.12.00 known
```

The row shows the detected version unconditionally. The comparison clause
(`▲ 16.00.12.00 known`) appears **only** for `behind`, `current` and `ahead`.
For `suppressed`, `no_it_firmware`, `oem_out_of_scope` and `unknown` the row
shows the version alone with no clause and no colour — the reason belongs on
the firmware page, not in a one-line summary that cannot carry it.

### Firmware page — under the existing `Current firmware:` line

```text
Firmware         BEHIND
Latest known IT  16.00.12.00
Branch           P16 (terminal)
Confidence       confirmed · index 2026-08-08
Note             NOT interchangeable with the 16i image
                 despite the shared P16.12 label.
```

| Status | Treatment |
|---|---|
| `current` | green, show version |
| `behind` | amber **only if the branch is terminal**; otherwise informational — on a non-terminal branch "latest" is a floor, not a ceiling |
| `ahead` | neutral; the index is stale, not the card |
| `no_it_firmware` | neutral, explain the RAID-on-Chip part |
| `oem_out_of_scope` | neutral, explain the generic-only scope |
| `suppressed` | detected version and reason, **no verdict** |
| `unknown` | render nothing |

The index `updated` date is shown on the **firmware page only**, in the
`Confidence` line. The Overview row has no space for it and no verdict to
qualify.

### Excluded from the health rollup

A stale-firmware finding is a recommendation, not a fault, and it is the only
sub-indicator whose data source is externally maintained and can silently go
wrong. A wrong thermal reading is a bug; a wrong firmware verdict talks someone
into a reflash. This also matches the repo's standing rule that absence is not
health — a blank indicator beats a confident wrong one.

## 7. Tests

`tests/firmware_index_test.php`, registered in **both** invocation lines of
`tests/run_php.sh` — missing the second is how a test silently never runs in CI.

Covers all seven statuses, the dotted-quad compare including unequal field
counts, and `normalize()` against both naming conventions.

Two guards written first and mutation-tested:

- Force the OEM gate to pass through → a Dell-subvendor card must stop
  reporting `behind`.
- Force topology to `internal` unconditionally → the expander fixture must stop
  reporting `suppressed`.

A golden test covers the composer's new `topology` and `subvendor_id` fields,
with fixtures built from the two collected bundles: one direct-attached
9305-24i, one with an expander. Fixtures are captured output with
length-preserving masking, per the repo's standing rule.

`bundle_coverage_test.sh` gains its `subsystem_vendor` assertion (§4.3).

## 8. Worked example

The reporter's card, from the 2026-08-08 bundle:

| Input | Value |
|---|---|
| board | `SAS9305-24i` |
| chip | `SAS3224` |
| firmware | `15.00.00.00` |
| subvendor_id | `0x1000` |
| topology | `internal` (no expander, 15 direct-attached end devices) |
| rom_profile | n/a — board has no `rom_profiles` |

Gates 1–7 all pass. Compare `15.00.00.00` against `latest_it` `16.00.12.00`
→ **`behind`**, branch P16, `terminal: true`, confidence `confirmed` → amber.

## 9. Out of scope

- The mirrored `firmware/` repository, `manifest.json`, the manifest builder
  and the 45Drives mirror script
- Download links, `SHA256SUMS`, and the "latest not present in local repo"
  state
- Fetching or caching the index over the network
- ROM profile detection on 9400/9500 — unresolved upstream, so profile-aware
  boards report `suppressed` and that is the correct answer today
- Retrieving Broadcom KB 1211211122774
