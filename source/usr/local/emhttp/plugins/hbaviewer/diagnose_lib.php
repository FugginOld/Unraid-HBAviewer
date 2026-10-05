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

/* unraid_disk_roles(): the one disks.ini reader the SMART tab, the bay map
   and (from Task 6) the Diagnose sidebar use. render/baymap.php holds only
   function declarations, so requiring it here has no side effects. */
require_once __DIR__ . '/render/baymap.php';

/* Every const the CLI test runner reaches must be declared ABOVE the dispatch
   guard: functions are hoisted, top-level consts are not. See ARCHITECTURE.md
   -- a const beside its callers blanked the SMART tab once. */
const DIAG_ROOT    = '/tmp/hbaviewer/jobs';
const DIAG_SCRIPTS = '/usr/local/emhttp/plugins/hbaviewer/scripts';
/* Unraid's slot assignments. Passed explicitly to unraid_disk_roles(): that
   function's own default, UNRAID_DISKINI, is declared only in ajax_info.php. */
const DIAG_DISKS_INI = '/var/local/emhttp/disks.ini';

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

/* A stable, per-disk, cross-job location for drive_triage.sh's baseline file --
   distinct from diag_job_dir()'s per-job directories, which diag_trim_runs()
   sweeps and this must not live inside, or the baseline would be deleted the
   moment its own job's run got trimmed. */
function diag_baseline_path(string $disk, string $root = DIAG_ROOT): string {
    return $root . '/' . $disk . '.baseline.tsv';
}

/* The bad-range ledger drive_triage.sh keeps under --badrange-state: one row
   per 2048-block chunk that has ever failed a check, across runs. A stable
   per-disk sibling of the job directories, like the baseline and for the same
   reason -- it spans runs, so diag_trim_runs() must never reach it. */
function diag_badrange_path(string $disk, string $root = DIAG_ROOT): string {
    return $root . '/' . $disk . '.badranges.tsv';
}

/* The drive's serial: VPD page 0x80 (unit serial number) as the kernel cached
   it at scan. A sysfs read sends no command to the drive, so it cannot wake
   one, and it is not a shell-out. SAME source and normalization as
   drive_triage.sh's br_serial(), which keys the ledger rows: skip the 4-byte
   page header, keep printable ASCII, trim spaces. Change one side alone and
   every row silently disappears from the Repair screen. '' when unreadable. */
function diag_disk_serial(string $disk, string $sys = '/sys'): string {
    if (!diag_disk_valid($disk)) return '';
    $raw = @file_get_contents("$sys/block/$disk/device/vpd_pg80");
    if (!is_string($raw) || strlen($raw) <= 4) return '';
    return trim((string) preg_replace('/[^\x20-\x7E]/', '', substr($raw, 4)), ' ');
}

/* THIS drive's ledger rows, in file order. Columns: serial, chunk_start,
   confirm_count, first_run_id, last_run_id, last_class, updated_ts -- and any
   later ones, ignored (the forward path adds columns, not a new shape).
   Filtered to $serial: the file is named by sd letter, and a drive that takes
   over that letter must not inherit its predecessor's evidence. An unknown
   serial reads NOTHING, never the unfiltered file. A missing file is zero
   rows. A malformed row -- too few columns, a non-numeric chunk or count, a
   class the engine never writes -- is skipped, not fatal: the
   fail-closed-per-row rule diag_slice() follows. */
function diag_badranges_read(string $file, string $serial): array {
    if ($serial === '') return [];
    $rows = [];
    foreach (explode("\n", (string) @file_get_contents($file)) as $line) {
        $c = explode("\t", rtrim($line, "\r"));
        if (count($c) < 7 || $c[0] !== $serial || !ctype_digit($c[1]) || !ctype_digit($c[2]) || !ctype_digit($c[6])
            || !in_array($c[5], ['media', 'transport', 'intermittent', 'unresolved'], true)) continue;
        $rows[] = ['chunk_start' => (int) $c[1], 'confirm_count' => (int) $c[2],
                   'first_run'   => $c[3],       'last_run'      => $c[4],
                   'last_class'  => $c[5],       'updated'       => (int) $c[6]];
    }
    return $rows;
}

/* Has Unraid assigned $disk ANYWHERE -- parity, parity2, a diskN slot, or a
   pool? Asked of unraid_disk_roles(), the same reader the SMART tab, bay map
   and Diagnose sidebar use, so no two screens can disagree about one disk.
   Called by the verdict AND repair actions. Wrong in the permissive direction,
   a screen would offer unassigned-disk content for a disk parity or a pool
   depends on, so an unreadable disks.ini answers ASSIGNED -- checked here
   first, because unraid_disk_roles() returns [] for both "unreadable" and
   "nothing assigned". Replaces the verdict action's old inline ^disk\d+$
   test, which called parity and pool disks unassigned (a Phase 1 bug). */
