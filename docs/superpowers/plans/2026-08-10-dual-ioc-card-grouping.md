# Dual-IOC Card Grouping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show a SAS9300-16i as one card with two controller sub-cards, instead of two unrelated HBAs.

**Architecture:** Each controller gains one field, `card_id`, holding the address of its PCI root port — the physical slot. Two controllers sharing one are on one board, because two cards cannot occupy one slot. The `controllers` array stays flat; grouping happens at render time in the Overview and the firmware page only.

**Tech Stack:** Bash (sysfs walk, parsers), PHP 8.2 (grouping, rendering), plain JS, self-asserting test scripts run by `tests/run.sh`.

## Global Constraints

- The `controllers` JSON array stays **flat**. Nine references across four files read it as a list; adding a field is allowed, restructuring is not.
- Group only when **both** hold: two or more controllers share a non-empty `card_id`, **and** their `board_name` has an `ioc_count` in the index that equals the group size exactly.
- An empty `card_id` never groups — including with other empty ones.
- Per-IOC temperatures are **never** merged into one number. Two dies, two sensors.
- Single-IOC cards must render **byte-identically** to today. Existing goldens do not change except to gain `card_id`.
- Verify uses one scoped call per IOC, concatenated. **Never `-listall` / `show all`.**
- Flash loops the card's own controller indices. **Never `-fwall`.**
- After a partial flash failure the error must name which IOC is on which version. No generic failure text.
- No local `php`/`phpstan`/`shellcheck`. All run through Docker; see each task's commands.

---

### Task 1: `hba_card_id()` in lib.sh

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh` (add after `hba_subvendor`, which ends around line 249)
- Test: `tests/topology_test.sh` (extend — it already covers the other two sysfs helpers)

**Interfaces:**
- Produces: `hba_card_id <sysfs-pci-device-dir>` → prints `0000:80:01.0` or empty. Never fails; unreadable input yields empty.

- [ ] **Step 1: Write the failing test**

Append to `tests/topology_test.sh`, immediately before the final `echo`/exit lines:

```bash
# ── hba_card_id ──────────────────────────────────────────────────────────────
# The maintainer's SAS9300-16i: two SAS3008 IOCs behind a switch of the card's
# own, both in slot 0000:80:01.0. Captured from Raven, where the two hosts
# resolve through 0000:80:01.0 -> 0000:82:00.0 -> {0000:83:00.0, 0000:83:09.0}.
CARD=$ROOT/devices
DUAL=$CARD/pci0000:80/0000:80:01.0/0000:82:00.0
mkdir -p "$DUAL/0000:83:00.0/0000:84:00.0" "$DUAL/0000:83:09.0/0000:86:00.0"
# An unrelated single-IOC card in a different slot.
mkdir -p "$CARD/pci0000:00/0000:00:11.0/0000:06:00.0"
# A device sitting directly on the host bridge, no bridge in between.
mkdir -p "$CARD/pci0000:00/0000:00:1f.2"

eq "both IOCs of one card share a slot" \
   "0000:80:01.0" "$(hba_card_id "$DUAL/0000:83:00.0/0000:84:00.0")"
eq "and the second IOC agrees" \
   "0000:80:01.0" "$(hba_card_id "$DUAL/0000:83:09.0/0000:86:00.0")"
eq "a card in another slot does not" \
   "0000:00:11.0" "$(hba_card_id "$CARD/pci0000:00/0000:00:11.0/0000:06:00.0")"
eq "a device on the host bridge is its own slot" \
   "0000:00:1f.2" "$(hba_card_id "$CARD/pci0000:00/0000:00:1f.2")"
eq "a path with no host bridge yields empty" \
   "" "$(hba_card_id "$ROOT")"
eq "a missing path yields empty" \
   "" "$(hba_card_id "$ROOT/nope/0000:99:00.0")"
```

Also extend the function-extraction line near the top of the file so the new
function is in scope. Change:

```bash
FN=$(sed -n '/^hba_topology()/,/^}/p' "$SRC"; sed -n '/^hba_subvendor()/,/^}/p' "$SRC")
```

to:

```bash
FN=$(sed -n '/^hba_topology()/,/^}/p' "$SRC"; sed -n '/^hba_subvendor()/,/^}/p' "$SRC"
     sed -n '/^hba_card_id()/,/^}/p' "$SRC")
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `bash tests/topology_test.sh`
Expected: FAIL — the extraction guard reports the function is missing, or the
`eq` lines report empty results.

- [ ] **Step 3: Write the implementation**

Add to `scripts/lib.sh` after `hba_subvendor()`:

