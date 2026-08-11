# Known-good firmware for LSI / Broadcom SAS HBAs

Generated from `source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json`
(schema 1, updated 2026-08-08) and the chip-to-tool map in
`source/usr/local/emhttp/plugins/hbaviewer/scripts/flash_hba.sh`.

**Blank cells mean *unconfirmed*, never zero.** A wrong value in a file that drives
update prompts is worse than an empty one.

## Confidence tiers

| Tier | Meaning | Comparison semantics |
| --- | --- | --- |
| `confirmed` | Verified, and the branch is terminal | Equality is meaningful both ways: matching = current, below = stale |
| `observed-floor` | Seen on a live card or community-reported | Below it is stale. **At it is NOT proof of current** — newer releases may exist |
| `weak` | Single source, provenance questionable | Display only; must not drive a decision to flash |

---

## SAS2 — 6 Gb/s · flash tool `sas2flash`

Branch **P20**, terminal. Companion BIOS/UEFI deliberately blank — never confirmed.
`sas2flash -list` reports no `FW Package Build` field on this generation:
structurally absent, not missing data.

| Board | Chip | Latest IT | Branch | BIOS | UEFI BSD | Confidence |
| --- | --- | --- | --- | --- | --- | --- |
| SAS9211-8i | SAS2008 | 20.00.07.00 | P20 | unconfirmed | unconfirmed | confirmed |
| SAS9201-16i | SAS2116 | 20.00.07.00 | P20 | unconfirmed | unconfirmed | confirmed |
| SAS9207-8i | SAS2308 | 20.00.07.00 | P20 | unconfirmed | unconfirmed | confirmed |

- **SAS9211-8i** — 20.00.04.00 had known defects; 20.00.07.00 is the recommended
  terminal version. OEM variants (Dell, IBM, Fujitsu) need a crossflash to the
  generic LSI IT image, which is riskier than an upgrade.

## SAS3 — 12 Gb/s · flash tool `sas3flash`

Branch **P16**, terminal. BIOS `08.37.00.00` and UEFI BSD `18.00.00.00` were
confirmed on the 9300-4i and 9300-8i and assumed branch-wide for the rest —
verify before treating an inferred board's BIOS as stale. **Every board in this
generation is on the multipath track**, so the plugin suppresses its verdict
unless it can prove the topology is internal.

| Board | Chip | Latest IT | Branch | BIOS | UEFI BSD | Confidence | Flags |
| --- | --- | --- | --- | --- | --- | --- | --- |
| SAS9300-4i | SAS3004 | 16.00.12.00 | P16 | 08.37.00.00 | 18.00.00.00 | confirmed | multipath |
| SAS9300-8i | SAS3008 | 16.00.12.00 | P16 | 08.37.00.00 | 18.00.00.00 | confirmed | multipath |
| SAS9300-16i | SAS3008 ×2 | 16.00.12.00 | P16 | branch-inferred | branch-inferred | confirmed | dual IOC, multipath |
| SAS9305-16i | SAS3216 | 16.00.12.00 | P16 | branch-inferred | branch-inferred | confirmed | multipath |
| SAS9305-24i | SAS3224 | 16.00.12.00 | P16 | branch-inferred | branch-inferred | confirmed | multipath |

- **SAS9300-8i** — below 16.00.12.00 there is a controller-reset bug affecting
  SATA drives. Distributed via iXsystems, not Broadcom's public download page.
- **SAS9300-16i** — one board carrying two SAS3008 controllers. HBAviewer shows
  and flashes it as a single card, writing both controllers in sequence from the
  one image you select. Nothing else in the server is touched.
- **SAS9305-16i** — SAS3216 treated as IT-capable by symmetry with the confirmed
  SAS3224, not from a live card. Downgrade if a 9305-16i ever contradicts it.
- **SAS9305-24i** — **not interchangeable with the 16i image** despite the shared
  P16.12 label. Confirmed IT-capable from a live card: lsiutil reports
  MPTFW-15.00.00.00-IT with 15 JBOD drives attached.

## SAS3.5 tri-mode · flash tool `storcli`

Flashed with `storcli /cx download file=<img>`. No IT/IR split on this generation
— one firmware image — and the BIOS travels inside the firmware package, so a
BIOS-only flash has nothing to act on. **No branch here is known to be terminal.**

| Board | Chip | Latest IT | Branch | BIOS | UEFI BSD | Confidence | Flags |
| --- | --- | --- | --- | --- | --- | --- | --- |
| HBA 9400-8i | SAS3408 | 24.00.00.00 | P24 | unknown | unknown | observed-floor | multipath |
| HBA 9400-16i | SAS3416 | 24.00.00.00 | P24 | 09.47.00.00 | unknown | observed-floor | multipath, ROM profiles |
| HBA 9405W-16i | SAS3616 | 21.00.00.00 | P21 | unknown | unknown | weak | multipath, ROM profiles |
| HBA 9500-8i | SAS3808 | 28.00.00.00 | P28 | none expected | unknown | observed-floor | — |
| HBA 9500-16i | SAS3816 | 28.00.00.00 | P28 | none expected | unknown | observed-floor | — |

- **HBA 9400-8i** — version inferred from the sibling 9400-16i, not observed on
  an 8i. Confirm separately.
- **HBA 9400-16i** — confirmed to exist on a live card; **not** confirmed as the
  newest Broadcom release.
- **HBA 9405W-16i** — sourced from a Broadcom download-search link whose filter
  names the *9400-8i*, not this board. Its multipath profiles run a separate
  `15.00.01.00` track, not comparable to this number.
