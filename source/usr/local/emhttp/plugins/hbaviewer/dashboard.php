<?PHP
/* HBAviewer dashboard tile — Unraid 7.2+ tile format.
   Mirrors the Overview tab layout: circle gauge + card info + PCIe row.
   Result cached in /tmp for 60 s to avoid hardware reads on every page load. */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/view.php';
$pluginname = 'HBAviewer';
$SCRIPT  = '/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh';

$cfg       = lsi_config_read();
$port      = $cfg['HBA_PORT'];
$threshold = $cfg['ALERT_THRESHOLD'];
// Display only — $threshold above stays °C for the band-matching logic
// elsewhere; $unitSym / the *_disp values below are what gets printed.
$unit      = (int) $cfg['TEMP_UNIT'];
$unitSym   = $unit === 1 ? '°F' : '°C';
$thresholdDisp = lsi_temp_convert($threshold, $unit);

// get_hba_info.sh self-caches (60s), so this stays cheap on every tile refresh.
// Increased timeout to 60s for slow storcli systems; script has 60s cache so usually faster
$data = null;
if (file_exists($SCRIPT)) {
    $raw = shell_exec('timeout 60 bash ' . escapeshellarg($SCRIPT) . ' 2>/dev/null') ?? '';
    $data = $raw ? json_decode($raw, true) : null;
}

if (!is_array($data)) {
    $error = 'Backend unavailable';
} else {
    $error = $data['error'] ?? null;
}
$controllers = $error ? [] : lsi_controllers($data);
if (!$error && !$controllers) $error = 'Backend unavailable';

$ts = lsi_time();

// Scoped styles. Per-controller color is inline (each circle/badge can differ).
echo <<<CSS
<style>
/* Chrome tokens follow Unraid's theme variables (confirmed present on
   white/black/gray/azure — see plan 021); each keeps its original literal as
   the CSS fallback so a missing variable renders exactly as before.
   --tc / --sc (set inline per-tile) carry STATUS, not chrome — untouched. */
