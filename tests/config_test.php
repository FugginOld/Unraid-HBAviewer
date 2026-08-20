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
check('defaults threshold', $d['ALERT_THRESHOLD'] === 76);
check('defaults are int',   is_int($d['SHOW_PCIE']));

/* One declaration, two views. The shell reads the same cfg file PHP does, and
   used to restate the default itself -- with a different number. On a box whose
   cfg lacks the key, the shell banded temperatures against 80 while PHP labelled
   them against 76. 80 was never a legal value for what this setting means: it is
   the FIRST BAND at which the badge complains, stored as that band's floor, and
   the floors are 66/76/86/96. */
$shellDefault = trim((string) shell_exec(
    'LSI_CFG_PATH=/nonexistent bash -c '
    . escapeshellarg('. ' . __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/scripts/config.sh; printf %s "$ALERT"')
));
check('shell and PHP agree on the ALERT_THRESHOLD default',
    $shellDefault === (string) LSI_SCHEMA['ALERT_THRESHOLD'][0]);
check('the default is a real band floor',
    in_array((int) $shellDefault, [66, 76, 86, 96], true));

// write clamps out-of-range input, read returns clamped values
lsi_config_write(['HBA_PORT' => 99, 'ALERT_THRESHOLD' => 0, 'SHOW_PHY' => 0], $tmp);
$r = lsi_config_read($tmp);
check('write clamps port hi',  $r['HBA_PORT'] === 8);
check('write clamps thr lo',   $r['ALERT_THRESHOLD'] === 1);
check('round-trip show off',   $r['SHOW_PHY'] === 0);
check('missing key -> default',$r['SHOW_DRIVES'] === 1);

/* A partial write must keep the keys it did not name. This is the whole
   reason lsi_config_update() exists: the Settings page names its nine form
   fields, and a plain write of those nine reset every bay-map key to default
   — silently unlocking a map somebody built by walking to the rack. */
lsi_config_write(['HBA_PORT' => 6, 'BAY_ROWS' => 2, 'BAY_COLS' => 12, 'BAY_LOCK' => 1], $tmp);
lsi_config_update(['HBA_PORT' => 3], $tmp);
$u = lsi_config_read($tmp);
check('update applies its own key',      $u['HBA_PORT'] === 3);
check('update preserves BAY_ROWS',       $u['BAY_ROWS'] === 2);
check('update preserves BAY_COLS',       $u['BAY_COLS'] === 12);
check('update preserves BAY_LOCK',       $u['BAY_LOCK'] === 1);
lsi_config_update(['HBA_PORT' => 99], $tmp);
check('update still clamps',             lsi_config_read($tmp)['HBA_PORT'] === 8);

@unlink($tmp);
echo $fails === 0 ? "config: all pass\n" : "config: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
