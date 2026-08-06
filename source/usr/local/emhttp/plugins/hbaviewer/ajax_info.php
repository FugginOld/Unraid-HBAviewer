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
// Same posture: bay_map.php's dispatch fires only on a POST carrying an action.
require_once __DIR__ . '/bay_map.php';
// And locate.php's, so the tables can ask which drives are currently blinking.
require_once __DIR__ . '/locate.php';

/* Where the background SMART collector writes. ABOVE the dispatch on purpose:
   a `function` is hoisted and can be called from anywhere in the file, but a
   top-level `const` is an ordinary statement that only exists once execution
   reaches it — so a const declared next to the functions that use it is
   undefined for every endpoint above, which is exactly how the SMART tab broke.
   Declared here, it is defined before the first endpoint runs AND under the CLI
   test runner, which returns below.

   THERE IS NO TTL. A collection reads every drive with smartctl, which takes
   ~1s per drive and can wake nothing but still costs 20-30s on a full shelf —
   paying that on a timer, for data that changes over weeks, made both the SMART
   tab and the bay map feel broken. The cache is now kept until the person
   presses Refresh. What replaces the TTL is honesty: every surface that renders
   this data states how old it is (lsi_age_str), so nobody reads a three-day-old
   temperature as current.
   Still /tmp, not /boot: a reboot costing one re-collect is a fair price for
   never writing this to the flash drive. */
const SMART_CACHE_PATH = '/tmp/lsiutil_smart.json';

/* Unraid's own state files, read for the array slot names and the parity
   rebuild. Up here with SMART_CACHE_PATH for the same reason, one step worse:
   these two are DEFAULT PARAMETER VALUES of functions, which resolve when the
   function is called, not where it is written — so a call from any endpoint
   above their declaration would fatal even though the function itself is
   hoisted. */
const UNRAID_VARINI  = '/var/local/emhttp/var.ini';
const UNRAID_DISKINI = '/var/local/emhttp/disks.ini';

/* ── Request dispatch (served only; skipped under the CLI test runner) ───────
   Everything below this line either shells out to the hardware-reading scripts
   or renders a response for one request. The render functions themselves are
   declared at file scope, so they are compiled and callable even though this
   return skips past their definitions — which is what lets tests require this
   file and exercise the table builders without touching a controller.
   Same posture as flash.php. */
if (PHP_SAPI === 'cli') return;

$type    = in_array($_GET['type'] ?? '', ['overview','overview_html','phy','drives','baymap','events','smart','smart_all','metrics','health'])
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
    $cache = SMART_CACHE_PATH;
    $prog  = $cache . '.progress';
    if (($_GET['refresh'] ?? '') === '1') { @unlink($cache); @unlink($prog); }

    // Any cache at all is served, however old. Re-reading every drive is the
    // expensive thing here, and the person asked for it exactly when they press
    // Refresh (which unlinks the cache above) — not on a timer.
    $cached = smart_cache_read();
    if ($cached !== null) { echo renderSmartTable($cached, smart_cache_age(), unraid_disk_roles()); exit; }
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

    $color = smart_state_color(smart_state($s));
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
    // The bay map is the same drive list as the Drives tab, joined against the
    // SMART cache and the stored positions further down.
    'baymap' => "$scripts/get_attached_drives.sh",
];

$raw  = shell_exec('bash ' . escapeshellarg($scriptMap[$type]) . ' 2>/dev/null');
$data = $raw ? json_decode($raw, true) : null;

if (!$data || isset($data['error'])) {
    // The bay map is the one consumer here that expects JSON; handing it an
    // HTML error block would surface as a silent parse failure in the view.
    if ($type === 'baymap') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $data['error'] ?? 'Script returned no data.']);
        exit;
    }
    $msg = htmlspecialchars($data['error'] ?? 'Script returned no data.');
    echo '<div class="lu-error"><strong>Error:</strong> ' . $msg . '</div>';
    exit;
}

/* ── Shared helpers ────────────────────────────────────────────────────────── */
/* Wrapped in its own scroller, not left to overflow the card. Plan 050 added
   "· N/hr in the last 10 min" to every error cell on the PHY tab, which roughly
   doubled the width of four columns and pushed the table out through the right
   edge of its panel — the data was rendered and unreachable. The wrapper is on
   luTable rather than on that one tab because every wide table has the same
   exposure, and overflow-x:auto costs nothing on a table that already fits. */
function luTable(array $headers, array $rows): string {
    $h = '<div class="lu-tscroll"><table class="lu-table"><thead><tr>';
    foreach ($headers as $hdr) $h .= '<th>' . htmlspecialchars($hdr) . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $cols) {
        $h .= '<tr>';
        foreach ($cols as $cell) $h .= '<td>' . $cell . '</td>';
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}

/* ── The background SMART cache: one reader, one health rule ────────────────
   collect_smart.sh writes {"drives":[{dev,serial,model,smart:{…}}]} here. Both
   the SMART tab and the bay map read it, and they must never disagree about a
   drive, so neither gets its own copy of "where is it / is it fresh / is it
   healthy" (plan 047's STOP condition).
   Returns null when there is no usable cache — deliberately NOT [], because a
   collected-and-genuinely-empty cache (a box with no drives) has to be
   distinguishable from one that was never collected, or the SMART tab would
   relaunch its collector on every visit forever. */
