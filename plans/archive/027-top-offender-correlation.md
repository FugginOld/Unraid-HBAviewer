# Plan 027 (v2): "Top offenders" — rank PHYs by error rate and name the drive

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 515195d..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php source/usr/local/emhttp/plugins/hbaviewer/phy_baseline.php source/usr/local/emhttp/plugins/hbaviewer/cached_read.php source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_phy.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/drives_join.sh tests/ajax_render_test.php`
> Expected output: **nothing**. Every excerpt below is quoted from `515195d`
> (`dev` tip, 2026-08-02). Any difference is a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MEDIUM. The output names a physical bay. **A list that points at the
  wrong drive is worse than no list** — someone pulls a healthy disk. Every
  ambiguity in this plan resolves to "show nothing", never "best guess".
- **Depends on**: **022** (DONE, merged) — the rate source.
- **Category**: feature
- **Planned at**: `515195d`, 2026-08-02 (**v2** — see History)

## History — v1's two open questions, both now answered with hardware evidence

v1 was written 2026-07-31 against `8286fe7` and left two things undecided. Both
are now settled and **v1's Step 2 code does not work** — do not resurrect it.

**1. The join key. v1's `$rate_by_phy[$d['phy']]` is broken on storcli.**
v1 assumed drives carry a `phy` field. They do on the lsiutil path only:

| Backend | PHY payload has | Drives payload has | Join |
|---|---|---|---|
| lsiutil (`parse/phy.sh` + `drives_join.sh`) | `phy` | `phy`, `sas_address`, `os_name` | PHY index, direct |
| storcli (`parse/storcli_phy.sh` + `storcli_drives.sh`) | `phy`, `sas_addr` | `sas_address`, `slot` — **no `phy`** | SAS address, see below |

`grep -c '"phy"' tests/expected/storcli_drives.json` → **0**. v1's join silently
produces an empty list on every storcli box.

**2. The rate source.** v1 offered plan 020's health ring or plan 022's
baseline. **Decision: 022.** The ring only fills while the Health tab is being
polled, so it reads empty or stale for anyone who has not opened that tab —
the same trap that kept the health rollup out of plan 025's export. 022's rate
is anchored to a reference point the user chose deliberately. The cost is that
it exists only where Set Baseline was pressed, which this plan handles with an
honest empty state rather than a fallback.

## The SAS-address join — measured, not assumed

Captured from the maintainer's box (9400-16i + 9400-8i, 24 drives) on
2026-08-02 by running the plugin's own two scripts and comparing:

```
PHY  5000CCA25319FB45   ->  slot 0/12   5000CCA25319FB47      (5 -> 7)
PHY  5000C500AEBADCE9   ->  slot 0/13   5000C500AEBADCE8      (9 -> 8)
PHY  50000399384073B2   ->  slot 0/15   50000399384073B0      (2 -> 0)
PHY  5000CCA2510DA989   ->  slot 0/14   5000CCA2510DA98B      (9 -> B)
```

An exact-match join scores **0 out of 24**. The reason is dual-port SAS
addressing: the PHY reports the port address it is attached to, storcli's `WWN`
reports the drive's other port or its node address. **In all 24 pairs the first
15 hex digits are identical and only the 16th differs.** The delta is
vendor-specific — `5000C500` (Seagate) −1, `5000CCA2` (HGST) +2, `50000399`
(Toshiba) −2 — so no fixed offset works, but truncation does.

**The rule: compare the first 15 hex digits, uppercased.**

### Independently corroborated through the kernel, 2026-08-02

The join was not only measured against storcli's own two views — it was checked
against a third source that never sees storcli. Sysfs reports, for each drive,
the SAS address of the port it is attached through, and that value equals the
PHY's `sas_addr` **exactly** (no nibble difference — it is storcli's `WWN` that
reports the drive's *other* port). Cross-referencing that with the kernel's
enclosure-slot map closes the chain:

| Join says | PHY attached SAS | `/sys/block` says | `/sys/class/enclosure` says |
|---|---|---|---|
| c0 phy 0 -> slot 0/12 | `5000CCA25319FB45` | sda | slot 12 = sda |
| c0 phy 9 -> slot 0/0 | `5000CCA28DDE3C89` | sdg | slot 0 = sdg |
| c0 phy 15 -> slot 0/3 | `5000C500CADB8C65` | sdl | slot 3 = sdl |

Three further pairs (phy 2/sdm/slot 13, phy 6/sde/slot 15, phy 14/sdk/slot 7)
agree. **24 of 24 PHYs resolve, and the ones checked resolve to the right bay** —
"resolves" and "resolves correctly" are different claims, and this is the second.

**The guard that makes it safe: the 15-digit prefix must be unique within that
controller's drive list.** Verified on the same capture — all 16 prefixes on c0
and all 8 on c1 are distinct, because different drives differ far above the low
nibble. If a prefix ever collides, that PHY resolves to **no drive**, not to
the first match. A wrong bay is the one outcome this feature must never produce.

## Current state

### `ajax_info.php:343-378` — the renderer, which already computes the rates

```php
function renderPhyTables(array $data, array $baselines = [], ?int $now = null, ?int $uptime = null): string {
    …
        // Resolve every PHY's delta first: a reboot or driver reload zeroes the
        // whole controller's counters at once, so one invalidated PHY condemns
        // the controller's baseline rather than just its own row.
        $bl     = phy_baseline_for($baselines, (int) $i);
        $ts     = phy_baseline_ts($baselines, (int) $i);
        $deltas = [];
        $stale  = false;
        foreach ($phys as $n => $p) {
            $d = phy_baseline_delta($bl[(int) ($p['phy'] ?? -1)] ?? null, $p, $now, $uptime);
            if ($d !== null && !empty($d['reset'])) $stale = true;
            $deltas[$n] = $d;
        }
        if ($stale) $deltas = array_map(fn() => null, $deltas);
        $out .= luPhyBaselineBar((int) $i, $ts, $stale);
```

**`$deltas` is the rate source and it is already sitting right there.** Build
the list from that same array. Recomputing rates separately would let the list
and the table below it disagree — in particular about staleness, where one
invalidated PHY nulls the whole controller.

### `phy_baseline.php` — what a delta contains

```php
    $out   = ['reset' => false, 'ts' => (int) $base['ts'], 'delta' => [], 'rate' => []];
        …
        $out['rate'][$k]  = $d / $hours;
```

Keyed by the four counters in `PHY_COUNTERS` = `['inv','disp','sync','reset']`.
`null` means "no baseline for this PHY"; `['reset' => true]` means the baseline
is stale.

### `ajax_info.php:20-24` — why new functions are testable

The file returns early under CLI, but PHP hoists top-level function
declarations, so functions defined after the return are still available to
`tests/ajax_render_test.php`. Put the new pure functions in this file, beside
the renderer that uses them, and test them there.

## Scope

**In scope**:

- `ajax_info.php`: two new pure functions, the `$type === 'phy'` dispatch
  reading the drives payload, and a list rendered inside `renderPhyTables`.
- `tests/ajax_render_test.php`: cases for both pure functions.

**Out of scope — do not touch**:

- **Every parser and script.** No new shell/awk join — v1 was right about this
  and it still holds. Both payloads are already JSON by the time PHP sees them.
- `phy_baseline.php`, `health.php`. The rate already exists; do not add a second
  computation, and do not read the health ring (see History for why).
- `drives_join.sh`, `storcli_phy.sh`, `storcli_drives.sh` — the plan reads their
  output, never their logic.
- Cross-controller ranking. This is **top offenders per controller**; a global
  list is a separate plan.

## Steps

### Step 1: `phy_drive_label()` — resolve a PHY to a drive, or to nothing

```php
/* Which drive sits behind this PHY? Two backends, two keys:
     lsiutil  - drives carry `phy`; match it directly.
     storcli  - drives carry no `phy` at all. The PHY's `sas_addr` and the
                drive's `sas_address` are two ports of the same dual-ported
                device and differ in the LAST hex digit only (measured across
                24 drives: Seagate -1, HGST +2, Toshiba -2 — no fixed offset),
                so compare the first 15 digits, uppercased.
   Returns null when nothing matches AND when the 15-digit prefix is not unique
   within this controller: a top-offenders row names a physical bay, and naming
   the wrong one is worse than naming none. */
function phy_drive_label(array $drives, array $phy): ?string
```

Return the drive's `slot` for storcli (e.g. `0/12`) or its `os_name` for
lsiutil (e.g. `/dev/sdb`), whichever the payload carries. `null` otherwise.

### Step 2: `phy_top_offenders()` — rank, excluding the unmeasured

```php
/* $phys and $deltas share indices, exactly as renderPhyTables built them.
   Rank by TOTAL errors/hour — the plain sum of the four counters' rates. No
   weighting is invented here: health.php's health_rate_state() is where
   per-counter thresholds live, and duplicating that judgement in a second
   place would let the two disagree. */
function phy_top_offenders(array $phys, array $deltas, array $drives, int $limit = 5): array
```

Rules, each pinned by a test:

- A PHY whose delta is `null` (no baseline) or `['reset' => true]` (stale) is
  **excluded entirely**. It must never rank at zero — zero reads as "measured
  and clean" when it means "never measured".
- A PHY whose total rate is `0.0` is also excluded. The list is for offenders;
  a clean measured PHY is not one, and listing it dilutes the thing.
- Sort descending by total rate, then by PHY index ascending for a stable order
  when rates tie.
- Return at most `$limit` (default 5) entries, each carrying `phy`, `rate_total`,
  the per-counter rates, and `drive` (from Step 1, possibly `null`).

### Step 3: read the drives payload on the PHY tab

`renderPhyTables` gets a new optional `array $drives = []` parameter — last
position, defaulting to empty so **every existing caller renders exactly what it
renders today** (the same backward-compatible shape `$baselines` already uses).

In the `$type === 'phy'` dispatch, read the drives payload through
`cached_read('drives', 60, …)` — never a second bare `shell_exec`. That call is
the second most expensive read in the plugin and the PHY tab polls; the cache is
what makes this affordable. If the cache is warming, pass `[]` and the list
renders without drive names rather than blocking the tab.

### Step 4: render

Above the PHY table, below `luPhyBaselineBar()`. Match the existing muted
subtitle style used elsewhere in the card; do not invent a new visual language.

Each row: rank, `PHY n`, the drive label when known, total errors/hour to one
decimal, and the per-counter breakdown. When `drive` is `null`, say
`PHY 6 — drive not identified`, never a guess.

**Two empty states, and they say different things:**

- No baseline on this controller → *"Set a baseline to rank PHYs by error
  rate."* (Not an error. This is the normal state on a fresh install.)
- Baseline exists, every measured PHY at zero → *"No PHY has logged errors
  since the baseline."* That is good news and should read as good news.

## Test plan

`tests/ajax_render_test.php`, matching its existing `check(name, bool)` style.
Fixture shapes come from the real capture above — use these actual address
pairs, not invented ones.

`phy_drive_label()`:

- storcli: PHY `5000CCA25319FB45` + drive `slot 0/12` / `5000CCA25319FB47` → `0/12`
- storcli: the Seagate (`…E9` vs `…E8`) and Toshiba (`…B2` vs `…B0`) pairs, to
  prove no fixed offset is assumed
- storcli: **two drives sharing a 15-digit prefix → `null`** (the collision guard)
- storcli: no drive matches → `null`
- lsiutil: drive with `phy` 3 → that drive's `os_name`
- case-insensitivity: lowercase `sas_address` still matches

`phy_top_offenders()`:

- ranks descending by summed rate; a lower-rate PHY sorts after a higher one
- a PHY with `null` delta is absent from the output (**not** present with 0)
- a PHY with `['reset' => true]` is absent
- a measured PHY with all-zero rates is absent
- `limit` is honoured
- empty `$phys` / empty `$deltas` → `[]`, no warning
- a PHY whose drive does not resolve still ranks, with `drive === null`

## Done criteria

- [ ] `grep -c 'health_' ajax_info.php` unchanged — the health ring is not read
- [ ] No file under `scripts/` appears in the diff
- [ ] A `null` or stale delta never produces a row (asserted)
- [ ] The 15-digit prefix collision case returns `null` (asserted)
- [ ] `renderPhyTables()`'s existing callers still work — its new parameter is
      last and optional; the existing PHY render tests pass **unmodified**
- [ ] `php -l` clean; `bash tests/run.sh` → `--- all pass ---`
- [ ] `git diff -- tests/expected/` empty
- [ ] The suite's PHP warning count is unchanged (`docker run … php tests/ajax_render_test.php 2>&1 | grep -ciE 'warning|deprecated'` → `2`)

## STOP conditions

- The drift check prints anything.
- Any file under `scripts/` is modified.
- A PHY with no baseline, or a stale one, appears in the list at any rate
  including zero.
- A 15-digit prefix collision resolves to a drive instead of `null`.
- An exact-match-only SAS join is used — it scores 0/24 on real hardware.
- A second rate computation is introduced instead of reusing `$deltas`.
- `health.php`'s ring is read.

## Maintenance notes

- **The join rule is empirical.** It rests on 24 real drives from two
  controllers and one vendor mix. If a report ever shows a PHY resolving to the
  wrong bay, the fix is to tighten the guard — not to widen the match.
- **This list and the PHY table must agree.** They share `$deltas` precisely so
  staleness is handled once. If someone later gives the list its own rate
  source, they will drift, and the list is the one users will trust.
- **`health.php`'s `link_integrity` reason string also names the worst PHY**,
  computed from the ring rather than the baseline. Two features naming "the
  worst PHY" from different windows is acceptable — they answer different
  questions — but if they ever visibly contradict each other on the same screen,
  that is a design problem worth resolving rather than a bug in either.
