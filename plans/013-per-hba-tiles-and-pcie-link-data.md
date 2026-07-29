# Plan 013: One dashboard tile per HBA, and real PCIe link data on storcli cards

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 5b27c93..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/dashboard.php source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh`
> If any changed since this plan was written, compare the "Current state"
> excerpts against the live code before proceeding; on a mismatch, treat it as a
> STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW
- **Depends on**: 012 (merged to `dev` as `761b18f`) — this plan edits the tile
  markup that 012 introduced
- **Category**: bug (missing PCIe data) + direction (user-requested UI change)
- **Planned at**: commit `5b27c93`, 2026-07-28
- **Branch from**: `dev`

## Why this matters

Two things came out of hardware-testing plan 012 on a live 2-controller box.

**1. The PCIe footer is nearly empty on storcli cards — a real data bug.**

The footer was supposed to show PCIe Width, PCIe Speed, Power Mode and PCI
Location. On the maintainer's SAS3 box it shows **only PCI Location**, in both
the expanded and collapsed states. The renderer is fine; the backend never
populated the other three. `parse/storcli_overview.sh` hardcodes them empty and
says so in its own header comment:

```bash
# PCIe width/speed/power are left empty — storcli doesn't report them; source
# those from lspci if that panel is wanted on SAS3/3.5 cards.
```

The lsiutil (SAS2) backend does populate them, which is why this went unnoticed:
every fixture-based test passes, and the gap only appears on real SAS3 hardware.

**2. The user wants one tile per HBA instead of one joint tile.**

Today all controllers render as stacked cards inside a single `<tbody>`. The
request is a separate dashboard tile per HBA, each independently positionable and
collapsible, and each keeping its temperature pill and its four footer fields
visible **in both the expanded and the collapsed state**.

Verbatim, so the executor does not have to infer it:

> When the dashboard tile is maximized and minimized, there should always be the
> temp pill at the top and the HBA model number, PCIe link speed, PCIe link
> width, and PCI location.

Note **Power Mode is not in that list**. Do not add work to source it for
storcli — see "Out of scope".

## Current state

### Multiple tiles are supported — this is already confirmed

From Unraid's own `/usr/local/emhttp/plugins/dynamix/DashStats.page:34-35`, read
on the box:

```php
global $mytiles;
if (isset($mytiles)) foreach ($mytiles as $tile) if (!empty($tile[$column])) echo $tile[$column];
```

It iterates the array and echoes each entry. **The key is arbitrary** — it is not
matched against a registered `.page`, a plugin name, or anything else. So a
single `.page` file can emit any number of tiles by writing several keys.
`HBAviewer_Dashboard.page` stays exactly as it is; no new `.page` files.

### The collapse mechanism is verified working — do not redesign it

Plan 012's collapsed-footer approach was confirmed on hardware: the pill and the
footer both survive minimise. **Keep that design.** For reference, it works
because Unraid collapses a tile by putting an inline `display: none` on every
`<tr>` after the first, so anything that must survive collapse has to live in row
1. The footer string is built once and emitted twice — at the bottom of the card
in row 2 (its natural place when expanded) and again in row 1, revealed by CSS
only while row 2 is collapsed:

```css
#tblHBAviewer .lu-d-foot-mini { display:none; padding:10px 0 2px; }
#tblHBAviewer:has(> tr:nth-child(2)[style*="display: none"]) .lu-d-foot-mini { display:block; }
```

The only change this plan makes to that mechanism is that **`#tblHBAviewer` must
become a class**, because there will now be several tiles and an `id` must be
unique. See Step 4.

### How the storcli backend is composed

`scripts/get_hba_info.sh:38-48` — the composer already resolves per-controller
sysfs data (PHY error counters) and passes it to the pure parser as an argument.
That is the pattern to follow for the PCIe fields:

```bash
ov_storcli() {   # $1 = controller index
    local perr=0 p f v
    for p in /sys/class/sas_phy/phy-"${1}":*/; do
        [ -d "$p" ] || continue
        for f in invalid_dword_count running_disparity_error_count loss_of_dword_sync_count phy_reset_problem_count; do
            v=$(cat "$p/$f" 2>/dev/null); perr=$(( perr + ${v:-0} ))
        done
    done
    { "$STORCLI" /c"$1" show; "$STORCLI" /c"$1" show temperature; } 2>/dev/null \
        | bash "$DIR/parse/storcli_overview.sh" "$ALERT" "$perr"
}
```

