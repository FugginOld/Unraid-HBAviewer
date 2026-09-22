/* HBAviewer Diagnose screens — Live Job and Verdict.
 *
 * One IIFE, no modules, no build step, the same shape as flash_view.js. The
 * globals it reads (luCsrf, luDiagJob) are declared in hbaviewer.php's inline
 * <script> ABOVE this file's <script src>; that split is load-bearing and is
 * the whole reason there is no templating step here.
 *
 * The event file is the source of truth. This file holds a byte offset and
 * nothing else durable: it buys a transparent reconnect within one page
 * life, not a resume across a reload. The browser's automatic EventSource
 * retry replays via Last-Event-ID, which diagnose_stream.php prefers over
 * the URL param, so a dropped connection picks up where it left off without
 * this file tracking anything the browser doesn't already know.
 */
'use strict';
(function () {

    function fesc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function el(id) { return document.getElementById(id); }

    /* ── pure: the latency buckets ───────────────────────────────────────── */
    /* EXCLUSIVE upper bounds, matching how the spec writes them ("<5 ms").
       An unreadable chunk is not the slowest bucket -- it is a different fact,
       and folding it into ">=500 ms" would hide a failure inside a slowness. */
    var BUCKETS = [[5, 'lu-b5'], [20, 'lu-b20'], [50, 'lu-b50'],
                   [150, 'lu-b150'], [500, 'lu-b500']];
    window.luDiagBucket = function (ms, ok) {
        if (!ok) return 'lu-bbad';
        for (var i = 0; i < BUCKETS.length; i++) {
            if (ms < BUCKETS[i][0]) return BUCKETS[i][1];
        }
        return 'lu-b500p';
    };

    /* ── pure: hot zones ─────────────────────────────────────────────────── */
    /* The LONGEST run of consecutive slow-or-failed chunks, if it reaches
       minRun. A single slow chunk on a healthy disk is noise -- a seek, a
       queued write elsewhere, the drive's own background scan -- and reporting
       it as a zone is how a callout becomes something people scroll past.
       A FAILED chunk counts toward the run whatever its latency: an unreadable
       block is worse than a slow one, and keying the run on latency alone
       splits a zone around the one block that failed outright. */
    var SLOW_MS = 150;
    window.luDiagHotZone = function (cells, minRun) {
        var best = null, run = null, i, c, hot;
        for (i = 0; i < cells.length; i++) {
            c = cells[i];
            hot = (c.ok === false) || (c.ms >= SLOW_MS);
            if (hot) {
                if (run === null) run = { from: c.lba, to: c.lba, n: 1 };
                else { run.to = c.lba; run.n++; }
                if (best === null || run.n > best.n) best = { from: run.from, to: run.to, n: run.n };
            } else {
                run = null;
            }
        }
        return (best && best.n >= minRun) ? best : null;
    };

    /* ── pure: the one sentence that matters ─────────────────────────────── */
    /* MEDIA counters are the drive's own platters; PATH counters are the wire.
       Both moving is genuinely ambiguous, and saying so is the honest answer:
       picking a side there sends someone to buy the wrong part. */
    window.luDiagInterp = function (d) {
        var media = (d.grown || 0) + (d.uncorr || 0);
        var path  = (d.disp || 0) + (d.invdw || 0) + (d.loss || 0);
        if (media > 0 && path === 0)
            return 'Media counters moved (+' + media + '), path counters flat — points to MEDIA, not the cable.';
        if (path > 0 && media === 0)
            return 'Path counters moved (+' + path + '), media counters flat — points to the cable, backplane or HBA, not the platters.';
        if (media > 0 && path > 0)
            return 'Both media (+' + media + ') and path (+' + path + ') counters moved — ambiguous. Re-run after moving the drive to a different bay and cable.';
        return 'No counter moved during this run.';
    };

    /* ── state: everything durable is the offset ─────────────────────────── */
    var PHASES = [['preflight', 'Preflight'], ['baseline', 'Baseline snapshot'],
                  ['targeted', 'Targeted VERIFY/READ'], ['surface', 'Surface VERIFY'],
                  ['selftest', 'SMART short test'], ['verdict', 'Verdict']];
    function freshState() {
        return { phase: '', cells: [], deltas: {}, es: null, offset: 0,
                 paused: false, started: 0, lbaTotal: 0, lba: 0 };
    }
    var st = freshState();

    function drawPills() {
        var seen = false, out = '';
        for (var i = 0; i < PHASES.length; i++) {
            var cls = 'queued';
            if (PHASES[i][0] === st.phase) { cls = 'active'; seen = true; }
            else if (!seen) cls = 'done';
            out += '<span class="lu-diag-pill ' + cls + '">' + fesc(PHASES[i][1]) + '</span>';
        }
        el('diag-pills').innerHTML = out;
    }

    function drawMap() {
        var out = '', i, c;
        for (i = 0; i < st.cells.length; i++) {
            c = st.cells[i];
            out += '<span class="lu-diag-cell ' + luDiagBucket(c.ms, c.ok)
                 + '" title="LBA ' + (Number(c.lba) || 0) + ' +' + (Number(c.n) || 0)
                 + ' · ' + (Number(c.ms) || 0) + ' ms'
                 + (c.ok ? '' : ' · UNREADABLE') + '"></span>';
        }
        el('diag-map').innerHTML = out;

        var z = luDiagHotZone(st.cells, 3);
        el('diag-hotzone').textContent = z
            ? 'Hot zone: ' + z.n + ' consecutive slow or failing chunks, LBA ' + z.from + '–' + z.to
            : '';
    }

    function drawHist() {
        var order = ['lu-b5', 'lu-b20', 'lu-b50', 'lu-b150', 'lu-b500', 'lu-b500p', 'lu-bbad'];
        var label = { 'lu-b5': '<5 ms', 'lu-b20': '<20 ms', 'lu-b50': '<50 ms',
                      'lu-b150': '<150 ms', 'lu-b500': '<500 ms',
                      'lu-b500p': '≥500 ms', 'lu-bbad': 'unreadable' };
        var counts = {}, i, b, max = 1, out = '';
        for (i = 0; i < st.cells.length; i++) {
            b = luDiagBucket(st.cells[i].ms, st.cells[i].ok);
            counts[b] = (counts[b] || 0) + 1;
            if (counts[b] > max) max = counts[b];
        }
        for (i = 0; i < order.length; i++) {
            var n = counts[order[i]] || 0;
            out += '<div><span style="display:inline-block;width:72px">' + label[order[i]] + '</span>'
                 + '<span class="lu-diag-cell ' + order[i]
                 + '" style="width:' + Math.round(180 * n / max) + 'px"></span> ' + n + '</div>';
        }
        el('diag-hist').innerHTML = out;
    }

    function drawCounters() {
        var keys = [['grown', 'Grown defect list'], ['uncorr', 'Uncorrected verify/read'],
                    ['disp', 'Running disparity'], ['invdw', 'Invalid DWORD'],
                    ['loss', 'Loss of DWORD sync']];
        var out = '', i, k, d;
        for (i = 0; i < keys.length; i++) {
            k = keys[i][0];
            d = st.deltas[k];
            out += '<div>' + fesc(keys[i][1]) + ': '
                 + (d === undefined ? '<span class="lu-muted">—</span>'
                    : (Number(d.after) || 0) + ' (' + (d.after - d.before >= 0 ? '+' : '')
                      + (d.after - d.before) + ')') + '</div>';
        }
        el('diag-counters').innerHTML = out;

        var flat = {};
        for (k in st.deltas) if (st.deltas.hasOwnProperty(k))
            flat[k] = st.deltas[k].after - st.deltas[k].before;
        el('diag-interp').textContent = luDiagInterp(flat);
    }

    function drawProgress() {
        var secs = st.started ? Math.round((Date.now() - st.started) / 1000) : 0;
        var pct  = st.lbaTotal > 0 ? Math.min(100, Math.round(100 * st.lba / st.lbaTotal)) : 0;
        var rate = secs > 0 ? Math.round(st.lba / secs) : 0;
        /* Remaining is omitted, not guessed, until there is a rate to divide
           by: a countdown from an undefined throughput is a number that looks
           measured and is not. Absence is not health, and it is not progress
           either. */
        var left = (rate > 0 && st.lbaTotal > st.lba)
            ? Math.round((st.lbaTotal - st.lba) / rate) + ' s remaining'
            : 'remaining unknown';
        el('diag-progress').textContent = pct + '% · LBA ' + st.lba
            + (st.lbaTotal ? ' of ' + st.lbaTotal : '')
            + ' · ' + rate + ' blocks/s · ' + secs + ' s elapsed · ' + left;
    }

    function logLine(text, sev) {
        var s = el('diag-stream');
        var t = new Date().toLocaleTimeString();
        s.innerHTML += '<div class="lu-' + (sev || 'muted') + '">' + fesc(t + '  ' + text) + '</div>';
        s.scrollTo(0, 1e9);
    }

    window.luDiagShow = function (which) {
        el('diag-live').hidden    = (which !== 'live');
        el('diag-verdict').hidden = (which !== 'verdict');
    };

    /* One decoded event -> the screen. Exported because this is the whole
       rendering contract and a test that cannot call it has to assert on the
       stream plumbing instead, which is the part least likely to be wrong. */
    window.luDiagApply = function (ev) {
        if (!ev || !ev.t) return;
        if (ev.t === 'phase') {
            st.phase = ev.phase;
            if (ev.lba_total) st.lbaTotal = ev.lba_total;
            if (!st.started) st.started = Date.now();
            if (!st.paused) {
                drawPills(); drawProgress();
                logLine('phase: ' + ev.phase, 'muted');
            }
        } else if (ev.t === 'chunk') {
            st.cells.push({ lba: ev.lba, n: ev.n, ms: ev.ms, ok: ev.ok !== false });
            st.lba = ev.lba + ev.n;
            if (!st.paused) {
                drawMap(); drawHist(); drawProgress();
                if (ev.ok === false) logLine(ev.op + ' FAILED at LBA ' + ev.lba + ' +' + ev.n, 'crit');
            }
        } else if (ev.t === 'counter') {
            st.deltas[ev.key] = { before: ev.before, after: ev.after };
            if (!st.paused) drawCounters();
        } else if (ev.t === 'verdict') {
            logLine('verdict: ' + ev.v + ' — ' + ev.why, ev.v === 'CLEAN' ? 'ok' : 'crit');
            /* No arguments: the verdict screen is rendered server-side from the
               job id in luDiagJob, because it needs the sense keys and the
               cmd_age this event does not carry. Passing `ev` here would imply
               otherwise and would go unused. */
            window.luDiagRenderVerdict();
            luDiagShow('verdict');
            el('diag-dot').classList.remove('running');
            el('diag-dot').setAttribute('aria-label', 'No job running');
            el('diag-pause').disabled = true;
            el('diag-cancel').disabled = true;
        }
    };

    function openStream() {
        if (st.es) st.es.close();
        /* The offset rides the URL on the FIRST connect only. Every automatic
           reconnect after that carries Last-Event-ID, which the server prefers
           -- so a dropped connection resumes exactly where it stopped without
           this file tracking anything the browser already knows. */
        st.es = new EventSource('/plugins/hbaviewer/diagnose_stream.php?job='
            + encodeURIComponent(luDiagJob) + '&offset=' + st.offset);
        st.es.onmessage = function (m) {
            if (m.lastEventId) st.offset = parseInt(m.lastEventId, 10) || st.offset;
            var ev = null;
            try { ev = JSON.parse(m.data); } catch (e) { return; }
            luDiagApply(ev);
        };
        st.es.addEventListener('end', function () {
            st.es.close(); st.es = null;
            el('diag-dot').classList.remove('running');
            el('diag-dot').setAttribute('aria-label', 'No job running');
            el('diag-pause').disabled = true;
            el('diag-cancel').disabled = true;
            logLine('job finished', 'ok');
        });
    }

    /* Pause is a VIEW control, not a job control. Phase 1 has no way to
       suspend an sg_verify mid-command and pretending otherwise would leave
       the disk being read while the screen said "paused". This freezes the
       rendering and says so. Events keep landing in st while paused, so the
       map/histogram/counters/hot-zone catch up fully on resume -- but the
       diag-stream LOG does not: logLine() calls made while paused are
       skipped, not queued, so the text log has a gap for whatever arrived
       during the pause even though the state it describes was captured. */
    window.luDiagPause = function () {
        st.paused = !st.paused;
        el('diag-pause').textContent = st.paused ? 'Resume' : 'Pause';
        logLine(st.paused ? 'view paused — the job keeps running' : 'view resumed', 'muted');
        /* One full redraw from current state shows everything that arrived
           -- for the map/histogram/counters/hot-zone only; see above. */
        if (!st.paused) { drawPills(); drawProgress(); drawMap(); drawHist(); drawCounters(); }
    };

    window.luDiagCancel = function () {
        if (!luDiagJob) return;
        fetch('/plugins/hbaviewer/diagnose.php', { method: 'POST',
            body: new URLSearchParams({ action: 'cancel', job: luDiagJob, csrf_token: luCsrf }) })
          .then(function (r) { return r.json(); })
          .then(function (d) { logLine(d.ok ? 'cancelled' : 'nothing to cancel', 'warn'); })
          .catch(function () { logLine('cancel request failed', 'crit'); });
    };

    /* The disk NAME, never a /dev path: diagnose.php validates
       /^[a-z0-9]{2,32}$/ and a "/dev/sdb" would be refused. One spelling on
       both sides of the wire. */
    window.luDiagnose = function (dev) {
        var disk = String(dev || '').replace(/^\/dev\//, '');
        luDiagShow('live');
        if (typeof luTab === 'function') luTab('diagnose');
        return fetch('/plugins/hbaviewer/diagnose.php', { method: 'POST',
            body: new URLSearchParams({ action: 'start', disk: disk, csrf_token: luCsrf }) })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d.error) { logLine('refused: ' + d.error, 'crit'); return; }
            /* Close out the PREVIOUS job's stream only now that a NEW job is
               confirmed started. Tearing the view down before knowing the
               start succeeded left a refused start (e.g. the per-disk lock
               rejecting a double-click) with a permanently blanked,
               unrecoverable view of a still-running job -- there is no
               reload-resume (luDiagJob is '' on page load) and no re-open. */
            if (st.es) { st.es.close(); st.es = null; }
            st = freshState();
            el('diag-map').innerHTML = '';
            el('diag-stream').innerHTML = '';
            el('diag-hotzone').textContent = '';
            el('diag-head').innerHTML = 'Diagnosing <code>/dev/' + fesc(disk) + '</code>';
            el('diag-pause').textContent = 'Pause';
            luDiagJob = d.job;
            el('diag-dot').classList.add('running');
            el('diag-dot').setAttribute('aria-label', 'Job running');
            el('diag-pause').disabled = false;
            el('diag-cancel').disabled = false;
            logLine('job ' + d.job + ' started', 'ok');
            openStream();
          })
          .catch(function () { logLine('request failed', 'crit'); });
    };

    /* Replaced by render/diagnose.php's client half in the next task. */
    if (!window.luDiagRenderVerdict) window.luDiagRenderVerdict = function () {};

})();
