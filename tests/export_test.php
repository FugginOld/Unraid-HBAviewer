<?PHP
/* Runnable check for export.php's projection: lsi_hba_view() output -> the
   export shape, and the plain-text Prometheus rendering. Fixtures are real
   backend payloads (see plan 025), not hand-rolled — the no-sensor fixture in
   particular exercises the '' -> null coercion the plan requires.
     php tests/export_test.php  ->  "export: all pass" (exit 0) */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/view.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/export.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

$FIXDIR = __DIR__ . '/fixtures';
$EXPDIR = __DIR__ . '/expected';

// ── Single controller, sensor present (tests/expected/hba_normal.json) ─────
$normal = json_decode((string) file_get_contents("$EXPDIR/hba_normal.json"), true);
$v      = lsi_hba_view($normal, 1, 0);
$e      = export_controller(0, $v);

$expectedKeys = ['controller', 'model', 'chip', 'firmware', 'mode', 'temp_c',
    'status', 'temp_band', 'cfg_band', 'drive_count', 'pcie_width', 'pcie_speed', 'fw_old'];
$sortedExpected = $expectedKeys; sort($sortedExpected);
$sortedActual   = array_keys($e); sort($sortedActual);
check('exact key set', $sortedExpected === $sortedActual);

foreach (['color', 'label', 'temp_grad', 'temp_label', 'cfg_band_label', 'port_label'] as $pk) {
    check("no presentation key: $pk", !array_key_exists($pk, $e));
}

check('controller index is the passed idx', $e['controller'] === 0);
check('model comes from board_name fallback', $e['model'] === 'SAS9207-8i');
check('chip comes from raw model', $e['chip'] === 'SAS2308');
check('temp_c is an int for a card with a sensor', $e['temp_c'] === 47 && is_int($e['temp_c']));
check('cfg_band is a non-empty string', is_string($e['cfg_band']) && $e['cfg_band'] !== '');
check('drive_count is null, not ""', $e['drive_count'] === null);
check('pcie_width pulled from the pcie list', $e['pcie_width'] === 'x8');
check('pcie_speed pulled from the pcie list', $e['pcie_speed'] === 'Gen3 (8.0 GT/s)');
check('fw_old is bool', $e['fw_old'] === false);

// ── No-sensor card (tests/fixtures/cache_lsiutil_notemp.json) ──────────────
$notemp = json_decode((string) file_get_contents("$FIXDIR/cache_lsiutil_notemp.json"), true);
$ctls   = lsi_controllers($notemp);
$eNoTemp = export_controller(0, lsi_hba_view($ctls[0], 1, 0));
check('temp_c is null for a card with no sensor', $eNoTemp['temp_c'] === null);

// ── Two-controller payload (tests/fixtures/cache_storcli_multi.json) ───────
$multi = json_decode((string) file_get_contents("$FIXDIR/cache_storcli_multi.json"), true);
$ctls  = lsi_controllers($multi);
$out   = [];
foreach ($ctls as $i => $c) $out[] = export_controller($i, lsi_hba_view($c, 1, $i));
check('two controllers -> two entries', count($out) === 2);
check('first controller projects to index 0', $out[0]['controller'] === 0);
check('second controller projects to index 1', $out[1]['controller'] === 1);
check('drive_count is an int, not a string', $out[0]['drive_count'] === 16 && is_int($out[0]['drive_count']));

// ── Mixed payload: one healthy controller, one errored ──────────────────────
// An errored controller must NOT be dropped (a consumer couldn't tell
// "errored" from "removed") and must NOT go through lsi_hba_view() (its
// status ?? 'ok' default would report a dead card as healthy).
$mixed = ['backend' => 'lsiutil', 'controllers' => [$normal, ['error' => 'Controller unavailable']]];
$outMixed = [];
foreach (lsi_controllers($mixed) as $i => $c) {
    $outMixed[] = isset($c['error'])
        ? export_error_controller($i, $c)
        : export_controller($i, lsi_hba_view($c, 1, $i));
}
check('errored controller is not dropped: two entries', count($outMixed) === 2);
check('errored entry has status "error"', $outMixed[1]['status'] === 'error');
check('errored entry has temp_c null', $outMixed[1]['temp_c'] === null);
check('errored entry carries an error key', array_key_exists('error', $outMixed[1]));
check('healthy entry carries no error key', !array_key_exists('error', $outMixed[0]));

$promMixed = export_prometheus($outMixed);
check('prometheus status=error for the errored controller',
    strpos($promMixed, 'hbaviewer_status{controller="1",status="error"} 1') !== false);
check('prometheus omits temp_celsius for the errored controller',
    strpos($promMixed, 'hbaviewer_temp_celsius{controller="1"') === false);

// ── Prometheus label escaping ───────────────────────────────────────────────
check('export_prom_label escapes a double quote', export_prom_label('a"b') === 'a\\"b');
check('export_prom_label escapes a backslash', export_prom_label('a\\b') === 'a\\\\b');

// ── Prometheus renderer omits null-valued metrics ───────────────────────────
$promWithTemp = export_prometheus([$e]);
check('prometheus emits temp_celsius when temp_c is present',
    strpos($promWithTemp, 'hbaviewer_temp_celsius{controller="0"') !== false);
check('prometheus emits a status line with value 1',
    strpos($promWithTemp, 'hbaviewer_status{controller="0",status="ok"} 1') !== false);

$promNoTemp = export_prometheus([$eNoTemp]);
check('prometheus omits temp_celsius when temp_c is null',
    strpos($promNoTemp, 'hbaviewer_temp_celsius{controller="0"') === false);
check('prometheus omits drive_count when drive_count is null',
    strpos($promNoTemp, 'hbaviewer_drive_count{controller="0"') === false);

echo $fails === 0 ? "export: all pass\n" : "export: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