function smart_cache_read(?string $path = null): ?array {
    $path ??= SMART_CACHE_PATH;
    if (!is_file($path)) return null;
    $d = json_decode((string) file_get_contents($path), true);
    return is_array($d) ? $d : null;
}

/* Seconds since the cache was written, or null when there is none. Every
   caller that renders cached SMART data must show this — a reading with no
   stated age is a reading the reader assumes is live. */
function smart_cache_age(?string $path = null, ?int $now = null): ?int {
    $path ??= SMART_CACHE_PATH;
    if (!is_file($path)) return null;
    return max(0, ($now ?? time()) - (int) filemtime($path));
}

/* One drive's SMART verdict: 'ok' | 'warn' | 'fail' | 'nodata'.
   'nodata' covers both "never collected" and "asleep, deliberately not woken"
   — the two cases where we know nothing. It is a distinct state, not a
   fall-through to healthy: a bay coloured green for a drive nobody has read
   is the exact failure this is here to prevent. */
function smart_state(array $s): string {
    $health = strtoupper((string) ($s['health'] ?? ''));
    if ($health === '') return 'nodata';
    if ($health !== 'OK' && $health !== 'PASSED') return 'fail';
    return ((int) ($s['defects'] ?? 0) > 0 || (int) ($s['pending'] ?? 0) > 0) ? 'warn' : 'ok';
}

/* The colours those states have always rendered as, kept in step across the
   SMART table, the per-drive line and the bay map. */
function smart_state_color(string $state): string {
    return ['fail' => '#e74c3c', 'warn' => '#f39c12', 'ok' => '#2ecc71'][$state] ?? '';
}

/* Render the background-collected SMART cache as a table. $ageSecs is how old
   that collection is; it is printed above the table because the cache is now
   kept until someone refreshes it, and an unlabelled table of week-old
   temperatures reads exactly like a live one. */
function renderSmartTable(array $data, ?int $ageSecs = null, array $roles = []): string {
    $drives = $data['drives'] ?? [];
    if (!$drives) return '<p class="lu-muted">No drives found.</p>';
    $age = $ageSecs === null ? '' :
        '<p class="lu-muted" style="font-size:11px;margin:0 0 8px">Collected ' . htmlspecialchars(lsi_age_str($ageSecs))
        . ' ago &middot; kept until you press Refresh</p>';
    $dash = '<span class="lu-muted">—</span>';
    $rows = [];
    foreach ($drives as $d) {
        $s     = $d['smart'] ?? [];
        $state = smart_state($s);
        $hb    = $state === 'nodata'
            ? '<span class="lu-muted">standby</span>'
            : '<span style="color:' . smart_state_color($state) . ';font-weight:700">'
              . htmlspecialchars($s['health']) . '</span>';
        $cell = fn($v, $suf = '') => ($v ?? '') !== '' ? htmlspecialchars((string) $v) . $suf : $dash;
        $rows[] = [
            '<code>' . htmlspecialchars($d['dev'] ?? '') . '</code>',
            lsi_role_cell($d['dev'] ?? null, $roles),
            htmlspecialchars($d['model'] ?? ''),
            ($s['transport'] ?? '') !== '' ? htmlspecialchars(strtoupper($s['transport'])) : $dash,
            '<code>' . htmlspecialchars($d['serial'] ?? '') . '</code>',
            $hb,
            $cell($s['temp'] ?? '', '&deg;C'),
            $cell($s['defects'] ?? ''),
            $cell($s['pending'] ?? ''),
            ($s['power_on_hours'] ?? '') !== '' ? number_format((int) $s['power_on_hours']) . 'h' : $dash,
        ];
    }
    return $age . luTable(['Device', 'Unraid', 'Model', 'Type', 'Serial', 'Health', 'Temp', 'Reallocated', 'Pending', 'Power-On'], $rows);
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
   raw-only behind the bar's re-baseline prompt.

   That rate is an AVERAGE spanning however long ago the baseline was set —
   not a current condition. A burst of errors from days ago still divides down
   to a small "X/hr" that never reaches zero, while the Health tab's much more
   recent ring can show the link is clean right now (issue: two tabs disagreed
   with no explanation, plan 050). "since baseline" plus the title tooltip say
   what the number answers; $recent, when the Health tab's own ring is usable
   for this PHY, says what has happened lately, on its own line beneath the
   average rather than in place of it — never hide the historical number, only
   add to it. Stacked, not joined by a separator: the two together ran to about
   fifty monospace characters in every one of four counter columns, which is
   what pushed this table wider than its card (the horizontal scroller added in
   a65abc1 was treating the symptom). Two short lines cost a row of height and
   give the columns back.
   $recent is health_rates()'s per-PHY row (keyed 'rst', not 'reset' — the two
   subsystems name that counter differently, see phy_top_offenders() and
   health.php's header) or null when the ring cannot support one yet:
   $ringSpanSecs travels with it purely to word "in the last N" — absence
   prints nothing extra, never a misleading zero. */
function luPhyCell($v, bool $err, ?array $d, string $k, ?array $recent = null, ?int $ringSpanSecs = null): string {
    $s    = htmlspecialchars((string) $v);
    $cell = $err ? '<span class="lu-err-val">' . $s . '</span>' : $s;
    if ($d === null || !empty($d['reset'])) return $cell;
    $r   = $d['rate'][$k];
    $out = $cell . '<div class="lu-phy-delta" title="Average rate since the baseline was set — a past burst of errors still shows here, decaying toward zero rather than reflecting the link right now.">'
         . '&Delta;' . (int) $d['delta'][$k] . ' &middot; ' . health_rate_str($r) . ' since baseline</div>';
    if ($recent !== null && $ringSpanSecs !== null) {
        $rk = $k === 'reset' ? 'rst' : $k;
        // Its own line and its own tooltip: the two numbers answer different
        // questions, which is the whole point of plan 050, and a shared title
        // describing only the average would mislabel this one.
        $out .= '<div class="lu-phy-delta" title="Rate across the Health tab\'s recent sample ring — what this link has been doing lately, independent of the long-run average above.">'
              . health_rate_str($recent[$rk]) . ' in the last ' . lsi_age_str($ringSpanSecs) . '</div>';
    }
    return $out;
}

/* SERIAL -> /dev/NAME for every SCSI block device, from ONE lsblk call.
   Serial is the join key, not the WWN: storcli's WWN and /dev's differ by a
   nibble on the same physical drive, while the serials match exactly — the
   same correlation the per-drive SMART button above has used since it shipped.
   Empty on a box without lsblk, which renders every Device cell as "—" rather
   than failing a tab. Callers pass the result in, so the render functions stay
   pure and the tests can inject a map. */
function lsi_dev_by_serial(): array {
    $map = [];
    foreach (explode("\n", (string) shell_exec('lsblk -S -o NAME,SERIAL -n 2>/dev/null')) as $line) {
        $f = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($f) >= 2 && $f[0] !== '') $map[strtoupper($f[1])] = '/dev/' . $f[0];
    }
    return $map;
}

