<?PHP
/* HBAviewer HBA Temperature Monitor — main plugin page */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/view.php';
$SCRIPT = '/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh';

$cfg        = lsi_config_read();
$port       = $cfg['HBA_PORT'];
$threshold  = $cfg['ALERT_THRESHOLD'];
$showPcie   = $cfg['SHOW_PCIE'];
$showPhy    = $cfg['SHOW_PHY'];
$showDrives = $cfg['SHOW_DRIVES'];
$showEvents = $cfg['SHOW_EVENTS'];

// Load overview data server-side on page load
// Use timeout to prevent page from hanging indefinitely
if (!file_exists($SCRIPT)) {
    // Check parent directory for debugging
    $dir = dirname($SCRIPT);
    $dir_exists = is_dir($dir);
    $error = "Backend script not found at $SCRIPT. Directory exists: " . ($dir_exists ? 'yes' : 'no');
    $data = null;
} else {
    $raw  = shell_exec('timeout 10 bash ' . escapeshellarg($SCRIPT) . ' 2>&1');
    $data = $raw ? json_decode($raw, true) : null;
    $error = $data['error'] ?? ($raw ? null : 'Backend script produced no output.');
    // If output wasn't valid JSON, it's an error message
    if (!is_array($data) && $raw) {
        $error = 'Backend error: ' . htmlspecialchars(substr($raw, 0, 200));
    }
}
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

<?php if ($error): ?>
  <div class="lu-error"><strong>Error:</strong> <?= htmlspecialchars($error) ?></div>
<?php else:
    $controllers = lsi_controllers($data);
    $backend     = $data['backend'] ?? 'lsiutil';
    $driver      = $data['driver']  ?? '';
    // storcli exposes link/speed/attached-device per phy; lsiutil exposes error counters.
    $phyDesc = $backend === 'storcli'
        ? 'SAS link status, speed, and attached device per physical port'
        : 'SAS link status and error counters per physical port';
?>

<!-- ── Tab bar ───────────────────────────────────────────────────────────── -->
<div class="lu-tabs">
  <button class="lu-tab-btn active" data-tab="overview" onclick="luTab('overview')">Overview</button>
  <?php if ($showPhy):    ?><button class="lu-tab-btn" data-tab="phy"    onclick="luTab('phy')">PHY Health</button><?php endif; ?>
  <?php if ($showDrives): ?><button class="lu-tab-btn" data-tab="drives" onclick="luTab('drives')">Drives</button><?php endif; ?>
  <button class="lu-tab-btn" data-tab="smart" onclick="luTab('smart')">SMART</button>
  <?php if ($showEvents): ?><button class="lu-tab-btn" data-tab="events" onclick="luTab('events')">Event Log</button><?php endif; ?>
  <a href="/Settings/HBAviewer_Settings" style="margin-left:auto;padding:8px 18px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#666;text-decoration:none;" onmouseover="this.style.color='#bbb'" onmouseout="this.style.color='#666'">&#9881; Settings</a>
</div>

