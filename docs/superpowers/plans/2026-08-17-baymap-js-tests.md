# Bay map JS write-path tests — Implementation Plan

> **Status: COMPLETE.** Shipped. `tests/baymap_js_test.js`, since extended with the keyboard write paths.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Put the bay map's client-side write paths under runtime test, so the code that mutates the one unregenerable store cannot silently change what it posts.

**Architecture:** One new file, `tests/baymap_js_test.js`, runs the real `hbaviewer.js` inside `node:vm` against a hand-rolled DOM shim, invokes the handlers `luBayPaint` attaches, and asserts on captured `fetch` calls. Three lines of wiring in `tests/run.sh`. No production code changes.

**Tech Stack:** Plain node (`node:fs`, `node:vm`, `node:path`). No package.json, no dependencies, no jsdom.

**Spec:** `docs/superpowers/specs/2026-08-17-baymap-js-tests-design.md`

## Global Constraints

- **No production code changes.** `source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js` is read-only to this plan, except for temporary mutants that MUST be reverted with `git checkout --` in the same step. If pinning a behaviour appears to require a production change, STOP and report it — that is not licence to refactor.
- **No dependencies.** No `package.json`, no `node_modules`, no jsdom. The shim is hand-rolled.
- **No sleeping.** Timers are stubbed and flushed. No real delays. The `dims` POST is on a 400ms debounce and must be triggered by `flushTimers()`.
- **Every assertion names the mutant it kills, and each must be SHOWN failing against that mutant before the task is complete.** An assertion that cannot be made to fail is not evidence and must be replaced.
- Test file style follows `tests/flash_js_test.js`: `'use strict'`, a `check(name, ok)` helper, `PASS  `/`FAIL  ` prefixes, prints `baymap_js: all pass` and exits 0, or exits 1 on any failure.
- Six of the eleven behaviours assert that **no POST happens**. Those are as load-bearing as the positive ones — a store nobody can rebuild is damaged by writes that should not have happened.

---

## File Structure

| File | Responsibility |
|---|---|
| `tests/baymap_js_test.js` | CREATE. The whole harness: shim, fixture, all eleven behaviours. Single file, matching `flash_js_test.js`'s precedent of one self-contained runtime test per subject. |
| `tests/run.sh` | MODIFY. Add `baymap_js_test.js` to the existing node-or-docker block and to the final pass/fail conjunction. |

Everything lives in one test file on purpose: the shim and the assertions are useless apart, and `flash_js_test.js` already set this pattern.

---

## Facts an implementer needs (verified against the code)

**Module shape.** `hbaviewer.js` is one IIFE, `(function () { ... })()`. `luBayCommit`, `luBayApply`, `luBayDims`, `luBayPaint`, `luBayRender` are PRIVATE. Public: `window.luBayFetch`, `luBayClear`, `luBayUndo`, `luBayCopy`, `luBayRestore`, `luBayLock`, `luLocate`, `luTab`.

**Load-time behaviour.** The IIFE ends by calling `loadOverview()` and then reading `new URLSearchParams(window.location.search).get('tab')`.
`loadOverview()` starts with `var el = document.getElementById('overview-content'); if (!el) return;`
**So: do NOT register an `overview-content` element.** `loadOverview` then returns immediately, fires no fetch, and starts no refresh timer. This keeps the shim small and is why the fixture never has to answer `type=overview_html`.

**Globals the file reads that the shim must provide:** `window` (self-referencing), `document`, `fetch`, `alert`, `confirm`, `prompt`, `setTimeout`, `clearTimeout`, `URLSearchParams`, `console`, `luCsrf` (declared in `hbaviewer.php`, NOT in the .js), `location.search`.

**URLs.**
- Load: `GET /plugins/hbaviewer/ajax_info.php?type=baymap`
- Every write: `POST /plugins/hbaviewer/bay_map.php`, body `new URLSearchParams(body)`, and `luBayPost` sets `body.csrf_token = luCsrf` before sending.

**Actions posted:** `assign` (key,row,col), `unassign` (key), `dims` (rows,cols), `clear`, `restore` (this is Undo), `import` (map), `lock` (locked `'0'`/`'1'`).

**`luBayPost` reply handling:** on `!j.ok` it calls `alert(...)` then `luBayReload()` (which is `luBayLoad(true)` → a GET to `?type=baymap`) and returns WITHOUT calling the done callback.

**Elements `luBayRender` creates via innerHTML:** ids `bay-rows`, `bay-cols`, `bay-lock`, `bay-clear`, `bay-copy`, `bay-restore`, `bay-undo` (only when `has_backup && !locked`), `bay-grid`, `bay-tray`. The shim must harvest ids out of assigned `innerHTML`, exactly as `flash_js_test.js` does.

**Elements the page supplies:** `baymap-content` and `bay-hint` (from `hbaviewer.php`). Register both up front.

**Cells.** `luBayPaint` builds each cell with `document.createElement('div')` and appends to `#bay-grid` in row-major order. When NOT locked it sets `cell.dataset.row`, `cell.dataset.col`, and for filled cells `cell.dataset.bayKey` and `cell.draggable = true`, and assigns `cell.onclick = luBayCellClick(r, c, drv)`.
When LOCKED it sets none of those — no `onclick`, no `dataset` — which is what makes behaviour 11 assertable.

**Tray chips.** `document.createElement('span')` appended to `#bay-tray`, with `chip.onclick` and `chip.dataset.trayKey` set only when `u.key !== null && !d.locked`.

