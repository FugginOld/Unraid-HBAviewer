# 058 — Firmware mirror and manifest

**Status:** **not started, and half of this document has already shipped without it.**
**Last updated:** 2026-08-08

## Read this before the rest

This started as one design covering both the *verdict* ("is my firmware current?")
and the *repository* ("here is the image, and its checksum"). The verdict half was
split out, specced and built:

- Spec: `docs/superpowers/specs/2026-08-08-known-firmware-verdict-design.md`
- Plan: `docs/superpowers/plans/2026-08-08-known-firmware-verdict.md`

**Sections 1-6 and 9 below are superseded by that spec**, and the spec disagrees
with them in three places that hardware settled — read it, not this, on anything
about the index, the lookup, or the status enum. In particular: `SAS3324` and
`SAS3316` in §9 are typos for `SAS3224` and `SAS3216`, and `SAS3224` is a
confirmed IT-capable IOC, not the unverified RAID-on-Chip candidate §9 implies.
`build-firmware-manifest.py` alongside this file has had the same typos corrected.

**What is still live and lives only here:** §7's update path, §8's firmware
repository — the 45Drives mirror, the GitHub 100 MB limit that rejects the 132 MB
LSA zip, the redistribution and takedown note, the absence of upstream
checksums — and §10's open questions. That is the deferred work this plan is now
about, and none of it is written down anywhere else.

`FirmwareIndex.php` and `known-firmware.json` used to sit beside this file and
have been deleted: the first was superseded by `firmware_index.php`, which has a
different shape and three closed false-positive paths the draft still had; the
second shipped, corrected, as the plugin's `data/known-firmware.json`.

---

## 1. Goal

Let HBAviewer tell a user whether their HBA is running current IT firmware,
backed by a firmware repository hosted in-repo at
**`FugginOld/Unraid-HBAviewer/firmware`** (seeded from the 45Drives mirror)
plus a hand-maintained index of known-latest versions.

Two artifacts, two jobs:

| Artifact | Answers |
|---|---|
| `firmware/manifest.json` | what firmware images does the repo hold? |
| `data/known-firmware.json` | what *should* exist upstream? |

Diffing them yields three useful states: **up to date**, **behind**, and
**latest not present in local repo** — the third being the one a
repo-backed plugin can uniquely offer.

---

## 2. Why this is harder than it looks

Six findings that each break an obvious implementation. These are the
reason the code is shaped the way it is; don't simplify past them.

### 2.1 Chipset is not a valid lookup key

`SAS3216` and `SAS3224` are both "9305," but take different images.
Flashing the 16i binary to a 24i bricks the card. NVDATA is likewise
board-specific, not chip-specific.

**→ Key on board name. Chip is an attribute, useful only for backend selection.**

### 2.2 Several supported chipsets have no IT firmware at all

`SAS2108`, `SAS2208`, `SAS3108`, `SAS3508`, `SAS3516` are RAID-on-Chip
parts (9260 / 9271 / 9361 / 9460). They run MegaRAID firmware and cannot
be crossflashed to IT. A lookup returns nothing — which must not be
rendered as a failed lookup.

**→ Distinct status: `no_it_firmware`.**

### 2.3 Board naming convention changes at the 9400 generation

| Generation | Reported as |
|---|---|
| SAS2 / SAS3 | `SAS9305-24i` |
| SAS3.5 | `HBA 9400-16i` |

**→ Normalize both before keying, or match on PCI device+subdevice ID.**

### 2.4 Scope is generic Broadcom images only

Dell / IBM / Fujitsu / Supermicro / Lenovo rebrands carry a different
SubVendor ID and ship different NVDATA and BIOS. Comparing a Dell H310
against the generic 9211-8i row reports a mismatch on a healthy card —
and a user acting on it is performing a **crossflash**, a different and
riskier operation than an upgrade.

Generic Broadcom signature: `SubVendor Id = 0x1000`.

**→ Gate the entire comparison on SubVendor. Rebrands → `oem_out_of_scope`.**

### 2.5 Tri-mode boards ship multiple ROM profiles at the same version

The 9400 and 9405W publish distinct capability profiles carrying the
*same* version number:

- `HBA_9400-16i_SAS_SATA.bin` — SAS/SATA only
- `HBA_9400-16i_Mixed_Profile.bin` — NVMe + SAS/SATA
- `HBA_9405W-16i_SAS_SATA_NVMe_FW_BIOS_UEFI.bin`
- `HBA_9405W-16i_SAS_SATA_Profile_IT_Nexus.bin`
- `HBA_9405W-16i_SAS_SATA_Profile_Abort_Task_Set.bin`

