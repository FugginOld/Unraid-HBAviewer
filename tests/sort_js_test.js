/* Runtime checks for luSort() in hbaviewer.js.
 *
 * Sorting is the first thing on these tabs that REORDERS what the operator is
 * reading. The failure that matters is not "the click did nothing" -- that is
 * visible -- it is an order that looks sorted and is not, because the
 * comparator read "12.733 TB" and "9.095 TB" as strings and put the smaller
 * drive last. Someone scanning for the biggest disk believes the top row.
 *
 * So the assertions here are mostly about ORDER on data shaped like the real
 * tables: capacities with decimals, slot pairs, device names, temperatures.
 *
 * No jsdom -- the repo has none. The table below is the smallest object graph
 * luSort actually touches: parentNode/children up from the button, closest()
 * to the table, tBodies[0], rows[].cells[].textContent, and an appendChild
 * that MOVES a row to the end the way the DOM's does. That last one is the
 * whole mechanism: luSort reorders by re-appending.
 *
 *   node tests/sort_js_test.js   ->  "sort_js: all pass" (exit 0)
 */
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

let fails = 0;
const check = (name, ok) => { console.log((ok ? 'PASS  ' : 'FAIL  ') + name); if (!ok) fails++; };

const SRC = path.join(__dirname, '../source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js');
const CODE = fs.readFileSync(SRC, 'utf8');

/* The plugin's JS is one IIFE that runs on load and reaches for the page, so
   it needs a document to start at all. Everything it looks for is absent, which
   is what the early returns in loadOverview()/luBayPaint() are for -- nothing
   below depends on any of it. */
function boot() {
    const ctx = {
        console, Promise, JSON, Math, Object, Array, String, Number, Intl, URLSearchParams,
        setTimeout: () => 0, clearTimeout: () => {},
        fetch: () => Promise.resolve({text: () => Promise.resolve(''), json: () => Promise.resolve({})}),
        location: {search: ''},
        document: {
            body: {}, createElement: () => ({style: {setProperty() {}}, dataset: {}, classList: {add() {}}}),
            getElementById: () => null, querySelectorAll: () => [], querySelector: () => null,
        },
    };
    ctx.window = ctx;
    vm.createContext(ctx);
    vm.runInContext(CODE, ctx, {filename: 'hbaviewer.js'});
    return ctx;
}

/* headers: ['Device', ...]; rows: [['/dev/sdb', ...], ...] */
function table(headers, rows) {
    const tbl = {};
    const headRow = {children: [], parentNode: {parentNode: tbl}};
    const ths = headers.map(() => {
        const th = {
            // luTable() ships aria-sort="none" on every header, so that is where
            // the browser starts. Starting blank here would measure the first
            // press from a state the page never has.
            _attr: {'aria-sort': 'none'},
            parentNode: headRow,
            getAttribute(k) { return th._attr[k] === undefined ? null : th._attr[k]; },
            setAttribute(k, v) { th._attr[k] = v; },
            // luSort walks up to the table with closest(); the only selector it
            // ever passes is 'table'.
            closest(sel) { if (sel !== 'table') throw new Error('shim: ' + sel); return tbl; },
        };
        const btn = {parentNode: th};
        th.button = btn;
        headRow.children.push(th);
        return th;
    });
    const body = {
        rows: rows.map(cols => ({cells: cols.map(t => ({textContent: t}))})),
        // The DOM moves an existing child rather than duplicating it. luSort
        // depends on exactly that: it re-appends every row in the new order.
        // A shim that pushed blindly would grow the table on every sort and
        // still report the right first row, so this has to move.
        appendChild(r) {
            const at = body.rows.indexOf(r);
            if (at >= 0) body.rows.splice(at, 1);
            body.rows.push(r);
            return r;
        },
    };
    tbl.tBodies = [body];
    tbl.body = body;
    tbl.ths = ths;
    tbl.col = (i) => body.rows.map(r => (r.cells[i] ? r.cells[i].textContent : null));
    return tbl;
}

const ctx = boot();

