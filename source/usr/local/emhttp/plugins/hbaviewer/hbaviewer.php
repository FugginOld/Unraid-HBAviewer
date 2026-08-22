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
$showPerf    = $cfg['SHOW_PERF'];
/* Unraid requires its CSRF token on POST, and this page has three writers: the
   bay map, Locate and the PHY baseline reset. Read UNCONDITIONALLY.

   It used to sit behind `if ($enableFlash)`, which was defensible while the
   flash tab lived here and was the loudest of the writers. It is not any more:
   with flashing off the token came out empty and every write depended on
   Unraid's own `csrf_token` JS global still being defined. That is a fallback,
   not a guarantee. The array-state read left with the flash view (plan 055) —
   flash_view.php does its own. */
$vi        = @parse_ini_file('/var/local/emhttp/var.ini');
$csrfToken = is_array($vi) ? (string) ($vi['csrf_token'] ?? '') : '';
?>

<link rel="stylesheet" href="/plugins/hbaviewer/tokens.css?v=<?= (int) @filemtime(__DIR__ . '/tokens.css') ?>">
<link rel="stylesheet" href="/plugins/hbaviewer/chrome.css?v=<?= (int) @filemtime(__DIR__ . '/chrome.css') ?>">

<div id="lu-wrap">

<?php require __DIR__ . '/icons.php'; ?>

<!-- ── Tab bar ───────────────────────────────────────────────────────────── -->
<!-- role=tablist, and the two buttons at the end that NAVIGATE rather than
     switch a pane carry role=link instead of role=tab: a screen reader that
     announces "tab 9 of 10" and then leaves the page is worse than no
     grouping at all. Arrow-key movement and the roving tabindex live in
     luTab()/luTabKey() in hbaviewer.js. -->
<div class="lu-tabs" role="tablist" aria-label="HBAviewer views" onkeydown="luTabKey(event)">
  <button class="lu-tab-btn active" type="button" role="tab" id="tabbtn-overview" aria-controls="tab-overview" aria-selected="true" data-tab="overview" onclick="luTab('overview')">Overview</button>
  <button class="lu-tab-btn" type="button" role="tab" id="tabbtn-health" aria-controls="tab-health" aria-selected="false" tabindex="-1" data-tab="health" onclick="luTab('health')">HBA Health</button>
  <?php if ($showPhy):    ?><button class="lu-tab-btn" type="button" role="tab" id="tabbtn-phy" aria-controls="tab-phy" aria-selected="false" tabindex="-1" data-tab="phy"    onclick="luTab('phy')">PHY Health</button><?php endif; ?>
  <?php if ($showDrives): ?><button class="lu-tab-btn" type="button" role="tab" id="tabbtn-drives" aria-controls="tab-drives" aria-selected="false" tabindex="-1" data-tab="drives" onclick="luTab('drives')">Drives</button><?php endif; ?>
  <?php /* Same payload as Drives, arranged as the chassis — so it follows the
           same toggle rather than adding a second setting for one data source. */ ?>
  <?php if ($showDrives): ?><button class="lu-tab-btn" type="button" role="tab" id="tabbtn-baymap" aria-controls="tab-baymap" aria-selected="false" tabindex="-1" data-tab="baymap" onclick="luTab('baymap')">Array Map</button><?php endif; ?>
  <button class="lu-tab-btn" type="button" role="tab" id="tabbtn-smart" aria-controls="tab-smart" aria-selected="false" tabindex="-1" data-tab="smart" onclick="luTab('smart')">SMART</button>
  <?php if ($showEvents): ?><button class="lu-tab-btn" type="button" role="tab" id="tabbtn-events" aria-controls="tab-events" aria-selected="false" tabindex="-1" data-tab="events" onclick="luTab('events')">Event Log</button><?php endif; ?>
  <?php if ($showPerf):   ?><button class="lu-tab-btn" type="button" role="tab" id="tabbtn-perf" aria-controls="tab-perf" aria-selected="false" tabindex="-1" data-tab="perf"   onclick="luTab('perf')">Performance</button><?php endif; ?>
  <?php /* Firmware sits at the end of the strip, red, and only once the user
           has ticked the box in Settings and the maintainer lock is off — so it
           cannot appear on a stock install and cannot appear on a locked one.

           Plan 055 kept it off this strip entirely, reasoning that reaching the
           flasher should mean passing the danger notice on the Settings page.
           That held while the alternative was a Utilities icon that skipped the
           notice; it does not hold against a tab that is invisible until you
           have already read the notice and opted in. It is a link, not a pane:
           the flash page owns its own CSS, JS and array-state read, and
           inlining it here would load all three on every Monitor visit. */ ?>
  <?php /* A <button> and not an <a>, even though it navigates: Unraid's theme
           styles the tab strip by element, so an anchor here rendered as bare
           text beside the filled pills of every real tab. No colour of its own
           -- the warning sign carries the meaning, and fighting whichever theme
           the user picked is not worth the CSS. */ ?>
  <?php if (!LSI_FLASH_LOCKED && (int) $cfg['ENABLE_FLASH'] === 1): ?>
  <button class="lu-tab-btn" type="button" role="link"
          onclick="location.href='/Tools/HBAviewer_Flash'"><svg class="lu-i" aria-hidden="true"><use href="#lu-i-warn"/></svg> Firmware</button>
  <?php endif; ?>
  <button class="lu-tab-btn lu-tab-right" type="button" role="link"
          onclick="location.href='/Settings/HBAviewer_Settings'"><svg class="lu-i" aria-hidden="true"><use href="#lu-i-settings"/></svg> Settings</button>
