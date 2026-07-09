<?PHP
/* LSIUtil AJAX endpoint
 * ?type=overview  → JSON  (temperature + card info, for auto-refresh)
 * ?type=phy       → HTML  (PHY health table)
 * ?type=drives    → HTML  (attached drives table)
 * ?type=events    → HTML  (event log table)
 */

require_once __DIR__ . '/view.php';

$type    = in_array($_GET['type'] ?? '', ['overview','phy','drives','events','smart','smart_all'])
           ? $_GET['type'] : 'overview';
$scripts = '/usr/local/emhttp/plugins/lsiutil/scripts';

/* ── SMART tab: all drives, collected in the background ─────────────────────
   Returns the cached table if fresh; otherwise reports progress (or launches a
   detached collector) so the request never blocks — the tab polls this. */
if ($type === 'smart_all') {
    header('Content-Type: text/html; charset=utf-8');
    $cache = '/tmp/lsiutil_smart.json';
    $prog  = $cache . '.progress';
    if (($_GET['refresh'] ?? '') === '1') { @unlink($cache); }

    if (is_file($cache) && (time() - filemtime($cache)) < 600) {
        echo renderSmartTable(json_decode((string) file_get_contents($cache), true) ?: []);
        exit;
    }
    if (is_file($prog)) {
        echo '<div class="lu-loading" data-smart="collecting">Collecting SMART… '
           . htmlspecialchars(trim((string) file_get_contents($prog)))
           . ' drives (you can use other tabs)</div>';
        exit;
    }
    shell_exec('nohup bash ' . escapeshellarg("$scripts/collect_smart.sh") . ' >/dev/null 2>&1 &');
    echo '<div class="lu-loading" data-smart="collecting">Collecting SMART in the background — this can take ~20s '
       . 'for all drives. You can switch to other tabs; results appear here when ready.</div>';
    exit;
}

/* ── Per-drive SMART (on demand) ────────────────────────────────────────────
   Correlate the storcli drive to /dev by SERIAL (the WWN differs by a nibble
   between storcli and /dev, but serials match exactly), then read SMART with
   -n standby so a sleeping drive is never woken. */
if ($type === 'smart') {
    header('Content-Type: text/html; charset=utf-8');
    $serial = preg_replace('/[^A-Za-z0-9_.:-]/', '', $_GET['serial'] ?? '');
    if ($serial === '') { echo '<span class="lu-muted">no serial</span>'; exit; }

    $dev = trim((string) shell_exec(
        'lsblk -S -o NAME,SERIAL -n 2>/dev/null | awk -v s=' . escapeshellarg($serial)
        . ' \'$2==s{print "/dev/"$1; exit}\''
    ));
    if ($dev === '') { echo '<span class="lu-muted">no /dev match</span>'; exit; }

    $raw = shell_exec('smartctl -n standby -a ' . escapeshellarg($dev)
        . ' 2>/dev/null | bash ' . escapeshellarg("$scripts/parse/smart.sh"));
    $s = json_decode((string) $raw, true) ?: [];
    if (($s['health'] ?? '') === '' && ($s['temp'] ?? '') === '') {
        echo '<span class="lu-muted">standby (not read)</span>'; exit;
    }

    $health = strtoupper($s['health'] ?? '');
    $ok     = $health === 'OK' || $health === 'PASSED';
    $warn   = (int)($s['defects'] ?? 0) > 0 || (int)($s['pending'] ?? 0) > 0;
    $color  = !$ok ? '#e74c3c' : ($warn ? '#f39c12' : '#2ecc71');
    $f = fn($v) => $v === '' || $v === null ? '?' : htmlspecialchars($v);
    printf(
        '<span style="color:%s;font-weight:700">%s</span> &middot; %s&deg;C &middot; %s def &middot; %s pend &middot; %sh',
        $color, $f($s['health'] ?? ''), $f($s['temp'] ?? ''),
        $f($s['defects'] ?? ''), $f($s['pending'] ?? ''), $f($s['power_on_hours'] ?? '')
    );
    exit;
}

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