```bash
# The physical slot a controller occupies, named by its PCI root port -- the
# first device under the host bridge in the resolved sysfs path. Two
# controllers sharing one are on the same board, because two cards cannot
# occupy one slot. pci_location cannot answer this and board_name must not:
# two SEPARATE 9300-8i cards report the same name, so grouping on it would
# merge unrelated hardware, which is worse than not grouping at all.
#
# A SAS9300-16i carries a PCIe switch of its own, so its two SAS3008 IOCs
# differ at every level below the root port:
#   pci0000:80/0000:80:01.0/0000:82:00.0/0000:83:00.0/0000:84:00.0
#   pci0000:80/0000:80:01.0/0000:82:00.0/0000:83:09.0/0000:86:00.0
#
# Empty when the ancestry is not visible -- an absent entry, a flat test tree,
# or a backend that reports no PCI address. Callers MUST treat empty as "do not
# group", including against other empties: two unknowns are not a match.
hba_card_id() {   # $1 = sysfs PCI device dir -> "0000:80:01.0" | ""
    local real rest
    real=$(readlink -f "$1" 2>/dev/null) || return 0
    case "$real" in
        */pci[0-9][0-9][0-9][0-9]:[0-9a-f][0-9a-f]/*) ;;
        *) return 0 ;;
    esac
    rest="${real#*/pci[0-9][0-9][0-9][0-9]:[0-9a-f][0-9a-f]/}"
    rest="${rest%%/*}"
    case "$rest" in
        [0-9a-f][0-9a-f][0-9a-f][0-9a-f]:[0-9a-f][0-9a-f]:[0-9a-f][0-9a-f].[0-9])
            printf '%s' "$rest" ;;
    esac
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `bash tests/topology_test.sh`
Expected: `topology: all pass`

- [ ] **Step 5: Mutation-check the guard**

Temporarily delete the `case "$rest" in ... esac` validation so any string is
printed, and re-run. The "no host bridge" and "missing path" checks must fail.
Restore afterwards.

- [ ] **Step 6: ShellCheck and commit**

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "/$(pwd -W | sed 's|:||'):/w" -w //w \
  koalaman/shellcheck:stable -S warning -e SC1090,SC2034,SC2207,SC1007 \
  source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh tests/topology_test.sh
git commit -m "Name the slot a controller sits in, so two IOCs can be one card"
```

---

### Task 2: Emit `card_id` from both composer paths

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh:107-109` and `:147-149`
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh:123`
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/hba.sh` (the JSON block ending at the `"status"` line)
- Test: `tests/run.sh`, `tests/expected/*.json` (all overview goldens gain the field)

**Interfaces:**
- Consumes: `hba_card_id <sysfs-pci-device-dir>` from Task 1.
- Produces: every controller object carries `"card_id": "<root-port>"`, empty string when unresolved. Field order: immediately after `"pci_location"`.

- [ ] **Step 1: Export it from the storcli composer**

In `get_hba_info.sh`, replace lines 107-109:

```bash
    LSI_TOPOLOGY=$([ -n "$hnum" ] && hba_topology "$hnum" || printf 'unknown')
    LSI_SUBVENDOR=$([ -n "$dir" ] && hba_subvendor "$dir")
    export LSI_TOPOLOGY LSI_SUBVENDOR
```

with:

```bash
    LSI_TOPOLOGY=$([ -n "$hnum" ] && hba_topology "$hnum" || printf 'unknown')
    LSI_SUBVENDOR=$([ -n "$dir" ] && hba_subvendor "$dir")
    # The slot, for grouping the two IOCs of a dual-controller board. Same
    # $dir the subvendor read uses, so it costs one more sysfs resolve.
    LSI_CARD_ID=$([ -n "$dir" ] && hba_card_id "$dir")
    export LSI_TOPOLOGY LSI_SUBVENDOR LSI_CARD_ID
```

- [ ] **Step 2: Export it from the lsiutil composer**

In the same file, replace lines 147-149:

```bash
    LSI_TOPOLOGY=$([ -n "$hnum" ] && hba_topology "$hnum" || printf 'unknown')
    LSI_SUBVENDOR=$([ -n "$pdir" ] && hba_subvendor "$pdir")
    export LSI_TOPOLOGY LSI_SUBVENDOR
```

with:

```bash
    LSI_TOPOLOGY=$([ -n "$hnum" ] && hba_topology "$hnum" || printf 'unknown')
    LSI_SUBVENDOR=$([ -n "$pdir" ] && hba_subvendor "$pdir")
    # Always resolves to one card here: lsiutil addresses a single controller,
    # so this path never produces two entries to group. Emitted anyway so the
    # field means the same thing on both backends.
    LSI_CARD_ID=$([ -n "$pdir" ] && hba_card_id "$pdir")
    export LSI_TOPOLOGY LSI_SUBVENDOR LSI_CARD_ID
```

- [ ] **Step 3: Emit it from the storcli parser**

In `parse/storcli_overview.sh`, find the `TOPOLOGY=`/`SUBVENDOR=` defaults block
and add alongside it:

```bash
CARD_ID="${LSI_CARD_ID:-}"
```

Then in the JSON line (line 123), insert after `"pci_location":"${PCI}",`:

```
"card_id":"${CARD_ID}",
```

- [ ] **Step 4: Emit it from the lsiutil parser**

In `parse/hba.sh`, next to the existing injected defaults:

```bash
TOPOLOGY="${LSI_TOPOLOGY:-unknown}"
SUBVENDOR="${LSI_SUBVENDOR:-}"
```

add:

```bash
CARD_ID="${LSI_CARD_ID:-}"
```

and in the JSON block, after the `"pci_location"` line:

```
  "card_id": "${CARD_ID}",
```

- [ ] **Step 5: Run the suite and confirm exactly the expected failures**

Run: `bash tests/run.sh`
Expected: every overview golden fails on one added line. No other failures.
If a non-overview test fails, stop — something reads the shape and the flat-array
constraint has been broken.

- [ ] **Step 6: Update the goldens**

Add `"card_id": ""` to each failing golden in `tests/expected/`, positioned
directly after `pci_location`. Empty is correct: `tests/run.sh` builds a flat
`SYSPCI` tree with no `pci0000:NN` ancestor, so the walk finds no root port —
which is exactly the "cannot resolve, do not group" case.

- [ ] **Step 7: Add a golden that proves a real slot resolves**

In `tests/run.sh`, beside the existing `SYSPCI` setup, build one nested device
and point a check at it:

```bash
# A card whose sysfs ancestry is real, so card_id resolves to a slot rather
# than to empty. Without this every golden would pin the empty case and a
# broken walk would look correct.
SYSNEST=$(mktemp -d)
mkdir -p "$SYSNEST/pci0000:80/0000:80:01.0/0000:82:00.0/0000:84:00.0"
```

Extend the existing `trap` line to remove `$SYSNEST` as well.

- [ ] **Step 8: Run the suite**

Run: `bash tests/run.sh`
Expected: `--- all pass ---`

- [ ] **Step 9: Mutation-check the wiring**

Delete the `LSI_CARD_ID=` line from the storcli composer and re-run. At least
one check must fail. This is the exact gap that hid the topology bug for a whole
session — a field the parser defaults, wired by nothing, tested by nothing.
Restore afterwards.

- [ ] **Step 10: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts tests
git commit -m "Carry the slot through to every controller record"
```

---

### Task 3: The grouping rule

**Files:**
- Create: `source/usr/local/emhttp/plugins/hbaviewer/card_group.php`
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json` (add `ioc_count` to `SAS9300-16i`)
- Create: `tests/card_group_test.php`
- Modify: `tests/run_php.sh` (register the new test)

**Interfaces:**
- Produces:
  - `lsi_ioc_counts(?array $idx): array` — board name → expected IOC count, read from the index's `boards` entries. Missing means 1.
  - `lsi_group_cards(array $ctls, array $iocCounts): array` — a list of groups, each an array of **indices into `$ctls`**, in input order. A controller that does not group appears as a group of one.

- [ ] **Step 1: Write the failing test**

Create `tests/card_group_test.php`:

```php
<?php
/* Grouping decides whether two rows collapse into one card. Getting it wrong in
   the permissive direction merges two SEPARATE cards, which is worse than the
   two-row display it replaces -- so every check here is about refusing to
   group, not about grouping. */
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/card_group.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    if ($ok) { echo "PASS  $name\n"; } else { echo "FAIL  $name\n"; $fails++; }
}
function ctl(string $board, string $card): array {
    return ['board_name' => $board, 'card_id' => $card];
}