**Selectors passed to `closest()` by this file** — the shim must support exactly these and THROW on anything else:
`button`, `.lu-bay-cell`, `.lu-bay-cell[data-bay-key]`, `.lu-bay-chip[data-tray-key]`

**`luBayCellClick` returns a handler that reads `e.detail`** — it returns early when `e.detail > 1`. Test events must pass `detail: 1`.

**Click-to-assign is two steps:** click a tray chip (sets `luBay.sel`, repaints, so element references go stale) then click an EMPTY cell (`luBayCommit(luBay.sel, r, c)`). Always re-read elements after a repaint.

---

## Fixture: why the coordinates are what they are

Grid is **2 rows × 3 cols**. Placed drives at **(0,1)** and **(1,2)**. That leaves (0,0), (0,2), (1,0), (1,1) empty.

**The assign test MUST target row 1, column 0.** Both halves are load-bearing:

- **Column 0** kills the `col === null` → `!col` mutant. Under it, an assign to column 0 posts `unassign` instead. An assign to any other column posts identically and the mutant survives.
- **row ≠ col** kills the row/col swap mutant. `(0,0)` is symmetric and would survive; `(1,0)` transposes to `(0,1)`, a different — and occupied — bay.

A 2×3 grid rather than 3×3 for the same reason: a transposed coordinate can leave the grid entirely and be caught.

Keys are `c0p4`, `c0p5`, `c0p6` — deliberately NOT coordinate-shaped, so a test that accidentally asserts a key against a position fails rather than coincidentally passing.

---

### Task 1: Harness, shim, fixture, and the assign path

**Files:**
- Create: `tests/baymap_js_test.js`
- Modify: `tests/run.sh` (node-or-docker block near line 464; final conjunction near line 480)

**Interfaces:**
- Consumes: nothing.
- Produces, for Tasks 2–4: `check(name, ok)`; `boot(payload)` returning `{ctx, els, calls, alerts, prompts, answers, settle, flushTimers, grid, tray, cellAt, chipAt, posts, lastPost, setReply, setPayload}`; `opened(payload)`; `ev(target, extra)`; `BAY()`; `LOCKED_BAY()`.

- [ ] **Step 1: Write the harness, shim and fixture**

Create `tests/baymap_js_test.js`:

