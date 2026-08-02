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
require_once __DIR__ . '/health.php';
// Read path only. phy_baseline.php's own dispatch fires solely on a POST
// carrying reset_baseline, so requiring it here cannot mutate anything.
require_once __DIR__ . '/phy_baseline.php';

/* ── Request dispatch (served only; skipped under the CLI test runner) ───────
   Everything below this line either shells out to the hardware-reading scripts
   or renders a response for one request. The render functions themselves are
   declared at file scope, so they are compiled and callable even though this
   return skips past their definitions — which is what lets tests require this
   file and exercise the table builders without touching a controller.
   Same posture as flash.php. */
if (PHP_SAPI === 'cli') return;

$type    = in_array($_GET['type'] ?? '', ['overview','overview_html','phy','drives','events','smart','smart_all','metrics','health'])
           ? $_GET['type'] : 'overview';
$scripts = '/usr/local/emhttp/plugins/hbaviewer/scripts';

/* A collector that was killed (or whose smartctl wedged) leaves its progress
   marker behind, and /tmp only clears on reboot — so treat a marker this stale
   as a dead job and start a fresh collection instead of reporting progress
   forever. The collector rewrites the marker once per drive, so a live one
   never goes this quiet.
   ponytail: a wall-clock timeout, not a liveness check on the PID — a PID file
   is the upgrade path if a collector ever legitimately stalls this long. */
const SMART_PROGRESS_TTL = 300;

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
    if (($_GET['refresh'] ?? '') === '1') { @unlink($cache); @unlink($prog); }

    if (is_file($cache) && (time() - filemtime($cache)) < 600) {
        echo renderSmartTable(json_decode((string) file_get_contents($cache), true) ?: []);
        exit;
    }
    if (is_file($prog) && (time() - filemtime($prog)) < SMART_PROGRESS_TTL) {
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

/* ── HBA Health tab: five sub-indicators + a worst-of rollup (plan 020) ─────
   get_hba_health.sh emits a stateless SAMPLE per controller; this handler is
   the only place that touches the /tmp ring — persistence is PHP's job,
   never the shell's (see health.php's header). */
if ($type === 'health') {
    header('Content-Type: text/html; charset=utf-8');
    $raw  = shell_exec("bash $scripts/get_hba_health.sh 2>/dev/null");
    $data = $raw ? json_decode($raw, true) : null;
    if (!$data || isset($data['error'])) {
        $msg = htmlspecialchars($data['error'] ?? 'Script returned no data.');
        echo '<div class="lu-error"><strong>Error:</strong> ' . $msg . '</div>';
        exit;
    }
    echo renderHealthTables($data);
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
        // Critical renders as an inverted chip (white on solid fill) — #922b21
        // measures 1.94:1 as plain text on a dark card and is unreadable there.
        $isCrit   = ($v['temp_band'] ?? '') === 'critical';
        $tempChip = $isCrit
            ? '<span style="background:' . lsi_temp_color('critical') . ';color:#fff;padding:2px 7px;border-radius:2px;font-weight:700">CRITICAL</span>'
            : htmlspecialchars($v['temp_label']);   // colour comes from the tile's --mark
        // The gauge reads 0-110C. Gradient ids must not collide when several
        // controllers render on one page, hence the index — and the Health tab
        // lives in the same DOM, so it uses its own prefix.
        [$gDark, $gLight] = $v['temp_grad'];
        $frac  = $v['temp'] !== '' ? max(0.0, min(1.0, (float) $v['temp'] / 110)) : 0.0;
        $out .= '<div class="lu-card first" style="--td:' . $gDark . ';--tl:' . $gLight . ';--sc:' . $v['color'] . '" data-ctl="' . $i . '">'
              . '<div class="lu-overview-row">'
              . '<div class="lu-gauge lu-tile' . (lsi_tile_is_light() ? ' light' : '') . '" id="lu-circle-' . $i . '">'
              . '<div class="lu-arc-wrap">'
              . lsi_gauge_svg('lu-grad-' . $i, $frac, [$gDark, $gLight])
              . '<div class="lu-arc-readout">'
              . '<span class="val" id="lu-val-' . $i . '">' . ($v['temp'] !== '' ? $v['temp'] : 'N/A') . '</span>'
              . '<span class="unit">' . ($v['temp'] !== '' ? '&deg;C' : 'no sensor') . '</span></div></div>'
              . '<span class="lu-temp-band">' . $tempChip . '</span>'
              . '</div>'
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
              . '<p>Badge Sensitivity: <span>' . htmlspecialchars($v['cfg_band_label']) . ' (' . $threshold . '&deg;C+)</span></p>'
              . '<p>Last read: <span>' . lsi_time() . '</span></p>'
              . '<p>HBA Health: <span class="lu-badge" id="lu-badge-' . $i . '">' . $v['label'] . '</span></p>'
              . '</div></div>';
        if ($showPcie && (($c['pcie_width'] ?? '') || ($c['pcie_speed'] ?? ''))) {
            $out .= '<hr class="lu-divider"><div class="lu-pcie-row">';
            foreach ($v['pcie'] as $item) {
                $out .= '<div class="lu-pcie-item">' . $item['label'] . ': <span>' . htmlspecialchars($item['value']) . '</span></div>';
            }
            $out .= '</div>';
        }
        $out .= '</div>';
    }
    return $out . '</div>';
}

