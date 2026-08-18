# Bay map JS: runtime tests for the write paths — design

Architecture-review candidate 2, reframed. Puts the bay map's client-side
write paths under test. No production code changes.

## This candidate's premise was false, and the correction is the point

The review proposed candidate 2 as "~685 duplicated JS render lines" in
`hbaviewer.js`. There is no duplication. Verified three ways:

- `render/baymap.php` documents itself as **the data half only** (lines 60-64):
  the grid is interactive, so its state lives in JS either way, and
  server-rendered cells would have to be re-derived on every click. That is a
  deliberate split with a stated reason, not an accident.
- No PHP file emits a single `lu-bay-cell` or `lu-bay-chip`. Only
  `hbaviewer.js` and `chrome.css` mention them.
- Inside the JS, `luBayRender` builds the chrome once and `luBayPaint` builds
  the cells. They do not overlap. The bay cell is a rich card; the tray chip is
  one `<span>` by design.

The ~685 lines are *all* of the bay map's JS, not duplicated JS. This is the
fifth review premise that has not survived contact with the code (see the four
recorded in the 2026-08-16 specs). Read the code before trusting that report.

## What is actually wrong here

| | Lines | Tests |
|---|---|---|
| `bay_map.php` — server-side store writer | 384 | 72 checks |
| bay map JS — the client driving every write | ~685 | none |

The largest untested surface in the plugin is the code that mutates the one
store that cannot be regenerated. A bay map is built by walking to the rack and
reading labels off a chassis; nothing else in this plugin remembers it, and
`bay_map.php` is the only thing that persists it.

The comments in `luBayPaint` are a list of bugs that already shipped there:

- A per-cell `ondblclick` that could never fire (2026.08.05). Clicking a filled
  bay repaints the grid, so the two clicks of a double-click land on different
  nodes and the browser dispatches at their common ancestor.
- An `input`-vs-`change` handler on the dimension fields. Clearing the box to
  retype it read as "1 row", and the debounced save displaced every drive below
  row 0 — an accidental wipe of exactly the state that cannot be rebuilt.

Both are wiring bugs. Neither is visible in the pure logic.

## Scope

**Write paths only.** The code splits into parts that write —
`luBayCommit`/`luBayApply`, `luBayDims`, `luBayClear`, `luBayRestore`,
`luBayUndo`, `luBayLock` — and parts that draw. Only the writers can damage the
store, and both known bugs were writers.

Drawing stays untested. Markup assertions break on cosmetic CSS changes and
would not have caught either shipped bug.

## Approach: drive the gestures

`hbaviewer.js` is a single IIFE. `luBayCommit`, `luBayApply` and `luBayDims`
are private; the public surface is `luBayFetch`, `luBayClear`, `luBayUndo`,
`luBayCopy`, `luBayRestore`, `luBayLock` and `luLocate`.

So assign, unassign and resize are reachable only through the handlers
`luBayPaint` attaches. The tests invoke those handlers directly and assert on
captured POSTs.

**Rejected: adding a test seam.** Exporting `luBayApply`/`luBayDims` on
`window` would shrink the shim to almost nothing, but it changes production
code to suit tests and pins only the pure logic — skipping the wiring layer
where both real failures happened. A test that took that route would have
caught neither bug it is being written for.

This follows `tests/flash_js_test.js`, which drove `flash_view.js` through its
public surface and killed a mutant every text assertion survived.

## Constraints

1. **No production code changes.** If pinning a behaviour appears to require
   one, stop and raise it rather than treating it as licence to refactor.
2. **No dependencies.** The repo has no `package.json` and no `node_modules`;
   CI runs bare `node`, and `tests/run.sh` falls back to `node:20-alpine` in
   docker. jsdom is out.
3. **No sleeping.** The `dims` POST is on a 400ms debounce. Timers are stubbed
   and flushed, never waited on.
4. **Every assertion names the mutant it kills**, and each must be shown
   failing against that mutant before it is accepted. An assertion that cannot
   be made to fail is not evidence.

## The shim

The smallest DOM this file runs against. Required because `luBayPaint` builds
cells with `createElement` rather than markup, unlike `flash_view.js`.

- `document.createElement` → element stub: `className`, `style`, `textContent`,
  `dataset`, `draggable`, `setAttribute`, `appendChild` (sets `parentNode`),
  and handler properties `onclick`, `ondblclick`, `ondragstart`, `ondragover`,
  `ondrop`, `ondragend`.
- `classList` with `add`, `remove`, `toggle`, `contains`.
- `closest(sel)` walking `parentNode`. Matches only the three selector forms
  this file uses: bare tag (`button`), class (`.lu-bay-cell`), and attribute
  presence (`[data-bay-key]`). Anything else must throw rather than silently
  return null — a shim that quietly fails to match would make the delegation
  tests pass regardless of the code.
