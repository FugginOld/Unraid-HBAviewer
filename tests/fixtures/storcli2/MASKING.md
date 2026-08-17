# Masking of these fixtures

These are real `storcli2` captures from a 9600-24i, because
[ARCHITECTURE.md](../../../ARCHITECTURE.md) requires fixtures to be evidence
rather than hand-written test data — a fixture somebody typed agrees with itself
and hides exactly the bugs a capture catches. The `/dev//dev/sdf` bug in the
Drives parser was found only because two captures from two StorCLI2 builds
disagreed about one field.

The hardware identifiers in them have been replaced. Two rules govern how:

**Length-preserving.** Every replacement is the same number of characters as the
value it replaces. The parsers read some columns positionally, and the tables are
whitespace-aligned, so a mask one character shorter would silently shift a column
and re-bless the shifted output as the expected result.

**Consistent across files.** A given real value maps to the same mask everywhere,
so anything that joins one capture to another — a SAS address in the PHY table
against the same address in the drive path table — still joins after masking.

What was replaced:

| Class | Mask shape |
| --- | --- |
| Drive and board serial numbers | `SN000001`, `SN00000002` — same length as the original |
| SAS addresses (`0x…`) | `0x3000000000000001` — leading nibble kept |
| WWNs and enclosure serials (bare hex) | `5000000000000002` — leading nibble kept |
| Host name | `node` |

The leading nibble of an address is deliberately preserved: it is protocol
meaningful (`5` for a WWN/NAA-assigned name, `3` for one the HBA assigned), and
the drive-to-PHY correlation compares address prefixes.

Examples of the *original* values are deliberately not reproduced here — a
masking note that quotes what it masked defeats itself.

Model numbers, firmware revisions, sizes, temperatures, link speeds, PHY numbers,
enclosure and slot IDs and every structural field are untouched — they carry no
identity and they are what the parsers are actually being tested against.

Regenerate the goldens with `bash tests/run.sh` after any change here, and treat a
golden that moves as a finding rather than something to re-bless.