function diag_array_disk(string $disk, string $iniFile = DIAG_DISKS_INI): bool {
    if (!is_array(@parse_ini_file($iniFile, true))) return true;
    return isset(unraid_disk_roles($iniFile)["/dev/$disk"]);
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
    $busy = diag_busy_reason($in['resync'] ?? null, $in['mover'] ?? null);
    if ($busy !== null)
        return ['ok' => false, 'error' => $busy];
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

/* Turns one diag_slice() read into distinct SSE frames, one per ndjson
   line, each with its own running id:. Sending several `data:` lines
   under one trailing blank line joins them per the SSE spec into ONE
   event whose data is those lines glued with "\n" -- not valid JSON, so
   the browser's JSON.parse(m.data) throws and diagnose_view.js's
   onmessage silently drops the whole batch. This is why a resume's
   offset-0 replay -- guaranteed more than one line for any job with more
   than one event already written -- never redrew anything. $offset is
   the byte offset this SLICE started from, not diag_slice()'s own
   advanced $s['offset']; the caller still uses that to know where to
   read from next. */
function diag_sse_frames(string $bytes, int $offset): array {
    if ($bytes === '') return [];
    $hasNl = substr($bytes, -1) === "\n";
    $lines = explode("\n", $hasNl ? substr($bytes, 0, -1) : $bytes);
    $n = count($lines);
    $pos = $offset;
    $frames = [];
    foreach ($lines as $i => $line) {
        // diag_slice() guarantees every line but a possible final raw
        // fragment ends in "\n" -- that fragment appears alone
        // (diag_slice's own over-long-line fallback, pinned by
        // tests/diagnose_test.php) and its id is the slice's own
        // offset, same as before this function existed.
        $pos += strlen($line) + ($i < $n - 1 || $hasNl ? 1 : 0);
        $frames[] = ['id' => $pos, 'data' => $line];
    }
    return $frames;
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

/* Is the mover running? The engine disables triage while it is (drive_triage.sh
   pgrep -f '/usr/local/sbin/mover') and exits clean having written no verdict,
   which left the screen waiting forever. Same match here, on /proc/<pid>/cmdline
   (NUL-separated), so the page and the engine cannot disagree. Null = /proc
   unreadable; the callers fail closed on it. */
function diag_mover_running(string $proc = '/proc'): ?bool {
    $dirs = is_dir($proc) ? glob("$proc/[0-9]*", GLOB_ONLYDIR) : false;
    if (!$dirs) return null;   // a real /proc always has pid 1; none means unreadable
    foreach ($dirs as $d) {
        $cmd = @file_get_contents("$d/cmdline");   // a pid can vanish between glob and read
        if ($cmd !== false && str_contains(str_replace("\0", ' ', $cmd), '/usr/local/sbin/mover')) return true;
    }
    return false;
}

/* The one reason Diagnose cannot start right now, or null. $resync is
   diag_resync(), $mover is diag_mover_running(); null in either is an unreadable
   state and is a refusal, not a pass. diag_preflight() gates the start with it
   and the Diagnose buttons grey out with it. */
function diag_busy_reason($resync, $mover): ?string {
    if ($resync === null || (int) $resync !== 0)
        return 'A parity check or rebuild is running. Diagnose competes with it for the same disk — wait for it to finish.';
    if ($mover !== false)
        return 'The mover is running. Diagnose is unavailable until it finishes.';
    return null;
}
function diag_busy(): ?string {
    return diag_busy_reason(diag_resync(), diag_mover_running());
}

/* The Diagnose button every tab embeds. Live, or -- while diag_busy() has a
   reason -- disabled with that reason as its tooltip. The argument is a JSON
   string literal so a quote cannot break out of the handler (issue #24). */
function diag_button(string $dev, ?string $busy = null): string {
    if ($busy !== null)
        return '<button class="lu-refresh-btn" type="button" disabled title="'
             . htmlspecialchars($busy, ENT_QUOTES) . '">Diagnose</button>';
    return '<button class="lu-refresh-btn" type="button" onclick="luDiagnose('
         . htmlspecialchars(json_encode($dev), ENT_QUOTES) . ')">Diagnose</button>';
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

/* The engine's --out is a fresh, unique directory per job, but drive_triage.sh
   still stamps its own run subdirectory inside it (OUTDIR/STAMP) and writes
   sense-<dev>.txt / dmesg-<dev>.txt there, not at --out itself. Exactly one
   stamped subdirectory is expected per job -- glob for the newest rather than
   assume there's only one, and fall back to the flat path so a future engine
   change that writes flat doesn't silently regress this to nothing. */
function diag_evidence_file(string $dir, string $name): string {
    $nested = glob("$dir/*/$name") ?: [];
    if ($nested !== []) {
        usort($nested, fn($a, $b) => (int) @filemtime($b) <=> (int) @filemtime($a));
        return $nested[0];
    }
    return "$dir/$name";
}