</div>

<!-- ── Overview tab (loaded via AJAX; banner shows until hardware read done) ─ -->
<div id="tab-overview" class="lu-tab-pane active" role="tabpanel" aria-labelledby="tabbtn-overview">
  <div id="overview-content"><div class="lu-loading">Loading HBA information… (first read can take up to 60 seconds)</div></div>
</div>

<!-- ── HBA Health tab (five sub-indicators + a worst-of rollup; no config toggle) -->
<div id="tab-health" class="lu-tab-pane" role="tabpanel" aria-labelledby="tabbtn-health">
  <div class="lu-tab-toolbar">
    <span style="font-size:12px;color:var(--text);">Thermal, link integrity, topology, host link, and read health — each judged independently</span>
    <button class="lu-refresh-btn" onclick="luReloadTab('health')">Refresh</button>
  </div>
  <div id="health-content"><div class="lu-loading">Loading…</div></div>
</div>

<!-- ── PHY Health tab ────────────────────────────────────────────────────── -->
<?php if ($showPhy): ?>
<div id="tab-phy" class="lu-tab-pane" role="tabpanel" aria-labelledby="tabbtn-phy">
  <div class="lu-tab-toolbar">
    <span style="font-size:12px;color:var(--text);">SAS link status, speed, and error counters per physical port</span>
    <button class="lu-refresh-btn" onclick="luReloadTab('phy')">Refresh</button>
  </div>
  <div id="phy-content"><div class="lu-loading">Loading…</div></div>
</div>
<?php endif; ?>

<!-- ── Drives tab ────────────────────────────────────────────────────────── -->
<?php if ($showDrives): ?>
<div id="tab-drives" class="lu-tab-pane" role="tabpanel" aria-labelledby="tabbtn-drives">
  <div class="lu-tab-toolbar">
    <span style="font-size:12px;color:var(--text);">Devices attached to the HBA</span>
    <button class="lu-refresh-btn" onclick="luReloadTab('drives')">Refresh</button>
  </div>
  <div id="drives-content"><div class="lu-loading">Loading…</div></div>
</div>
<?php endif; ?>

