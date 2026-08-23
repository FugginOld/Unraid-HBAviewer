<?PHP
/* HBAviewer AJAX endpoint
 * ?type=overview  → JSON  (one entry per CARD, each carrying its own `ctl`;
 *                          consumed by the firmware page and nothing else)
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
// Which controllers are one physical card (a 9300-16i is two IOCs on one board).
require_once __DIR__ . '/card_group.php';
require_once __DIR__ . '/render/table.php';
require_once __DIR__ . '/render/smart.php';
require_once __DIR__ . '/render/events.php';
require_once __DIR__ . '/render/health.php';
require_once __DIR__ . '/render/drives.php';
require_once __DIR__ . '/render/phy.php';
require_once __DIR__ . '/render/baymap.php';
require_once __DIR__ . '/render/overview.php';

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
   or renders a response for one request. The render functions live in
   render/*.php, required above at :24-31 — those require_once lines run
   BEFORE this guard, so every render function is already defined by the time
   this file returns. That is what lets tests require this file and exercise
   the table builders without touching a controller. Move a require below this
   line and every render function goes undefined for the tests.
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
    if ($cached !== null) { echo renderSmartTable($cached, smart_cache_age(), unraid_disk_roles(), unraid_ud_mounts(),
                                                (int) (lsi_config_read()['TEMP_UNIT'] ?? 0)); exit; }
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
    // (string) cast: serial[]=x makes preg_replace return an ARRAY, which then
    // reaches escapeshellarg() below as a TypeError -- an uncaught 500 on a
    // read-only endpoint. Fourth and last of the four sites with this shape;
    // the other three are in flash.php.
    $serial = preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) ($_GET['serial'] ?? ''));
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
    // The unit rides in the string now, so the format loses its own &deg;C --
    // leaving it would print "131 F degC" once the setting is on.
    $u = (int) (lsi_config_read()['TEMP_UNIT'] ?? 0);
    printf(
        '<span style="color:%s;font-weight:700">%s</span> &middot; %s &middot; %s def &middot; %s pend &middot; %sh',
        $color, $f($s['health'] ?? ''), $f(lsi_temp_str($s['temp'] ?? '', $u)),
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
    $fwIdx = fw_load();   // read once, not once per controller
    foreach ($ctls as &$c) {
        if (isset($c['error'])) continue;
        $c['color'] = lsi_status_color($c['status'] ?? 'ok');
        $c['label'] = lsi_status_label($c['status'] ?? 'ok');
        $c['firmware_verdict'] = fw_evaluate([
            'board'        => $c['board_name']   ?? '',
            'chip'         => $c['model']        ?? '',
            'firmware'     => $c['firmware']     ?? '',
            'subvendor_id' => $c['subvendor_id'] ?? '',
            'topology'     => $c['topology']     ?? 'unknown',
        ], $fwIdx);
        // One rule, one home: fw_verdict_color() is the only place that knows
        // amber is reserved for a terminal branch. The firmware page recomputes
        // nothing — it just reads the colour this endpoint already worked out.
        $c['firmware_verdict']['color'] = fw_verdict_color($c['firmware_verdict']);
    }
    unset($c);
    /* One entry per CARD, not per controller. This JSON feeds the firmware page
       alone, and a SAS9300-16i is one board carrying two SAS3008 IOCs that must
       be verified and flashed together — see the loop in scripts/flash_hba.sh.
       Board-level fields come from the first member: both IOCs report the same
       model, firmware and BIOS, because those describe the card.
       'ctl' is the entry's own controller number(s), and it exists precisely so
       the page never uses the array index as one — a group's members are NOT
       necessarily contiguous, so index and controller number are different
       facts (card_group.php's header has the [[0,2],[1]] case).
       $fwIdx, never a hand-built map: lsi_ioc_counts() keys on fw_normalize(),
       the same key space fw_load() re-keys the index into, and a literal
       'SAS9300-16i' key would miss every lookup and group nothing. */
    $cards = [];
    foreach (lsi_group_cards($ctls, lsi_ioc_counts($fwIdx)) as $g) {
        $card = $ctls[$g[0]];
        $card['ctl'] = implode(',', $g);
        $cards[] = $card;
    }
    echo json_encode(['controllers' => $cards]);
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
    echo renderHealthTables($data, lsi_config_read());
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
    echo renderPhyTables($data, phy_baseline_read(), null, null, $ddata, lsi_dev_by_serial(), unraid_disk_roles(), unraid_ud_mounts());
    exit;
}

if ($type === 'drives') {
    echo renderDrivesTables($data, lsi_dev_by_serial(), unraid_disk_roles(),
                            lsi_scsi_addr_by_dev(), locate_active(), unraid_ud_mounts());
    exit;
}

if ($type === 'baymap') {
    header('Content-Type: application/json; charset=utf-8');
    $d = bay_map_dims();
    // Carry a pre-#15 port-keyed map onto slot keys before reading it, so an
    // upgrade does not empty somebody's grid on the first page load. A no-op
    // on every map already in the current shape, and it needs the drive
    // payload, which is why it lives at the endpoint rather than in the store.
    bay_map_migrate_ports($data);
    echo json_encode(bay_map_assemble(
        $data, smart_cache_read(), bay_map_read(), $d['rows'], $d['cols'],
        lsi_dev_by_serial(), bay_map_locked(), (int) lsi_config_read()['BAY_WARN_TEMP'],
        smart_cache_age(),
        unraid_rebuilding() ? unraid_parity_devs() : [], unraid_disk_roles(),
        lsi_scsi_addr_by_dev(), locate_active(), unraid_ud_mounts()
    // Whether an Undo is available. Merged here rather than threaded through
    // bay_map_assemble(), which is about drives and has no business knowing
    // about the store's backup file.
    ) + ['has_backup' => bay_map_has_backup()]);
    exit;
}

if ($type === 'events') { echo renderEventsTables($data); exit; }