```js
/* Runtime checks for the bay map's WRITE paths in hbaviewer.js.
 *
 * The bay map is the one piece of state in this plugin that cannot be
 * regenerated -- it is built by walking to the rack and reading labels off a
 * chassis. bay_map.php (the server-side writer) has 72 checks; the ~685 lines
 * of JS that drive every one of its writes had none.
 *
 * Both bugs that have shipped here were WIRING bugs, invisible in the pure
 * logic: a per-cell ondblclick that could never fire (the repaint moves the
 * node between the two clicks), and an `input`-vs-`change` handler that read a
 * half-typed field as "1 row" and displaced everything below it. So these
 * tests drive the real handlers luBayPaint attaches, rather than calling
 * internals -- which is also the only way in, since hbaviewer.js is one IIFE
 * and luBayCommit/luBayApply/luBayDims are private.
 *
 * Six of the eleven behaviours assert that NOTHING goes on the wire. For a
 * store nobody can rebuild, the absent POST is the half that matters.
 *
 * No jsdom, no framework, no package.json -- the repo has none and CI runs
 * bare node (docker node:20-alpine as fallback).
 *
 *   node tests/baymap_js_test.js   ->  "baymap_js: all pass" (exit 0)
 */
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

let fails = 0;
const check = (name, ok) => { console.log((ok ? 'PASS  ' : 'FAIL  ') + name); if (!ok) fails++; };

const SRC = path.join(__dirname, '../source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js');
const CODE = fs.readFileSync(SRC, 'utf8');

/* ── fixture ────────────────────────────────────────────────────────────────
   2x3 with drives at (0,1) and (1,2), so (1,0) is empty and is where the
   assign test aims. Column 0 kills the `col === null` -> `!col` mutant; row 1
   makes the coordinates asymmetric so a row/col swap cannot survive. Keys are
   not coordinate-shaped on purpose. */
const BAY = () => ({
    rows: 2, cols: 3, locked: false, warn_temp: 45,
    smart_age: '2 minutes', has_backup: true,
    placed: [
        {key: 'c0p4', dev: '/dev/sdb', serial: 'SER-B', model: 'MODEL-B', cap: '8', cap_unit: 'TB',
         temp: 38, state: 'ok', role: 'Disk 1', port: 'Port 4', slot: '4', addr: '', locating: false,
         rebuild_label: null, row: 0, col: 1},
        {key: 'c0p5', dev: '/dev/sdc', serial: 'SER-C', model: 'MODEL-C', cap: '8', cap_unit: 'TB',
         temp: 41, state: 'ok', role: 'Disk 2', port: 'Port 5', slot: '5', addr: '', locating: false,
         rebuild_label: null, row: 1, col: 2},
    ],
    unassigned: [
        {key: 'c0p6', dev: '/dev/sda', serial: 'SER-A', model: 'MODEL-A', cap: '4', cap_unit: 'TB',
         temp: 35, state: 'ok', role: 'Parity', port: 'Port 6', slot: '6', addr: '', locating: false,
         rebuild_label: null},
        // No key: reported neither port nor PHY. Shown, never placeable.
        {key: null, dev: '/dev/sdd', serial: 'SER-D', model: 'MODEL-D', cap: '4', cap_unit: 'TB',
         temp: null, state: 'nodata', role: '', port: '', slot: '', addr: '', locating: false,
         rebuild_label: null},
    ],
});

const LOCKED_BAY = () => Object.assign(BAY(), {locked: true});

/* ── the smallest DOM this file runs against ────────────────────────────────
   luBayPaint builds cells with createElement + textContent (a drive's own
   firmware is not a trusted source of markup), so unlike flash_js_test.js this
   shim needs real element objects, not just an innerHTML harvester. */
function matchSel(el, sel) {
    const m = /^([a-z]+)?((?:\.[-\w]+)*)((?:\[[-\w]+\])*)$/.exec(sel);
    // Deliberately fatal: a shim that silently fails to match would make every
    // delegation test pass regardless of what the code does.
    if (!m) throw new Error('shim: unsupported selector ' + sel);
    if (m[1] && el.tagName !== m[1].toUpperCase()) return false;
    for (const c of m[2].split('.').filter(Boolean)) {
        if (!(' ' + el.className + ' ').includes(' ' + c + ' ')) return false;
    }
    for (const a of (m[3].match(/\[([-\w]+)\]/g) || [])) {
        const attr = a.slice(1, -1);
        const key = attr.replace(/^data-/, '').replace(/-(\w)/g, (_, ch) => ch.toUpperCase());
        if (el.dataset[key] === undefined) return false;
    }
    return true;
}

function boot(payload) {
    const els = new Map();
    const calls = [];
    const alerts = [];
    const prompts = [];
    const answers = {confirm: [], prompt: []};
    const timers = [];
    let bayPayload = payload || BAY();
    let postReply = {ok: true};

    function mkEl(tag) {
        const el = {
            tagName: String(tag).toUpperCase(),
            id: '', className: '', textContent: '', value: '', title: '',
            draggable: false, parentNode: null, style: {}, dataset: {}, children: [],
            _html: '',
            setAttribute(k, v) { el['attr:' + k] = v; },
            appendChild(c) { c.parentNode = el; el.children.push(c); return c; },
            removeChild(c) { el.children = el.children.filter(x => x !== c); return c; },
            select() {},
            closest(sel) {
                for (let n = el; n; n = n.parentNode) if (matchSel(n, sel)) return n;
                return null;
            },
        };
        const has = (c) => (' ' + el.className + ' ').includes(' ' + c + ' ');
        el.classList = {
            contains: has,
            add(...cs) { cs.forEach(c => { if (!has(c)) el.className = (el.className + ' ' + c).trim(); }); },
            remove(...cs) {
                el.className = el.className.split(/\s+/).filter(x => x && !cs.includes(x)).join(' ');
            },
            toggle(c, on) {
                if (on === undefined ? !has(c) : on) el.classList.add(c); else el.classList.remove(c);
            },
        };
        Object.defineProperty(el, 'innerHTML', {
            get() { return el._html; },
            set(v) { el._html = String(v); el.children = []; harvest(el._html); },
        });
        return el;
    }
    // Register whatever the markup just created, so the next getElementById
    // finds it. Regex, not a parser: this file emits its own markup.
    function harvest(html) {
        for (const m of html.matchAll(/id="([^"]+)"/g)) {
            if (!els.has(m[1])) { const e = mkEl('div'); e.id = m[1]; els.set(m[1], e); }
        }
    }
    // baymap-content and bay-hint come from hbaviewer.php. overview-content is
    // deliberately ABSENT: loadOverview() bails on `if (!el) return`, so it
    // fires no request and starts no refresh timer.
    for (const id of ['baymap-content', 'bay-hint']) {
        const e = mkEl('div'); e.id = id; els.set(id, e);
    }

    const json = (o) => Promise.resolve({
        json: () => Promise.resolve(o),
        text: () => Promise.resolve(JSON.stringify(o)),
    });
    function fakeFetch(url, opts) {
        const body = opts && opts.body ? String(opts.body) : '';
        const params = new URLSearchParams(body);
        calls.push({url, params, body, action: params.get('action') || '',
                    method: (opts && opts.method) || 'GET'});
        if (url.includes('type=baymap')) return json(bayPayload);
        return json(postReply);
    }

    const ctx = {
        console, URLSearchParams, Promise, JSON, Math, Object, Array, String, Number,
        luCsrf: 'tok',
        fetch: fakeFetch,
        alert: (m) => { alerts.push(String(m)); },
        // Queued answers; default DENY. A test that forgets to queue an answer
        // gets the safe outcome (nothing written) rather than a silent write.
        confirm: (m) => {
            alerts.push(String(m));
            return answers.confirm.length ? answers.confirm.shift() : false;
        },
        prompt: (m) => {
            prompts.push(String(m));
            return answers.prompt.length ? answers.prompt.shift() : null;
        },
        setTimeout: (fn, ms) => { timers.push({fn, ms}); return timers.length; },
        clearTimeout: (id) => { if (timers[id - 1]) timers[id - 1].fn = null; },
        location: {search: ''},
        document: {
            body: mkEl('body'),
            createElement: (t) => mkEl(t),
            getElementById: (id) => els.get(id) || null,
            querySelectorAll: () => [],
            querySelector: () => null,
            execCommand: () => true,
        },
    };
    ctx.window = ctx;   // window.luBayClear = fn must also land as a bare global

    vm.createContext(ctx);
    vm.runInContext(CODE, ctx, {filename: 'hbaviewer.js'});

    // Real macrotask drain for the fetch promise chains. The vm's setTimeout is
    // stubbed; this one is the host's, so it cannot be starved by the stub and
    // no test ever sleeps.
    const settle = async (n = 8) => {
        for (let i = 0; i < n; i++) await new Promise(r => setImmediate(r));
    };
    const flushTimers = () => { timers.splice(0).forEach(t => t.fn && t.fn()); };

    const grid = () => els.get('bay-grid');
    const tray = () => els.get('bay-tray');
    const cellAt = (r, c) => grid().children.find(x => +x.dataset.row === r && +x.dataset.col === c);
    const chipAt = (i) => tray().children[i];
    const posts = () => calls.filter(c => c.url.includes('bay_map.php'));
    const lastPost = () => posts()[posts().length - 1];
    const setReply = (o) => { postReply = o; };
    const setPayload = (p) => { bayPayload = p; };

    return {ctx, els, calls, alerts, prompts, answers, settle, flushTimers,
            grid, tray, cellAt, chipAt, posts, lastPost, setReply, setPayload};
}

// A fake DOM event. detail:1 matters -- luBayCellClick ignores e.detail > 1 so
// the second click of a double-click cannot toggle the selection back.
const ev = (target, extra) => Object.assign(
    {target, detail: 1, preventDefault() {}, stopPropagation() {},
     dataTransfer: {setData() {}, effectAllowed: '', dropEffect: ''}},
    extra || {});

/* Open the bay map tab the way the page does, and wait for the payload. */
async function opened(payload) {
    const h = boot(payload);
    h.ctx.luBayFetch();
    await h.settle();
    return h;
}

async function main() {
    /* ── 1. Click a tray drive, then an empty bay -> one assign ──────────────
       Aimed at row 1, COLUMN 0, and both halves are load-bearing:
       - column 0 kills `col === null` -> `!col` in luBayCommit, which turns an
         assign to column 0 into an unassign. Any other column posts the same
         under that mutant and it survives.
       - row != col kills a row/col swap in the POST body. (0,0) is symmetric
         and would survive; (1,0) transposes to (0,1), a different bay. */
    {
        const h = await opened();
        h.chipAt(0).onclick();                       // select the tray drive (c0p6)
        const cell = h.cellAt(1, 0);                 // re-read: the click repainted
        cell.onclick(ev(cell));
        await h.settle();
        const p = h.lastPost();
        check('assign: clicking a tray drive then an empty bay posts that bay',
            !!p && p.action === 'assign' && p.params.get('key') === 'c0p6'
                && p.params.get('row') === '1' && p.params.get('col') === '0');
    }
}

if (require.main === module) {
    main().then(() => {
        console.log(fails ? 'baymap_js: FAILURES' : 'baymap_js: all pass');
        process.exit(fails ? 1 : 0);
    });
}
```