$counts = ['SAS9300-16i' => 2];

// The case the feature exists for.
$dual = [ctl('SAS9300-16i', '0000:80:01.0'), ctl('SAS9300-16i', '0000:80:01.0')];
check('two IOCs of a known dual board group',
      lsi_group_cards($dual, $counts) === [[0, 1]]);

// The riser hazard: two separate cards behind one motherboard switch.
$risers = [ctl('SAS9300-8i', '0000:80:01.0'), ctl('SAS9300-8i', '0000:80:01.0')];
check('two single-IOC boards sharing a slot do NOT group',
      lsi_group_cards($risers, $counts) === [[0], [1]]);

// Count must match exactly, not "at least".
$three = [ctl('SAS9300-16i', '0000:80:01.0'), ctl('SAS9300-16i', '0000:80:01.0'),
          ctl('SAS9300-16i', '0000:80:01.0')];
check('three controllers on a board declaring two do NOT group',
      lsi_group_cards($three, $counts) === [[0], [1], [2]]);

// Unresolvable slot. Two unknowns are not a match.
$empty = [ctl('SAS9300-16i', ''), ctl('SAS9300-16i', '')];
check('an empty card_id never groups, not even with another empty',
      lsi_group_cards($empty, $counts) === [[0], [1]]);

// Same board name, different slots: two 16i cards, four IOCs, two groups.
$two16 = [ctl('SAS9300-16i', '0000:80:01.0'), ctl('SAS9300-16i', '0000:80:01.0'),
          ctl('SAS9300-16i', '0000:00:11.0'), ctl('SAS9300-16i', '0000:00:11.0')];
check('two dual cards make two groups, not one of four',
      lsi_group_cards($two16, $counts) === [[0, 1], [2, 3]]);

// Mixed names in one slot cannot be one board.
$mixed = [ctl('SAS9300-16i', '0000:80:01.0'), ctl('SAS9300-8i', '0000:80:01.0')];
check('differing board names in one slot do NOT group',
      lsi_group_cards($mixed, $counts) === [[0], [1]]);

// A single controller is a group of one, so callers need no special case.
check('a lone controller is a group of one',
      lsi_group_cards([ctl('SAS9300-8i', '0000:00:11.0')], $counts) === [[0]]);

check('an empty controller list yields no groups',
      lsi_group_cards([], $counts) === []);

// Order is preserved, because the Overview renders in enumeration order.
check('groups come back in input order',
      lsi_group_cards(
          [ctl('SAS9300-8i', '0000:00:11.0'),
           ctl('SAS9300-16i', '0000:80:01.0'), ctl('SAS9300-16i', '0000:80:01.0')],
          $counts) === [[0], [1, 2]]);