/* ── PHY Health (per controller; columns adapt to the detected backend) ────── */
function luCtlHead(int $i): string {
    // No top margin: this is now the first child of its controller's card, and
    // the card already supplies 18px of padding above it.
    return '<h3 style="margin:0 0 10px;color:#f5a623;font-size:12px;'
         . 'text-transform:uppercase;letter-spacing:0.06em;">Controller /c' . $i . '</h3>';
}
function luLinkBadge(string $link): string {
    return strtolower($link) === 'up'
        ? '<span class="lu-link-up">UP</span>' : '<span class="lu-link-down">DOWN</span>';
}

/* Per-controller baseline bar (plan 022 Step 1: per-controller, not per-PHY —
   precise enough to baseline the card whose cable you just reseated, without a
   button on every row). Always states WHEN the baseline was taken: a baseline
   set at install and never touched measures "errors since install", which is
   the raw counter wearing a rate's clothes. */
function luPhyBaselineBar(int $ctl, ?int $ts, bool $stale): string {
    if ($stale) {
        $note = '<span class="lu-phy-stale">Baseline reset by reboot or driver reload — press Reset Baseline to re-establish.</span>';
    } elseif ($ts === null) {
        $note = '<span class="lu-muted">No baseline set — counters are cumulative since the driver loaded.</span>';
    } else {
        $note = '<span class="lu-muted">Baseline set ' . htmlspecialchars(date('Y-m-d', $ts) . ' ' . lsi_time($ts)) . '</span>';
    }
    return '<div class="lu-phy-bar">' . $note
         . '<button class="lu-refresh-btn" onclick="luPhyBaseline(' . $ctl . ', this)">'
         . ($ts === null ? 'Set Baseline' : 'Reset Baseline') . '</button></div>';
}

/* One counter cell: the raw counter exactly as before, plus a delta-since-
   baseline and a rate when this PHY has a usable baseline. Omitted entirely
   when there is none — a "0" there would read as "no errors" rather than "no
   reference point". A negative delta can never reach this: phy_baseline_delta()
   reports a counter restart as `reset`, and the controller then renders
   raw-only behind the bar's re-baseline prompt. */
function luPhyCell($v, bool $err, ?array $d, string $k): string {
    $s    = htmlspecialchars((string) $v);
    $cell = $err ? '<span class="lu-err-val">' . $s . '</span>' : $s;
    if ($d === null || !empty($d['reset'])) return $cell;
    $r = $d['rate'][$k];
    return $cell . '<div class="lu-phy-delta">&Delta;' . (int) $d['delta'][$k]
         . ' &middot; ' . number_format($r, $r > 0 && $r < 10 ? 1 : 0) . '/hr</div>';
}

/* $baselines defaults to none, so every existing caller (and the raw-only
   fresh install) renders exactly what it rendered before this plan. */
