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

/* ── the baseline path is per DISK and stable across jobs ────────────────
   Distinct from diag_job_dir()'s per-job directories, which diag_trim_runs()
   sweeps -- the baseline must not live inside one of those or it would be
   deleted the moment its own job's run got trimmed. */
check('a baseline path sits under the root, keyed by disk',
      diag_baseline_path('sdb', $root) === "$root/sdb.baseline.tsv");

/* ── the bad-range ledger: a stable per-disk path ─────────────────────────
   Same sibling-of-the-job-dirs placement as the baseline, for the same reason:
   the ledger spans runs, so it must not live where diag_trim_runs() sweeps. */
check('a ledger path sits under the root, keyed by disk',
      diag_badrange_path('sdb', $root) === "$root/sdb.badranges.tsv");
check('and it is not the baseline file',
      diag_badrange_path('sdb', $root) !== diag_baseline_path('sdb', $root));

/* ── the drive's serial: sysfs VPD 0x80, never a command to the drive ─────
   Same source and normalization as drive_triage.sh's br_serial(). The length
   byte is 0x30 -- printable -- so a reader that fails to skip the 4-byte
   header is caught rather than having the header stripped as non-printable. */
$sys = "$root/sys";
@mkdir("$sys/block/sdb/device", 0777, true);
file_put_contents("$sys/block/sdb/device/vpd_pg80", "\x00\x80\x00\x30  SERIALB00001  ");
check('the serial is read from VPD 0x80, header skipped, padding trimmed',
      diag_disk_serial('sdb', $sys) === 'SERIALB00001');
check('no VPD page means no serial', diag_disk_serial('sdc', $sys) === '');
@mkdir("$sys/block/sdd/device", 0777, true);
file_put_contents("$sys/block/sdd/device/vpd_pg80", "\x00\x80\x00\x00");
check('a header-only page means no serial', diag_disk_serial('sdd', $sys) === '');
check('an invalid disk name reads nothing', diag_disk_serial('../block/sdb', $sys) === '');

/* ── the reader: this drive's rows only, forgiving about everything else ── */
$led = "$root/sdb.badranges.tsv";
check('a missing ledger is zero rows, not an error', diag_badranges_read($led, 'S1') === []);
file_put_contents($led, implode("\n", [
    "S1\t10240\t2\t20260101-000000\t20260102-000000\tmedia\t1767312000",
    "S1\t20480\t0\t20260101-000000\t20260102-000000\ttransport\t1767312000",
    "S1\tNaN\t1\ta\tb\tmedia\t1",                      // non-numeric chunk_start
    "S1\t30720\t1\ta\tb",                              // too few columns
    "S1\t40960\t1\ta\tb\tbogus\t1",                    // a class the engine never writes
    "S1\t51200\t1\ta\tb\tmedia\t1\textra",             // a later column: the forward path
    "S1\t61440\t1\ta\tb\tintermittent\t5\r",           // hand-edited on Windows
    "OTHER\t10240\t2\ta\tb\tmedia\t1",                 // a previous drive on this sdX name
    "\t71680\t2\ta\tb\tmedia\t1",                      // no serial at all
    "",
]));
$rows = diag_badranges_read($led, 'S1');
check('only this drive\'s well-formed rows are read', count($rows) === 4);
check('a row carries chunk_start and confirm_count as ints',
      $rows[0]['chunk_start'] === 10240 && $rows[0]['confirm_count'] === 2);
check('and its last class and last run',
      $rows[0]['last_class'] === 'media' && $rows[0]['last_run'] === '20260102-000000');
check('a trailing extra column does not reject a row', $rows[2]['chunk_start'] === 51200);
check('a CRLF line still reads, updated_ts intact',
      $rows[3]['last_class'] === 'intermittent' && $rows[3]['updated'] === 5);
// OTHER also has a row at 10240: unfiltered, this drive would show two.
check('a row filed under another serial is not shown',
      count(array_filter($rows, fn($r) => $r['chunk_start'] === 10240)) === 1
      && count(diag_badranges_read($led, 'OTHER')) === 1);
// Unknown serial must show NOTHING -- not the unfiltered file, and not the
// row whose key is empty, which an equality test alone would match.
check('an unknown serial reads nothing', diag_badranges_read($led, '') === []);
@unlink($led);

/* ── assigned-ness: assigned ANYWHERE, one reader ─────────────────────────
   Parity, parity2 and pool members are assigned: the old inline ^disk\d+$
   test called them unassigned, and a parity disk's MEDIA verdict got the
   unassigned-disk advice. An unreadable disks.ini must mean ASSIGNED, the
   stricter answer, which never suggests writing to the disk. */
