# Plan 047: Graphical drive bay map on the Drives tab

> **DONE — executed, hardware-verified and archived 2026-08-04.** Every done
> criterion below is met. The design was then replaced wholesale by the
> maintainer's `1b` handoff (health-as-colour), which is archived beside this
> file in [`047-design-handoff-drive-bay-map/`](047-design-handoff-drive-bay-map/)
> — read that for what the map actually looks like now; this plan is the
> mechanism underneath it and is still accurate about the store, the identity
> key and the SMART join. Four things shipped after it that this plan never
> specified: the lock, double-click-to-clear, Unraid slot names on every table,
> and a SMART cache with no TTL. See `plans/README.md` for the one-line history.

---

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat d7d7fa7..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_drives.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_enclosures.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/smart.sh source/usr/local/emhttp/plugins/hbaviewer/config.php`
> Expected output: **nothing**. Every excerpt below is quoted from `d7d7fa7`
> (release 2026.08.04.1). Any difference is a STOP condition.
>
> **Re-stamped 2026-08-04** from `8286fe7` to `d7d7fa7`. The plan was written
> against the 2026-07-30 `dev` tip; issues #10 and #11 then landed 800+ lines
> across these same files. Every contract this plan quotes was re-verified
> against `d7d7fa7` and holds — the excerpts below are updated where the
> surrounding code moved, and the two substantive consequences are called out
> inline: `storcli_drives.sh`'s `slot` is now conditional (which strengthens
> the case for keying on `port`), and `renderDrivesTables()` has a second
> parameter. Step 1's question is also already answered by shipped code; see
> that step.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW — new tab content, additive; the assignment store is the
  only new persisted state
- **Depends on**: none to ship. **Informational cross-reference**: plan 017
  (not yet written) is tracking a storcli `EID:Slt` state-scrape bug on
  certain expander-backed boxes (blank `EID`, IT/IR mode detection). This
  plan's own reference hardware (Supermicro SAS846TQ, direct-attach, no
  expander) is a different topology than 017's affected boxes and is not
  known to hit that bug — but if 017 lands first, re-check its fix doesn't
  change the `state`-field shape this plan reads.
- **Category**: feature
- **Planned at**: `8286fe7`, 2026-07-31
- **Requested by**: maintainer (Drives tab feature request, external
  roadmap review session, 2026-07-31) — design settled interactively:
  grid is **4 columns × 6 rows** (not the reverse), bay cells are
  **rectangular at a 3:1 width:height ratio**, resembling a physical
  drive rather than a square tile

## Why this matters

The Drives tab already lists every attached drive in a table
(`renderDrivesTables()`), but a table has no spatial relationship to the
physical chassis — knowing "slot e0/s14 is in Warning state" doesn't tell
you which of 24 physical bays to walk over to. A graphical bay map closes
that gap the same way plan 024's SES locate button does, but without
needing any hardware LED support — pure software, works on every backplane
including passthrough boards with no SES chip at all.

## Current state

### `storcli_drives.sh` — the per-drive identity this plan keys on

```awk
printf "{\"slot\":\"%s\",\"port\":\"%s\",\"model\":\"%s\",\"serial\":\"%s\",\"state\":\"%s\",\"sas_address\":\"%s\",\"size\":\"%s\",\"link\":\"%s\",\"firmware\":\"%s\"}", \
    (eid == "" ? slot : eid"/"slot), port, model, sn, state, wwn, size, link, fw