`parse/storcli_overview.sh` is a **pure stdin filter** — it takes storcli text on
stdin plus scalar arguments, and touches no hardware. Every test feeds it a
captured fixture. **Preserve that property**: the sysfs reads belong in the
composer, not in the filter.

Its current signature and output line:

```bash
ALERT="${1:-80}"
PHYERR="${2:-0}"    # total sysfs phy error counters for this controller (from composer)
CHIPARG="${3:-}"    # chip name from storcli AdapterType (covers every chipset; no ID map)
...
PCI=$(val "PCI Address")
...
{"temp":$TEMP,...,"pci_location":"${PCI}","pcie_width":"","pcie_speed":"","power_mode":"",...}
```

Note `$3` (CHIPARG) is **not currently passed** by the composer — it falls back
to the device-ID map. Leave that alone; new arguments go at `$4` and `$5`.

### The exact value formats to match

The lsiutil backend already emits these fields, and both backends must agree so
`view.php` and the tests stay uniform. From `scripts/parse/hba.sh:22-34`:

| Field | Format | Examples |
|---|---|---|
| `pcie_width` | `x` + lane count | `x1` `x2` `x4` `x8` `x16` |
| `pcie_speed` | `GenN (R GT/s)` | `Gen1 (2.5 GT/s)` `Gen2 (5.0 GT/s)` `Gen3 (8.0 GT/s)` |

Linux exposes both directly in sysfs — no `lspci` dependency, no output parsing:

| sysfs file | Example contents |
|---|---|
| `/sys/bus/pci/devices/<addr>/current_link_width` | `8` |
| `/sys/bus/pci/devices/<addr>/current_link_speed` | `8.0 GT/s PCIe` |

**The address needs converting.** storcli reports `PCI Address = 00:c1:00:00`
(domain:bus:device:function, each two hex digits). sysfs uses
`0000:c1:00.0` — a four-digit domain, and the function separated by a dot with no
leading zero. Step 1 does this conversion.

The two committed fixtures carry these addresses, already verified against the
conversion in Step 1:

| Fixture | `PCI Address` | sysfs directory |
|---|---|---|
| `tests/fixtures/storcli/overview_c0.txt` | `00:c1:00:00` | `0000:c1:00.0` |
| `tests/fixtures/storcli/overview_c1.txt` | `00:65:00:00` | `0000:65:00.0` |

## Commands you will need

```bash
bash tests/run.sh                       # golden-file suite; must end "--- all pass ---"
bash tests/run_php.sh                   # PHP unit tests (falls back to docker php:8.2-cli)
php -l <file>                           # syntax check; no local php — use:
docker run --rm -v "<abs-repo>:/w" -w /w php:8.2-cli php -l <file>
```

On Windows/Git Bash, prefix docker invocations with `MSYS_NO_PATHCONV=1` or the
`-w /w` argument is mangled into `W:/`.

## Scope

**In scope**:

- `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh`
- `source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh`
- `source/usr/local/emhttp/plugins/hbaviewer/dashboard.php`
- `tests/run.sh` and new fixtures/goldens under `tests/`

**Out of scope** (do NOT touch):

- **Power Mode for storcli.** The user did not ask for it and sysfs has no clean
  equivalent. `power_mode` stays `""` on the storcli path. Do not invent a value
  and do not remove the field from the JSON — the lsiutil path still uses it.
- `scripts/parse/hba.sh` — the lsiutil backend already emits all four fields
  correctly. This plan does not change SAS2 behaviour.
- `HBAviewer_Dashboard.page` — one `.page` emits many tiles; no change needed.
- `view.php` — `lsi_hba_view()` already returns `pcie` and `color`. Once the
  backend populates the fields, they flow through unchanged.
- `ajax_info.php` and the Monitor page. This is the dashboard tile only.
- `hbaviewer.plg`, `plugins/hbaviewer.xml`, `ca_profile.xml`.
- Any JavaScript.

## Git workflow

- Branch: `advisor/013-per-hba-tiles-and-pcie-link-data`, cut from `dev`
- **`git switch -c advisor/013-per-hba-tiles-and-pcie-link-data dev`** — cut from
  `dev`, not `main`. A worktree provisioned from `main` will not have plan 012's
  tile markup and every excerpt below will mismatch.
