# known-firmware.json — schema 2 proposal

> **Status, 2026-08-11: not adopted. Three findings extracted and shipped; the
> schema itself is deferred to its own spec cycle.**
>
> The diagnosis is sound — `null` genuinely means two things, confidence is
> stored per row but earned per field, and provenance is undated. All three bit
> while writing `docs/hba-firmware-reference.md`.
>
> **Before this is picked up again, three corrections.** The draft JSON was
> reconstructed from the generated markdown rather than from the real file, and
> it diverges:
>
> 1. **`boards` is a dict keyed by board name, not a list**, and `fw_load()`
>    re-keys every entry through `fw_normalize()` — `SAS9300-16i` is stored as
>    `930016i`. A list of objects needs the loader to build that map. Keying a
>    lookup raw against the normalised index is exactly the defect that shipped
>    dead-on-arrival on the `dual-ioc-grouping` branch.
> 2. **`ioc_count` is dropped.** It exists on one board in schema 1 and none in
>    the draft. `card_group.php`'s `lsi_ioc_counts()` is its only reader, and
>    without it a SAS9300-16i silently stops grouping as one card — the feature
>    that branch was built for. It must survive any migration.
> 3. **`flash_hba.sh` never reads this file.** The two matches in it are
>    comments; it carries its own hardcoded chip→tool map. Migration step 3 is
>    written against code that does not exist — the `refuse.by_board` check
>    belongs in `flash.php`.
>
> Real board record keys are `chip, generation, backend, it_capable, latest_it,
> branch, eol, confidence, oem_variants, notes` (plus `ioc_count` on one board).
> The readers are `firmware_index.php` (the only parser) and four consumers:
> `ajax_info.php`, `view.php`, `flash.php`, `card_group.php`.
>
> **What was taken from this proposal and shipped:**
>
> - Four of the validator's invariants, ported to `tests/firmware_index_test.php`
>   against schema 1 — all four already passed, so they now hold by enforcement
>   rather than by care.
> - The MegaRAID shared-silicon refusal, closed in `flash_cards_from()` by board
>   name rather than by subdevice, which needs no hardware IDs and covers the
>   whole family rather than the two known models.
> - The `SAS33*` question, answered below.
>
> **Still open for the maintainer:** the 9305-16i and 9305-24i downgrades. Both
> are a `confidence` string change in schema 1 and need no migration.

Against `source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json` (schema 1,
2026-08-08) and `scripts/flash_hba.sh`.

Schema 1 gets the hard part right: it refuses to guess. What it can't do is *record why*
it isn't guessing, so every one of those judgements lives in prose in the generated
reference and evaporates on the way into the JSON. Schema 2 changes almost no values. It
adds the fields the prose was already carrying and makes the rules machine-checkable.

Three things drive the whole design:

1. **`null` currently means two incompatible things** — "we don't know" and "correctly
   nothing." The 9500's absent option ROM and the 9400-8i's unknown UEFI BSD are both
   blank, and only one of them is a gap. A future contributor filling in blanks has no way
   to tell which is which.
2. **Confidence is stored per row but earned per field.** A SAS3 row is tagged `confirmed`
   while carrying branch-inferred BIOS. The tier is doing double duty and neither job
   cleanly.
3. **Provenance is the value.** `observed-floor` explicitly means "newer may exist," which
   makes *when it was last checked* part of the claim. A floor from 2024 and one from last
   week are different facts stored identically.

---

## The envelope

Every version-bearing field becomes an object instead of a bare string:

```json
"firmware": {
  "value": "16.00.12.00",
  "state": "confirmed",
  "basis": "observed",
  "source": "iXsystems distribution, not Broadcom's public download page",
  "verified": "2026-07-14",
  "note": "Below this version, controller-reset bug affecting SATA drives."
}
```

`state` and `basis` are independent axes, and splitting them is what resolves items 1 and 2
above.

| `state` | What we claim | `value` |
| --- | --- | --- |
| `confirmed` | Verified, branch terminal. Equality meaningful both ways. | set |
| `observed-floor` | Below it is stale. At it is not proof of current. | set |
| `weak` | Single source, provenance questionable. Display only. | set |
| `unconfirmed` | We don't know. | **null** |
| `not-applicable` | Correctly nothing — this field doesn't exist here. | **null** |

