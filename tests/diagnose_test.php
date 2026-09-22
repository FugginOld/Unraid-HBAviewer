<?PHP
/* Runnable checks for diagnose.php's pure half: identity, the per-disk
   retention sweep, the lock, the preflight and the SSE slice. Nothing here
   touches /tmp/hbaviewer, a real disk or a real job -- every path is injected.
     php tests/diagnose_test.php  ->  "diagnose: all pass" (exit 0) */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/diagnose_lib.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

$root = sys_get_temp_dir() . '/hbav_diag_' . getmypid();
@mkdir($root, 0777, true);

/* ── identity ──────────────────────────────────────────────────────────── */
check('a plain device name is valid',  diag_disk_valid('sdb'));
check('an nvme name is valid',         diag_disk_valid('nvme0n1'));
check('an empty name is refused',      !diag_disk_valid(''));
// The name becomes a directory component under DIAG_ROOT and an argument to a
// script running as root. Anything that can climb out, carry a shell
// metacharacter, or hold the ':' NTFS forbids in a path is refused HERE and
// nowhere else -- one validator, so two call sites cannot disagree.
check('a traversal is refused',        !diag_disk_valid('../etc'));
check('a slash is refused',            !diag_disk_valid('sd/b'));
check('a colon is refused',            !diag_disk_valid('0:0:2:0'));
check('a space is refused',            !diag_disk_valid('sd b'));
check('an uppercase name is refused',  !diag_disk_valid('SDB'));
check('a job id carries no separator of its own',
      diag_job_id('sdb', 1700000000) === 'sdb-1700000000');
check('a job dir sits under the root it was given',
      diag_job_dir('sdb-17', $root) === "$root/sdb-17");

/* ── retention: newest $keep per disk, and only that disk ──────────────── */
$mk = function (string $id, int $age) use ($root) {
    @mkdir("$root/$id", 0777, true);
    file_put_contents("$root/$id/events.ndjson", 'x');
    touch("$root/$id", 1_700_000_000 - $age);
};
$wipe = function () use ($root) {
    foreach (glob("$root/*") ?: [] as $d) {
        foreach (glob("$d/*") ?: [] as $f) @unlink($f);
        @rmdir($d);
    }
};
$wipe();
$mk('sdb-1', 400); $mk('sdb-2', 300); $mk('sdb-3', 200); $mk('sdb-4', 100);
$mk('sdc-1', 500);                        // another disk, must be untouched
$removed = diag_trim_runs('sdb', 2, $root);
check('trimming keeps the newest N', is_dir("$root/sdb-4") && is_dir("$root/sdb-3"));
check('trimming removes the oldest', !is_dir("$root/sdb-1") && !is_dir("$root/sdb-2"));
check('trimming names what it removed', $removed === ["$root/sdb-1", "$root/sdb-2"]);
// A sweep keyed on a bare prefix eats a neighbour: glob("sdb*") does not match
// sdc, but the same code written as glob("sd*") does, and deleting another
// disk's history is not a tidy-up. Asserted so the shape of the match is
// pinned rather than assumed.
check('trimming leaves other disks alone', is_dir("$root/sdc-1"));
check('keep below 1 removes nothing',
      diag_trim_runs('sdb', 0, $root) === [] && is_dir("$root/sdb-3"));
check('a disk with no runs is not an error', diag_trim_runs('sdz', 2, $root) === []);
check('an invalid disk name trims nothing',  diag_trim_runs('../', 1, $root) === []);

/* ── the lock is per DISK, and claiming is atomic ──────────────────────── */
$lock = diag_lock_path('sdb', $root);
check('the lock is named for the disk, not the job', $lock === "$root/sdb.lock");
@unlink($lock);
check('the first claim wins',   diag_claim_lock($lock) === true);
// fopen('x') and not is_file()-then-touch(): the latter lets two concurrent
// requests both pass the check and both launch a job at the same disk.
check('the second claim loses',  diag_claim_lock($lock) === false);
@unlink($lock);
check('and wins again once released', diag_claim_lock($lock) === true);
@unlink($lock);

/* ── preflight: every gate fails closed ────────────────────────────────── */
$base = ['disk' => 'sdb', 'resync' => 0, 'locked' => false, 'exists' => true];
check('a clean request passes', diag_preflight($base)['ok'] === true);

$r = diag_preflight(['disk' => 'sdb', 'resync' => 0, 'locked' => false, 'exists' => false]);
check('a device that is not there is refused', $r['ok'] === false);
check('and the refusal names the device',      str_contains($r['error'], 'sdb'));

check('an invalid disk name is refused',
      diag_preflight(array_merge($base, ['disk' => '../etc']))['ok'] === false);
check('an empty disk name is refused',
      diag_preflight(array_merge($base, ['disk' => '']))['ok'] === false);