- One commit. Short imperative subject, no conventional-commit prefix. Suggested:
  `Dashboard: one tile per HBA; real PCIe link data on storcli cards`
- Do not push and do not open a PR.

## Steps

### Step 1: Read PCIe link width and speed in the composer

In `scripts/get_hba_info.sh`, rewrite `ov_storcli()` so it captures the storcli
output once, extracts the PCI address from it, resolves the sysfs directory, and
passes width and speed to the filter.

Capturing the output first is required — the address is only available *in* that
text, and the text must still reach the filter on stdin.

```bash
ov_storcli() {   # $1 = controller index
    local perr=0 p f v out pci dom bus dev fn dir width speed
    for p in /sys/class/sas_phy/phy-"${1}":*/; do
        [ -d "$p" ] || continue
        for f in invalid_dword_count running_disparity_error_count loss_of_dword_sync_count phy_reset_problem_count; do
            v=$(cat "$p/$f" 2>/dev/null); perr=$(( perr + ${v:-0} ))
        done
    done

    out=$({ "$STORCLI" /c"$1" show; "$STORCLI" /c"$1" show temperature; } 2>/dev/null)

    # storcli reports "PCI Address = 00:c1:00:00" (domain:bus:device:function).
    # sysfs wants "0000:c1:00.0" — four-digit domain, dot before the function.
    # PCIe link state is not in storcli's output at all, so read it from sysfs;
    # SYS_PCI_ROOT is overridable so the suite can point it at a fixture tree.
    width=""; speed=""
    pci=$(printf '%s\n' "$out" | grep -m1 -E '^PCI Address[[:space:]]*=' | sed 's/^[^=]*=[[:space:]]*//; s/[[:space:]]*$//')
    if [ -n "$pci" ]; then
        IFS=: read -r dom bus dev fn <<< "$pci"
        dir="${SYS_PCI_ROOT:-/sys/bus/pci/devices}/$(printf '%04x:%s:%s.%d' "0x${dom:-0}" "$bus" "$dev" "0x${fn:-0}")"
        v=$(cat "$dir/current_link_width" 2>/dev/null)
        [ -n "$v" ] && [ "$v" != "0" ] && width="x$v"
        v=$(cat "$dir/current_link_speed" 2>/dev/null)
        case "$v" in
            2.5*) speed="Gen1 (2.5 GT/s)"  ;;
            5.0*|5*) speed="Gen2 (5.0 GT/s)"  ;;
            8.0*|8*) speed="Gen3 (8.0 GT/s)"  ;;
            16*)  speed="Gen4 (16.0 GT/s)" ;;
            32*)  speed="Gen5 (32.0 GT/s)" ;;
        esac
    fi

    printf '%s\n' "$out" | bash "$DIR/parse/storcli_overview.sh" "$ALERT" "$perr" "" "$width" "$speed"
}
```

Three details that matter:

- **`""` is passed for `$3`.** CHIPARG keeps its position; do not renumber it.
- **A width of `0`** means the link is down; treat it as unknown rather than
  emitting `x0`.
- **`Gen4`/`Gen5` are included** because SAS3.5 (9500-series) cards are Gen4. The
  lsiutil map has no entries for them, which is correct — no SAS2 card is Gen4.

**Verify** the filter is still invoked with the storcli text on stdin:
`grep -c 'printf .%s\\n. "$out" | bash "$DIR/parse/storcli_overview.sh"' scripts/get_hba_info.sh` → prints `1`

**Verify** the composer no longer pipes the commands directly:
`grep -c 'show temperature; } 2>/dev/null \\' source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh` → prints `0`

### Step 2: Accept and emit the two new fields in the filter

In `scripts/parse/storcli_overview.sh`, add the two arguments next to the
existing ones:

```bash
ALERT="${1:-80}"
PHYERR="${2:-0}"    # total sysfs phy error counters for this controller (from composer)
CHIPARG="${3:-}"    # chip name from storcli AdapterType (covers every chipset; no ID map)
PCIEW="${4:-}"      # PCIe link width  (e.g. "x8") — sysfs, read by the composer
PCIES="${5:-}"      # PCIe link speed  (e.g. "Gen3 (8.0 GT/s)") — sysfs, read by the composer
```