function renderPhyTables(array $data, array $baselines = [], ?int $now = null, ?int $uptime = null): string {
    $ctls    = $data['controllers'] ?? [$data];
    $storcli = ($data['backend'] ?? '') === 'storcli';
    $multi   = count($ctls) > 1;
    $now   ??= time();
    $uptime ??= phy_baseline_uptime();
    $out   = '';
    foreach ($ctls as $i => $ctl) {
        // One card per HBA (see renderOverviewCards). Both early-outs below close
        // it too: an errored or PHY-less controller still gets its own card
        // instead of bare text floating between its neighbours'.
        $out .= '<div class="lu-card first" data-ctl="' . $i . '">';
        if ($multi) $out .= luCtlHead($i);
        if (isset($ctl['error'])) { $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['error']) . '</p></div>'; continue; }
        $phys = $ctl['phys'] ?? [];
        if (empty($phys)) { $out .= '<p class="lu-muted">No PHY data.</p></div>'; continue; }

        // Resolve every PHY's delta first: a reboot or driver reload zeroes the
        // whole controller's counters at once, so one invalidated PHY condemns
        // the controller's baseline rather than just its own row.
        $bl     = phy_baseline_for($baselines, (int) $i);
        $ts     = phy_baseline_ts($baselines, (int) $i);
        $deltas = [];
        $stale  = false;
        foreach ($phys as $n => $p) {
            $d = phy_baseline_delta($bl[(int) ($p['phy'] ?? -1)] ?? null, $p, $now, $uptime);
            if ($d !== null && !empty($d['reset'])) $stale = true;
            $deltas[$n] = $d;
        }
        if ($stale) $deltas = array_map(fn() => null, $deltas);
        // $ts is passed through even when stale: a stale baseline still EXISTS,
        // so the button must read "Reset Baseline" — the same words the stale
        // note tells the user to press.
        $out .= luPhyBaselineBar((int) $i, $ts, $stale);

        // storcli backend if stamped; fall back to key-sniff pre-rollout.
        if ($storcli || (($data['backend'] ?? '') === '' && isset($phys[0]['speed']))) {
            // storcli backend: link/speed/attached-SAS (storcli) + error counters (sysfs)
            $rows = [];
            foreach ($phys as $n => $p) {
                $hasErr = (($p['inv'] ?? 0) + ($p['disp'] ?? 0) + ($p['sync'] ?? 0) + ($p['reset'] ?? 0)) > 0;
                $d  = $deltas[$n];
                $ec = fn($k) => luPhyCell($p[$k] ?? 0, $hasErr && ($p[$k] ?? 0) > 0, $d, $k);
                $rows[] = [
                    htmlspecialchars((string) $p['phy']),
                    luLinkBadge($p['link']),
                    htmlspecialchars($p['speed']),
                    !empty($p['sas_addr']) ? '<code>' . htmlspecialchars(strtoupper($p['sas_addr'])) . '</code>' : '<span class="lu-muted">—</span>',
                    $ec('inv'), $ec('disp'), $ec('sync'), $ec('reset'),
                ];
            }
            $out .= luTable(['PHY', 'Link', 'Speed', 'Attached SAS Address', 'Invalid DWords', 'Disparity Errors', 'Loss of Sync', 'Reset Problems'], $rows);
        } else {
            // lsiutil backend: SAS error counters
            $rows = [];
            foreach ($phys as $n => $p) {
                $hasErr = ($p['inv'] + $p['disp'] + $p['sync'] + $p['reset']) > 0;
                $d  = $deltas[$n];
                $ec = fn($k) => luPhyCell($p[$k], $hasErr, $d, $k);
                $rows[] = [
                    htmlspecialchars((string) $p['phy']),
                    luLinkBadge($p['link']),
                    $ec('inv'), $ec('disp'), $ec('sync'), $ec('reset'),
                ];
            }
            $out .= luTable(['PHY', 'Link', 'Invalid DWords', 'Disparity Errors', 'Loss of Sync', 'Reset Problems'], $rows);
        }
        $out .= '</div>';
    }
    return $out;
}

if ($type === 'phy') { echo renderPhyTables($data, phy_baseline_read()); exit; }

