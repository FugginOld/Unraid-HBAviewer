/* Runtime checks for diagnose_view.js. Three things here cannot be asserted
 * from the source text, and all three are the ones that would be wrong
 * silently:
 *
 *   - the latency bucket boundaries. Off by one bucket and the surface map is
 *     a picture of a different disk; every source assertion still passes.
 *   - the hot-zone detector. A run detector that reports every single slow
 *     chunk as a zone makes the callout useless, and one that needs the whole
 *     map to be slow never fires.
 *   - the media-vs-path interpretation. It is the one sentence that tells the
 *     user whether to buy a drive or a cable.
 *
 *   node tests/diagnose_js_test.js   ->  "diagnose_js: all pass" (exit 0)
 */
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

let fails = 0;
const check = (name, ok) => { console.log((ok ? 'PASS  ' : 'FAIL  ') + name); if (!ok) fails++; };

const SRC = path.join(__dirname,
    '../source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js');

/* ── the smallest DOM this file can run against ─────────────────────────── */
const els = new Map();
function mkEl(id) {
    const el = { id, style: {}, textContent: '', value: '', checked: false,
                 hidden: false, disabled: false, _html: '', children: [],
                 classList: { _s: new Set(),
                              add(...c) { c.forEach(x => this._s.add(x)); },
                              remove(...c) { c.forEach(x => this._s.delete(x)); },
                              contains(c) { return this._s.has(c); } },
                 setAttribute() {}, scrollTo() {},
                 appendChild(c) { el.children.push(c); } };
    Object.defineProperty(el, 'innerHTML', {
        get() { return el._html; }, set(v) { el._html = String(v); el.children = []; },
    });
    return el;
}
const ids = ['diag-live','diag-verdict','diag-head','diag-dot','diag-pause','diag-cancel',
             'diag-pills','diag-progress','diag-hotzone','diag-map','diag-hist',
             'diag-counters','diag-interp','diag-stream','diag-newjob','diag-standby',
             'diag-drives'];
ids.forEach(i => els.set(i, mkEl(i)));

const fetches = [];
const sandbox = {
    console, URLSearchParams,
    window: {},
    luCsrf: 'TOKEN',
    luDiagJob: '',
    document: {
        getElementById: (id) => els.get(id) || null,
        createElement: (t) => mkEl('created-' + t),
        querySelectorAll: () => [],
        querySelector: () => null,
    },
    fetch: (url, opts) => {
        fetches.push({ url, body: opts && opts.body ? String(opts.body) : '' });
        return Promise.resolve({ json: () => Promise.resolve({ ok: true, job: 'sdb-1', disk: 'sdb' }) });
    },
    EventSource: function (url) { this.url = url; this.close = () => {}; },
    setTimeout: (fn) => fn && 0,
    luTab: () => {},
};
sandbox.window = sandbox;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(SRC, 'utf8'), sandbox);

/* ── latency buckets: the boundaries the spec names, exactly ────────────── */
const B = sandbox.luDiagBucket;
check('an unreadable chunk is its own bucket', B(12, false) === 'lu-bbad');
// Boundaries are EXCLUSIVE upper bounds -- "<5 ms", not "<=5 ms". 5 belongs to
// the next bucket up, and getting this backwards shifts the whole map.
check('4 ms is the fastest bucket',   B(4, true)   === 'lu-b5');
check('5 ms is not',                  B(5, true)   === 'lu-b20');
check('19 ms is still the 20 bucket', B(19, true)  === 'lu-b20');
check('20 ms moves up',               B(20, true)  === 'lu-b50');
check('49 ms is the 50 bucket',       B(49, true)  === 'lu-b50');
check('50 ms moves up',               B(50, true)  === 'lu-b150');
check('149 ms is the 150 bucket',     B(149, true) === 'lu-b150');
check('150 ms moves up',              B(150, true) === 'lu-b500');
check('499 ms is the 500 bucket',     B(499, true) === 'lu-b500');
check('500 ms is the slowest bucket', B(500, true) === 'lu-b500p');
check('a huge latency stays in the slowest bucket', B(99999, true) === 'lu-b500p');