Replace the stale header comment on lines 4-5:

```bash
# PCIe width/speed/power are left empty — storcli doesn't report them; source
# those from lspci if that panel is wanted on SAS3/3.5 cards.
```

with:

```bash
# storcli reports no PCIe link state, so width/speed arrive as $4/$5 — the
# composer reads them from sysfs, which keeps this a pure stdin filter.
# power_mode stays empty on this path; sysfs has no equivalent and the SAS3
# cards don't report one.
```

And in the output line, substitute the two variables:

```bash
{"temp":$TEMP,"model":"${CHIP}","firmware":"${FW}","bios":"${BIOS}","mode":"${MODE}","drive_count":"${DRIVES}","port_name":"","board_name":"${BOARD}","pci_location":"${PCI}","pcie_width":"${PCIEW}","pcie_speed":"${PCIES}","power_mode":"","alert_threshold":$ALERT,"status":"$STATUS"}
```

Defaulting both to empty means every existing test — which passes at most two
arguments — produces byte-identical output and all current goldens still pass.

**Verify**: `grep -c 'pcie_width":"${PCIEW}"' source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh` → prints `1`

**Verify**: `grep -c 'lspci' source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh` → prints `0`

**Verify existing goldens are untouched**: `bash tests/run.sh` → `--- all pass ---`

### Step 3: Cover the new path with tests

Two new cases.

**3a — the filter passes the values through.** Add to `tests/run.sh`, directly
after the existing `storcli-overview` line:

```bash
# PCIe link state arrives as $4/$5 from the composer (sysfs); storcli reports none
check storcli-overview-pcie storcli_overview_pcie.json bash "$P/storcli_overview.sh" 80 0 "" "x8" "Gen3 (8.0 GT/s)" < <(cat fixtures/storcli/overview_c0.txt fixtures/storcli/temp_c0.txt)
```

Create `tests/expected/storcli_overview_pcie.json` by copying
`tests/expected/storcli_overview.json` and setting the two fields. **Match the
existing goldens' trailing-newline convention exactly** — `check()` compares with
`printf '%s'`, so a stray newline fails the diff. Generate it rather than hand-
typing it:

```bash
bash source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh 80 0 "" "x8" "Gen3 (8.0 GT/s)" \
  < <(cat tests/fixtures/storcli/overview_c0.txt tests/fixtures/storcli/temp_c0.txt) \
  > tests/expected/storcli_overview_pcie.json
```

Then **read the generated file** and confirm it contains `"pcie_width":"x8"` and
`"pcie_speed":"Gen3 (8.0 GT/s)"` before trusting it. A golden generated from
broken code silently enshrines the bug.

**3b — the composer's sysfs read and address conversion.** This is the part most
likely to be wrong, so test it against a fixture tree rather than real hardware.
Create the directory that the fixture's PCI address maps to. First find that
address:

```bash
grep -m1 'PCI Address' tests/fixtures/storcli/overview_c0.txt
```

Convert it per Step 1's rule and create the tree — for example, if it reports
`00:c1:00:00`:

```bash
mkdir -p tests/fixtures/sys_pci/0000:c1:00.0
printf '8\n'             > tests/fixtures/sys_pci/0000:c1:00.0/current_link_width
printf '8.0 GT/s PCIe\n' > tests/fixtures/sys_pci/0000:c1:00.0/current_link_speed
```

Use whatever address the fixture actually contains — do not assume `00:c1:00:00`.
If controller `c1`'s fixture has a different address, create that directory too,
or the multi-controller golden will only be half-populated.

Then set `SYS_PCI_ROOT` for the stubbed-backend block in `tests/run.sh`. It goes
on the existing export line around line 55:

```bash
export STUB_FIX="$PWD/fixtures/storcli" STORCLI="$PWD/stub/storcli" LSI_CACHE=/dev/null SYS_PCI_ROOT="$PWD/fixtures/sys_pci"
```

The existing `route-storcli` check now exercises the whole composer path, so
`tests/expected/storcli_multi.json` must be regenerated to include the populated
fields. Regenerate it the same way — run the command, then **read the result and
confirm** the PCIe fields are populated for the controller(s) you created
directories for:

```bash
cd tests && STUB_FIX="$PWD/fixtures/storcli" STORCLI="$PWD/stub/storcli" LSI_CACHE=/dev/null SYS_PCI_ROOT="$PWD/fixtures/sys_pci" \
  bash ../source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh > expected/storcli_multi.json
```

