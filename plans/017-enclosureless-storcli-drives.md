# Plan 017: Enumerate storcli drives on controllers that report no enclosure

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 8286fe7..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/get_attached_drives.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_drives.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_enclosures.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php tests/run.sh`
> Expected output: **nothing**. Every excerpt below is quoted from `8286fe7`.
> Any difference is a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MEDIUM — changes what the Drives tab reads on every storcli box
- **Depends on**: none
- **Category**: bug
- **Planned at**: `8286fe7`, 2026-07-31
- **Issues**: [#5](https://github.com/FugginOld/Unraid-HBAviewer/issues/5) (drives half),
  [#6](https://github.com/FugginOld/Unraid-HBAviewer/issues/6)

## Why this matters

Three users on three different SAS3 chips report an empty **Drives** tab while
every other tab works. The cause is confirmed on real hardware and it is not one
bug but four.

Controllers whose drives carry **no enclosure ID** address them as `/c0/s0`, not
`/c0/e0/s0`. The plugin only ever asks the enclosure-scoped form:

```bash
storcli /c0/eall/sall show all      # -> "Status = Failure / Description = No drive found!"
storcli /c0/sall show all           # -> 15 drives
```

The PD LIST shows why — the `EID` column is blank:

```text
EID:Slt DID State DG      Size Intf Med SED PI SeSz Model
 :0       1 JBOD  -  23.647 TB SATA HDD -   -  512B ST26000NM000C-3WE103
```

Note this is **not** "the controller has no enclosure". Issue #6's box *does*
report a `VirtualSES` enclosure — it simply has no drives associated with it. So
the fix must trigger on **"the enclosure query returned no drives"**, never on
"no enclosure exists".

The empty tab is the visible symptom. Three quieter defects ride along:

- **Drive health is unmonitored.** `storcli_overview.sh`'s drive-state scrape keys
  off `^[0-9]+:[0-9]+`, which a blank EID never matches, so a failed drive on
  these controllers raises nothing.
- **IT/IR mode renders blank** on IR firmware, which reports `UGood` where IT
  firmware reports `JBOD`.
- **The enclosure line prints invented numbers** — "8 slots · 0 drives" on a
  controller with 15 drives, from a `Properties` section that is empty.

## Evidence — real output from three boxes

**Issue #6, SAS3416 (9400-16e), SATA, IT firmware.** `/c0/sall show all`:

```text
Drive /c0/s0 :
============

----------------------------------------------------------------------------
EID:Slt DID State DG      Size Intf Med SED PI SeSz Model                Sp
----------------------------------------------------------------------------
 :0       1 JBOD  -  23.647 TB SATA HDD -   -  512B ST26000NM000C-3WE103 -
----------------------------------------------------------------------------

EID-Enclosure Device ID|Slt-Slot No|DID-Device ID|DG-DriveGroup
UGood-Unconfigured Good|UBad-Unconfigured Bad|Intf-Interface
...

Drive /c0/s0 Device attributes :
==============================
Model Number = ST26000NM000C-3WE103
SN =             XXXXXXXX
WWN = XXXXXXXXXXXXXXXXX
Firmware Revision = SN02
Raw size = 23.647 TB [0xbd2dfffff Sectors]
Link Speed = 12.0Gb/s
Connector Name = C0   & C1   x8

Drive /c0/s0 Policies/Settings :
==============================
Enclosure position = -
Connected Port Number = 8(path0)
```

**Issue #5, SAS3224, SATA, IR firmware** — same shape, four fields differ:

```text
 :0       0 UGood -  3.638 TB SATA HDD -   N  512B ST4000VN008-2DR166 -