/* The /dev name for one drive row. The lsiutil backend already resolves it
   itself (`os_name`, from get_attached_drives.sh's sysfs join); storcli reports
   no /dev name at all, so it goes through the serial map. Null, never a guess:
   a Device column that names the wrong disk is worse than one that says "—". */
function drive_dev_name(array $d, array $devBySerial): ?string {
    if (!empty($d['os_name'])) return (string) $d['os_name'];
    $sn = strtoupper(trim((string) ($d['serial'] ?? '')));
    return $sn !== '' ? ($devBySerial[$sn] ?? null) : null;
}

/* "/dev/sdb" => "0:0:2:0" for every SCSI block device — the SCSI H:C:T:L
   address, which is also the name of the device's node under /dev/bsg. That is
   what the locate blink reads (plan 048), and it comes straight out of sysfs:
   /sys/block/sdb/device is a symlink whose last component IS the address. No
   lookup table, no extra tool, no cache to go stale.
   Anything that does not look like an address is dropped rather than passed
   on — the value ends up in a device path. */
function lsi_scsi_addr_by_dev(string $sysBlock = '/sys/block'): array {
    $map = [];
    foreach (glob("$sysBlock/sd*") ?: [] as $d) {
        $target = @readlink("$d/device");
        if ($target === false) continue;
        $addr = basename($target);
        if (preg_match('/^\d+:\d+:\d+:\d+$/', $addr)) $map['/dev/' . basename($d)] = $addr;
    }
    return $map;
}

/* The Unraid slot cell for a table: "Parity", "Disk 1", or an em dash for a
   drive the array does not use. One renderer so the four tables that show it
   cannot drift apart in spelling or in what they do with a miss. */
function lsi_role_cell(?string $dev, array $roles): string {
    $r = $dev !== null ? ($roles[$dev] ?? '') : '';
    return $r !== '' ? htmlspecialchars($r) : '<span class="lu-muted">—</span>';
}

/* Which drive sits behind this PHY? Two backends, two keys:
     lsiutil  - drives carry `phy`; match it directly.
     storcli  - drives carry no `phy` at all. The PHY's `sas_addr` and the
                drive's `sas_address` are two ports of the same dual-ported
                device and differ in the LAST hex digit only (measured across
                24 drives: Seagate -1, HGST +2, Toshiba -2 — no fixed offset),
                so compare the first 15 digits, uppercased.
   Returns null when nothing matches AND when the 15-digit prefix is not unique
   within this controller: a top-offenders row names a physical bay, and naming
   the wrong one is worse than naming none (plan 027). */
function phy_drive(array $drives, array $phy): ?array {
    if (!$drives) return null;

    // lsiutil: drives carry `phy` directly — a straight index match.
    if (isset($drives[0]['phy'])) {
        foreach ($drives as $d) {
            // A drive behind an expander numbers its PHY in the expander's
            // namespace; these rows are the controller's own PHYs (plan 049).
            // Matching the two names the wrong bay, which this function's whole
            // contract says is worse than naming none.
            if (($d['expander'] ?? '') !== '') continue;
            if (isset($d['phy']) && (string) $d['phy'] === (string) ($phy['phy'] ?? '')) return $d;
        }
        return null;
    }

    // storcli: no `phy` field on drives — join on the SAS address prefix.
    $pfx = strtoupper(substr((string) ($phy['sas_addr'] ?? ''), 0, 15));
    if (strlen($pfx) < 15) return null;

    $matches = array_values(array_filter($drives, fn($d) =>
        strtoupper(substr((string) ($d['sas_address'] ?? ''), 0, 15)) === $pfx
    ));
    // Exactly one match is safe. Zero (no drive) or more than one (the prefix
    // collides between two drives) both resolve to null — never a guess.
    return count($matches) === 1 ? $matches[0] : null;
}

