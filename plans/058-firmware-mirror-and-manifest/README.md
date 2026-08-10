# 058 — Firmware repository: rejected, and what survives it

**Status:** **parked. The mirror is rejected on purpose — see §5. What is left is
one small UI addition (§4) and a set of open hardware questions (§6).**
**Last updated:** 2026-08-09

## Read this before the rest

This began as one design covering both the *verdict* ("is my firmware current?")
and the *repository* ("here is the image, and its checksum"). The verdict half
shipped:

- Spec: `docs/superpowers/specs/2026-08-08-known-firmware-verdict-design.md`
- Plan: `docs/superpowers/plans/2026-08-08-known-firmware-verdict.md`
- Code: `source/usr/local/emhttp/plugins/hbaviewer/firmware_index.php`
- Data: `source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json`

The original document's data model, status enum, integration API and firmware
table have all been superseded by that spec and by the shipped JSON — read
those, not this, on anything about the index or the lookup. Two long sections
of it were also **deleted in this revision**: the remote-index fetch path and
the 45Drives mirror. §4 and §5 record why, so the decision doesn't get
re-litigated from scratch.

What survives here is the part that was never about code: the six findings in
§2 that constrain any firmware comparison, the parser quirks in §3, and the
open questions in §6.

---

## 1. Why the repository idea is dead

The premise was that HBAviewer should host firmware images and offer them for
download. Four things kill it, and one of them only became true recently:

- **45Drives freezes firmware behind current.** They qualify images for their
  own chassis and stay there deliberately. Good as an archive, poor as an
  upstream-latest reference — which is the job it was being hired for.
- **The tree doesn't cover 9400 / 9405W / 9500.** That is precisely where the
  newer cards are.
- **Binaries in git are permanent.** Every revision of a `.bin` stays in the
  pack forever; replacing an image means carrying both copies for good. The
  132 MB LSA zip in the 45Drives tree also exceeds GitHub's 100 MB file limit
  outright.
- **It is rehosting Broadcom binaries under this project's name**, from an
  HTTP source that publishes no checksums, so there is nothing to verify
  against. 45Drives does it openly and the practical risk is low, but the
  benefit no longer justifies even a low risk.

**The recent change:** the flash page now works from a central drop directory
(`/boot/config/plugins/hbaviewer/flash/`) that users `scp` into. A browser
download link does not complete that loop — the file lands on their
workstation and they still have to move it to the server. Hosting the image
solves the half of the problem that was never hard.

`build-firmware-manifest.py` alongside this file is the only artifact of the
rejected design still present. It has no consumer. Keep it only as a starting
point if this decision ever reverses; delete it otherwise.

---

## 2. Why firmware comparison is harder than it looks

Six findings that each break an obvious implementation. These are the reason
`firmware_index.php` is shaped the way it is — its seven gates map onto these
almost one to one. Don't simplify past them.

### 2.1 Chipset is not a valid lookup key

`SAS3216` and `SAS3224` are both "9305," but take different images. Flashing
the 16i binary to a 24i bricks the card. NVDATA is likewise board-specific,
not chip-specific.

**→ Key on board name. Chip is an attribute, useful only for backend
selection.**

### 2.2 Several supported chipsets have no IT firmware at all

`SAS2108`, `SAS2208`, `SAS3108`, `SAS3508`, `SAS3516` are RAID-on-Chip parts
(9260 / 9271 / 9361 / 9460). They run MegaRAID firmware and cannot be
crossflashed to IT. A lookup returns nothing — which must not be rendered as a
failed lookup.

**→ Distinct status: `no_it_firmware`.** Shipped; the same five chips are the
refusal list in `flash_hba.sh`.

### 2.3 Board naming convention changes at the 9400 generation

| Generation | Reported as |
|---|---|
| SAS2 / SAS3 | `SAS9305-24i` |
| SAS3.5 | `HBA 9400-16i` |

**→ Normalize both before keying, or match on PCI device+subdevice ID.**

### 2.4 Scope is generic Broadcom images only

Dell / IBM / Fujitsu / Supermicro / Lenovo rebrands carry a different
SubVendor ID and ship different NVDATA and BIOS. Comparing a Dell H310 against
the generic 9211-8i row reports a mismatch on a healthy card — and a user
acting on it is performing a **crossflash**, a different and riskier operation
than an upgrade.

Generic Broadcom signature: `SubVendor Id = 0x1000`.

**→ Gate the entire comparison on SubVendor. Rebrands → `oem_out_of_scope`.**

### 2.5 Tri-mode boards ship multiple ROM profiles at the same version

The 9400 and 9405W publish distinct capability profiles carrying the *same*
version number:

- `HBA_9400-16i_SAS_SATA.bin` — SAS/SATA only
- `HBA_9400-16i_Mixed_Profile.bin` — NVMe + SAS/SATA
- `HBA_9405W-16i_SAS_SATA_NVMe_FW_BIOS_UEFI.bin`
- `HBA_9405W-16i_SAS_SATA_Profile_IT_Nexus.bin`
- `HBA_9405W-16i_SAS_SATA_Profile_Abort_Task_Set.bin`

Same number, different capability. Offering a single "latest" download can
silently remove NVMe support. The installed profile does not appear to be
cleanly exposed by storcli.

**→ When profile is unresolved, suppress the comparison. Do not guess.**

### 2.6 Multi-path configurations run a separate version track

Broadcom publishes separate multi-path firmware for the **9300, 9302, 9305,
9400, and 9405W** (KB 1211211122774). These carry independent version
numbering — the 9405W IT Nexus profile is `15.00.01.00` while its standard
track is `21.x`.

A 9305-24i in an external multipath config may correctly run something other
than `16.00.12.00`. The naive check reports it as six major versions behind;
acting on that destroys a working multipath setup.

