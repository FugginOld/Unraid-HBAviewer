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

$ts = date('H:i:s');

// Scoped styles. Per-controller color is inline (each circle/badge can differ).
echo <<<CSS
<style>
.lu-d-tile .lu-d-ctl { padding-top:16px; margin-top:16px; border-top:1px solid #2a2a2a; }
.lu-d-tile .lu-d-ctl:first-child { padding-top:0; margin-top:0; border-top:none; }
.lu-d-tile .lu-d-overview { display:flex; align-items:center; gap:16px; }
/* Gauge column: the circle with the status badge centred beneath it. flex-shrink:0
   so the meta column's text never squeezes the gauge. */
.lu-d-tile .lu-d-gauge { display:flex; flex-direction:column; align-items:center; gap:8px; flex-shrink:0; }
.lu-d-tile .lu-d-circle {
  position:relative; width:84px; height:84px; flex-shrink:0; border-radius:50%;
  background:conic-gradient(var(--tc,#2ecc71) calc(var(--pct,0)*1%), #2a2a2a 0);
  display:grid; place-items:center;
  filter:drop-shadow(0 0 8px color-mix(in srgb, var(--tc,#2ecc71) 30%, transparent));
}
.lu-d-tile .lu-d-circle::before { content:''; position:absolute; inset:6px; border-radius:50%; background:#1c1c1c; border:1px solid #2a2a2a; }
.lu-d-tile .lu-d-circle .v { position:relative; z-index:1; transform:translateY(-3px); font-family:ui-monospace,"SF Mono",Menlo,monospace; font-size:24px; font-weight:600; font-variant-numeric:tabular-nums; color:#ddd; line-height:1; }
.lu-d-tile .lu-d-circle .u { position:absolute; z-index:1; left:0; right:0; bottom:15px; text-align:center; font-size:10px; color:#ddd; letter-spacing:0.05em; }
.lu-d-tile .lu-d-meta { flex:1; }
.lu-d-tile .lu-d-meta p   { margin:3px 0; font-size:12px; color:#ddd; display:flex; justify-content:space-between; gap:10px; border-bottom:1px dashed #2a2a2a; padding-bottom:2px; }
.lu-d-tile .lu-d-meta span { color:#ddd; font-weight:500; font-family:ui-monospace,"SF Mono",Menlo,monospace; font-variant-numeric:tabular-nums; }
.lu-d-tile .lu-d-badge {
  display:inline-flex; align-items:center; gap:6px;
  padding:3px 11px; border-radius:20px;
  font-size:10px; font-weight:700; letter-spacing:0.05em;
  color:var(--sc,#2ecc71); background:color-mix(in srgb, var(--sc,#2ecc71) 16%, transparent);
  box-shadow:0 0 8px color-mix(in srgb, var(--sc,#2ecc71) 30%, transparent);
}
.lu-d-tile .lu-d-badge::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; }
.lu-d-tile .lu-d-ts { font-size:10px; color:#ddd; text-align:right; margin-top:8px; font-family:ui-monospace,Menlo,monospace; }
.lu-d-tile .lu-d-pill {
  display:none; align-items:center; margin-right:8px;
  padding:3px 11px; border-radius:20px;
  font-size:12px; font-weight:700; letter-spacing:0.03em;
  font-family:ui-monospace,"SF Mono",Menlo,monospace; font-variant-numeric:tabular-nums;
  color:var(--tc,#2ecc71);
  border:1px solid color-mix(in srgb, var(--tc,#2ecc71) 55%, transparent);
  background:color-mix(in srgb, var(--tc,#2ecc71) 12%, transparent);
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
  display:flex; gap:16px; flex-wrap:wrap; align-items:baseline;
  font-size:12px; color:#ddd; padding-top:6px;
  border-top:1px solid #2a2a2a;
}
.lu-d-tile .lu-d-foot-row span { color:#ddd; font-weight:500; }
</style>
CSS;

// One tile per HBA. Unraid's DashStats.page simply echoes every $mytiles entry
// and never matches the key against anything, so a single .page can emit as many
// tiles as there are controllers — each independently positionable and collapsible.
$tiles = [];

if ($error) {
    $tiles[] = [
        'key'  => "{$pluginname}_err",
        'id'   => 'tblHBAviewerErr',
        'tc'   => lsi_status_color('alert'),
        'main' => 'HBAviewer',
        'sub'  => 'Unknown',
        'pill' => '',
        'foot' => '',
        'body' => "<span style='color:#d88'>" . htmlspecialchars($error) . "</span>",
    ];
}

foreach ($controllers as $i => $c) {
    $t = [
        'key'  => "{$pluginname}_c{$i}",
        'id'   => "tblHBAviewer{$i}",
        'tc'   => lsi_status_color('alert'),
        'main' => 'HBAviewer',
        'sub'  => "Controller /c{$i}",
        'pill' => '',
        'foot' => '',
    ];

    // A controller that failed to read still gets its own tile — error text in
    // the body, no pill. Skipping it made errored cards vanish once collapsed.
    if (isset($c['error'])) {
        $t['body'] = "<div class='lu-d-ctl'><span style='color:#d88'>Controller {$i}: "
                   . htmlspecialchars($c['error']) . "</span></div>"
                   . "<div class='lu-d-ts'>Last read: {$ts}</div>";
        $tiles[] = $t;
        continue;
    }

    $v         = lsi_hba_view($c, $port, $i);
    $col       = $v['color'];       // rollup status -> badge
    $tempCol   = $v['temp_stroke']; // temperature band -> gauge/glow/pill
    $temp      = (int)($c['temp'] ?? 0);
    $model     = htmlspecialchars($v['model']);
    $chip      = htmlspecialchars($v['chip']);
    $firmware  = htmlspecialchars($v['firmware']);
    $portLabel = htmlspecialchars($v['port_label']);
    $badge     = $v['label'];
    $bios      = htmlspecialchars($v['bios']   ?? '');
    $mode      = htmlspecialchars($v['mode']   ?? '');
    $drives    = htmlspecialchars($v['drives'] ?? '');

    // Critical renders as an inverted chip (white on solid fill) — #922b21 measures
    // 1.94:1 as plain text on a dark card and is unreadable there.
    $isCrit    = ($v['temp_band'] ?? '') === 'critical';
    $tempChip  = $isCrit
        ? '<span style="background:' . lsi_temp_color('critical') . ';color:#fff;padding:2px 7px;border-radius:2px;font-weight:700">CRITICAL</span>'
        : '<span style="color:' . $v['temp_stroke'] . '">' . htmlspecialchars($v['temp_label']) . '</span>';

    // Title stays the plugin name; the subtitle identifies which card this tile is.
    // $portLabel is already "Controller /cN" on storcli cards and
    // "ioc0 (lsiutil -pN)" on lsiutil ones — both are the right thing to show.
    $t['tc']  = $col;
    $t['sub'] = $model . ' - ' . $portLabel;

    $pillTemp  = ($v['temp'] === '' || $v['temp'] === null) ? '' : (int) $v['temp'];
    $t['pill'] = '<span class="lu-d-pill" style="--tc:' . $tempCol . '">'
               . ($pillTemp === '' ? 'N/A' : $pillTemp . '&deg;C') . '</span>';

    // The footer is built ONCE and emitted twice — at the bottom of the card in
    // row 2 (its natural place when expanded) and again in row 1, where CSS
    // reveals it only while the tile is collapsed. Unraid hides every <tr> after
    // the first, so row 1 is the only place a collapsed tile can still show
    // anything. No model here — the subtitle names which card this tile is, and
    // it stays visible when collapsed.
    $parts = [];
    foreach ($v['pcie'] as $item) {
        $parts[] = $item['label'] . ': <span>' . htmlspecialchars($item['value']) . '</span>';
    }
    $t['foot'] = "<div class='lu-d-foot-row'>" . implode('', $parts) . "</div>";

    $t['body'] = "
    <div class='lu-d-ctl'>
      <div class='lu-d-overview'>
        <div class='lu-d-gauge'>
          <div class='lu-d-circle' style='--tc:{$tempCol};--pct:{$temp}'>
            <span class='v'>{$temp}</span>
            <span class='u'>°C</span>
          </div>
          <span class='lu-d-badge' style='--sc:{$col}'>{$badge}</span>
        </div>
        <div class='lu-d-meta'>
          <p>Model: <span>{$model}</span></p>"
          . ($chip     ? "<p>Chip: <span>{$chip}</span></p>"         : '')
          . ($firmware ? "<p>Firmware: <span>{$firmware}</span></p>" : '')
          . ($bios     ? "<p>BIOS: <span>{$bios}</span></p>"         : '')
          . ($v['port_name'] !== '' ? "<p>lsiutil Port: <span>{$portLabel}</span></p>" : '')
          . ($mode     ? "<p>Mode: <span>{$mode}</span></p>"         : '')
          . ($drives   ? "<p>Drives: <span>{$drives} connected</span></p>" : '')
          . "<p>Temp Band: {$tempChip}</p>
          <p>Alert Threshold: <span>{$threshold}°C</span></p>
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
    $pill = $t['pill']; $foot = $t['foot']; $body = $t['body'];

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