/* How a top-offenders row names the drive behind a PHY: the /dev name when it
   resolves, the enclosure bay when storcli gave one, both when both are known.
   Encl:slot alone does not line up with anything on Unraid's Main page (issue
   #11), and /dev alone loses the bay you actually have to pull. */
function phy_drive_label(array $drives, array $phy, array $devBySerial = []): ?string {
    $d = phy_drive($drives, $phy);
    if ($d === null) return null;
    $dev  = drive_dev_name($d, $devBySerial);
    $slot = isset($d['slot']) && $d['slot'] !== '' ? (string) $d['slot'] : null;
    if ($slot !== null && $dev !== null) return "$slot · $dev";
    return $dev ?? $slot;
}

/* The Health tab's own ring for this controller, read READ-ONLY — this never
   calls health_ingest(); writing the ring stays the Health tab's job alone
   (plan 050's STOP conditions). Returns the matching PHY's rate row from
   health_rates(), or null when there is nothing usable for THIS PHY: no ring,
   too short a span, or (issue #12) an unpairable duplicate index that
   health_rates() already excludes. Absence is deliberate — the caller must
   print nothing extra rather than a "0/hr" that looks measured but isn't. */
function phy_recent_rate(array $ring, int $phyIdx): ?array {
    foreach (health_rates($ring) as $r) {
        if ((int) $r['idx'] === $phyIdx) return $r;
    }
    return null;
}

/* $phys and $deltas share indices, exactly as renderPhyTables builds them.
   Rank by TOTAL errors/hour — the plain sum of the four counters' rates. No
   weighting is invented here: the per-counter thresholds used to color the
   Health tab's link-integrity indicator live in health.php, and duplicating
   that judgement in a second place would let the two disagree (plan 027). */
function phy_top_offenders(array $phys, array $deltas, array $drives, int $limit = 5, array $devBySerial = []): array {
    $rows = [];
    foreach ($phys as $n => $p) {
        $d = $deltas[$n] ?? null;
        // No baseline, or a stale one: excluded entirely. Zero would read as
        // "measured and clean" when it means "never measured".
        if ($d === null || !empty($d['reset'])) continue;
        $total = array_sum($d['rate']);
        if ($total <= 0.0) continue;   // measured and clean is not an offender
        $rows[] = [
            'phy'        => $p['phy'] ?? $n,
            'rate_total' => $total,
            'rate'       => $d['rate'],
            'drive'      => phy_drive_label($drives, $p, $devBySerial),
        ];
    }
    usort($rows, fn($a, $b) => $a['rate_total'] === $b['rate_total']
        ? $a['phy'] <=> $b['phy']
        : $b['rate_total'] <=> $a['rate_total']);
    return array_slice($rows, 0, $limit);
}

/* $baselines defaults to none, so every existing caller (and the raw-only
   fresh install) renders exactly what it rendered before this plan. $drives is
   the decoded `drives` payload (the same shape $data carries), added last and
   defaulting to empty so every existing caller still renders exactly what it
   rendered before this plan. */