| `basis` | How we know |
| --- | --- |
| `observed` | Read off a live card |
| `documented` | Broadcom release note, README, package manifest |
| `inferred` | Derived from a sibling board or the branch, not independently checked |
| `assumed` | Believed on general grounds, no artifact behind it |
| `none` | Only valid with `unconfirmed` |

This is what lets the 9500's BIOS say what it actually means: `not-applicable` + `assumed`
— we think there's no option ROM, and nobody has proven it. Schema 1 had to write "none
expected" and hope the reader caught the hedge.

`branch-inferred` stops being a magic string and becomes `"inherit": "branch"` with
`basis: "inferred"`. The loader resolves the value; the record stores only the fact that it
wasn't checked here.

## New fields

| Field | Where | Why |
| --- | --- | --- |
| `nvdata` | board | The one value that's board-specific by definition, and the index carried none. Seeded with the 9400-16i's `24.00.00.22`. |
| `verified` | every envelope | Turns `observed-floor` from a claim into a dated claim. |
| `source` | every envelope | The 9405W's mismatched download-search filter is a fact about the data; it belongs in the data. |
| `images[]` | board | `{profile, filename, url, sha256}`. The firmware page renders download links against the in-repo mirror and nothing mapped board+profile to a file. Profile-split boards need two entries. |
| `alt_tracks[]` | board | The 9405W's `15.00.01.00` multipath track isn't comparable to the standard track and shouldn't sit in the same field. |
| `identity` | board | `vendor / device / subvendor / subdevice`. Required to close the refusal hole below. |
| `class` | board | `hba` vs `roc`. Makes the refusal a property of the record, not of a separate list. |
| `unsupported[]` | top level | Prefixes that deliberately match nothing, with the reason. `SAS33*` and the 9600s currently fail silently and identically. |

## The refusal hole

Reason 4 in the reference says the chip is not the key — and then the RAID-on-Chip refusal
is keyed entirely on chip. That works for parts that only ever ship as MegaRAID. It doesn't
work for entry-level MegaRAID that shares silicon with an indexed HBA:

- **MegaRAID 9440-8i** — SAS3408. Matches `SAS34*`, gets storcli, gets offered the 9400-8i
  package.
- **MegaRAID 9341-8i** — SAS3008. Matches `SAS30*`, gets offered 16.00.12.00.

Schema 2 splits `refuse` into `by_chip` (parts that are only ever RoC) and `by_board`
(shares silicon; needs subdevice to identify). Both entries are seeded with
`status: "needs-identity"` — the chip mappings need confirming against real hardware before
they ship, and the validator warns until a subdevice is filled in. The 9440 is the one that
matters; the 9341 crossflash is something people do on purpose.

## Invariants the validator enforces

`validate-known-firmware.py` runs in CI. These are all rules schema 1 stated in prose:

- `value` is null iff `state` is `unconfirmed` or `not-applicable`
- `basis: none` iff `state: unconfirmed`
- a board may not claim more certainty than the branch it inherits from
- `confirmed` firmware is rejected on a non-terminal branch — this alone would have caught
  a `confirmed` P24 row before it shipped
- `nvdata` may never inherit from a branch
- `rom-profiles` flag requires ≥2 `images` entries, and vice versa
- `dual-ioc` requires ≥2 chips
- every chip resolves to a tool pattern, is on the refuse list, or is in `unsupported` — no
  silent misses
- a chip on the refuse list may not also be an indexed board

Warnings (non-blocking) cover missing `verified` dates, unsourced `observed-floor`/`weak`
values, unmapped image files, and shared-silicon refusals with no subdevice.

Against the migrated data it currently reports **0 errors, 45 warnings** — which is the
honest picture. Nothing contradicts anything; there's just a large unfilled provenance
surface, and now it's counted instead of implied.

The multipath count also stops being hand-maintained. The reference asserts "eight of the
thirteen boards"; the validator prints `boards: 13  multipath: 8` from the data. It agrees
today, which is the good case — it just won't quietly stop agreeing.

## Value changes from schema 1 — review these

Everything else is a lossless carry-over. Each of these is marked in the JSON with a note
so you can revert individually.