**If the regenerated `storcli_multi.json` differs from the old one in any field
other than `pcie_width` and `pcie_speed`, STOP and report** — that means Step 1
changed composer behaviour beyond its remit, most likely by mangling the stdin
that reaches the filter.

**Verify**: `bash tests/run.sh` → `--- all pass ---`

### Step 4: One tile per HBA

In `dashboard.php`, the final section currently builds one `$body` string across
all controllers and emits a single tile keyed by `$pluginname`. Restructure so
each controller produces its own complete tile.

The mechanics, in order:

1. **`#tblHBAviewer` becomes a class.** An `id` must be unique and there are now
   several tiles. In the CSS block, replace every `#tblHBAviewer` selector with
   `.lu-d-tile`. In the markup, the `<tbody>` gets
   `id="tblHBAviewer{$i}" class="lu-d-tile"` — a unique id (harmless, and useful
   for debugging) plus the shared class the CSS targets.

   **The `:has()` rule must be converted too**, or the collapsed footer breaks:

   ```css
   .lu-d-tile:has(> tr:nth-child(2)[style*="display: none"]) .lu-d-foot-mini { display:block; }
   ```

2. **Emit one key per controller.** The key is arbitrary (see "Current state"),
   so index it:

   ```php
   $mytiles["{$pluginname}_c{$i}"]['column1'] = <<<EOT
   ...
   EOT;
   ```

3. **Per-tile header content.** Each tile now describes one card, so:
   - `<h3 class="tile-header-main">` — the board name, e.g. `HBA 9400-16i`
   - the subtitle `<span>` — `Controller /c{$i}`, or `$v['port_label']` which
     already produces exactly that string for storcli cards
   - the pill — that controller's temperature only, one pill, not a set
   - the `<svg>` `stroke` — that controller's own `$v['color']`, not the
     all-controller worst

4. **The footer carries all four fields, always.** Per the request, each tile's
   footer shows the model, PCIe speed, PCIe width, and PCI location. Build it
   once per controller and emit it twice — in row 2 at the bottom of the card,
   and in row 1 as `<div class="lu-d-foot-mini">` for the collapsed state. Row 1
   is the only place that survives collapse.

   The model must be included **unconditionally**. The current code only prefixes
   it when there is more than one controller:

   ```php
   . (count($controllers) > 1 ? '<b>' . htmlspecialchars($v['model']) . '</b>' : '')
   ```

   With one tile per card that condition is wrong in both directions — drop it
   and always emit the model.

5. **The error tile.** When `$error` is set there are no controllers to loop, so
   emit exactly one tile keyed `"{$pluginname}_err"` carrying the message. Keep
   the existing error markup.

   Separately, a controller with a **per-controller** error (`$c['error']` set
   while `$error` is not) must still get its own tile — with the error text in
   the body and no pill. Do not `continue` past it, which is what the current
   pill loop does; that made errored controllers invisible when collapsed.

6. **`Last read: {$ts}`** currently renders once after the last card. Emit it in
   each tile's body so every tile carries its own timestamp.

**Verify**: `grep -c '#tblHBAviewer' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `0`

**Verify**: `grep -c 'lu-d-tile' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints at least `2` (the CSS selectors and the markup)

**Verify** the `:has()` rule survived the rename intact:
`grep -c 'lu-d-tile:has(> tr:nth-child(2)\[style\*="display: none"\])' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `1`

**Verify** the tile key is per-controller:
`grep -c '_c{$i}' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `1`

**Verify** the model is no longer conditional on controller count:
`grep -c "count(\$controllers) > 1" source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` → prints `0`

### Step 5: Lint and full suite

```bash
bash tests/run.sh
bash tests/run_php.sh
MSYS_NO_PATHCONV=1 docker run --rm -v "<abs-repo>:/w" -w /w php:8.2-cli php -l source/usr/local/emhttp/plugins/hbaviewer/dashboard.php
```

All three must pass. `run.sh` must end `--- all pass ---`.

## Test plan

Automated (Step 3) covers the filter's pass-through and the composer's sysfs
read and address conversion.

**Not automatically verifiable — the operator will check these on hardware:**