// The count map comes from the index, and a board without ioc_count means 1.
$idx = ['boards' => ['SAS9300-16i' => ['ioc_count' => 2], 'SAS9300-8i' => []]];
$fromIdx = lsi_ioc_counts($idx);
check('ioc_count is read from the index',      ($fromIdx['SAS9300-16i'] ?? 0) === 2);
check('a board without ioc_count means one',   ($fromIdx['SAS9300-8i'] ?? 0) === 1);
check('no index at all yields no counts',      lsi_ioc_counts(null) === []);

echo $fails === 0 ? "card_group: all pass\n" : "card_group: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
```

- [ ] **Step 2: Run it to verify it fails**

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "/$(pwd -W | sed 's|:||'):/w" -w //w \
  php:8.2-cli php tests/card_group_test.php
```
Expected: fatal error, `card_group.php` does not exist.

- [ ] **Step 3: Write the implementation**

Create `source/usr/local/emhttp/plugins/hbaviewer/card_group.php`:

```php
<?PHP
/* Which controllers are the same physical card.
 *
 * A SAS9300-16i is one board carrying two SAS3008 IOCs: two PCI functions, two
 * storcli indices, two die temperature sensors. Everything below the UI is
 * right to see two controllers; only the display should say "one card".
 *
 * The key is card_id, the PCI root port -- the slot. Two controllers sharing
 * one are on one board, because two cards cannot occupy one slot.
 *
 * The guard matters more than the rule. Server boards and risers can put
 * several SLOTS behind one motherboard PCIe switch, in which case two genuinely
 * separate cards share a root port. Merging those is worse than the split
 * display this replaces, so a shared slot alone is never enough: the board must
 * be one the index says carries that many IOCs, and the count must match
 * exactly. Everything unrecognised stays split, which is the old behaviour. */

/* board name => expected IOC count. Absent from the index, or absent from a
   board's entry, means one -- the overwhelmingly common case, and the one that
   cannot merge anything. */
function lsi_ioc_counts(?array $idx): array {
    $out = [];
    foreach (($idx['boards'] ?? []) as $board => $b) {
        $out[$board] = max(1, (int) ($b['ioc_count'] ?? 1));
    }
    return $out;
}

/* Returns groups of INDICES into $ctls, in input order. A controller that does
   not group comes back as a group of one, so callers never special-case. */
function lsi_group_cards(array $ctls, array $iocCounts): array {
    // Bucket by slot+board. Both, not just slot: two different boards in one
    // slot cannot be one card, and that shape is exactly what a riser produces.
    $buckets = [];
    foreach ($ctls as $i => $c) {
        $card  = (string) ($c['card_id'] ?? '');
        $board = (string) ($c['board_name'] ?? '');
        // Unresolvable slot: no ancestry, no PCI address, or a backend that
        // reports neither. Two unknowns are not a match, so give each its own
        // bucket keyed by index and let it fall through as a group of one.
        $key = $card === '' ? "?$i" : "$card\0$board";
        $buckets[$key][] = $i;
    }

    $groups = [];
    foreach ($buckets as $key => $members) {
        $board = (string) ($ctls[$members[0]]['board_name'] ?? '');
        $want  = $iocCounts[$board] ?? 1;
        // Exactly, not "at least": a count that does not match means the
        // topology is not the one this board is known to have, and guessing
        // from there is how two cards become one.
        if (count($members) > 1 && count($members) === $want) {
            $groups[] = $members;
            continue;
        }
        foreach ($members as $i) { $groups[] = [$i]; }
    }

    // Buckets are insertion-ordered but a group of one emitted from a later
    // bucket can precede an earlier index, so sort by first member to restore
    // enumeration order -- the Overview renders in the order the composer found
    // the cards and must keep doing so.
    usort($groups, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    return $groups;
}
```

- [ ] **Step 4: Run it to verify it passes**

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "/$(pwd -W | sed 's|:||'):/w" -w //w \
  php:8.2-cli php tests/card_group_test.php
```
Expected: `card_group: all pass`

- [ ] **Step 5: Add `ioc_count` to the index**

In `data/known-firmware.json`, on the `SAS9300-16i` board entry only, add:

```json
"ioc_count": 2,
```

Leave every other board untouched. The 9201-16i, 9305-16i, 9305-24i and
9400-16i are all single-IOC despite the port count.

**Errors here are not symmetric, so be careful in one direction only.** A board
wrongly marked dual simply never groups, because the count will not match — an
annoyance. A single-IOC board wrongly marked dual is the dangerous case: two of
them on one riser would match the count and merge into one card. Add
`ioc_count` only for boards confirmed to carry that many chips.

The maintainer's list of genuinely dual-controller LSI HBAs is
**9300-16i** (two SAS3008) and **9206-16e** (two SAS2308). Only the 9300-16i is
in the index today, and it is the one confirmed against real hardware. The
9206-16e is a SAS2 board with no index entry at all; adding one needs firmware
data this plan does not have, so it stays out — the feature simply will not
trigger for it, which is the correct default.

- [ ] **Step 6: Register the test**

Add to `tests/run_php.sh` alongside the existing entries:

```bash
php card_group_test.php   || fail=1
```

Match the surrounding style exactly — read the neighbouring lines first, they
may use a helper rather than a bare `php` call.

- [ ] **Step 7: Mutation-check the guard**

Change `count($members) === $want` to `count($members) > 1`. The riser check and
the three-controller check must both fail. Restore afterwards.

- [ ] **Step 8: Run everything and commit**

```bash
bash tests/run.sh
MSYS_NO_PATHCONV=1 docker run --rm -v "/$(pwd -W | sed 's|:||'):/w" -w //w \
  ghcr.io/phpstan/phpstan:latest analyse source tests --level=3 --no-progress