...
Model Number = ST4000VN008-2DR166
Firmware Revision = SC60
Device Speed = Unknown
Link Speed = 6.0Gb/s
Connector Name = N/A
...
Connected Port Number = 0
```

`UGood` not `JBOD`, `Connector Name = N/A`, `Connected Port Number` with no
`(path0)` suffix, `PI = N` not `-`. The parser must survive all four.

**Completeness, from issue #6** — the fallback is not lossy:

```text
drive blocks : 15
PD LIST rows : 15
(no duplicate slots)
```

**And the enclosure's Properties section is empty**, which is why its slots/drives
figures are fiction.

## Current state

Excerpts from `8286fe7`.

### 1. `scripts/get_attached_drives.sh` — the composer asks one form only

```bash
drv_storcli() {   # $1 = controller index
    local encl drv
    encl=$("$STORCLI" /c"$1"/eall show all      2>/dev/null | bash "$DIR/parse/storcli_enclosures.sh")
    drv=$( "$STORCLI" /c"$1"/eall/sall show all 2>/dev/null | bash "$DIR/parse/storcli_drives.sh")
    [ -n "$encl" ] || encl='{"enclosures":[]}'
    [ -n "$drv" ]  || drv='{"drives":[]}'
    printf '%s,%s' "${encl%\}}" "${drv#\{}"     # merge two single-key objects into one
}
```

### 2. `scripts/parse/storcli_drives.sh` — the header and state patterns

```awk
/^Drive \/c[0-9]+\/e[0-9]+\/s[0-9]+ :[ \t]*$/ {
    if (have) emit()
    match($0, /e([0-9]+)\/s([0-9]+)/, a); eid=a[1]; slot=a[2]
    model=""; sn=""; state=""; wwn=""; size=""; link=""; fw=""; port=""; have=1
    next
}
have && /^[0-9]+:[0-9]+[ \t]/       { state=$3 }   # summary row: EID:Slt DID State ...
```

and the emit builds the slot as `eid"/"slot`.

### 3. `scripts/parse/storcli_overview.sh:48-51` — MODE, from a whole-input grep

```bash
# IT vs IR from the drive states: JBOD = passthrough (IT); RAID/Onln/Optl = IR.
if   printf '%s\n' "$input" | grep -qE '\bJBOD\b';          then MODE="IT"
elif printf '%s\n' "$input" | grep -qE '\b(Onln|Optl|RAID)\b'; then MODE="IR"
else MODE=""; fi
```

**This runs at line 49, before `DSTATES` exists at line 83.** That ordering is why
the fix restructures rather than patching the regex.

### 4. `scripts/parse/storcli_overview.sh:83-87` — the drive-state scrape

```bash
DSTATES=$(printf '%s\n' "$input" | awk '/^[0-9]+:[0-9]+[ \t]/ { print $3 }')
if printf '%s\n' "$DSTATES" | grep -qiE '^(Failed|Offln|Missing|UBad|Foreign)$'; then
    [ "$RANK" -lt 2 ] && RANK=2
elif printf '%s\n' "$DSTATES" | grep -qiE '^(Rbld|Rebuild|Copyback)$'; then
    [ "$RANK" -lt 1 ] && RANK=1
fi
```

Its own comment says scanning the whole output would false-match legend text such
as `UBad-Unconfigured Bad` — that warning is the key to Step 3.

### 5. `ajax_info.php:335-343` — the enclosure line

```php
        foreach ($ctl['enclosures'] ?? [] as $e) {
            $mode = !empty($e['direct']) ? 'direct-attach (no expander)' : 'expander / backplane';
            $out .= '<p class="lu-muted" style="font-size:12px;margin:0 0 8px">Enclosure e' . htmlspecialchars($e['eid'])
                  . ': ' . htmlspecialchars($e['product']) . ' (' . htmlspecialchars($e['vendor']) . ') &middot; '
                  . htmlspecialchars($e['slots']) . ' slots &middot; ' . htmlspecialchars($e['drives'])
                  . ' drives &middot; ' . $mode . '</p>';
        }
