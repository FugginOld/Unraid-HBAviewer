# Real captures from two multi-card SAS2 boxes (issue #18)

Both directories are **real lsiutil 1.70 output**, taken from the diagnostic
bundles two reporters attached to issue #18 on 2026-08-14. They replace the
synthetic fixtures plan 059 Step 0 allowed as a fallback; the fallback is no
longer needed and its guesses turned out to be wrong in two places (see below).

| Dir | Box | Cards |
|-----|-----|-------|
| `2card/` | masterwishx | `LSI2308-IT` at bus 1, `SAS9207-8i` at bus 6 |
| `3card/` | brianara3 | three `SAS9207-8i`, buses 129, 130, 131 |
| `9400/` | Golem | `HBA 9400-16i` at bus 193, `HBA 9400-8i` at bus 101 |

Each holds the banner (`lsiutil` port table), the `lsiutil -b` board table, and
the single IOC capture that bundle contains — port 1 on the 2-card box, **port 3
on the 3-card box**, whose owner had already set `HBA_PORT=3` by hand to see his
third card. There is no second IOC capture from either box, because the bundle
only ever reads the one configured port. That is the bug.

Only edit: the `Board Tracer` column in `3card/board.txt`, three card serials,
replaced with `SV00000000` at the same length. The bundle anonymiser scrubs
drive serials, WWNs and hostnames but not board tracers.

## What these fixtures settled

**The bus is DECIMAL in `lsiutil -b`, and hex everywhere else.** `129 130 131`
in the board table are `0000:81:00.0`, `0000:82:00.0`, `0000:83:00.0` in sysfs.
The 2-card box could never have shown this — buses 1 and 6 read the same in
either base. Any sysfs join, and the `pci_location` field the Overview has been
printing all along, has to convert.

**`lsiutil -b` lists every port in one call**, in the same order as the banner,
so a per-port `-b` capture is unnecessary — take the Nth `ioc` row. Plan 059's
STOP condition 3 ("does `-p<n> -b` accept `-p`") is moot and was dropped.

**All three ports are addressable, and the join lands.** On 2026-08-16 the
3-card box ran the shipped enumeration + join logic live: ports `1 2 3` gave
53/61/59 C at `81:00.0`, `82:00.0`, `83:00.0`, matching `host0`, `host1`,
`host2`. `ioc_p3.txt` here is still the only raw per-port capture — the other
two ports are confirmed by that summary, not by a stored transcript.

**A board name can contain a space, and the columns do not line up.** `9400/`
is `lsiutil -b` from Golem, whose cards read `HBA 9400-16i` and `HBA 9400-8i`
where every SAS2 card reads a single token like `SAS9207-8i`. `awk $5` kept
`HBA` and dropped the model — and `fw_evaluate` cannot match a board called
`HBA`, so the card also lost its firmware verdict. Cutting at the header's
`Board Name` offset does not work either: the board name starts at column 30 on
the 1-digit buses in `2card/` and column 31 on the 3-digit buses in `3card/` and
`9400/`. What IS stable is that the name is never double-spaced while the gap to
the Board Assembly column always is. Only `board.txt` was captured here; Golem
runs the storcli backend, so it has no lsiutil telemetry to go with it.

**The columns are `Seg/Bus/Dev`,** so `awk $3`/`$4` in `parse/hba.sh` read bus
and device correctly; the segment in `$2` is 0 on both boxes.