- [ ] **Step 2: Run it and confirm the assertion passes against clean code**

```bash
cd tests && node baymap_js_test.js
```

Expected: `PASS  assign: clicking a tray drive then an empty bay posts that bay`, then `baymap_js: all pass`, exit 0.

If it fails, the shim is wrong — not the production code. Debug the shim. Do NOT change `hbaviewer.js`.

- [ ] **Step 3: Prove the assertion kills mutant A (row/col swap)**

```bash
cd "$(git rev-parse --show-toplevel)"
sed -i "s/{action: 'assign', key: key, row: row, col: col}/{action: 'assign', key: key, row: col, col: row}/" \
    source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
cd tests && node baymap_js_test.js; echo "exit=$?"
cd .. && git checkout -- source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
git status --porcelain source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
```

Expected: `FAIL  assign: ...`, `exit=1`, and no output from `git status` (the revert took).

- [ ] **Step 4: Prove the assertion kills mutant B (`!col`)**

```bash
cd "$(git rev-parse --show-toplevel)"
sed -i "s/luBayPost(col === null ?/luBayPost(!col ?/" \
    source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
cd tests && node baymap_js_test.js; echo "exit=$?"
cd .. && git checkout -- source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
git status --porcelain source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
```

Expected: `FAIL`, `exit=1` — the assign to column 0 posts `unassign` instead — and a clean `git status`.

**If either mutant SURVIVES, the test is not evidence.** Fix the assertion (most likely cause: the target bay is not at column 0, or row equals col) and repeat.

- [ ] **Step 5: Wire it into `tests/run.sh`**

Find the existing node-or-docker block (search for `flash_js_test.js`). Immediately after that block's closing `fi`, add:

```bash

echo
echo "=== bay map JS write-path tests ==="
# Same local-then-docker fallback as the flash_view.js block above, and for the
# same reason: Unraid has no node. The bay map is the one store that cannot be
# regenerated, so its write paths are pinned by running them.
if command -v node >/dev/null 2>&1; then
    node baymap_js_test.js; baymap_js_fail=$?
elif command -v docker >/dev/null 2>&1; then
    MSYS_NO_PATHCONV=1 docker run --rm \
        -v "$(cd .. && { pwd -W 2>/dev/null || pwd; }):/app" -w /app/tests \
        node:20-alpine node baymap_js_test.js; baymap_js_fail=$?
else
    echo "SKIP  bay map JS runtime tests (no node, no docker)"; baymap_js_fail=0
fi
```

