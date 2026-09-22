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
// Tokenized, not regexed: the dispatch's own glob(DIAG_ROOT . '/*', ...) calls
// put a literal "/*" inside a STRING, and a regex stripper can't tell that
// apart from a real comment opener -- once a real comment appears anywhere
// later in the file, a naive `/\*.*?\*/` match spans from that string's "/*"
// to the far-off real "*/", silently deleting everything in between. PHP's own
// tokenizer already knows the difference; use it instead of re-deriving it.
$code = implode('', array_map(
    fn($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($t) ? $t[1] : $t),
    token_get_all($src)
));

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
$lib = (string) file_get_contents(
    __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/diagnose_lib.php');
// The pure half is its own file with NO dispatch, so both endpoints and the
// test runner can require it without side effects. diagnose.php's dispatch
// executing on require is what made diagnose_stream.php answer 400 before it
// wrote a byte.
check('the shared library has no dispatch guard, because it has no dispatch',
      !str_contains($lib, "PHP_SAPI"));
foreach (['DIAG_ROOT', 'DIAG_SCRIPTS', 'DIAG_SSE_MAX_SECS'] as $c) {
    check("const $c lives in the dispatch-free library", str_contains($lib, "const $c"));
}
// diag_job_alive/diag_kill_probe moved alongside the rest of the pure half --
// diagnose_stream.php cannot require diagnose.php to borrow them, so nothing
// status/list/cancel depend on can be left behind.
check('diag_job_alive lives in the dispatch-free library',
      str_contains($lib, 'function diag_job_alive('));
check('diag_kill_probe lives in the dispatch-free library',
      str_contains($lib, 'function diag_kill_probe('));
$reqAt   = strpos($code, "require_once __DIR__ . '/diagnose_lib.php'");
check('diagnose.php requires the library above its dispatch guard',
      $reqAt !== false && $guardAt !== false && $reqAt < $guardAt);

// setsid, not bare nohup. nohup detaches from the terminal but leaves the job
// in the caller's process group, so there is no group of its own to signal and
// cancel degrades to killing the shell while sg_verify keeps reading.
check('the job is launched under setsid', str_contains($code, 'setsid'));

// The three checks below must be scoped to the DISPATCH code only (below the
// CLI guard). Task 9's diag_cancel()/diag_pgid() function bodies already
// contain the literal strings 'pgid' and 'diag_cancel(' above the guard, so
// matching against the whole file would pass even if the dispatch never
// called either one.
if ($guardAt === false) {
    echo "FAIL  cannot scope to dispatch code (guard not found)\n";
    exit(1);
}
$dispatchCode = substr($code, $guardAt);
// Rounds 1 and 2 both spent a fix cycle on this exact branch: diag_job_running()
// folds the starting window into $running, so a lingering "$exit !== null"
// term silently turned every cancelled job, SIGKILLed engine and unknown job
// id into a done:null a polling client can never resolve, instead of 'error'.
check('a job that ends with no status file still resolves to done:error, not a stuck null',
      str_contains($dispatchCode, 'elseif (!$running)')
      && !str_contains($dispatchCode, '$exit !== null'));
check('the launcher records the job process group',
      str_contains($dispatchCode, 'pgid'));

// The cancel path must reach diag_cancel(), which is where the NEGATIVE pid
// lives. A dispatch that called posix_kill($pid, …) directly would pass every
// other assertion here.
check('cancel goes through diag_cancel()', str_contains($dispatchCode, 'diag_cancel('));
// A dedicated "does not kill a bare pid" regex existed here and was dropped
// for being existential rather than universal, then replaced by this one --
// which checks EVERY 'kill' token in the dispatch, not just the presence of
// one correct call. \bkill\b skips diag_kill_probe (no word boundary between
// '_' and 'k') and only matches the standalone command word.
// A lone '-12345'-shaped argument is parsed by sh's kill builtin as a SIGNAL
// SPEC, not a pid -- nothing gets signalled and the error is swallowed by
// 2>/dev/null. 'kill -SIG -- <pid>' is the only unambiguous form, so every
// occurrence must be followed by it.
check('every kill in the dispatch uses the unambiguous kill -SIG -- form',
      !preg_match('/\bkill\b(?!\s+-\w+\s+--\s)/', $dispatchCode));
// status and list must ask per-JOB liveness through the one shared decision
// (diag_job_running(), which itself checks status file -> diag_job_alive() ->
// disk lock, in that order) -- not the per-disk lock alone, which is held by
// whichever job currently owns the disk, not by the specific job being asked
// about.
check('status and list both use the shared per-job running check',
      substr_count($dispatchCode, 'diag_job_running(') >= 2);

// The engine is invoked with --events, or the whole live view has nothing to
// read, and with the disk as an explicit /dev path.
check('the engine is invoked with --events', str_contains($code, '--events'));
check('every engine argument is escaped',
      substr_count($code, 'escapeshellarg') >= 4);

// --out is a fresh, unique directory every launch, so a baseline keyed to it
// never survives to the next run. The launcher must pass a stable, per-disk
// --state path instead -- see diag_baseline_path().
check('the start action passes a stable per-disk --state path, escaped',
      str_contains($code, "--state ' . escapeshellarg(diag_baseline_path("));

// Read-only phase: nothing here may reach the Tier 2/3 tools, whether by
// accident or by a later edit that thought it was helping.
foreach (['sg_reassign', 'write-sector', 'badblocks', 'sg_format', 'sg_sanitize'] as $t) {
    check("the dispatch never invokes $t", !str_contains($code, $t));
}

// Job artifacts are RAM. /boot is flash and a triage run is worthless after a
// reboot; writing runs there would wear the stick for nothing.
check('nothing under /boot is written', !str_contains($code, '/boot'));

/* ── the SSE endpoint ──────────────────────────────────────────────────── */
$ssrc = (string) file_get_contents(
    __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/diagnose_stream.php');
$scode = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $ssrc);

check('the stream declares the SSE content type',
      str_contains($scode, 'text/event-stream'));
// Every frame carries its byte offset as the SSE id, because that id is what
// the browser sends back as Last-Event-ID on an automatic reconnect. Without
// it the resume depends on the client remembering, and a reload forgets.
check('every frame carries the offset as its SSE id', str_contains($scode, 'id: '));
check('a reconnect is honoured via Last-Event-ID',
      str_contains($scode, 'HTTP_LAST_EVENT_ID'));
check('the stream reads through diag_slice()', str_contains($scode, 'diag_slice('));
// The end-of-stream check must ask per-JOB liveness (diag_job_running), not
// the per-disk lock -- the lock is held by whichever job currently owns the
// disk, not by the specific job this connection is streaming.
check('the stream asks diag_job_running(), not the raw per-disk lock',
      str_contains($scode, 'diag_job_running('));
// A 200 that closes makes EventSource reconnect forever; only a non-2xx
// status fails the connection permanently, so an invalid job id must send one.
check('an invalid job id fails the connection with a non-2xx status',
      str_contains($scode, 'http_response_code(400)'));

// BOUNDED. An unbounded stream holds a php-fpm worker for the length of a
// surface scan -- hours -- which is the exact shape of the incident
// docs/foreground-reads.md was written after. EventSource reconnects by
// itself, so ending the response costs the client nothing.
check('the stream is bounded by DIAG_SSE_MAX_SECS',
      str_contains($scode, 'DIAG_SSE_MAX_SECS'));
check('and the bound is actually compared against elapsed time',
      preg_match('/DIAG_SSE_MAX_SECS/', $scode)
      && preg_match('/(time\(\)|microtime)/', $scode));

// A stream that never yields to the poll interval spins a core. A stream that
// ignores a disconnected client keeps doing it after the tab closed.
check('the loop sleeps between polls',       preg_match('/usleep|sleep\(/', $scode));
check('a disconnected client ends the loop', str_contains($scode, 'connection_aborted'));
check('the job id is validated before use',  str_contains($scode, 'diag_job_valid('));

check('the verdict action renders server-side',
      str_contains($code, "action === 'verdict'")
      && str_contains($code, 'renderDiagVerdict('));
// An unreadable disks.ini must mean ASSIGNED, the stricter card -- it is the
// one that never suggests writing to the disk. The permissive default here
// would offer sector-level advice for an array member.
check('assigned-ness defaults to true when disks.ini cannot be read',
      str_contains($code, '$arrayDisk = true;'));
// renderDiagDriveList has exactly one caller. An uncalled renderer is one
// nobody notices breaking, and this is the surface every Diagnose job is
// started from.
check('the drive list has an action that renders it',
      str_contains($code, "action === 'drivelist'")
      && str_contains($code, 'renderDiagDriveList('));

$js = (string) file_get_contents(
    __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js');
check('the client fetches the drive list',  str_contains($js, 'luDiagDrives'));
check('the verdict renderer takes no arguments, matching its caller',
      str_contains($js, 'window.luDiagRenderVerdict = function ()')
      && str_contains($js, 'window.luDiagRenderVerdict();'));
$hbjs = (string) file_get_contents(
    __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.js');
// hbaviewer.js loads BEFORE diagnose_view.js, so the hook must be guarded on
// typeof or the first render of any tab throws before the strip works.
check('luTab fills the drive list, guarded on typeof',
      str_contains($hbjs, "typeof luDiagDrives === 'function'"));

echo $fails === 0 ? "diagnose_php: all pass\n" : "diagnose_php: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