check('an unreadable disks.ini means ASSIGNED', diag_array_disk('sdb', "$root/nope.ini") === true);
$iniF = "$root/disks.ini";
file_put_contents($iniF, "[\"parity\"]\nname=\"parity\"\ndevice=\"sdp\"\n"
                       . "[\"parity2\"]\nname=\"parity2\"\ndevice=\"sdq\"\n"
                       . "[\"disk1\"]\nname=\"disk1\"\ndevice=\"sdb\"\n"
                       . "[\"disk2\"]\nname=\"disk2\"\ndevice=\"\"\n"
                       . "[\"cache\"]\nname=\"cache\"\ndevice=\"nvme0n1\"\n");
check('an array data slot is assigned',   diag_array_disk('sdb', $iniF) === true);
check('parity is assigned',               diag_array_disk('sdp', $iniF) === true);
check('parity2 is assigned',              diag_array_disk('sdq', $iniF) === true);
check('a pool member is assigned',        diag_array_disk('nvme0n1', $iniF) === true);
check('a device in no slot is not',       diag_array_disk('sdz', $iniF) === false);
@unlink($iniF);
foreach (['sdb', 'sdd'] as $d) { @unlink("$sys/block/$d/device/vpd_pg80"); @rmdir("$sys/block/$d/device"); @rmdir("$sys/block/$d"); }
@rmdir("$sys/block"); @rmdir($sys);

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
      diag_job_running($jd, 'sdb', $probeAlwaysTrue, $root) === false);

// Case 2: the trailer's `echo $? > status` truncates the file before writing
// the exit code, so a read landing in that gap sees an EXISTING, EMPTY file.
// That must fall through to the liveness/lock checks, not read as terminated.
file_put_contents("$jd/status", '');
check('an empty status file (the truncate race) falls through, not terminated',
      diag_job_running($jd, 'sdb', $probeAlwaysTrue, $root) === true);
@unlink("$jd/status");
@unlink($lock);

// Case 3: no status file, a live pgid -> running.
check('no status file and a live pgid is running',
      diag_job_running($jd, 'sdb', $probeAlwaysTrue, $root) === true);

// Case 4: no status file, a recorded pgid whose probe says gone, and no lock
// -> not running.
check('no status file, a dead pgid, and no lock is not running',
      diag_job_running($jd, 'sdb', $probeAlwaysFalse, $root) === false);

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

/* ── diag_sse_frames(): one ndjson line, one SSE frame ──────────────────
   Fix round: diagnose_stream.php used to write N `data:` lines under ONE
   trailing blank line for a multi-line slice. Per the SSE spec that joins
   them into ONE event whose data is the lines glued with "\n" -- not
   valid JSON, so the browser's JSON.parse(m.data) silently drops the
   WHOLE batch. This is why a resume's offset-0 replay (guaranteed more
   than one line) never redrew the map on real hardware. */
check('a missing diag_sse_frames means the old bug is still there',
      function_exists('diag_sse_frames'));

$frames = diag_sse_frames("{\"a\":1}\n{\"b\":2}\n", 0);
check('two lines become two frames, not one',
      count($frames) === 2);
check('each frame carries exactly one ndjson line',
      $frames[0]['data'] === '{"a":1}' && $frames[1]['data'] === '{"b":2}');
check('ids are the running per-line offset, not just the final one',
      $frames[0]['id'] === 8 && $frames[1]['id'] === 16);

check('empty bytes produce no frames', diag_sse_frames('', 0) === []);

// Post-review fix: rtrim($bytes, "\n") strips EVERY trailing newline, not just
// the one diag_slice() guarantees -- for a pathological slice ending in "\n\n"
// that swallows a real trailing blank line and leaves the last id one byte
// short of the true offset. Stripping exactly one trailing newline keeps that
// blank line as its own (empty) frame.
$dblNl = "{\"a\":1}\n{\"b\":2}\n\n";
$dblFrames = diag_sse_frames($dblNl, 0);
check('a slice ending in two newlines yields three frames, not two',
      count($dblFrames) === 3);
check('the extra trailing newline becomes its own empty final frame',
      $dblFrames[2]['data'] === '');
check('the last id lands at the true byte length, not one short',
      end($dblFrames)['id'] === strlen($dblNl));

check('resuming mid-stream keeps ids relative to the real file offset',
      diag_sse_frames("{\"c\":3}\n", 16) === [['id' => 24, 'data' => '{"c":3}']]);

