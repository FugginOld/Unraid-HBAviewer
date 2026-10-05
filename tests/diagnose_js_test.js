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
let focused = null;
function mkEl(id) {
    const el = { id, style: {}, textContent: '', value: '', checked: false,
                 hidden: false, disabled: false, _html: '', children: [],
                 classList: { _s: new Set(),
                              add(...c) { c.forEach(x => this._s.add(x)); },
                              remove(...c) { c.forEach(x => this._s.delete(x)); },
                              contains(c) { return this._s.has(c); } },
                 setAttribute() {}, scrollTo() {}, focus() { focused = id; },
                 appendChild(c) { el.children.push(c); } };
    Object.defineProperty(el, 'innerHTML', {
        get() { return el._html; }, set(v) { el._html = String(v); el.children = []; },
    });
    return el;
}
const ids = ['diag-live','diag-verdict','diag-repair','diag-head','diag-dot','diag-pause','diag-cancel',
             'diag-pills','diag-progress','diag-hotzone','diag-map','diag-hist',
             'diag-counters','diag-interp','diag-stream','diag-newjob','diag-standby',
             'diag-drives','diag-verdict-body','diag-repair-body','diag-repair-back','diag-verdict-back'];
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
check('uncorrected writes moving points at MEDIA',
      /MEDIA/.test(I({ wuncorr: 1, grown: 0, uncorr: 0, disp: 0, invdw: 0, loss: 0 })));

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
// The lifetime count is the point (why Unraid disabled the disk), so it is
// listed even when this run did not move it.
A({ t: 'counter', key: 'wuncorr', before: 3, after: 3 });
check('the uncorrected-write counter is listed with its count',
      /Uncorrected writes: 3 \(\+0\)/.test(els.get('diag-counters')._html));
A({ t: 'verdict', disk: 'sdb', v: 'TRANSPORT', why: 'verify clean, read failed x3' });
check('a verdict event switches to the verdict screen',
      els.get('diag-verdict').hidden === false && els.get('diag-live').hidden === true);
// STANDBY is not a fault -- the drive was simply left asleep -- so it must
// not log at the same 'crit' severity as an actual TRANSPORT/MEDIA verdict.
A({ t: 'verdict', disk: 'sdd', v: 'STANDBY', why: 'left asleep -- Diagnose never spins a disk up' });
check('a STANDBY verdict logs at warn severity, not crit',
      els.get('diag-stream')._html.includes('lu-warn'));
