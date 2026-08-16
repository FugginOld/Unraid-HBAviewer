/* Runtime checks for flash_view.js — the one thing source assertions cannot do.
 *
 * Every other pin on this file is str_contains() over its text, and a mutant
 * that keeps the asserted literals while changing the meaning survives all of
 * them. The one that matters:
 *
 *     var ctl = fesc(String(c.ctl).split(',')[0]), lbl = fesc(ctlLabel(c.ctl));
 *
 * The page then LABELS both controllers of a dual-IOC board and POSTS only the
 * first. One IOC written, one silently left behind, no error anywhere — the
 * exact failure this whole feature exists to prevent, and flash_php_test.php
 * stays green through it. What kills it is running the code and reading what
 * goes on the wire, which is all this file does.
 *
 * No jsdom, no framework: flash_view.js touches a handful of DOM methods and
 * fetch, so the shim below is smaller than the dependency would be. The group
 * used throughout is NON-ADJACENT ([0,2]) on purpose — a card's controller
 * numbers are not its position in the array, and "0,2" cannot be produced by
 * any surviving index arithmetic.
 *
 *   node tests/flash_js_test.js   ->  "flash_js: all pass" (exit 0)
 */
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

let fails = 0;
const check = (name, ok) => { console.log((ok ? 'PASS  ' : 'FAIL  ') + name); if (!ok) fails++; };

const SRC = path.join(__dirname, '../source/usr/local/emhttp/plugins/hbaviewer/flash_view.js');

/* ── the smallest DOM this file can run against ─────────────────────────────── */
const els   = new Map();   // id            -> element stub
const cards = new Map();   // data-ctl      -> data-chip (null when absent)

function mkEl(id) {
    const el = { id, style: {}, textContent: '', value: '', checked: false, _html: '' };
    Object.defineProperty(el, 'innerHTML', {
        get() { return el._html; },
        set(v) { el._html = String(v); harvest(el._html); },
    });
    return el;
}
// Register whatever the markup just created, so the next getElementById finds
// it. Regex rather than a parser: this file emits its own markup and we only
// need the ids and the two data- attributes it keys off.
function harvest(html) {
    for (const m of html.matchAll(/id="([^"]+)"/g)) if (!els.has(m[1])) els.set(m[1], mkEl(m[1]));
    for (const m of html.matchAll(/data-ctl="([^"]*)"(\s+data-chip="([^"]*)")?/g))
        cards.set(m[1], m[3] === undefined ? null : m[3]);
}

const calls = [];          // every fetch: {url, params}
function fakeFetch(url, opts) {
    const body   = opts && opts.body ? String(opts.body) : '';
    const params = new URLSearchParams(body);
    calls.push({ url, params, body });
    const action = params.get('action') || (url.includes('action=status') ? 'status' : '');
    const json = (o) => Promise.resolve({ json: () => Promise.resolve(o), text: () => Promise.resolve(JSON.stringify(o)) });
    if (url.includes('type=overview')) return json(OVERVIEW);
    if (action === 'dropfiles') return json({ dir: '/boot/x', images: [{ name: 'fw.bin', size: 2048 }, { name: 'b.rom', size: 1024 }] });
    if (action === 'toolinfo')  return json({ status: 'found', name: 'sas3flash', path: '/x/sas3flash' });
    if (action === 'listall')   return Promise.resolve({ text: () => Promise.resolve('--- controller /c0 ---\n--- controller /c2 ---\n') });
    if (action === 'flash')     return json({ ok: true, state: 'flashing' });
    if (action === 'status')    return json(STATUS);
    return json({});
}

// One CARD made of two NON-ADJACENT controllers, exactly as lsi_group_cards()
// reports [[0,2],[1]] for [16i@X, 8i@Y, 16i@X].
const OVERVIEW = { controllers: [
    { ctl: '0,2', model: 'SAS3008', firmware: '16.00.12.00', bios: '08.37.00.00', firmware_verdict: {} },
    { ctl: '1',   model: 'SAS2008', firmware: '20.00.07.00', firmware_verdict: {} },
] };
let STATUS = { running: false, exit: 0, done: 'success', log: 'done' };

const ctx = {
    console, URLSearchParams, setTimeout, clearTimeout,
    fetch: fakeFetch,
    alert: () => {},
    flashCsrf: 'tok', flashArrayStopped: true,
    lockNote: '', lockCls: '', lockAttr: '',
    document: {
        getElementById: (id) => els.get(id) || null,
        querySelector: (sel) => {
            const m = /\[data-ctl="([^"]*)"\]/.exec(sel);
            if (!m || !cards.has(m[1])) return null;
            const chip = cards.get(m[1]);
            return { getAttribute: (a) => (a === 'data-chip' ? chip : a === 'data-ctl' ? m[1] : null) };
        },
    },
};
ctx.window = ctx;                 // window.luFlashX = ... must land in scope
ctx.confirm = () => true;
els.set('flash-content', mkEl('flash-content'));

const tick = () => new Promise((r) => setImmediate(r));

/* check() only counts what it REACHES. A mutant that makes the page render the
   wrong ids throws partway through — getElementById returns null and the
   handler dereferences it — and the run then reports whatever small number of
   failures it happened to get to, which reads like a near-miss rather than a
   file that does not work. Anything escaping main() is itself a failure. */