.lu-d-tile {
  --d-bg:     var(--shade-bg-color, #1c1c1c);
  --d-border: var(--border-color, #2a2a2a);
  --d-text:   var(--text-color, #ddd);
  /* Body-text variant of the alert colour (matches lsi_status_color('alert') /
     view.php's #e74c3c). As TEXT the raw colour measures ~2:1 on a light theme's
     card; mixing 50% toward --text-color lands 4.6-10.2:1 in every theme. */
  --crit-text: color-mix(in srgb, #e74c3c 50%, var(--text-color, #ddd));
}
.lu-d-tile .lu-d-ctl { padding-top:16px; margin-top:16px; border-top:1px solid var(--d-border); }
.lu-d-tile .lu-d-ctl:first-child { padding-top:0; margin-top:0; border-top:none; }
.lu-d-tile .lu-d-overview { display:flex; align-items:center; gap:16px; }
/* Gauge column: the instrument tile with the band label centred beneath the arc.
   flex-shrink:0 so the meta column's text never squeezes the gauge.
   This is the SAME gauge as the Overview tab's (hbaviewer.php .lu-tile/.lu-arc)
   — the two have drifted before, so a change to one must be made to the other.
   The geometry itself is shared: both call lsi_gauge_svg() in view.php. */
.lu-d-tile .lu-d-gauge {
  display:flex; flex-direction:column; align-items:center; gap:7px; flex-shrink:0;
  padding:9px 13px 10px; border-radius:12px; border:1px solid #2e2e2e; background:transparent;
  --gauge-track:#3a3a3a; --mark:var(--tl,#41d141);
}
.lu-d-tile .lu-d-gauge.light {
  background:#6e6e6e; border-color:#5c5c5c; box-shadow:inset 0 1px 0 rgba(255,255,255,.22);
  --gauge-track:#5a5a5a; --mark:#fff;
}
.lu-d-tile .lu-arc { display:block; width:116px; height:65px; }
.lu-d-tile .lu-arc-bg, .lu-d-tile .lu-arc-fg { fill:none; stroke-width:14; stroke-linecap:round; }
.lu-d-tile .lu-arc-bg { stroke:var(--gauge-track); }
.lu-d-tile .lu-arc-wrap { position:relative; }
.lu-d-tile .lu-arc-readout { position:absolute; inset:0; display:flex; flex-direction:column; justify-content:flex-end; align-items:center; padding-bottom:1px; line-height:1; }
.lu-d-tile .lu-arc-readout .v { font-family:ui-monospace,"SF Mono",Menlo,monospace; font-size:24px; font-weight:600; font-variant-numeric:tabular-nums; color:var(--mark); }
.lu-d-tile .lu-arc-readout .u { font-size:10px; letter-spacing:0.05em; color:var(--mark); margin-top:4px; }
.lu-d-tile .lu-d-meta { flex:1; }
.lu-d-tile .lu-d-meta p   { margin:3px 0; font-size:12px; color:var(--d-text); display:flex; justify-content:space-between; gap:10px; border-bottom:1px dashed var(--d-border); padding-bottom:2px; }
.lu-d-tile .lu-d-meta span { color:var(--d-text); font-weight:500; font-family:ui-monospace,"SF Mono",Menlo,monospace; font-variant-numeric:tabular-nums; }
.lu-d-tile .lu-d-health {
  display:inline-flex; align-items:center; gap:6px;
  padding:3px 11px; border-radius:20px;
  font-size:10px; font-weight:700; letter-spacing:0.05em;
  color:var(--sc,#2ecc71); background:color-mix(in srgb, var(--sc,#2ecc71) 16%, transparent);
  box-shadow:0 0 8px color-mix(in srgb, var(--sc,#2ecc71) 30%, transparent);
}
.lu-d-tile .lu-d-health::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; }
.lu-d-tile .lu-d-temp-band { font-size:10px; font-weight:700; letter-spacing:0.06em; font-family:ui-monospace,"SF Mono",Menlo,monospace; color:var(--mark); }
.lu-d-tile .lu-d-ts { font-size:10px; color:var(--d-text); text-align:right; margin-top:8px; font-family:ui-monospace,Menlo,monospace; }
.lu-d-tile .lu-d-pill {
  display:none; align-items:center; margin-right:8px;
  padding:3px 11px; border-radius:20px;
  font-size:12px; font-weight:700; letter-spacing:0.03em;
  font-family:ui-monospace,"SF Mono",Menlo,monospace; font-variant-numeric:tabular-nums;
  /* --tc here is lsi_temp_text(): the gradient stop that reads as TEXT on this
     theme's own surfaces. The pill sits in the tile HEADER, outside the
     instrument tile, so it gets no panel to supply its background. */
  color:var(--tc,#41d141);
  border:1px solid color-mix(in srgb, var(--tc,#41d141) 55%, transparent);
  background:color-mix(in srgb, var(--tc,#41d141) 12%, transparent);
}
/* Collapsed-only footer. Unraid hides every <tr> after the first by setting an
   inline display:none on it — no class, no attribute we can hook. Measured on
   7.2: expanded style="" , collapsed style="display: none;". So this attribute
   substring match is the collapse signal, and :has() lets row 1 react to it.
   ponytail: CSS only, no MutationObserver. If a future Unraid stops using an
   inline style, this rule silently stops firing and the footer just never shows
   when collapsed — degrade, not break. */
.lu-d-tile .lu-d-foot-mini { display:none; padding:10px 0 2px; }
.lu-d-tile:has(> tr:nth-child(2)[style*="display: none"]) .lu-d-foot-mini { display:block; }
/* The pill is redundant while expanded — the circle gauge shows the same
   temperature far larger. Collapsed, the gauge is gone (it lives in row 2) and
   the pill is the only place the temperature appears. Same collapse signal as
   .lu-d-foot-mini above: Unraid's inline display:none on row 2. */
.lu-d-tile:has(> tr:nth-child(2)[style*="display: none"]) .lu-d-pill { display:inline-flex; }
.lu-d-tile .lu-d-foot-row {
  display:flex; gap:16px; flex-wrap:wrap; align-items:baseline; justify-content:center;
  font-size:12px; color:var(--d-text); padding-top:6px;
  border-top:1px solid var(--d-border);
}
.lu-d-tile .lu-d-foot-item { white-space:nowrap; }
.lu-d-tile .lu-d-foot-row span { color:var(--d-text); font-weight:500; }
</style>
CSS;

// One tile per HBA. Unraid's DashStats.page simply echoes every $mytiles entry
// and never matches the key against anything, so a single .page can emit as many
// tiles as there are controllers — each independently positionable and collapsible.
$tiles = [];

if ($error) {
    $tiles[] = [
        'key'    => "{$pluginname}_err",
        'id'     => 'tblHBAviewerErr',
        'tc'     => lsi_status_color('alert'),
        'main'   => 'HBAviewer',
        'sub'    => 'Unknown',
        'pill'   => '',
        'health' => '<span class="lu-d-health" style="--sc:' . lsi_status_color('alert') . '">UNREADABLE</span>',
        'foot'   => '',
        'body'   => "<span style='color:var(--crit-text)'>" . htmlspecialchars($error) . "</span>",
    ];
}

foreach ($controllers as $i => $c) {
    $t = [
        'key'    => "{$pluginname}_c{$i}",
        'id'     => "tblHBAviewer{$i}",
        'tc'     => lsi_status_color('alert'),
        'main'   => 'HBAviewer',
        'sub'    => "Controller /c{$i}",
        'pill'   => '',
        'health' => '',
        'foot'   => '',
    ];

    // A controller that failed to read still gets its own tile — error text in
    // the body, no pill. Skipping it made errored cards vanish once collapsed.
    if (isset($c['error'])) {
        $t['body'] = "<div class='lu-d-ctl'><span style='color:var(--crit-text)'>Controller {$i}: "
                   . htmlspecialchars($c['error']) . "</span></div>"
                   . "<div class='lu-d-ts'>Last read: {$ts}</div>";
        $tiles[] = $t;
        continue;
    }

    $v         = lsi_hba_view($c, $port, $i);
    $col       = $v['color'];                            // rollup status -> badge
    [$gDark, $gLight] = $v['temp_grad'];                 // temperature band -> gauge arc
    $tempCol   = lsi_temp_text($v['temp_band'] ?? '');   // ...and the header pill's text
    $temp      = (int)($c['temp'] ?? 0);
    // Gauge geometry (arc fraction, 0-110C scale) stays in °C throughout —
    // only the printed number/pill below switch units.
    $tempDisp  = lsi_temp_convert($temp, $unit);
    $model     = htmlspecialchars($v['model']);
    $chip      = htmlspecialchars($v['chip']);
    $firmware  = htmlspecialchars($v['firmware']);
    $portLabel = htmlspecialchars($v['port_label']);
    $badge     = $v['label'];
    $bios      = htmlspecialchars($v['bios']   ?? '');
    $mode      = htmlspecialchars($v['mode']   ?? '');
    $drives    = htmlspecialchars($v['drives'] ?? '');
    $cfgBandLabel = htmlspecialchars($v['cfg_band_label'] ?? '');

    // Critical renders as an inverted chip (white on solid fill) — #922b21 measures
    // 1.94:1 as plain text on a dark card and is unreadable there.
    $isCrit    = ($v['temp_band'] ?? '') === 'critical';
    $tempChip  = $isCrit
        ? '<span style="background:' . lsi_temp_color('critical') . ';color:#fff;padding:2px 7px;border-radius:2px;font-weight:700">CRITICAL</span>'
        : htmlspecialchars($v['temp_label']);   // colour comes from the tile's --mark

    // Title stays the plugin name; the subtitle identifies which card this tile is.
    // $portLabel is already "Controller /cN" on storcli cards and
    // "ioc0 (lsiutil -pN)" on lsiutil ones — both are the right thing to show.
    $t['tc']  = $col;
    $t['sub'] = $model . ' - ' . $portLabel;

    $pillTemp  = ($v['temp'] === '' || $v['temp'] === null) ? '' : lsi_temp_convert((int) $v['temp'], $unit);
    $t['pill'] = '<span class="lu-d-pill" style="--tc:' . $tempCol . '">'
               . ($pillTemp === '' ? 'N/A' : $pillTemp . '&deg;' . ($unit === 1 ? 'F' : 'C')) . '</span>';

    // Health pill lives in the tile header beside the gear, visible whether the
    // tile is expanded or collapsed — it is the one thing worth seeing without
    // opening the tile. The temperature pill sits to its left and, as before,
    // only appears while collapsed (expanded, the gauge shows the same number
    // far larger).
    $t['health'] = '<span class="lu-d-health" style="--sc:' . $col . '">' . $badge . '</span>';

    // The footer is built ONCE and emitted twice — at the bottom of the card in
    // row 2 (its natural place when expanded) and again in row 1, where CSS
    // reveals it only while the tile is collapsed. Unraid hides every <tr> after
    // the first, so row 1 is the only place a collapsed tile can still show
    // anything. No model here — the subtitle names which card this tile is, and
    // it stays visible when collapsed.
    $parts = [];
    foreach ($v['pcie'] as $item) {
        $parts[] = "<span class='lu-d-foot-item'>" . $item['label'] . ': <span>'
                 . htmlspecialchars($item['value']) . '</span></span>';
    }
    $t['foot'] = "<div class='lu-d-foot-row'>" . implode('', $parts) . "</div>";

    // Same 0-110C scale and the same renderer as the Overview tab's gauge.
    // The gradient id carries the controller index because a box with two HBAs
    // emits two tiles onto ONE dashboard page.
    $gauge = lsi_gauge_svg("lu-dgrad-{$i}", $temp / 110, [$gDark, $gLight]);
    $tileLight = lsi_tile_is_light() ? ' light' : '';

    $t['body'] = "
    <div class='lu-d-ctl'>
      <div class='lu-d-overview'>
        <div class='lu-d-gauge{$tileLight}' style='--td:{$gDark};--tl:{$gLight}'>
          <div class='lu-arc-wrap'>
            {$gauge}
            <div class='lu-arc-readout'>
              <span class='v'>{$tempDisp}</span>
              <span class='u'>{$unitSym}</span>
            </div>
          </div>
          <span class='lu-d-temp-band'>{$tempChip}</span>
        </div>
        <div class='lu-d-meta'>
          <p>Model: <span>{$model}</span></p>"
          . ($chip     ? "<p>Chip: <span>{$chip}</span></p>"         : '')
          . ($firmware ? "<p>Firmware: <span>{$firmware}</span></p>" : '')
          . ($bios     ? "<p>BIOS: <span>{$bios}</span></p>"         : '')
          . ($v['port_name'] !== '' ? "<p>lsiutil Port: <span>{$portLabel}</span></p>" : '')
          . ($mode     ? "<p>Mode: <span>{$mode}</span></p>"         : '')
          . ($drives   ? "<p>Drives: <span>{$drives} connected</span></p>" : '')
          . "<p>Badge Sensitivity: <span>{$cfgBandLabel} ({$thresholdDisp}{$unitSym}+)</span></p>
          <p>Last read: <span>{$ts}</span></p>
        </div>
      </div>
    </div>"
    . $t['foot'];

    $tiles[] = $t;
}

foreach ($tiles as $t) {
    $id   = $t['id'];   $tc   = $t['tc'];
    $main = $t['main']; $sub  = $t['sub'];
    $pill = $t['pill']; $health = $t['health']; $foot = $t['foot']; $body = $t['body'];

    $mytiles[$t['key']]['column1'] = <<<EOT
<tbody id="{$id}" class="lu-d-tile" title="HBAviewer">
  <tr>
    <td>
      <span class="tile-header">
        <span class="tile-header-left">
          <svg viewBox="0 0 64 64" width="32" height="32" fill="none" stroke="{$tc}"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
               style="vertical-align:middle;flex-shrink:0" role="img" aria-label="HBAviewer">
            <path d="M13 8H7v21H4v6h3v21h6"/>
            <rect x="16" y="12" width="44" height="40" rx="3"/>
            <rect x="20" y="19" width="9" height="6" rx="1"/>
            <rect x="20" y="30" width="9" height="6" rx="1"/>
            <rect x="36" y="24" width="16" height="16" rx="1"/>
            <rect x="40" y="28" width="8" height="8" rx="1"/>
            <path d="M40 20v4M44 20v4M48 20v4M40 40v4M44 40v4M48 40v4M32 28h4M32 32h4M32 36h4M52 28h4M52 32h4M52 36h4"/>
          </svg>
          <div class="section">
            <h3 class="tile-header-main">{$main}</h3>
            <span>{$sub}</span>
          </div>
        </span>
        <span class="tile-header-right">
          <span class="tile-header-right-controls">
            {$pill}
            {$health}
            <a href="/Tools/HBAviewer_Monitor" title="Open HBAviewer">
              <i class="fa fa-fw fa-cog control"></i>
            </a>
          </span>
        </span>
      </span>
      <div class="lu-d-foot-mini">{$foot}</div>
    </td>
  </tr>
  <tr>
    <td>
      {$body}
    </td>
  </tr>
</tbody>
EOT;
}
