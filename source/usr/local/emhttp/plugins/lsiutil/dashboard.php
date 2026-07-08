<?PHP
/* LSIUtil dashboard tile — Unraid 7.2+ tile format.
   Mirrors the Overview tab layout: circle gauge + card info + PCIe row.
   Result cached in /tmp for 60 s to avoid hardware reads on every page load. */

$pluginname = 'LSIUtil';
$CACHE   = '/tmp/lsiutil_dash.json';
$SCRIPT  = '/usr/local/emhttp/plugins/lsiutil/scripts/get_hba_info.sh';
$CFG     = '/boot/config/plugins/lsiutil/lsiutil.cfg';

// Read config for port + alert threshold
$c = ['HBA_PORT' => 1, 'ALERT_THRESHOLD' => 80];
if (file_exists($CFG)) {
    foreach (file($CFG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        if (strpos($ln, '=') !== false) {
            [$k, $v] = explode('=', $ln, 2);
            $c[trim($k)] = trim($v);
        }
    }
}
$port      = (int)$c['HBA_PORT'];
$threshold = (int)$c['ALERT_THRESHOLD'];

// Use cached data or run fresh
$data = null;
if (file_exists($CACHE) && (time() - filemtime($CACHE)) < 60) {
    $data = json_decode(file_get_contents($CACHE), true);
}
if (!$data || isset($data['error'])) {
    if (file_exists($SCRIPT)) {
        $raw  = shell_exec('bash ' . escapeshellarg($SCRIPT) . ' 2>/dev/null');
        $data = json_decode($raw ?? '', true);
        if ($data && !isset($data['error'])) file_put_contents($CACHE, $raw);
    }
}

$temp     = isset($data['temp'])       ? (int)$data['temp']    : null;
$status   = $data['status']            ?? 'ok';
$error    = $data['error']             ?? ($temp === null ? 'lsiutil unavailable' : null);
$tc       = match ($status) { 'alert' => '#e74c3c', 'warn' => '#f39c12', default => '#2ecc71' };
$badge    = match ($status) { 'alert' => 'ALERT',   'warn' => 'WARNING',  default => 'NORMAL'  };

$boardName = htmlspecialchars(!empty($data['board_name']) ? $data['board_name'] : ($data['model'] ?? 'Unknown'));
$chip      = htmlspecialchars($data['model']    ?? '');
$firmware  = htmlspecialchars($data['firmware'] ?? '');
$portName  = htmlspecialchars($data['port_name'] ?? 'ioc0');
$ts        = date('H:i:s');

// Scoped styles — output directly so they appear in the page <head> area
echo <<<CSS
<style>
#tblLsiutil .lu-d-overview { display:flex; align-items:center; gap:20px; }
#tblLsiutil .lu-d-circle {
  width:90px; height:90px; border-radius:50%;
  border:4px solid {$tc};
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  flex-shrink:0;
}
#tblLsiutil .lu-d-circle .v { font-size:28px; font-weight:700; color:{$tc}; line-height:1; }
#tblLsiutil .lu-d-circle .u { font-size:12px; color:#666; margin-top:3px; }
#tblLsiutil .lu-d-meta p   { margin:3px 0; font-size:13px; color:#888; }
#tblLsiutil .lu-d-meta span { color:#ddd; font-weight:500; }
#tblLsiutil .lu-d-badge {
  display:inline-block; margin-top:6px;
  padding:2px 12px; border-radius:12px;
  font-size:11px; font-weight:700; letter-spacing:0.05em;
  background:{$tc}; color:#111;
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

// Build PCIe row
$pcieRow = '';
if (!$error) {
    $pcieParts = [];
    if (!empty($data['pcie_width']))   $pcieParts[] = 'PCIe Width: <span>' . htmlspecialchars($data['pcie_width'])   . '</span>';
    if (!empty($data['pcie_speed']))   $pcieParts[] = 'PCIe Speed: <span>' . htmlspecialchars($data['pcie_speed'])   . '</span>';
    if (!empty($data['power_mode']))   $pcieParts[] = 'Power Mode: <span>' . htmlspecialchars($data['power_mode'])   . '</span>';
    if (!empty($data['pci_location'])) $pcieParts[] = 'PCI Location: <span>' . htmlspecialchars($data['pci_location']) . '</span>';
    if ($pcieParts) $pcieRow = '<div class="lu-d-pcie">' . implode('', $pcieParts) . '</div>';
}

// Tile body
if ($error) {
    $body = "<span style='color:#d88'>" . htmlspecialchars($error) . "</span>";
} else {
    $body = "
    <div class='lu-d-overview'>
      <div class='lu-d-circle'>
        <span class='v'>{$temp}</span>
        <span class='u'>°C</span>
      </div>
      <div class='lu-d-meta'>
        <p>Model: <span>{$boardName}</span></p>"
        . ($chip     ? "<p>Chip: <span>{$chip}</span></p>"                                : '')
        . ($firmware ? "<p>Firmware: <span>{$firmware}</span></p>"                        : '')
        . "        <p>Port: <span>{$portName} (lsiutil -p{$port})</span></p>
        <p>Alert Threshold: <span>{$threshold}°C</span></p>
        <span class='lu-d-badge'>{$badge}</span>
      </div>
    </div>
    {$pcieRow}
    <div class='lu-d-ts'>Last read: {$ts}</div>";
}

$mytiles[$pluginname]['column1'] = <<<EOT
<tbody id="tblLsiutil" title="HBA Temperature">
  <tr>
    <td>
      <span class="tile-header">
        <span class="tile-header-left">
          <i class="fa fa-thermometer-half f32" style="color:{$tc}"></i>
          <div class="section">
            <h3 class="tile-header-main">HBA Temperature</h3>
            <span>{$boardName}</span>
          </div>
        </span>
        <span class="tile-header-right">
          <span class="tile-header-right-controls">
            <a href="/Tools/LSIUtil_Monitor" title="Open LSIUtil">
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
