# Dual-IOC card grouping — design

**Date:** 2026-08-10
**Status:** approved, not yet planned
**Hardware that prompted it:** SAS9300-16i on the maintainer's box (Raven) — two
SAS3008 IOCs on one board, shown by the plugin as two unrelated HBAs.

## 1. The problem

A SAS9300-16i is one card carrying two SAS3008 IOCs. Every layer below the UI
sees two controllers, because that is what they are: two PCI functions, two
drivers instances, two `storcli` indices, two die temperature sensors. The
Overview therefore renders two cards, and a user with one HBA is told they have
two.

The fix is a rendering one — group the two IOCs under a single card — but it
depends on a fact the plugin does not currently collect: whether two
controllers are on the same board.

## 2. Why the obvious keys do not work

| Candidate | Fails because |
|---|---|
| `board_name` | Two *separate* 9300-8i cards both report `SAS9300-8i`. Grouping on it merges unrelated hardware, which is worse than the current split. |
| `pci_location` | Differs between the two IOCs on one board — that is the whole point of them being two functions. |
| Board serial / tracer | `sas3flash -listall` prints no serial column, and neither parser extracts the storcli fields. The values are referenced only by the diagnostics anonymiser. |

## 3. The key that does work

Observed on Raven:

```
host0: 0000:80:01.0 → 0000:82:00.0 → 0000:83:00.0 → 0000:84:00.0
host1: 0000:80:01.0 → 0000:82:00.0 → 0000:83:09.0 → 0000:86:00.0
             ↑             ↑
        root port     PCIe switch on the card
     (physical slot)
```

`0000:80:01.0` is the root port — the physical slot. `0000:82:00.0` is a switch
on the 9300-16i itself, fanning out to two downstream ports each carrying one
SAS3008. For contrast, the unrelated `host9` resolves through `0000:00:11.0`, a
different root port.

Two controllers sharing a root port are in the same slot, and two cards cannot
occupy one slot. That is the grouping key.

It is also backend-agnostic: it comes from sysfs rather than from tool output,
so it behaves the same whichever of storcli or lsiutil enumerated the card.

### 3.1 The riser caveat, and the guard for it

"Same root port" is not universally "same card". Server boards and risers
sometimes place *several slots* behind one motherboard PCIe switch, in which
case two genuinely separate cards would share an ancestor and be merged.

Grouping therefore requires **both** conditions:

1. two or more controllers share a `card_id`, **and**
2. their `board_name` is a board known to carry that many IOCs, and the count
   matches exactly.

Anything unexpected stays split, which is today's behaviour. The failure mode is
"no grouping", never "two cards merged".

## 4. Data model

Nine references across four files already read `data['controllers']` as a flat
list — `ajax_info.php` (Overview, Drives, SMART, Events, export), `view.php`
(the server-rendered Overview), `bay_map.php` and `phy_baseline.php`. Several
have goldens pinned to the current shape. Restructuring the array into a nested
card→controller shape would touch all of them for a change that is
presentational.

**So the array stays flat and each controller gains one field:**

```json
{ "model": "SAS3008", "board_name": "SAS9300-16i",
  "pci_location": "00:84:00:00", "card_id": "0000:80:01.0", ... }
```

Grouping is a rendering concern. Consumers that do not group ignore the field
and behave exactly as they do today.

`card_id` is `""` when it cannot be resolved — no sysfs entry, an unparseable
`pci_location`, a backend that reports no PCI address. An empty `card_id` never
groups, including with other empty ones.

### 4.1 Deriving it

New `hba_card_id()` in `scripts/lib.sh`, beside `hba_topology()`, which already
walks this chain — and near `_pci_dir_of_host()`, which moved to `lib.sh` for
the same reason.

Input is the controller's PCI address. The storcli path has it as
`PCI Address` in the form `00:84:00:00` (domain:bus:device:function), which
normalises to the sysfs form `0000:84:00.0`. The function resolves
`/sys/bus/pci/devices/<addr>` and returns the first PCI address under the
`pci0000:NN` host bridge in the resolved path.

Both parsers emit the field: `parse/storcli_overview.sh` and `parse/hba.sh`.
As with `topology` and `subvendor_id`, the composer supplies it through the
environment and the parsers stay pure filters with no hardware access.