/* ── Attached Drives (per controller; columns adapt to the backend) ───────── */
function renderDrivesTables(array $data): string {
    $ctls    = $data['controllers'] ?? [$data];
    $storcli = ($data['backend'] ?? '') === 'storcli';
    $multi   = count($ctls) > 1;
    $out   = '';
    foreach ($ctls as $i => $ctl) {
        // One card per HBA (see renderOverviewCards). Both early-outs below close
        // it too: an errored or driveless controller still gets its own card
        // instead of bare text floating between its neighbours'.
        $out .= '<div class="lu-card first" data-ctl="' . $i . '">';
        if ($multi) $out .= luCtlHead($i);
        if (isset($ctl['error'])) { $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['error']) . '</p></div>'; continue; }

        // Enclosure/topology summary (storcli). VirtualSES = direct-attach, no expander.
        // storcli_drives.sh emits "eid/slot" when a drive carries an enclosure ID and a
        // bare "slot" when it does not. If NO drive on this controller carries one, the
        // enclosure's own slot/drive counts describe something the drives aren't
        // attached to — showing "0 drives" above 15 rows reads as a bug (issue #6).
        $dl = $ctl['drives'] ?? [];
        $enclLess = $dl !== [] && !array_filter($dl, fn($d) => str_contains((string) ($d['slot'] ?? ''), '/'));
        foreach ($ctl['enclosures'] ?? [] as $e) {
            $mode  = !empty($e['direct']) ? 'direct-attach (no expander)' : 'expander / backplane';
            // Only state a slot/drive count when storcli actually reported one —
            // an empty Properties section previously rendered as "8 slots / 0 drives"
            // on a controller with 15 drives. Also suppress when this controller's
            // drives are addressed without an enclosure (issue #6): the counts are
            // real but describe nothing the drive table shows.
            $counts = !$enclLess && ($e['slots'] ?? '') !== '' && ($e['drives'] ?? '') !== ''
                ? htmlspecialchars($e['slots']) . ' slots &middot; ' . htmlspecialchars($e['drives']) . ' drives &middot; '
                : '';
            $out .= '<p class="lu-muted" style="font-size:12px;margin:0 0 8px">Enclosure e' . htmlspecialchars($e['eid'])
                  . ': ' . htmlspecialchars($e['product']) . ' (' . htmlspecialchars($e['vendor']) . ') &middot; '
                  . $counts . $mode . ($enclLess ? ' &middot; drives are addressed without an enclosure' : '') . '</p>';
        }

        $drives = $ctl['drives'] ?? [];
        if (empty($drives)) { $out .= '<p class="lu-muted">No drives detected.</p></div>'; continue; }

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
                    !empty($d['sas_address']) ? '<code>' . htmlspecialchars(strtoupper($d['sas_address'])) . '</code>' : '<span class="lu-muted">—</span>',
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
                $os  = !empty($d['os_name'])     ? '<code>' . htmlspecialchars($d['os_name']) . '</code>'                : '<span class="lu-muted">—</span>';
                $sas = !empty($d['sas_address']) ? '<code>' . htmlspecialchars(strtoupper($d['sas_address'])) . '</code>' : '<span class="lu-muted">—</span>';
                $phy = isset($d['phy']) && $d['phy'] !== '' ? 'PHY ' . htmlspecialchars((string) $d['phy'])              : '<span class="lu-muted">—</span>';
                $rows[] = [
                    htmlspecialchars((string) $d['bus']) . ':' . htmlspecialchars((string) $d['target']),
                    $phy, $sas, $os,
                ];
            }
            $out .= luTable(['Bus:Tgt', 'Port', 'SAS Address', 'OS Device'], $rows);
        }
        $out .= '</div>';
    }
    return $out;
}

if ($type === 'drives') { echo renderDrivesTables($data); exit; }

/* ── Event Log (per controller; persisted to /boot across reboots) ─────────── */
/* $dir is the archive location; it is injectable so tests can point the store
   at a temp directory instead of the boot flash. */