/* Render the background-collected SMART cache as a table. */
function renderSmartTable(array $data): string {
    $drives = $data['drives'] ?? [];
    if (!$drives) return '<p class="lu-muted">No drives found.</p>';
    $dash = '<span class="lu-muted">—</span>';
    $rows = [];
    foreach ($drives as $d) {
        $s = $d['smart'] ?? [];
        $health = strtoupper((string) ($s['health'] ?? ''));
        if ($health === '') {
            $hb = '<span class="lu-muted">standby</span>';
        } else {
            $ok   = $health === 'OK' || $health === 'PASSED';
            $warn = (int) ($s['defects'] ?? 0) > 0 || (int) ($s['pending'] ?? 0) > 0;
            $hc   = !$ok ? '#e74c3c' : ($warn ? '#f39c12' : '#2ecc71');
            $hb   = '<span style="color:' . $hc . ';font-weight:700">' . htmlspecialchars($s['health']) . '</span>';
        }
        $cell = fn($v, $suf = '') => ($v ?? '') !== '' ? htmlspecialchars((string) $v) . $suf : $dash;
        $rows[] = [
            '<code>' . htmlspecialchars($d['dev'] ?? '') . '</code>',
            htmlspecialchars($d['model'] ?? ''),
            '<code>' . htmlspecialchars($d['serial'] ?? '') . '</code>',
            $hb,
            $cell($s['temp'] ?? '', '&deg;C'),
            $cell($s['defects'] ?? ''),
            $cell($s['pending'] ?? ''),
            ($s['power_on_hours'] ?? '') !== '' ? number_format((int) $s['power_on_hours']) . 'h' : $dash,
        ];
    }
    return luTable(['Device', 'Model', 'Serial', 'Health', 'Temp', 'Grown Defects', 'Pending', 'Power-On'], $rows);
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
            // storcli backend: link/speed/attached-SAS (storcli) + error counters (sysfs)
            $rows = [];
            foreach ($phys as $p) {
                $hasErr = (($p['inv'] ?? 0) + ($p['disp'] ?? 0) + ($p['sync'] ?? 0) + ($p['reset'] ?? 0)) > 0;
                $ec = function ($v) use ($hasErr) {
                    return $hasErr && $v > 0 ? '<span class="lu-err-val">' . $v . '</span>' : $v;
                };
                $rows[] = [
                    $p['phy'],
                    luLinkBadge($p['link']),
                    htmlspecialchars($p['speed']),
                    !empty($p['sas_addr']) ? '<code>' . strtoupper($p['sas_addr']) . '</code>' : '<span class="lu-muted">—</span>',
                    $ec($p['inv'] ?? 0),
                    $ec($p['disp'] ?? 0),
                    $ec($p['sync'] ?? 0),
                    $ec($p['reset'] ?? 0),
                ];
            }
            $out .= luTable(['PHY', 'Link', 'Speed', 'Attached SAS Address', 'Invalid DWords', 'Disparity Errors', 'Loss of Sync', 'Reset Problems'], $rows);
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

/* ── Attached Drives (per controller; columns adapt to the backend) ───────── */
if ($type === 'drives') {
    $ctls  = $data['controllers'] ?? [$data];
    $multi = count($ctls) > 1;
    $out   = '';
    foreach ($ctls as $i => $ctl) {
        if ($multi) $out .= luCtlHead($i);
        if (isset($ctl['error'])) { $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['error']) . '</p>'; continue; }
        $drives = $ctl['drives'] ?? [];
        if (empty($drives)) { $out .= '<p class="lu-muted">No drives detected.</p>'; continue; }

        if (isset($drives[0]['slot'])) {
            // storcli backend: enclosure/slot, model, serial, state, size, SAS (WWN), link, fw
            $rows = [];
            foreach ($drives as $d) {
                $serial = $d['serial'] ?? '';
                $smart  = $serial !== ''
                    ? '<button class="lu-refresh-btn" onclick="luSmart(this,\'' . htmlspecialchars($serial, ENT_QUOTES) . '\')">SMART</button>'
                    : '<span class="lu-muted">—</span>';
                $rows[] = [
                    htmlspecialchars($d['slot']),
                    ($d['port'] ?? '') !== '' ? htmlspecialchars($d['port']) : '<span class="lu-muted">—</span>',
                    htmlspecialchars($d['model']),
                    $serial !== '' ? '<code>' . htmlspecialchars($serial) . '</code>' : '<span class="lu-muted">—</span>',
                    htmlspecialchars($d['state'] ?? ''),
                    htmlspecialchars($d['size']),
                    htmlspecialchars($d['link']),
                    htmlspecialchars($d['firmware']),
                    $smart,
                ];
            }
            $out .= luTable(['Encl:Slot', 'Port', 'Model', 'Serial', 'State', 'Size', 'Link', 'Firmware', 'SMART'], $rows);
        } else {
            // lsiutil backend: bus:target, port, SAS address, OS device
            $rows = [];
            foreach ($drives as $d) {
                $os  = !empty($d['os_name'])     ? '<code>' . $d['os_name'] . '</code>'                : '<span class="lu-muted">—</span>';
                $sas = !empty($d['sas_address']) ? '<code>' . strtoupper($d['sas_address']) . '</code>' : '<span class="lu-muted">—</span>';
                $phy = isset($d['phy']) && $d['phy'] !== '' ? 'PHY ' . $d['phy']                        : '<span class="lu-muted">—</span>';
                $rows[] = [$d['bus'] . ':' . $d['target'], $phy, $sas, $os];
            }
            $out .= luTable(['Bus:Tgt', 'Port', 'SAS Address', 'OS Device'], $rows);
        }
    }
    echo $out;
    exit;
}

/* ── Event Log (per controller; columns adapt to the backend) ─────────────── */
if ($type === 'events') {
    $ctls  = $data['controllers'] ?? [$data];
    $multi = count($ctls) > 1;
    $out   = '';
    foreach ($ctls as $i => $ctl) {
        if ($multi) $out .= luCtlHead($i);
        if (isset($ctl['error'])) { $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['error']) . '</p>'; continue; }
        $entries = $ctl['entries'] ?? [];
        if (!empty($ctl['note'])) $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['note']) . '</p>';
        if (empty($entries)) { $out .= '<p class="lu-muted">No log entries.</p>'; continue; }

        if (isset($entries[0]['description'])) {
            // storcli backend: seq, time, code, human-readable description (newest first)
            $rows = [];
            foreach (array_reverse($entries) as $e) {
                $rows[] = [
                    '<code>' . htmlspecialchars($e['seq']) . '</code>',
                    htmlspecialchars($e['time']),
                    '<code>' . htmlspecialchars($e['code']) . '</code>',
                    htmlspecialchars($e['description']),
                ];
            }
            $out .= luTable(['Seq', 'Time', 'Code', 'Description'], $rows);
        } else {
            // lsiutil backend: seq, qualifier, data, timestamp (hex)
            $rows = [];
            foreach (array_reverse($entries) as $e) {
                $rows[] = [
                    $e['seq'],
                    '<code>' . $e['qualifier'] . '</code>',
                    '<code>' . htmlspecialchars($e['data']) . '</code>',
                    '<code>' . $e['timestamp'] . '</code>',
                ];
            }
            $out .= luTable(['Seq', 'Qualifier', 'Data', 'Timestamp'], $rows);
        }
    }
    echo $out;
    exit;
}
