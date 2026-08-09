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

// ── flash_claim_lock: exactly one claimant wins, and a release re-arms it ────
// This is the single-flight guarantee that stands between a double-submit and
// two flash tools writing to the same controller at once.
$lk = sys_get_temp_dir() . '/hbav_lock_' . getmypid() . '.lock';
@unlink($lk);
check('claim lock: first wins',      flash_claim_lock($lk) === true);
check('claim lock: second refused',  flash_claim_lock($lk) === false);
check('claim lock: third refused',   flash_claim_lock($lk) === false);
@unlink($lk);
check('claim lock: re-arms after release', flash_claim_lock($lk) === true);
@unlink($lk);

/* ── The maintainer lock ──────────────────────────────────────────────────────
   Flashing is disabled for everyone in this release, over and above the user's
   own ENABLE_FLASH toggle, until the path has been tested on more hardware.

   These assertions are deliberately annoying: flipping LSI_FLASH_LOCKED back to
   false fails the first one, which is the point. Reactivation should be a
   decision somebody makes and edits a test for, not something that happens
   because a constant drifted.

   The source-order check is crude but it is the only property that matters and
   it cannot be tested any other way without HTTP: the 403 must come BEFORE any
   line that can reach flash_hba.sh. A lock placed after the dispatch is not a
   lock. Same technique bundle_coverage_test.sh already uses. */
$flashSrc = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/flash.php');

check('this release ships with flashing locked', LSI_FLASH_LOCKED === true);
check('the lock explains itself to the user',    trim(LSI_FLASH_LOCK_NOTE) !== '');

// Match the invocation, not the string: the file's header comment names
// flash_hba.sh several lines above the lock, and an earlier draft of this
// assertion matched that and failed. FLASH_SCRIPTS . '/flash_hba.sh' appears
// only where the script is actually run.
$lockPos  = strpos($flashSrc, 'if (LSI_FLASH_LOCKED)');
$firstRun = strpos($flashSrc, "FLASH_SCRIPTS . '/flash_hba.sh'");
check('flash.php refuses on the lock',           $lockPos !== false);
check('the flasher is invoked at all',           $firstRun !== false);
check('the refusal precedes every flash_hba.sh invocation',
      $lockPos !== false && $firstRun !== false && $lockPos < $firstRun);

// The UI half. Not the real gate -- flash.php is -- but a page offering buttons
// the endpoint would refuse is its own kind of bug, so both surfaces are pinned.
$settingsSrc = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/settings.php');
$viewSrc     = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/flash_view.php');
check('settings disables the toggle while locked',
      str_contains($settingsSrc, "LSI_FLASH_LOCKED ? 'disabled' : ''"));
check('settings hides the way in while locked',
      str_contains($settingsSrc, '!LSI_FLASH_LOCKED && (int)$cfg[\'ENABLE_FLASH\'] === 1'));
check('settings holds the saved value while locked',
      str_contains($settingsSrc, "LSI_FLASH_LOCKED\n            ? (int) (lsi_config_read()['ENABLE_FLASH'] ?? 0)"));
check('the firmware page leads with the lock',
      str_contains($viewSrc, '<?php if (LSI_FLASH_LOCKED): ?>'));

echo $fails === 0 ? "flash_php: all pass\n" : "flash_php: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