(async function main() {
  try {
    vm.createContext(ctx);
    vm.runInContext(fs.readFileSync(SRC, 'utf8'), ctx);   // luFlashInit() runs on load
    for (let n = 0; n < 8; n++) await tick();             // let the promise chains settle

    const html = els.get('flash-content').innerHTML;

    /* ── the card is the CARD, and it is labelled with every controller ─────── */
    check('a non-adjacent group renders as one card', cards.has('0,2'));
    check('and the lone controller as another',       cards.has('1'));
    check('exactly two cards for two groups',         cards.size === 2);
    check('the card names both of its controllers',   html.includes('Controller /c0, /c2'));
    check('the Verify button names both',             html.includes('Verify /c0, /c2'));
    check('the Flash button names both',              html.includes('Flash /c0, /c2'));
    check('the chip travels on the card',             cards.get('0,2') === 'SAS3008');
    // The DOM ids are keyed by the same string, or the handlers below find nothing.
    check('the per-card elements are keyed by the list', els.has('flash-log-0,2') && els.has('flash-list-0,2'));
    // Step 3's selects arrived from the shared drop listing, keyed the same way.
    check('the drop selects are keyed by the list too', els.has('flash-fw-0,2') && els.has('flash-bios-0,2'));

    /* ── Step 1 asked about THIS card's chip, not some index ────────────────── */
    const tool = calls.filter((c) => c.params.get('action') === 'toolinfo');
    check('one tool lookup per card', tool.length === 2);
    check('the tool lookup carries the card chip', tool.some((c) => c.params.get('chip') === 'SAS3008'));

    /* ── THE MUTANT KILLER: what actually goes on the wire ───────────────────
       Both handlers are driven with the argument EXTRACTED FROM THE MARKUP, not
       with a literal. Passing '0,2' by hand only proves the handler does not
       truncate its own argument; it says nothing about what the page hands it,
       and that is where the whole failure lives. A mutant that leaves data-ctl,
       the ids and both labels correct and truncates only the onclick argument —
       luFlashGo('+ctl.split(',')[0]+') — renders a card reading "Flash /c0, /c2"
       that POSTs ctl=0. Nothing else in this suite can see it. */
    const listArg  = (/onclick="luFlashList\('([^']*)'\)"/.exec(html)  || [])[1];
    const flashArg = (/onclick="luFlashGo\('([^']*)'\)"/.exec(html)    || [])[1];
    check('the Verify button is wired to the whole list', listArg  === '0,2');
    check('the Flash button is wired to the whole list',  flashArg === '0,2');

    ctx.luFlashList(listArg);
    await tick();
    const listall = calls.filter((c) => c.params.get('action') === 'listall').pop();
    check('Verify POSTs the whole controller list', !!listall && listall.params.get('ctl') === '0,2');
    check('and it is percent-encoded, not split',   !!listall && listall.body.includes('ctl=0%2C2'));

    els.get('flash-ack-0,2').checked   = true;
    els.get('flash-confirm-0,2').value = 'FLASH';
    els.get('flash-fw-0,2').value      = 'fw.bin';
    els.get('flash-bios-0,2').value    = '';
    ctx.luFlashGo(flashArg);
    await tick(); await tick();
    const flash = calls.filter((c) => c.params.get('action') === 'flash').pop();
    check('Flash POSTs the whole controller list', !!flash && flash.params.get('ctl') === '0,2');
    check('with the chip and the chosen image',
          !!flash && flash.params.get('chip') === 'SAS3008' && flash.params.get('firmware') === 'fw.bin');
    // The confirmation the operator reads must name every controller about to
    // be written; a dialog naming one of two is a lie about a bricking action.
    let prompt = '';
    ctx.confirm = (m) => { prompt = m; return false; };
    ctx.luFlashGo(flashArg);
    check('the final confirmation names both controllers', prompt.includes('/c0, /c2'));
    ctx.confirm = () => true;

    /* ── a partial flash is its own state, loudly ───────────────────────────── */
    STATUS = { running: false, exit: 7, done: 'partial', log: 'PARTIAL FLASH. …' };
    ctx.luFlashPoll('0,2');
    for (let n = 0; n < 4; n++) await tick();
    const plog = els.get('flash-log-0,2').textContent;
    check('a partial flash gets its own banner',   plog.includes('PARTIAL FLASH'));
    check('it says the firmware now differs',      /DIFFERENT firmware/.test(plog));
    check('it says do not reboot',                 plog.includes('Do NOT reboot'));
    check('it is not the generic error line',      !plog.includes('Flash tool exited with an error'));
    /* The recovery it names has to be one the server will accept. The banner
       used to say "re-run the flash for that controller", and flash.php's
       membership gate refuses any list that is not a whole card — so the single
       instruction given in the loudest state this feature has was rejected on
       arrival. Re-running the whole card rewrites both and is the safe action. */
    check('it sends the operator back at the WHOLE CARD', plog.includes('WHOLE CARD'));
    check('and says that rewrites both controllers',      /BOTH controllers/.test(plog));
    check('and not at the failed controller alone',
          !/flash for (that|the failed) controller/.test(plog));

    STATUS = { running: false, exit: 6, done: 'error', log: 'nothing was written' };
    els.get('flash-log-0,2').textContent = '';
    ctx.luFlashPoll('0,2');
    for (let n = 0; n < 4; n++) await tick();
    const elog = els.get('flash-log-0,2').textContent;
    check('a plain failure keeps the generic line', elog.includes('Flash tool exited with an error'));
    check('and is never called partial',            !elog.includes('PARTIAL FLASH'));

  } catch (e) {
    check('the page ran to completion without throwing — ' + e.message, false);
  }
  console.log(fails === 0 ? 'flash_js: all pass' : `flash_js: ${fails} FAILED`);
  process.exit(fails === 0 ? 0 : 1);
})();
