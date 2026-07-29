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

// Header icon reflects the worst controller; subtitle names the card or the count.
$rank  = ['ok' => 0, 'warn' => 1, 'alert' => 2];
$worst = 'ok';
foreach ($controllers as $c) {
    $s = $c['status'] ?? 'ok';
    if (($rank[$s] ?? 0) > $rank[$worst]) $worst = $s;
}
$tc = lsi_status_color($worst);
$ts = date('H:i:s');

// Header subtitle: the card model, or the count when there is more than one.
// The temperature used to live here; it now has its own colour-coded pill in the
// header, so this line is identity only.
$boardName = htmlspecialchars(
    $error ? 'Unknown'
           : (count($controllers) === 1 ? lsi_hba_view($controllers[0], $port, 0)['model']
                                        : count($controllers) . ' controllers')
);

// Per-controller temperature pills for the header, and the PCIe footer strings.
// The footer is built ONCE here and emitted twice — at the bottom of each card
// (row 2, its natural place) and again inside the header row, where CSS reveals
// it only while the tile is collapsed. Unraid hides every <tr> after the first,
// so row 1 is the only place a collapsed tile can still show anything.
$pills   = '';
$footMini = '';
foreach ($controllers as $i => $c) {
    if (isset($c['error'])) continue;
    $v    = lsi_hba_view($c, $port, $i);
    $col  = $v['color'];
    $temp = ($v['temp'] === '' || $v['temp'] === null) ? '' : (int) $v['temp'];
    $pills .= '<span class="lu-d-pill" style="--tc:' . $col . '">'
            . ($temp === '' ? 'N/A' : $temp . '&deg;C') . '</span>';

    $parts = [];
    foreach ($v['pcie'] as $item) {
        $parts[] = $item['label'] . ': <span>' . htmlspecialchars($item['value']) . '</span>';
    }
    if ($parts) {
        $footMini .= '<div class="lu-d-foot-row">'
                   . (count($controllers) > 1 ? '<b>' . htmlspecialchars($v['model']) . '</b>' : '')
                   . implode('', $parts) . '</div>';
    }
}

