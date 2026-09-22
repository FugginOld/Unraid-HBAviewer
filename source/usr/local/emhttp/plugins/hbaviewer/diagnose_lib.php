<?PHP
/* HBAviewer diagnose — the pure half, shared by diagnose.php (the job
 * endpoint) and diagnose_stream.php (the SSE stream).
 *
 * NO DISPATCH LIVES HERE, and that is the point. diagnose.php's dispatch
 * executes on require under a web SAPI, so an endpoint that required it to
 * borrow one helper would answer its own request with a 400 before writing a
 * byte. A file with nothing but declarations is safe to require from anywhere,
 * including the CLI test runner.
 */

/* Every const the CLI test runner reaches must be declared ABOVE the dispatch
   guard: functions are hoisted, top-level consts are not. See ARCHITECTURE.md
   -- a const beside its callers blanked the SMART tab once. */
const DIAG_ROOT    = '/tmp/hbaviewer/jobs';
const DIAG_SCRIPTS = '/usr/local/emhttp/plugins/hbaviewer/scripts';

/* How long one SSE connection is allowed to hold a php-fpm worker before it
   closes and the browser reconnects. See diagnose_stream.php. */
const DIAG_SSE_MAX_SECS = 55;

/* Lowercase alnum only. That covers every name Linux gives a physical block
   device (sdb, nvme0n1) and excludes a traversal, a shell metacharacter, and
   the ':' NTFS cannot hold in a path. Device-mapper names are excluded on
   purpose: this diagnoses disks behind an HBA, not mappings over them. */
function diag_disk_valid(string $disk): bool {
    return (bool) preg_match('/^[a-z0-9]{2,32}\z/', $disk);
}

/* "sdb-1700000000". The timestamp is both the ordering key and the uniqueness
   key; two jobs for one disk in the same second cannot happen because the
   per-disk lock refuses the second (see diag_claim_lock). */
function diag_job_id(string $disk, int $now): string {
    return $disk . '-' . $now;
}

function diag_job_dir(string $jobId, string $root = DIAG_ROOT): string {
    return $root . '/' . $jobId;
}

/* Keep the newest $keep job directories for ONE disk; delete the rest and say
   which. Count-based, matching the KEEP_RUNS trimming drive_triage.sh already
   does -- an age-based scheme would be a second retention idea in a plugin
   that has one.
   Matched on the exact "<disk>-<digits>" shape rather than trusting the glob:
   the glob is a prefix match, so a careless pattern reaches a neighbouring
   disk's runs, and deleting those is not a tidy-up. */
function diag_trim_runs(string $disk, int $keep, string $root = DIAG_ROOT): array {
    if ($keep < 1 || !diag_disk_valid($disk)) return [];
    $dirs = [];
    foreach (glob("$root/$disk-*", GLOB_ONLYDIR) ?: [] as $d) {
        if (!preg_match('/^' . preg_quote($disk, '/') . '-\d+\z/', basename($d))) continue;
        $dirs[$d] = (int) @filemtime($d);
    }
    if (count($dirs) <= $keep) return [];
    arsort($dirs);                              // newest first
    $doomed = array_slice(array_keys($dirs), $keep);
    foreach ($doomed as $d) {
        foreach (glob("$d/*") ?: [] as $f) @unlink($f);
        @rmdir($d);
    }
    sort($doomed);
    return $doomed;
}

/* ONE LOCK PER DISK, not per job. The Tier 1 gate is "one job per disk at a
   time", and a lock named for the job could never express that -- two jobs on
   one disk would take two different locks and both win. */
function diag_lock_path(string $disk, string $root = DIAG_ROOT): string {
    return $root . '/' . $disk . '.lock';
}

/* Claim the single-flight lock ATOMICALLY. 'x' fails when the file already
   exists, so of two concurrent requests exactly one can win -- unlike
   is_file()-then-touch(), which lets both pass the gate and launch a job at
   the same disk. Returns true if THIS caller now owns it, in which case it
   must release it on any later refusal. */
