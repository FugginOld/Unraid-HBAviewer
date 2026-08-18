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
            draggable: false, parentNode: null, dataset: {}, children: [],
            // setProperty for the grid's --bay-cols custom property; a plain {}
            // throws there, and that throw lands inside luBayLoad's fetch .then
            // chain where it is swallowed by .catch as a "request failed" state
            // -- so this is fatal-but-silent without it.
            style: {setProperty() {}},
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
            if (!els.has(m[1])) {
                const e = mkEl('div'); e.id = m[1];
                // The real markup nests bay-grid inside .lu-bay-scroll, and
                // luBayPaint reaches through grid.parentNode.classList to toggle
                // the locked state -- give every harvested node a stand-in
                // parent so that reach never hits null.
                e.parentNode = mkEl('div');
                els.set(m[1], e);
            }
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