function renderPhyTables(array $data, array $baselines = [], ?int $now = null, ?int $uptime = null, array $drives = [], array $devBySerial = [], array $roles = []): string {
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
        // This controller's drives, for the Device column and the offenders list
        // below. Empty while the drives cache is still warming — every Device
        // cell then reads "—" and the tab renders exactly as it used to.
        $ctlDrives = $drives['controllers'][$i]['drives'] ?? [];
        $devCell = function (array $p) use ($ctlDrives, $devBySerial): string {
            $d = phy_drive($ctlDrives, $p);
            $n = $d !== null ? drive_dev_name($d, $devBySerial) : null;
            return $n !== null ? '<code>' . htmlspecialchars($n) . '</code>' : '<span class="lu-muted">—</span>';
        };
        $roleCell = function (array $p) use ($ctlDrives, $devBySerial, $roles): string {
            $d = phy_drive($ctlDrives, $p);
            return lsi_role_cell($d !== null ? drive_dev_name($d, $devBySerial) : null, $roles);
        };

        // The Health tab's ring for THIS controller (read-only — see
        // phy_recent_rate()), keyed by $i exactly as the Health tab itself
        // keys its store: never by position in $phys, or a multi-controller
        // box would show one card's recent rate on another's row (plan 050).
        // A missing or too-short ring degrades to null/null below, and the
        // cells simply omit the recent figure — absence, not a false zero.
        $ctlRing     = health_store_read(health_store_path((int) $i));
        $ctlRingSpan = health_ring_span_secs($ctlRing);

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

        // Top offenders: reuses $deltas above, never a second rate computation
        // (see this function's header). Skipped entirely while stale — the bar
        // above already asks for a re-baseline, and a second, differently-worded
        // empty state here would only contradict it.
        if (!$stale) {
            $off = phy_top_offenders($phys, $deltas, $ctlDrives, 5, $devBySerial);
            if ($ts === null) {
                $out .= '<p class="lu-muted" style="font-size:12px;margin:8px 0">Set a baseline to rank PHYs by error rate.</p>';
            } elseif (empty($off)) {
                $out .= '<p class="lu-muted" style="font-size:12px;margin:8px 0">No PHY has logged errors since the baseline.</p>';
            } else {
                $out .= '<p class="lu-muted" style="font-size:12px;margin:8px 0 4px">Top offenders</p>';
                $rows = [];
                foreach ($off as $rank => $o) {
                    $drvLabel = $o['drive'] !== null ? htmlspecialchars($o['drive']) : 'drive not identified';
                    $rows[] = [
                        (string) ($rank + 1),
                        'PHY ' . htmlspecialchars((string) $o['phy']) . ' &mdash; ' . $drvLabel,
                        // Same average-since-baseline as the per-counter cells (see
                        // luPhyCell) — the tooltip repeats that in the column header
                        // instead of per-cell, since this is a table with one.
                        number_format($o['rate_total'], 1) . '/hr',
                        // lu-muted, not lu-phy-delta: the latter's count is asserted
                        // 1:1 against the main table's per-counter cells elsewhere in
                        // this file's tests, and this breakdown is a second, distinct
                        // rendering of the same rates.
                        '<span class="lu-muted">inv ' . number_format($o['rate']['inv'], 1)
                            . ' &middot; disp ' . number_format($o['rate']['disp'], 1)
                            . ' &middot; sync ' . number_format($o['rate']['sync'], 1)
                            . ' &middot; reset ' . number_format($o['rate']['reset'], 1) . '</span>',
                    ];
                }
                $out .= luTable(['#', 'PHY', 'Errors/hr — average since baseline', 'Breakdown'], $rows);
            }
        }

        // storcli backend if stamped; fall back to key-sniff pre-rollout.
        if ($storcli || (($data['backend'] ?? '') === '' && isset($phys[0]['speed']))) {
            // storcli backend: link/speed/attached-SAS (storcli) + error counters (sysfs)
            $rows = [];
            foreach ($phys as $n => $p) {
                $hasErr = (($p['inv'] ?? 0) + ($p['disp'] ?? 0) + ($p['sync'] ?? 0) + ($p['reset'] ?? 0)) > 0;
                $d      = $deltas[$n];
                $recent = $ctlRingSpan !== null ? phy_recent_rate($ctlRing, (int) ($p['phy'] ?? -1)) : null;
                $ec = fn($k) => luPhyCell($p[$k] ?? 0, $hasErr && ($p[$k] ?? 0) > 0, $d, $k, $recent, $ctlRingSpan);
                $rows[] = [
                    htmlspecialchars((string) $p['phy']),
                    $devCell($p),
                    $roleCell($p),
                    luLinkBadge($p['link']),
                    htmlspecialchars($p['speed']),
                    !empty($p['sas_addr']) ? '<code>' . htmlspecialchars(strtoupper($p['sas_addr'])) . '</code>' : '<span class="lu-muted">—</span>',
                    $ec('inv'), $ec('disp'), $ec('sync'), $ec('reset'),
                ];
            }
            $out .= luTable(['PHY', 'Device', 'Unraid', 'Link', 'Speed', 'Attached SAS Address', 'Invalid DWords', 'Disparity Errors', 'Loss of Sync', 'Reset Problems'], $rows);
        } else {
            // lsiutil backend: SAS error counters
            $rows = [];
            foreach ($phys as $n => $p) {
                $hasErr = ($p['inv'] + $p['disp'] + $p['sync'] + $p['reset']) > 0;
                $d      = $deltas[$n];
                $recent = $ctlRingSpan !== null ? phy_recent_rate($ctlRing, (int) ($p['phy'] ?? -1)) : null;
                $ec = fn($k) => luPhyCell($p[$k], $hasErr, $d, $k, $recent, $ctlRingSpan);
                $rows[] = [
                    htmlspecialchars((string) $p['phy']),
                    $devCell($p),
                    $roleCell($p),
                    luLinkBadge($p['link']),
                    $ec('inv'), $ec('disp'), $ec('sync'), $ec('reset'),
                ];
            }
            $out .= luTable(['PHY', 'Device', 'Unraid', 'Link', 'Invalid DWords', 'Disparity Errors', 'Loss of Sync', 'Reset Problems'], $rows);
        }
        $out .= '</div>';
    }
    return $out;
}

if ($type === 'phy') {
    /* Drive names for the Device column and the top-offenders list. Read the
       same way the Drives tab reads them — one direct call — and NOT through
       cached_read('drives') as this did before (plan 027). That cache was
       cheap on paper and empty in practice: the PHY tab is its only consumer,
       so a 60s TTL had always expired by the next visit, every visit got the
       `warming` (empty) answer and re-launched the producer, and the names
       never appeared (issue #11). The producer also folds stderr into the
       cached file (`2>&1`), so one storcli warning is enough to make the JSON
       undecodable and the drives vanish silently. The tab loads on click and
       on Refresh, never on a timer, and the Drives tab already pays exactly
       this read on exactly this hardware. */
    $ddec  = json_decode((string) shell_exec(
        'bash ' . escapeshellarg("$scripts/get_attached_drives.sh") . ' 2>/dev/null'), true);
    $ddata = is_array($ddec) ? $ddec : [];
    echo renderPhyTables($data, phy_baseline_read(), null, null, $ddata, lsi_dev_by_serial(), unraid_disk_roles());
    exit;
}