<!-- ── Array Map tab (plan 047): the same drives, arranged as the chassis ─── -->
<?php if ($showDrives): ?>
<div id="tab-baymap" class="lu-tab-pane" role="tabpanel" aria-labelledby="tabbtn-baymap">
  <div class="lu-tab-toolbar">
    <!-- Both spans wrap in one flex child so the toolbar still has exactly two,
         and space-between keeps Refresh pinned right as the hint text changes. -->
    <div class="lu-bay-head">
      <span style="font-size:12px;color:var(--text);">Where each drive physically sits — you place them once, the map remembers</span>
      <span id="bay-hint" class="lu-bay-hint"></span>
    </div>
    <button class="lu-refresh-btn" onclick="luBayFetch()">Refresh</button>
  </div>
  <div id="baymap-content"><div class="lu-loading">Loading…</div></div>
</div>
<?php endif; ?>

<!-- ── Event Log tab ─────────────────────────────────────────────────────── -->
<?php if ($showEvents): ?>
<div id="tab-events" class="lu-tab-pane" role="tabpanel" aria-labelledby="tabbtn-events">
  <div class="lu-tab-toolbar">
    <span style="font-size:12px;color:var(--text);">HBA firmware event log (newest first)</span>
    <span>
      <button class="lu-refresh-btn" onclick="luCopy('events', this)">Copy</button>
      <button class="lu-refresh-btn" onclick="luReloadTab('events')">Refresh</button>
    </span>
  </div>
  <div id="events-content"><div class="lu-loading">Loading…</div></div>
</div>
<?php endif; ?>

<!-- ── SMART tab (all drives, collected in the background) ────────────────── -->
<div id="tab-smart" class="lu-tab-pane" role="tabpanel" aria-labelledby="tabbtn-smart">
  <div class="lu-card first">
    <div class="lu-tab-toolbar">
      <span style="font-size:12px;color:var(--text);">Per-drive SMART health — collected in the background (safe: never wakes a standby drive)</span>
      <button class="lu-refresh-btn" onclick="luSmartAll(true)">Refresh</button>
    </div>
    <div id="smart-content"><div class="lu-loading">Loading…</div></div>
  </div>
</div>

<!-- ── Performance tab (real-time graphs; in-browser history only) ────────── -->
<?php if ($showPerf): ?>
<div id="tab-perf" class="lu-tab-pane" role="tabpanel" aria-labelledby="tabbtn-perf">
  <div class="lu-tab-toolbar">
    <span style="font-size:12px;color:var(--text);">Real-time throughput / IOPS / %util / latency / PHY-error-rate / temp &middot; sampled ~2s in your browser (last ~5&nbsp;min; resets on reload)</span>
  </div>
  <div id="perf-content"><div class="lu-loading">Waiting for first samples…</div></div>
</div>
<?php endif; ?>

<!-- ── Firmware/BIOS Update tab (opt-in; hidden unless ENABLE_FLASH) ──────── -->

</div><!-- #lu-wrap -->

<?php if ($showPerf): ?><script src="/plugins/hbaviewer/chart.umd.min.js"></script><?php endif; ?>
<script>

    /* Unraid rejects POSTs without its CSRF token. Prefer Unraid's own fresh JS
       global; fall back to the token read from var.ini at render time.

       This is NOT flash state, despite having been declared inside the flash
       block and named for it until plan 055. The bay map, Locate and the PHY
       baseline reset all post with it, and moving it out with the rest of the
       flash JS broke every write on this page while the page still rendered
       perfectly — which is exactly how this would reach a user unnoticed.
       Renamed off `flashCsrf` so nothing here is named after code that no
       longer lives here. */
    var luCsrf = (typeof csrf_token !== 'undefined' && csrf_token) ? csrf_token : '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
</script>
<script src="/plugins/hbaviewer/hbaviewer.js?v=<?= (int) @filemtime(__DIR__ . '/hbaviewer.js') ?>"></script>