// Tier 1 gate from the risk table: the array must not be mid-rebuild. Reading
// every block of a disk that parity is currently reconstructing competes with
// the rebuild for the same spindle and the same link.
check('a running parity op is refused',
      diag_preflight(array_merge($base, ['resync' => 1]))['ok'] === false);
check('an existing job on this disk is refused',
      diag_preflight(array_merge($base, ['locked' => true]))['ok'] === false);

// Absence is refusal, not permission. flash_preflight's 'card' gate defaulted
// to allow once and it was the most dangerous gate in the plugin; this one is
// far cheaper but the rule is the rule, and a caller that forgets to pass a
// key must not get a pass.
foreach (['disk', 'resync', 'locked', 'exists'] as $k) {
    $missing = $base; unset($missing[$k]);
    check("omitting '$k' fails closed", diag_preflight($missing)['ok'] === false);
}

/* ── cancel kills the GROUP ────────────────────────────────────────────── */
$jd = diag_job_dir('sdb-42', $root);
@mkdir($jd, 0777, true);
$killed = [];
$kill = function (int $sig) use (&$killed) { $killed[] = $sig; return true; };
check('no pgid recorded means nothing to cancel', diag_cancel($jd, $kill) === false);
check('and nothing was signalled',                $killed === []);

file_put_contents("$jd/pgid", "12345\n");
check('a recorded pgid is read back', diag_pgid($jd) === 12345);
$killed = [];
check('cancel reports it signalled', diag_cancel($jd, $kill) === true);
// NEGATIVE. setsid puts the engine in its own process group and the engine
// spawns sg_verify/sg_read/smartctl children; signalling the parent alone
// leaves an sg_verify holding the disk with nothing left to reap it.
check('cancel signals the whole process GROUP', $killed === [-12345]);

file_put_contents("$jd/pgid", "not a number\n");
check('a corrupt pgid file is no pgid', diag_pgid($jd) === null);
// A pgid of 0 or 1 would mean "signal this process group" or init. Refuse.
file_put_contents("$jd/pgid", "0\n");
check('pgid 0 is refused', diag_pgid($jd) === null);
file_put_contents("$jd/pgid", "1\n");
check('pgid 1 is refused', diag_pgid($jd) === null);

/* ── job liveness: per JOB, not the shared per-disk lock ─────────────────
   status/list must not borrow "running" from the per-disk lock: an old
   completed job on the same disk must not read as running just because a
   NEW job currently holds that disk's lock. */
@unlink("$jd/pgid");
$probeCalls = [];
$probe = function (int $pgid) use (&$probeCalls) { $probeCalls[] = $pgid; return true; };
check('no pgid file means not alive, and the probe is never called',
      diag_job_alive($jd, $probe) === false && $probeCalls === []);

file_put_contents("$jd/pgid", "12345\n");
$probeCalls = [];
check('a recorded pgid whose probe says alive is alive',
      diag_job_alive($jd, $probe) === true);
// diag_job_alive() hands the probe the pgid AS READ -- positive. Negating it
// for the real kill call is diag_kill_probe()'s job, not this function's.
check('the probe receives the POSITIVE pgid, not the negated form',
      $probeCalls === [12345]);

$probeCalls = [];
$probeDead = function (int $pgid) use (&$probeCalls) { $probeCalls[] = $pgid; return false; };
check('a recorded pgid whose probe says gone is not alive',
      diag_job_alive($jd, $probeDead) === false);

/* ── diag_job_running(): the one shared "is THIS job active" decision ────
   status/list/the SSE stream all call this instead of each answering the
   question their own way -- that drift is what reopened the per-disk-lock
   bug a third time. */
$probeAlwaysTrue  = function (int $pgid) { return true; };
$probeAlwaysFalse = function (int $pgid) { return false; };
$lock = diag_lock_path('sdb', $root);

// Case 1: a written, non-empty status file is proof of termination and wins
// over everything else -- a pgid the kernel recycled or a lock still held for
// a different reason must not override it.
file_put_contents("$jd/pgid", "12345\n");
file_put_contents("$jd/status", "0\n");
file_put_contents($lock, '');
check('a written status file means not running, no matter what else says running',
      diag_job_running($jd, 'sdb', $probeAlwaysTrue) === false);

// Case 2: the trailer's `echo $? > status` truncates the file before writing
// the exit code, so a read landing in that gap sees an EXISTING, EMPTY file.
// That must fall through to the liveness/lock checks, not read as terminated.
file_put_contents("$jd/status", '');
check('an empty status file (the truncate race) falls through, not terminated',
      diag_job_running($jd, 'sdb', $probeAlwaysTrue) === true);
@unlink("$jd/status");
@unlink($lock);

// Case 3: no status file, a live pgid -> running.
check('no status file and a live pgid is running',
      diag_job_running($jd, 'sdb', $probeAlwaysTrue) === true);

// Case 4: no status file, a recorded pgid whose probe says gone, and no lock
// -> not running.
check('no status file, a dead pgid, and no lock is not running',
      diag_job_running($jd, 'sdb', $probeAlwaysFalse) === false);

