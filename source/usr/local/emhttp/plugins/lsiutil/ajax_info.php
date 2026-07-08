<?PHP
/* LSIUtil AJAX endpoint
 * ?type=overview  → JSON  (temperature + card info, for auto-refresh)
 * ?type=phy       → HTML  (PHY health table)
 * ?type=drives    → HTML  (attached drives table)
 * ?type=events    → HTML  (event log table)
 */

$type    = in_array($_GET['type'] ?? '', ['overview','phy','drives','events'])
           ? $_GET['type'] : 'overview';
$scripts = '/usr/local/emhttp/plugins/lsiutil/scripts';

if ($type === 'overview') {
    header('Content-Type: application/json');
    $out = shell_exec("bash $scripts/get_hba_info.sh 2>/dev/null");
    echo $out ?: '{"error":"No output from script"}';
    exit;
}

// Non-overview tabs: return styled HTML fragments
$scriptMap = [
    'phy'    => "$scripts/get_phy_health.sh",
    'drives' => "$scripts/get_attached_drives.sh",
    'events' => "$scripts/get_event_log.sh",
];

$raw  = shell_exec('bash ' . escapeshellarg($scriptMap[$type]) . ' 2>/dev/null');
$data = $raw ? json_decode($raw, true) : null;

if (!$data || isset($data['error'])) {
    $msg = htmlspecialchars($data['error'] ?? 'Script returned no data.');
    echo '<div class="lu-error"><strong>Error:</strong> ' . $msg . '</div>';
    exit;
}

/* ── Shared helpers ────────────────────────────────────────────────────────── */
function luTable(array $headers, array $rows): string {
    $h = '<table class="lu-table"><thead><tr>';
    foreach ($headers as $hdr) $h .= '<th>' . htmlspecialchars($hdr) . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $cols) {
        $h .= '<tr>';
        foreach ($cols as $cell) $h .= '<td>' . $cell . '</td>';
        $h .= '</tr>';
    }
    return $h . '</tbody></table>';
}

/* ── PHY Health ─────────────────────────────────────────────────────────────  */
if ($type === 'phy') {
    $phys = $data['phys'] ?? [];
    if (empty($phys)) { echo '<p class="lu-muted">No PHY data.</p>'; exit; }

    $rows = [];
    foreach ($phys as $p) {
        $up    = strtolower($p['link']) === 'up';
        $badge = $up
            ? '<span class="lu-link-up">UP</span>'
            : '<span class="lu-link-down">DOWN</span>';
        $hasErr = ($p['inv'] + $p['disp'] + $p['sync'] + $p['reset']) > 0;
        $rows[] = [
            $p['phy'],
            $badge,
            $hasErr ? '<span class="lu-err-val">'.$p['inv'].'</span>'   : $p['inv'],
            $hasErr ? '<span class="lu-err-val">'.$p['disp'].'</span>'  : $p['disp'],
            $hasErr ? '<span class="lu-err-val">'.$p['sync'].'</span>'  : $p['sync'],
            $hasErr ? '<span class="lu-err-val">'.$p['reset'].'</span>' : $p['reset'],
        ];
    }
    echo luTable(
        ['PHY', 'Link', 'Invalid DWords', 'Disparity Errors', 'Loss of Sync', 'Reset Problems'],
        $rows
    );
    exit;
}

/* ── Attached Drives ─────────────────────────────────────────────────────── */
if ($type === 'drives') {
    $drives = $data['drives'] ?? [];
    if (empty($drives)) { echo '<p class="lu-muted">No drives detected.</p>'; exit; }

    $rows = [];
    foreach ($drives as $d) {
        $os  = !empty($d['os_name'])     ? '<code>' . $d['os_name'] . '</code>'              : '<span class="lu-muted">—</span>';
        $sas = !empty($d['sas_address']) ? '<code>' . strtoupper($d['sas_address']) . '</code>' : '<span class="lu-muted">—</span>';
        $phy = isset($d['phy']) && $d['phy'] !== '' ? 'PHY ' . $d['phy']                      : '<span class="lu-muted">—</span>';
        $rows[] = [
            $d['bus'] . ':' . $d['target'],
            $phy,
            $sas,
            $os,
        ];
    }
    echo luTable(
        ['Bus:Tgt', 'Port', 'SAS Address', 'OS Device'],
        $rows
    );
    exit;
}

/* ── Event Log ───────────────────────────────────────────────────────────── */
if ($type === 'events') {
    $entries = $data['entries'] ?? [];
    $note    = $data['note']    ?? '';
    if ($note) echo '<p class="lu-muted">' . htmlspecialchars($note) . '</p>';
    if (empty($entries)) { echo '<p class="lu-muted">No log entries.</p>'; exit; }

    $rows = [];
    foreach (array_reverse($entries) as $e) {
        $rows[] = [
            $e['seq'],
            '<code>' . $e['qualifier'] . '</code>',
            '<code>' . htmlspecialchars($e['data']) . '</code>',
            '<code>' . $e['timestamp'] . '</code>',
        ];
    }
    echo luTable(['Seq', 'Qualifier', 'Data', 'Timestamp'], $rows);
    exit;
}
