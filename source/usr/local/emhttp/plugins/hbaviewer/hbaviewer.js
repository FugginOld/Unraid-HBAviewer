(function () {
    var REFRESH_MS = 60000;
    var timer;
    var smartTimer;
    var loaded = {};

    /* ── Tab switching ────────────────────────────────────────────────────── */
    window.luTab = function (name) {
        if (window.luMetricsStop) luMetricsStop();   // pause perf polling on any switch
        document.querySelectorAll('.lu-tab-btn').forEach(function (b) {
            var on = b.dataset.tab === name;
            b.classList.toggle('active', on);
            /* The class is what the eye reads; these two are what a screen
               reader and the Tab key read, and they have to say the same thing.
               A ROVING tabindex: exactly one tab is in the page's tab order, so
               Tab leaves the strip in one press instead of walking ten buttons,
               and the arrow keys (luTabKey) move within it. Guarded on role
               because the strip also holds the two navigators, which are
               role=link and must keep their own place in the order. */
            if (b.getAttribute('role') !== 'tab') return;
            b.setAttribute('aria-selected', on ? 'true' : 'false');
            if (on) b.removeAttribute('tabindex'); else b.setAttribute('tabindex', '-1');
        });
        document.querySelectorAll('.lu-tab-pane').forEach(function (p) {
            p.classList.toggle('active', p.id === 'tab-' + name);
        });
        if (name === 'smart') {
            luSmartAll(false);
        } else if (name === 'perf') {
            luMetricsStart();
        } else if (name === 'baymap') {
            // JSON, not an HTML fragment, so it never goes through luReloadTab.
            if (!luBay.data) luBayFetch();
        } else if (name !== 'overview' && !loaded[name]) {
            luReloadTab(name);
        }
    };

    /* Arrow-key movement along the strip, which is what role=tablist promises a
       screen-reader user it will get. Delegated from .lu-tabs so the two PHP
       conditionals that hide optional tabs cannot leave a handler bound to a
       button that is not there.
       Only role=tab participates: Left/Right must skip the Firmware and
       Settings navigators at the end, or an arrow press leaves the page.
       Activation follows focus, matching the mouse — switching a pane here is
       free, so the manual-activation variant would only cost a second press. */
    window.luTabKey = function (e) {
        var step = {ArrowRight: 1, ArrowLeft: -1, Home: 'first', End: 'last'}[e.key];
        if (step === undefined) return;
        var tabs = [].slice.call(e.currentTarget.querySelectorAll('[role="tab"]'));
        var i = tabs.indexOf(document.activeElement);
        if (i < 0) return;                       // focus is on a navigator, not a tab
        var next = step === 'first' ? 0
                 : step === 'last'  ? tabs.length - 1
                 : (i + step + tabs.length) % tabs.length;   // wraps, per the ARIA pattern
        e.preventDefault();                      // Home/End would otherwise scroll the page
        tabs[next].focus();
        luTab(tabs[next].dataset.tab);
    };

    /* ── Load / reload a tab's content via AJAX ───────────────────────────── */
    window.luReloadTab = function (name) {
        var el = document.getElementById(name + '-content');
        if (!el) return;
        el.innerHTML = '<div class="lu-loading">Loading…</div>';
        fetch('/plugins/hbaviewer/ajax_info.php?type=' + name)
            .then(function (r) { return r.text(); })
            .then(function (html) {
                el.innerHTML = html;
                loaded[name] = true;
                // The fragment carries the state at render time; this catches a
                // locate that started or expired since (plan 048).
                if (name === 'drives' && window.luLocateSync) luLocateSync();
            })
            .catch(function () {
                el.innerHTML = '<div class="lu-error">Request failed.</div>';
            });
    };

    /* ── PHY tab: snapshot one controller's error counters as the baseline ────
       Confirmed first, because it discards the previous reference point. The
       server re-reads the hardware rather than trusting anything sent from
       here, so this only picks the controller and reloads the tab. */
    window.luPhyBaseline = function (ctl, btn) {
        if (!confirm('Set the PHY error baseline for controller /c' + ctl + ' to its current counters?\n\n'
                   + 'Everything the tab shows as Δ and /hr is measured from this moment. '
                   + 'Any existing baseline for this controller is replaced.')) return;
        var label = btn.textContent;
        btn.disabled = true; btn.textContent = 'Working…';
        fetch('/plugins/hbaviewer/phy_baseline.php', {
            method: 'POST',
            body: new URLSearchParams({reset_baseline: ctl, csrf_token: luCsrf})
        })
            .then(function (r) { return r.text(); })
            .then(function (t) {
                if (t.trim() === 'ok') { luReloadTab('phy'); return; }
                btn.disabled = false; btn.textContent = label;
                alert('Baseline not set: ' + t);
            })
            .catch(function () { btn.disabled = false; btn.textContent = label; });
    };

    /* ── SMART tab: poll the background collector until the cache is ready ──── */
    window.luSmartAll = function (force) {
        var el = document.getElementById('smart-content');
        if (!el) return;
        clearTimeout(smartTimer);   // single poll loop
        if (force) el.innerHTML = '<div class="lu-loading">Starting…</div>';
        fetch('/plugins/hbaviewer/ajax_info.php?type=smart_all' + (force ? '&refresh=1' : ''))
            .then(function (r) { return r.text(); })
            .then(function (html) {
                el.innerHTML = html;
                if (/data-smart="collecting"/.test(html)) {
                    smartTimer = setTimeout(function () { luSmartAll(false); }, 3000);
                }
            })
            .catch(function () { el.innerHTML = '<div class="lu-error">Request failed.</div>'; });
    };

    /* ── Per-drive SMART fetch (on demand; -n standby, never wakes a disk) ──── */
    window.luSmart = function (btn, serial) {
        btn.disabled = true; btn.textContent = '…';
        fetch('/plugins/hbaviewer/ajax_info.php?type=smart&serial=' + encodeURIComponent(serial))
            .then(function (r) { return r.text(); })
            .then(function (html) { btn.outerHTML = html; })
            .catch(function () { btn.disabled = false; btn.textContent = 'retry'; });
    };

    /* ── Copy a tab's rendered content to the clipboard (for support tickets) ── */
    window.luCopy = function (name, btn) {
        var el = document.getElementById(name + '-content');
        if (!el) return;
        var text = el.innerText || el.textContent || '';
        var done = function () {
            var old = btn.textContent; btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = old; }, 1200);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () {});
        } else {
            var r = document.createRange(); r.selectNode(el);
            var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r);
            try { document.execCommand('copy'); done(); } catch (e) {}
            sel.removeAllRanges();
        }
    };

    /* ── Overview: full card HTML via AJAX (banner shows until the read done) ──
       While the backend is still reading (data-overview="warming") poll every
       few seconds; once cards are in, settle into the slow auto-refresh. */
    function loadOverview() {
        var el = document.getElementById('overview-content');
        if (!el) return;
        fetch('/plugins/hbaviewer/ajax_info.php?type=overview_html')
            .then(function (r) { return r.text(); })
            .then(function (html) {
                el.innerHTML = html;
                clearTimeout(timer);
                var warming = /data-overview="warming"/.test(html);
                timer = setTimeout(loadOverview, warming ? 4000 : REFRESH_MS);
            })
            .catch(function () {
                el.innerHTML = '<div class="lu-error">Request failed — retrying…</div>';
                clearTimeout(timer);
                timer = setTimeout(loadOverview, 5000);
            });
    }


    /* ── Drives tab: the bay map (plan 047) ───────────────────────────────────
       Lives here, below luCsrf, because every write goes through the same
       Unraid CSRF token the baseline POST uses.
       The grid is built from the payload with createElement + textContent, not
       innerHTML: model and serial strings come off the drive itself, and a
       drive's own firmware is not a trusted source of markup. */
    /* drag = the key being dragged, over = the cell currently under the pointer.
       Both live here rather than in dataTransfer because dataTransfer is
       write-only during dragover — the browser will not let a page read what is
       being dragged until the drop, and the hover highlight needs it sooner. */
    var luBay = { data: null, sel: null, dimTimer: 0, drag: null, over: null };

    /* Two ways in. The loud one blanks the card and rebuilds all of it, which is
       right on first open and after anything that changes the TOOLBAR — lock,
       clear, undo, a resize. The quiet one leaves the card standing and repaints
       only the grid and tray, which is right after moving a single drive: there
       the full rebuild threw away the whole card and flashed "Loading…" for a
       change that touched two bays. */
    function luBayLoad(quiet) {
        var el = document.getElementById('baymap-content');
        if (!quiet) el.innerHTML = '<div class="lu-loading">Loading…</div>';
        fetch('/plugins/hbaviewer/ajax_info.php?type=baymap')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                // An error still takes the card, quiet or not: a stale map left
                // silently on screen is worse than a visible failure.
                if (d.error) { el.innerHTML = '<div class="lu-error"></div>'; el.firstChild.textContent = d.error; return; }
                luBay.data = d;
                if (quiet) { luBayPaint(); }
                else { luBay.sel = null; luBayRender(); }
                if (window.luLocateSync) luLocateSync();
            })
            .catch(function () { if (!quiet) el.innerHTML = '<div class="lu-error">Request failed.</div>'; });
    }

    /* Takes no argument ON PURPOSE. It is handed straight to luBayPost as a
       callback, which calls it with the server's reply — so a `quiet` parameter
       here would be truthy on every one of those call sites and silently turn
       the loud path into the quiet one. */
    window.luBayFetch = function () { luBayLoad(false); };
    function luBayReload() { luBayLoad(true); }

    function luBayPost(body, done) {
        body.csrf_token = luCsrf;
        fetch('/plugins/hbaviewer/bay_map.php', {method: 'POST', body: new URLSearchParams(body)})
            .then(function (r) { return r.json(); })
            .then(function (j) {
                /* A refused write has to be un-done on screen as well as
                   reported. The grid is now painted optimistically, so without
                   this resync the map would keep showing a move the server
                   rejected — the one state the person has no way to notice. */
                if (!j.ok) { alert(j.error || 'Bay map not saved.'); luBayReload(); return; }
                if (done) done(j);
            })
            .catch(function () { alert('Bay map request failed.'); luBayReload(); });
    }

    /* Chrome once, contents on every change. Re-rendering the whole view from
       the dimension inputs' own oninput would replace the input the person is
       typing into and drop focus mid-number, so only the grid and tray repaint. */
    function luBayRender() {
        var d = luBay.data, el = document.getElementById('baymap-content');
        var dis = d.locked ? ' disabled' : '';
        el.innerHTML =
            '<div class="lu-card first">'
          /* Toolbar: Rows / Columns / lock, and no full-width sentence about
             the lock state — the disabled inputs and the glyph already say it,
             and that band of prose was the largest piece of dead space on the
             screen. The unlock button is the only thing pressable while
             locked, so it carries the explanation in its tooltip. */
          + '<div class="lu-bay-dims">'
          +   '<label>Rows <input type="number" id="bay-rows" min="1" max="12" value="' + (d.rows | 0) + '"' + dis + '></label>'
          +   '<label>Columns <input type="number" id="bay-cols" min="1" max="12" value="' + (d.cols | 0) + '"' + dis + '></label>'
          +   '<button class="lu-refresh-btn" id="bay-lock" onclick="luBayLock()" title="'
          +     (d.locked ? 'The layout is locked. Unlock it to move drives or resize the grid.'
                          : 'Lock the layout so it cannot be changed by accident.') + '">'
          +     (d.locked ? '&#128274; Unlock' : '&#128275; Lock') + '</button>'
          /* "Clear map", never "Clear array" — on an Unraid page that second
             word means the disks, and a button that reads as "erase my array"
             is a scare nobody needs to survive to use this. */
          +   '<button class="lu-refresh-btn" id="bay-clear" onclick="luBayClear()"' + dis
          +     ' title="Send every placed drive back to the unassigned list. Only the map is'
          +     ' cleared — no drive is touched.">Clear map</button>'
          /* Copy carries no `dis`: reading the map out is safe with the layout
             locked, and a locked map is exactly the finished one worth saving.
             Restore writes, so it is disabled like everything else that does. */
          +   '<button class="lu-refresh-btn" id="bay-copy" onclick="luBayCopy(this)"'
          +     ' title="Copy the map to the clipboard, so it can be kept somewhere'
          +     ' other than the boot flash.">Copy map</button>'
          +   '<button class="lu-refresh-btn" id="bay-restore" onclick="luBayRestore()"' + dis
          +     ' title="Rebuild the map from text that Copy map produced, or from a'
          +     ' bay_map.json out of a backup.">Restore map</button>'
          /* Only rendered when there is something to undo, so it is never a
             button that does nothing — and its presence is the signal that the
             last action was one of the destructive ones. */
          +   (d.has_backup && !d.locked
                  ? '<button class="lu-refresh-btn" id="bay-undo" onclick="luBayUndo()"'
                    + ' title="Put the map back as it was before the last Clear or grid resize.">'
                    + '&#8630; Undo</button>'
                  : '')
          /* The hint used to sit here. It moved to the tab header: a sentence
             in the middle of a row of controls shifted every button along it
             whenever the text changed, and the toolbar is for controls. */
          + '</div>'
          + '<div class="lu-bay-legend">'
          +   luBayLegend('#3fb950', 'Healthy')     + luBayLegend('#d29922', 'High temp')
          +   luBayLegend('#f85149', 'Failed')      + luBayLegend('#58a6ff', 'Parity rebuild')
          +   luBayLegend('#6e7681', 'No SMART data')
          +   '<span class="lu-bay-lg"><i class="dashed"></i>Empty bay</span>'
          /* The colours and temperatures above are only as current as the
             collection behind them, and that collection is now kept until
             someone refreshes it. Say its age here rather than letting a
             three-day-old temperature pass for a live one. */
          +   '<span class="lu-bay-lg" style="margin-left:auto">' + (d.smart_age
                  ? 'SMART data collected ' + d.smart_age + ' ago — refresh it on the SMART tab'
                  : 'No SMART data yet — open the SMART tab to collect it') + '</span>'
          + '</div>'
          + '<div class="lu-bay-scroll"><div class="lu-bay-grid" id="bay-grid"></div></div>'
          + '<p class="lu-muted" style="font-size:12px;margin:0 0 8px">Unassigned drives</p>'
          + '<div class="lu-bay-tray" id="bay-tray"></div>'
          + '</div>';
        /* Set, not rendered: the hint lives in the TAB header, which is outside
           #baymap-content and so is not luBayRender's to rewrite. Emptied while
           locked, because none of those gestures do anything then. */
        var hint = document.getElementById('bay-hint');
        if (hint) {
            // The keyboard path is named here rather than left to be discovered:
            // a control that only works if you already know it exists is not
            // much better than one that does not work.
            hint.textContent = d.locked ? ''
                : 'Drag a drive into a bay, or click one then a bay. '
                + 'Drag it back to the tray — or double-click it — to empty the bay. '
                + 'By keyboard: Enter to pick up and place, Delete to empty.';
        }
        if (!d.locked) {
            // change, not input: `input` fires on every keystroke, so clearing
            // the field to retype it read as "1 row" and the debounced save
            // then displaced every drive below row 0 — the accidental wipe.
            // `change` waits for the field to be committed (blur/Enter/spinner).
            document.getElementById('bay-rows').onchange = luBayDims;
            document.getElementById('bay-cols').onchange = luBayDims;
        }
        luBayPaint();
    }

    /* The confirm names the COUNT rather than asking "are you sure?", because
       the number is the thing that makes a person stop: a map of 24 bays was
       built by walking to the rack and reading labels, and nothing here
       remembers it once it is written. There is no undo to fall back on, and
       the server cannot tell an intended clear from a misclick — so this
       prompt is the only guard the action has. */
    window.luBayClear = function () {
        var n = (luBay.data.placed || []).length;
        // Already empty: say so instead of asking a question whose answer
        // changes nothing.
        if (!n) { alert('The bay map is already empty.'); return; }
        if (!confirm('Clear all ' + n + ' placed drive' + (n === 1 ? '' : 's') + ' from the bay map?\n\n'
                   + 'They go back to the unassigned list and you will have to place them again.\n'
                   + 'No drive is touched — only the map. This cannot be undone.')) return;
        luBayPost({action: 'clear'}, luBayFetch);
    };

    // No confirm on the way back: undo is the recovery path, and putting a
    // dialog in front of it would guard the safe direction.
    window.luBayUndo = function () { luBayPost({action: 'restore'}, luBayFetch); };

    /* execCommand('copy'), NOT navigator.clipboard. The modern API is gated on a
       secure context, and an Unraid webGui is normally reached over plain HTTP
       on a LAN address — where navigator.clipboard is simply undefined. This
       path is deprecated and works everywhere, which is the trade that matters
       for a button whose whole job is rescuing data. Returns false if the
       browser refuses, and the caller falls back to showing the text. */
    function luBayCopyText(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        // Off-screen but focusable; display:none would make select() a no-op.
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(ta);
        return ok;
    }

    /* Copy is the backup half. The map is hand-built by walking to the rack and
       nothing on the box can regenerate it, so it needs to be able to leave the
       box — a flash that dies, or a flash backup that quietly stopped running,
       otherwise takes it with no warning.
       Emitted in bay_map.json's own shape on purpose: what this produces can be
       pasted straight into that file, and the file's contents can be pasted
       straight back in here. One format, both directions. */
    window.luBayCopy = function (btn) {
        var m = {};
        (luBay.data.placed || []).forEach(function (p) { m[p.key] = {row: p.row, col: p.col}; });
        var n = Object.keys(m).length;
        if (!n) { alert('Nothing to copy — no drive is placed yet.'); return; }
        var text = JSON.stringify(m, null, 4);
        if (luBayCopyText(text)) {
            var old = btn.textContent;
            btn.textContent = 'Copied ' + n + ' bay' + (n === 1 ? '' : 's');
            setTimeout(function () { btn.textContent = old; }, 1600);
        } else {
            // Never leave with nothing: if the copy is refused, show the text so
            // it can still be selected by hand.
            prompt('Copy this and keep it somewhere off the flash:', text);
        }
    };

    window.luBayRestore = function () {
        var raw = prompt('Paste a saved map — either what "Copy map" produced, '
                       + 'or the contents of bay_map.json from a backup:');
        if (raw === null || !raw.trim()) return;
        // Parsed here only to reject obvious rubbish before a round trip; the
        // server re-validates every key and position regardless.
        try { JSON.parse(raw); } catch (e) { alert('That is not valid JSON.'); return; }
        luBayPost({action: 'import', map: raw.trim()}, function (j) {
            /* Say what was dropped. A restore that silently keeps 18 of 24 bays
               reads as a success and sends someone to the wrong slot. */
            if (j.skipped) {
                alert('Restored ' + j.placed + ' placement' + (j.placed === 1 ? '' : 's') + '.\n\n'
                    + j.skipped + ' entr' + (j.skipped === 1 ? 'y was' : 'ies were') + ' skipped — '
                    + 'an unrecognised drive key, a bay outside the current grid size, '
                    + 'or two drives in the same bay.');
            }
            luBayFetch();
        });
    };

    window.luBayLock = function () {
        luBayPost({action: 'lock', locked: luBay.data.locked ? '0' : '1'}, function (j) {
            luBay.data.locked = !!j.locked;
            luBay.sel = null;
            luBayRender();
        });
    };

    function luBayPaint() {
        var d = luBay.data;
        var grid = document.getElementById('bay-grid');
        if (!grid) return;
        /* Keyboard focus has to survive the repaint. Every cell and chip below
           is a brand-new node, so the element the user was standing on when
           they pressed Enter is thrown away mid-action and the browser drops
           focus to the top of the document — one press in, the keyboard path
           is over. Remember the COORDINATES rather than the node (the node is
           the thing that will not exist), and re-focus whatever lands on them.
           A bay stays a bay across a repaint even when the drive in it moved,
           which is what a keyboard user expects: put a drive down, and you are
           still standing at the bay you put it in. */
        var af = document.activeElement, want = null, land = null;
        if (af && af.dataset) {
            want = af.dataset.trayKey !== undefined ? {tray: af.dataset.trayKey}
                 : af.dataset.row !== undefined ? {row: af.dataset.row, col: af.dataset.col}
                 : null;
        }
        grid.parentNode.classList.toggle('lu-bay-locked', !!d.locked);
        /* Emptying a bay is delegated to the GRID, which survives a repaint.
           A per-cell ondblclick cannot work here and shipped broken in
           2026.08.05: single-clicking a filled bay picks the drive up, that
           calls luBayPaint(), and this function replaces every cell with a
           fresh element. The browser then sees the two clicks of a double-click
           land on two DIFFERENT nodes and dispatches dblclick at their nearest
           common ancestor — this grid — so nothing on the cell ever runs.
           Assigned as a property, not addEventListener, so repainting cannot
           stack duplicate handlers. */
        grid.ondblclick = function (e) {
            if (luBay.data.locked) return;
            if (e.target.closest('button')) return;   // the Locate button, not the bay
            var hit = e.target.closest('.lu-bay-cell[data-bay-key]');
            if (!hit) return;
            luBayCommit(hit.dataset.bayKey, null, null);
        };
        /* The keyboard's half of the map, delegated for the same reason the two
           handlers either side of it are: luBayPaint() replaces every cell.

           Every write this view can perform now has a keyboard path — Enter or
           Space picks a drive up and puts it down (WCAG 2.2's single-pointer
           alternative to the drag, which click-then-click already gave the
           mouse), and Delete empties a bay, which was double-click only and so
           had no keyboard equivalent at all.

           Space is prevented before it scrolls the page out from under the
           bay the user is standing on. */
        grid.onkeydown = function (e) {
            var cell = e.target.closest && e.target.closest('.lu-bay-cell');
            if (!cell || luBay.data.locked) return;
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (cell.onclick) cell.onclick({detail: 1, target: cell});
            } else if ((e.key === 'Delete' || e.key === 'Backspace') && cell.dataset.bayKey) {
                e.preventDefault();
                luBayCommit(cell.dataset.bayKey, null, null);
            }
        };
        /* Drag and drop, delegated to the grid for the same reason dblclick is:
           luBayPaint() replaces every cell, so a handler bound to a cell is
           bound to a node that is about to be thrown away. Click-then-click is
           deliberately kept alongside this — HTML5 drag does nothing at all on
           a touch screen, and that is the fallback rather than a second
           codepath, since both ends post the same assign action. */
        grid.ondragstart = function (e) {
            // The Locate button lives inside a draggable cell; dragging from it
            // must not pick the drive up. Same hazard the dblclick guard has.
            if (luBay.data.locked || e.target.closest('button')) { e.preventDefault(); return; }
            var cell = e.target.closest('.lu-bay-cell[data-bay-key]');
            if (!cell) { e.preventDefault(); return; }
            luBay.drag = cell.dataset.bayKey;
            e.dataTransfer.effectAllowed = 'move';
            // Firefox will not start a drag at all unless some data is set.
            e.dataTransfer.setData('text/plain', luBay.drag);
        };
        grid.ondragover = function (e) {
            if (!luBay.drag || luBay.data.locked) return;
            var cell = e.target.closest('.lu-bay-cell');
            if (!cell) return;
            // preventDefault is what marks this a valid drop target. Without it
            // the browser refuses the drop and animates the drag back.
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (luBay.over !== cell) { luBayDragClear(); luBay.over = cell; cell.classList.add('drop'); }
        };
        grid.ondrop = function (e) {
            e.preventDefault();
            var key = luBay.drag, cell = e.target.closest('.lu-bay-cell');
            luBayDragEnd();
            if (!key || !cell || luBay.data.locked) return;
            luBayCommit(key, +cell.dataset.row, +cell.dataset.col);
        };
        // Fires however the drag ends, including Escape and a drop on nothing —
        // without it the highlight sticks to a cell nobody is pointing at.
        grid.ondragend = luBayDragEnd;
        grid.innerHTML = '';
        grid.style.setProperty('--bay-cols', d.cols);
        var at = {};
        d.placed.forEach(function (p) { at[p.row + ':' + p.col] = p; });

        for (var r = 0; r < d.rows; r++) {
            for (var c = 0; c < d.cols; c++) {
                var drv  = at[r + ':' + c];
                var slot = (r + 1) + '-' + (c + 1);
                var cell = document.createElement('div');

                if (!drv) {
                    // An empty bay is drawn as a bay, not as a gap — a chassis
                    // with a hole in it is information.
                    cell.className = 'lu-bay-cell empty' + (luBay.sel ? ' target' : '');
                    var eid = document.createElement('span');
                    eid.className = 'lu-bay-eid'; eid.textContent = slot;
                    var ew = document.createElement('span');
                    ew.className = 'lu-bay-eword'; ew.textContent = 'EMPTY BAY';
                    cell.appendChild(eid); cell.appendChild(ew);
                } else {
                    var st = luBayState(drv, d.warn_temp);
                    cell.className = 'lu-bay-cell st-' + st.cls + (luBay.sel === drv.key ? ' sel' : '');

                    var body = document.createElement('div');
                    body.className = 'lu-bay-body';

                    // 1. Identity: slot chip, device path (the anchor), status chip.
                    var id = document.createElement('div');
                    id.className = 'lu-bay-id';
                    var sc = document.createElement('span');
                    sc.className = 'lu-bay-slot'; sc.textContent = slot;
                    var dv = document.createElement('span');
                    dv.className = 'lu-bay-dev'; dv.textContent = drv.dev || drv.slot || drv.key;
                    var stc = document.createElement('span');
                    stc.className = 'lu-bay-stat'; stc.textContent = st.label;
                    stc.style.color = st.col;
                    stc.style.background = st.col + '22';
                    id.appendChild(sc); id.appendChild(dv); id.appendChild(stc);
                    body.appendChild(id);

                    // 2. Capacity + temperature.
                    var cap = document.createElement('div');
                    cap.className = 'lu-bay-cap';
                    var cv = document.createElement('span');
                    cv.className = 'lu-bay-capv'; cv.textContent = drv.cap || '—';
                    var cu = document.createElement('span');
                    cu.className = 'lu-bay-capu'; cu.textContent = drv.cap_unit || '';
                    var tp = document.createElement('span');
                    tp.className = 'lu-bay-temp';
                    // No reading is said, never left to read as a temperature.
                    tp.textContent = drv.temp === null ? 'no data' : drv.temp + '°C';
                    var heat = luBayHeat(drv.temp, d.warn_temp);
                    if (heat) tp.style.color = heat;
                    cap.appendChild(cv); cap.appendChild(cu); cap.appendChild(tp);
                    body.appendChild(cap);

                    // 3. Temperature bar — a hot row is visible without reading
                    //    24 numbers. An unread drive gets an empty track, not a
                    //    zero-width bar that would read as "cold".
                    var track = document.createElement('div');
                    track.className = 'lu-bay-track';
                    if (drv.temp !== null || st.cls === 'rebuild') {
                        var fill = document.createElement('div');
                        fill.className = 'lu-bay-fill' + (st.cls === 'rebuild' ? ' rebuild' : '');
                        var pct = drv.temp === null ? 100
                                : Math.max(6, Math.min(100, ((drv.temp - 30) / 25) * 100));
                        fill.style.width = pct + '%';
                        if (st.cls !== 'rebuild') fill.style.background = heat || '#8b949e';
                        track.appendChild(fill);
                    }
                    body.appendChild(track);

                    // 4. Reference rows, one left edge for every value.
                    var ref = document.createElement('div');
                    ref.className = 'lu-bay-ref';
                    // First, because it is the identifier the person already
                    // knows: what this disk is called everywhere else in Unraid.
                    /* UNRAID and PORT share a row — both values are short, and
                       pairing them takes a row off every card in the grid. MODEL
                       and SERIAL each keep a full row: they are the long ones,
                       and halving their width only buys an ellipsis. */
                    if (drv.role) luBayRef(ref, 'UNRAID', drv.role);
                    luBayRef(ref, 'PORT',   drv.port);
                    luBayRef(ref, 'MODEL',  drv.model,  false, true);
                    luBayRef(ref, 'SERIAL', drv.serial, true,  true);
                    body.appendChild(ref);

                    // Locate lives inside the cell but is not part of
                    // click-to-move — its handler stops propagation.
                    if (drv.addr) {
                        var lb = document.createElement('button');
                        lb.className = 'lu-refresh-btn lu-bay-loc' + (drv.locating ? ' locating' : '');
                        lb.setAttribute('data-locate', drv.addr);
                        lb.textContent = drv.locating ? 'STOP' : 'Locate';
                        lb.onclick = (function (a, dv) {
                            return function (ev) { luLocate(ev, this, a, dv); };
                        })(drv.addr, drv.dev);
                        body.appendChild(lb);
                        if (drv.locating) cell.classList.add('locating');
                    }

                    cell.appendChild(body);
                }

                if (!d.locked) {
                    cell.onclick = luBayCellClick(r, c, drv);
                    /* The cell is a div with an onclick, which the keyboard
                       cannot reach at all — this is what makes it a control.
                       The name is spelled out rather than left to the cell's
                       own text, which reads as an unpunctuated run of every
                       field in the card ("sdb OK 8 TB 38 UNRAID Disk 1 PORT
                       Port 4 MODEL..."). Bay first, because the bay is the
                       coordinate the user is navigating by. */
                    cell.setAttribute('role', 'button');
                    cell.tabIndex = 0;
                    cell.setAttribute('aria-label', drv
                        ? 'Bay ' + slot + ', ' + (drv.dev || drv.serial || 'drive')
                          + (drv.role ? ', ' + drv.role : '')
                        : 'Bay ' + slot + ', empty');
                    // Picking a drive up is a toggle, and pressed is how a
                    // button says so.
                    if (drv) cell.setAttribute('aria-pressed', luBay.sel === drv.key ? 'true' : 'false');
                    // The key rides on the element; the grid's delegated
                    // dblclick reads it. A handler here could never fire.
                    if (drv) cell.dataset.bayKey = drv.key;
                    // Every bay is a drop TARGET, so both coordinates have to be
                    // readable from the element — the delegated handler has no
                    // closure over r and c the way cell.onclick does.
                    cell.dataset.row = r;
                    cell.dataset.col = c;
                    if (want && +want.row === r && +want.col === c) land = cell;
                    if (drv) cell.draggable = true;
                }
                grid.appendChild(cell);
            }
        }

        var tray = document.getElementById('bay-tray');
        tray.innerHTML = d.unassigned.length
            ? '' : '<span class="lu-muted" style="font-size:12px">Every detected drive is placed.</span>';
        d.unassigned.forEach(function (u) {
            var chip = document.createElement('span');
            // No key = the drive reported neither a port nor a PHY, so there is
            // nothing stable to remember it by. Shown, but not placeable.
            chip.className = 'lu-bay-chip' + (u.key === null ? ' dead' : (luBay.sel === u.key ? ' sel' : ''));
            chip.textContent = (u.dev || u.slot || u.serial || '?') + (u.role ? '  ' + u.role : '');
            chip.title = u.key === null
                ? 'This drive reports no port or PHY, so it cannot be assigned to a bay.'
                : [u.role, u.model, u.serial, u.size].filter(Boolean).join(' · ');
            if (u.key !== null && !d.locked) {
                chip.onclick = function () { luBay.sel = (luBay.sel === u.key) ? null : u.key; luBayPaint(); };
                chip.draggable = true;
                chip.dataset.trayKey = u.key;
                // Same reason as the cells: a <span> with an onclick is not a
                // control until it says so and the keyboard can land on it.
                chip.setAttribute('role', 'button');
                chip.tabIndex = 0;
                chip.setAttribute('aria-pressed', luBay.sel === u.key ? 'true' : 'false');
                if (want && want.tray === u.key) land = chip;
            }
            tray.appendChild(chip);
        });

        /* The tray is the drop target for taking a drive back OUT of a bay —
           the drag equivalent of double-clicking it. Assigned after the chips
           because tray.innerHTML above replaces its contents, not the element,
           so these survive as properties on the same node. */
        // The tray's half of the keyboard path. Only Enter/Space: a chip is
        // already unassigned, so there is nothing here for Delete to do.
        tray.onkeydown = function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var chip = e.target.closest && e.target.closest('.lu-bay-chip[data-tray-key]');
            if (!chip || !chip.onclick) return;
            e.preventDefault();
            chip.onclick();
        };
        tray.ondragstart = function (e) {
            var chip = e.target.closest('.lu-bay-chip[data-tray-key]');
            if (luBay.data.locked || !chip) { e.preventDefault(); return; }
            luBay.drag = chip.dataset.trayKey;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', luBay.drag);
        };
        tray.ondragover = function (e) {
            if (!luBay.drag || luBay.data.locked) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            tray.classList.add('drop');
        };
        tray.ondragleave = function () { tray.classList.remove('drop'); };
        tray.ondrop = function (e) {
            e.preventDefault();
            var key = luBay.drag;
            luBayDragEnd();
            if (!key || luBay.data.locked) return;
            // Dragging a tray chip back onto the tray is a no-op, not a POST
            // that unassigns something already unassigned.
            if (!luBay.data.placed.some(function (p) { return p.key === key; })) return;
            luBayCommit(key, null, null);
        };
        tray.ondragend = luBayDragEnd;

        // A drive that moved from the tray into a bay leaves no chip to return
        // to, so `land` is null and focus stays where the browser put it. That
        // is the one case worth a fallback, but the bay it went into is not
        // reachable from here without guessing which one, and guessing wrong
        // would move a keyboard user somewhere they did not ask to be.
        if (land && land.focus) land.focus();
    }

    /* Move a drive in the LOCAL model, so the grid redraws on the spot instead
       of after a round trip. Mirrors what bay_map.php's assign does, including
       displacing whatever already occupied the target bay — if the two ever
       disagree, the quiet reload afterwards overwrites this and the server stays
       the authority.
       col === null means "back to the tray". The drive is appended there rather
       than sorted into Unraid's Main-page order, because that order is computed
       server-side (bay_tray_order) and duplicating the comparator here would be
       a second copy of the rule to keep in step. The reload settles it a moment
       later, which is the right trade for not having two sorts to maintain. */
    function luBayApply(key, row, col) {
        var d = luBay.data, moving = null;
        function pull(list) {
            return list.filter(function (e) {
                if (moving || e.key !== key) return true;
                moving = e; return false;
            });
        }
        d.placed = pull(d.placed);
        d.unassigned = pull(d.unassigned);
        if (!moving) return false;
        if (col === null) {
            delete moving.row; delete moving.col;
            d.unassigned.push(moving);
        } else {
            d.placed = d.placed.filter(function (p) {
                if (p.row !== row || p.col !== col) return true;
                delete p.row; delete p.col;
                d.unassigned.push(p);           // one drive per bay, same as the server
                return false;
            });
            moving.row = row; moving.col = col;
            d.placed.push(moving);
        }
        return true;
    }

    /* The single way a drive moves, whichever gesture asked for it — drag,
       click-then-click, or a double-click to empty. Paint first, POST second,
       reconcile last. Keeping all three gestures on this one path is why the
       optimistic update cannot drift between them. */
    function luBayCommit(key, row, col) {
        luBay.sel = null;
        if (!luBayApply(key, row, col)) return;   // nothing matched; nothing to save
        luBayPaint();
        luBayPost(col === null ? {action: 'unassign', key: key}
                               : {action: 'assign', key: key, row: row, col: col}, luBayReload);
    }

    // Drop highlights are cleared from one place, because every way a drag can
    // end has to clear them — drop, dragend, Escape, and moving to another cell.
    function luBayDragClear() {
        if (luBay.over) { luBay.over.classList.remove('drop'); luBay.over = null; }
        var t = document.getElementById('bay-tray');
        if (t) t.classList.remove('drop');
    }

    function luBayDragEnd() { luBay.drag = null; luBayDragClear(); }

    /* ── Locate: blink one drive's activity light (plan 048) ──────────────────
       The confirm fires once per page load, not once per press: the two things
       it says are properties of the technique, so a person needs them the first
       time and would resent them the tenth. */
    var luLocateWarned = false;

    window.luLocate = function (ev, btn, addr, dev) {
        // Bay cells are click-to-move and double-click-to-clear; this button
        // lives inside one and must not trigger either.
        if (ev) { ev.stopPropagation(); ev.preventDefault(); }
        var on = /locating/.test(btn.className);
        if (!on && !luLocateWarned) {
            if (!confirm('Locate blinks ' + (dev || 'this drive') + '’s ACTIVITY light by reading it '
                       + 'twice a second.\n\n'
                       + '• It is the activity light, not a dedicated locate LED. On a busy array other '
                       + 'drives blink too — look for the steady rhythm.\n'
                       + '• It wakes the drive and keeps it awake until you stop it, or it stops itself.\n\n'
                       + 'Start blinking?')) return;
            luLocateWarned = true;
        }
        luLocatePost(on ? 'stop' : 'start', addr);
    };

    function luLocatePost(action, addr) {
        var body = {action: action, csrf_token: luCsrf};
        if (addr) body.addr = addr;
        fetch('/plugins/hbaviewer/locate.php', {method: 'POST', body: new URLSearchParams(body)})
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) { alert(j.error || 'Locate failed.'); return; }
                luLocateApply(j.active || []);
            })
            .catch(function () { alert('Locate request failed.'); });
    }

    /* Paint every Locate control from one list of blinking addresses — the
       table's buttons and the map's bays are two views of the same server-side
       state, so neither is ever guessed at from what was just clicked. */
    function luLocateApply(active) {
        /* Write the state into luBay.data BEFORE the DOM, because the map is
           repainted from that data and not from what is on screen. Touching
           only the DOM was the bug: luBayPaint() clears the grid and rebuilds
           every cell out of luBay.data, so picking a drive up -- any click on
           the map at all -- restored whatever the Locate buttons looked like at
           the last fetch.
           That is not merely cosmetic, because luLocate() reads start-vs-stop
           off the button's own class. A button stale-showing "Locate" over a
           drive that IS blinking sends `start`, which is a deliberate no-op, so
           the drive keeps going and only the SECOND press stops it. Same shape
           as the double-click bug: state applied to cells that luBayPaint()
           then throws away. */
        if (luBay.data) {
            (luBay.data.placed || []).concat(luBay.data.unassigned || []).forEach(function (drv) {
                if (drv.addr) drv.locating = active.indexOf(drv.addr) !== -1;
            });
        }
        document.querySelectorAll('[data-locate]').forEach(function (el) {
            var on = active.indexOf(el.getAttribute('data-locate')) !== -1;
            el.classList.toggle('locating', on);
            // The button is its own stop control — one place to press, and it
            // says what pressing it does rather than what is happening.
            if (el.tagName === 'BUTTON') el.textContent = on ? 'STOP' : 'Locate';
            var cell = el.closest('.lu-bay-cell');
            if (cell) cell.classList.toggle('locating', on);
        });
    }

    // On load, ask the server what is already blinking — a locate started in
    // another tab, or before this reload, must still show as running.
    window.luLocateSync = function () { luLocatePost('status', null); };

    function luBayLegend(color, label) {
        return '<span class="lu-bay-lg"><i style="background:' + color + '"></i>' + label + '</span>';
    }

    // One PORT/MODEL/SERIAL row. An absent value still emits both cells, or the
    // rows below it climb into the wrong label's place.
    /* wide = this pair takes a row to itself, value spanning to the right edge.
       Used for MODEL and SERIAL, whose values are long enough that sharing a row
       would ellipsise them — and a truncated serial is no use at all against the
       label printed on the drive, which is the one moment it is ever read. */
    function luBayRef(parent, label, text, dim, wide) {
        var l = document.createElement('span');
        l.className = 'lu-bay-lbl' + (wide ? ' wide' : '');
        l.textContent = label;
        var v = document.createElement('span');
        v.className = 'lu-bay-val' + (dim ? ' dim' : '') + (wide ? ' wide' : '');
        v.textContent = (text === null || text === undefined || text === '') ? '—' : text;
        v.title = v.textContent;   // the value is ellipsised; the tooltip is the full string
        parent.appendChild(l);
        parent.appendChild(v);
    }

    /* Which state a bay renders as, and what the chip says. Health comes from
       the backend; a hot drive is promoted here because nothing server-side
       judges drive temperature. Order is worst-first — a failed drive that is
       also hot is failed. */
    function luBayState(drv, warn) {
        if (drv.state === 'fail')    return {cls: 'fail',    col: '#f85149', label: 'FAILED'};
        // The server says WHICH rebuild — Unraid's parity reconstruct, or a
        // controller-level one. It is never blank while the state is 'rebuild'.
        if (drv.state === 'rebuild') return {cls: 'rebuild', col: '#58a6ff',
                                             label: drv.rebuild_label || 'REBUILDING'};
        if (drv.temp !== null && drv.temp >= warn)
                                     return {cls: 'warn',    col: '#d29922', label: 'HIGH TEMP'};
        // Amber for a reallocated/pending sector count too — but never labelled
        // HIGH TEMP, which would be a plain lie on a 37°C drive.
        if (drv.state === 'warn')    return {cls: 'warn',    col: '#d29922', label: 'SECTORS'};
        if (drv.state === 'nodata')  return {cls: 'nodata',  col: '#6e7681', label: 'NO SMART'};
        return {cls: 'ok', col: '#3fb950', label: 'HEALTHY'};
    }

    // Heat scale off warnTemp. Normal returns '' — the number then inherits the
    // secondary ink, because a green temperature would signal something when
    // there is nothing to signal.
    function luBayHeat(t, warn) {
        if (t === null || t === undefined) return '';
        if (t >= warn + 4) return '#f85149';
        if (t >= warn)     return '#d29922';
        if (t >= warn - 3) return '#c9a227';
        return '';
    }

    /* Single click never destroys anything. On a filled bay it picks the drive
       up so you can put it somewhere else; on an empty one it drops whatever is
       held. Emptying a bay is a double-click, handled by the grid's delegated
       ondblclick in luBayPaint — deliberate, because a stray click on a map
       somebody walked to the rack to build should not undo any of it. */
    function luBayCellClick(r, c, drv) {
        return function (e) {
            /* Ignore the second click of a double-click. Without this the
               selection toggles on and straight back off before the dblclick
               that empties the bay arrives — harmless in the end state, but it
               repaints the grid twice and flickers on the way. */
            if (e && e.detail > 1) return;
            if (drv) { luBay.sel = (luBay.sel === drv.key) ? null : drv.key; luBayPaint(); return; }
            if (!luBay.sel) return;
            luBayCommit(luBay.sel, r, c);   // reads sel before luBayCommit clears it
        };
    }

    /* Resize: reflow the grid on screen, then persist. Drives that no longer
       fit move to the tray HERE too, not just in the server's prune, so the
       preview shows what the change will actually do.
       A shrink that displaces drives asks first. Everything else about this
       view is reversible with another click; this is the one action that can
       undo a lot of someone's work at once. */
    function luBayDims() {
        var rf = document.getElementById('bay-rows'), cf = document.getElementById('bay-cols');
        var rows = parseInt(rf.value, 10), cols = parseInt(cf.value, 10);
        // A blank or half-typed field is not a resize request. Without this,
        // clearing the box to retype it read as 1 and wiped the layout below
        // the first row.
        if (!(rows >= 1 && rows <= 12) || !(cols >= 1 && cols <= 12)) return;

        var d = luBay.data, keep = [], drop = [];
        d.placed.forEach(function (p) {
            if (p.row < rows && p.col < cols) keep.push(p); else drop.push(p);
        });
        if (drop.length && !confirm(
                drop.length + (drop.length === 1 ? ' drive does' : ' drives do')
                + ' not fit in a ' + rows + ' x ' + cols + ' grid.\n\n'
                + 'They go back to the unassigned list and you will have to place them again. '
                + 'Everything that still fits keeps its bay.')) {
            rf.value = d.rows; cf.value = d.cols;   // put the fields back
            return;
        }
        drop.forEach(function (p) { d.unassigned.push(p); });
        d.placed = keep; d.rows = rows; d.cols = cols;
        luBayPaint();
        clearTimeout(luBay.dimTimer);
        luBay.dimTimer = setTimeout(function () {
            luBayPost({action: 'dims', rows: rows, cols: cols}, luBayFetch);
        }, 400);
    }

    /* ── Performance tab: poll instant counters, compute rates, plot ─────────
       In-browser only: a ring buffer (~5 min) of rates derived from the delta
       between two /proc/diskstats + sysfs snapshots. Runs ONLY while the tab is
       open (luTab starts/stops it). Server stays stateless. */
    var perfTimer = null, perfActive = false, perfPrev = null, perfCharts = {};
    var PERF_MAX = 150;   // ~5 min at 2s

    function perfCell(title) {
        var wrap = document.createElement('div'); wrap.className = 'lu-perf-cell';
        var cap = document.createElement('div'); cap.className = 'cap';
        var t = document.createElement('span'); t.textContent = title;
        var v = document.createElement('b'); v.textContent = '–';
        cap.appendChild(t); cap.appendChild(v);
        var cv = document.createElement('div'); cv.className = 'lu-perf-canvas';
        var canvas = document.createElement('canvas'); cv.appendChild(canvas);
        wrap.appendChild(cap); wrap.appendChild(cv);
        return { wrap: wrap, canvas: canvas, val: v };
    }
    function perfChart(canvas, colors) {
        return new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: { labels: [], datasets: colors.map(function (c) { return {
                data: [], borderColor: c, backgroundColor: 'transparent',
                borderWidth: 1.4, pointRadius: 0, tension: 0.25, spanGaps: true }; }) },
            options: {
                animation: false, responsive: true, maintainAspectRatio: false,
                scales: { x: { display: false },
                          y: { beginAtZero: true, ticks: { color:'#777', font:{size:9}, maxTicksLimit:4 }, grid: { color:'#242424' } } },
                plugins: { legend: { display: false }, tooltip: { enabled: false } }
            }
        });
    }
    function perfPush(cell, values, valText) {
        var ch = cell.chart;
        ch.data.labels.push('');
        values.forEach(function (v, i) { if (ch.data.datasets[i]) ch.data.datasets[i].data.push(v); });
        if (ch.data.labels.length > PERF_MAX) {
            ch.data.labels.shift();
            ch.data.datasets.forEach(function (ds) { ds.data.shift(); });
        }
        ch.update('none');
        cell.val.textContent = valText;
    }
    function perfBuild(ctls) {
        var host = document.getElementById('perf-content'); host.innerHTML = ''; perfCharts = {};
        var defs = [
            { key:'thr',  title:'Throughput MB/s', series:['#3aa0ff','#f5a623'] },  // read, write
            { key:'iops', title:'IOPS',            series:['#2ecc71'] },
            { key:'util', title:'% Util',          series:['#9b59b6'] },
            { key:'lat',  title:'Latency ms',      series:['#e74c3c'] },
            { key:'phy',  title:'PHY err/s',       series:['#e67e22'] },
            { key:'temp', title:'Temp °C',         series:['#1abc9c'] }
        ];
        ctls.forEach(function (c) {
            var box = document.createElement('div'); box.className = 'lu-perf-ctl lu-card first';
            box.setAttribute('data-ctl', c.idx);
            var h = document.createElement('h4'); h.textContent = 'Controller /c' + c.idx; box.appendChild(h);
            var grid = document.createElement('div'); grid.className = 'lu-perf-grid';
            var cells = {};
            defs.forEach(function (d) {
                var cell = perfCell(d.title); grid.appendChild(cell.wrap);
                cell.chart = perfChart(cell.canvas, d.series); cells[d.key] = cell;
            });
            box.appendChild(grid); host.appendChild(box); perfCharts[c.idx] = cells;
        });
    }
    function perfDriveMap(c) { var m = {}; (c.drives || []).forEach(function (d) { m[d.dev] = d; }); return m; }

    function luMetricsRender(snap) {
        var ctls = snap.controllers || [];
        if (!ctls.length) { document.getElementById('perf-content').innerHTML = '<p class="lu-muted">No SAS controllers detected.</p>'; perfPrev = null; return; }
        if (Object.keys(perfCharts).length !== ctls.length) { perfBuild(ctls); perfPrev = null; }

        if (perfPrev) {
            var dt = snap.t - perfPrev.t;
            if (dt > 0) {
                var prevById = {}; (perfPrev.controllers || []).forEach(function (c) { prevById[c.idx] = c; });
                ctls.forEach(function (c) {
                    var cells = perfCharts[c.idx]; if (!cells) return;
                    var pc = prevById[c.idx];
                    if (pc) {
                        var pm = perfDriveMap(pc), cm = perfDriveMap(c);
                        var rMB = 0, wMB = 0, iops = 0, utilSum = 0, utilN = 0, dWt = 0, dOps = 0;
                        Object.keys(cm).forEach(function (dev) {
                            var cur = cm[dev], prv = pm[dev]; if (!prv) return;
                            var dR = cur.r_sect - prv.r_sect, dWs = cur.w_sect - prv.w_sect;
                            var dRi = cur.r_io - prv.r_io, dWi = cur.w_io - prv.w_io;
                            var dTick = cur.io_ticks - prv.io_ticks, dW = cur.weighted - prv.weighted;
                            if (dR < 0 || dWs < 0 || dRi < 0 || dWi < 0 || dTick < 0 || dW < 0) return;  // counter wrap -> skip drive
                            rMB += dR * 512 / dt / 1e6; wMB += dWs * 512 / dt / 1e6;
                            iops += (dRi + dWi) / dt;
                            utilSum += Math.min(100, dTick / dt / 10); utilN++;
                            dWt += dW; dOps += (dRi + dWi);
                        });
                        var util = utilN ? utilSum / utilN : 0;
                        var lat = dOps > 0 ? dWt / dOps : 0;
                        // phy is null when the counters were never read, not zero.
                        // A SAS4 controller in eHBA personality registers no SAS
                        // transport class, so /sys/class/sas_phy — the only source
                        // this poll is allowed to touch, being the instant path —
                        // is empty for it. Plotting 0 there would draw a confident
                        // flat line meaning "no link errors" on a card nobody
                        // measured. NaN leaves a gap in the series instead.
                        var phyRate = null;
                        if (c.phy && pc.phy) {
                            var dPhy = (c.phy.inv + c.phy.disp + c.phy.sync + c.phy.reset)
                                     - (pc.phy.inv + pc.phy.disp + pc.phy.sync + pc.phy.reset);
                            phyRate = dPhy >= 0 ? dPhy / dt : 0;
                        }
                        perfPush(cells.thr,  [rMB, wMB], (rMB + wMB).toFixed(1));
                        perfPush(cells.iops, [iops], Math.round(iops).toString());
                        perfPush(cells.util, [util], util.toFixed(0) + '%');
                        perfPush(cells.lat,  [lat], lat.toFixed(1));
                        perfPush(cells.phy,  [phyRate == null ? NaN : phyRate],
                                 phyRate == null ? '–' : phyRate.toFixed(1));
                    }
                    var temp = (c.temp == null) ? null : c.temp;
                    perfPush(cells.temp, [temp == null ? NaN : temp], temp == null ? '–' : temp + '°');
                });
            }
        }
        perfPrev = snap;
    }

    function luMetricsPoll() {
        if (!perfActive) return;
        fetch('/plugins/hbaviewer/ajax_info.php?type=metrics')
          .then(function (r) { return r.json(); })
          .then(function (snap) { if (!perfActive) return; luMetricsRender(snap); perfTimer = setTimeout(luMetricsPoll, 2000); })
          .catch(function () { if (perfActive) perfTimer = setTimeout(luMetricsPoll, 3000); });
    }
    window.luMetricsStart = function () {
        var host = document.getElementById('perf-content');
        if (typeof Chart === 'undefined') { host.innerHTML = '<div class="lu-error">Chart.js failed to load — reinstall the plugin (build.sh bundles chart.umd.min.js).</div>'; return; }
        perfActive = true; luMetricsPoll();
    };
    window.luMetricsStop = function () { perfActive = false; clearTimeout(perfTimer); perfPrev = null; };

    loadOverview();   // fire immediately on page load, then auto-refresh

    // Auto-open tab from URL param (?tab=xxx)
    var urlTab = new URLSearchParams(window.location.search).get('tab');
    if (urlTab && urlTab !== 'overview') { luTab(urlTab); }
})();
