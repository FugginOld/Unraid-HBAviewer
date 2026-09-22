<?PHP
require_once __DIR__ . '/diagnose_lib.php';

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
    $raw     = is_file($stf) ? trim((string) @file_get_contents($stf)) : '';
    $exit    = $raw === '' ? null : (int) $raw;
    $running = diag_job_running($dir, $disk, 'diag_kill_probe');
    $res = ['running' => $running, 'disk' => $disk,
            'exit' => $running ? null : $exit, 'done' => null];
    if (!$running && $exit === 0)        $res['done'] = 'success';
    elseif (!$running && $exit !== null) $res['done'] = 'error';
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
        $running = diag_job_running($d, $disk, 'diag_kill_probe');
        $jobs[] = ['job' => $job, 'disk' => $disk, 'mtime' => (int) @filemtime($d),
                   'running' => $running];
    }
    usort($jobs, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    echo json_encode(['jobs' => $jobs]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action.']);
