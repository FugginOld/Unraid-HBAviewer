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
check('defaults inlet off', $d['INLET_SENSOR'] === '');
check('defaults inlet is string', is_string($d['INLET_SENSOR']));

// write clamps out-of-range input, read returns clamped values
lsi_config_write(['HBA_PORT' => 99, 'ALERT_THRESHOLD' => 0, 'SHOW_PHY' => 0], $tmp);
$r = lsi_config_read($tmp);
check('write clamps port hi',  $r['HBA_PORT'] === 8);
check('write clamps thr lo',   $r['ALERT_THRESHOLD'] === 1);
check('round-trip show off',   $r['SHOW_PHY'] === 0);
check('missing key -> default',$r['SHOW_DRIVES'] === 1);

/* ── lsi_sanitise_sensor: whitelist, not an escape (plan 029) ─────────────── */
check('sanitise valid id',        lsi_sanitise_sensor('nct6798-isa-0290/SYSTIN') === 'nct6798-isa-0290/SYSTIN');
check('sanitise space in label',  lsi_sanitise_sensor('nct6798-isa-0290/CPU Temp') === 'nct6798-isa-0290/CPU Temp');
check('sanitise traversal',       lsi_sanitise_sensor('../../etc/passwd') === '');
check('sanitise metachar semi',   lsi_sanitise_sensor('x; touch /tmp/PWNED') === '');
check('sanitise metachar dollar', lsi_sanitise_sensor('$(touch /tmp/PWNED)/label') === '');
check('sanitise metachar tick',   lsi_sanitise_sensor('`touch /tmp/PWNED`/label') === '');
check('sanitise no slash',        lsi_sanitise_sensor('nolabelatall') === '');
check('sanitise over-length',     lsi_sanitise_sensor(str_repeat('a', 65) . '/label') === '');
check('sanitise empty',           lsi_sanitise_sensor('') === '');
// PCRE $ matches just before a trailing \n, not only end-of-string — \z closes
// that gap (a value ending in a real newline must be rejected, not passed through).
check('sanitise trailing newline', lsi_sanitise_sensor("abc/def\n") === '');

/* ── lsi_cfg_quote: round-trip through a REAL `bash -c 'source ...'`, for
   every payload above — the Step 1 gate this plan is built around. Skipped
   (not failed) when bash is unavailable, since the PHP suite otherwise needs
   no shell at all. */
$hasBash = @shell_exec('bash -c "echo ok" 2>/dev/null') === "ok\n";
if ($hasBash) {
    $qtmp = sys_get_temp_dir() . '/lsi_cfg_quote_test_' . getmypid();
    $pwn  = sys_get_temp_dir() . '/lsi_cfg_quote_PWNED_' . getmypid();
    $payloads = [
        'valid'      => 'nct6798-isa-0290/SYSTIN',
        'space'      => 'nct6798-isa-0290/CPU Temp',
        'traversal'  => '../../etc/passwd',
        'semicolon'  => "x; touch $pwn",
        'dollar'     => "\$(touch $pwn)/label",
        'backtick'   => "`touch $pwn`/label",
    ];
    foreach ($payloads as $name => $payload) {
        @unlink($pwn);
        $cfgFile = "$qtmp.cfg";
        lsi_config_write(['INLET_SENSOR' => $payload], $cfgFile);
        $expected = lsi_sanitise_sensor($payload);   // what the whitelist alone allows through
        // A written-then-read value is byte-identical to what a whitelisted
        // payload sanitises to (the sanitiser runs on write AND on read).
        check("quote round-trip byte-identical (php read): $name", lsi_config_read($cfgFile)['INLET_SENSOR'] === $expected);

        // The test that actually has teeth against a broken lsi_cfg_quote():
        // ask bash — not PHP — what it thinks the variable is after sourcing.
        // An unquoted "nct6798-isa-0290/CPU Temp" is a legal, whitelisted value
        // (spaces are allowed in labels) that word-splits on the space if
        // written raw, so this is the case that catches lsi_cfg_quote()
        // degrading to `return $v;` — the sanitiser alone can't, since it never
        // touches quoting and every malicious payload here is stripped to ''
        // before quoting even runs.
        $sourced = (string) shell_exec(
            'bash -c ' . escapeshellarg('source ' . escapeshellarg($cfgFile) . '; printf %s "$INLET_SENSOR"') . ' 2>&1'
        );
        check("sourced var byte-identical to input: $name", $sourced === $expected);

        shell_exec('bash -c ' . escapeshellarg('source ' . escapeshellarg($cfgFile)) . ' 2>&1');
        check("quote sources cleanly: $name", !file_exists($pwn));
        @unlink($cfgFile);
    }
    @unlink($pwn);
} else {
    echo "SKIP  lsi_cfg_quote round-trip (no bash on PATH)\n";
}

@unlink($tmp);
echo $fails === 0 ? "config: all pass\n" : "config: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