**This affects 8 of 13 boards, including the five that looked most certain.**

**→ If topology is external or unknown on an affected board, suppress.**

> **Retracted:** an earlier heuristic held that the leading version pair
> identifies the branch (P16 → 16.x), so a mismatch flags a wrong-generation
> image. §2.6 kills this — 15.x on a 9405W is correct, not stale. Resolve
> profile/track first, compare second.

---

## 3. Parser quirks

Collected from real `storcli` / `sas3flash` output. Still the best reference
in the repo for what these tools actually print.

| Quirk | Detail |
|---|---|
| Compound BIOS field | 9400-gen reports `Bios Version = 09.47.00.00_24.00.00.00`. Split on `_`, take field 1. |
| `show personality` fails | Returns `Un-supported Command` on 9400 HBAs. Expected, not a fault. Do not map to degraded/unknown. |
| Thermal sensor location | Check `Temperature Sensor for ROC` / `...for Controller` before reading. On 9400: ROC present, controller absent → read `ROC temperature(Degree Celcius)`. Both absent → return unknown, never 0. |
| Zero is not a reading | SAS2008 prints `IOCTemperature: 0x0000` when it has no sensor at all (issue #17). An absent field and a zero field both mean "no data". |
| `Support JBOD = No` | Does **not** indicate IR mode on a 9400 HBA. Drives still enumerate as JBOD. Do not infer personality from it. |
| NVDATA format varies | `24.00.00.22` (9400) vs hex-style `0F.0b.91.xx` (9405W multipath). Parse as opaque string; never version-compare. |
| No `FW Package Build` on SAS2 | `sas2flash -list` has no such field. Structurally empty, not missing data. |
| 9500 has no legacy option ROM | Null BIOS is expected. Do not render as an incomplete flash set. *(unconfirmed)* |
| Dual-controller boards | 9300-16i has two IOCs. Flash with `-fwall`, not `-f`, or only one updates. |

---

## 4. The one thing still worth building

**Surface the index's `updated` date on the firmware page.** The JSON carries
it (`"updated": "2026-08-08"`); nothing displays it. A user looking at a
verdict has no way to tell whether the facts behind it are a week or two years
old. One line, no network.

**Optionally, a per-board `wget` line** next to the verdict, pointing at
Broadcom or 45Drives directly. This is what remains of the download idea after
§1 — it runs on the server, so the file lands where the drop directory needs
it, and it costs nothing to host.

### Rejected: fetching the index at runtime

The original §7 proposed fetching `known-firmware.json` from
`raw.githubusercontent.com` and caching it to `/boot`, on the reasoning that
firmware facts change on Broadcom's schedule rather than the plugin's.

They don't change often enough to matter. P20 dates from 2014, P16 is
terminal, and both are marked `confirmed`. Meanwhile the `.plg` polls `main`
on every boot and releases go out most weeks — **the bundled copy already is
the update channel.** A fetch path would add an outbound dependency, a cache
in `/boot`, a schema-version guard and a new failure mode, to deliver a file
that changes less often than the thing delivering it.

Revisit only if a board's `latest_it` turns out to move between releases in
practice.

---

## 5. Current data

Superseded. The live table is
`source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json` — 13
boards, `schema_version: 1`, with `no_it_firmware`, `multipath_track` and
`branches` alongside. Terminal branches are P16 and P20; equality checks are
only meaningful there.

---

## 6. Open questions

**Closed since this was written:**

- [x] **Topology detection.** `hba_topology()` in `scripts/lib.sh` resolves
      `internal` or `unknown` per SCSI host — any `sas_expander`, or any
      three-component `end_device-H:N:M`, collapses to `unknown`. It never
      claims `external`. That is the conservative half of the question and the
      half that mattered: a direct-attach 9300-8i now gets a real verdict
      instead of a permanent suppression, and anything that might be multipath
      still says nothing. What remains is narrower — distinguishing
      external-single-path from external-multipath — and buys verdicts only
      for a cohort that barely exists on Unraid.
- [x] **SAS3316 / SAS3324 roles.** Typos for `SAS3216` and `SAS3224`, both
      confirmed IT-capable IOCs, both in the shipped index.

**Still open:**

- [ ] **Retrieve KB 1211211122774 contents.** Page body is JavaScript-rendered;
      a plain fetch returns metadata only. Need per-board multipath versions,
      and whether multipath images are distinguishable at runtime (e.g. by
      NVDATA prefix, as the 9405W profiles are). If they are, the app could
      *detect* the track instead of suppressing.
- [ ] **ROM profile detection on 9400/9500.** Same shape of problem as §2.5.
- [ ] **SAS2 companion BIOS / UEFI BSD.** Deliberately left blank — better
      empty than wrong in a file that drives update prompts.
- [ ] **9400-16i UEFI BSD.** Not exposed as a separate storcli field the way
      sas3flash surfaces it on SAS3.
- [ ] **Does the 9500 share the profile split?** If yes, `profile_aware`
      should be the SAS3.5 default rather than a per-board exception.
- [ ] **Confirm 9500 has no legacy option ROM.**

All of these convert some `suppressed` verdicts into real ones, on boards
nobody here owns. Low value until someone reports one.

---

## 7. Sources

- Broadcom KB 1211211122774 — multipath firmware, 9300/9302/9305/9400/9405W
- 9300-series 16.00.12.00 — SATA controller-reset fix, distributed via
  iXsystems rather than Broadcom's public download page
- 9400-16i field report — `storcli /c0 show all`, live card
- 45Drives firmware mirror — `images.45drives.com/Firmware/` (archive only,
  see §1)

Broadcom's own product-page download search frequently returns nothing; the
global search box in the top-right returns the actual packages.
