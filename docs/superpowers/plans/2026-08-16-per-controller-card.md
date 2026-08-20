# Per-Controller Card Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Own the per-controller card loop once instead of four times, then split `ajax_info.php`'s 1,502 lines into per-tab renderer files, leaving it as dispatch and fetch.

**Architecture:** A new `luCardPerController(array $ctls, callable $body): string` holds the card shell, the multi-controller heading and the error branch that four renderers currently repeat verbatim; each renderer passes a closure that emits only its own body. Then each tab's renderer family moves to its own file under `render/`, required by `ajax_info.php` so the CLI test seam still defines every function.

**Tech Stack:** PHP 8 (Unraid's bundled), assertion tests via `tests/run_php.sh` (341 assertions in `tests/ajax_render_test.php`), golden HTML via `tests/run.sh`.

**Spec:** `docs/superpowers/specs/2026-08-16-per-controller-card-design.md`

## Global Constraints

- **No file in `tests/expected/` may change**, including `overview_single_pcie.html`, which pins exact rendered markup. `bash tests/run.sh` must end `--- all pass ---` after every task. Never run `UPDATE=1`.
- Run everything from the repo root. Branch is `advisor/codebase-improvements`. PHP suite alone: `bash tests/run_php.sh`.
- **`tests/ajax_render_test.php` requires `ajax_info.php` and calls its render functions directly**, relying on `if (PHP_SAPI === 'cli') return;` at line 60. After any split, requiring `ajax_info.php` must still define every render function, so all 341 assertions keep running unchanged. If a task breaks that seam, it has failed.
- The per-controller card contract must produce byte-identical output: card shell `<div class="lu-card first" data-ctl="N">`, `luCtlHead($i)` only when more than one controller, and an error branch that emits the muted paragraph AND closes the card. That last part is what stops an errored controller rendering as loose text between two cards.
- **Do not merge `renderGroupedCard` into `renderControllerCard`.** It is out of scope and has its own reasons — see the spec. Leave both alone.
- Commit style: a sentence saying what changed and why, no `feat:`/`chore:` prefixes.

---

### Task 1: Own the per-controller card loop once

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` — add the helper near `luTable` (~line 274), then change four renderers at `:813`, `:972`, `:1328`, `:1404`
- Test: `tests/ajax_render_test.php`

**Interfaces:**
- Consumes: `luCtlHead(int $i): string`, already at `:580`.
- Produces: `luCardPerController(array $ctls, callable $body): string`. `$body` receives `(int $i, array $ctl)` and returns the card's inner HTML, without the wrapping div and without handling the error case. Task 2 moves this function; nothing else depends on it.

- [ ] **Step 1: Write the failing test**

Add to `tests/ajax_render_test.php`, immediately after the `luTable` checks:

```php
/* The card shell four renderers used to repeat verbatim. The error branch is
   the load-bearing part: an errored controller must still get its own card and
   the card must be CLOSED, or it renders as bare text floating between its
   neighbours' cards. luCtlHead appears only when there is more than one
   controller -- a single-controller box gets no heading, which is what every
   existing single-controller expectation pins. */
check('card: one card per controller', function_exists('luCardPerController')
    && substr_count(luCardPerController([[], []], fn($i, $c) => 'X'), 'lu-card first') === 2);
check('card: body output lands inside the card',
    str_contains(luCardPerController([['phys' => []]], fn($i, $c) => 'BODYMARK'), 'BODYMARK'));
check('card: single controller gets no heading',
    !str_contains(luCardPerController([[]], fn($i, $c) => ''), 'Controller /c'));
check('card: two controllers get headings',
    substr_count(luCardPerController([[], []], fn($i, $c) => ''), 'Controller /c') === 2);
check('card: an errored controller still gets a closed card',
    luCardPerController([['error' => 'no response']], fn($i, $c) => 'NEVER')
        === '<div class="lu-card first" data-ctl="0"><p class="lu-muted">no response</p></div>');
check('card: the body is not called for an errored controller',
    !str_contains(luCardPerController([['error' => 'x']], fn($i, $c) => 'NEVER'), 'NEVER'));
check('card: error text is escaped',
    str_contains(luCardPerController([['error' => '<b>x']], fn($i, $c) => ''), '&lt;b&gt;x'));
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
bash tests/run_php.sh 2>&1 | grep -E "^FAIL|ajax_render"
```

Expected: all seven FAIL — `luCardPerController` does not exist. If any PASSES, stop and report: a check that passes against a missing function is testing nothing.

- [ ] **Step 3: Add the helper**

In `ajax_info.php`, immediately after `luTable`'s closing brace (~line 295), add:

```php
/* One card per controller, and the three rules every tab shares about it: the
   card shell carries data-ctl so the JS can find it, a heading appears only when
   there is more than one controller, and an errored controller still gets its
   own CLOSED card rather than bare text floating between its neighbours'.
   Four renderers each carried these seven lines, byte-identical apart from one
   word of comment, and each documented the contract by pointing at
   renderOverviewCards. $body renders only what is inside the card, and is not
   called at all for a controller that reported an error. */
function luCardPerController(array $ctls, callable $body): string {
    $multi = count($ctls) > 1;
    $out   = '';
    foreach ($ctls as $i => $ctl) {
        $out .= '<div class="lu-card first" data-ctl="' . $i . '">';
        if ($multi) $out .= luCtlHead($i);
        if (isset($ctl['error'])) {
            $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['error']) . '</p></div>';
            continue;
        }
        $out .= $body($i, $ctl) . '</div>';
    }
    return $out;
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
bash tests/run_php.sh 2>&1 | grep -E "^FAIL|ajax_render"
```

Expected: `ajax_render: all pass` — all seven new checks green, nothing else moved.

- [ ] **Step 5: Migrate `renderHealthTables` first — it is the smallest**

`renderHealthTables` is at `:1404`. Its loop currently reads:

```php
    $multi = count($ctls) > 1;
    $out   = '';
    foreach ($ctls as $i => $ctl) {
        // One card per HBA, matching renderOverviewCards — including the error
        // branch below, or an errored controller renders as bare text floating
        // between two cards.
        $out .= '<div class="lu-card first" data-ctl="' . $i . '">';
        if ($multi) $out .= luCtlHead($i);
        if (isset($ctl['error'])) { $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['error']) . '</p></div>'; continue; }
```

Replace those lines with:

```php
    return luCardPerController($ctls, function (int $i, array $ctl) use ($cfg): string {
        $out = '';
```

Then, at the end of that function, the loop's tail — which currently closes the card, closes the `foreach`, and returns — becomes a `return $out;` for the closure plus a `});` to close the call. **Read the whole function before editing it**, find every `continue;` inside the loop (a `continue` in a `foreach` becomes `return $out;` in a closure — they are not equivalent and this is the single most likely way to break this task), and make sure the `</div>` that closed each card is no longer emitted by the body, because `luCardPerController` now emits it.

Any variable the body used from the enclosing scope must appear in the `use (...)` list. Run the test after this one function, before touching the others.

- [ ] **Step 6: Verify the health migration alone**

```bash
bash tests/run_php.sh 2>&1 | grep -E "^FAIL|ajax_render"
bash tests/run.sh 2>&1 | grep -E "^FAIL|FAILURES|^---"
```

Expected: both clean. If anything fails here, fix it before migrating another renderer — a bug in the first migration will otherwise be copied into three more.

- [ ] **Step 7: Migrate the other three the same way**

`renderEventsTables` (`:1328`), `renderDrivesTables` (`:972`), then `renderPhyTables` (`:813`) — largest last. For each: same transformation, same `use (...)` discipline, same `continue;` → `return $out;` care. Run `bash tests/run_php.sh` after EACH one and record the result; do not batch them.

`renderPhyTables` has the most locals of the four, so its `use (...)` list is the longest — check its body for every variable defined before the loop.

- [ ] **Step 8: Prove the duplication is gone**

```bash
grep -c "lu-card first' . \$i" source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php
grep -n "data-ctl=" source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php
```

Expected: the card shell string appears in `luCardPerController` and in the overview card renderers only — not in any of the four tab renderers. Record the remaining occurrences and which function each is in.

- [ ] **Step 9: Full suite and commit**

```bash
bash tests/run.sh 2>&1 | grep -E "^FAIL|FAILURES|^---"
```

Expected: `--- all pass ---`.

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php tests/ajax_render_test.php
git commit -m "Own the per-controller card once, not four times

Four tab renderers opened with the same seven lines -- card shell, heading when
multi, and an error branch that closes the card -- differing only in one word of
comment, each pointing at renderOverviewCards to explain the contract they were
copying.

luCardPerController holds it now and takes a closure for the body, which is not
called at all for a controller that reported an error. The error branch is the
part worth naming: without it an errored controller renders as bare text
floating between its neighbours' cards, which is what the four copies existed to
prevent."
```

---

### Task 2: Split the renderers into per-tab files

Mechanical move, no logic changes. Each tab's functions go to their own file; `ajax_info.php` requires them and keeps dispatch, fetch and `$scriptMap`.

**Files:**
- Create: `source/usr/local/emhttp/plugins/hbaviewer/render/table.php`, `render/smart.php`, `render/overview.php`, `render/phy.php`, `render/drives.php`, `render/events.php`, `render/health.php`, `render/baymap.php`
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php`

**Interfaces:**
- Consumes: `luCardPerController` from Task 1 — it moves to `render/table.php` alongside `luTable`, since three tabs use both.
- Produces: nothing new. Every function keeps its name and signature.

- [ ] **Step 1: Move the shared helpers first**

Create `render/table.php` with an opening `<?php` and no closing tag, holding `luTable`, `luCardPerController` and `luCtlHead` — the three things more than one tab uses. Delete them from `ajax_info.php` and add near its other requires:

```php
require_once __DIR__ . '/render/table.php';
```

Run `bash tests/run_php.sh`. Expected: unchanged, all pass. If a function-not-found error appears, the require order is wrong — `render/table.php` must be required before anything that calls it at load time (nothing should, since these are all function definitions, but check).

- [ ] **Step 2: Move one tab, verify, repeat**

In this order, moving one family per step and running `bash tests/run_php.sh` after each:

| File | Functions to move |
|---|---|
| `render/smart.php` | `smart_cache_read`, `smart_cache_age`, `smart_state`, `smart_state_color`, `renderSmartTable` |
| `render/events.php` | `renderEventsTables` |
| `render/health.php` | `luHealthCtlMeta`, `renderHealthTables` |
| `render/drives.php` | `lsi_dev_by_serial`, `drive_dev_name`, `lsi_scsi_addr_by_dev`, `lsi_role_cell`, `renderDrivesTables` |
| `render/phy.php` | `luPhyBaselineBar`, `luPhyCell`, `phy_drive`, `phy_drive_label`, `phy_recent_rate`, `phy_top_offenders`, `renderPhyTables` |
| `render/baymap.php` | `unraid_disk_roles`, `unraid_parity_devs`, `unraid_rebuilding`, `bay_map_assemble`, `bay_tray_order` |
| `render/overview.php` | `luTempTile`, `renderControllerCard`, `renderGroupedCard`, `renderOverviewCards`, `luLinkBadge` |

Each new file starts with `<?php` and a one-line comment naming the tab it serves. Add its `require_once` to `ajax_info.php` in the same block as `render/table.php`. **Move functions verbatim — do not reformat, rename, or "tidy" anything while moving.** A move task that also edits is a move task whose diff nobody can review.

Do not batch. After each family: `bash tests/run_php.sh` must stay clean.

- [ ] **Step 3: Full suite**

```bash
bash tests/run.sh 2>&1 | grep -E "^FAIL|FAILURES|^---"
wc -l source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php source/usr/local/emhttp/plugins/hbaviewer/render/*.php
```

Expected: `--- all pass ---`, and `ajax_info.php` down from 1,502 lines to roughly 300. Record the actual numbers.

- [ ] **Step 4: Confirm the CLI test seam still holds**

```bash
grep -n "PHP_SAPI === 'cli'" source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php
grep -n "require_once __DIR__ . '/ajax_info.php'" tests/ajax_render_test.php
```

Expected: the seam is still at the top of `ajax_info.php`, and it sits AFTER the `require_once` lines for the render files. If the seam returns before the requires, the test file gets no render functions and every one of the 341 assertions fails — verify by reading, not by assuming.

- [ ] **Step 5: Check the plugin package ships the new directory**

```bash
grep -n "hbaviewer/" hbaviewer.plg | head -20
grep -rn "render/" build.sh 2>/dev/null
```

The `.txz` is built from `source/`, so a new subdirectory is normally included automatically — but confirm nothing enumerates files explicitly. If anything does, add `render/`. Record what you found; a missing directory means every page 500s on a real install, and no test here would catch it.

- [ ] **Step 6: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/render source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php
git commit -m "Give each tab's renderers their own file

ajax_info.php was 1,502 lines: dispatch, one shared fetch, and then every
renderer in the plugin, which is why its four render calls sat 700 lines below
the fetch that feeds them. The functions move to render/ by tab -- only luTable,
luCardPerController and luCtlHead are shared, which is what makes the split fall
along tab lines rather than arbitrary ones.

Pure move: no function renamed, no body edited. ajax_info.php keeps dispatch and
fetch, and still requires every render file, so tests/ajax_render_test.php's 292
assertions reach the same functions through the same CLI seam."
```

---

## Self-review notes

- **Spec coverage.** The loop duplication → Task 1. The file's mass and the fetch/render separation → Task 2. The spec's exclusion of the `renderGroupedCard` merge → Global Constraints, and no task touches either card renderer except to move it.
- **The risk in Task 1 is `continue`.** Inside a `foreach`, `continue` skips to the next controller; inside the closure it must become `return $out;`, which returns that controller's body. They look interchangeable and are not. Step 5 migrates one renderer and verifies before the other three, specifically so a wrong transformation is caught once rather than four times.
- **The risk in Task 2 is require order and packaging.** Steps 4 and 5 exist for those. Step 5's check cannot be verified by any test in this repo — a missing directory in the package only shows up on a real install — so it is a read-and-record step, not a test.
- **Type consistency.** `luCardPerController(array $ctls, callable $body): string` is defined in Task 1 and moved unchanged in Task 2. `$body` receives `(int $i, array $ctl)` at every call site.
- **Not attempted.** Merging the two card renderers (own plan, see spec), and candidates 2, 5 and 6 of the architecture review.