1. Two HBAs produce **two separate dashboard tiles**, each independently
   draggable and collapsible.
2. Each tile's header shows that card's model, its own temperature pill, and its
   own status colour.
3. Each footer shows **model, PCIe Speed, PCIe Width, PCI Location** — all four
   populated, not just location.
4. Collapse one tile: header, pill and footer remain; the card body disappears.
   The other tile is unaffected.
5. The values are correct — cross-check against
   `lspci -vv -s <addr> | grep LnkSta` on the box.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'pcie_width":"${PCIEW}"' source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh` prints `1`
- [ ] `grep -c 'pcie_speed":"${PCIES}"' source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh` prints `1`
- [ ] `grep -c 'power_mode":""' source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh` prints `1` — deliberately still empty
- [ ] `grep -c 'lspci' source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh` prints `0`
- [ ] `grep -c 'current_link_width' source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh` prints `1`
- [ ] `grep -c 'current_link_speed' source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh` prints `1`
- [ ] `grep -c 'SYS_PCI_ROOT' source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh` prints `1`
- [ ] `grep -c 'current_link_width\|current_link_speed' source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh` prints `0` — the filter stays pure
- [ ] `grep -c '#tblHBAviewer' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `0`
- [ ] `grep -c 'lu-d-tile:has(> tr:nth-child(2)\[style\*="display: none"\])' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `1`
- [ ] `grep -c '_c{$i}' source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `1`
- [ ] `grep -c "count(\$controllers) > 1" source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` prints `0`
- [ ] `grep -c '"pcie_width":"x8"' tests/expected/storcli_overview_pcie.json` prints `1`
- [ ] `bash tests/run.sh` ends `--- all pass ---`
- [ ] `bash tests/run_php.sh` exits 0
- [ ] `php -l` on `dashboard.php` reports no syntax errors
- [ ] `git status --porcelain` shows only the four in-scope source/test files plus
      the new fixtures and goldens

## STOP conditions

Stop and report instead of improvising if:

- **The regenerated `storcli_multi.json` differs in any field other than
  `pcie_width` and `pcie_speed`.** That means Step 1 altered what reaches the
  filter on stdin — a real regression, not a golden to bless.
- **`tests/fixtures/storcli/overview_c0.txt` has no `PCI Address` line.** The
  whole sysfs lookup hangs off it. Report what the fixture does contain.
- **Any existing golden fails after Step 2.** The new arguments default to empty
  precisely so that cannot happen; if it does, something else broke.
- **Unraid turns out to key tile position or collapse state off the array key**
  in a way that makes `HBAviewer_c0` unstable across reboots. This plan assumes
  the key is free-form based on `DashStats.page` simply echoing each entry. If
  you find contrary evidence in Unraid's source, report it — the fallback is one
  tile with per-card sections, i.e. today's behaviour.
- You are tempted to source Power Mode for storcli. It is explicitly out of
  scope.
- You are tempted to change `scripts/parse/hba.sh`. The SAS2 path is correct and
  **cannot be hardware-tested** — the maintainer has no SAS2 card, so a
  regression there would ship unnoticed.

## Maintenance notes

- **The sysfs link files are the authority, not storcli.** `current_link_*`
  reflects the *negotiated* link, so a card in a physically x8 slot running at x4
  will correctly report `x4`. `max_link_*` sits alongside them if a
  "negotiated vs capable" comparison is ever wanted — that is the natural place
  to surface "your HBA is in the wrong slot", which is a common Unraid problem.
- **The two backends must keep emitting identical formats.** `x8` and
  `Gen3 (8.0 GT/s)` are set by `parse/hba.sh`; if either side's format changes,
  change both and update both goldens.
- **`SYS_PCI_ROOT` exists for the test suite**, mirroring `LSI_CACHE` and
  `STUB_FIX`. It is not a user-facing setting and should not be documented as
  one.
- **Tile keys are `HBAviewer_c<N>`**, tied to the storcli controller index. If a
  card is removed, the surviving cards keep their indices as storcli reports
  them — the tiles are not renumbered by this code.
- **What a reviewer should scrutinise**: that the PCI-address-to-sysfs conversion
  handles the four-part storcli format, that the filter still takes zero sysfs
  reads, and that the `:has()` selector survived the id-to-class rename — that
  last one silently degrades rather than erroring, so the footer would just stop
  appearing when collapsed.