Then add `baymap_js_fail` to the final conjunction at the end of the file, directly after the `[ $flash_js_fail -eq 0 ]` term:

```bash
 && [ $baymap_js_fail -eq 0 ]
```

Do not reorder the existing terms.

- [ ] **Step 6: Run the whole suite, then prove the wiring is real**

```bash
cd "$(git rev-parse --show-toplevel)" && bash tests/run.sh 2>&1 | tail -3
```

Expected: `--- all pass ---`.

A failing JS test must fail the SUITE, not just its own file:

```bash
sed -i "s/luBayPost(col === null ?/luBayPost(!col ?/" source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
bash tests/run.sh >/dev/null 2>&1; echo "suite exit=$?"
git checkout -- source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
```

Expected: `suite exit=1`. A `0` here means the conjunction edit did not take.

- [ ] **Step 7: Commit**

```bash
git add tests/baymap_js_test.js tests/run.sh
git commit -m "Run the bay map's assign path, instead of trusting it

The bay map is the one store here that cannot be regenerated, and the ~685
lines of JS driving every write to it had no tests at all -- while
bay_map.php, which only persists what they send, has 72 checks.

The shim is bigger than flash_js_test.js's because luBayPaint builds cells
with createElement rather than markup. It is also the risk in this file: a
stub wrong in the same direction as a bug would hide it, which is why every
assertion here is proven against a named mutant.

This one aims at row 1, column 0 deliberately. Column 0 is what kills
col === null -> !col; an asymmetric coordinate is what kills a row/col swap."
```

---

### Task 2: Displacement, double-click unassign, and the tray no-op

**Files:**
- Modify: `tests/baymap_js_test.js` (add to `main()`, after behaviour 1)

**Interfaces:**
- Consumes: `opened()`, `ev()`, `check()`, `cellAt()`, `chipAt()`, `grid()`, `tray()`, `posts()`, `lastPost()` from Task 1.
- Produces: nothing new.

- [ ] **Step 1: Add the three behaviours inside `main()`**

```js
    /* ── 2. Drop onto an OCCUPIED bay -> the occupant goes back to the tray ──
       One drive per bay, matching the server. The local model is updated
       optimistically before the POST, so if the displacement filter goes, the
       grid shows two drives in one bay until the next reload. */
    {
        const h = await opened();
        h.chipAt(0).onclick();                        // pick up c0p6 from the tray
        const target = h.cellAt(0, 1);                // occupied by c0p4
        target.onclick(ev(target));
        await h.settle();
        const p = h.lastPost();
        const trayKeys = h.tray().children.map(x => x.dataset.trayKey);
        check('displace: dropping onto an occupied bay posts the assign',
            !!p && p.action === 'assign' && p.params.get('key') === 'c0p6'
                && p.params.get('row') === '0' && p.params.get('col') === '1');
        check('displace: the drive that was in that bay is back in the tray',
            trayKeys.includes('c0p4'));
        check('displace: that bay holds exactly one drive',
            h.grid().children.filter(x => x.dataset.bayKey === 'c0p4'
                                       || x.dataset.bayKey === 'c0p6').length === 1);
    }

    /* ── 3. Double-click a filled bay -> one unassign ────────────────────────
       The handler is on the GRID, not the cell. A per-cell ondblclick shipped
       in 2026.08.05 and could never fire: single-clicking a filled bay picks
       the drive up, which repaints and replaces every cell, so the browser
       sees the two clicks land on different nodes and dispatches dblclick at
       their common ancestor. Invoking grid.ondblclick is exactly that path. */
    {
        const h = await opened();
        const cell = h.cellAt(0, 1);                  // holds c0p4
        h.grid().ondblclick(ev(cell));
        await h.settle();
        const p = h.lastPost();
        check('unassign: double-clicking a filled bay posts unassign for that drive',
            !!p && p.action === 'unassign' && p.params.get('key') === 'c0p4');
    }

    /* ── 4. Drag a tray chip back onto the tray -> nothing on the wire ───────
       Unassigning something already unassigned is a no-op, not a POST. */
    {
        const h = await opened();
        const chip = h.chipAt(0);                     // c0p6, already unassigned
        h.tray().ondragstart(ev(chip));
        h.tray().ondrop(ev(h.tray()));
        await h.settle();
        check('tray: dragging an unassigned drive back to the tray posts nothing',
            h.posts().length === 0);
    }
```

- [ ] **Step 2: Run and confirm every check passes**

```bash
cd tests && node baymap_js_test.js
```

Expected: all PASS, `baymap_js: all pass`.

- [ ] **Step 3: Prove behaviour 2 kills its mutant (displacement filter removed)**

```bash
cd "$(git rev-parse --show-toplevel)"
python - <<'PY'
p = 'source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js'
s = open(p, encoding='utf-8').read()
old = """            d.placed = d.placed.filter(function (p) {
                if (p.row !== row || p.col !== col) return true;
                delete p.row; delete p.col;
                d.unassigned.push(p);           // one drive per bay, same as the server
                return false;
            });
"""
assert old in s, 'anchor not found -- read luBayApply and adjust'
open(p, 'w', encoding='utf-8').write(s.replace(old, ''))
PY
cd tests && node baymap_js_test.js; echo "exit=$?"
cd .. && git checkout -- source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
```