### 4.2 Where `ioc_count` lives

`data/known-firmware.json`, on the board entry, because it is a board fact and
that file is already the home for board facts keyed by reported board name:

```json
"SAS9300-16i": { "chip": "SAS3008", "ioc_count": 2, ... }
```

Absent means 1. `SAS9300-16i` is the only dual-IOC board in the current index;
the 9201-16i, 9305-16i, 9305-24i and 9400-16i are all single-IOC despite the
port count. Boards outside the index never group.

## 5. Overview

One parent card per group, carrying what belongs to the **board**:

- board name, chip, PCIe link width and speed
- firmware version and its verdict badge
- a **status badge that is the worst of its children** (`alert` > `warn` > `ok`)

A sub-card per IOC, carrying what is genuinely **per-die**:

- temperature and its band — these are separate sensors and must not be merged
  into one number. On Raven both read 56 °C, equal because they share airflow
  and load, not because they are one reading.
- PHY error counters
- drive count

Single-IOC cards render exactly as they do now. A user without a dual-IOC board
sees no change at all.

The parent's worst-of badge exists because a parent card with no status of its
own reads as broken beside single-IOC cards that have one.

## 6. Firmware page

The card list groups by the same rule, so a 9300-16i is one entry rather than
two.

### 6.1 Verify

Today `flash_hba.sh` deliberately scopes verification to one controller, and the
comment gives the reason: a multi-HBA box must not confuse which physical card
maps to an index.

That reasoning is preserved, not overturned. The correct scope was never "one
controller" or "the whole system" — it is **the card**. Verify runs one scoped
call per IOC and concatenates the output:

```
sas3flash -c 0 -list
sas3flash -c 1 -list
```

**Not** `-listall`, which on a box holding a 9300-16i and a 9200-8i would show
the 9200 while the operator is verifying the 16i.

### 6.2 Flash

Broadcom's guidance for dual-controller boards is `-fwall`. This design does
**not** use it. `sas3flash -fwall` means all controllers *in the system*, not
all controllers on this card: on a box with a 9300-16i and a 9300-8i, flashing
the 16i's image with `-fwall` writes it to the 8i as well and bricks it.

The intent behind the guidance — never leave one IOC behind — is met by looping
the card's own indices:

```
sas3flash -c 0 -o -f SAS9300_16i_IT.bin
sas3flash -c 1 -o -f SAS9300_16i_IT.bin
```

Identical behaviour to `-fwall` on a single-card system, with no blast radius on
any other.

### 6.3 The partial-flash hazard

This is the one genuinely new failure mode the design introduces. If the second
write fails after the first succeeded, the board is left with mismatched IOCs.

That case must produce a loud, specific error naming which IOC is on which
version and stating that the card must not be rebooted until the second write
succeeds — not a generic failure. A silent or vague failure here is worse than
the two-card display it replaces.

The loop does not stop early on success and does not skip the second write for
any reason short of the tool being gone.

## 7. Testing

- **Grouping, sysfs fixture.** Two hosts resolving through a shared root port
  and two through separate ones. Asserts the first pair groups and the second
  does not.
- **The riser guard.** A board absent from `ioc_count` never groups however the
  topology looks. Mutation: remove the `ioc_count` check and this must fail.
- **Count mismatch.** Three controllers sharing a root port on a board declaring
  `ioc_count: 2` do not group.
- **Empty `card_id`.** Two controllers with `card_id: ""` do not group with each
  other.
- **Overview golden** for the grouped render, and the existing single-IOC
  goldens unchanged — proving no visual change for everyone else.
- **Flash loop.** The flasher stub records its invocations; the test asserts two
  scoped calls with the same image and no `-fwall` anywhere in the built
  command.
- **Verify concatenation.** Two scoped calls, not `-listall`.

## 8. Out of scope

- Restructuring `data['controllers']` into a nested shape.
- Dual-IOC boards outside the index. They stay split until someone adds an
  `ioc_count` entry with hardware to confirm it.
- The lsiutil backend, which addresses only one controller and so never produces
  two entries to group. Fail-safe by accident, but worth stating.
- `"mode":""` on the storcli path for SAS3008 — noticed while gathering the
  evidence above, unrelated to grouping, filed separately.