```

### 6. Repo conventions

- Parsers are **pure stdin filters** that emit JSON; composers do the I/O.
- gawk 3-argument `match()` is used throughout and is available on Unraid —
  `parse/phy.sh` relies on it and works on every reporter's box.
- Error payloads are `{"error":"..."}`.
- Goldens live in `tests/expected/`, fixtures in `tests/fixtures/`, and are
  registered in `tests/run.sh` with `check <name> <expected-file> <command>`.
- Deliberate simplifications carry a `ponytail:` comment naming the ceiling and
  the upgrade path.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Shell lint | `find source tests -name '*.sh' -print0 \| xargs -0 -r -n1 bash -n` | exit 0 |
| PHP lint | `find source tests -name '*.php' -print0 \| xargs -0 -r -n1 php -l` | exit 0 |
| Full suite | `bash tests/run.sh` | `--- all pass ---`, exit 0 |

`php` may be absent; the suite falls back to a `php:8.2-cli` Docker image.

## Scope

**In scope**:

- `scripts/get_attached_drives.sh` — add the `/cN/sall` fallback
- `scripts/parse/storcli_drives.sh` — accept both address forms
- `scripts/parse/storcli_overview.sh` — widen the state scrape, derive MODE from it
- `scripts/parse/storcli_enclosures.sh` — stop inventing slots/drives
- `ajax_info.php` — omit the slots/drives clause when absent
- `tests/fixtures/storcli/` — two new fixtures from real hardware
- `tests/run.sh`, `tests/expected/` — new goldens

**Out of scope** (do NOT touch):

- **The lsiutil path** in `get_attached_drives.sh`. Untouched by this bug.
- **The `eall/sall` call itself.** It must stay and stay **first** — on a
  controller *with* enclosure-attached drives, `/cN/sall` fails with
  "No drive found!". The two forms are complements, not replacements.
- **`PHYERR_FLOOR`, the temperature bands, `cfg_band`** — settled in 018/019.
- **Anything in the HBA Health tab.** Plan 020 is on a separate unmerged branch;
  it does not touch these files, so keep it that way.

## Git workflow

- Branch: `advisor/017-enclosureless-storcli-drives`, cut from `dev` (`8286fe7`)
- Three or four commits. Short imperative subjects.
- Do NOT push or open a PR.

## Steps

### Step 1: Accept both drive-address forms in the parser

In `scripts/parse/storcli_drives.sh`, replace the header rule quoted in "Current
state 2" with one that makes the enclosure segment optional:

```awk
/^Drive \/c[0-9]+(\/e[0-9]+)?\/s[0-9]+ :[ \t]*$/ {
    if (have) emit()
    # Enclosure-less controllers address drives /c0/s0 with a blank EID column;
    # enclosure-attached ones use /c0/e0/s0. Capture the two parts separately so
    # the absent EID is an empty string rather than a failed match.
    eid = match($0, /\/e([0-9]+)\//, a) ? a[1] : ""
    match($0, /\/s([0-9]+)[ \t]*:/, b); slot = b[1]
    model=""; sn=""; state=""; wwn=""; size=""; link=""; fw=""; port=""; have=1
    next
}
```

Widen the summary-row rule so a blank EID still yields the state. `$3` is still
the State column: awk ignores leading blanks, so ` :0  1 JBOD` gives `$1=":0"`,
`$2="1"`, `$3="JBOD"`.

```awk
have && /^[ \t]*[0-9]*:[0-9]+[ \t]/ { state=$3 }   # summary row: EID:Slt DID State ...
```

And in `emit()`, drop the `/` when there is no EID:

```awk
        (eid == "" ? slot : eid"/"slot), port, model, sn, state, wwn, size, link, fw
```

**Verify** against the existing enclosure-attached fixture — output must be
byte-identical to today:

```bash
bash source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_drives.sh \
  < tests/fixtures/storcli/drives_c0.txt
```

→ must still start `{"drives":[{"slot":"0/0"` — if the slot lost its `0/` prefix,
the optional-EID capture is wrong.

### Step 2: The two new fixtures, from real hardware

Both fixtures are given **in full below**. Copy them byte for byte. Serials and
WWNs were already redacted by the reporters. **Do not invent additional drives,
re-wrap lines, or tidy whitespace** — the leading space before `:0`, the trailing
spaces after `ATA` and `SC60`, and the blank lines are exactly what the parser
must cope with. An earlier attempt at this fix used a hand-typed row and the
reconstruction was subtly wrong.

`tests/fixtures/storcli/drives_noencl_jbod.txt` — issue #6, SAS3416, IT firmware:

```text
Drive /c0/s0 :
============

----------------------------------------------------------------------------
EID:Slt DID State DG      Size Intf Med SED PI SeSz Model                Sp 
----------------------------------------------------------------------------
 :0       1 JBOD  -  23.647 TB SATA HDD -   -  512B ST26000NM000C-3WE103 -  
----------------------------------------------------------------------------

EID-Enclosure Device ID|Slt-Slot No|DID-Device ID|DG-DriveGroup
UGood-Unconfigured Good|UBad-Unconfigured Bad|Intf-Interface
Med-Media Type|SED-Self Encryptive Drive|PI-Protection Info
SeSz-Sector Size|Sp-Spun|U-Up|D-Down|T-Transition


Drive /c0/s0 - Detailed Information :
===================================

Drive /c0/s0 State :
==================
Shield Counter = N/A
Media Error Count = N/A

Drive /c0/s0 Device attributes :
==============================
Manufacturer Id = ATA     
Model Number = ST26000NM000C-3WE103
NAND Vendor = NA
SN =             XXXXXXXX
WWN = XXXXXXXXXXXXXXXXX
Firmware Revision = SN02    
Raw size = 23.647 TB [0xbd2dfffff Sectors]
Device Speed = 6.0Gb/s
Link Speed = 12.0Gb/s
Sector Size = 512B
Connector Name = C0   & C1   x8

Drive /c0/s0 Policies/Settings :
==============================
Enclosure position = -
Connected Port Number = 8(path0) 
Sequence Number = 0
```

`tests/fixtures/storcli/drives_noencl_ugood.txt` — issue #5, SAS3224, IR firmware:

```text
Drive /c0/s0 :
============

-------------------------------------------------------------------------
EID:Slt DID State DG     Size Intf Med SED PI SeSz Model              Sp 
-------------------------------------------------------------------------
 :0       0 UGood -  3.638 TB SATA HDD -   N  512B ST4000VN008-2DR166 -  
-------------------------------------------------------------------------

EID-Enclosure Device ID|Slt-Slot No|DID-Device ID|DG-DriveGroup
UGood-Unconfigured Good|UBad-Unconfigured Bad|Intf-Interface
Med-Media Type|SED-Self Encryptive Drive|PI-Protection Info
SeSz-Sector Size|Sp-Spun|U-Up|D-Down|T-Transition


Drive /c0/s0 - Detailed Information :
===================================

Drive /c0/s0 State :
==================
Shield Counter = N/A
Media Error Count = N/A

Drive /c0/s0 Device attributes :
==============================
Manufacturer Id = ATA     
Model Number = ST4000VN008-2DR166
NAND Vendor = NA
SN =             XXXXXXXXX
WWN = XXXXXXXXXXXXX
Firmware Revision = SC60    
Raw size = 3.638 TB [0x1d1c0beaf Sectors]
Device Speed = Unknown
Link Speed = 6.0Gb/s
Sector Size = 512B
Connector Name = N/A

Drive /c0/s0 Policies/Settings :
==============================
Enclosure position = -
Connected Port Number = 0 
Sequence Number = 0
```

For reference, the patched parser was run against both while this plan was
written. Expected output, which the goldens should match:

```json
{"drives":[{"slot":"0","port":"8","model":"ST26000NM000C-3WE103","serial":"XXXXXXXX","state":"JBOD","sas_address":"XXXXXXXXXXXXXXXXX","size":"23.647 TB","link":"12.0Gb/s","firmware":"SN02"}]}
{"drives":[{"slot":"0","port":"0","model":"ST4000VN008-2DR166","serial":"XXXXXXXXX","state":"UGood","sas_address":"XXXXXXXXXXXXX","size":"3.638 TB","link":"6.0Gb/s","firmware":"SC60"}]}
```

Register two goldens in `tests/run.sh`, next to the existing `storcli-drives`
check:

```bash
# Enclosure-less controllers (blank EID in PD LIST) address drives /c0/sN. Real
# output: issue #6 is a SAS3416 on IT firmware (JBOD), issue #5 a SAS3224 on IR
# firmware (UGood, no (path0) suffix on the port, Connector Name = N/A).
check storcli-drives-noencl-jbod  storcli_drives_noencl_jbod.json  bash "$P/storcli_drives.sh" < fixtures/storcli/drives_noencl_jbod.txt
check storcli-drives-noencl-ugood storcli_drives_noencl_ugood.json bash "$P/storcli_drives.sh" < fixtures/storcli/drives_noencl_ugood.txt
```

Generate the two expected files with `UPDATE=1 bash tests/run.sh`, then **read
both** and confirm each contains a real drive with `"slot":"0"` (no `/`), the
right `state` (`JBOD` / `UGood`), model, size, link and firmware.

**Verify**: `git diff -- tests/expected/` shows **only the two new files**. Any
pre-existing golden changing is a STOP condition — Step 1 must not alter the
enclosure-attached output.

### Step 3: Widen the state scrape, and derive MODE from it

In `scripts/parse/storcli_overview.sh`, **move** the `DSTATES` assignment from
line 83 up to just above the MODE block at line 48, then replace the MODE block:

```bash
# Drive states from the drive-summary table's State column ONLY ($3 of rows like
# "0:0  15 JBOD ..." or " :0  1 UGood ..." where the controller reports no
# enclosure ID). Scanning the whole output would false-match legend text such as
# "UGood-Unconfigured Good|UBad-Unconfigured Bad".
DSTATES=$(printf '%s\n' "$input" | awk '/^[ \t]*[0-9]*:[0-9]+[ \t]/ { print $3 }')

# IT vs IR from those states, NOT from a whole-output grep: IT firmware reports
# JBOD, IR firmware reports UGood/UBad for a bare disk and Onln/Optl for a
# configured one. A grep over the raw text would match the legend line and call
# every card IR.
if   printf '%s\n' "$DSTATES" | grep -qiE '^JBOD$';                    then MODE="IT"
elif printf '%s\n' "$DSTATES" | grep -qiE '^(Onln|Optl|UGood|UBad)$';  then MODE="IR"
else MODE=""; fi
```

Delete the old `DSTATES=` line at 83, leaving the two health-rollup `grep`s that
consume it exactly as they are.

**Verify** MODE now resolves for both firmware modes. Feed a **bare temperature
line** plus the fixture — do **not** prepend `overview_c0.txt`, which carries its
own `JBOD` PD LIST and would make both fixtures report `IT` regardless of the
code. (That mistake was made while writing this plan; the check passed for the
wrong reason.)

```bash
P=source/usr/local/emhttp/plugins/hbaviewer/scripts/parse
for f in tests/fixtures/storcli/drives_noencl_jbod.txt tests/fixtures/storcli/drives_noencl_ugood.txt; do
  printf '%-34s ' "$(basename $f)"
  { printf 'ROC temperature(Degree Celsius) 50\n'; cat "$f"; } \
    | bash "$P/storcli_overview.sh" 76 0 | grep -o '"mode":"[A-Z]*"'
done
```

→ prints `"mode":"IT"` for the JBOD fixture and `"mode":"IR"` for the UGood one.

For reference, the same command against the **unfixed** parser prints
`"mode":"IT"` and `"mode":""` — the blank is the bug being fixed, and it is the
before/after this step must move.

**Verify** the legend cannot trigger IR on its own:

```bash
printf 'ROC temperature(Degree Celsius) 50\nUGood-Unconfigured Good|UBad-Unconfigured Bad|Intf-Interface\n' \
  | bash "$P/storcli_overview.sh" 76 0 | grep -o '"mode":"[A-Z]*"'
```

→ prints **nothing** (mode empty). If it prints `"mode":"IR"` the scrape is still
reading the legend and Step 3 is not done.

### Step 4: The composer falls back when the enclosure form finds nothing

In `scripts/get_attached_drives.sh`, replace `drv_storcli` with:

```bash
drv_storcli() {   # $1 = controller index
    local encl drv
    encl=$("$STORCLI" /c"$1"/eall show all      2>/dev/null | bash "$DIR/parse/storcli_enclosures.sh")
    drv=$( "$STORCLI" /c"$1"/eall/sall show all 2>/dev/null | bash "$DIR/parse/storcli_drives.sh")
    # Controllers whose drives carry no enclosure ID answer the eall form with
    # "No drive found!" and address their drives /cN/sN instead. Try the flat form
    # only when the enclosure form yielded nothing — the order matters, because on
    # a controller WITH enclosure-attached drives it is /cN/sall that fails.
    # Keyed on "no drives came back", never on "no enclosure exists": issue #6's
    # box reports a VirtualSES enclosure that simply has no drives attached to it.
    case "$drv" in
        ''|'{"drives":[]}')
            drv=$("$STORCLI" /c"$1"/sall show all 2>/dev/null | bash "$DIR/parse/storcli_drives.sh") ;;
    esac
    [ -n "$encl" ] || encl='{"enclosures":[]}'
    [ -n "$drv" ]  || drv='{"drives":[]}'
    printf '%s,%s' "${encl%\}}" "${drv#\{}"     # merge two single-key objects into one
}
```

**Verify**: `bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/get_attached_drives.sh` → exit 0

**Verify**: the existing `drives-route` golden still passes — the stub returns
enclosure-attached drives, so the fallback must **not** fire there.

### Step 5: Stop the enclosure line inventing slots and drives

Issue #6's controller reports an empty `Properties` section, yet the UI prints
"8 slots · 0 drives" for 15 drives — the parser's data-row pattern matched
something that is not a properties row.

In `scripts/parse/storcli_enclosures.sh`, make the properties row require all four
columns to be genuinely present, and leave `slots`/`drives` empty otherwise. The
existing rule is:

```awk
have && /^[ \t]*[0-9]+[ \t]+[A-Za-z]+[ \t]+[0-9]+[ \t]+[0-9]+[ \t]/ { state=$2; slots=$3; drives=$4 }
```

Anchor it to the section so a stray table elsewhere cannot match — set a flag when
a line beginning `Properties` is seen, clear it at the next `Inquiry Data` or
`EnclSasAddress` header, and only accept the data row while that flag is set. Add
a `ponytail:` comment naming the ceiling: a section-scoped scrape, not a full
parse of storcli's table layout.

Then in `ajax_info.php`, emit the slots/drives clause only when both are non-empty:

```php
        foreach ($ctl['enclosures'] ?? [] as $e) {
            $mode  = !empty($e['direct']) ? 'direct-attach (no expander)' : 'expander / backplane';
            // Only state a slot/drive count when storcli actually reported one —
            // an empty Properties section previously rendered as "8 slots · 0 drives"
            // on a controller with 15 drives.
            $counts = ($e['slots'] ?? '') !== '' && ($e['drives'] ?? '') !== ''
                ? htmlspecialchars($e['slots']) . ' slots &middot; ' . htmlspecialchars($e['drives']) . ' drives &middot; '
                : '';
            $out .= '<p class="lu-muted" style="font-size:12px;margin:0 0 8px">Enclosure e' . htmlspecialchars($e['eid'])
                  . ': ' . htmlspecialchars($e['product']) . ' (' . htmlspecialchars($e['vendor']) . ') &middot; '
                  . $counts . $mode . '</p>';
        }
```

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` → clean

**Verify**: the existing `storcli-encl` golden still passes — the committed
fixture has a real Properties section, so its counts must survive.

### Step 6: Lint and full suite

**Verify**: `find source tests -name '*.sh' -print0 | xargs -0 -r -n1 bash -n` → exit 0

**Verify**: `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` → exit 0

**Verify**: `bash tests/run.sh` → `--- all pass ---`, exit 0

**Verify**: `git diff -- tests/expected/` lists **only** the two new golden files.

## Test plan

- **Two fixtures captured from real hardware**, one per firmware mode, are the
  core. Both come from reporters' terminals with serials already redacted; neither
  is reconstructed. An earlier attempt at this fix used a hand-typed row and the
  reconstruction was subtly wrong, which is why this is stated so firmly.
- **Step 1's regression check** proves the enclosure-attached path is byte-identical.
- **Step 3's negative test** — the legend line alone must not yield `IR` — is the
  one that guards the false-match the file's own comment warns about.
- **Step 4** relies on the existing `drives-route` golden to prove the fallback
  does not fire when the enclosure form works.
- No new PHP test: `ajax_info.php`'s change is a conditional around existing
  markup, and no PHP test asserts that markup today.

## Done criteria

- [ ] `grep -c 'e(\[0-9\]+)/s(\[0-9\]+)' source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_drives.sh` prints `0` (old combined match gone)
- [ ] Step 1's regression check still prints `{"drives":[{"slot":"0/0"`
- [ ] `ls tests/fixtures/storcli/drives_noencl_*.txt | wc -l` → `2`
- [ ] Both new goldens contain `"slot":"0"` with no `/`, and states `JBOD` and `UGood`
- [ ] Step 3 prints `"mode":"IT"` and `"mode":"IR"` for the two fixtures
- [ ] Step 3's legend-only input prints **no** mode
- [ ] `grep -c '/c"$1"/sall show all' source/usr/local/emhttp/plugins/hbaviewer/scripts/get_attached_drives.sh` → `1`
- [ ] `git diff -- tests/expected/` lists only the two new files
- [ ] Both lints exit 0; `bash tests/run.sh` exits 0 with `--- all pass ---`
- [ ] `git status --porcelain` lists only the six in-scope files plus the two
      fixtures and two goldens

## STOP conditions

- The drift check prints anything.
- **A pre-existing golden changes.** Step 1 widens a pattern; it must not alter
  what the enclosure-attached fixture produces.
- The `/cN/sall` call is placed **before** `/cN/eall/sall`, or replaces it. On a
  controller with enclosure-attached drives, `/cN/sall` fails — the order is load-bearing.
- The fallback is keyed on "no enclosure exists" rather than "no drives came
  back". Issue #6's controller has a `VirtualSES` enclosure and still needs the
  fallback.
- You find yourself hand-writing or "tidying" fixture text. Both fixtures are
  verbatim real output; a reconstruction already sent this fix down a wrong path once.
- You find yourself touching the lsiutil path, the HBA Health tab, or anything
  plan 018/019 settled.

## Follow-ups this plan does not do

1. **The `Connector Name` / `Connected Port Number` variance** is absorbed by the
   existing `sub(/[ \t(].*/, "", port)`, verified against both fixtures. If a third
   format appears, that substitution is where it lands.
2. **Per-drive `/dev/` names on the storcli backend.** storcli does not map them;
   the lsiutil path joins sysfs to get them. Out of scope, and the UI already
   shows what each backend can provide.
3. **A full parse of storcli's enclosure table.** Step 5 scopes the scrape to the
   Properties section; it does not model storcli's table layout properly.

## Maintenance notes

- **The two drive-address forms are complements.** `/cN/eall/sall` works only with
  enclosure-attached drives; `/cN/sall` only without. Neither is a superset, which
  is why the composer tries one and falls back rather than picking by inspection.
- **Blank EID is the signature**, not "no enclosure". A controller can report a
  `VirtualSES` enclosure with no drives attached to it — issue #6's does.
- **`UGood` means IR firmware, not a fault.** It is "Unconfigured Good": a bare
  disk on RAID firmware, exactly what `JBOD` means on IT firmware. It must never
  raise the health rollup; only `UBad` and friends do.
- **MODE derives from the parsed state column, never a whole-output grep.** The
  legend line contains `UGood-Unconfigured Good|UBad-Unconfigured Bad`, so a raw
  grep marks every controller IR. The file's existing comment already warned about
  this for the health scrape; Step 3 extends the same discipline to MODE.
- **What a reviewer should scrutinise**: that the enclosure-attached golden is
  byte-identical, that the fallback cannot fire when the enclosure form succeeds,
  and that the two fixtures are unmodified reporter output.