// Case 5: no status file, no pgid yet, but THIS job's own directory exists
// and the disk lock is held -- the "starting" window between `start`
// returning and the launcher's own setsid'd shell writing its pgid file.
@unlink("$jd/pgid");
file_put_contents($lock, '');
check('no pgid yet, own job dir exists, disk lock held: starting, i.e. running',
      diag_job_running($jd, 'sdb', $probeAlwaysFalse, $root) === true);

// Case 6: same, but the lock is not held either -> not running.
@unlink($lock);
check('no pgid, own job dir exists, no lock: not running',
      diag_job_running($jd, 'sdb', $probeAlwaysFalse, $root) === false);

// Case 7: THE BUG THIS FUNCTION FIXES. A job whose own directory does not
// exist at all (never launched) must not read as running just because a
// DIFFERENT job currently holds this disk's lock -- the exact case that broke
// under the old per-disk-lock-only check (that check would have returned
// true here, since it never looked at the job's own directory at all).
$ghostDir = diag_job_dir('sdb-999', $root);   // deliberately never created
file_put_contents($lock, '');
check("a lock held by a DIFFERENT job's launch does not make a nonexistent job dir read as running",
      diag_job_running($ghostDir, 'sdb', $probeAlwaysFalse, $root) === false);
@unlink($lock);

/* ── the SSE slice: resume from a byte offset ──────────────────────────── */
$ev = "$jd/events.ndjson";
file_put_contents($ev, "{\"t\":\"phase\"}\n{\"t\":\"chunk\"}\n");
$s = diag_slice($ev, 0, 4096);
check('from zero the slice is the whole file', $s['bytes'] === "{\"t\":\"phase\"}\n{\"t\":\"chunk\"}\n");
check('and the offset advances to the end',    $s['offset'] === filesize($ev));
check('and it reports eof',                    $s['eof'] === true);

$s2 = diag_slice($ev, 14, 4096);
check('resuming mid-file returns only what is new', $s2['bytes'] === "{\"t\":\"chunk\"}\n");

// A slice that stops mid-line hands the browser half a JSON object, and the
// client cannot tell a truncated line from a malformed one. The offset must
// come back at the last NEWLINE, so the partial line is re-read next time.
file_put_contents($ev, "{\"t\":\"phase\"}\n{\"t\":\"par");
$s3 = diag_slice($ev, 0, 4096);
check('a partial trailing line is withheld', $s3['bytes'] === "{\"t\":\"phase\"}\n");
check('and the offset stops at the newline',  $s3['offset'] === 14);
check('and it is not eof',                    $s3['eof'] === false);

// An offset past the end is a client that reconnected to a file which was
// trimmed or replaced. Restart it rather than returning garbage or failing.
$s4 = diag_slice($ev, 999999, 4096);
check('an offset past the end restarts from zero', $s4['offset'] === 14 && $s4['bytes'] !== '');
check('a negative offset is clamped to zero',      diag_slice($ev, -5, 4096)['offset'] === 14);
check('a missing file is empty, not an error',
      diag_slice("$jd/nope.ndjson", 0, 4096) === ['bytes' => '', 'offset' => 0, 'eof' => true]);

// Fix round 1: a stat cache must not hide writes from another process. The
// engine that appends to this file is always a different process than the
// one reading it, which is exactly the case filesize()'s cache never
// self-invalidates for.
$growFile = "$jd/grow.ndjson";
file_put_contents($growFile, "{\"a\":1}\n");
$r1 = diag_slice($growFile, 0, 4096);
check('first read gets the first line', $r1['bytes'] === "{\"a\":1}\n");

file_put_contents($growFile, "{\"b\":2}\n", FILE_APPEND);
$r2 = diag_slice($growFile, $r1['offset'], 4096);
check('second read gets the appended line', $r2['bytes'] === "{\"b\":2}\n");

file_put_contents($growFile, "{\"c\":3}\n", FILE_APPEND);
$r3 = diag_slice($growFile, $r2['offset'], 4096);
check('a third read does not replay the whole file from a stale stat cache',
      $r3['bytes'] === "{\"c\":3}\n");

// Fix round 2: a single event line longer than maxBytes must not stall the
// stream forever -- diag_slice must make forward progress even without a
// newline boundary once the whole window is consumed.
$longFile = "$jd/long.ndjson";
file_put_contents($longFile, str_repeat('x', 40)); // no newline anywhere
$rl1 = diag_slice($longFile, 0, 10);
check('an over-long line returns its window rather than stalling',
      $rl1['bytes'] !== '' && $rl1['offset'] > 0);
$rl2 = diag_slice($longFile, $rl1['offset'], 10);
check('the offset keeps advancing on the next call',
      $rl2['offset'] > $rl1['offset']);

$wipe(); @rmdir($root);
echo $fails === 0 ? "diagnose: all pass\n" : "diagnose: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
