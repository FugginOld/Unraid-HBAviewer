<?PHP
/* Source assertions for diagnose.php's dispatch. The dispatch shells out and
   sets headers, so it cannot be called in-process -- but four of its
   properties are exactly the kind that regress silently, and prose does not
   hold them.
     php tests/diagnose_php_test.php  ->  "diagnose_php: all pass" (exit 0) */

$src = (string) file_get_contents(
    __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/diagnose.php');
// Comments are stripped before matching: this file argues about setsid and
// about kill -PGID in its own prose, and a comment naming a flag must not be
// able to satisfy an assertion about the code using it.
$code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src);

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

check('the CLI guard exists, so the test runner gets the functions',
      str_contains($code, "if (PHP_SAPI === 'cli') return;"));

// Every const the CLI runner reaches must be ABOVE the guard. Same rule
// ajax_render_test.php pins for ajax_info.php; a const beside its callers
// blanked the SMART tab once.
$guardAt = strpos($code, "if (PHP_SAPI === 'cli') return;");
foreach (['DIAG_ROOT', 'DIAG_SCRIPTS', 'DIAG_SSE_MAX_SECS'] as $c) {
    $at = strpos($code, "const $c");
    check("const $c is declared above the dispatch guard",
          $at !== false && $guardAt !== false && $at < $guardAt);
}

// setsid, not bare nohup. nohup detaches from the terminal but leaves the job
// in the caller's process group, so there is no group of its own to signal and
// cancel degrades to killing the shell while sg_verify keeps reading.
check('the job is launched under setsid', str_contains($code, 'setsid'));
check('the launcher records the job process group',
      str_contains($code, 'pgid'));

// The cancel path must reach diag_cancel(), which is where the NEGATIVE pid
// lives. A dispatch that called posix_kill($pid, …) directly would pass every
// other assertion here.
check('cancel goes through diag_cancel()', str_contains($code, 'diag_cancel('));
check('the dispatch does not kill a bare pid',
      !preg_match('/kill\s+\'?\s*\.\s*\$pid\b/', $code));

// The engine is invoked with --events, or the whole live view has nothing to
// read, and with the disk as an explicit /dev path.
check('the engine is invoked with --events', str_contains($code, '--events'));
check('every engine argument is escaped',
      substr_count($code, 'escapeshellarg') >= 4);

// Read-only phase: nothing here may reach the Tier 2/3 tools, whether by
// accident or by a later edit that thought it was helping.
foreach (['sg_reassign', 'write-sector', 'badblocks', 'sg_format', 'sg_sanitize'] as $t) {
    check("the dispatch never invokes $t", !str_contains($code, $t));
}

// Job artifacts are RAM. /boot is flash and a triage run is worthless after a
// reboot; writing runs there would wear the stick for nothing.
check('nothing under /boot is written', !str_contains($code, '/boot'));

echo $fails === 0 ? "diagnose_php: all pass\n" : "diagnose_php: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