function renderEventsTables(array $data, string $dir = '/boot/config/plugins/hbaviewer'): string {
    $ctls    = $data['controllers'] ?? [$data];
    $storcli = ($data['backend'] ?? '') === 'storcli';
    $multi   = count($ctls) > 1;
    $out   = '';
    foreach ($ctls as $i => $ctl) {
        // One card per HBA (see renderOverviewCards). Both early-outs below close
        // it too: an errored or entry-less controller still gets its own card
        // instead of bare text floating between its neighbours'.
        $out .= '<div class="lu-card first" data-ctl="' . $i . '">';
        if ($multi) $out .= luCtlHead($i);
        if (isset($ctl['error'])) { $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['error']) . '</p></div>'; continue; }
        if (!empty($ctl['note'])) $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['note']) . '</p>';

        $file = event_store_path($i, $dir);
        [$archived, $changed] = event_merge(event_store_read($file), $ctl['entries'] ?? []);
        if ($changed) event_store_write($file, $archived);
        // Archive everything, display only what this backend's table can format.
        // A box that switched backend keeps its old entries on disk; showing them
        // through the wrong renderer produces undefined-key warnings and blank rows.
        $entries = event_visible($archived, $data['backend'] ?? '');
        $hidden  = count($archived) - count($entries);
        if (empty($entries)) { $out .= '<p class="lu-muted">No log entries.</p></div>'; continue; }
        $out .= '<p class="lu-muted" style="font-size:11px;margin:0 0 8px">'
              . count($entries) . ' entries &middot; archived to /boot (survives reboots &amp; ring-buffer wrap)'
              . ($hidden > 0 ? ' &middot; ' . $hidden . ' from a previous backend not shown' : '') . '</p>';

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
                    htmlspecialchars((string) $e['seq']),
                    '<code>' . htmlspecialchars((string) $e['qualifier']) . '</code>',
                    '<code>' . htmlspecialchars($e['data']) . '</code>',
                    '<code>' . htmlspecialchars((string) $e['timestamp']) . '</code>',
                ];
            }
            $out .= luTable(['Seq', 'Qualifier', 'Data', 'Timestamp'], $rows);
        }
        $out .= '</div>';
    }
    return $out;
}

if ($type === 'events') { echo renderEventsTables($data); exit; }

/* ── HBA Health (per controller; five indicator rows + a rollup pill) ──────
   Cosmetic-only best-effort board/chip label pulled from the existing 60s
   overview cache (get_hba_info.sh already maintains it) — get_hba_health.sh
   itself emits no board/chip fields, since health.php's ring/rate logic
   never needs them. Missing cache -> just the /cN label, nothing breaks. */
function luHealthCtlMeta(int $i): array {
    $cache = getenv('LSI_CACHE') ?: '/tmp/lsiutil_dash.json';
    if (!is_file($cache)) return ['board' => '', 'chip' => ''];
    $d = json_decode((string) @file_get_contents($cache), true);
    $ctls = lsi_controllers(is_array($d) ? $d : []);
    $c = $ctls[$i] ?? [];
    return ['board' => $c['board_name'] ?? '', 'chip' => $c['model'] ?? ''];
}