// Scoped styles. Per-controller color is inline (each circle/badge can differ).
echo <<<CSS
<style>
#tblHBAviewer .lu-d-ctl { padding-top:16px; margin-top:16px; border-top:1px solid #2a2a2a; }
#tblHBAviewer .lu-d-ctl:first-child { padding-top:0; margin-top:0; border-top:none; }
#tblHBAviewer .lu-d-overview { display:flex; align-items:center; gap:16px; }
#tblHBAviewer .lu-d-circle {
  position:relative; width:84px; height:84px; flex-shrink:0; border-radius:50%;
  background:conic-gradient(var(--tc,#2ecc71) calc(var(--pct,0)*1%), #2a2a2a 0);
  display:grid; place-items:center;
  filter:drop-shadow(0 0 8px color-mix(in srgb, var(--tc,#2ecc71) 30%, transparent));
}
#tblHBAviewer .lu-d-circle::before { content:''; position:absolute; inset:6px; border-radius:50%; background:#1c1c1c; border:1px solid #2a2a2a; }
#tblHBAviewer .lu-d-circle .v { position:relative; z-index:1; transform:translateY(-3px); font-family:ui-monospace,"SF Mono",Menlo,monospace; font-size:24px; font-weight:600; font-variant-numeric:tabular-nums; color:#ddd; line-height:1; }
#tblHBAviewer .lu-d-circle .u { position:absolute; z-index:1; left:0; right:0; bottom:15px; text-align:center; font-size:10px; color:#ddd; letter-spacing:0.05em; }
#tblHBAviewer .lu-d-meta { flex:1; }
#tblHBAviewer .lu-d-meta p   { margin:3px 0; font-size:12px; color:#ddd; display:flex; justify-content:space-between; gap:10px; border-bottom:1px dashed #2a2a2a; padding-bottom:2px; }
#tblHBAviewer .lu-d-meta span { color:#ddd; font-weight:500; font-family:ui-monospace,"SF Mono",Menlo,monospace; font-variant-numeric:tabular-nums; }
#tblHBAviewer .lu-d-badge {
  display:inline-flex; align-items:center; gap:6px; margin-top:6px;
  padding:3px 11px; border-radius:20px;
  font-size:10px; font-weight:700; letter-spacing:0.05em;
  color:var(--tc,#2ecc71); background:color-mix(in srgb, var(--tc,#2ecc71) 16%, transparent);
}
#tblHBAviewer .lu-d-badge::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; }
#tblHBAviewer .lu-d-pcie {
  display:flex; gap:16px; flex-wrap:wrap;
  font-size:12px; color:#ddd;
  padding-top:8px; margin-top:8px; margin-left:100px;
  border-top:1px solid #2a2a2a;
}
#tblHBAviewer .lu-d-pcie span { color:#ddd; font-weight:500; }
#tblHBAviewer .lu-d-ts { font-size:10px; color:#ddd; text-align:right; margin-top:8px; font-family:ui-monospace,Menlo,monospace; }
#tblHBAviewer .lu-d-pill {
  display:inline-flex; align-items:center; margin-right:8px;
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
#tblHBAviewer .lu-d-foot-mini { display:none; padding:10px 0 2px; }
#tblHBAviewer:has(> tr:nth-child(2)[style*="display: none"]) .lu-d-foot-mini { display:block; }
#tblHBAviewer .lu-d-foot-row {
  display:flex; gap:16px; flex-wrap:wrap; align-items:baseline;
  font-size:12px; color:#ddd; padding-top:6px;
  border-top:1px solid #2a2a2a;
}
#tblHBAviewer .lu-d-foot-row span { color:#ddd; font-weight:500; }
#tblHBAviewer .lu-d-foot-row b { color:#f5a623; font-weight:600; margin-right:4px; }
</style>
CSS;

// Tile body — one block per controller (stacked).
if ($error) {
    $body = "<span style='color:#d88'>" . htmlspecialchars($error) . "</span>";
} else {
    $body = '';
    foreach ($controllers as $i => $c) {
        if (isset($c['error'])) {
            $body .= "<div class='lu-d-ctl'><span style='color:#d88'>Controller {$i}: "
                   . htmlspecialchars($c['error']) . "</span></div>";
            continue;
        }
        $v         = lsi_hba_view($c, $port, $i);
        $col       = $v['color'];
        $temp      = (int)($c['temp'] ?? 0);
        $model     = htmlspecialchars($v['model']);
        $chip      = htmlspecialchars($v['chip']);
        $firmware  = htmlspecialchars($v['firmware']);
        $portLabel = htmlspecialchars($v['port_label']);
        $badge     = $v['label'];

        $pcieParts = [];
        foreach ($v['pcie'] as $item) {
            $pcieParts[] = $item['label'] . ': <span>' . htmlspecialchars($item['value']) . '</span>';
        }
        $pcieRow = $pcieParts ? "<div class='lu-d-pcie'>" . implode('', $pcieParts) . "</div>" : '';

        $bios   = htmlspecialchars($v['bios'] ?? '');
        $mode   = htmlspecialchars($v['mode'] ?? '');
        $drives = htmlspecialchars($v['drives'] ?? '');

        $body .= "
    <div class='lu-d-ctl'>
      <div class='lu-d-overview'>
        <div class='lu-d-circle' style='--tc:{$col};--pct:{$temp}'>
          <span class='v'>{$temp}</span>
          <span class='u'>°C</span>
        </div>
        <div class='lu-d-meta'>
          <p>Model: <span>{$model}</span></p>"
          . ($chip     ? "<p>Chip: <span>{$chip}</span></p>"         : '')
          . ($firmware ? "<p>Firmware: <span>{$firmware}</span></p>" : '')
          . ($bios     ? "<p>BIOS: <span>{$bios}</span></p>"         : '')
          . ($v['port_name'] !== '' ? "<p>lsiutil Port: <span>{$portLabel}</span></p>" : '')
          . ($mode     ? "<p>Mode: <span>{$mode}</span></p>"         : '')
          . ($drives   ? "<p>Drives: <span>{$drives} connected</span></p>" : '')
          . "<p>Alert Threshold: <span>{$threshold}°C</span></p>
          <span class='lu-d-badge' style='--tc:{$col}'>{$badge}</span>
        </div>
      </div>
      {$pcieRow}
    </div>";
    }
    $body .= "<div class='lu-d-ts'>Last read: {$ts}</div>";
}

$mytiles[$pluginname]['column1'] = <<<EOT
<tbody id="tblHBAviewer" title="HBA Dashboard">
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
            <h3 class="tile-header-main">HBA Dashboard</h3>
            <span>{$boardName}</span>
          </div>
        </span>
        <span class="tile-header-right">
          <span class="tile-header-right-controls">
            {$pills}
            <a href="/Tools/HBAviewer_Monitor" title="Open HBAviewer">
              <i class="fa fa-fw fa-cog control"></i>
            </a>
          </span>
        </span>
      </span>
      <div class="lu-d-foot-mini">{$footMini}</div>
    </td>
  </tr>
  <tr>
    <td>
      {$body}
    </td>
  </tr>
</tbody>
EOT;
