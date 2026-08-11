# Handoff: firmware index review, second pass (with repo access)

Read from `origin/advisor/firmware-verdict` @ `8732111`. The index is not on `main`;
it exists on `advisor/firmware-verdict`, `dual-ioc-grouping`, and `dev`. `ioc_count`
is on `dual-ioc-grouping` only, which is why the schema-2 draft appeared to drop it —
it was written against `advisor/firmware-verdict`.

Your sequencing was right and I'm adopting it. Two items change, one materially.

---

## 1. Retract from the schema-2 proposal

The proposal was reconstructed from the generated markdown reference, not from
`known-firmware.json`. Four of its claimed gaps do not exist in the source. Don't act
on them:

| Proposal claim | Actual |
| --- | --- |
| "NVDATA has no column anywhere" | `nvdata` exists; `HBA 9400-16i` carries `24.00.00.22` |
| "No provenance fields" | `source` on 5 boards; branches carry `confirmed_on` / `inferred_on` / `observed_on` |
| "No file mapping for downloads" | `package` and `rom_profiles` carry filenames; 9405W has per-profile `file` + `version` + `track` + `nvdata_prefix`. Only URL and checksum are absent |
| "`boards` should be a list" | It's a dict keyed by board name. Keep it |

The reference table omits `nvdata`, `source`, `pci`, `oem_variants`, `package`, `psoc`,
`chip_rev`, `eol`, `min_recommended`, and `fw_package_build` entirely. It presents
itself as generated from the JSON and is not.

What survives from the proposal: the two-meanings-of-null problem is real —
`branches.P20.bios` and `branches.P28.bios` are both `null`, one meaning "unconfirmed"
and one meaning "believed absent," and only the prose `notes` distinguishes them. Per-row
`confidence` on a row with branch-inferred BIOS is also a real fault. Both stay deferred.

## 2. Primary finding: five indexed boards cannot be flashed

`scripts/flash_hba.sh`, `flasher_for_chip()`:

```sh
SAS2*)          echo sas2 ;;
SAS30*|SAS31*)  echo sas3 ;;
SAS34*|SAS35*)  echo storcli ;;
*)              return 1 ;;
```

No `SAS32*`, no `SAS36*`, no `SAS38*`. Indexed boards with `it_capable: true` that match
nothing and die at exit 3:

| Board | Chip | Confidence |
| --- | --- | --- |
| SAS9305-16i | SAS3216 | confirmed |
| SAS9305-24i | SAS3224 | confirmed |
| HBA 9405W-16i | SAS3616 | weak |
| HBA 9500-8i | SAS3808 | observed-floor |
| HBA 9500-16i | SAS3816 | observed-floor |

It fails closed, so this is a functionality gap rather than a safety hole — but the
verdict path will tell a 9500 owner they're out of date and the flash path will then
refuse the card.

Reproduce:

```bash
python3 - <<'EOF'
import json, fnmatch
d = json.load(open('source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json'))
pats = ['SAS2*', 'SAS30*', 'SAS31*', 'SAS34*', 'SAS35*']   # from flash_hba.sh, not the doc
for k, v in d['boards'].items():
    if v.get('it_capable') and not any(fnmatch.fnmatch(v['chip'], p) for p in pats):
        print('unflashable:', k, v['chip'])
EOF
```

## 3. Why the probe passed: the reference has drifted from the code

Your item 1 reported "every indexed chip hits a tool prefix — all 13." That is true
against the prefix table in the generated markdown, which lists `SAS30* SAS31* SAS32*`
and `SAS34* SAS35* SAS36* SAS38*`. It is false against `flasher_for_chip()`.

The check was run against the document rather than the shell, and the document is wrong.
This moves "regenerate the reference from the index" out of the deferred pile — it isn't
a tidiness item, it's currently producing false assurance. Any invariant you port needs
to read its patterns from `flash_hba.sh` (or from a single shared source both consume),
not from the markdown.

Note `backend` in the JSON is the telemetry read tool (`lsiutil` / `storcli`) and is a
different concept from the flash tool. The reference's per-generation "flash tool"
headings come from `flash_hba.sh` and are consistent; only the prefix list is wrong.

## 4. Revised item 2: `flash.php` gates on nothing

`fw_evaluate()` in `firmware_index.php` is stronger than either of us assumed. Gate 2
requires `subvendor === '0x1000'` and treats an unreadable value as out-of-scope rather
than defaulting to generic — that answers the open question from the last round. Gates
3–7 cover RoC-by-chip, not-indexed, `it_capable`, multipath-unless-internal, and
unresolved ROM profile.

`flash.php` calls none of it. It reads `chip` from POST, strips to alphanumerics
(`preg_replace('/[^A-Za-z0-9]/', ...)`), and passes it to `flash_hba.sh`. The chip
string is client-supplied and never checked against the index. A MegaRAID 9440-8i
reports `SAS3408`, matches `SAS34*`, gets storcli.

So the guard is not a MegaRAID name match — it's calling the gate machinery that already
exists and is already tested. Refusing on gates 2, 3, and 5 closes MegaRAID, OEM
rebrands, and non-IT boards in one move, with no new string heuristics and no dependency
on storcli emitting "MegaRAID" in a product name that OEM rebrands may not carry.

One deliberate difference from the verdict path: **gate 4 must not refuse in the flash
path.** "Not indexed" boards (the 9200-8e class) are intentionally flashable with a
user-supplied image. Flash refuses on 2, 3, 5; allows 4 with no verdict.

## 5. Agreed without change

- **`SAS33*`** — accepted, Plan 058 settles it. Record as "no SAS3316/SAS3324 confirmed
  to exist; the prefix intentionally matches nothing" so a future sighting lands in
  unsupported rather than reading as a regression.
- **9305-24i → `observed-floor`** — take it. The live card reported
  `MPTFW-15.00.00.00-IT`, which proves IT-capability and nothing about `16.00.12.00`.
- **9305-16i stays `confirmed`** — your call is right. The board needs "confirmed value,
  inferred derivation," schema 1 has no tier for that, and `weak` (display-only)
  overcorrects more than `confirmed` overclaims. Worth noting this is the clearest
  argument for the envelope when schema 2 comes up.
- **Schema 2 deferred** to its own cycle. Keep `boards` as a dict, carry `ioc_count`.

## 6. Suggested task list

1. Add `SAS32*` to the sas3 arm and `SAS36*|SAS38*` to the storcli arm of
   `flasher_for_chip()`. Confirm sas3flash is correct for `SAS32*` and storcli for
   `SAS36*`/`SAS38*` before shipping — I inferred both from the boards' `backend` values
   and the generation split, not from a flash.
2. Port the invariants to `tests/firmware_index_test.php`, reading tool prefixes from
   `flash_hba.sh`. Add: every `it_capable` board resolves to a flasher. That assertion
   fails today, which is the point.
3. Wire `fw_evaluate()` into `flash.php` — refuse on gates 2, 3, 5; allow gate 4.
4. Generate the reference from the index. Promoted out of the deferred pile per §3.
5. Record the `SAS33*` conclusion; apply the 9305-24i downgrade.

## 7. Open questions

- Is `sas3flash` right for `SAS32*` (9305 boards), or do they take storcli like their
  `backend` field suggests? This is the one thing blocking task 1.
- Does the 9500 generation actually flash through `storcli /cx download`, same as the
  9400? If it needs StorCLI2, the fix is a dependency rather than a prefix.
- `min_recommended` exists on the 9300-8i but nothing appears to read it. Intended for a
  future gate, or dead?