<!-- ── Overview tab (one card per controller) ────────────────────────────── -->
<div id="tab-overview" class="lu-tab-pane active">
  <div class="lu-ov-grid">
  <?php foreach ($controllers as $i => $c): ?>
    <?php if (isset($c['error'])): ?>
  <div class="lu-card first"><div class="lu-error"><strong>Controller <?= $i ?>:</strong> <?= htmlspecialchars($c['error']) ?></div></div>
    <?php continue; endif; $v = lsi_hba_view($c, $port, $i); ?>
  <div class="lu-card first" style="--tc:<?= $v['color'] ?>" data-ctl="<?= $i ?>">

    <div class="lu-overview-row">
      <div class="lu-circle" id="lu-circle-<?= $i ?>">
        <span class="val" id="lu-val-<?= $i ?>"><?= $v['temp'] !== '' ? $v['temp'] : 'N/A' ?></span>
        <span class="unit"><?= $v['temp'] !== '' ? '°C' : 'no sensor' ?></span>
      </div>
      <div class="lu-meta">
        <p>Model: <span><?= htmlspecialchars($v['model']) ?></span></p>
        <p>Chip: <span><?= htmlspecialchars($v['chip']) ?></span></p>
        <p>Firmware: <span><?= htmlspecialchars($v['firmware']) ?></span><?php if ($v['fw_old']): ?> <span style="color:#f39c12" title="P20 is the IT-mode baseline for SAS2">⚠ pre-P20</span><?php endif; ?></p>
        <?php if ($v['bios']   !== ''): ?><p>BIOS: <span><?= htmlspecialchars($v['bios']) ?></span></p><?php endif; ?>
        <?php if ($driver      !== ''): ?><p>Driver: <span><?= htmlspecialchars($driver) ?></span></p><?php endif; ?>
        <?php if ($v['mode']   !== ''): ?><p>Mode: <span><?= htmlspecialchars($v['mode']) ?></span></p><?php endif; ?>
        <?php if ($v['drives'] !== ''): ?><p>Drives: <span><?= htmlspecialchars($v['drives']) ?> connected</span></p><?php endif; ?>
        <?php if ($v['port_name'] !== ''): ?><p>lsiutil Port: <span><?= htmlspecialchars($v['port_label']) ?></span></p><?php endif; ?>
        <p>Alert Threshold: <span><?= $threshold ?>°C</span></p>
        <span class="lu-badge" id="lu-badge-<?= $i ?>"><?= $v['label'] ?></span>
      </div>
    </div>

    <?php if ($showPcie && (($c['pcie_width'] ?? '') || ($c['pcie_speed'] ?? ''))): ?>
    <hr class="lu-divider">
    <div class="lu-pcie-row">
      <?php foreach ($v['pcie'] as $item): ?><div class="lu-pcie-item"><?= $item['label'] ?>: <span><?= htmlspecialchars($item['value']) ?></span></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="lu-ts" id="lu-ts-<?= $i ?>">Last read: <?= date('H:i:s') ?></div>
  </div>
  <?php endforeach; ?>
  </div>
</div>

<!-- ── PHY Health tab ────────────────────────────────────────────────────── -->
<?php if ($showPhy): ?>
<div id="tab-phy" class="lu-tab-pane">
  <div class="lu-card first">
    <div class="lu-tab-toolbar">
      <span style="font-size:12px;color:#555;"><?= $phyDesc ?></span>
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

<?php endif; // end !$error ?>

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

    /* ── Overview auto-refresh (temperature only) ─────────────────────────── */
    function refreshOverview() {
        fetch('/plugins/hbaviewer/ajax_info.php?type=overview')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error || !d.controllers) return;
                // color/label computed server-side (view.php) — no client-side status map
                d.controllers.forEach(function (ctl, i) {
                    if (ctl.error) return;
                    var circle = document.getElementById('lu-circle-' + i);
                    var val    = document.getElementById('lu-val-' + i);
                    var badge  = document.getElementById('lu-badge-' + i);
                    var ts     = document.getElementById('lu-ts-' + i);

                    if (circle) circle.style.setProperty('--tc', ctl.color);
                    if (val)    val.textContent = ctl.temp;
                    if (badge)  { badge.textContent = ctl.label; badge.style.background = ctl.color; }
                    if (ts)     ts.textContent = 'Last read: ' + new Date().toLocaleTimeString();
                });
            })
            .catch(function () {});

        clearTimeout(timer);
        timer = setTimeout(refreshOverview, REFRESH_MS);
    }

    timer = setTimeout(refreshOverview, REFRESH_MS);

    // Auto-open tab from URL param (?tab=xxx)
    var urlTab = new URLSearchParams(window.location.search).get('tab');
    if (urlTab && urlTab !== 'overview') { luTab(urlTab); }
})();
</script>