function diag_claim_lock(string $lock): bool {
    @mkdir(dirname($lock), 0755, true);
    $fh = @fopen($lock, 'x');
    if ($fh === false) return false;
    fclose($fh);
    return true;
}

/* Pure preflight for a diagnose request. Returns [ok=>bool, error=>string].
   The handler injects real values; tests inject fakes.
   EVERY gate fails closed on a missing input. flash_preflight's 'card' gate
   defaulted to allow once and was the most dangerous gate in the plugin; this
   path writes nothing, but "absent means refused" is cheaper to keep true
   everywhere than to re-reason about per gate. */
function diag_preflight(array $in): array {
    if (!array_key_exists('disk', $in) || !diag_disk_valid((string) $in['disk']))
        return ['ok' => false, 'error' => 'Invalid disk name.'];
    if (!array_key_exists('exists', $in) || empty($in['exists']))
        return ['ok' => false, 'error' => 'No block device /dev/' . $in['disk']
                                        . ' — it may have been pulled or renamed. Reload the Drives tab.'];
    /* Tier 1 gate from the spec's risk table. Reading every block of a disk
       that parity is currently reconstructing competes with the rebuild for
       the same spindle and the same link, and slows the window in which the
       array has no redundancy. Fails closed on an unreadable array state for
       the same reason flash_array_stopped() does. */
    if (!array_key_exists('resync', $in) || (int) $in['resync'] !== 0)
        return ['ok' => false, 'error' => 'A parity check or rebuild is running. Diagnose competes with it for the same disk — wait for it to finish.'];
    if (!array_key_exists('locked', $in) || !empty($in['locked']))
        return ['ok' => false, 'error' => 'A Diagnose job is already running on this disk.'];
    return ['ok' => true, 'error' => ''];
}

/* The process-group id the launcher recorded. Null on anything unusable.
   0 and 1 are refused explicitly: kill(-0) signals the CALLER's own process
   group -- the php-fpm pool -- and kill(-1) signals every process the user can
   reach. Both are catastrophic and both are what a truncated or half-written
   pgid file most easily produces. */
function diag_pgid(string $dir): ?int {
    $raw = @file_get_contents("$dir/pgid");
    if ($raw === false) return null;
    $raw = trim((string) $raw);
    if (!preg_match('/^\d+\z/', $raw)) return null;
    $pgid = (int) $raw;
    return $pgid > 1 ? $pgid : null;
}

/* Cancel = signal the whole PROCESS GROUP, negative pid. The engine is
   launched under setsid so it leads its own group, and it spawns
   sg_verify/sg_read/smartctl children that hold the disk open. Signalling the
   parent alone leaves an sg_verify running with nothing left to reap it, and
   the lock released under a job that is still reading. */
function diag_cancel(string $dir, callable $kill): bool {
    $pgid = diag_pgid($dir);
    if ($pgid === null) return false;
    $kill(-$pgid);
    return true;
}

/* Is this job's process group still alive? diag_pgid() already validates the
   pgid file; this asks the injected probe (a real signal-0 kill in
   production) whether that group still exists. Unlike the per-disk lock,
   this answers the question per JOB: an old completed run on the same disk
   does not borrow "running" from whatever job currently holds the disk's
   lock. */
function diag_job_alive(string $dir, callable $probe): bool {
    $pgid = diag_pgid($dir);
    return $pgid !== null && $probe($pgid);
}

/* Is a job the one CURRENTLY active for its disk? One boolean, three cases,
   in this order -- status/list/the SSE stream all call this instead of each
   answering the question their own way, which is how the per-disk-lock vs
   per-job-liveness ambiguity kept reappearing.
     1. A written, non-empty status file is proof of termination. Always wins.
     2. Otherwise a live process group (diag_job_alive) proves THIS job is
        running -- not just some job on the disk.
     3. Otherwise, only for a job whose own directory exists and has not yet
        written its pgid file: the disk's lock. diag_claim_lock() claims it
        SYNCHRONOUSLY inside 'start', before that response is ever sent, so
        this exact window can only belong to this job's own launch in
        flight -- never a different job's, because a different job could
        only hold this lock by way of ITS OWN pgid file existing (case 2
        would already be true) or ITS OWN status file existing (case 1
        would already be true).
   $root is injectable, matching diag_job_dir()/diag_lock_path()/
   diag_trim_runs() -- every production caller omits it and gets DIAG_ROOT;
   the test runner injects a throwaway directory, same as every other helper
   here that touches a job or lock path. */