/* ── hot zones: a CLUSTER, not a single slow chunk ──────────────────────── */
const HZ = sandbox.luDiagHotZone;
const cell = (lba, ms, ok) => ({ lba, ms, ok });
check('no slow chunks means no zone',
      HZ([cell(0,2,true), cell(64,3,true)], 3) === null);
// One slow chunk on a healthy disk is noise -- a seek, a queued write
// elsewhere, a background scan. Calling it a hot zone makes the callout
// something the user learns to ignore.
check('a single slow chunk is not a zone',
      HZ([cell(0,2,true), cell(64,900,true), cell(128,2,true)], 3) === null);
const z = HZ([cell(0,2,true), cell(64,900,true), cell(128,800,true),
              cell(192,700,true), cell(256,2,true)], 3);
check('three consecutive slow chunks are a zone', z !== null);
check('the zone starts at the first slow chunk',  z && z.from === 64);
check('and ends at the last',                     z && z.to === 192);
check('and reports how many',                     z && z.n === 3);
// A failed chunk counts toward a run even if it returned fast: an unreadable
// block is worse than a slow one, and a run detector keyed only on latency
// would split a zone in half around the one block that failed outright.
const z2 = HZ([cell(0,900,true), cell(64,1,false), cell(128,800,true)], 3);
check('a failed chunk counts toward the run', z2 !== null && z2.n === 3);

/* ── the interpretation sentence ────────────────────────────────────────── */
const I = sandbox.luDiagInterp;
check('media moved, path flat points at MEDIA',
      /MEDIA/.test(I({ grown: 2, uncorr: 1, disp: 0, invdw: 0, loss: 0 })));
check('path moved, media flat points at the path',
      /TRANSPORT|path|cable/i.test(I({ grown: 0, uncorr: 0, disp: 14, invdw: 9, loss: 0 })));
// Both moving is genuinely ambiguous and must read that way. A sentence that
// picks a side here sends someone to buy the wrong part.
check('both moving says so rather than guessing',
      /both/i.test(I({ grown: 2, uncorr: 0, disp: 14, invdw: 0, loss: 0 })));
check('nothing moving says nothing moved',
      /no counter|nothing/i.test(I({ grown: 0, uncorr: 0, disp: 0, invdw: 0, loss: 0 })));

/* ── applying events to the DOM ─────────────────────────────────────────── */
const A = sandbox.luDiagApply;
A({ t: 'phase', disk: 'sdb', phase: 'surface', lba_total: 1000 });
check('a phase event marks the pill active',
      els.get('diag-pills')._html.includes('active'));
A({ t: 'chunk', lba: 0, n: 64, op: 'verify', ms: 900, ok: true });
check('a chunk event adds a cell to the map',
      els.get('diag-map')._html.includes('lu-diag-cell')
      || els.get('diag-map').children.length > 0);
A({ t: 'counter', key: 'disp', before: 210, after: 214 });
check('a counter event renders the delta',
      els.get('diag-counters')._html.includes('214')
      || els.get('diag-counters')._html.includes('+4'));
A({ t: 'verdict', disk: 'sdb', v: 'TRANSPORT', why: 'verify clean, read failed x3' });
check('a verdict event switches to the verdict screen',
      els.get('diag-verdict').hidden === false && els.get('diag-live').hidden === true);

/* ── starting a job ─────────────────────────────────────────────────────── */
fetches.length = 0;
sandbox.luDiagnose('sdb');
check('starting a job posts to diagnose.php',
      fetches.length === 1 && fetches[0].url.includes('diagnose.php'));
check('and names the disk, not a /dev path',
      fetches[0].body.includes('disk=sdb') && !fetches[0].body.includes('%2Fdev'));
check('and sends Unraid\'s CSRF token',  fetches[0].body.includes('csrf_token=TOKEN'));

console.log();
if (fails === 0) { console.log('diagnose_js: all pass'); process.exit(0); }
console.log('diagnose_js: FAILURES'); process.exit(1);