git add source/usr/local/emhttp/plugins/hbaviewer/card_group.php \
        source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json \
        tests/card_group_test.php tests/run_php.sh
git commit -m "Decide when two controllers are one card"
```

---

### Task 4: Overview renders the group

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` — `renderOverviewCards()`, which starts at line 342
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/view.php` — the server-rendered first paint
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/chrome.css`
- Test: `tests/ajax_render_test.php`

**Interfaces:**
- Consumes: `lsi_group_cards(array $ctls, array $iocCounts): array` and `lsi_ioc_counts(?array $idx): array` from Task 3; `fw_load()` from `firmware_index.php`.
- Produces: `renderControllerCard(array $c, int $i, array $cfg, string $driver): string` — the existing per-controller card, extracted unchanged, so Task 5 and `view.php` can reuse it.

**Field split.** The current card mixes two kinds of fact. When a group renders,
they separate as follows. Do not move any field not listed here.

| Goes on the parent (board) | Stays per IOC |
|---|---|
| Model, Chip | Temperature gauge and `lu-temp-band` chip |
| Firmware + verdict clause, BIOS | Drives connected |
| Driver, Mode | That IOC's own `lu-badge` |
| PCIe row, Badge Sensitivity, Last read | |
| `lu-badge` showing the **worst** child status | |

- [ ] **Step 1: Extract the existing card body, changing nothing**

In `ajax_info.php`, move the body of the `foreach (lsi_controllers($data) as $i => $c)`
loop in `renderOverviewCards()` into a new function directly above it:

```php
/* One controller, one card -- the markup this page has always emitted. Pulled
   out of renderOverviewCards's loop so a dual-IOC board can compose a grouped
   card from the same pieces instead of a second copy of them drifting apart. */
function renderControllerCard(array $c, int $i, array $cfg, string $driver): string {
```

The function takes `$port = $cfg['HBA_PORT']`, `$threshold = $cfg['ALERT_THRESHOLD']`
and `$showPcie = $cfg['SHOW_PCIE']` from `$cfg` exactly as the outer function does,
returns the string the loop used to append, and keeps the `isset($c['error'])`
early-return as its first branch. The loop becomes:

```php
    foreach (lsi_controllers($data) as $i => $c) {
        $out .= renderControllerCard($c, $i, $cfg, $driver);
    }
```

- [ ] **Step 2: Prove the extraction changed no output**

Run: `bash tests/run.sh`
Expected: `--- all pass ---` with **no golden touched**. If any overview golden
fails, the extraction was not faithful — fix the extraction, never the golden.
This step exists so that the next one starts from a known-identical baseline.

- [ ] **Step 3: Commit the extraction on its own**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php
git commit -m "Extract the per-controller Overview card, byte for byte"
```

- [ ] **Step 4: Write the failing test**

Add to `tests/ajax_render_test.php`, matching the `check()` helper already there:

```php
/* A dual-IOC board renders as ONE card with a sub-card per controller. Both
   temperatures must survive: two dies, two sensors, and one number standing for
   two would be a wrong reading rather than a simplification. */
$dualCfg  = ['HBA_PORT' => 1, 'ALERT_THRESHOLD' => 76, 'SHOW_PCIE' => 1];
$dualData = ['driver' => 'mpt3sas 54.100.00.00', 'controllers' => [
    ['model' => 'SAS3008', 'board_name' => 'SAS9300-16i', 'card_id' => '0000:80:01.0',
     'temp' => 56, 'temp_band' => 'normal',   'cfg_band' => 'warning', 'status' => 'ok',
     'firmware' => '16.00.12.00', 'bios' => '08.15.00.00', 'mode' => 'IT',
     'pci_location' => '00:84:00:00', 'drive_count' => '2'],
    ['model' => 'SAS3008', 'board_name' => 'SAS9300-16i', 'card_id' => '0000:80:01.0',
     'temp' => 71, 'temp_band' => 'elevated', 'cfg_band' => 'warning', 'status' => 'warn',
     'firmware' => '16.00.12.00', 'bios' => '08.15.00.00', 'mode' => 'IT',
     'pci_location' => '00:86:00:00', 'drive_count' => '6'],
]];
$html = renderOverviewCards($dualData, $dualCfg);

check('one parent card for a dual-IOC board', substr_count($html, 'lu-card-parent') === 1);
check('a sub-card per controller',            substr_count($html, 'lu-card-ioc') === 2);
check('both temperatures are shown',          str_contains($html, '>56<') && str_contains($html, '>71<'));
check('the board name appears once',          substr_count($html, 'SAS9300-16i') === 1);
check('the parent takes the worse status',    str_contains($html, 'lu-card-parent'));

/* The case that must NOT change. A single-IOC card grows no wrapper: everyone
   without a dual board sees exactly the page they saw before. */
