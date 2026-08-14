# Real captures from two multi-card SAS2 boxes (issue #18)

Both directories are **real lsiutil 1.70 output**, taken from the diagnostic
bundles two reporters attached to issue #18 on 2026-08-14. They replace the
synthetic fixtures plan 059 Step 0 allowed as a fallback; the fallback is no
longer needed and its guesses turned out to be wrong in two places (see below).

| Dir | Box | Cards |
|-----|-----|-------|
| `2card/` | masterwishx | `LSI2308-IT` at bus 1, `SAS9207-8i` at bus 6 |
| `3card/` | brianara3 | three `SAS9207-8i`, buses 129, 130, 131 |

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

**The columns are `Seg/Bus/Dev`,** so `awk $3`/`$4` in `parse/hba.sh` read bus
and device correctly; the segment in `$2` is 0 on both boxes.
