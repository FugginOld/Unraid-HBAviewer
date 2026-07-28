<?PHP
/* Runnable check for config.php: clamp bounds, defaults, and write->read round-trip.
   No framework. Needs php (present on the Unraid box, absent on some dev machines):
     php tests/config_test.php   ->  "config: all pass"  (exit 0) */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/config.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

// clamp: below min, above max, in range, non-numeric
check('clamp port below min',  lsi_clamp('HBA_PORT', 0)        === 1);
check('clamp port above max',  lsi_clamp('HBA_PORT', 99)       === 8);
check('clamp threshold in',    lsi_clamp('ALERT_THRESHOLD', 70) === 70);
check('clamp threshold above', lsi_clamp('ALERT_THRESHOLD', 999) === 150);
check('clamp show garbage->0', lsi_clamp('SHOW_PHY', 'xyz')    === 0);
check('clamp show 1 stays 1',  lsi_clamp('SHOW_PHY', 1)        === 1);

$tmp = sys_get_temp_dir() . '/lsi_cfg_test_' . getmypid() . '.cfg';
@unlink($tmp);

// missing file -> defaults, typed int
$d = lsi_config_read($tmp);
check('defaults port',      $d['HBA_PORT'] === 1);
check('defaults threshold', $d['ALERT_THRESHOLD'] === 80);
check('defaults are int',   is_int($d['SHOW_PCIE']));

// write clamps out-of-range input, read returns clamped values
lsi_config_write(['HBA_PORT' => 99, 'ALERT_THRESHOLD' => 0, 'SHOW_PHY' => 0], $tmp);
$r = lsi_config_read($tmp);
check('write clamps port hi',  $r['HBA_PORT'] === 8);
check('write clamps thr lo',   $r['ALERT_THRESHOLD'] === 1);
check('round-trip show off',   $r['SHOW_PHY'] === 0);
check('missing key -> default',$r['SHOW_DRIVES'] === 1);

@unlink($tmp);

// ── CSRF: token read + constant-time compare, both failing closed ───────────
$vi = sys_get_temp_dir() . '/hbav_varini_csrf_' . getmypid() . '.ini';
file_put_contents($vi, "csrf_token=\"ABC123\"\nmdState=\"STARTED\"\n");
check('csrf token read',        lsi_csrf_token($vi) === 'ABC123');
@unlink($vi);
check('csrf missing file -> empty', lsi_csrf_token($vi) === '');
file_put_contents($vi, "mdState=\"STARTED\"\n");
check('csrf absent key -> empty',   lsi_csrf_token($vi) === '');
@unlink($vi);

check('csrf match',              lsi_csrf_ok('ABC123', 'ABC123') === true);
check('csrf mismatch',           lsi_csrf_ok('ABC123', 'abc123') === false);
check('csrf empty sent',         lsi_csrf_ok('ABC123', '') === false);
check('csrf null sent',          lsi_csrf_ok('ABC123', null) === false);
check('csrf array sent',         lsi_csrf_ok('ABC123', ['x']) === false);
check('csrf no expected -> deny', lsi_csrf_ok('', 'anything') === false);
check('csrf both empty -> deny',  lsi_csrf_ok('', '') === false);
check('csrf prefix not accepted', lsi_csrf_ok('ABC123', 'ABC') === false);

echo $fails === 0 ? "config: all pass\n" : "config: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