/* ── Attached Drives (per controller; columns adapt to the backend) ───────── */
function renderDrivesTables(array $data, array $devBySerial = [], array $roles = [],
                            array $addrByDev = [], array $locating = []): string {
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

        // Leading column on both backends: encl:slot and bus:target are the
        // controller's own addressing and line up with nothing on Unraid's Main
        // page (issue #11). /dev/sdX is the name shared with Main, the SMART tab
        // and every other Unraid screen, so it goes first, like the SMART tab.
        $devCell = function (array $d) use ($devBySerial): string {
            $n = drive_dev_name($d, $devBySerial);
            return $n !== null ? '<code>' . htmlspecialchars($n) . '</code>' : '<span class="lu-muted">—</span>';
        };
        /* Locate blinks the drive's own activity light (plan 048). Offered only
           where an H:C:T:L address resolved — no address, no device to read, so
           the cell says why rather than presenting a button that cannot work. */
        $locCell = function (array $d) use ($devBySerial, $addrByDev, $locating): string {
            $dev  = drive_dev_name($d, $devBySerial);
            $addr = $dev !== null ? ($addrByDev[$dev] ?? '') : '';
            if ($addr === '') return '<span class="lu-muted" title="No SCSI address for this drive">—</span>';
            $on = in_array($addr, $locating, true);
            // The address is [0-9:] by construction (lsi_scsi_addr_by_dev drops
            // anything else) and the /dev name comes from lsblk, so neither can
            // carry a quote into the handler — htmlspecialchars is the belt.
            return sprintf(
                '<button class="lu-refresh-btn%s" data-locate="%s" onclick="luLocate(event, this, \'%s\', \'%s\')">%s</button>',
                $on ? ' locating' : '',
                htmlspecialchars($addr, ENT_QUOTES),
                htmlspecialchars($addr, ENT_QUOTES),
                htmlspecialchars((string) $dev, ENT_QUOTES),
                $on ? 'STOP' : 'Locate'
            );
        };

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
                    $devCell($d),
                    lsi_role_cell(drive_dev_name($d, $devBySerial), $roles),
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
                    $locCell($d),
                ];
            }
            $out .= luTable(['Device', 'Unraid', 'Encl:Slot', 'Port', 'Model', 'Serial', 'State', 'Size', 'SAS Address', 'Link', 'Firmware', 'SMART', 'Locate'], $rows);
        } else {
            // lsiutil backend: device, bus:target, port, SAS address. The /dev
            // name was already here as a trailing "OS Device" column; it moves
            // to the front so all three tabs lead with the same identifier.
            $rows = [];
            foreach ($drives as $d) {
                $sas = !empty($d['sas_address']) ? '<code>' . htmlspecialchars(strtoupper($d['sas_address'])) . '</code>' : '<span class="lu-muted">—</span>';
                $phy = isset($d['phy']) && $d['phy'] !== '' ? 'PHY ' . htmlspecialchars((string) $d['phy'])              : '<span class="lu-muted">—</span>';
                $rows[] = [
                    $devCell($d),
                    lsi_role_cell(drive_dev_name($d, $devBySerial), $roles),
                    htmlspecialchars((string) $d['bus']) . ':' . htmlspecialchars((string) $d['target']),
                    $phy, $sas,
                    $locCell($d),
                ];
            }
            $out .= luTable(['Device', 'Unraid', 'Bus:Tgt', 'Port', 'SAS Address', 'Locate'], $rows);
        }
        $out .= '</div>';
    }
    return $out;
}

if ($type === 'drives') {
    echo renderDrivesTables($data, lsi_dev_by_serial(), unraid_disk_roles(),
                            lsi_scsi_addr_by_dev(), locate_active());
    exit;
}

/* ── Unraid parity rebuild ───────────────────────────────────────────────────
   Which /dev names Unraid has assigned to parity, and whether the array is
   currently reconstructing. Read from the same two files Unraid's own webGui
   renders from, with parse_ini_file — the identical approach flash.php already
   uses for mdState (`flash_array_stopped`).

   NARROW ON PURPOSE, and BOTH halves of the test are load-bearing:
   - mdResyncAction is STICKY. A live idle array still reports the operation it
     last ran ("check P" on the reference box, with mdResync="0" and no
     operation running for weeks). Matching on the action alone would paint a
     permanent rebuild on the parity disk of every array that has ever run one.
     Hence mdResync > 0, which is the "something is running now" signal.
   - Only `recon` counts. A parity CHECK reads the array and writes nothing;
     animating it as a rebuild would be a claim about a disk that is not being
     rebuilt.
   Anything unreadable, missing or unrecognised means "no rebuild" — this only
   ever claims one on positive evidence. */
/* Which slot Unraid has each device in: "/dev/sdp" => "Parity", "/dev/sdg" =>
   "Disk 1". This is the identifier every OTHER Unraid screen uses, and until
   now none of this plugin's tables carried it — so matching a row here against
   the Main page meant tracking /dev/sdX by eye.
   Labels are spelled the way Main spells them, so the two can be read side by
   side. A slot with no disk assigned (parity2 on a single-parity array reports
   device="") is skipped rather than becoming "/dev/". */
function unraid_disk_roles(string $disksIni = UNRAID_DISKINI): array {
    if (!is_file($disksIni)) return [];
    $ini = @parse_ini_file($disksIni, true);
    if (!is_array($ini)) return [];
    $roles = [];
    foreach ($ini as $section => $sec) {
        if (!is_array($sec)) continue;
        $dev = trim((string) ($sec['device'] ?? ''));
        if ($dev === '') continue;
        $name = trim((string) ($sec['name'] ?? '')) !== '' ? (string) $sec['name'] : (string) $section;
        if (preg_match('/^disk(\d+)$/i', $name, $m))       $label = 'Disk ' . (int) $m[1];
        elseif (preg_match('/^parity(\d*)$/i', $name, $m)) $label = 'Parity' . ($m[1] !== '' ? ' ' . (int) $m[1] : '');
        else                                               $label = ucfirst($name);   // cache, and any named pool
        $roles[str_starts_with($dev, '/dev/') ? $dev : '/dev/' . $dev] = $label;
    }
    return $roles;
}

