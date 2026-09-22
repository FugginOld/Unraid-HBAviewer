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

/* Read the event file from a byte offset. The file is the source of truth, not
   anything held in the PHP worker -- the same rule cached_read() follows, so a
   reconnecting browser resumes instead of restarting.
   NEVER returns a partial trailing line: the client splits on newlines and
   cannot tell a truncated object from a malformed one, so the returned offset
   stops at the last newline and the partial line is re-read next time. */
function diag_slice(string $file, int $offset, int $maxBytes): array {
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
    if ($nl === false) return ['bytes' => '', 'offset' => $offset, 'eof' => false];
    $buf = substr($buf, 0, $nl + 1);
    $next = $offset + strlen($buf);
    return ['bytes' => $buf, 'offset' => $next, 'eof' => $next >= $size];
}
