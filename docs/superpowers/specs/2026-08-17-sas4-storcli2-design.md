# SAS4 / 9600 support via StorCLI2 — design

Issue #19. Ports techanonymous's implementation onto our current tree.

## How this arrived

The reporter asked for 9600-24i support on 2026-08-14. Maintainer asked twice
(Aug 15, Aug 16) for a diagnostic bundle and `storcli2 show` output. Neither
came — the reporter built it themselves at
`github.com/techanonymous/Unraid-HBAviewer-sas4`, released as 2026.08.15.4.

## Provenance and licence

- Their repo is **not a GitHub fork** — a standalone repo with no shared history.
- **Base is exactly `acb52d6`**, our `main` at release 2026.08.09. Verified:
  `view.php` is byte-identical, and their tree has no `card_group.php`,
  `firmware_index.php` or `data/`.
- Both repos are **MIT**. Copying is permitted; attribution is required and is
  the right thing regardless. Credit `techanonymous` in the commits that carry
  their code and in the `<CHANGES>` entry.

## This reverses a decision we made on 2026-08-16

`scripts/lib.sh` on `dev` says, in a comment:

> **storcli2 is deliberately NOT a candidate** (it was, until issue #19).

and `tests/run.sh:350` pins `route-sas4-storcli2-ignored`, asserting that a box
with storcli2 on PATH and an mpi3mr card is still *refused*. That refusal, and
its test, were added deliberately because storcli2 enumerated zero controllers
through the code path we had then.

This plan reverses that. The reversal is the point, not an accident — but the
test and the comment must be changed knowingly, not deleted as noise.

## What they built

Against `acb52d6`, ~40 files. The substance:

| Piece | Detail |
|---|---|
| Third backend at the seam | `hba_each` takes an optional third function for storcli2; `STORCLI_FLAVOR` selects it |
| Flavour detection | Read from the tool's **output**, not its filename — see below |
| Four parse filters | `storcli2_{overview,drives,phy,enclosures}.sh`, 325 lines, pure `stdin→JSON`, positional args only, no environment reads |
| `hba_is_sas_proc` | One personality list; adding `mpi3mr` had meant editing six scripts |
| `install_storcli2.sh` | 136 lines, for the FULL StorCLI2 — needed only for the Event Log tab |
| Tests | 10 goldens, a `storcli2` fixture set, and a `tests/stub/storcli2` |

**Why flavour comes from output, not filename.** dkaser's storcli plugin ships
`/usr/local/bin/storcli2Lite-8.14`. The filename is unusable as a signal; the
banner is not.

## Lite versus full StorCLI2

- **Lite** — what the dkaser plugin installs, reachable as `storcli2`. Covers
  every tab except the firmware Event Log.
- **Full** — Broadcom's own build, proprietary, behind a Cloudflare-gated
  JavaScript page with no stable URL, and not redistributable. Their
  `install_storcli2.sh` does **not** download it: you hand it an archive you
  fetched by hand, and it unpacks, installs to `/opt/MegaRAID/storcli2` (RAM on
  Unraid) and adds a `/boot/config/go` line to restore it each boot, because
  FAT32 cannot hold the execute bit. Backed up, idempotent.

## Why this is a port, not a copy

Their base predates our 2026.08.16 release. Copying their files wholesale would
delete `card_group.php`, `firmware_index.php` and the multi-card lsiutil work —
i.e. dual-IOC card grouping, the firmware verdict, and multi-card SAS2 support,
all of which were verified on four boxes on 2026-08-16.

The two sides collide hardest in `lib.sh`: they changed 189 lines against the
base, we changed 281. Same file, same functions.

Only the four parse filters, the fixtures, the goldens and the stub can come
across near-verbatim. Everything else is adaptation.

## What can and cannot be verified

- **No SAS4 hardware exists for this project.** Neither maintainer box has an
  mpi3mr card: Raven is SAS9300-16i (SAS3008/mpt3sas), Golem is 9400-16i +
  9400-8i (SAS3416/3408, mpt3sas).
- The maintainer **does** have storcli2 (Lite) installed and can run it — so
  "the tool is found, the flavour is detected, and a box with no 9600 is not
  broken by any of this" is verifiable locally. That is the negative case.
- The positive case — a 9600 actually read — needs techanonymous. Their fork
  proves the parsers work against their hardware; our port needs its own
  confirmation, and the A/B differential approach used for the multi-card work
  is the cheapest way to ask for it.

## Constraints

1. No existing golden may change **except** `route_sas4_mpi3mr.json` and the
   `route-sas4-storcli2-ignored` check, whose behaviour this plan deliberately
   reverses. Any other golden moving is a regression.
2. The four storcli2 parse filters must stay pure — positional args only, no
   environment reads. They arrive that way; keep them that way. (Our own
   `parse/hba.sh` smuggles three fields through the environment; do not copy
   that pattern into the new filters.)
3. `hba_each`'s existing two-argument calls must keep working — the third
   argument is optional, and every SAS2/SAS3 composer keeps its current shape.
4. A box with no SAS4 card must behave exactly as it does today. This is the
   only part that can be tested on real hardware here, so it carries the weight.
