<?PHP
/* HBAviewer read-only export endpoint (plan 025).
 *
 * ?format=json (default)  -> {"controllers": [...]}
 * ?format=prometheus       -> text exposition format
 *
 * This is a thin serialization pass over the SAME warm overview cache
 * ajax_info.php's overview_html handler reads (cached_read('overview', ...)) —
 * it never re-parses controller data itself, only lsi_controllers() +
 * lsi_hba_view()'s already-shared decoding.
 *
 * Session-gated, same as every other page in this plugin: there is no auth
 * scheme here, so a Prometheus scraper outside the webGui session cannot
 * reach this. That is deliberate — see plan 025. Do not add one here.
 *
 * The pure functions are unit-tested (tests/export_test.php); the HTTP
 * dispatch at the bottom runs only when served, never under the CLI test
 * runner — same shape as bundle.php and flash.php.
 */

require_once __DIR__ . '/view.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cached_read.php';

/* Project one controller's lsi_hba_view() output down to the export shape.
   Pure: takes the view array, returns scalars only. Presentation keys
   (color, label, *_grad, *_label) are deliberately dropped — see the plan.
   NOTE: this shape is an external contract once shipped. Renaming a key here
   breaks consumers silently; add, don't rename. */
function export_controller(int $idx, array $v): array {
    $pcieWidth = null;
    $pcieSpeed = null;
    foreach ($v['pcie'] ?? [] as $item) {
        if ($item['label'] === 'PCIe Width') $pcieWidth = $item['value'];
        if ($item['label'] === 'PCIe Speed') $pcieSpeed = $item['value'];
    }
    // lsi_hba_view() returns '' for a card with no sensor / no reported drive
    // count; "" in JSON is useless to a consumer, so number-or-null instead.
    $temp   = $v['temp']   ?? '';
    $drives = $v['drives'] ?? '';
    return [
        'controller'  => $idx,
        'model'       => $v['model'],
        'chip'        => $v['chip'],
        'firmware'    => $v['firmware'],
        'mode'        => $v['mode'],
        'temp_c'      => is_numeric($temp) ? (int) $temp : null,
        'status'      => $v['status'],
        'temp_band'   => $v['temp_band'],
        'cfg_band'    => $v['cfg_band'] ?? '',
        'drive_count' => is_numeric($drives) ? (int) $drives : null,
        'pcie_width'  => $pcieWidth,
        'pcie_speed'  => $pcieSpeed,
        'fw_old'      => (bool) $v['fw_old'],
    ];
}

/* Build the export entry for a controller lsi_controllers() flagged with an
   error — deliberately NOT routed through lsi_hba_view(), which defaults a
   missing status to 'ok' and would silently report a dead card as healthy.
   Dropping the entry instead (the earlier version of this function) is worse:
   a consumer then can't tell "card errored" from "card removed", and a
   controller going unreadable is exactly the event someone would want an
   alert on. Key order matches export_controller()'s so both entry kinds
   serialize the same shape; `error` is appended and present ONLY on this
   kind, so a consumer can branch on its presence. */
function export_error_controller(int $idx, array $c): array {
    return [
        'controller'  => $idx,
        'model'       => $c['board_name'] ?? $c['model'] ?? 'Unknown',
        'chip'        => $c['model'] ?? 'Unknown',
        'firmware'    => 'Unknown',
        'mode'        => '',
        'temp_c'      => null,
        'status'      => 'error',
        'temp_band'   => '',
        'cfg_band'    => '',
        'drive_count' => null,
        'pcie_width'  => null,
        'pcie_speed'  => null,
        'fw_old'      => false,
        'error'       => (string) $c['error'],
    ];
}

/* Prometheus label-value escaping (exposition format, not a general escaper):
   backslash first (so it doesn't double-escape the quote/newline escapes it
   introduces), then quote, then newline. Model strings come from hardware and
   are not guaranteed quote-free. */
function export_prom_label(string $v): string {
    $v = str_replace('\\', '\\\\', $v);
    $v = str_replace('"', '\\"', $v);
    $v = str_replace("\n", '\\n', $v);
    return $v;
}

/* Render the export_controller() list as Prometheus exposition text. A null
   metric value is OMITTED entirely (never 0 or NaN) — a card with no
   temperature sensor has no temperature, and absent is honest. Status is an
   enum-style gauge (one line per controller, value 1) rather than an invented
   numeric severity scale — nothing else in the plugin has one. */
function export_prometheus(array $controllers): string {
    $out = "# HELP hbaviewer_temp_celsius Controller temperature in degrees Celsius.\n"
         . "# TYPE hbaviewer_temp_celsius gauge\n";
    foreach ($controllers as $c) {
        if ($c['temp_c'] === null) continue;
        $out .= 'hbaviewer_temp_celsius{controller="' . $c['controller']
              . '",model="' . export_prom_label($c['model']) . '"} ' . $c['temp_c'] . "\n";
    }

    $out .= "# HELP hbaviewer_drive_count Drives connected to the controller.\n"
         . "# TYPE hbaviewer_drive_count gauge\n";
    foreach ($controllers as $c) {
        if ($c['drive_count'] === null) continue;
        $out .= 'hbaviewer_drive_count{controller="' . $c['controller']
              . '",model="' . export_prom_label($c['model']) . '"} ' . $c['drive_count'] . "\n";
    }

    $out .= "# HELP hbaviewer_status Controller health status (1 = current state).\n"
         . "# TYPE hbaviewer_status gauge\n";
    foreach ($controllers as $c) {
        $out .= 'hbaviewer_status{controller="' . $c['controller']
              . '",status="' . export_prom_label($c['status']) . '"} 1' . "\n";
    }

    return $out;
}

/* ── HTTP dispatch (served only; skipped under the CLI test runner) ─────────── */
if (PHP_SAPI === 'cli') return;

$scripts = '/usr/local/emhttp/plugins/hbaviewer/scripts';
$cfg     = lsi_config_read();
$port    = $cfg['HBA_PORT'];
$format  = ($_GET['format'] ?? '') === 'prometheus' ? 'prometheus' : 'json';

// Same cache cached_read owns for the overview_html handler (ajax_info.php)
// — freshness/lock/atomic-swap are its job, this endpoint only turns a
// result into output. Cold cache is a required case, not an edge case: a
// scraper must never read a warming box as "no controllers".
$r = cached_read('overview', 60, 'bash ' . escapeshellarg("$scripts/get_hba_info.sh"));

if ($r['state'] !== 'ready') {
    http_response_code(503);
    header('Retry-After: 5');
    header('Content-Type: application/json');
    echo json_encode(['state' => 'warming']);
    return;
}

$raw  = $r['body'];
$data = $raw !== '' ? json_decode($raw, true) : null;

if (!is_array($data) || isset($data['error'])) {
    http_response_code(503);
    header('Content-Type: application/json');
    $msg = is_array($data) && isset($data['error']) ? $data['error'] : 'No output from script';
    echo json_encode(['error' => $msg]);
    return;
}

$controllers = [];
foreach (lsi_controllers($data) as $i => $c) {
    $controllers[] = isset($c['error'])
        ? export_error_controller((int) $i, $c)
        : export_controller((int) $i, lsi_hba_view($c, $port, (int) $i));
}

if ($format === 'prometheus') {
    header('Content-Type: text/plain; version=0.0.4');
    echo export_prometheus($controllers);
    return;
}

header('Content-Type: application/json');
echo json_encode(['controllers' => $controllers]);
