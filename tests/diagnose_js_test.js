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
const esInstances = [];
/* Mutable so a test can make the NEXT diagnose.php POST come back refused
 * (e.g. the per-disk lock rejecting a double-click) without a second sandbox. */
let fetchResponse = { ok: true, job: 'sdb-1', disk: 'sdb' };
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
        return Promise.resolve({ json: () => Promise.resolve(fetchResponse) });
    },
    EventSource: function (url) {
        this.url = url;
        this.closed = false;
        this.close = () => { this.closed = true; };
        this._listeners = {};
        this.addEventListener = (type, fn) => { this._listeners[type] = fn; };
        esInstances.push(this);
    },
    setTimeout: (fn) => fn && 0,
    luTab: () => {},
};
sandbox.window = sandbox;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(SRC, 'utf8'), sandbox);

// The bug Block E found: nothing on page load asked whether a job was
// already running, so a reload always showed "No job running".
check('loading the page asks diagnose.php for the job list once',
      fetches.length === 1 && fetches[0].url.includes('action=list'));

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

/* ── starting a job, EventSource lifecycle, and the pause fix ───────────── */
/* Async because openStream() -- and the EventSource it opens -- only runs
 * after luDiagnose's fetch().then() chain resolves. */
async function tail() {
    fetches.length = 0;
    esInstances.length = 0;
    await sandbox.luDiagnose('sdb');
    check('starting a job posts to diagnose.php',
          fetches.length === 2 && fetches[0].url.includes('diagnose.php'));
    check('starting a job also refreshes the sidebar drive list',
          fetches[1] && fetches[1].url.includes('action=drivelist'));
    check('and names the disk, not a /dev path',
          fetches[0].body.includes('disk=sdb') && !fetches[0].body.includes('%2Fdev'));
    check('and sends Unraid\'s CSRF token',  fetches[0].body.includes('csrf_token=TOKEN'));
    check('starting a job opens one EventSource',
          esInstances.length === 1 && esInstances[0].closed === false);

    // Critical: a second start must close the first job's EventSource, not
    // orphan it -- an orphan keeps writing the old disk's events into
    // whatever state the new job set up, and holds a php-fpm worker open.
    await sandbox.luDiagnose('sdb2');
    check('starting a second job closes the previous EventSource',
          esInstances[0].closed === true);
    check('and opens a fresh EventSource for the new job',
          esInstances.length === 2 && esInstances[1].closed === false);

    // Important: pause freezes the VIEW, not data collection. An event that
    // arrives while paused must still be applied to state (so resuming can
    // catch up) but must not redraw until resumed.
    sandbox.luDiagPause();
    esInstances[1].onmessage({
        data: JSON.stringify({ t: 'chunk', lba: 640, n: 64, ms: 5, ok: true }),
        lastEventId: '77',
    });
    check('an event arriving while paused does not redraw the map',
          els.get('diag-map')._html === '');
    sandbox.luDiagPause();
    check('resuming redraws the map with the event that arrived while paused',
          els.get('diag-map')._html.includes('lu-diag-cell'));

    // Blocking (round 2): a refused start must not tear down the CURRENT,
    // still-running job's view. Job A starts, gets rendered content, then a
    // second start (e.g. the per-disk lock rejecting a double-click) is
    // refused -- job A's stream and view must survive untouched.
    fetches.length = 0;
    esInstances.length = 0;
    fetchResponse = { ok: true, job: 'sdc-1', disk: 'sdc' };
    await sandbox.luDiagnose('sdc');
    const jobA = esInstances[0];
    jobA.onmessage({
        data: JSON.stringify({ t: 'chunk', lba: 0, n: 64, ms: 5, ok: true }),
        lastEventId: '1',
    });
    const mapBefore = els.get('diag-map')._html;
    const streamBefore = els.get('diag-stream')._html;
    check('job A has rendered content before the refused second start',
          mapBefore.includes('lu-diag-cell') && streamBefore.length > 0);

    fetchResponse = { error: 'disk sdc already has a job running' };
    await sandbox.luDiagnose('sdc');
    check('a refused second start does not close job A\'s EventSource',
          jobA.closed === false);
    check('a refused second start does not open a new EventSource',
          esInstances.length === 1);
    check('a refused second start leaves the map untouched',
          els.get('diag-map')._html === mapBefore);
    check('a refused second start appends to the log rather than wiping it',
          els.get('diag-stream')._html.startsWith(streamBefore)
          && els.get('diag-stream')._html.includes('refused'));

    // Regression guard: openStream() registers its 'end' handler via
    // addEventListener, not onmessage. A stub missing addEventListener lets
    // that call throw, swallowed by luDiagnose's own .catch -- every
    // "successful start" check above would still report PASS while nothing
    // past openStream() ever actually ran and the 'end' handler (clears the
    // dot, disables Pause/Cancel) went unexercised by the whole suite.
    fetches.length = 0;
    esInstances.length = 0;
    fetchResponse = { ok: true, job: 'sdd-1', disk: 'sdd' };
    await sandbox.luDiagnose('sdd');
    check('opening a stream registers an end handler via addEventListener',
          typeof esInstances[0]._listeners.end === 'function');
    esInstances[0]._listeners.end();
    check('the end event closes the stream, clears the running dot, and disables controls',
          esInstances[0].closed === true
          && !els.get('diag-dot').classList.contains('running')
          && els.get('diag-pause').disabled === true
          && els.get('diag-cancel').disabled === true);

    /* ── reattach after a reload (Task 16 Block E fix round) ─────────── */
    const idleHead = '<span class="lu-muted">No job running.</span>';
    const resumeCase = async (jobs, pageJob) => {
        fetches.length = 0;
        esInstances.length = 0;
        sandbox.luDiagJob = pageJob || '';
        els.get('diag-head').innerHTML = idleHead;
        els.get('diag-dot').classList.remove('running');
        els.get('diag-cancel').disabled = true;
        fetchResponse = { jobs };
        await sandbox.luDiagResume();
    };

    await resumeCase([{ job: 'sdq-300', disk: 'sdq', mtime: 300, running: false }]);
    check('resume asks diagnose.php for the job list',
          fetches.length === 1 && fetches[0].url.includes('action=list'));
    check('no running job: no EventSource is opened', esInstances.length === 0);
    check('no running job: the idle header is left alone',
          els.get('diag-head')._html === idleHead);
    check('no running job: nothing is attached', sandbox.luDiagJob === '');

    // The newest job is a FINISHED one: an implementation that takes
    // jobs[0] instead of the running one attaches to the wrong job.
    await resumeCase([{ job: 'sdr-400', disk: 'sdr', mtime: 400, running: false },
                      { job: 'sdq-300', disk: 'sdq', mtime: 300, running: true }]);
    check('one running job: the view attaches to it, not to the newest job',
          sandbox.luDiagJob === 'sdq-300');
    check('one running job: one EventSource, for that job, from offset 0',
          esInstances.length === 1 && esInstances[0].url.includes('job=sdq-300')
          && esInstances[0].url.includes('offset=0'));
    check('one running job: the header names its disk',
          els.get('diag-head')._html.includes('/dev/sdq'));
    check('one running job: the dot runs and Cancel is live',
          els.get('diag-dot').classList.contains('running')
          && els.get('diag-cancel').disabled === false);
    esInstances[0].onmessage({
        data: JSON.stringify({ t: 'chunk', lba: 0, n: 64, ms: 5, ok: true }),
        lastEventId: '60',
    });
    check('one running job: replayed events redraw the map',
          els.get('diag-map')._html.includes('lu-diag-cell'));

    await resumeCase([{ job: 'sds-500', disk: 'sds', mtime: 500, running: true },
                      { job: 'sdq-300', disk: 'sdq', mtime: 300, running: true }]);
    check('two running jobs: no EventSource is opened', esInstances.length === 0);
    check('two running jobs: nothing is attached, so Cancel has no target',
          sandbox.luDiagJob === '' && els.get('diag-cancel').disabled === true);
    check('two running jobs: the header names both disks',
          els.get('diag-head')._html.includes('/dev/sds')
          && els.get('diag-head')._html.includes('/dev/sdq'));

    await resumeCase([{ job: 'sdq-300', disk: 'sdq', mtime: 300, running: true }], 'sdz-1');
    check('a job the page already holds is not replaced by a resume',
          sandbox.luDiagJob === 'sdz-1' && esInstances.length === 0);

    /* The check above sets luDiagJob BEFORE calling luDiagResume, so it
     * cannot tell whether the "if (luDiagJob) return;" guard sits before or
     * after the fetch -- a mutant moving it earlier still passes it. These
     * hold the list fetch open with a deferred promise so a page-owned job
     * can land WHILE it is still in flight, which only the correct
     * (post-fetch) placement of the guard survives. */
    const deferredResumeRace = async (landJob) => {
        fetches.length = 0;
        esInstances.length = 0;
        sandbox.luDiagJob = '';
        els.get('diag-head').innerHTML = idleHead;
        els.get('diag-dot').classList.remove('running');
        els.get('diag-cancel').disabled = true;

        const originalFetch = sandbox.fetch;
        let heldResolve = null;
        sandbox.fetch = (url, opts) => {
            fetches.push({ url, body: opts && opts.body ? String(opts.body) : '' });
            // Only the FIRST call (luDiagResume's own list request) is held
            // open; anything the landed job triggers (a verdict fetch, a
            // confirmed start) goes through the real mock so it settles.
            if (heldResolve) return originalFetch(url, opts);
            return new Promise((resolve) => { heldResolve = resolve; });
        };

        const resumePromise = sandbox.luDiagResume();
        await landJob();
        sandbox.fetch = originalFetch;
        heldResolve({ json: () => Promise.resolve(
            { jobs: [{ job: 'sdq-300', disk: 'sdq', mtime: 300, running: true }] }) });
        await resumePromise;
    };

    await deferredResumeRace(() => { sandbox.luDiagOpen('sdr-1'); return Promise.resolve(); });
    check('a reopened verdict landing while resume\'s list fetch is in flight is not overwritten',
          sandbox.luDiagJob === 'sdr-1' && esInstances.length === 0);

    fetchResponse = { ok: true, job: 'sdt-1', disk: 'sdt' };
    await deferredResumeRace(() => sandbox.luDiagnose('sdt'));
    check('a confirmed start landing while resume\'s list fetch is in flight is not overwritten',
          sandbox.luDiagJob === 'sdt-1' && esInstances.length === 1);
}

tail().then(() => {
    console.log();
    if (fails === 0) { console.log('diagnose_js: all pass'); process.exit(0); }
    console.log('diagnose_js: FAILURES'); process.exit(1);
}).catch((e) => {
    console.error(e);
    console.log('diagnose_js: FAILURES');
    process.exit(1);
});