/* Parity is just the slots whose label says so — one reader for disks.ini, not
   two that could disagree about which device is parity. */
function unraid_parity_devs(string $disksIni = UNRAID_DISKINI): array {
    return array_keys(array_filter(unraid_disk_roles($disksIni),
        fn($label) => str_starts_with($label, 'Parity')));
}

function unraid_rebuilding(string $varini = UNRAID_VARINI): bool {
    if (!is_file($varini)) return false;
    $ini = @parse_ini_file($varini);
    if (!is_array($ini)) return false;
    return (int) ($ini['mdResync'] ?? 0) > 0
        && stripos((string) ($ini['mdResyncAction'] ?? ''), 'recon') !== false;
}

/* ── Drive bay map: drives × stored positions × SMART health (plan 047) ──────
   The data half only. It returns the payload the map view renders client-side
   — the grid is interactive (click a drive, click a bay), so its state lives
   in JS either way and server-rendered cells would just have to be re-derived
   there on every click.
   $smart is the decoded SMART cache or null (see smart_cache_read); null and
   "collected but this drive is not in it" are the same thing here — no data,
   which is a state of its own and never renders as healthy. */
function bay_map_assemble(array $drivesData, ?array $smart, array $map, int $rows, int $cols,
                          array $devBySerial = [], bool $locked = false, int $warnTemp = 45,
                          ?int $smartAge = null, array $rebuildDevs = [], array $roles = [],
                          array $addrByDev = [], array $locating = []): array {
    // Serial is the join key the SMART collector already emits per drive; it is
    // also the only identifier both payloads share (storcli's WWN differs by a
    // nibble from /dev's — see lsi_dev_by_serial).
    $bySerial = [];
    foreach ($smart['drives'] ?? [] as $sd) {
        $sn = strtoupper(trim((string) ($sd['serial'] ?? '')));
        if ($sn !== '') $bySerial[$sn] = $sd['smart'] ?? [];
    }

    $placed = [];
    $tray   = [];
    foreach ($drivesData['controllers'] ?? [$drivesData] as $i => $ctl) {
        foreach ($ctl['drives'] ?? [] as $d) {
            $sn = strtoupper(trim((string) ($d['serial'] ?? '')));
            $s  = $bySerial[$sn] ?? [];
            $key = bay_map_key((int) $i, $d);
            $dev = drive_dev_name($d, $devBySerial);
            /* Two different rebuilds can reach the same cell. Unraid's parity
               reconstruct wins, because on an Unraid box it is the one the
               person is actually waiting on — and on an IT-mode HBA (which is
               most of them) storcli's Rbld can never fire at all. */
            $rebuild = $dev !== null && in_array($dev, $rebuildDevs, true) ? 'PARITY REBUILD'
                     : (str_starts_with((string) ($d['state'] ?? ''), 'Rbld') ? 'RESILVER' : null);
            $entry = [
                // null key = this drive reported neither a port nor a PHY, so it
                // cannot be placed. It still appears in the tray, greyed: a drive
                // silently missing from both lists reads as a detection bug.
                'key'    => $key,
                'ctl'    => (int) $i,
                'dev'    => $dev,
                'serial' => $d['serial'] ?? '',
                'model'  => $d['model'] ?? '',
                'size'   => $d['size'] ?? '',
                // The bay card prints the number and its unit at different
                // sizes, so they are split once here rather than parsed in the
                // view. "12.733 TB" -> "12.733" + "TB"; anything that does not
                // look like a measurement passes through whole as the value.
                'cap'    => preg_match('/^\s*([0-9.]+)\s*([A-Za-z]+)/', (string) ($d['size'] ?? ''), $cm) ? $cm[1] : ($d['size'] ?? ''),
                'cap_unit' => $cm[2] ?? '',
                'slot'   => $d['slot'] ?? (isset($d['phy']) ? 'PHY ' . $d['phy'] : ''),
                // What Unraid calls this disk — the name on its Main page, and
                // the one identifier a person already knows before they look
                // here. Empty for a drive the array does not use.
                'role'   => $dev !== null ? ($roles[$dev] ?? '') : '',
                // The SCSI address the locate blink reads, and whether it is
                // blinking right now (plan 048). Empty address = no Locate
                // button on this bay, rather than one that cannot work.
                'addr'   => $dev !== null ? ($addrByDev[$dev] ?? '') : '',
                'locating' => $dev !== null && ($addrByDev[$dev] ?? '') !== ''
                              && in_array($addrByDev[$dev], $locating, true),
                // Display-ready, because the two backends key on different
                // wires and the word has to match: "Port 14" is storcli's
                // Connected Port Number, "PHY 2" is lsiutil's PHY index.
                // Calling a PHY a port on the cell would be a small lie in
                // exactly the place someone reads before pulling a drive.
                'port'   => isset($d['phy']) && $d['phy'] !== '' ? 'PHY ' . $d['phy']
                          : ((($d['port'] ?? '') !== '') ? 'Port ' . $d['port'] : ''),
                'temp'   => ($s['temp'] ?? '') !== '' ? (int) $s['temp'] : null,
                /* Health comes from SMART, never from storcli's `state` field,
                   which is a RAID-topology role rather than a verdict (plan
                   047). Rebuild is the one exception, and it is not an
                   exception to that rule: neither "Rbld" nor Unraid's resync is
                   a health claim — a rebuilding disk is not a sick disk, it is
                   a busy one, and nothing else reports it. */
                'state'  => $rebuild !== null ? 'rebuild' : smart_state($s),
                // Which rebuild, for the chip. Null on every drive that is not
                // rebuilding, so the view never has to guess a default.
                'rebuild_label' => $rebuild,
            ];
            $pos = $key !== null ? ($map[$key] ?? null) : null;
            // An out-of-grid position falls back to the tray rather than being
            // dropped: bay_map_prune_to_dims() normally clears these on resize,
            // but a hand-edited bay_map.json must not strand a drive off-screen.
            if ($pos !== null && (int) $pos['row'] < $rows && (int) $pos['col'] < $cols) {
                $placed[] = $entry + ['row' => (int) $pos['row'], 'col' => (int) $pos['col']];
            } else {
                $tray[] = $entry;
            }
        }
    }
    /* The tray comes out of the loop in controller/wire order, which is the one
       order nobody reading it is thinking in. Sorted into Unraid's Main-page
       order instead, so the chip you are hunting for sits where you already
       expect it. Only the tray is sorted; placed drives sit at coordinates the
       person chose.
       The /dev tiebreak compares LENGTH before text, because sd names are
       bijective base-26 and not decimal: the kernel goes sdz -> sdaa, so any
       plain string compare puts sdaa ahead of sdz. strnatcmp is no help either
       — it only groups digit runs, and there are no digits here. A drive with
       no /dev name at all is '' from the cast and so leads its tier, which is
       where an undetected device is worth looking at first. */
    usort($tray, fn(array $a, array $b) => bay_tray_order($a) <=> bay_tray_order($b)
                                        ?: [strlen((string) $a['dev']), (string) $a['dev']]
                                       <=> [strlen((string) $b['dev']), (string) $b['dev']]);
    return ['rows' => $rows, 'cols' => $cols, 'locked' => $locked, 'warn_temp' => $warnTemp,
            // Rendered in the legend row: the map's colours and temperatures are
            // only as current as the collection behind them.
            'smart_age' => $smartAge === null ? null : lsi_age_str($smartAge),
            'placed' => $placed, 'unassigned' => $tray];
}