function renderHealthTables(array $data): string {
    $ctls  = $data['controllers'] ?? [$data];
    $multi = count($ctls) > 1;
    $out   = '';
    foreach ($ctls as $i => $ctl) {
        // One card per HBA, matching renderOverviewCards — including the error
        // branch below, or an errored controller renders as bare text floating
        // between two cards.
        $out .= '<div class="lu-card first" data-ctl="' . $i . '">';
        if ($multi) $out .= luCtlHead($i);
        if (isset($ctl['error'])) { $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['error']) . '</p></div>'; continue; }

        // The only place that touches the /tmp ring — see health.php's header.
        $file  = health_store_path($i);
        $ring  = health_ingest(health_store_read($file), $ctl);
        health_store_write($file, $ring);

        $rates = health_rates($ring);
        $ind   = health_indicators($ring, $rates, time());
        [$state, $reason] = health_rollup($ind);

        $meta  = luHealthCtlMeta($i);
        $fw    = (string) ($ctl['fw'] ?? '');
        $pill  = lsi_health_color($state);

        $out .= '<div class="lu-health-head">'
              . '<span class="lu-health-title">'
              . ($meta['board'] !== '' ? htmlspecialchars($meta['board']) . ' &middot; ' : '')
              . '/c' . $i
              . ($meta['chip'] !== '' ? ' &middot; ' . htmlspecialchars($meta['chip']) : '')
              . ($fw !== '' ? ' &middot; FW ' . htmlspecialchars($fw) : '')
              . '</span>'
              . '<span class="lu-health-pill" style="color:' . $pill . ';background:color-mix(in srgb,' . $pill . ' 15%, transparent)">'
              . htmlspecialchars(ucfirst($state)) . ' &mdash; ' . htmlspecialchars($reason)
              . '</span></div>';

        // Gauge + band meter share one instrument tile. The gauge reads
        // "N / total indicators ok" — a count of what health_indicators()
        // actually returned, NOT a 0-100 score (plan 030, option A): the
        // indicators are categorical and a manufactured score that drifts from
        // 89 to 87 for unexplainable reasons is worse than no number.
        $g      = health_gauge($ind);
        $gStops = lsi_health_gradient($state);
        $out .= '<div class="lu-tile lu-health-tile' . (lsi_tile_is_light() ? ' light' : '')
              . '" style="--td:' . $gStops[0] . ';--tl:' . $gStops[1] . '">'
              . '<div class="lu-gauge"><div class="lu-arc-wrap">'
              . lsi_gauge_svg('lu-hgrad-' . $i, $g['frac'], $gStops)
              . '<div class="lu-arc-readout count"><span class="val">' . $g['ok'] . ' / ' . $g['total'] . '</span>'
              . '<span class="unit">indicators ok</span></div></div></div>';

        // Only thermal earns a band meter: it is the one continuous metric with
        // meaningful bands. Scaled 0-110C with segment boundaries at the
        // plan-018 band cut-points (65/75/85/95): each label's inline `left`
        // below is that boundary's true percentage of 110 — NOT evenly spaced
        // — and must stay in step with the .lu-band-seg flex weights in
        // hbaviewer.php; both encode the same band edges, just in different files.
        $temp = $ctl['temp'] ?? null;
        if ($temp !== null && $temp !== '') {
            $pct = max(0, min(100, ((float) $temp / 110) * 100));
            $out .= '<div class="lu-band-meter"><div class="lu-band-track">'
                  . '<span class="lu-band-seg s0"></span><span class="lu-band-seg s1"></span>'
                  . '<span class="lu-band-seg s2"></span><span class="lu-band-seg s3"></span><span class="lu-band-seg s4"></span>'
                  . '<span class="lu-band-marker" style="left:' . number_format($pct, 1) . '%" title="' . htmlspecialchars((string) $temp) . '&deg;C"></span>'
                  . '</div><div class="lu-band-labels">'
                  . '<span style="left:0%">0</span><span style="left:59.09%">65</span>'
                  . '<span style="left:68.18%">75</span><span style="left:77.27%">85</span>'
                  . '<span style="left:86.36%">95</span><span style="left:100%">110</span></div></div>';
        }
        $out .= '</div>';

        // Order and labels mirror hbaviewer.php's header sentence ("Thermal, link
        // integrity, topology, host link, and read health"), which is also
        // health_indicators()'s return order. Every key it returns must appear
        // here: the gauge above counts all of them, so an omitted row makes the
        // count contradict the list beneath it (plan 031 — `thermal` was missing).
        $out .= '<div class="lu-indicator-rows">';
        foreach (['thermal' => 'Thermal', 'link_integrity' => 'Link Integrity', 'topology' => 'Topology', 'host_link' => 'Host Link', 'controller' => 'Read Health'] as $key => $label) {
            $row = $ind[$key] ?? ['state' => 'unknown', 'value' => '—'];
            [$bDark, $bLight] = lsi_health_gradient($row['state']);
            // Sprite ids live in hbaviewer.php's #lu-wrap. Most match $key; these
            // two do not, and a mismatch renders an empty icon slot silently.
            $icon = ['link_integrity' => 'link', 'host_link' => 'hostlink'][$key] ?? $key;
            $out .= '<div class="lu-indicator-row">'
                  . '<span class="lu-ind-dot" style="--gd:' . $bDark . ';--gl:' . $bLight . '"></span>'
                  . '<svg class="lu-ind-icon" aria-hidden="true"><use href="#lu-i-' . $icon . '"/></svg>'
                  . '<span class="lu-indicator-label">' . htmlspecialchars($label) . '</span>'
                  . '<span class="lu-indicator-value">' . htmlspecialchars((string) ($row['value'] ?? '')) . '</span></div>';
        }
        $out .= '</div></div>';
    }
    return $out;
}