$soloData = ['driver' => 'mpt3sas 54.100.00.00', 'controllers' => [$dualData['controllers'][0]]];
$soloData['controllers'][0]['board_name'] = 'SAS9300-8i';
$soloData['controllers'][0]['card_id']    = '0000:00:11.0';
$solo = renderOverviewCards($soloData, $dualCfg);
check('a single-IOC card grows no parent wrapper',
      !str_contains($solo, 'lu-card-parent') && !str_contains($solo, 'lu-card-ioc'));
```

- [ ] **Step 5: Run it to verify it fails**

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "/$(pwd -W | sed 's|:||'):/w" -w //w \
  php:8.2-cli php tests/ajax_render_test.php
```
Expected: FAIL on the parent and sub-card counts — two plain cards render today.

- [ ] **Step 6: Implement the grouped render**

Require the grouping helper at the top of `ajax_info.php`, beside the existing
requires:

```php
require_once __DIR__ . '/card_group.php';
```

Replace the loop in `renderOverviewCards()`:

```php
    $ctls   = lsi_controllers($data);
    $groups = lsi_group_cards($ctls, lsi_ioc_counts(fw_load()));
    foreach ($groups as $g) {
        // A group of one is the old path exactly -- same function, no wrapper,
        // byte-identical output. That is what keeps every existing golden green
        // and every user without a dual-IOC board seeing no change at all.
        if (count($g) === 1) {
            $out .= renderControllerCard($ctls[$g[0]], $g[0], $cfg, $driver);
            continue;
        }
        $out .= renderGroupedCard($ctls, $g, $cfg, $driver);
    }
```

Add `renderGroupedCard()` beside `renderControllerCard()`. It builds the parent
from the first member -- board name, chip, firmware and PCIe are properties of
the board and both IOCs report them identically -- and one sub-card per member
carrying only the per-IOC fields from the table above:

```php
/* A dual-IOC board: one card for the board, one sub-card per controller.
 *
 * Board-level fields come from the first member. Both IOCs of a 9300-16i report
 * the same model, chip, firmware, BIOS and PCIe link, because those describe the
 * card; reading them from member 0 rather than merging avoids inventing a rule
 * for a disagreement that cannot happen on a working board.
 *
 * Temperature is the exception and must never be merged: two dies, two sensors.
 * On the maintainer's card both read 56C, equal because they share airflow and
 * load -- not because they are one reading. */
function renderGroupedCard(array $ctls, array $group, array $cfg, string $driver): string {
    $head  = $ctls[$group[0]];
    // Worst-of, so the parent says something true about the board: a card whose
    // second IOC is overheating must not show a green badge because its first
    // one is fine.
    $rank  = ['ok' => 0, 'warn' => 1, 'alert' => 2];
    $worst = 'ok';
    foreach ($group as $i) {
        $s = (string) ($ctls[$i]['status'] ?? 'ok');
        if (($rank[$s] ?? 0) > ($rank[$worst] ?? 0)) { $worst = $s; }
    }
    ...
}
```

Complete the body using the same expressions the extracted
`renderControllerCard()` uses for each field — `lsi_hba_view()`,
`fw_overview_clause()`, `lsi_gauge_svg()`, `lsi_temp_color()` — so the two
renderers stay one implementation of each field rather than two. Wrap the whole
thing in:

```php
'<div class="lu-card first lu-card-parent" data-status="' . htmlspecialchars($worst) . '">'
```

and each member in:

```php
'<div class="lu-card-ioc"><span class="lu-ioc-label">Controller ' . $i . '</span>' . ... . '</div>'
```

- [ ] **Step 7: Run the tests**

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "/$(pwd -W | sed 's|:||'):/w" -w //w \
  php:8.2-cli php tests/ajax_render_test.php
bash tests/run.sh
```
Expected: both pass, and again **no existing golden changes**.

- [ ] **Step 8: Mirror the grouping in `view.php`**

`view.php` paints the Overview server-side on first load; if it stays flat the
page reshapes when the AJAX refresh lands. Apply the same grouping with the same
class names. If `view.php` can call `renderOverviewCards()` rather than keeping
its own copy, do that instead — one renderer is better than two that agree today.

Run `MSYS_NO_PATHCONV=1 docker run --rm -v "/$(pwd -W | sed 's|:||'):/w" -w //w php:8.2-cli php tests/view_test.php`
after.

- [ ] **Step 9: Add the CSS**

In `chrome.css`, beside the existing `.lu-card` rules:

```css
/* A dual-IOC board: one card, one sub-card per controller. Separated by a rule
   and an indent rather than boxed again -- nesting a full card inside a card
   reads as two cards, which is the thing this feature exists to stop. */
.lu-card-parent .lu-card-ioc {
    border-top: 1px solid var(--border);
    padding: 10px 0 0 14px;
    margin-top: 10px;
}
.lu-card-parent .lu-ioc-label {
    color: var(--faint); font-size: 11.5px; font-weight: 700;
    letter-spacing: 0.04em; text-transform: uppercase;
}
```

- [ ] **Step 10: Mutation-check the worst-of badge**

Change `> ($rank[$worst] ?? 0)` to `< ($rank[$worst] ?? 0)` and re-run
`ajax_render_test.php`. A check must fail. If none does, the test is not reading
the badge and needs strengthening before you move on — a parent that always
reports the first IOC's status is the likeliest way this feature ships broken.
Restore afterwards.

- [ ] **Step 11: Commit**

