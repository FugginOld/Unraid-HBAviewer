# Dashboard Card Grouping Implementation Plan

> **Status: COMPLETE.** Released 2026.08.23. Task 2 needed no change: the reporting box’s SAS9300-16i was already indexed with `ioc_count: 2`, which the diagnostic confirmed rather than fixed.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A dual-IOC board produces **one** dashboard tile, as it already produces one Overview card. The dashboard becomes the fourth consumer of `lsi_group_cards()` rather than the one screen that disagrees with the other three.

**Architecture:** `dashboard.php`'s tile loop iterates groups instead of controllers. A single-member group renders exactly the tile it renders today, keyed identically. A multi-member group renders one tile carrying the worst status, the higher temperature, both IOC names, and the board's PCIe row once.

**Tech Stack:** PHP 8 (no framework), PHP unit tests via `tests/run_php.sh`.

**Spec:** `docs/superpowers/specs/2026-08-22-dashboard-card-grouping-design.md`

## Global Constraints

- Run from the repo root: `cd c:/Users/Joe/Documents/GitHub/Unraid-HBAviewer`.
- Full verification is `bash tests/run.sh` (~3 min). It must print `--- all pass ---` at the end of **every** task.
- **Land `2026-08-22-dashboard-blocking-read.md` first.** Both rewrite `dashboard.php`; that one is a live severity-1 defect and changes where `$data` comes from, this one changes what is done with it.
- **Do not modify `card_group.php`.** Three other consumers depend on it and none of them is suspected. If grouping looks wrong, the bug is in the caller or in the firmware index's `ioc_count`, and a change to the shared rule needs its own spec.
- **Do not soften the merge guard.** `lsi_group_cards()` merges only on shared `card_id` *and* a board the index says has that IOC count, matching exactly. Risers and PCIe switches put genuinely separate cards behind one root port; merging those is a worse bug than the one being fixed and appears only on hardware nobody here has.
- **A single-controller box's tile key must not change.** Unraid persists dashboard layout per tile key; changing it resets users' layouts. Key on the group's first member index — for every ungrouped card that is the index it already had.
- No golden in `tests/expected/` should change.
- Commit after every task. Message style: a sentence saying what changed and why, no `feat:`/`chore:` prefixes.

---

### Task 1: Group the tile loop

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` (the `foreach ($controllers as $i => $c)` at ~line 141, and the requires at the top)
- Test: `tests/ajax_render_test.php`, beside the existing dual-IOC Overview checks

**Interfaces:**
- Consumes: `lsi_group_cards($ctls, lsi_ioc_counts(fw_load()))` — the exact call `render/overview.php:235` makes.
- Produces: one `$tiles[]` entry per group.

- [ ] **Step 1: Write the failing tests**

Reuse the dual-IOC fixture `ajax_render_test.php` already uses for "the real dual-IOC capture renders one card" — the same hardware must produce one card *and* one tile, and using a different fixture here would let the two screens drift apart while both pass.

```php
// The report, stated as an assertion: one board, one tile. The Overview has
// grouped this exact capture since plan 2026-08-10; the dashboard is the one
// consumer that never started.
```

1. Dual-IOC capture → **one** tile, not two.
2. Single-controller capture → one tile, and its key is **byte-identical** to the key it has today (`HBAviewer_c0`). Pin the literal — this is the users'-layout constraint, and a test that computes the expected key the same way the code does would not catch a change to both.
3. Two genuinely separate cards (distinct `card_id`) → **two** tiles. The guard, asserted from the outside.
4. Grouped tile status is the **worst** of the members, with the alert on the **second** member so a "last one wins" implementation fails.
5. Grouped tile temperature is the **higher** of the two sensors, with the higher one on the **first** member so a "last one wins" implementation also fails here. (4 and 5 deliberately put the significant value at opposite ends.)

- [ ] **Step 2: Implement**

Add the requires (`card_group.php`, `firmware_index.php`), then:

```php
foreach (lsi_group_cards($controllers, lsi_ioc_counts(fw_load())) as $g) {
    $first = $controllers[$g[0]];
    $i     = $g[0];                    // key stays the first member's index
    ...
}
```

For a multi-member group:
- **Status:** worst across members, via the same comparison the Overview parent uses — do not re-derive a severity order here, and if one is not exposed as a function, that is the finding to raise before continuing.
- **Temperature:** `max()` across members.
- **Sub-line:** the board name and its IOC count, not `Controller /cN` — a tile showing one temperature for a two-sensor board must say so, or the number reads as the only sensor.
- **PCIe row:** once, from the first member; it is a slot fact and the group is one slot.

- [ ] **Step 3: Verify** — `bash tests/run.sh` prints `--- all pass ---`.

- [ ] **Step 4: Mutate**

Each of these must fail a test. If one passes, that test is decorative:

1. Revert to `foreach ($controllers as $i => $c)` → case 1 fails.
2. Take the **last** member's status instead of the worst → case 4 fails.
3. Take the **last** member's temperature instead of the max → case 5 fails.
4. Key the tile on the group's ordinal rather than its first member index → case 2 fails.

---

### Task 2: Confirm the reporter's board actually groups

Grouping depends on the firmware index knowing the board carries two IOCs. A
board absent from the index, or present with `ioc_count` unset, stays split —
correctly, by the guard — and the tile would still show two. That is a *data*
outcome, not a code one, and Task 1 cannot detect it.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json` — **only if** the box's board is missing or has no `ioc_count`.

- [ ] **Step 1: Ask the box**

On the reporter's server:

```bash
cd /usr/local/emhttp/plugins/hbaviewer
php -r 'require "firmware_index.php"; require "card_group.php";
        $i = fw_load();
        var_dump(lsi_ioc_counts($i));' 2>&1 | head -30
bash scripts/get_hba_info.sh | php -r '$d=json_decode(file_get_contents("php://stdin"),true);
        foreach ($d["controllers"] ?? [] as $n => $c) {
            printf("%d  board=%-18s card_id=%s\n", $n, $c["board_name"] ?? "?", $c["card_id"] ?? "?");
        }'
```

Two controllers with the **same** `card_id` and a `board_name` whose normalised
form appears in the first dump with `ioc_count >= 2` will group. If the
`card_id`s differ, they are not one card and the report needs re-reading before
any code changes.

- [ ] **Step 2: Add the board only if the evidence says so**

If the board is genuinely dual-IOC and missing its `ioc_count`, add it, with a
test asserting that board normalises to a count of 2. Do **not** add a board on
the strength of its model number alone — the index is what stops risers from
merging unrelated cards.

- [ ] **Step 3: Verify** — `bash tests/run.sh` prints `--- all pass ---`.

---

## Hardware verification

On a box with a dual-IOC board (SAS9300-16i, SAS9305-24i or similar):

1. Dashboard shows **one** HBAviewer tile, not two.
2. Its temperature matches the **higher** of the two shown on the Overview card.
3. Its status matches the Overview card's badge.
4. On a single-card box, the tile is where the user left it — position and
   collapsed state preserved across the upgrade. This is the key-stability
   constraint and the only way to check it is to have had a layout before.