Same number, different capability. Offering a single "latest" download
can silently remove NVMe support.

The installed profile does not appear to be cleanly exposed by storcli.

**→ When profile is unresolved, suppress the comparison. Do not guess.**

### 2.6 Multi-path configurations run a separate version track

Broadcom publishes separate multi-path firmware for the **9300, 9302,
9305, 9400, and 9405W** (KB 1211211122774). These carry independent
version numbering — the 9405W IT Nexus profile is `15.00.01.00` while
its standard track is `21.x`.

A 9305-24i in an external multipath config may correctly run something
other than `16.00.12.00`. The naive check reports it as six major
versions behind; acting on that destroys a working multipath setup.

**This affects 8 of 13 boards, including the five I was most confident about.**

**→ If topology is external or unknown on an affected board, suppress.**

> **Retracted:** an earlier heuristic held that the leading version pair
> identifies the branch (P16 → 16.x), so a mismatch flags a
> wrong-generation image. Section 2.6 kills this — 15.x on a 9405W is
> correct, not stale. Resolve profile/track first, compare second.

---

## 3. Parser quirks

Collected from real `storcli` / `sas3flash` output.

| Quirk | Detail |
|---|---|
| Compound BIOS field | 9400-gen reports `Bios Version = 09.47.00.00_24.00.00.00`. Split on `_`, take field 1. |
| `show personality` fails | Returns `Un-supported Command` on 9400 HBAs. Expected, not a fault. Do not map to degraded/unknown. |
| Thermal sensor location | Check `Temperature Sensor for ROC` / `...for Controller` before reading. On 9400: ROC present, controller absent → read `ROC temperature(Degree Celcius)`. Both absent → return unknown, never 0. |
| `Support JBOD = No` | Does **not** indicate IR mode on a 9400 HBA. Drives still enumerate as JBOD. Do not infer personality from it. |
| NVDATA format varies | `24.00.00.22` (9400) vs hex-style `0F.0b.91.xx` (9405W multipath). Parse as opaque string; never version-compare. |
| No `FW Package Build` on SAS2 | `sas2flash -list` has no such field. Structurally empty, not missing data. |
| 9500 has no legacy option ROM | Null BIOS is expected. Do not render as an incomplete flash set. *(unconfirmed)* |
| Dual-controller boards | 9300-16i has two IOCs. Flash with `-fwall`, not `-f`, or only one updates. |

---

## 4. Data model

`known-firmware.json`, `schema_version: 1`.

```
{
  schema_version, updated, source,
  boards: {                    # keyed by reported Board Name
    "<board>": {
      chip, generation, backend, it_capable,
      latest_it, branch, bios, uefi_bsd,
      confidence, rom_profiles?, pci?, notes
    }
  },
  no_it_firmware: { <chip>: [<boards>] },
  multipath_track: { affected_boards, known_versions, kb, app_guidance },
  branches: { "<Pnn>": { firmware, bios, uefi_bsd, terminal, profile_aware } },
  parser_notes: { ... },
  unverified_chips: { ... }
}
```

### Confidence tiers

| Tier | Meaning | Comparison semantics |
|---|---|---|
| `confirmed` | Verified, branch terminal | Equality is meaningful both directions |
| `observed-floor` | Seen on a live card / community-reported | Below = stale. At = **not** proof of current |
| `weak` | Single source, provenance questionable | Display only; don't drive prompts |

Companion BIOS/UEFI live at **branch** level, not per-board — they're a
property of the firmware branch. NVDATA stays per-board.

---

## 5. Repo layout

```
firmware/                              # the firmware repository itself
  manifest.json                        # generated index of what's here
  overrides.json                       # manual version entries
  SHA256SUMS
  LSI9305/24i/SAS9305_24i_IT_P.bin
  LSI9305/16i/...
  LSI3008/... LSI9201/... etc.
data/
  known-firmware.json                  # canonical index, edited here
  build-firmware-manifest.py           # indexes firmware/
  mirror-45drives-firmware.sh          # seeds firmware/ from 45Drives
src/usr/local/emhttp/plugins/hbaviewer/
  data/known-firmware.json             # installed copy
  include/FirmwareIndex.php            # lookup layer
```

Add `known-firmware.json` to the `.plg` file list so it installs. The
`firmware/` tree stays in the repo only and is **never** packaged with the
plugin — the firmware page links to images by manifest path instead
(see §7).