...
have && /^Connected Port Number =/   { port=val($0); sub(/[ \t(].*/, "", port) }  # "14(path0)" -> "14"
```

(`slot` became conditional in issue #6: a controller whose drives carry no
enclosure ID emits a bare slot number. `port` is byte-identical to the
original excerpt. The change only sharpens this plan's own conclusion — `slot`
means two different things depending on the backplane, `port` means one.)

`slot` is `eid/slot` — real per-bay addressing **only on a backplane with
an SES expander**. `port` (`Connected Port Number`) is the HBA PHY the
drive is wired to — present regardless of backplane type, and the field
this plan actually keys the bay assignment on, for the reason below.

### `renderDrivesTables()` (`ajax_info.php`) — confirms the passthrough case

```php
foreach ($ctl['enclosures'] ?? [] as $e) {
    $mode = !empty($e['direct']) ? 'direct-attach (no expander)' : 'expander / backplane';
    $out .= '<p class="lu-muted" ...>Enclosure e' . htmlspecialchars($e['eid']) . ': ' ...
```

Already renders exactly the distinction this plan depends on. On a
Supermicro SAS846TQ (or any passthrough board), this prints
**"direct-attach (no expander)"** — confirming there is no real per-bay
`eid`/`slot` addressing to read automatically, only the `VirtualSES`
entry `storcli_enclosures.sh` (plan 024's excerpt) already produces for
direct-attached drives. **This is why the bay assignment must be manual
and keyed on `controller:port`, not on `slot`.** On chassis that *do* have
a real expander per row (four separate enclosures, `eid 0`–`3`), `eid`
already equals the row and this plan's manual-assignment step becomes a
one-click "accept the detected layout" instead of a full manual map —
worth keeping in mind for Step 2, but do not build a separate code path
for it in this pass; treat it as a future auto-fill convenience over the
same manual-assignment storage.

### `parse/smart.sh` — where per-drive temperature and health actually live

```awk
/Current Drive Temperature:/  { match($0,/([0-9]+)[ \t]*C/,m); temp=m[1] }
...
NF>=10 && $1==194 && $2 ~ /Temperature/  { st=$10 }
```

and, in `ajax_info.php`'s SMART rendering: `$ok = $health === 'OK' ||
$health === 'PASSED';`. **This, not `storcli_drives.sh`'s `state` field,
is the bay map's color/temp source.** `state` (`Onln`/`UGood`/etc.) is the
drive's *RAID-topology role*, not its health — a drive can be `Onln` and
failing, or `UGood` (unconfigured, IT-mode-typical) and perfectly healthy.
SMART data is already collected in the background for all drives (see the
Drives tab's neighboring "SMART tab (all drives, collected in the
background)" and its `SMART_PROGRESS_TTL` dead-collector handling in
`ajax_info.php`) and keyed by **serial** — which `storcli_drives.sh`
already emits per drive. This plan joins on that existing serial key; it
does not add a second SMART collection path.

### `config.php` — the persistence pattern to follow

Same `LSI_CFG` schema-clamped KEY=value pattern used throughout the
plugin. Bay-map settings that are simple scalars (rows, columns) fit this
schema directly; the assignment map itself (`controller:port` → `{row,
col}`, an open-ended list, not fixed keys) needs its own small JSON store,
following the same precedent plan 022 and plan 023 already establish for
non-schema state under `/boot/config/plugins/hbaviewer/`.

## Scope

**In scope**:

- **The map view is self-contained on the Drives tab — no separate
  Settings page step.** Dimension inputs, the grid, and the drive list
  all live together in one place: the map is something you set up and
  arrange right where you use it, not a value you configure elsewhere
  and then go look at.
- New config schema keys: `BAY_ROWS`, `BAY_COLS` (defaults `6`, `4` —
  matching the maintainer's chassis, but genuinely configurable — see
  above, not a Settings-page field). `config.php`'s `LSI_SCHEMA` is still
  the storage mechanism (clamped, persisted, follows the existing
  pattern), it's just not where the person edits it.
- New store: `/boot/config/plugins/hbaviewer/bay_map.json` — one entry per
  assigned drive, keyed by `"ctrl:port"`, value `{row, col}`
- A new "Map" toggle on the Drives tab toolbar, next to the existing
  "Refresh" button, switching the tab's content between the existing
  table and the new self-contained map view (keep the table — this is
  additive, not a replacement)
- **Inline dimension editor, at the top of the map view**: two small
  number inputs ("Rows" / "Columns", defaulting to the stored
  `BAY_ROWS`/`BAY_COLS`). Changing either immediately re-renders the grid
  at the new size (client-side reflow — no round trip needed just to
  preview a size change) and writes the new values back to
  `bay_map_dims_set()` (Step 2) once the person stops adjusting — debounce
  this rather than writing on every keystroke/click
- The grid itself: current `BAY_ROWS` × `BAY_COLS` cells, **3:1
  width:height per cell**, laid out left-to-right/top-to-bottom. Each
  cell shows a short position label and, when a drive is assigned and
  SMART data is available, its temperature; color follows SMART health
  (green/ok, amber/pending-or-stale, red/FAILED), not the `state` field
- **Shrinking the grid below the highest assigned row/col must not
  silently drop assignments** — the drives that no longer fit go back to
  the unassigned list rather than disappearing from `bay_map.json`
  entirely; see Step 3
- An "unassigned drives" list, part of the same map view (not a separate
  page section), listing every detected `controller:port` not yet present
  in `bay_map.json` — this is the list the person populates slots from
- Assignment interaction: click a list entry, then click an empty cell to
  place it; click an occupied cell to unassign it back to the list (exact
  interaction can refine during implementation — the two-click model is
  the baseline, drag-and-drop is a nice-to-have, not required for v1)
- Works on both backends: `storcli`'s `port` field is direct;
  `lsiutil`-backend drives have no `Connected Port Number` field at all
  (confirm in Step 1) — key on PHY instead for that backend, and note the
  distinction in the UI/docs since lsiutil users are working from a
  different identity space

**Out of scope**:

- Any automatic detection of physical row/column layout from `eid` — see
  "Current state" above; noted as a future convenience, not built here
- Drag-and-drop reordering (nice-to-have, not required for v1 — see above)
- Any change to `storcli_drives.sh`, `storcli_enclosures.sh`, or
  `parse/smart.sh` — this plan only reads their existing output
- SES locate integration (plan 024) — a natural companion once both
  exist (click a bay → locate that drive), but not required for this
  plan to ship independently

## Steps

### Step 1: Confirm the lsiutil-backend identity key

> **Answered by shipped code at re-stamp time (`d7d7fa7`).** `phy_drive()` in
> `ajax_info.php` (issue #11) already branches on exactly this: lsiutil drives
> carry `phy` (and `os_name`); storcli drives carry neither and carry `port`.
> So the two key shapes are confirmed — but they must NOT share a numeric
> namespace: port 3 and PHY 3 are different physical positions, and a box that
> switches backend would silently place a drive in the wrong bay. The key
> therefore carries which one it is (`c0:p14` vs `c0:h14`), so a backend switch
> orphans the assignment visibly into the unassigned list instead of lying.

`storcli_drives.sh` has `port` (`Connected Port Number`) as a stable,
always-present field. Confirm what the lsiutil-backend drives payload
(`drives_join.sh`, plan 027's grounding) offers as an equivalent stable
identifier — likely PHY index, per that plan's own read of the same file.
Decide whether lsiutil-backend drives get their own assignment key shape
(`ctrl:phy` instead of `ctrl:port`) before writing the store schema, since
retrofitting the key shape after drives are already assigned would
silently orphan existing assignments.

### Step 2: Config schema additions — storage only, not a Settings page field

```php
const LSI_SCHEMA = [
    // ... existing keys unchanged ...
    'BAY_ROWS' => [6, 1, 12],
    'BAY_COLS' => [4, 1, 12],
];
```

**Deliberately no `settings.php` row for these two.** They're written by
the map view's own inline dimension editor (Scope, above) via a small
dedicated setter:

```php
// Clamped the same way LSI_SCHEMA already clamps everything else —
// reuse config.php's existing clamp helper rather than re-checking bounds here.
function bay_map_dims_set(int $rows, int $cols): void {
    lsi_config_write(['BAY_ROWS' => $rows, 'BAY_COLS' => $cols]);
}
```

> **Corrected at execution.** `lsi_config_write()` writes EVERY schema key and
> falls back to the default for any key the array omits — so the snippet above
> would reset `HBA_PORT`, `ALERT_THRESHOLD` and every `SHOW_*` toggle to
> defaults each time someone nudged the grid size. The shipped version merges
> over the current config first (`['BAY_ROWS'=>…,'BAY_COLS'=>…] + lsi_config_read()`),
> and lives in `bay_map.php` beside the rest of the bay-map state rather than
> in `config.php`, which stays the generic schema/reader/writer.

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/config.php`
→ clean; existing config read/write tests still pass with the two new
keys defaulting correctly; a value outside `[1, 12]` from the map view's
inputs clamps the same way an out-of-range Settings value already does.

### Step 3: `bay_map.php` — assignment store

Follow plan 022/023's established shape:

```php
const BAY_MAP_PATH = '/boot/config/plugins/hbaviewer/bay_map.json';

function bay_map_read(?string $path = null): array {
    $path ??= BAY_MAP_PATH;
    if (!is_file($path)) return [];
    $data = json_decode((string) @file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function bay_map_write(array $map, ?string $path = null): void {
    $path ??= BAY_MAP_PATH;
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode($map, JSON_PRETTY_PRINT));
}

// Assign or clear one drive. $key = "ctrl:port" (or "ctrl:phy" per Step 1).
function bay_map_set(string $key, ?int $row, ?int $col, ?string $path = null): void {
    $map = bay_map_read($path);
    if ($row === null) { unset($map[$key]); }
    else { $map[$key] = ['row' => $row, 'col' => $col]; }
    bay_map_write($map, $path);
}

// Called whenever the map view's dimension editor shrinks the grid.
// Drives whose stored position no longer fits go back to unassigned —
// they are NOT deleted from the concept of "this drive was placed
// somewhere," just knocked out of bay_map.json until re-placed.
function bay_map_prune_to_dims(int $rows, int $cols, ?string $path = null): array {
    $map = bay_map_read($path);
    $dropped = [];
    foreach ($map as $key => $pos) {
        if ($pos['row'] >= $rows || $pos['col'] >= $cols) {
            $dropped[] = $key;
            unset($map[$key]);
        }
    }
    bay_map_write($map, $path);
    return $dropped; // caller surfaces these back into the UI's unassigned list
}
```

**Verify**: unit test round-trips assign/unassign through a temp path; a
separate test shrinks dimensions past an assigned drive's position and
confirms it's returned by `bay_map_prune_to_dims()` (and gone from
`bay_map_read()`) rather than silently retained at an out-of-grid
position.

### Step 4: Server-side join — drives + bay positions + SMART health

New render function, `renderDriveBayMap()`, alongside
`renderDrivesTables()` in `ajax_info.php`:

- For each drive in the current payload, compute its identity key (Step 1)
- Look up its position in `bay_map_read()` — present → placed on the grid;
  absent → goes in the "unassigned" tray
- Look up its SMART health/temp by serial (reuse whatever accessor the
  existing `renderSmartTable()` uses for the same join — do not
  re-implement SMART lookup a second way)
- Emit a small JSON payload for the front end to render (grid dimensions,
  placed drives with row/col/color/temp, unassigned list) — rendering the
  actual grid client-side (per the interactive mockup already agreed with
  the maintainer) rather than server-rendered HTML table rows, since the
  click-to-assign interaction needs JS state anyway

**Verify**: unit test the join function against a fixture combining a
drives payload, a SMART payload, and a bay-map fixture — confirm a drive
present in all three renders placed-with-color, a drive with no bay-map
entry renders in the unassigned list, and a drive with no SMART data yet
renders placed-but-uncolored (not miscolored as healthy).

### Step 5: Front end — one self-contained map view

Everything in this step is a single unit the person sees together, not
separate pages or steps:

1. **Dimension editor** — two small number inputs, "Rows" and "Columns,"
   pre-filled from `BAY_ROWS`/`BAY_COLS`. On change: re-render the grid
   client-side immediately (pure JS reflow, instant preview, no server
   round trip), then debounced-POST to `bay_map_dims_set()` — and if the
   new size is smaller, call `bay_map_prune_to_dims()` server-side and
   fold whatever it returns back into the on-screen unassigned list
   without a full page reload.
2. **The grid** — current `BAY_ROWS` × `BAY_COLS` cells, `aspect-ratio:
   3/1`, color-coded by SMART health, per the interactive mockup already
   agreed with the maintainer during design.
3. **The unassigned drives list** — directly below the grid, in the same
   view. This is the list the person populates the grid from, per the
   original request: "the application will have a list of drives it
   detects... and the user can populate the slots."

New POST action (CSRF-protected consistent with the rest of the plugin)
calling `bay_map_set()` on click-list-entry-then-click-cell.

### Step 6: Toolbar toggle

Add the "Map" button next to "Refresh" on the Drives tab
(`hbaviewer.php`'s existing `lu-tab-toolbar` block); toggle between the
existing table and Step 5's self-contained map view without re-fetching
data twice — both views can share one `type=drives` (or a combined)
payload if that's cheaper than a second AJAX round trip; decide based on
payload size once the SMART join is in.

> **Decided at execution**: two endpoints, not one. The table is HTML and the
> map is JSON, they are fetched at different times (the map only when first
> opened), and the map's payload carries the SMART join the table has no use
> for. Sharing one payload would mean the table's every Refresh pays for the
> SMART join it does not render. `renderDrivesTables()` also grew a second
> parameter since this plan was written (`$devBySerial`, issue #11).

## Test plan

- `bay_map_read`/`write`/`set` — pure, temp-path-injectable, tested the
  same way plan 022's baseline store is.
- The join function (`renderDriveBayMap()`'s data-assembly half, kept
  separate from its HTML/JSON output for testability, matching the
  pattern plan 006 already established for the render layer) — fixture
  cases: placed+healthy, placed+no-SMART-yet, unassigned, and (if Step 1
  finds a second identity shape for lsiutil) one case per backend.
- No existing goldens touched — this is new, additive rendering.
- `bash tests/run.sh` stays green.

## Done criteria

- [ ] Step 1's lsiutil-backend identity key confirmed and documented
      before Step 3's schema is finalized
- [ ] `BAY_ROWS`/`BAY_COLS` in `LSI_SCHEMA`, sane defaults (6, 4) —
      **no corresponding `settings.php` row**; the only edit surface is
      the map view's own inline dimension inputs
- [ ] `bay_map_read`/`write`/`set` round-trip through a temp path,
      unit-tested
- [ ] `bay_map_prune_to_dims()` returns the dropped keys and removes them
      from the store when the grid shrinks past their position;
      unit-tested with a fixture that assigns a drive, then shrinks below it
- [ ] Join function: placed+healthy, placed+no-SMART, unassigned all
      covered by fixture tests; a no-SMART-yet drive never renders as
      falsely healthy
- [ ] Grid renders at the current rows × cols with 3:1 cells, and resizes
      immediately (client-side) when the dimension inputs change
- [ ] Assignment flow: list entry → click → click → placed; occupied cell
      click → back to the list
- [ ] Shrinking the grid below an assigned drive's position visibly moves
      it back into the unassigned list, in the same view, without a full
      page reload
- [ ] `php -l` / `bash -n` clean on every touched file
- [ ] `bash tests/run.sh` → `--- all pass ---`

## STOP conditions

- The drift check prints anything.
- A drive with no SMART data yet renders colored as if healthy — must
  render as "no data" (neutral/gray), not green.
- The SMART lookup is re-implemented separately from what
  `renderSmartTable()` already uses, instead of sharing one accessor.
- Any edit lands in `storcli_drives.sh`, `storcli_enclosures.sh`, or
  `parse/smart.sh` — this plan only reads their existing contracts.

## Maintenance notes

- **The assignment store is per-installation, manually curated data** —
  unlike everything else this plugin persists, it can't be regenerated
  from hardware. Any future change to the identity-key shape
  (`ctrl:port` today) needs a migration path, not a silent format change,
  or every existing user's bay map orphans on upgrade.
- **This plan's grid is a natural pairing with plan 024's SES locate** —
  clicking a bay to locate the drive is the obvious next step once both
  exist, but they were deliberately kept independent so either can ship
  without waiting on the other.
- **The dimension editor was deliberately moved off `settings.php` and
  into the map view itself** (revised from this plan's first draft,
  which put `BAY_ROWS`/`BAY_COLS` on the Settings page like every other
  config value). The map is meant to be something you build in place —
  set the size, see the grid, drag drives in — not a value you configure
  on one page and then go check on another.
