<?PHP
/* HBAviewer disk-diagnose job endpoint — read-only.
 *
 * Phase 1 of the Disk Utility. Every operation this launches is a read: SMART
 * and log pages, SCSI VERIFY, SCSI READ, a SMART self-test. Nothing here
 * writes to a device, which is why it is NOT behind flash.php's opt-in toggle.
 *
 * The launch/lock/cancel SHAPE is flash.php's, deliberately: pure guards above
 * the dispatch, an atomic fopen('x') single-flight claim, a detached job that
 * releases its own lock. It shares no CODE with flash.php -- that file is the
 * only mutating surface in the plugin, and folding a read path into it widens
 * the thing docs/review-policy.md exists to protect.
 *
 * Job state is /tmp, never /boot. A triage run is worth nothing after a reboot
 * and flash wear is worth avoiding (contrast bay_map.json, which is the one
 * thing here that cannot be regenerated).
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

/* ── HTTP dispatch (served only; skipped under the CLI test runner) ────────── */
if (PHP_SAPI === 'cli') return;

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
/* CSRF is enforced by Unraid's platform layer; a token-less POST never reaches
   here. A plugin-side check was added once, denied every settings save, and is
   marked do-not-re-attempt. */
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$cfg    = lsi_config_read();
@mkdir(DIAG_ROOT, 0755, true);

/* A job id is "<disk>-<digits>". Validated on the way IN, because it becomes a
   directory name and the disk half of it becomes a kill target. */
function diag_job_valid(string $jobId): bool {
    return (bool) preg_match('/^[a-z0-9]{2,32}-\d{1,20}\z/', $jobId);
}
function diag_job_disk(string $jobId): string {
    return substr($jobId, 0, (int) strrpos($jobId, '-'));
}

if ($action === 'start') {
    $disk = (string) ($_POST['disk'] ?? '');
    $lock = diag_lock_path($disk, DIAG_ROOT);

    /* Claim single-flight BEFORE the gate, so the check and the claim cannot be
       interleaved by a second request. Any refusal below hands the lock back.
       Unlike flash.php there is no expensive hardware read to keep outside the
       claim window -- every input here is a stat or a small file read. */
    $owned = diag_disk_valid($disk) && diag_claim_lock($lock);

    $pf = diag_preflight([
        'disk'   => $disk,
        'exists' => diag_disk_valid($disk) && is_file("/sys/block/$disk/dev"),
        'resync' => diag_resync(),
        'locked' => !$owned,
    ]);
    if (!$pf['ok']) {
        if ($owned) @unlink($lock);
        echo json_encode(['error' => $pf['error']]);
        exit;
    }

    $now = time();
    $job = diag_job_id($disk, $now);
    $dir = diag_job_dir($job, DIAG_ROOT);
    @mkdir($dir, 0755, true);

    /* Trim BEFORE the new run, not after: trimming after would have to exclude
       the run it just made, and the count the user set would be off by one in
       whichever direction the next reader assumed. */
    diag_trim_runs($disk, (int) lsi_clamp('DIAG_KEEP_RUNS', $cfg['DIAG_KEEP_RUNS']), DIAG_ROOT);

    /* setsid, not a bare nohup. nohup detaches from the terminal but leaves the
       job in the CALLER's process group -- there would be no group of its own
       to signal, and Cancel would kill the wrapper shell while sg_verify kept
       reading the disk. setsid makes the engine a group leader, and the
       launcher records that group so diag_cancel() can signal all of it.
       $$ inside the setsid'd shell IS the new group id, because setsid makes
       that shell the leader. */
    $cmd = 'bash ' . escapeshellarg(DIAG_SCRIPTS . '/drive_triage.sh')
         . ' --out ' . escapeshellarg($dir)
         . ' --events ' . escapeshellarg("$dir/events.ndjson")
         . ' --auto-triage --all'
         . ' ' . escapeshellarg("/dev/$disk");
    $inner = 'echo $$ > ' . escapeshellarg("$dir/pgid") . '; '
           . $cmd . ' > ' . escapeshellarg("$dir/job.log") . ' 2>&1; '
           . 'echo $? > ' . escapeshellarg("$dir/status") . '; '
           . 'rm -f ' . escapeshellarg($lock);
    shell_exec('setsid sh -c ' . escapeshellarg($inner) . ' >/dev/null 2>&1 &');

    echo json_encode(['ok' => true, 'job' => $job, 'disk' => $disk]);
    exit;
}

if ($action === 'status') {
    $job = (string) ($_GET['job'] ?? $_POST['job'] ?? '');
    if (!diag_job_valid($job)) { echo json_encode(['error' => 'Invalid job.']); exit; }
    $disk = diag_job_disk($job);
    $dir  = diag_job_dir($job, DIAG_ROOT);
    $stf  = "$dir/status";
    /* A written status file is proof of termination on its own -- read it
       FIRST and skip the liveness probe once it exists. Without this, a
       finished job's pgid can be recycled by the kernel for an unrelated
       process and diag_job_alive() would say "running" again. */
    $exit    = is_file($stf) ? (int) trim((string) @file_get_contents($stf)) : null;
    $running = $exit === null && diag_job_alive($dir, 'diag_kill_probe');
    /* The launcher writes its OWN pgid file from inside the setsid'd shell, so
       there is a real window right after `start` returns where neither a pgid
       nor a status file exists yet. That is "starting", not "dead" -- and the
       per-disk lock is still held during exactly this window, which is the
       one place it's still the right signal. Without this, a client sees a
       false done:'error' for a job that is launching fine. */
    $starting = !is_file("$dir/pgid") && $exit === null
             && is_file(diag_lock_path($disk, DIAG_ROOT));
    $res = ['running' => $running, 'disk' => $disk,
            'exit' => $running ? null : $exit, 'done' => null];
    if (!$running && $exit === 0)               $res['done'] = 'success';
    elseif (!$running && !$starting)            $res['done'] = 'error';
    echo json_encode($res);
    exit;
}

if ($action === 'cancel') {
    $job = (string) ($_POST['job'] ?? '');
    if (!diag_job_valid($job)) { echo json_encode(['ok' => false, 'error' => 'Invalid job.']); exit; }
    $dir  = diag_job_dir($job, DIAG_ROOT);
    $disk = diag_job_disk($job);
    /* Signal the GROUP. posix_kill is not guaranteed present in Unraid's PHP
       build, so this goes through /bin/kill, which takes the negative pid the
       same way. diag_cancel() is what puts the minus sign there -- it is not
       spelled at this call site on purpose, so one place owns it. */
    $sent = diag_cancel($dir, function (int $target): void {
        shell_exec('/bin/kill -TERM -- ' . escapeshellarg((string) $target) . ' 2>/dev/null');
    });
    /* Release the lock even when no pgid was found: a job directory with no
       pgid is a launch that died before recording one, and leaving the lock
       would refuse every later job on that disk until reboot -- the orphaned
       lock flash.php's ordering comment exists to avoid. */
    @unlink(diag_lock_path($disk, DIAG_ROOT));
    echo json_encode(['ok' => $sent]);
    exit;
}

if ($action === 'list') {
    $jobs = [];
    foreach (glob(DIAG_ROOT . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        $job = basename($d);
        if (!diag_job_valid($job)) continue;
        $disk = diag_job_disk($job);
        $running = !is_file("$d/status") && diag_job_alive($d, 'diag_kill_probe');
        $jobs[] = ['job' => $job, 'disk' => $disk, 'mtime' => (int) @filemtime($d),
                   'running' => $running];
    }
    usort($jobs, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    echo json_encode(['jobs' => $jobs]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action.']);
