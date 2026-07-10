<?PHP
/* Runnable checks for flash.php guards: filename confinement, the array-stopped
   gate, and the preflight — the safety logic that stands between a web request
   and a card-bricking flash. Pure functions, no HTTP, no flashing.
     php tests/flash_php_test.php  ->  "flash_php: all pass" (exit 0) */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/flash.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

// ── flash_safe_name: confine uploads to a safe basename + allowed extension ──
check('safe name keeps good',   flash_safe_name('SAS9300_8i_IT.bin', ['bin']) === 'SAS9300_8i_IT.bin');
check('safe name strips path',  flash_safe_name('../../etc/x.bin', ['bin']) === 'x.bin');
check('safe name kills dotfile',flash_safe_name('.bashrc', ['bin','rom']) === null);
check('safe name bad ext',      flash_safe_name('payload.sh', ['bin','rom']) === null);
check('safe name empty',        flash_safe_name('', ['bin']) === null);
check('safe name cleans chars', flash_safe_name('fw v2;rm.bin', ['bin']) === 'fwv2rm.bin');
check('safe name traversal+badext', flash_safe_name('../../etc/passwd', ['bin']) === null);

// ── flash_array_stopped: only STOPPED passes; missing/other fails safe ───────
$ini = sys_get_temp_dir() . '/hbav_varini_' . getmypid() . '.ini';
file_put_contents($ini, "mdState=\"STOPPED\"\n");
check('array stopped -> true',  flash_array_stopped($ini) === true);
file_put_contents($ini, "mdState=\"STARTED\"\n");
check('array started -> false', flash_array_stopped($ini) === false);
@unlink($ini);
check('missing varini -> false', flash_array_stopped($ini) === false);

// ── flash_preflight: happy path + every hard block ───────────────────────────
@mkdir(FLASH_DIR, 0755, true);
$fw = FLASH_DIR . '/unit.bin';
file_put_contents($fw, 'x');
$good = ['enable'=>1, 'stopped'=>true, 'ctl'=>'0', 'fw'=>$fw, 'confirm'=>'FLASH', 'locked'=>false];
$err  = fn($ov) => flash_preflight(array_merge($good, $ov))['error'];

check('preflight ok',            flash_preflight($good)['ok'] === true);
check('block disabled',          str_contains($err(['enable'=>0]),  'disabled'));
check('block array running',     str_contains($err(['stopped'=>false]), 'STOPPED'));
check('block bad ctl',           str_contains($err(['ctl'=>'x']),   'controller'));
check('block missing fw',        str_contains($err(['fw'=>'']),     'No firmware'));
check('block path escape',       str_contains($err(['fw'=>'/tmp/evil.bin']), 'not permitted'));
check('block no confirm',        str_contains($err(['confirm'=>'flash']), 'Type FLASH'));
check('block locked',            str_contains($err(['locked'=>true]), 'in progress'));
@unlink($fw);

echo $fails === 0 ? "flash_php: all pass\n" : "flash_php: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
