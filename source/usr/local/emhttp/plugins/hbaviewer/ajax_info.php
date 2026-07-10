<?PHP
/* HBAviewer AJAX endpoint
 * ?type=overview  → JSON  (temperature + card info, for auto-refresh)
 * ?type=phy       → HTML  (PHY health table)
 * ?type=drives    → HTML  (attached drives table)
 * ?type=events    → HTML  (event log table)
 */

require_once __DIR__ . '/view.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/event_archive.php';
require_once __DIR__ . '/cached_read.php';

$type    = in_array($_GET['type'] ?? '', ['overview','overview_html','phy','drives','events','smart','smart_all','metrics'])
           ? $_GET['type'] : 'overview';
$scripts = '/usr/local/emhttp/plugins/hbaviewer/scripts';

/* ── Performance tab: instant counter snapshot (browser computes the rates) ──
   Polled ~2s. get_metrics.sh touches only /proc + /sys + the overview cache —
   never storcli/lsiutil — so this stays fast. Its JSON is already the shape the
   JS wants; echo it straight through. */
if ($type === 'metrics') {
    header('Content-Type: application/json');
    $out = shell_exec("bash $scripts/get_metrics.sh 2>/dev/null");
    echo ($out !== null && trim($out) !== '') ? $out : '{"t":0,"controllers":[]}';
    exit;
}

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

    $raw = shell_exec('bash ' . escapeshellarg("$scripts/read_smart.sh") . ' ' . escapeshellarg($dev));
    $s = json_decode((string) $raw, true) ?: [];
    if (($s['health'] ?? '') === '' && ($s['temp'] ?? '') === '') {
        echo '<span class="lu-muted">standby (SATA, not read)</span>'; exit;
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

/* ── Overview cards as HTML (the Monitor page's initial + auto-refresh load) ──
   The foreground request NEVER reads the hardware — it serves a result file.
   A slow storcli scan can take >60s; running it inline would get killed by the
   web timeout and leave nothing (that was the "no output" error). Instead a
   detached background job is the sole reader; the JS polls until it lands. */
if ($type === 'overview_html') {
    header('Content-Type: text/html; charset=utf-8');
    $cfg = lsi_config_read();

    // cached_read owns the freshness/lock/atomic-swap; this handler only turns a
    // ready result into cards (or a backend error) and a warming result into the
    // loading banner the JS polls on.
    $r = cached_read('overview', 60, 'bash ' . escapeshellarg("$scripts/get_hba_info.sh"));
    if ($r['state'] === 'ready') {
        $raw  = $r['body'];
        $data = $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($data) && !isset($data['error'])) { echo renderOverviewCards($data, $cfg); exit; }
        if (is_array($data) && isset($data['error'])) {
            echo '<div class="lu-error"><strong>Error:</strong> ' . htmlspecialchars($data['error']) . '</div>'; exit;
        }
        if (trim($raw) !== '') {
            echo '<div class="lu-error"><strong>Error:</strong> ' . htmlspecialchars(substr($raw, 0, 300)) . '</div>'; exit;
        }
    }
    echo '<div class="lu-loading" data-overview="warming">Reading controller information… the first read can take up to a minute on slow controllers. This updates automatically.</div>';
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

/* Render the Overview cards (one per controller) — same markup the Monitor page
   used to emit server-side, moved here so the initial load is async. */
function renderOverviewCards(array $data, array $cfg): string {
    $port      = $cfg['HBA_PORT'];
    $threshold = $cfg['ALERT_THRESHOLD'];
    $showPcie  = $cfg['SHOW_PCIE'];
    $driver    = $data['driver'] ?? '';
    $out = '<div class="lu-ov-grid">';
    foreach (lsi_controllers($data) as $i => $c) {
        if (isset($c['error'])) {
            $out .= '<div class="lu-card first"><div class="lu-error"><strong>Controller ' . $i . ':</strong> '
                  . htmlspecialchars($c['error']) . '</div></div>';
            continue;
        }
        $v = lsi_hba_view($c, $port, $i);
        $out .= '<div class="lu-card first" style="--tc:' . $v['color'] . '" data-ctl="' . $i . '">'
              . '<div class="lu-overview-row">'
              . '<div class="lu-circle" id="lu-circle-' . $i . '">'
              . '<span class="val" id="lu-val-' . $i . '">' . ($v['temp'] !== '' ? $v['temp'] : 'N/A') . '</span>'
              . '<span class="unit">' . ($v['temp'] !== '' ? '&deg;C' : 'no sensor') . '</span></div>'
              . '<div class="lu-meta">'
              . '<p>Model: <span>' . htmlspecialchars($v['model']) . '</span></p>'
              . '<p>Chip: <span>' . htmlspecialchars($v['chip']) . '</span></p>'
              . '<p>Firmware: <span>' . htmlspecialchars($v['firmware']) . '</span>'
              . ($v['fw_old'] ? ' <span style="color:#f39c12" title="P20 is the IT-mode baseline for SAS2">&#9888; pre-P20</span>' : '') . '</p>'
              . ($v['bios']   !== '' ? '<p>BIOS: <span>' . htmlspecialchars($v['bios']) . '</span></p>' : '')
              . ($driver      !== '' ? '<p>Driver: <span>' . htmlspecialchars($driver) . '</span></p>' : '')
              . ($v['mode']   !== '' ? '<p>Mode: <span>' . htmlspecialchars($v['mode']) . '</span></p>' : '')
              . ($v['drives'] !== '' ? '<p>Drives: <span>' . htmlspecialchars($v['drives']) . ' connected</span></p>' : '')
              . ($v['port_name'] !== '' ? '<p>lsiutil Port: <span>' . htmlspecialchars($v['port_label']) . '</span></p>' : '')
              . '<p>Alert Threshold: <span>' . $threshold . '&deg;C</span></p>'
              . '<span class="lu-badge" id="lu-badge-' . $i . '">' . $v['label'] . '</span>'
              . '</div></div>';
        if ($showPcie && (($c['pcie_width'] ?? '') || ($c['pcie_speed'] ?? ''))) {
            $out .= '<hr class="lu-divider"><div class="lu-pcie-row">';
            foreach ($v['pcie'] as $item) {
                $out .= '<div class="lu-pcie-item">' . $item['label'] . ': <span>' . htmlspecialchars($item['value']) . '</span></div>';
            }
            $out .= '</div>';
        }
        $out .= '<div class="lu-ts" id="lu-ts-' . $i . '">Last read: ' . date('H:i:s') . '</div></div>';
    }
    return $out . '</div>';
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
    $ctls    = $data['controllers'] ?? [$data];
    $storcli = ($data['backend'] ?? '') === 'storcli';
    $multi   = count($ctls) > 1;
    $out   = '';
    foreach ($ctls as $i => $ctl) {
        if ($multi) $out .= luCtlHead($i);
        if (isset($ctl['error'])) { $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['error']) . '</p>'; continue; }
        $phys = $ctl['phys'] ?? [];
        if (empty($phys)) { $out .= '<p class="lu-muted">No PHY data.</p>'; continue; }

        // storcli backend if stamped; fall back to key-sniff pre-rollout.
        if ($storcli || (($data['backend'] ?? '') === '' && isset($phys[0]['speed']))) {
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
    $ctls    = $data['controllers'] ?? [$data];
    $storcli = ($data['backend'] ?? '') === 'storcli';
    $multi   = count($ctls) > 1;
    $out   = '';
    foreach ($ctls as $i => $ctl) {
        if ($multi) $out .= luCtlHead($i);
        if (isset($ctl['error'])) { $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['error']) . '</p>'; continue; }

        // Enclosure/topology summary (storcli). VirtualSES = direct-attach, no expander.
        foreach ($ctl['enclosures'] ?? [] as $e) {
            $mode = !empty($e['direct']) ? 'direct-attach (no expander)' : 'expander / backplane';
            $out .= '<p class="lu-muted" style="font-size:12px;margin:0 0 8px">Enclosure e' . htmlspecialchars($e['eid'])
                  . ': ' . htmlspecialchars($e['product']) . ' (' . htmlspecialchars($e['vendor']) . ') &middot; '
                  . htmlspecialchars($e['slots']) . ' slots &middot; ' . htmlspecialchars($e['drives'])
                  . ' drives &middot; ' . $mode . '</p>';
        }

        $drives = $ctl['drives'] ?? [];
        if (empty($drives)) { $out .= '<p class="lu-muted">No drives detected.</p>'; continue; }

        // storcli backend if stamped; fall back to key-sniff pre-rollout.
        if ($storcli || (($data['backend'] ?? '') === '' && isset($drives[0]['slot']))) {
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
                    !empty($d['sas_address']) ? '<code>' . strtoupper($d['sas_address']) . '</code>' : '<span class="lu-muted">—</span>',
                    htmlspecialchars($d['link']),
                    htmlspecialchars($d['firmware']),
                    $smart,
                ];
            }
            $out .= luTable(['Encl:Slot', 'Port', 'Model', 'Serial', 'State', 'Size', 'SAS Address', 'Link', 'Firmware', 'SMART'], $rows);
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

/* ── Event Log (per controller; persisted to /boot across reboots) ─────────── */
if ($type === 'events') {
    $ctls    = $data['controllers'] ?? [$data];
    $storcli = ($data['backend'] ?? '') === 'storcli';
    $multi   = count($ctls) > 1;
    $out   = '';
    foreach ($ctls as $i => $ctl) {
        if ($multi) $out .= luCtlHead($i);
        if (isset($ctl['error'])) { $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['error']) . '</p>'; continue; }
        if (!empty($ctl['note'])) $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['note']) . '</p>';

        $file = event_store_path($i);
        [$entries, $changed] = event_merge(event_store_read($file), $ctl['entries'] ?? []);
        if ($changed) event_store_write($file, $entries);
        if (empty($entries)) { $out .= '<p class="lu-muted">No log entries.</p>'; continue; }
        $out .= '<p class="lu-muted" style="font-size:11px;margin:0 0 8px">'
              . count($entries) . ' entries &middot; archived to /boot (survives reboots &amp; ring-buffer wrap)</p>';

        // storcli backend if stamped; fall back to key-sniff pre-rollout.
        if ($storcli || (($data['backend'] ?? '') === '' && isset($entries[0]['description']))) {
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