// diag_slice()'s own oversized-single-record fallback: no newline at all.
// One frame, and its id is exactly what diag_slice itself would report,
// matching this function's behavior before it existed for this case.
$raw = str_repeat('x', 10);
check('a single unterminated fragment (diag_slice\'s over-long-line case) is one frame',
      diag_sse_frames($raw, 5) === [['id' => 15, 'data' => $raw]]);

// Post-review fix: diag_slice() silently resets to offset 0 internally when
// the requested offset is negative or past the file's current end (a client
// reconnecting after the file was trimmed/replaced, or a stale
// Last-Event-ID). The frame base must come from diag_slice()'s OWN returned
// offset, not the offset the caller asked it to read from -- otherwise every
// frame id is wrong by the stale requested offset. Reproduces the reviewer's
// case: a 16-byte 2-line file, requesting offset 5000 (past EOF) must give
// frame ids 8,16, not 5008,5016.
$resetBytes = "{\"a\":1}\n{\"b\":2}\n";
$resetEv = "$jd/reset.ndjson";
file_put_contents($resetEv, $resetBytes);
$rs = diag_slice($resetEv, 5000, 4096);
check('an offset past EOF makes diag_slice() reset internally to zero',
      $rs['offset'] === strlen($resetBytes));
$rsFrames = diag_sse_frames($rs['bytes'], $rs['offset'] - strlen($rs['bytes']));
check('the frame base derives from diag_slice()\'s own offset, so ids land at 8 and 16',
      $rsFrames[0]['id'] === 8 && $rsFrames[1]['id'] === 16);
check('the last frame id equals diag_slice()\'s returned offset, not the stale requested one',
      end($rsFrames)['id'] === $rs['offset']);
@unlink($resetEv);

/* ── diag_evidence_file(): the engine stamps its OWN run subdirectory ────
   inside --out (OUTDIR/STAMP), so sense-<dev>.txt / dmesg-<dev>.txt land at
   $dir/<STAMP>/name, never at $dir/name directly. Reading the flat path is
   the bug this function exists to fix -- Fix round 1's Critical finding. */
$evJob = "$root/sdb-77";
@mkdir($evJob, 0777, true);
check('no stamped run dir yet falls back to the flat path',
      diag_evidence_file($evJob, 'sense-sdb.txt') === "$evJob/sense-sdb.txt");

@mkdir("$evJob/20260101-000000", 0777, true);
file_put_contents("$evJob/20260101-000000/sense-sdb.txt", "  4 Sense Key : 0x3\n");
check('a single stamped run dir resolves the nested path',
      diag_evidence_file($evJob, 'sense-sdb.txt') === "$evJob/20260101-000000/sense-sdb.txt");

// Two stamped run dirs (a re-run reusing the same job id is not how the
// launcher works, but the resolver must not assume there is exactly one) --
// the NEWER one wins, by mtime, not by name sorting first or last.
@mkdir("$evJob/20260101-010000", 0777, true);
file_put_contents("$evJob/20260101-010000/sense-sdb.txt", "  1 Sense Key : 0x4\n");
touch("$evJob/20260101-000000/sense-sdb.txt", 1_700_000_000);
touch("$evJob/20260101-010000/sense-sdb.txt", 1_700_000_100);
check('the newer stamped run dir wins when two exist',
      diag_evidence_file($evJob, 'sense-sdb.txt') === "$evJob/20260101-010000/sense-sdb.txt");
// ...and reversed mtimes flip the answer, so this is reading mtime, not
// picking whichever glob() happened to return first.
touch("$evJob/20260101-000000/sense-sdb.txt", 1_700_000_200);
touch("$evJob/20260101-010000/sense-sdb.txt", 1_700_000_000);
check('mtime decides, not glob order', str_contains(
    diag_evidence_file($evJob, 'sense-sdb.txt'), '20260101-000000'));

// $wipe() only descends one level (matches the rest of this file's job dirs,
// which are flat); this fixture nests a stamped subdirectory inside its job
// dir, so clean that extra level up by hand rather than leaving it behind.
foreach (glob("$evJob/*", GLOB_ONLYDIR) ?: [] as $stamp) {
    foreach (glob("$stamp/*") ?: [] as $f) @unlink($f);
    @rmdir($stamp);
}
@rmdir($evJob);

$wipe(); @rmdir($root);
echo $fails === 0 ? "diagnose: all pass\n" : "diagnose: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