**Runtime resolution order:**
1. `/boot/config/plugins/hbaviewer/known-firmware.json` (fetched cache)
2. bundled copy
3. `known-firmware.local.json` shallow-merged over the top (user override)

---

## 6. Integration

### API

```php
$idx = new FirmwareIndex();
$r = $idx->evaluate([
    'board'        => 'SAS9305-24i',  // Board Name, either convention
    'chip'         => 'SAS3224',
    'firmware'     => '16.00.10.00',
    'subvendor_id' => '0x1000',
    'topology'     => 'internal',     // internal|external|unknown
    'rom_profile'  => null,           // null when undetectable
]);
```

### Status enum

Six states, not a boolean — "not current" and "should update" are
different claims.

| Status | UI treatment |
|---|---|
| `current` | green, show version |
| `behind` | amber **only if branch is terminal**; otherwise informational |
| `ahead` | neutral — the index is stale, not the card |
| `no_it_firmware` | neutral, explain ROC part |
| `oem_out_of_scope` | neutral, explain generic-only scope |
| `suppressed` | show detected version, **no verdict**, show reason |
| `unknown` | blank indicator |

### Rollup integration

Recommendation: **keep firmware out of the worst-of rollup**, or cap its
contribution at amber.

Unlike thermal or PHY error rates, a stale-firmware finding is a
*recommendation*, not a health fault — and it's the only sub-indicator
whose data source is externally maintained and can silently go wrong.
A wrong thermal reading is a bug; a wrong firmware verdict talks someone
into a reflash.

Same reasoning as the mandatory `unknown` state elsewhere in the rollup
spec: a blank indicator beats a confident wrong one.

---

## 7. Update path

Do **not** bake firmware facts into plugin releases — they change on
Broadcom's schedule.

- Fetch `raw.githubusercontent.com/FugginOld/Unraid-HBAviewer/main/data/known-firmware.json`
- Cache to `/boot/config/plugins/hbaviewer/`
- Fall back to bundled on failure
- Refuse to load `schema_version` newer than the plugin supports
- Surface `updated` date in the UI footer so users can see how stale the facts are

Firmware images are **not** fetched by the plugin. The firmware page
renders a download link per manifest entry, pinned to a release tag:

```
https://github.com/FugginOld/Unraid-HBAviewer/raw/<tag>/firmware/<path>
```

Pin to a tag or commit, never `main` — a `main` link silently changes
meaning when the repo updates.

The download occurs in the user's browser, not on the Unraid host, so the
file lands on their workstation. Alongside the link, show:

- the expected `sha256` from `manifest.json` (the only integrity check in
  the chain — the upstream source publishes none)
- a copy-paste `wget` line for running directly on the server, which is
  the shorter path for most users

This keeps the plugin fully functional with no outbound network: version
detection and the known-latest comparison work offline, and only the
download convenience depends on connectivity.

---

## 8. The `firmware/` repository

Seeded from `http://images.45drives.com/Firmware/` — plain HTTP, no
published checksums, contains `IPMI/`, `LSI3008/`, `LSI9201/`, `LSI9305/`,
`LSI9361/`, `LSIP411W-32P/`.

```bash
./data/mirror-45drives-firmware.sh --dry-run
./data/mirror-45drives-firmware.sh --skip-large --dest ./firmware
./data/build-firmware-manifest.py ./firmware
```

The mirror script does a dry run, resumes partials, and generates
`SHA256SUMS`. The manifest builder then indexes it.

### Constraints of hosting binaries in git

- **GitHub rejects any file over 100 MB.** The `003.160.000.000_LSA_Linux-x64.zip`
  in the 45Drives tree is ~132 MB and *will* be refused on push. Use
  `--skip-large`, or Git LFS if it's genuinely needed. Nothing else in the
  tree is close to the limit.
- **Binaries bloat history permanently.** Every revision of a `.bin` stays
  in the pack forever. Replacing an image means the repo carries both
  copies for good. Prefer adding new versioned filenames over overwriting.
- **Redistribution.** These are Broadcom firmware images being rehosted.
  45Drives does it openly and this is common practice in the homelab
  community, but it isn't the same as having a license to redistribute.
  Worth a `firmware/README.md` stating provenance, that images are
  unmodified vendor binaries, and a takedown contact. Low practical risk,
  cheap insurance.
- **No upstream integrity check.** Source is HTTP with no published
  checksums. `SHA256SUMS` records what *you* pulled; it can't prove the
  source was authentic. Commit it anyway — it detects drift and
  corruption after the fact.