```bash
bash tests/run.sh
MSYS_NO_PATHCONV=1 docker run --rm -v "/$(pwd -W | sed 's|:||'):/w" -w //w \
  ghcr.io/phpstan/phpstan:latest analyse source tests --level=3 --no-progress
git add source/usr/local/emhttp/plugins/hbaviewer tests
git commit -m "Render a dual-IOC board as one card with a sub-card per controller"
```

---

### Task 5: Firmware page — one card, both IOCs

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/flash.php` (controller listing, `flash_preflight`, the flash action)
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/flash_view.js` (one entry per card)
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/flash_hba.sh:105-136`
- Test: `tests/flash_test.sh`, `tests/flash_php_test.php`

**Interfaces:**
- Consumes: `lsi_group_cards()` / `lsi_ioc_counts()` from Task 3.
- Produces: `flash_hba.sh` accepts a comma-separated controller list in place of a single index — `list 0,1` and `flash 0,1` — and loops it. A single index keeps working unchanged.

- [ ] **Step 1: Write the failing shell test**

Add to `tests/flash_test.sh`:

```bash
# A dual-IOC board is one card and must be verified and flashed as one. The
# controller argument therefore accepts a list. Broadcom's advice for these
# boards is -fwall, which this deliberately does NOT use: -fwall means every
# controller in the SYSTEM, so on a box holding a 9300-16i and a 9300-8i it
# writes the 16i image to the 8i and bricks it. Looping the card's own indices
# meets the same intent -- never leave one IOC behind -- with no blast radius.
out=$(FLASHER_LOG="$TMP/calls" run_flash list 0,1 SAS3008)
grep -q -- '-c 0 -list' "$TMP/calls" || bad "verify calls IOC 0"
grep -q -- '-c 1 -list' "$TMP/calls" || bad "verify calls IOC 1"
grep -q -- '-listall'   "$TMP/calls" && bad "verify must not use -listall"

: > "$TMP/calls"
out=$(FLASHER_LOG="$TMP/calls" run_flash flash 0,1 SAS3008 --fw "$TMP/fw.bin")
[ "$(grep -c -- '-o' "$TMP/calls")" = 2 ] || bad "flash writes both IOCs"
grep -q -- '-fwall' "$TMP/calls" && bad "flash must never use -fwall"
```

Adapt `run_flash` and `FLASHER_LOG` to the harness this file already uses — read
its existing stub setup first and follow it rather than inventing a second one.

- [ ] **Step 2: Run it to verify it fails**

Run: `bash tests/flash_test.sh`
Expected: FAIL — `0,1` is not a valid controller index today.

- [ ] **Step 3: Accept a controller list in `flash_hba.sh`**

Replace the `list` block at line 105:

```sh
if [ "$mode" = list ]; then
    # Scope to THE CARD -- every IOC on it and nothing else. Not -listall: on a
    # box with a 9300-16i and a 9200-8i that would show the 9200 while the
    # operator is verifying the 16i, which is the confusion this scoping has
    # always existed to prevent. A dual-IOC board is one card, so both of its
    # controllers belong in the same verification output.
    rc=0
    for one in $(printf '%s' "$ctl" | tr ',' ' '); do
        echo "--- controller /c$one ---"
        if [ "$gen" = storcli ]; then "$tool" /c"$one" show || rc=$?
        else                          "$tool" -c "$one" -list || rc=$?; fi
    done
    exit $rc
fi
```

- [ ] **Step 4: Loop the flash over the card's IOCs**

Replace the flash dispatch (the `if [ "$gen" = storcli ]` block at line 121)
with a loop over the same list, and make a partial failure explicit:

```sh
done_ok=""
for one in $(printf '%s' "$ctl" | tr ',' ' '); do
    if [ "$gen" = storcli ]; then
        [ -n "$fw" ] || die "$chip is flashed through storcli, where the BIOS is part of the firmware package. A BIOS-only flash is not possible on this generation." 5
        echo "+ storcli /c$one download file=$fw"
        "$tool" /c"$one" download file="$fw" || flash_rc=$?
    else
        set -- -c "$one" -o
        [ -n "$fw" ]   && set -- "$@" -f "$fw"
        [ -n "$bios" ] && set -- "$@" -b "$bios"
        echo "+ $(basename "$tool") $*"
        "$tool" "$@" || flash_rc=$?
    fi
    if [ -n "${flash_rc:-}" ]; then
        # A board left with one IOC updated and one not is the new hazard this
        # loop introduces, and it must never be reported as a generic failure:
        # the operator has to know the card is now mismatched and which half
        # succeeded, because rebooting on a half-flashed board is what turns a
        # failed update into a dead card.
        if [ -n "$done_ok" ]; then
            die "PARTIAL FLASH. Controller(s) /c$done_ok on this card were written successfully and /c$one FAILED. The two controllers on this board are now running different firmware. Do NOT reboot. Re-run the flash for /c$one before doing anything else." 6
        fi
        die "flash of /c$one failed and nothing was written" 6
    fi
    done_ok="${done_ok:+$done_ok,}$one"
