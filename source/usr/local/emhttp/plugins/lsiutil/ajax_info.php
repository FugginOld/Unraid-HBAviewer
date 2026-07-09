<?PHP
/* LSIUtil AJAX endpoint
 * ?type=overview  → JSON  (temperature + card info, for auto-refresh)
 * ?type=phy       → HTML  (PHY health table)
 * ?type=drives    → HTML  (attached drives table)
 * ?type=events    → HTML  (event log table)
 */

require_once __DIR__ . '/view.php';

$type    = in_array($_GET['type'] ?? '', ['overview','phy','drives','events'])
           ? $_GET['type'] : 'overview';
$scripts = '/usr/local/emhttp/plugins/lsiutil/scripts';

if ($type === 'overview') {
    header('Content-Type: application/json');
    $out  = shell_exec("bash $scripts/get_hba_info.sh 2>/dev/null");
    $data = $out ? json_decode($out, true) : null;
    if (!$data) { echo '{"error":"No output from script"}'; exit; }
    if (isset($data['error'])) { echo json_encode($data); exit; }  // total backend failure
    // Always hand the JS a controllers[] array (normalizes flat + array shapes),
    // each enriched with the shared status->color/label so the JS needs no map.
    $ctls = lsi_controllers($data);
    foreach ($ctls as &$c) {
        if (isset($c['error'])) continue;
        $c['color'] = lsi_status_color($c['status'] ?? 'ok');
        $c['label'] = lsi_status_label($c['status'] ?? 'ok');
    }
    unset($c);
    echo json_encode(['controllers' => $ctls]);
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

/* ── PHY Health (per controller; columns adapt to the detected backend) ────── */
function luCtlHead(int $i): string {
    return '<h3 style="margin:18px 0 8px;color:#f5a623;font-size:12px;'
         . 'text-transform:uppercase;letter-spacing:0.06em;">Controller /c' . $i . '</h3>';
}
function luLinkBadge(string $link): string {
    return strtolower($link) === 'up'
        ? '<span class="lu-link-up">UP</span>' : '<span class="lu-link-down">DOWN</span>';
}

if ($type === 'phy') {
    $ctls  = $data['controllers'] ?? [$data];
    $multi = count($ctls) > 1;
    $out   = '';
    foreach ($ctls as $i => $ctl) {
        if ($multi) $out .= luCtlHead($i);
        if (isset($ctl['error'])) { $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['error']) . '</p>'; continue; }
        $phys = $ctl['phys'] ?? [];
        if (empty($phys)) { $out .= '<p class="lu-muted">No PHY data.</p>'; continue; }

        if (isset($phys[0]['speed'])) {
            // storcli backend: link / speed / attached SAS address / port
            $rows = [];
            foreach ($phys as $p) {
                $rows[] = [
                    $p['phy'],
                    luLinkBadge($p['link']),
                    htmlspecialchars($p['speed']),
                    !empty($p['sas_addr']) ? '<code>' . strtoupper($p['sas_addr']) . '</code>' : '<span class="lu-muted">—</span>',
                    $p['port'] !== '' ? htmlspecialchars($p['port']) : '<span class="lu-muted">—</span>',
                ];
            }
            $out .= luTable(['PHY', 'Link', 'Speed', 'Attached SAS Address', 'Port'], $rows);
        } else {
            // lsiutil backend: SAS error counters
            $rows = [];
            foreach ($phys as $p) {
                $hasErr = ($p['inv'] + $p['disp'] + $p['sync'] + $p['reset']) > 0;
                $rows[] = [
                    $p['phy'],
                    luLinkBadge($p['link']),
                    $hasErr ? '<span class="lu-err-val">'.$p['inv'].'</span>'   : $p['inv'],
                    $hasErr ? '<span class="lu-err-val">'.$p['disp'].'</span>'  : $p['disp'],
                    $hasErr ? '<span class="lu-err-val">'.$p['sync'].'</span>'  : $p['sync'],
                    $hasErr ? '<span class="lu-err-val">'.$p['reset'].'</span>' : $p['reset'],
                ];
            }
            $out .= luTable(['PHY', 'Link', 'Invalid DWords', 'Disparity Errors', 'Loss of Sync', 'Reset Problems'], $rows);
        }
    }
    echo $out;
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
