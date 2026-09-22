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