/* ── 1. Capacities sort by size, not by leading character ──────────────────
   The reason localeCompare gets {numeric: true}. As plain strings "12.733 TB"
   precedes "9.095 TB", which is the wrong answer to "which is my biggest
   drive" -- and a plausible-looking one. */
{
    const t = table(['Device', 'Size'], [
        ['/dev/sdb', '12.733 TB'],
        ['/dev/sdc', '9.095 TB'],
        ['/dev/sdd', '25.466 TB'],
        ['/dev/sde', '7.277 TB'],
    ]);
    ctx.luSort(t.ths[1].button);
    check('capacities ascend by real size',
        JSON.stringify(t.col(1)) === JSON.stringify(['7.277 TB', '9.095 TB', '12.733 TB', '25.466 TB']));
    ctx.luSort(t.ths[1].button);
    check('a second press reverses them',
        JSON.stringify(t.col(1)) === JSON.stringify(['25.466 TB', '12.733 TB', '9.095 TB', '7.277 TB']));
    check('the table did not grow while being sorted', t.body.rows.length === 4);
    // The row is what carries the drive; sorting a column must bring the rest
    // of its row with it. A comparator that sorted the CELLS would pass every
    // assertion above and scramble every table in the plugin.
    check('each row keeps its own device', t.col(0)[0] === '/dev/sdd' && t.col(0)[3] === '/dev/sde');
}

/* ── 2. aria-sort says what the arrow says ─────────────────────────────────
   The CSS draws its arrow from this attribute, so a wrong value is both a
   wrong announcement and a wrong arrow. */
{
    const t = table(['Device', 'Temp'], [['/dev/sdb', '38'], ['/dev/sdc', '31']]);
    // That the header ARRIVES as aria-sort="none" is the server's half and is
    // pinned in ajax_render_test.php; asserting it against this shim would only
    // test the shim.
    ctx.luSort(t.ths[1].button);
    check('the sorted column says ascending', t.ths[1].getAttribute('aria-sort') === 'ascending');
    ctx.luSort(t.ths[1].button);
    check('pressing it again says descending', t.ths[1].getAttribute('aria-sort') === 'descending');
    // Two columns claiming to be the sort would draw two arrows and announce
    // two orders, only one of which is true.
    ctx.luSort(t.ths[0].button);
    check('sorting another column clears the first', t.ths[1].getAttribute('aria-sort') === 'none');
    check('and claims the sort itself', t.ths[0].getAttribute('aria-sort') === 'ascending');
}

/* ── 3. Slot pairs and device names ────────────────────────────────────────
   Encl:Slot is "0/2", "0/10" -- digit-run collation is what puts 2 before 10.
   Both are covered by the same comparator, which is the point of choosing it. */
{
    const t = table(['Slot'], [['0/10'], ['0/2'], ['1/1'], ['0/1']]);
    ctx.luSort(t.ths[0].button);
    check('slot pairs ascend numerically within the enclosure',
        JSON.stringify(t.col(0)) === JSON.stringify(['0/1', '0/2', '0/10', '1/1']));
}
{
    const t = table(['Device'], [['/dev/sdc'], ['/dev/sdaa'], ['/dev/sdb']]);
    ctx.luSort(t.ths[0].button);
    check('device names ascend alphabetically',
        JSON.stringify(t.col(0)) === JSON.stringify(['/dev/sdaa', '/dev/sdb', '/dev/sdc']));
}

/* ── 4. A row that does not have the column ────────────────────────────────
   events.php renders a colspan'd "No log entries." line into the same tbody.
   It cannot be compared, and dropping it -- which is what re-appending only
   the sorted rows would do -- would delete the only thing on screen telling
   the operator the log is empty rather than broken. */
{
    const t = table(['Seq', 'Code'], [['3', 'C0'], ['1', 'A1'], ['2', 'B2']]);
    t.body.rows.push({cells: [{textContent: 'No further entries.'}]});
    ctx.luSort(t.ths[1].button);
    check('the uncomparable row survives the sort', t.body.rows.length === 4);
    check('and is left at the end', t.body.rows[3].cells[0].textContent === 'No further entries.');
    check('while the real rows sorted', JSON.stringify(t.col(1).slice(0, 3))
        === JSON.stringify(['A1', 'B2', 'C0']));
}

console.log(fails ? 'sort_js: FAILURES' : 'sort_js: all pass');
process.exit(fails ? 1 : 0);
