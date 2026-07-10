<?PHP
/* HBAviewer HBA Temperature Monitor — main plugin page */

require_once __DIR__ . '/config.php';

// Only config is read server-side (instant). The hardware read is deferred to
// AJAX (ajax_info.php?type=overview_html) so the page shell paints immediately
// and shows a "Loading HBA information" banner instead of blocking for storcli.
$cfg         = lsi_config_read();
$showPhy     = $cfg['SHOW_PHY'];
$showDrives  = $cfg['SHOW_DRIVES'];
$showEvents  = $cfg['SHOW_EVENTS'];
$enableFlash = $cfg['ENABLE_FLASH'];
// Array must be stopped before flashing. Read the state once (cheap, no hardware);
// the flash.php preflight is the authoritative gate — this banner is advisory.
$arrayStopped = false;
if ($enableFlash) {
    $vi = @parse_ini_file('/var/local/emhttp/var.ini');
    $arrayStopped = is_array($vi) && strtoupper((string) ($vi['mdState'] ?? '')) === 'STOPPED';
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

/* ── Firmware/BIOS flash tab ─────────────────────────────────────────────── */
.lu-flash-warn { background:#2a1414; border:1px solid #7a2020; border-radius:6px; color:#e88; font-size:13px; line-height:1.5; padding:12px 16px; margin-bottom:14px; }
.lu-flash-warn strong { color:#ff6b6b; }
.lu-flash-array { border-radius:6px; font-size:13px; padding:10px 16px; margin-bottom:16px; }
.lu-flash-array.ok  { background:#14240f; border:1px solid #2a4a1f; color:#9c9; }
.lu-flash-array.bad { background:#241a0f; border:1px solid #5a3f1f; color:#dba24a; }
.lu-fc { border:1px solid #333; border-radius:6px; padding:16px 18px; margin-bottom:16px; }
.lu-fc h4 { margin:0 0 4px; color:#f5a623; font-size:13px; }
.lu-fc .sub { color:#888; font-size:12px; margin:0 0 14px; }
.lu-fstep { margin:14px 0; }
.lu-fstep label.step { display:block; color:#aaa; font-size:11px; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:6px; }
.lu-fc input[type=file] { color:#bbb; font-size:12px; }
.lu-fc input[type=text] { background:#111; border:1px solid #3a3a3a; border-radius:4px; color:#ddd; padding:6px 9px; font-size:13px; width:120px; }
.lu-fc pre { background:#0d0d0d; border:1px solid #222; border-radius:4px; color:#bbb; font-size:11px; line-height:1.4; max-height:280px; overflow:auto; padding:10px; margin:8px 0 0; white-space:pre-wrap; }
.lu-fbtn { background:#f5a623; border:none; border-radius:4px; color:#111; font-size:12px; font-weight:700; padding:7px 16px; cursor:pointer; }
.lu-fbtn:hover { background:#d9901a; }
.lu-fbtn.danger { background:#c0392b; color:#fff; }
.lu-fbtn.danger:hover { background:#a5281b; }
.lu-fack { display:flex; align-items:center; gap:8px; color:#ddd; font-size:12px; margin:8px 0; }
</style>

<div id="lu-wrap">

<!-- ── Tab bar ───────────────────────────────────────────────────────────── -->
<div class="lu-tabs">
  <button class="lu-tab-btn active" data-tab="overview" onclick="luTab('overview')">Overview</button>
  <?php if ($showPhy):    ?><button class="lu-tab-btn" data-tab="phy"    onclick="luTab('phy')">PHY Health</button><?php endif; ?>
  <?php if ($showDrives): ?><button class="lu-tab-btn" data-tab="drives" onclick="luTab('drives')">Drives</button><?php endif; ?>
  <button class="lu-tab-btn" data-tab="smart" onclick="luTab('smart')">SMART</button>
  <?php if ($showEvents): ?><button class="lu-tab-btn" data-tab="events" onclick="luTab('events')">Event Log</button><?php endif; ?>
  <?php if ($enableFlash): ?><button class="lu-tab-btn" data-tab="flash" onclick="luTab('flash')">Firmware/BIOS Update</button><?php endif; ?>
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

<!-- ── Firmware/BIOS Update tab (opt-in; hidden unless ENABLE_FLASH) ──────── -->
<?php if ($enableFlash): ?>
<div id="tab-flash" class="lu-tab-pane">
  <div class="lu-card first">
    <div class="lu-flash-warn">
      <strong>&#9888; Firmware / BIOS flashing.</strong> A wrong or mismatched image
      will <strong>permanently brick</strong> your controller. Verify the image
      matches your exact card and chip. The array must be stopped. Proceed entirely
      at your own risk.
    </div>
    <div class="lu-flash-array <?= $arrayStopped ? 'ok' : 'bad' ?>">
      <?php if ($arrayStopped): ?>
        Array is <strong>STOPPED</strong> — safe to flash.
      <?php else: ?>
        Array is <strong>NOT stopped</strong> — stop it on the Main tab, then reload
        this page. Flashing is blocked by the server until the array is stopped.
      <?php endif; ?>
    </div>
    <div id="flash-content"><div class="lu-loading">Loading controllers…</div></div>
  </div>
</div>
<?php endif; ?>

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
        } else if (name === 'flash') {
            if (!loaded['flash']) luFlashInit();
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

    /* ── Firmware/BIOS flash tab ─────────────────────────────────────────────
       Opt-in, single-flight (one flash at a time, enforced server-side). This UI
       drives flash.php; every real guard (array stopped, confirm, lock) is
       re-checked on the server, so the JS checks here are only fast feedback. */
    var flashArrayStopped = <?= $arrayStopped ? 'true' : 'false' ?>;
    function fesc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
    function flashCard(i){ return document.querySelector('.lu-fc[data-ctl="'+i+'"]'); }
    function flashChip(i){ var c=flashCard(i); return c?c.getAttribute('data-chip'):''; }

    window.luFlashInit = function () {
        var el = document.getElementById('flash-content');
        if (!el) return;
        fetch('/plugins/hbaviewer/ajax_info.php?type=overview')
          .then(function(r){ return r.json(); })
          .then(function(d){
            var ctls = (d && d.controllers) || [];
            if (!ctls.length) { el.innerHTML = '<div class="lu-error">No controllers detected (or backend error).</div>'; return; }
            el.innerHTML = ctls.map(function(c,i){
              if (c.error) return '<div class="lu-fc"><h4>Controller /c'+i+'</h4><div class="lu-error">'+fesc(c.error)+'</div></div>';
              var chip = c.model || '';
              return '<div class="lu-fc" data-ctl="'+i+'" data-chip="'+fesc(chip)+'">'
                + '<h4>Controller /c'+i+' — '+fesc(chip||'unknown chip')+'</h4>'
                + '<p class="sub">Current firmware: '+fesc(c.firmware||'?')+(c.bios?' · BIOS: '+fesc(c.bios):'')+'</p>'
                + '<div class="lu-fstep"><label class="step">Step 1 — verify the flash tool sees this card</label>'
                +   '<button class="lu-fbtn" onclick="luFlashList('+i+')">Run listall</button>'
                +   '<pre id="flash-list-'+i+'" style="display:none"></pre></div>'
                + '<div class="lu-fstep"><label class="step">Step 2 — upload the model-correct image (+ optional BIOS / tool)</label>'
                +   'Firmware (.bin/.rom): <input type="file" id="flash-fw-'+i+'"><br><br>'
                +   'BIOS (optional, .rom): <input type="file" id="flash-bios-'+i+'"><br><br>'
                +   'Flash tool if not installed (sas2flash/sas3flash): <input type="file" id="flash-tool-'+i+'"> '
                +   '<button class="lu-fbtn" onclick="luFlashUpload('+i+')">Upload</button> '
                +   '<span id="flash-up-'+i+'" style="font-size:12px"></span></div>'
                + '<div class="lu-fstep"><label class="step">Step 3 — confirm &amp; flash</label>'
                +   '<label class="lu-fack"><input type="checkbox" id="flash-ack-'+i+'"> I understand a wrong image can permanently brick this controller.</label>'
                +   'Type <strong>FLASH</strong>: <input type="text" id="flash-confirm-'+i+'" placeholder="FLASH"> '
                +   '<button class="lu-fbtn danger" onclick="luFlashGo('+i+')">Flash /c'+i+'</button></div>'
                + '<pre id="flash-log-'+i+'" style="display:none"></pre>'
                + '</div>';
            }).join('');
            loaded['flash'] = true;
          })
          .catch(function(){ el.innerHTML = '<div class="lu-error">Failed to load controllers.</div>'; });
    };

    window.luFlashList = function (i) {
        var pre = document.getElementById('flash-list-'+i);
        pre.style.display='block'; pre.textContent='Running…';
        fetch('/plugins/hbaviewer/flash.php', {method:'POST', body:new URLSearchParams({action:'listall', chip:flashChip(i), ctl:i})})
          .then(function(r){ return r.text(); })
          .then(function(t){ pre.textContent = t || '(no output)'; })
          .catch(function(){ pre.textContent='Request failed.'; });
    };

    window.luFlashUpload = function (i) {
        var out = document.getElementById('flash-up-'+i); out.style.color='#888'; out.textContent='Uploading…';
        var fw=document.getElementById('flash-fw-'+i).files[0];
        var bios=document.getElementById('flash-bios-'+i).files[0];
        var tool=document.getElementById('flash-tool-'+i).files[0];
        if (!fw && !tool) { out.style.color='#e88'; out.textContent='Choose a firmware file first.'; return; }
        var fd = new FormData(); fd.append('action','upload');
        if (fw) fd.append('firmware', fw);
        if (bios) fd.append('bios', bios);
        if (tool) fd.append('tool', tool);
        fetch('/plugins/hbaviewer/flash.php', {method:'POST', body:fd})
          .then(function(r){ return r.json(); })
          .then(function(d){
            if (d.error) { out.style.color='#e88'; out.textContent=d.error; return; }
            var c=flashCard(i);
            if (d.firmware) c.setAttribute('data-fw', d.firmware);
            if (d.bios) c.setAttribute('data-bios', d.bios);
            out.style.color='#9c9';
            out.textContent='Stored: '+[d.firmware, d.bios, d.tool?('tool '+d.tool):''].filter(Boolean).join(', ');
          })
          .catch(function(){ out.style.color='#e88'; out.textContent='Upload failed.'; });
    };

    window.luFlashGo = function (i) {
        var log = document.getElementById('flash-log-'+i);
        var c = flashCard(i);
        var fw = c.getAttribute('data-fw'); var bios = c.getAttribute('data-bios') || '';
        var ack = document.getElementById('flash-ack-'+i).checked;
        var confirmTxt = document.getElementById('flash-confirm-'+i).value;
        if (!flashArrayStopped) { alert('The array is not stopped. Stop it on the Main tab and reload this page.'); return; }
        if (!ack) { alert('Tick the acknowledgement box first.'); return; }
        if (confirmTxt !== 'FLASH') { alert('Type FLASH (all caps) to confirm.'); return; }
        if (!fw) { alert('Upload a firmware image first.'); return; }
        if (!window.confirm('FINAL confirmation: flash controller '+i+' now?\n\nThis can brick the card if the image is wrong. Do not power off or reboot until it finishes.')) return;
        log.style.display='block'; log.textContent='Starting flash…';
        fetch('/plugins/hbaviewer/flash.php', {method:'POST', body:new URLSearchParams({action:'flash', chip:flashChip(i), ctl:i, firmware:fw, bios:bios, confirm:confirmTxt})})
          .then(function(r){ return r.json(); })
          .then(function(d){
            if (d.error) { log.textContent='Refused: '+d.error; return; }
            luFlashPoll(i);
          })
          .catch(function(){ log.textContent='Request failed.'; });
    };

    window.luFlashPoll = function (i) {
        var log = document.getElementById('flash-log-'+i);
        fetch('/plugins/hbaviewer/flash.php?action=status')
          .then(function(r){ return r.json(); })
          .then(function(d){
            log.textContent = d.log || '(waiting for output…)';
            if (d.running) { setTimeout(function(){ luFlashPoll(i); }, 2000); return; }
            if (d.done === 'success') log.textContent += '\n\n✔ Flash completed. REBOOT the server to load the new firmware. (Linux flashers update the BIOS but cannot erase it.)';
            else if (d.done === 'error') log.textContent += '\n\n✖ Flash tool exited with an error (code '+d.exit+'). Read the log above; do NOT reboot — reflash the correct image first.';
          })
          .catch(function(){ log.textContent += '\n(status poll failed — retrying)'; setTimeout(function(){ luFlashPoll(i); }, 3000); });
    };

    loadOverview();   // fire immediately on page load, then auto-refresh

    // Auto-open tab from URL param (?tab=xxx)
    var urlTab = new URLSearchParams(window.location.search).get('tab');
    if (urlTab && urlTab !== 'overview') { luTab(urlTab); }
})();
</script>
