<?PHP
/* HBAviewer HBA Temperature Monitor — main plugin page */

require_once __DIR__ . '/config.php';

// Only config is read server-side (instant). The hardware read is deferred to
// AJAX (ajax_info.php?type=overview_html) so the page shell paints immediately
// and shows a "Loading HBA information" banner instead of blocking for storcli.
$cfg        = lsi_config_read();
$showPhy    = $cfg['SHOW_PHY'];
$showDrives = $cfg['SHOW_DRIVES'];
$showEvents = $cfg['SHOW_EVENTS'];
?>

<style>
/* ── Layout ──────────────────────────────────────────────────────────────── */
#lu-wrap { font-family: inherit; max-width: 1280px; margin: 20px auto; }
/* Overview cards span the full width too, matching the data tables. */

/* ── Tabs ────────────────────────────────────────────────────────────────── */
.lu-tabs { display: flex; gap: 2px; margin-bottom: 0; border-bottom: 2px solid #2a2a2a; }
.lu-tab-btn {
    padding: 8px 18px;
    background: #141414;
    border: 1px solid #2a2a2a;
    border-bottom: none;
    border-radius: 5px 5px 0 0;
    color: #666;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    transition: color 0.15s;
}
.lu-tab-btn:hover  { color: #bbb; }
.lu-tab-btn.active { background: #1c1c1c; border-bottom-color: #1c1c1c; color: #f5a623; }
.lu-tab-pane { display: none; }
.lu-tab-pane.active { display: block; }

/* ── Cards ───────────────────────────────────────────────────────────────── */
.lu-card {
    background: #1c1c1c;
    border: 1px solid #333;
    border-top: none;
    border-radius: 0 6px 6px 6px;
    padding: 20px 24px;
    margin-bottom: 16px;
}
.lu-card.first { border-radius: 0 0 6px 6px; }
.lu-card h3 {
    margin: 0 0 14px;
    color: #bbb;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    border-bottom: 1px solid #2a2a2a;
    padding-bottom: 8px;
}
.lu-divider { border: none; border-top: 1px solid #2a2a2a; margin: 16px 0; }

/* ── Temperature display ─────────────────────────────────────────────────── */
/* Controllers side by side, splitting the width; a lone card is capped + centered. */
.lu-ov-grid { display: flex; gap: 16px; flex-wrap: wrap; justify-content: center; }
.lu-ov-grid .lu-card { flex: 1 1 380px; max-width: 700px; margin-bottom: 0; }
.lu-overview-row { display: flex; align-items: center; justify-content: center; gap: 24px; }
.lu-circle {
    width: 96px; height: 96px;
    border-radius: 50%;
    border: 4px solid var(--tc, #2ecc71);
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    flex-shrink: 0;
    transition: border-color 0.3s;
}
.lu-circle .val  { font-size: 30px; font-weight: 700; color: var(--tc, #2ecc71); line-height: 1; }
.lu-circle .unit { font-size: 12px; color: #666; margin-top: 3px; }
.lu-meta p       { margin: 4px 0; font-size: 13px; color: #888; }
.lu-meta p span  { color: #ddd; font-weight: 500; }
.lu-badge {
    display: inline-block; margin-top: 6px;
    padding: 2px 12px; border-radius: 12px;
    font-size: 11px; font-weight: 700; letter-spacing: 0.05em;
    background: var(--tc, #2ecc71); color: #111;
    transition: background 0.3s;
}

/* ── PCIe row ────────────────────────────────────────────────────────────── */
.lu-pcie-row { display: flex; justify-content: center; gap: 24px; flex-wrap: wrap; }
.lu-pcie-item { font-size: 13px; color: #888; }
.lu-pcie-item span { color: #ddd; font-weight: 500; }

/* ── Tables (shared between tabs) ────────────────────────────────────────── */
.lu-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.lu-table th {
    text-align: left; padding: 6px 10px;
    color: #777; font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.05em;
    border-bottom: 1px solid #2a2a2a;
}
.lu-table td { padding: 7px 10px; color: #ccc; border-bottom: 1px solid #1a1a1a; }
.lu-table tr:last-child td { border-bottom: none; }
.lu-table code { color: #88aaff; font-size: 12px; }

/* ── Link badges ─────────────────────────────────────────────────────────── */
.lu-link-up   { color: #2ecc71; font-weight: 700; font-size: 11px; }
.lu-link-down { color: #e74c3c; font-weight: 700; font-size: 11px; }
.lu-err-val   { color: #f39c12; font-weight: 600; }

/* ── Misc ────────────────────────────────────────────────────────────────── */
.lu-error {
    background: #1e0e0e; border: 1px solid #7a2020;
    border-radius: 6px; padding: 14px 18px;
    color: #d88; font-size: 13px; margin-bottom: 12px;
}
.lu-muted  { color: #555; font-size: 13px; }
.lu-ts     { font-size: 11px; color: #444; text-align: right; margin-top: 10px; }
.lu-loading { color: #555; font-size: 13px; padding: 20px 0; text-align: center; }
.lu-tab-toolbar {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 12px;
}
.lu-refresh-btn {
    background: transparent; border: 1px solid #444;
    border-radius: 4px; color: #aaa;
    font-size: 11px; font-weight: 600; padding: 5px 12px;
    cursor: pointer; text-transform: uppercase; letter-spacing: 0.05em;
}
.lu-refresh-btn:hover { border-color: #888; color: #ddd; }
</style>

<div id="lu-wrap">

<!-- ── Tab bar ───────────────────────────────────────────────────────────── -->
<div class="lu-tabs">
  <button class="lu-tab-btn active" data-tab="overview" onclick="luTab('overview')">Overview</button>
  <?php if ($showPhy):    ?><button class="lu-tab-btn" data-tab="phy"    onclick="luTab('phy')">PHY Health</button><?php endif; ?>
  <?php if ($showDrives): ?><button class="lu-tab-btn" data-tab="drives" onclick="luTab('drives')">Drives</button><?php endif; ?>
  <button class="lu-tab-btn" data-tab="smart" onclick="luTab('smart')">SMART</button>
  <?php if ($showEvents): ?><button class="lu-tab-btn" data-tab="events" onclick="luTab('events')">Event Log</button><?php endif; ?>
  <a href="/Settings/HBAviewer_Settings" style="margin-left:auto;padding:8px 18px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#666;text-decoration:none;" onmouseover="this.style.color='#bbb'" onmouseout="this.style.color='#666'">&#9881; Settings</a>
</div>

<!-- ── Overview tab (loaded via AJAX; banner shows until hardware read done) ─ -->
<div id="tab-overview" class="lu-tab-pane active">
  <div id="overview-content"><div class="lu-loading">Loading HBA information… (first read can take up to 60 seconds)</div></div>
</div>

<!-- ── PHY Health tab ────────────────────────────────────────────────────── -->
<?php if ($showPhy): ?>
<div id="tab-phy" class="lu-tab-pane">
  <div class="lu-card first">
    <div class="lu-tab-toolbar">
      <span style="font-size:12px;color:#555;">SAS link status, speed, and error counters per physical port</span>
      <button class="lu-refresh-btn" onclick="luReloadTab('phy')">Refresh</button>
    </div>
    <div id="phy-content"><div class="lu-loading">Loading…</div></div>
  </div>
</div>
<?php endif; ?>

<!-- ── Drives tab ────────────────────────────────────────────────────────── -->
<?php if ($showDrives): ?>
<div id="tab-drives" class="lu-tab-pane">
  <div class="lu-card first">
    <div class="lu-tab-toolbar">
      <span style="font-size:12px;color:#555;">Devices attached to the HBA</span>
      <button class="lu-refresh-btn" onclick="luReloadTab('drives')">Refresh</button>
    </div>
    <div id="drives-content"><div class="lu-loading">Loading…</div></div>
  </div>
</div>
<?php endif; ?>

<!-- ── Event Log tab ─────────────────────────────────────────────────────── -->
<?php if ($showEvents): ?>
<div id="tab-events" class="lu-tab-pane">
  <div class="lu-card first">
    <div class="lu-tab-toolbar">
      <span style="font-size:12px;color:#555;">HBA firmware event log (newest first)</span>
      <span>
        <button class="lu-refresh-btn" onclick="luCopy('events', this)">Copy</button>
        <button class="lu-refresh-btn" onclick="luReloadTab('events')">Refresh</button>
      </span>
    </div>
    <div id="events-content"><div class="lu-loading">Loading…</div></div>
  </div>
</div>
<?php endif; ?>

<!-- ── SMART tab (all drives, collected in the background) ────────────────── -->
<div id="tab-smart" class="lu-tab-pane">
  <div class="lu-card first">
    <div class="lu-tab-toolbar">
      <span style="font-size:12px;color:#555;">Per-drive SMART health — collected in the background (safe: never wakes a standby drive)</span>
      <button class="lu-refresh-btn" onclick="luSmartAll(true)">Refresh</button>
    </div>
    <div id="smart-content"><div class="lu-loading">Loading…</div></div>
  </div>
</div>

</div><!-- #lu-wrap -->

<script>
(function () {
    var REFRESH_MS = 60000;
    var timer;
    var smartTimer;
    var loaded = {};

    /* ── Tab switching ────────────────────────────────────────────────────── */
    window.luTab = function (name) {
        document.querySelectorAll('.lu-tab-btn').forEach(function (b) {
            b.classList.toggle('active', b.dataset.tab === name);
        });
        document.querySelectorAll('.lu-tab-pane').forEach(function (p) {
            p.classList.toggle('active', p.id === 'tab-' + name);
        });
        if (name === 'smart') {
            luSmartAll(false);
        } else if (name !== 'overview' && !loaded[name]) {
            luReloadTab(name);
        }
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
            })
            .catch(function () {
                el.innerHTML = '<div class="lu-error">Request failed.</div>';
            });
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

    /* ── Overview: full card HTML via AJAX (banner shows until the read done) ── */
    function loadOverview() {
        var el = document.getElementById('overview-content');
        if (!el) return;
        fetch('/plugins/hbaviewer/ajax_info.php?type=overview_html')
            .then(function (r) { return r.text(); })
            .then(function (html) { el.innerHTML = html; })
            .catch(function () {
                el.innerHTML = '<div class="lu-error">Request failed — the backend may still be reading the controller. It will retry shortly.</div>';
            });
        clearTimeout(timer);
        timer = setTimeout(loadOverview, REFRESH_MS);
    }

    loadOverview();   // fire immediately on page load, then auto-refresh

    // Auto-open tab from URL param (?tab=xxx)
    var urlTab = new URLSearchParams(window.location.search).get('tab');
    if (urlTab && urlTab !== 'overview') { luTab(urlTab); }
})();
</script>