Expected: the two `displace:` checks about the tray and the bay count FAIL, `exit=1`.

- [ ] **Step 4: Prove behaviour 3 kills its mutant (handler moved off the grid)**

The historical bug bound dblclick to the cell instead of the grid. Simulate by leaving `grid.ondblclick` unassigned:

```bash
cd "$(git rev-parse --show-toplevel)"
sed -i "s/^        grid\.ondblclick = function (e) {/        grid.NOTondblclick = function (e) {/" \
    source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
cd tests && node baymap_js_test.js; echo "exit=$?"
cd .. && git checkout -- source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
```

Expected: `exit=1`. A `TypeError` on calling `undefined` is acceptable evidence that the mutant is caught, but the file must still print a verdict — if the throw prevents that, wrap the invocation so the run records a FAIL and continues.

- [ ] **Step 5: Prove behaviour 4 kills its mutant (guard removed)**

```bash
cd "$(git rev-parse --show-toplevel)"
sed -i "s|            if (!luBay\.data\.placed\.some(function (p) { return p\.key === key; })) return;||" \
    source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
cd tests && node baymap_js_test.js; echo "exit=$?"
cd .. && git checkout -- source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
```

Expected: `FAIL  tray: dragging an unassigned drive back to the tray posts nothing`, `exit=1`.

- [ ] **Step 6: Confirm the production file is clean and the suite is green**

```bash
cd "$(git rev-parse --show-toplevel)"
git status --porcelain source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
bash tests/run.sh 2>&1 | tail -2
```

Expected: no output from `git status`, and `--- all pass ---`.

- [ ] **Step 7: Commit**

```bash
git add tests/baymap_js_test.js
git commit -m "Pin one drive per bay, and the double-click that empties one

The dblclick check invokes grid.ondblclick, which is the whole point: a
per-cell handler shipped in 2026.08.05 and could never fire, because
picking the drive up repaints the grid and the two clicks land on different
nodes. Moving the handler back onto the cell makes this check fail.

The tray no-op check asserts an ABSENT post -- dragging an unassigned drive
onto the tray must not write."
```

---

### Task 3: Resize — the path that has already wiped a map

**Files:**
- Modify: `tests/baymap_js_test.js` (add to `main()`)

**Interfaces:**
- Consumes: `opened()`, `check()`, `flushTimers()`, `answers`, `els`, `tray()`, `posts()`, `lastPost()` from Task 1.
- Produces: nothing new.

Background the implementer needs: `luBayDims` is bound to `#bay-rows`/`#bay-cols` `.onchange` (NOT `oninput` — that is the bug it guards against). It reads both fields, returns early unless BOTH parse to 1..12, asks for confirmation only when drives would be displaced, and posts `dims` through a 400ms `setTimeout`. Nothing is on the wire until `flushTimers()` runs.

- [ ] **Step 1: Add the three behaviours inside `main()`**

```js
    /* ── 5. Shrink the grid, accept the warning -> displaced drive, one dims ─
       2x3 -> 2x2 evicts the drive at (1,2). The POST is debounced 400ms, so
       nothing is on the wire until the timers are flushed. */
    {
        const h = await opened();
        h.answers.confirm.push(true);
        h.els.get('bay-cols').value = '2';
        h.els.get('bay-rows').value = '2';
        h.els.get('bay-cols').onchange();
        check('resize: the dims post is debounced, not immediate', h.posts().length === 0);
        h.flushTimers();
        await h.settle();
        const p = h.lastPost();
        check('resize: accepting the shrink posts the new dimensions',
            !!p && p.action === 'dims' && p.params.get('rows') === '2' && p.params.get('cols') === '2');
        check('resize: the drive that no longer fits is back in the tray',
            h.tray().children.map(x => x.dataset.trayKey).includes('c0p5'));
    }

    /* ── 6. A blank field is not a resize request ────────────────────────────
       THE WIPE BUG. Clearing the box to retype it read as "1 row", and the
       debounced save then displaced every drive below row 0 -- destroying a
       map somebody walked to the rack to build. Nothing may happen at all: no
       confirm, no model change, no POST. */
    {
        const h = await opened();
        h.answers.confirm.push(true);          // consumed only if a confirm is reached
        h.els.get('bay-cols').value = '';
        h.els.get('bay-cols').onchange();
        h.flushTimers();
        await h.settle();
        check('resize: a blank dimension field posts nothing', h.posts().length === 0);
        check('resize: a blank dimension field displaces no drive',
            h.tray().children.filter(x => x.dataset.trayKey).length === 1);
        check('resize: a blank dimension field does not even ask', h.answers.confirm.length === 1);
    }

    /* ── 7. Declining the shrink puts the fields back ────────────────────────
       Without the restore the boxes keep showing a grid size the map does not
       have, and the next change is computed from a lie. */
    {
        const h = await opened();
        h.answers.confirm.push(false);
        h.els.get('bay-cols').value = '2';
        h.els.get('bay-cols').onchange();
        h.flushTimers();
        await h.settle();
        check('resize: declining the shrink posts nothing', h.posts().length === 0);
        check('resize: declining the shrink puts the field back to 3',
            String(h.els.get('bay-cols').value) === '3');
    }
```

- [ ] **Step 2: Run and confirm every check passes**

```bash
cd tests && node baymap_js_test.js
```

If `resize: the dims post is debounced, not immediate` fails, the shim's `setTimeout` is executing immediately instead of queueing — fix the shim, not the production code.

