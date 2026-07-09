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
// Use timeout to prevent dashboard from hanging if backend script fails
$data = null;
if (file_exists($SCRIPT)) {
    $raw = shell_exec('timeout 5 bash ' . escapeshellarg($SCRIPT) . ' 2>/dev/null') ?? '';
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
$boardName = htmlspecialchars(
    $error ? 'Unknown'
           : (count($controllers) === 1 ? lsi_hba_view($controllers[0], $port, 0)['model']
                                        : count($controllers) . ' controllers')
);

// Scoped styles. Per-controller color is inline (each circle/badge can differ).
echo <<<CSS
<style>
#tblLsiutil .lu-d-ctl { padding-top:14px; margin-top:14px; border-top:1px solid #2a2a2a; }
#tblLsiutil .lu-d-ctl:first-child { padding-top:0; margin-top:0; border-top:none; }
#tblLsiutil .lu-d-overview { display:flex; align-items:center; gap:20px; }
#tblLsiutil .lu-d-circle {
  width:90px; height:90px; border-radius:50%; border:4px solid #2ecc71;
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  flex-shrink:0;
}
#tblLsiutil .lu-d-circle .v { font-size:28px; font-weight:700; line-height:1; }
#tblLsiutil .lu-d-circle .u { font-size:12px; color:#666; margin-top:3px; }
#tblLsiutil .lu-d-meta p   { margin:3px 0; font-size:13px; color:#888; }
#tblLsiutil .lu-d-meta span { color:#ddd; font-weight:500; }
#tblLsiutil .lu-d-badge {
  display:inline-block; margin-top:6px;
  padding:2px 12px; border-radius:12px;
  font-size:11px; font-weight:700; letter-spacing:0.05em; color:#111;
}
#tblLsiutil .lu-d-pcie {
  display:flex; gap:18px; flex-wrap:wrap;
  font-size:13px; color:#888;
  padding-top:12px; margin-top:8px;
  border-top:1px solid #2a2a2a;
}
#tblLsiutil .lu-d-pcie span { color:#ddd; font-weight:500; }
#tblLsiutil .lu-d-ts { font-size:11px; color:#444; text-align:right; margin-top:8px; }
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
        <div class='lu-d-circle' style='border-color:{$col}'>
          <span class='v' style='color:{$col}'>{$temp}</span>
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
          <span class='lu-d-badge' style='background:{$col}'>{$badge}</span>
        </div>
      </div>
      {$pcieRow}
    </div>";
    }
    $body .= "<div class='lu-d-ts'>Last read: {$ts}</div>";
}

$mytiles[$pluginname]['column1'] = <<<EOT
<tbody id="tblHBAviewer" title="HBA Temperature">
  <tr>
    <td>
      <span class="tile-header">
        <span class="tile-header-left">
          <i class="fa fa-hdd-o f32" style="color:{$tc}"></i>
          <div class="section">
            <h3 class="tile-header-main">HBA Temperature</h3>
            <span>{$boardName}</span>
          </div>
        </span>
        <span class="tile-header-right">
          <span class="tile-header-right-controls">
            <a href="/Tools/HBAviewer_Monitor" title="Open HBAviewer">
              <i class="fa fa-fw fa-cog control"></i>
            </a>
          </span>
        </span>
      </span>
    </td>
  </tr>
  <tr>
    <td>
      {$body}
    </td>
  </tr>
</tbody>
EOT;