- **HBA 9500-8i / -16i** — believed to have no legacy option ROM; a null BIOS is
  expected, not missing data. Profile split unconfirmed for this generation.

## Firmware branches

Companion BIOS/UEFI are properties of the **branch**, not the board. A complete
flash set is firmware + NVDATA + BIOS + UEFI BSD; NVDATA stays board-specific.
Only a terminal branch makes an exact version match meaningful.

| Branch | Gen | Firmware | BIOS | UEFI BSD | Terminal | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| P20 | sas2 | 20.00.07.00 | — | — | yes | Final branch for SAS2 |
| P16 | sas3 | 16.00.12.00 | 08.37.00.00 | 18.00.00.00 | yes | Companions confirmed on 2 boards, assumed for 3 |
| P21 | sas3.5 | 21.00.00.00 | — | — | no | Standard track only; profile-aware; multipath runs 15.x |
| P24 | sas3.5 | 24.00.00.00 | 09.47.00.00 | — | no | Profile-aware; treat as a floor |
| P28 | sas3.5 | 28.00.00.00 | none expected | — | no | Latest retail package for SAS3808/3816 |

---

## Four reasons a version match can still be wrong

1. **OEM rebrands.** Dell / IBM / Fujitsu / Supermicro / Lenovo cards carry a
   different SubVendor ID and ship different NVDATA and BIOS. Comparing one
   against a generic row reports a mismatch on a healthy card, and acting on it
   is a **crossflash** — riskier than an upgrade. Generic Broadcom is
   `SubVendor Id = 0x1000`; anything else is out of scope.
2. **Multipath firmware.** Broadcom publishes separate multipath firmware for the
   9300, 9302, 9305, 9400 and 9405W on independent version numbering.
   **Eight of the thirteen boards are affected.** A 9305-24i in an external
   multipath config may correctly run far below `16.00.12.00`; acting on that
   destroys a working setup. (KB 1211211122774)
3. **ROM profiles.** The 9400 and 9405W publish distinct capability profiles at
   the *same* version number — SAS/SATA-only vs Mixed with NVMe. A version match
   alone does not mean the correct image is installed, and switching profiles can
   silently remove NVMe support. The installed profile is not cleanly exposed by
   storcli, so the verdict is suppressed rather than guessed.
4. **Chip is not the key.** SAS3216 and SAS3224 are both "9305" and both on
   P16.12, but take **different images** — flashing the 16i binary to a 24i
   bricks the card. NVDATA is board-specific. Look up by board name; the chip
   only selects the flashing tool.

## RAID-on-Chip parts — no IT firmware exists

MegaRAID controllers. **No IT firmware exists at any version and they cannot be
crossflashed to one.** HBAviewer detects them and refuses before selecting a
tool, rather than returning an empty lookup that might read as "no update needed".

| Chip | Sold as |
| --- | --- |
| SAS2108 | MegaRAID 9260-8i |
| SAS2208 | MegaRAID 9271-8i |
| SAS3108 | MegaRAID 9361-8i |
| SAS3508 | MegaRAID 9460-8i |
| SAS3516 | MegaRAID 9460-16i |

## Which tool flashes which chip

The RAID-on-Chip refusal is checked **before** the family patterns — otherwise
SAS3108 would be handed to sas3flash and SAS3508/3516 to storcli, as though an
IT image were something they could take.

| Chip prefix | Tool | Boards | Where it comes from |
| --- | --- | --- | --- |
| `SAS2*` | sas2flash | 9200 / 9201 / 9207 / 9211 | You supply it — not bundled |
| `SAS30* SAS31* SAS32*` | sas3flash | 9300, 9305 | You supply it — not bundled |
| `SAS34* SAS35* SAS36* SAS38*` | storcli | 9400, 9405W, 9500 | storcli plugin (dkaser) from CA |
| `SAS2108 SAS2208 SAS3108 SAS3508 SAS3516` | — | MegaRAID | Refused before tool selection |

## Recognised hardware with no firmware data

These exist in the wild and most are flashable by chip prefix, but the index
carries no version — so the plugin reports the installed firmware and gives no
verdict. A blank indicator beats a confident wrong one.

- **External and combo variants** — 9200-8e, 9201-16e, 9205-8i/8e, 9207-8e,
  9207-4i4e, 9212-4i, 9211-4i, 9300-8e, 9300-16e, 9311-8i, 9311-4i4e, 9400-8e,
  9400-16e, 9400-8i8e, 9500-8e, 9500-16e. Chip prefixes are covered by the tool
  map; only the version rows are missing.
- **9206-16e** — the only other genuinely dual-controller LSI HBA (two SAS2308).
  No index entry at all, so it can never be grouped as one card the way the
  9300-16i is.
- **9600 series** — 9600-8i/16i/24i/8e/16e, on SAS4116 and SAS4024.
  **Not supported at all:** those prefixes match nothing in the tool map, so the
  plugin cannot select a flasher for them.

### One conflict worth knowing about

Broadcom's own materials have been read both ways on `SAS3516`: as a premium
single-chip tri-mode controller with an integrated PCIe switch, and as the
RAID-on-Chip part behind the MegaRAID 9460-16i. **HBAviewer treats it as
RAID-on-Chip and refuses to flash it.** If that is wrong, the cost is a card the
plugin declines to touch. If the other reading were adopted and it is wrong, the
cost is a bricked controller — so the refusal stands until a live card settles it.