- [ ] **Step 3: Prove behaviour 6 kills the wipe mutant**

This is the most important mutation in the plan: it is the bug that already shipped.

```bash
cd "$(git rev-parse --show-toplevel)"
python - <<'PY'
p = 'source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js'
s = open(p, encoding='utf-8').read()
old = "        if (!(rows >= 1 && rows <= 12) || !(cols >= 1 && cols <= 12)) return;\n"
assert old in s, 'anchor not found -- read luBayDims and adjust'
open(p, 'w', encoding='utf-8').write(s.replace(old, ''))
PY
cd tests && node baymap_js_test.js; echo "exit=$?"
cd .. && git checkout -- source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
```

Expected: the `blank dimension field` checks FAIL, `exit=1`. With the guard gone, `parseInt('', 10)` is `NaN` and the resize proceeds off a number nobody typed.

- [ ] **Step 4: Prove behaviour 5 kills the fit-test mutant**

```bash
cd "$(git rev-parse --show-toplevel)"
sed -i "s|            if (p\.row < rows \&\& p\.col < cols) keep\.push(p); else drop\.push(p);|            keep.push(p);|" \
    source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
cd tests && node baymap_js_test.js; echo "exit=$?"
cd .. && git checkout -- source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
```

Expected: `FAIL  resize: the drive that no longer fits is back in the tray`.

- [ ] **Step 5: Prove behaviour 7 kills the restore mutant**

```bash
cd "$(git rev-parse --show-toplevel)"
sed -i "s|            rf\.value = d\.rows; cf\.value = d\.cols;.*$||" \
    source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
cd tests && node baymap_js_test.js; echo "exit=$?"
cd .. && git checkout -- source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
```

Expected: `FAIL  resize: declining the shrink puts the field back to 3`.

- [ ] **Step 6: Confirm clean and green**

```bash
cd "$(git rev-parse --show-toplevel)"
git status --porcelain source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
bash tests/run.sh 2>&1 | tail -2
```

Expected: nothing from `git status`, `--- all pass ---`.

- [ ] **Step 7: Commit**

```bash
git add tests/baymap_js_test.js
git commit -m "Pin the resize path, including the field that once wiped a map

Clearing a dimension box to retype it read as 1 row, and the debounced save
displaced everything below it. The guard against that is now pinned three
ways: no POST, no displacement, and no confirm prompt -- because a resize
that asks the question has already decided to do something.

Also pins that the dims POST is debounced: it must not be on the wire before
the timers are flushed."
```

---

### Task 4: Destructive actions, the refusal path, and the lock

**Files:**
- Modify: `tests/baymap_js_test.js` (add to `main()`)

**Interfaces:**
- Consumes: `opened()`, `ev()`, `BAY()`, `LOCKED_BAY()`, `check()`, `answers`, `alerts`, `calls`, `setReply()`, `grid()`, `cellAt()`, `posts()` from Task 1.
- Produces: nothing new.

Background: `window.luBayClear` alerts and returns when nothing is placed; otherwise it confirms, naming the count, then posts `clear`. `luBayPost` on a `{ok:false}` reply alerts and calls `luBayReload()` (a GET to `?type=baymap`) WITHOUT invoking the done callback. When `locked` is true, `luBayPaint` assigns no cell `onclick` and no `dataset`, and the delegated handlers return early.

- [ ] **Step 1: Add the four behaviours inside `main()`**

```js
    /* ── 8. Clear with nothing placed -> say so, do not ask, do not post ─────
       A confirm whose answer changes nothing is a question not worth asking. */
    {
        const h = await opened(Object.assign(BAY(), {placed: []}));
        h.answers.confirm.push(true);
        h.ctx.luBayClear();
        await h.settle();
        check('clear: an already-empty map posts nothing', h.posts().length === 0);
        check('clear: an already-empty map says so', h.alerts.some(a => /already empty/i.test(a)));
        check('clear: an already-empty map does not ask for confirmation',
            h.answers.confirm.length === 1);
    }

    /* ── 9. Clear declined -> nothing on the wire ────────────────────────────
       The confirm names the COUNT, because the number is what makes a person
       stop: a map of 24 bays was built by walking to the rack. */
    {
        const h = await opened();
        h.answers.confirm.push(false);
        h.ctx.luBayClear();
        await h.settle();
        check('clear: declining the confirmation posts nothing', h.posts().length === 0);
        check('clear: the confirmation names how many drives are placed',
            h.alerts.some(a => /\b2\b/.test(a) && /placed/i.test(a)));
    }

    /* ── 10. A refused write must be undone on screen, not just reported ─────
       The grid paints optimistically. Without the resync the map keeps showing
       a move the server rejected -- the one state the person cannot notice. */
    {
        const h = await opened();
        h.setReply({ok: false, error: 'bay map is locked'});
        const before = h.calls.filter(c => c.url.includes('type=baymap')).length;
        const cell = h.cellAt(0, 1);
        h.grid().ondblclick(ev(cell));
        await h.settle();
        const after = h.calls.filter(c => c.url.includes('type=baymap')).length;
        check('refused: a rejected write is reported', h.alerts.some(a => /locked/.test(a)));
        check('refused: a rejected write triggers a reload', after > before);
    }

    /* ── 11. Locked: no gesture writes ───────────────────────────────────────
       Locked cells get no onclick and no dataset at all, so there is nothing
       to click -- and the delegated handlers return early regardless. Both are
       asserted: the absent wiring, and the silent grid handler. */
    {
        const h = await opened(LOCKED_BAY());
        const anyCell = h.grid().children[0];
        check('locked: cells carry no click handler', !anyCell.onclick);
        check('locked: cells carry no bay key to drag', anyCell.dataset.bayKey === undefined);
        h.grid().ondblclick(ev(anyCell));
        await h.settle();
        check('locked: the delegated double-click writes nothing', h.posts().length === 0);
    }
```