- `getElementById` over a registry seeded with the ids `hbaviewer.php` supplies
  (`baymap-content`, `bay-hint`) plus those `luBayRender`'s markup creates
  (`bay-grid`, `bay-tray`, `bay-rows`, `bay-cols`, `bay-lock`).
- `innerHTML` setter clearing children and harvesting ids, as `flash_js_test.js`
  does.
- `fetch` capturing `{url, params}` for every call and replying from fixtures.
  Must answer the `type=overview` request that `loadOverview()` fires at IIFE
  load, before any bay map test runs.
- `confirm` / `alert` / `prompt`: scriptable queued answers plus a captured
  message log. Six assertions turn on a confirm being declined.
- `setTimeout` / `clearTimeout` recording callbacks, with `flushTimers()`.
- `luCsrf`, `window.location.search = ''`, `querySelectorAll` returning `[]`.

Not stubbed: layout, CSS, real event dispatch, `Chart`. Handlers are invoked
with a fake event carrying `target`, `preventDefault`, `stopPropagation` and
`dataTransfer`.

**The shim is the risk in this plan.** A stub wrong in the same direction as a
bug lets both through — a `closest` matching too eagerly would make every
delegation test pass regardless of the code. The mutation requirement in
constraint 4 is the guard, and it is worth more here than breadth of
assertions.

## Fixture

One payload in `bay_map_assemble`'s output shape:

- `rows: 2`, `cols: 3` — non-square and non-full on purpose. A 2x3 grid makes a
  row/col transposition a visible failure; 3x3 would hide it.
- Two placed drives at known, distinct coordinates.
- Two tray entries, one with `key: null` (reported neither port nor PHY, so it
  is shown but not placeable).
- `locked: false`, `has_backup: true`, a `warn_temp`, and drives carrying the
  fields the cell reads (`dev`, `serial`, `model`, `cap`, `temp`, `state`).

A second variant with `locked: true` for the lock assertions.

## Behaviours pinned

Each row is one assertion and the mutant it kills.

| # | Behaviour | Assertion | Mutant it kills |
|---|---|---|---|
| 1 | Click drive, click empty bay **at column 0** | one `assign` with that key, row, col | two: swap row/col in the POST body (caught because the grid is 2x3, so a transposed coordinate leaves the grid); and change `col === null` to `!col` in `luBayCommit`, which turns an assign to column 0 into an unassign. **The target bay must be at column 0 or the second mutant survives** — an assign to any other column posts identically under it. |
| 2 | Drop onto an occupied bay | occupant moves to tray, one `assign` | delete the displacement filter in `luBayApply` — two drives in one bay |
| 3 | Double-click a filled bay | one `unassign`, key from `dataset.bayKey` | move the `ondblclick` handler from the grid to the cell — the 2026.08.05 bug exactly. The test invokes `grid.ondblclick`, which is then undefined. |
| 4 | Drag a tray chip onto the tray | **no POST** | delete the `placed.some(...)` guard in `tray.ondrop` |
| 5 | Resize smaller, confirm accepted | non-fitting drives to tray, one `dims` after flush | drop the `p.row < rows && p.col < cols` test |
| 6 | Resize with a blank field | **no POST, no model change** | delete the `rows >= 1 && rows <= 12` guard — the wipe bug |
| 7 | Resize declined at the confirm | fields restored to prior values, **no POST** | remove the `rf.value = d.rows` restore |
| 8 | Clear on an empty map | alert shown, **no POST** | delete the `if (!n)` early return |
| 9 | Clear declined at the confirm | **no POST** | invert the `confirm` test |
| 10 | Server replies `{ok:false}` | alert **and** a reload request | delete the `!j.ok` branch — silent drift |
| 11 | Any write while `locked` | **no POST** | drop a `d.locked` guard |

Six of the eleven assert that nothing goes on the wire. For a store nobody can
rebuild, the absent POST is the half that matters.

## Wiring

`tests/run.sh` already has a node-or-docker block for `flash_js_test.js`
(lines 461-471). `baymap_js_test.js` joins it under the same guard, gets its own
`baymap_js_fail` variable, and is added to the final pass/fail conjunction. A
box without node or docker skips both rather than failing.

CI needs no change: `php.yml` lints every `.js` with `node --check` and runs
`tests/run.sh`, which will pick the new file up.

## What this does and does not prove

**Proves:** `luBayCommit`/`luBayApply`, `luBayDims`, and `luBayClear` post what
they claim to and stay silent when they should — including the two wiring
layers where real bugs shipped.

**Does not prove:** that the grid looks right, that drag-and-drop works in a
real browser, or that `bay_map.php` persists correctly. The last already has 72
checks of its own. The first two remain what the Raven install test covers, by
hand. Also not covered: `luBayRestore` (paste-a-map import), `luBayUndo`, and
`luBayLock` — named as in-scope writers above but not exercised by this file.