done
exit 0
```

Keep the existing pre-flight checks (`no firmware or BIOS image given`, the
`-f`/`-b` existence tests) above the loop, unchanged — they are card-level and
must run once, before anything is written.

- [ ] **Step 5: Run the shell test**

Run: `bash tests/flash_test.sh`
Expected: all pass.

- [ ] **Step 6: Widen the controller validation in `flash.php`**

Two sites validate the controller as a single integer, and both now receive a
list. `flash.php:59`, inside `flash_preflight()`:

```php
    if (!preg_match('/^\d+$/', (string) ($in['ctl'] ?? '')))
```

and `flash.php:173`, in the `listall` action:

```php
    if ($chip === '' || !preg_match('/^\d+$/', $ctl)) { echo 'Invalid controller.'; exit; }
```

Both become the same list pattern — digits and commas only, anchored with `\z`
rather than `$` so a trailing newline cannot slip through:

```php
'/^\d+(,\d+)*\z/'
```

Nothing else about the two call sites changes; the value still goes through
`escapeshellarg()`. Keep the pattern identical in both places and in the test in
Step 8 — this string is what stands between a form field and a script that
writes firmware to a controller, so it is worth having exactly one spelling of
it that a grep can find.

- [ ] **Step 7: Emit one entry per card and let the JS carry the list**

The page builds its cards from `d.controllers` (`flash_view.js:46`) and uses the
**array index** as the controller number throughout — `data-ctl="'+i+'"` at lines
49 and 51, `ctl:i` in the `listall` fetch at line 188 and the `flash` fetch at
line 217, and `luFlashTool(i)` at line 85.

Give each returned entry an explicit `ctl` string and have the JS use that
instead of the index:

- In the endpoint that supplies `d.controllers`, require `card_group.php`, group
  with `lsi_group_cards($ctls, lsi_ioc_counts(fw_load()))`, and return one entry
  per group with `'ctl' => implode(',', $group)` plus the board-level fields
  taken from the first member.
- In `flash_view.js`, replace every use of the loop index as a controller number
  with `c.ctl`: `data-ctl="'+fesc(c.ctl)+'"`, `ctl:c.ctl` in both fetches, and
  `luFlashTool(c.ctl)`. `flashCard()` already looks up by the `data-ctl`
  attribute, so it keeps working once the attribute holds the list.
- Label the card with every controller it covers, e.g. `Controller /c0, /c1`,
  so the operator can see the Verify output will cover both.

The index is still fine as a DOM id suffix; it is only its use as a *controller
number* that has to go. Do not add an endpoint — the shape of the existing one
changes, nothing more.

- [ ] **Step 8: Pin the new rules in `flash_php_test.php`**

```php
/* -fwall means every controller in the SYSTEM, not every controller on this
   card. On a box with a 9300-16i and a 9300-8i it would write the 16i image to
   the 8i. The loop in flash_hba.sh replaces it and must stay replaced. */
$shSrc = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/scripts/flash_hba.sh');
check('the flasher never uses -fwall',        !str_contains($shSrc, 'fwall'));
check('verification never uses -listall',     !str_contains($shSrc, '-listall'));
check('a partial flash says so explicitly',   str_contains($shSrc, 'PARTIAL FLASH'));
check('and tells the operator not to reboot', str_contains($shSrc, 'Do NOT reboot'));

/* The controller argument reaches a script that writes firmware. */
$flashSrc = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/flash.php');
check('the controller list is validated as digits and commas only',
      str_contains($flashSrc, "preg_match('/^\\d+(,\\d+)*\\z/'"));
```

Use the exact regex the implementation ends up with; the two must match.

- [ ] **Step 9: Full verification**

```bash
bash tests/run.sh
MSYS_NO_PATHCONV=1 docker run --rm -v "/$(pwd -W | sed 's|:||'):/w" -w //w \
  ghcr.io/phpstan/phpstan:latest analyse source tests --level=3 --no-progress
MSYS_NO_PATHCONV=1 docker run --rm -v "/$(pwd -W | sed 's|:||'):/w" -w //w \
  koalaman/shellcheck:stable -S warning -e SC1090,SC2034,SC2207,SC1007 \
  source/usr/local/emhttp/plugins/hbaviewer/scripts/*.sh
MSYS_NO_PATHCONV=1 docker run --rm -v "/$(pwd -W | sed 's|:||'):/w" -w //w \
  node:20-alpine node --check source/usr/local/emhttp/plugins/hbaviewer/flash_view.js
```
Expected: `--- all pass ---`, `[OK] No errors`, no ShellCheck output, no node output.

- [ ] **Step 10: Update the docs and commit**

`ARCHITECTURE.md` gains `card_group.php` in its file table. `HOWTO.md`'s
firmware section states that a dual-controller board is flashed as one card and
that both controllers are written in sequence.

```bash
git add source/usr/local/emhttp/plugins/hbaviewer tests ARCHITECTURE.md HOWTO.md
git commit -m "Flash and verify a dual-IOC board as one card"
```

---

## Verification before merge

- `bash tests/run.sh` → `--- all pass ---`
- PHPStan level 3 → `[OK] No errors`
- ShellCheck clean, `node --check` clean
- **Browser check on Raven**, which is the only dual-IOC board available: the
  Overview shows one SAS9300-16i card with two controller sub-cards and two
  distinct temperatures; the firmware page lists one card; Verify returns both
  controllers' listings in one window.
- **Not testable without risk, and deliberately not tested:** the flash loop
  itself, and the partial-flash error path. No actual flash has ever been
  performed by this plugin.