- [ ] **Step 2: Run and confirm every check passes**

```bash
cd tests && node baymap_js_test.js
```

- [ ] **Step 3: Prove behaviour 8 kills its mutant**

```bash
cd "$(git rev-parse --show-toplevel)"
sed -i "s|        if (!n) { alert('The bay map is already empty\.'); return; }||" \
    source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
cd tests && node baymap_js_test.js; echo "exit=$?"
cd .. && git checkout -- source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
```

Expected: the `already-empty` checks FAIL.

- [ ] **Step 4: Prove behaviour 10 kills the silent-drift mutant**

```bash
cd "$(git rev-parse --show-toplevel)"
python - <<'PY'
p = 'source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js'
s = open(p, encoding='utf-8').read()
old = "                if (!j.ok) { alert(j.error || 'Bay map not saved.'); luBayReload(); return; }\n"
assert old in s, 'anchor not found -- read luBayPost and adjust'
open(p, 'w', encoding='utf-8').write(s.replace(old, ''))
PY
cd tests && node baymap_js_test.js; echo "exit=$?"
cd .. && git checkout -- source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
```

Expected: both `refused:` checks FAIL.

- [ ] **Step 5: Prove behaviour 11 kills the lock mutant**

Target the guard on the delegated dblclick specifically — it is the first line of `grid.ondblclick` in `luBayPaint`:

```bash
cd "$(git rev-parse --show-toplevel)"
python - <<'PY'
p = 'source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js'
s = open(p, encoding='utf-8').read()
old = """        grid.ondblclick = function (e) {
            if (luBay.data.locked) return;
"""
new = """        grid.ondblclick = function (e) {
"""
assert old in s, 'anchor not found -- read luBayPaint and adjust'
open(p, 'w', encoding='utf-8').write(s.replace(old, new))
PY
cd tests && node baymap_js_test.js; echo "exit=$?"
cd .. && git checkout -- source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
```

Expected: `FAIL  locked: the delegated double-click writes nothing`.

If this mutant does NOT kill the check, work out why before proceeding: the likely cause is that the locked cell carries no `data-bay-key`, so `closest('.lu-bay-cell[data-bay-key]')` returns null and the handler bails for a different reason. That is a genuine second guard, and the check should then be strengthened to also drive a gesture that reaches past it — or the finding recorded and the check re-scoped. Do not weaken the mutant.

- [ ] **Step 6: Full suite, clean tree, and a count of what is now pinned**

```bash
cd "$(git rev-parse --show-toplevel)"
git status --porcelain source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js
bash tests/run.sh 2>&1 | tail -2
cd tests && node baymap_js_test.js | grep -c '^PASS'
```

Expected: nothing from `git status`, `--- all pass ---`, and a PASS count of at least 20.

- [ ] **Step 7: Commit**

```bash
git add tests/baymap_js_test.js
git commit -m "Pin the destructive actions, the refusal, and the lock

The refusal check is the subtle one. The grid paints optimistically, so a
write the server rejects leaves the screen showing a move that did not
happen -- the single state a person has no way to notice. Deleting the
!j.ok branch fails it.

The lock checks assert the absence of wiring as well as the guard: a locked
cell gets no onclick and no dataset, so there is nothing to click even
before the handler decides."
```

---

## Self-review

**Spec coverage.** All eleven behaviours from the spec's table are assigned: 1 → Task 1; 2, 3, 4 → Task 2; 5, 6, 7 → Task 3; 8, 9, 10, 11 → Task 4. The shim contract, the fixture shape, the `run.sh` wiring, and the four global constraints are covered in Task 1 or the header.

**Deviations from the spec, recorded deliberately:**

- The spec assigned the `!col` mutant to behaviour 3 and the row/col swap to behaviour 1. Both are proven by behaviour 1 here, because behaviour 3 (an unassign) posts identically under `!col` and cannot kill it. Behaviour 3 instead kills the handler-placement mutant — the 2026.08.05 bug, and the one worth pinning there.
- Behaviour 1 targets `(1, 0)` specifically rather than any empty bay, for the two reasons in the Fixture section.
- Several behaviours became more than one `check()` — displacement is three, the blank field three. The spec's table counts behaviours, not assertions.

**Placeholder scan:** none. Every step carries its command or its code.

**Naming consistency:** `boot`, `opened`, `ev`, `check`, `BAY`, `LOCKED_BAY`, `cellAt`, `chipAt`, `grid`, `tray`, `posts`, `lastPost`, `settle`, `flushTimers`, `setReply`, `setPayload` are defined in Task 1 and used with those exact names in Tasks 2–4. `baymap_js_fail` is the shell variable in both places it appears.

**Known risk carried forward:** the shim is the weak point, and a stub wrong in the same direction as a bug hides it. Every assertion is therefore gated on a named mutant being shown to fail. An implementer who cannot make a mutant fail must replace the assertion, not weaken the mutant.