### Caveats on the content

45Drives freezes firmware at what they've qualified for their own chassis,
often deliberately behind current. Good as an *archive*, poor as an
*upstream-latest reference*. That's precisely why `known-firmware.json`
is maintained separately — and why `manifest.json` and the index are
compared rather than conflated.

Filenames carry no version. The manifest builder does a best-effort
`strings` scan and flags anything it can't resolve for manual entry in
`overrides.json`. Verify at least one against a live card before trusting it.

The 45Drives tree does **not** cover 9400 / 9405W / 9500. Those boards
will have index entries with no corresponding local image — which is the
`latest not present in local repo` state from §1, and should be surfaced
as such rather than as an error.

---

## 9. Current data

| Chip | Board | FW | Branch | BIOS | UEFI BSD | Confidence |
|---|---|---|---|---|---|---|
| SAS2008 | SAS9211-8i | 20.00.07.00 | P20 | ? | ? | confirmed |
| SAS2116 | SAS9201-16i | 20.00.07.00 | P20 | ? | ? | confirmed |
| SAS2308 | SAS9207-8i | 20.00.07.00 | P20 | ? | ? | confirmed |
| SAS3004 | SAS9300-4i | 16.00.12.00 | P16 | 08.37.00.00 | 18.00.00.00 | confirmed |
| SAS3008 | SAS9300-8i / -16i | 16.00.12.00 | P16 | 08.37.00.00 | 18.00.00.00 | confirmed |
| SAS3216 | SAS9305-16i | 16.00.12.00 | P16 | 08.37.00.00* | 18.00.00.00* | confirmed |
| SAS3224 | SAS9305-24i | 16.00.12.00 | P16 | 08.37.00.00* | 18.00.00.00* | confirmed |
| SAS3408 | HBA 9400-8i | 24.00.00.00 | P24 | ? | ? | observed-floor |
| SAS3416 | HBA 9400-16i | 24.00.00.00 | P24 | 09.47.00.00 | ? | observed-floor |
| SAS3616 | HBA 9405W-16i | 21.00.00.00 | P21 | ? | ? | weak |
| SAS3808 | HBA 9500-8i | 28.00.00.00 | P28 | none | ? | observed-floor |
| SAS3816 | HBA 9500-16i | 28.00.00.00 | P28 | none | ? | observed-floor |

`*` branch-inferred, not confirmed. `?` unknown.

**Terminal branches:** P16, P20 — equality checks safe.
**Non-terminal:** P21, P24, P28 — treat as floors only.

Multipath caveat applies to all 9300 / 9305 / 9400 / 9405W rows.

---

## 10. Open questions

- [ ] **Topology detection.** How does an external multipath config present
      from Unraid? `storcli /cx/ex show` enclosure output? SES presence?
      Until this is answered, 8 of 13 boards can only ever report
      `suppressed` when topology is unstated. **Highest-value unknown.**
- [ ] **Retrieve KB 1211211122774 contents.** Page body is
      JavaScript-rendered; a plain fetch returns metadata only. Need
      per-board multipath versions, and whether multipath images are
      distinguishable at runtime (e.g. by NVDATA prefix, as the 9405W
      profiles are). If they are, the app can *detect* the track instead
      of suppressing.
- [ ] **ROM profile detection on 9400/9500.** Same shape of problem.
- [ ] **Confirm SAS3316 / SAS3324 roles.** Present in the supported-chipset
      table; believed to be RAID-on-Chip rather than IOC. If so they belong
      in `no_it_firmware`.
- [ ] **SAS2 companion BIOS / UEFI BSD.** Deliberately left blank — better
      empty than wrong in a file that drives update prompts.
- [ ] **9400-16i UEFI BSD.** Not exposed as a separate storcli field the way
      sas3flash surfaces it on SAS3.
- [ ] **Does the 9500 share the profile split?** If yes, `profile_aware`
      should be the SAS3.5 default rather than a per-board exception.
- [ ] **Confirm 9500 has no legacy option ROM.**

---

## 11. Sources

- Broadcom KB 1211211122774 — multipath firmware, 9300/9302/9305/9400/9405W
- 9300-series 16.00.12.00 — SATA controller-reset fix, distributed via
  iXsystems rather than Broadcom's public download page
- 9400-16i field report — `storcli /c0 show all`, live card
- 45Drives firmware mirror — `images.45drives.com/Firmware/`

Broadcom's own product-page download search frequently returns nothing;
the global search box in the top-right returns the actual packages.