function diag_job_running(string $dir, string $disk, callable $probe, string $root = DIAG_ROOT): bool {
    $raw = is_file("$dir/status") ? trim((string) @file_get_contents("$dir/status")) : '';
    if ($raw !== '') return false;
    if (diag_job_alive($dir, $probe)) return true;
    return is_dir($dir) && !is_file("$dir/pgid") && is_file(diag_lock_path($disk, $root));
}

/* Read the event file from a byte offset. The file is the source of truth, not
   anything held in the PHP worker -- the same rule cached_read() follows, so a
   reconnecting browser resumes instead of restarting.
   Normally never returns a partial trailing line: the returned offset stops at
   the last newline and the partial line is re-read next time. ONE exception --
   a line that fills the whole $maxBytes window without a newline is returned
   raw, offset advanced past it, so the stream can't stall forever on one
   oversized event. The client MUST concatenate successive slices before
   splitting on newlines; it cannot assume every non-empty slice is
   newline-terminated. */
function diag_slice(string $file, int $offset, int $maxBytes): array {
    clearstatcache(true, $file);
    if (!is_file($file)) return ['bytes' => '', 'offset' => 0, 'eof' => true];
    $size = (int) filesize($file);
    /* An offset past the end is a client reconnecting to a file that was
       trimmed or replaced under it. Restart from zero: a fresh full read is
       correct and cheap, and returning nothing would leave the view frozen. */
    if ($offset < 0 || $offset > $size) $offset = 0;
    $fh = @fopen($file, 'rb');
    if ($fh === false) return ['bytes' => '', 'offset' => $offset, 'eof' => true];
    fseek($fh, $offset);
    $buf = (string) fread($fh, max(0, $maxBytes));
    fclose($fh);
    $nl = strrpos($buf, "\n");
    if ($nl === false) {
        if (strlen($buf) >= $maxBytes) {
            // The line exceeds one window's worth of bytes -- no newline will
            // ever appear here. Hand back the raw chunk and advance past it
            // rather than stalling the stream forever on one oversized event.
            $next = $offset + strlen($buf);
            return ['bytes' => $buf, 'offset' => $next, 'eof' => $next >= $size];
        }
        return ['bytes' => '', 'offset' => $offset, 'eof' => false];
    }
    $buf = substr($buf, 0, $nl + 1);
    $next = $offset + strlen($buf);
    return ['bytes' => $buf, 'offset' => $next, 'eof' => $next >= $size];
}

/* Is the array mid-parity-op? mdResync is nonzero during a check or rebuild.
   Fails closed the way flash_array_stopped() does: an unreadable state is a
   refusal, not a pass. */
function diag_resync(string $varini = '/var/local/emhttp/var.ini'): int {
    if (!is_file($varini)) return 1;
    $ini = @parse_ini_file($varini);
    if (!is_array($ini)) return 1;
    return (int) ($ini['mdResync'] ?? 1);
}

function diag_kill_probe(int $pgid): bool {
    exec('/bin/kill -0 -- ' . escapeshellarg((string) (-$pgid)) . ' 2>/dev/null', $out, $rc);
    return $rc === 0;
}

/* A job id is "<disk>-<digits>". Validated on the way IN, because it becomes a
   directory name and the disk half of it becomes a kill target. */
function diag_job_valid(string $jobId): bool {
    return (bool) preg_match('/^[a-z0-9]{2,32}-\d{1,20}\z/', $jobId);
}
function diag_job_disk(string $jobId): string {
    return substr($jobId, 0, (int) strrpos($jobId, '-'));
}
