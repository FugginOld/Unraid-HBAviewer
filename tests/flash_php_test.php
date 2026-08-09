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

/* ── The firmware page's upload path ──────────────────────────────────────────
   Source-level assertions, because there is no JS harness in this repo and
   these three properties all failed together in a real session: the page hung
   on "Uploading…" forever, with the message rendered beside the wrong button.

   The hang was `document.getElementById(x).files[0]` on an element that no
   longer exists — Step 1 renders no file input once the tool is found, so null
   is the NORMAL state, not an edge case. That throws synchronously, after the
   label is set and before the fetch exists, so no .catch can ever clear it.
   Anything that sets "Uploading…" must be able to unset it again. */
$jsSrc    = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/flash_view.js');
$flashSrc = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/flash.php');

check('no unguarded .files read in the page',
      preg_match('/getElementById\([^)]*\)\s*\.files/', $jsSrc) === 0);
check('file inputs go through the null-safe helper',
      str_contains($jsSrc, 'function pick(id)') && str_contains($jsSrc, 'e && e.files'));

// Two Upload buttons per card now, so every call must name its target; a bare
// luFlashUpload(i) writes into whichever span the default happens to pick.
preg_match_all('/luFlashUpload\((.*?)\)/', $jsSrc, $m);
$bare = array_values(array_filter($m[1], fn($a) => !str_contains($a, ',')));
check('every upload call names its target', $bare === []);
check('the tool upload has its own status span', str_contains($jsSrc, "flash-up-tool-'+i"));
check('a non-JSON response is reported, not swallowed', str_contains($jsSrc, "'HTTP '+r.status"));

// /boot is vfat: chmod is a silent no-op there, and find_flasher resolves on
// [ -x ]. A stored-but-not-executable tool is invisible to the flasher while
// looking, to the user, exactly like a successful upload.
check('the endpoint verifies the tool is executable', str_contains($flashSrc, 'is_executable($dest)'));
check('the page says so when it is not',              str_contains($jsSrc, 'tool_exec === false'));
// The tool is only useful once Step 1 agrees it is there, and only the real
// lookup can say that — re-ask rather than assume the upload worked.
check('a stored tool re-runs the Step 1 lookup',      str_contains($jsSrc, 'luFlashTool(i)'));

echo $fails === 0 ? "flash_php: all pass\n" : "flash_php: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