/* Sort rank for one tray entry, as [tier, number, label] — compared
   element-wise by <=>, so the tiers separate first and the number only breaks
   ties inside one.
   Tiers are Main's own reading order: parity, then the data disks, then pools,
   then everything Unraid has no slot for. The number is pulled out as an
   integer rather than compared inside the label, because "Disk 10" sorts
   before "Disk 2" as a string and that is exactly the list where an off-by-one
   read gets the wrong drive pulled. Bare "Parity" ranks as 1 so it leads
   "Parity 2" — unraid_disk_roles() emits the first one without a number.
   Roleless drives sort last: they have nothing to match against Main, so they
   are not what anyone is scanning this list for. */
function bay_tray_order(array $e): array {
    $role = (string) ($e['role'] ?? '');
    if ($role === '')                                   return [3, 0, ''];
    if (preg_match('/^Parity(?: (\d+))?$/', $role, $m)) return [0, isset($m[1]) ? (int) $m[1] : 1, ''];
    if (preg_match('/^Disk (\d+)$/', $role, $m))        return [1, (int) $m[1], ''];
    return [2, 0, $role];   // cache and any named pool, alphabetical among themselves
}

if ($type === 'baymap') {
    header('Content-Type: application/json; charset=utf-8');
    $d = bay_map_dims();
    echo json_encode(bay_map_assemble(
        $data, smart_cache_read(), bay_map_read(), $d['rows'], $d['cols'],
        lsi_dev_by_serial(), bay_map_locked(), (int) lsi_config_read()['BAY_WARN_TEMP'],
        smart_cache_age(),
        unraid_rebuilding() ? unraid_parity_devs() : [], unraid_disk_roles(),
        lsi_scsi_addr_by_dev(), locate_active()
    ));
    exit;
}

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
            // The reason string health_indicators() already computes for the rollup
            // pill, printed under its own row too. Without it a row reads "Link
            // Integrity 0/hr" with nothing saying 0 what (issue #11) — the number
            // is only meaningful next to the sentence that names it.
            $hint = (string) ($row['reason'] ?? '');
            [$bDark, $bLight] = lsi_health_gradient($row['state']);
            // Sprite ids live in hbaviewer.php's #lu-wrap. Most match $key; these
            // two do not, and a mismatch renders an empty icon slot silently.
            $icon = ['link_integrity' => 'link', 'host_link' => 'hostlink'][$key] ?? $key;
            $out .= '<div class="lu-indicator-row">'
                  . '<span class="lu-ind-dot" style="--gd:' . $bDark . ';--gl:' . $bLight . '"></span>'
                  . '<svg class="lu-ind-icon" aria-hidden="true"><use href="#lu-i-' . $icon . '"/></svg>'
                  . '<span class="lu-indicator-label">' . htmlspecialchars($label) . '</span>'
                  . '<span class="lu-indicator-value">' . htmlspecialchars((string) ($row['value'] ?? '')) . '</span>'
                  . ($hint !== '' ? '<span class="lu-ind-hint">' . htmlspecialchars($hint) . '</span>' : '')
                  . '</div>';
        }
        $out .= '</div></div>';
    }
    return $out;
}
