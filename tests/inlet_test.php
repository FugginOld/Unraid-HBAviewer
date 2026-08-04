<?PHP
/* Runnable check for inlet.php: candidate discovery, sensor resolve, and Δ
   arithmetic (plan 029). No hardware — points at tests/fixtures/hwmon/, the
   same fixture tree tests/run.sh's hwmon-list/hwmon-resolve goldens use.
     php tests/inlet_test.php   ->  "inlet: all pass"  (exit 0) */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/inlet.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

$root = __DIR__ . '/fixtures/hwmon';

/* ── discovery: every candidate, including the junk (plan 029 — filtering
   happens in the UI, not here, so the user can SEE -61C before choosing it) ── */
$cands = lsi_inlet_candidates($root);
check('discovery finds all three', count($cands) === 3);
$bySensor = [];
foreach ($cands as $c) $bySensor[$c['sensor']] = $c;
check('discovery labelled input',   ($bySensor['nct6798/SYSTIN']['reading'] ?? null) === 55);
check('discovery unlabelled input', isset($bySensor['acpitz/acpitz temp1']) && $bySensor['acpitz/acpitz temp1']['reading'] === -61);
check('discovery zero reading',     ($bySensor['coretemp/Package id 0']['reading'] ?? null) === 0);
check('discovery empty root -> []', lsi_inlet_candidates($root . '/does-not-exist') === []);

/* ── resolve: happy path, chip absent, label absent but chip present — the
   fallback-to-a-different-sensor failure mode must never happen ─────────── */
check('resolve happy path',        lsi_inlet_reading('nct6798/SYSTIN', $root) === 55);
check('resolve negative reading',  lsi_inlet_reading('acpitz/acpitz temp1', $root) === -61);
check('resolve chip absent',       lsi_inlet_reading('nonexistent/SYSTIN', $root) === null);
check('resolve label absent',      lsi_inlet_reading('nct6798/NOPE', $root) === null);
check('resolve unconfigured',      lsi_inlet_reading('', $root) === null);
check('resolve malformed no slash',lsi_inlet_reading('justachipname', $root) === null);

/* ── Δ arithmetic: normal, negative (inlet hotter than controller — a
   misidentified sensor, shown rather than hidden), zero ─────────────────── */
check('delta normal',   lsi_inlet_delta(72, 24) === 48);
check('delta negative', lsi_inlet_delta(30, 55) === -25);
check('delta zero',     lsi_inlet_delta(40, 40) === 0);

echo $fails === 0 ? "inlet: all pass\n" : "inlet: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