// Likewise POWER_UNKNOWN: nothing was tested. Cleared first, so an earlier
// warn line cannot satisfy the check.
els.get('diag-stream')._html = '';
A({ t: 'verdict', disk: 'sdx', v: 'POWER_UNKNOWN', why: 'power state unknown' });
check('a POWER_UNKNOWN verdict logs at warn severity, not crit',
      els.get('diag-stream')._html.includes('lu-warn')
      && !els.get('diag-stream')._html.includes('lu-crit'));

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

    // A run can finish having written no verdict: the engine disables triage
    // while the mover runs and exits clean after the preflight and baseline
    // phases. The screen used to sit on the progress view forever; at 'end' it
    // must now say the run ended without one. A real verdict must not say it.
    fetches.length = 0; esInstances.length = 0;
    fetchResponse = { ok: true, job: 'sde-1', disk: 'sde' };
    await sandbox.luDiagnose('sde');
    sandbox.luDiagApply({ t: 'phase', disk: '-', phase: 'preflight', lba_total: 0 });
    esInstances[0]._listeners.end();
    check('a run that ends with no verdict says so in the log',
          els.get('diag-stream')._html.includes('without a verdict'));
    check('and in the header, so it is not mistaken for a running job',
          els.get('diag-head').innerHTML.includes('without a verdict'));

    fetches.length = 0; esInstances.length = 0;
    fetchResponse = { ok: true, job: 'sdf-1', disk: 'sdf' };
    await sandbox.luDiagnose('sdf');
    fetchResponse = { ok: true };
    sandbox.luDiagApply({ t: 'verdict', disk: 'sdf', v: 'CLEAN', why: 'nothing reproduced' });
    esInstances[0]._listeners.end();
    check('a run that did write a verdict does not claim it did not',
          !els.get('diag-stream')._html.includes('without a verdict')
          && !els.get('diag-head').innerHTML.includes('without a verdict'));

    // A cancel also ends with no verdict, and the user asked for it: no alarm.
    fetches.length = 0; esInstances.length = 0;
    fetchResponse = { ok: true, job: 'sdg-1', disk: 'sdg' };
    await sandbox.luDiagnose('sdg');
    fetchResponse = { ok: true };
    sandbox.luDiagCancel(); await new Promise(r => setTimeout(r, 0)); await new Promise(r => setTimeout(r, 0));
    esInstances[0]._listeners.end();
    check('a cancelled run does not claim a missing verdict as a failure',
          !els.get('diag-stream')._html.includes('without a verdict'));

    // luDiagOpen repoints luDiagJob at an old verdict without detaching the live
    // stream, so the header must name the run that actually ended.
    fetches.length = 0; esInstances.length = 0;
    fetchResponse = { ok: true, job: 'sdh-1', disk: 'sdh' };
    await sandbox.luDiagnose('sdh');
    sandbox.luDiagJob = 'sdz-9';
    esInstances[0]._listeners.end();
    check('the no-verdict header names the run that ended, not whichever job the page now points at',
          els.get('diag-head').innerHTML.includes('/dev/sdh')
          && !els.get('diag-head').innerHTML.includes('/dev/sdz'));

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

    /* ── the Repair screen (Phase 2a) ─────────────────────────────────── */
    fetches.length = 0;
    sandbox.luDiagJob = 'sdb-9';
    els.get('diag-repair').hidden = true;
    const fetchBeforeRepair = sandbox.fetch;
    sandbox.fetch = (url, opts) => {
        fetches.push({ url, body: opts && opts.body ? String(opts.body) : '' });
        return Promise.resolve({ ok: true, text: () => Promise.resolve('<p>REPAIR sdc</p>') });
    };
    await sandbox.luDiagRepair('sdc');
    sandbox.fetch = fetchBeforeRepair;
    check('Repair fetches the disk-scoped repair action once',
          fetches.length === 1 && fetches[0].url.includes('action=repair')
          && fetches[0].url.includes('disk=sdc'));
    check('and it is a GET: nothing is posted', fetches[0].body === '');
    check('Repair shows only the repair screen',
          els.get('diag-repair').hidden === false && els.get('diag-verdict').hidden === true
          && els.get('diag-live').hidden === true);
    check('the fragment lands in #diag-repair-body', els.get('diag-repair-body')._html.includes('REPAIR sdc'));
    check('Repair leaves the page\'s job alone', sandbox.luDiagJob === 'sdb-9');
    check('Repair moves focus to its Back to verdict button', focused === 'diag-repair-back');
    sandbox.luDiagRepairBack();
    check('Back to verdict moves focus to the verdict screen', focused === 'diag-verdict-back');
    check('Back to verdict hides the repair screen again',
          els.get('diag-repair').hidden === true && els.get('diag-verdict').hidden === false);

    /* A failed load keeps the screen (and its static Back), shows the error in
       the body only, and never leaves a previous disk's fragment; the disk is
       URL-encoded. */
    fetches.length = 0;
    els.get('diag-repair-body')._html = '<p>STALE sdc</p>';
    sandbox.fetch = (url) => {
        fetches.push({ url });
        return Promise.resolve({ ok: false, text: () => Promise.resolve('Invalid disk.') });
    };
    await sandbox.luDiagRepair('a&b');
    sandbox.fetch = fetchBeforeRepair;
    check('the disk is URL-encoded in the repair fetch', fetches[0].url.includes('disk=a%26b'));
    check('a non-OK repair response shows its error in the body',
          els.get('diag-repair-body').textContent === 'Invalid disk.');
    check('and clears the previous disk\'s fragment', !String(els.get('diag-repair-body')._html).includes('STALE'));
    check('and leaves the screen shown', els.get('diag-repair').hidden === false);

    /* The Verdict screen's way back lives in its static markup; the verdict
       fetch must write below it, never over it. The stub fetch has no text(),
       so this lands on the error path -- which must spare the button too. */
    els.get('diag-verdict')._html = 'BACK-BUTTON';
    els.get('diag-verdict').textContent = '';
    await sandbox.luDiagRenderVerdict();
    check('the verdict fetch writes into #diag-verdict-body, not over the back button',
          els.get('diag-verdict')._html === 'BACK-BUTTON'
          && els.get('diag-verdict').textContent === ''
          && els.get('diag-verdict-body').textContent.includes('Could not load'));
    // The success path is the one that overwrote the screen on Golem.
    const stubFetch = sandbox.fetch;
    sandbox.fetch = () => Promise.resolve({ text: () => Promise.resolve('<p>V</p>') });
    await sandbox.luDiagRenderVerdict();
    sandbox.fetch = stubFetch;
    check('a loaded verdict lands in #diag-verdict-body and spares the back button',
          els.get('diag-verdict')._html === 'BACK-BUTTON'
          && els.get('diag-verdict-body')._html === '<p>V</p>');
    /* Back to drives: the live screen, a FRESH drive list (badges changed
       since the verdict landed), and a header that no longer claims a
       finished job is still running. */
    sandbox.luDiagJob = 'sdd-1790737945';
    els.get('diag-head')._html = 'Diagnosing <code>/dev/sdd</code>';
    sandbox.luDiagApply({ t: 'verdict', disk: 'manual', v: 'CLEAN', why: 'x' });
    check('a verdict turns the header into Last run',
          els.get('diag-head')._html.includes('Last run')
          && els.get('diag-head')._html.includes('/dev/sdd')
          && !els.get('diag-head')._html.includes('Diagnosing'));
    fetches.length = 0;
    sandbox.luDiagBack();
    check('luDiagBack brings the drive list back from the verdict screen',
          els.get('diag-live').hidden === false && els.get('diag-verdict').hidden === true);
    check('luDiagBack refreshes the drive list',
          fetches.some(f => f.url.includes('action=drivelist')));
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
