# Plan 027: "Top offenders" — join PHY error rate to the drive/slot it serves

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 8286fe7..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/drives_join.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/get_attached_drives.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/get_phy_health.sh source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php`
> Expected output: **nothing**. Every excerpt below is quoted from `8286fe7`
> (`dev` tip, 2026-07-30). Any difference is a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW — read-only join over data the plugin already collects
- **Depends on**: **022** (PHY error baseline/rate) — a raw cumulative
  counter is not a meaningful thing to rank by; see "Why this depends on
  022" below. Do not start this plan until 022 has shipped, or scope it
  down per that section.
- **Category**: feature
- **Planned at**: `8286fe7`, 2026-07-31
- **Requested by**: external roadmap review

## Two possible rate sources — pick one deliberately

This plan was written when 022 was the only route to a rate. **Plan 020 has since
shipped per-PHY rates**, computed from a `/tmp` sample ring and verified on real
hardware, and its `link_integrity` reason string already names the worst PHY. So
there are now two providers:

| | plan 020 (`health.php`) | plan 022 (baseline file) |
|---|---|---|
| Rate window | rolling ~4 h, automatic | since the user pressed Reset |
| Available | as soon as 020 merges | needs 022 built |
| Survives reboot | no | yes |
| Good for ranking | "who is bad *now*" | "who got worse since I fixed it" |

Either satisfies this plan's requirement that ranking uses a rate, not a
cumulative counter. Building on **020** drops the 022 dependency entirely and is
the shorter path; building on **022** gives a ranking anchored to a user-chosen
reference point, which is arguably the more useful question after a repair.

Decide before Step 1 and record the choice — do not build a third rate
computation. If both eventually exist, this list should read whichever the user
is currently looking at, not average them.

## Why this depends on 022

Ranking PHY error counters to find "the cable most likely to need
reseating" only works on a **rate**, not a cumulative counter — a
controller that's been up for six months will have naturally larger raw
counts than one rebooted yesterday, regardless of actual cable health.
Plan 022 is what turns the raw counter into `errors/hour since baseline`.
Building this plan against the raw counter instead would rank by uptime,
not by health, and would need redoing once 022 ships anyway.

**If 022 is not yet available and this is being picked up standalone**,
the fallback is to rank by the existing `PHYERR_FLOOR=100` threshold
crossing (binary: over/under) rather than a continuous rank — strictly
less useful, but not actively misleading the way raw-counter ranking would
be. Note that choice explicitly in the plan's status row if taken.

## Why this matters

The PHY Health tab and the Attached Drives tab both exist, but nothing
joins them today — the answer to "which physical drive is behind the PHY
throwing errors" requires a human to cross-reference a PHY index against a
slot number by hand. A ranked "top offenders" list, sorted by rate,
labeled with the enclosure/slot it maps to, turns that into a glance.

## Current state

### `scripts/parse/drives_join.sh` — the join pattern already in the codebase

```awk
# Pure parser: join OS-name map with sysfs SAS/PHY map -> drives JSON.
#   $1 osmap : "bus_tgt /dev/sdX"        (drives_osmap.sh, or sysfs fallback)
#   $2 sasmap: "/dev/sdX SAS_ADDR PHY"   (sysfs sas_end_device)
# This join is where the historical drive bugs lived — golden-tested.
awk '
BEGIN { first=1; printf "{\"drives\":[" }
NR==FNR { os[$1]=$2; n++; ord[n]=$1; next }
{ sasmap[$1]=$2; phymap[$1]=$3 }
END {
    for (i=1; i<=n; i++) {
        key=ord[i]; dev=os[key]
        ...
        phy=(dev in phymap) ? phymap[dev]+0 : tgt
        ...
    }
}'
```

This is the lsiutil-path drive↔PHY join, and it's explicitly called out as
"where the historical drive bugs lived" — the codebase already treats this
class of join as delicate. **This plan adds a second join (PHY error
rate ↔ drive) on top of an already-fragile first join** — see Scope for
why that argues for building it as a display-layer aggregation over
already-joined data, not a third awk script duplicating the join logic.

### The two data sources being correlated

- PHY Health (`get_phy_health.sh`) — per-PHY error counters, keyed by PHY
  index (lsiutil path) or SAS address (storcli path, merged from sysfs per
  `get_phy_health.sh`'s own header comment)
- Attached Drives (`get_attached_drives.sh` / `drives_join.sh`) — per-drive
  `phy` field, already present in the joined output

Both already key on PHY index for the lsiutil path. The storcli path's
`storcli_phy.sh` (not excerpted here — read it before Step 1) merges via
SAS address, which is the more reliable join key when both are storcli-
sourced, since `phy` numbering conventions can differ between what
`storcli show all` reports and what sysfs reports.

## Scope

**In scope**:

- A display-layer aggregation (PHP, not a new awk parser) that takes the
  already-decoded PHY payload and already-decoded drives payload — both
  already JSON by the time they reach PHP — and joins them by PHY
  index/SAS address, entirely in PHP. **Do not write a third bash/awk join
  script** — the existing two JSON payloads have everything needed; a PHP
  array join is simpler to test and doesn't touch the fragile shell join
  layer at all.
- Sort by plan 022's rate field (or the floor-crossing boolean, if taken
  standalone per "Why this depends on 022"), descending, top N (5 is a
  reasonable default)
- Render as a small ranked list — Overview tab or a new section on PHY
  Health, whichever reads better next to the per-controller cards (decide
  in Step 1 with a mockup, this is a UI judgment call)
- Label each entry with enclosure/slot (from the drives payload) so it
  reads as "which physical drive," not just "which PHY index"

**Out of scope**:

- Any new shell-level join script — see above, this stays a PHP
  aggregation over existing JSON
- Changing `drives_join.sh` or `storcli_phy.sh` themselves
- Cross-controller ranking in a single list if the plugin ever supports
  genuinely heterogeneous multi-vendor setups — scope this to "top
  offenders per controller" first; a global cross-controller list is a
  natural follow-up, not required here

## Steps

### Step 1: Confirm the join key per backend

- lsiutil path: PHY index, already shared between the two payloads
  directly (`drives_join.sh`'s `phy` field, `parse/phy.sh`'s `phy` field)
- storcli path: read `storcli_phy.sh` fully (not done for this plan — it's
  outside the excerpted files) to confirm whether it emits PHY index, SAS
  address, or both, and match against what `storcli_drives.sh` emits for
  the same drive. **If the two storcli-side parsers use different keys,
  this step is where that gets resolved — do not assume they align just
  because the lsiutil side does.**

### Step 2: PHP join function

```php
// $phy_data: decoded PHY payload (per plan 022, includes 'rate' per phy
// when a baseline exists). $drives_data: decoded drives payload.
// Returns drives sorted by their PHY's rate, descending, rate-having only.
function top_offenders(array $phy_data, array $drives_data, int $limit = 5): array {
    $rate_by_phy = [];
    foreach ($phy_data['phys'] ?? [] as $p) {
        if (isset($p['rate'])) $rate_by_phy[$p['phy']] = $p['rate'];
    }
    $ranked = [];
    foreach ($drives_data['drives'] ?? [] as $d) {
        if (isset($rate_by_phy[$d['phy']])) {
            $ranked[] = $d + ['rate' => $rate_by_phy[$d['phy']]];
        }
    }
    usort($ranked, fn($a, $b) => $b['rate'] <=> $a['rate']);
    return array_slice($ranked, 0, $limit);
}
```

Pure function over two already-decoded arrays — no I/O, straightforward to
unit test with fixture JSON.

**Verify**: unit test with a fixture where drive A's PHY has a high rate,
drive B's has none (baseline never set) — B must not appear in the ranked
output, and A must be first.

### Step 3: Render

Small ranked list component — reuse whatever card/table styling
`hbaviewer.php` already has for the Attached Drives table rather than
inventing new markup.

## Test plan

- `top_offenders()` — pure, fixture-driven: normal ranking, drive with no
  rate data excluded (not ranked-as-zero, which would misleadingly imply
  "measured and healthy" rather than "not measured"), empty-PHY-data
  edge case (no crash, empty result).
- No existing goldens touched — this is new, additive aggregation.
- `bash tests/run.sh` stays green.

## Done criteria

- [ ] Step 1's per-backend join key confirmed by actually reading
      `storcli_phy.sh` and `storcli_drives.sh`, not assumed
- [ ] `top_offenders()` unit-tested: normal rank, no-rate-data exclusion,
      empty-input edge case
- [ ] Drives with no baseline/rate are excluded from the list, never
      shown ranked at zero
- [ ] `bash tests/run.sh` → `--- all pass ---`

## STOP conditions

- The drift check prints anything.
- A new shell/awk join script is introduced — the PHP-aggregation-over-
  existing-JSON approach is the point of this plan's scope.
- A drive with no rate data is ranked/displayed as if it had a rate of
  zero, rather than being excluded — that would read as "confirmed
  healthy" when it actually means "never baselined."
- This plan is started before plan 022 ships, without explicitly falling
  back to the floor-crossing-boolean scope described above.

## Maintenance notes

- **This plan is entirely dependent on 022's rate field existing and
  being trustworthy.** If 022's baseline mechanism changes shape later,
  this join needs to move with it — they should probably be reviewed
  together if either changes after both have shipped.