| Board | Field | Schema 1 | Schema 2 | Why |
| --- | --- | --- | --- | --- |
| SAS9305-16i | firmware state | `confirmed` | `weak` | IT-capability inferred by symmetry with the 3224, never observed on a 16i. Your own note says downgrade on contradiction — this starts it downgraded. |
| SAS9305-24i | firmware state | `confirmed` | `observed-floor` | The live card reports `MPTFW-15.00.00.00-IT`. That confirms IT-capability, not 16.00.12.00 on this board. `confirmed` means equality is meaningful both ways, and the only observation is *below* the listed version. |
| HBA 9400-8i | bios | `unknown` | inherits P24 `09.47.00.00`, `basis: inferred` | BIOS is a branch property per your own branches section. The SAS3 table already handles this case as `branch-inferred`. |
| HBA 9400-8i | flags | `multipath` | `multipath, rom-profiles` | Reason 3 says the profile split applies to "the 9400 and 9405W" — the 8i was missing the flag, so its verdict wouldn't get suppressed. |
| HBA 9400-16i | nvdata | absent | `24.00.00.22` | Your existing datapoint. |
| P20 bios / uefi_bsd | notes | "never confirmed" | + recovery path | These ship inside the P20 IT package as `mptsas2.rom` / `x64sas2.rom`. Commonly cited as `07.39.02.00` and `07.27.01.01`; unverified, so still `unconfirmed`, but the note now says where to close it. P20 is terminal, so confirming once closes the column permanently. |

## Coverage gaps recorded, not fixed

Added to `unsupported` and `known_unindexed.gaps` so they stop being invisible:

- **`SAS33*` matches nothing.** SAS3316 is in your stated chipset coverage but falls between
  the sas3flash and storcli prefix ranges. Open decision: add to sas3flash, or declare it
  unsupported.

  > **Answered, 2026-08-11 — not a gap.** `SAS3316` and `SAS3324` were **typos**
  > for `SAS3216` and `SAS3224`, introduced by the manifest builder and leaked
  > into an "unconfirmed, may be RAID-on-Chip" list. Hardware settled it: the
  > live 9305-24i reports `SAS3224` and runs `MPTFW-15.00.00.00-IT`. The typo
  > block was deleted from the index, and `tests/firmware_index_test.php` pins
  > all three facts (`SAS9305-24i chip is SAS3224`, `is present and IT-capable`,
  > `the unverified_chips typo block is gone`). See `plans/058-firmware-mirror-
  > and-manifest/README.md` §6. So `SAS33*` matching nothing is correct — there
  > is no such part to reach. No entry in `unsupported` is needed; if one is
  > kept, it should say "no such chip" rather than "unreachable prefix".
- **9600 series needs StorCLI2**, not storcli. Framed in schema 1 as an unmatched prefix,
  which makes it look like a one-line fix. Marked `blocked-on-dependency`.
- **9302 family** — named in the multipath advisory, absent from every list.
- **SAS2004 boards** (9211-4i, 9212-4i) — chip in scope, no statement on whether P20
  20.00.07.00 applies.
- **9200-8i** — `tool_map` claims the 9200 family; no 9200 board is indexed.
- **9206-16e** — dual SAS2308, no entry, so it can't be grouped as one card the way the
  9300-16i is.

## Migration

1. Drop in `known-firmware.v2.json`, add `validate-known-firmware.py` to CI.
2. Loader: resolve `inherit: "branch"` at load, and expose `state` per field rather than
   per row.
3. `flash_hba.sh`: check `refuse.by_board` on identity **before** `refuse.by_chip`, and
   both before `tool_map`. Chip-keyed refusal stays as the coarse net.
4. Verdict logic: `weak` and `unconfirmed` render as display-only; `not-applicable` renders
   as nothing at all rather than as a gap. Suppression on `multipath` / `rom-profiles`
   is unchanged.
5. Regenerate the markdown reference from the JSON — every table in it, including the
   thirteen-board and eight-multipath counts, is derivable now.
6. Backfill `verified` dates. Everything else can stay warning-level indefinitely; this one
   is what makes `observed-floor` mean anything.

## Open decisions

- `SAS33*` — support or declare unsupported?
- The 9305-16i and 9305-24i downgrades: agree, or restore and record the missing
  provenance instead?
- `identity.subdevice` for the 9440-8i and 9341-8i — do you have access to either card, or
  should the refusal stay chip-keyed with a documented hole?
- Does `sha256` in `images[]` earn its keep, given the files live in your own repo?
