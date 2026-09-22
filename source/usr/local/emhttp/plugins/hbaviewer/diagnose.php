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
    /* diag_job_running() already absorbs the "starting" window into $running
       (case 3 of its three), so !$running here means genuinely over -- a
       cancelled job (never writes status, since diag_cancel() SIGTERMs the
       group before its trailer's `echo $? > status` can run), a SIGKILLed
       engine, or an unknown job id all belong in 'error', not a silent null
       that never lets a polling client reach a terminal state. */
    if (!$running && $exit === 0) $res['done'] = 'success';
    elseif (!$running)            $res['done'] = 'error';
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

if ($action === 'verdict') {
    $job = (string) ($_GET['job'] ?? '');
    if (!diag_job_valid($job)) { echo json_encode(['error' => 'Invalid job.']); exit; }
    header('Content-Type: text/html; charset=utf-8');
    require_once __DIR__ . '/render/diagnose.php';
    $dir  = diag_job_dir($job, DIAG_ROOT);
    $disk = diag_job_disk($job);
    /* The whole event file, not a slice: this runs once, when a job ends or a
       past verdict is reopened, and the file is the size of a scan's chunk
       count -- not a stream to keep up with. */
    $events = diag_events_decode((string) @file_get_contents("$dir/events.ndjson"));
    /* sense-<dev>.txt is "  4 Sense Key : 0x3" per line, the engine's own
       uniq -c output. */
    $sense = [];
    foreach (explode("\n", (string) @file_get_contents("$dir/sense-$disk.txt")) as $l) {
        if (preg_match('/^\s*(\d+)\s+Sense Key : (0x[0-9a-f]+)/', $l, $m)) {
            $sense[$m[2]] = (int) $m[1];
        }
    }
    $age = null;
    if (preg_match_all('/cmd_age=(\d+)/', (string) @file_get_contents("$dir/dmesg-$disk.txt"), $m)) {
        $age = max(array_map('intval', $m[1]));
    }
    /* Assigned-ness decides which next-steps card the screen shows, and
       getting it wrong in the permissive direction would offer array advice
       for an unassigned disk or, worse, the reverse. Read from Unraid's own
       disks.ini, and treat an unreadable file as ASSIGNED -- the stricter of
       the two cards, which never suggests writing to the disk. */
    $ini = @parse_ini_file('/var/local/emhttp/disks.ini', true);
    $arrayDisk = true;
    if (is_array($ini)) {
        $arrayDisk = false;
        foreach ($ini as $name => $sec) {
            if (($sec['device'] ?? '') === $disk && preg_match('/^disk\d+$/', (string) $name)) {
                $arrayDisk = true; break;
            }
        }
    }
    $recent = [];
    foreach (glob(DIAG_ROOT . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        $j = basename($d);
        if (!diag_job_valid($j) || $j === $job) continue;
        $ve = diag_events_decode((string) @file_get_contents("$d/events.ndjson"));
        $vv = 'unknown';
        foreach ($ve as $e) if (($e['t'] ?? '') === 'verdict') $vv = (string) ($e['v'] ?? 'unknown');
        $recent[] = ['job' => $j, 'disk' => diag_job_disk($j), 'verdict' => $vv,
                     'mtime' => (int) @filemtime($d)];
    }
    usort($recent, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    $verdict = ''; $why = '';
    foreach ($events as $e) {
        if (($e['t'] ?? '') === 'verdict') {
            $verdict = (string) ($e['v'] ?? ''); $why = (string) ($e['why'] ?? '');
        }
    }
    echo renderDiagVerdict([
        'disk' => $disk, 'verdict' => $verdict, 'why' => $why, 'events' => $events,
        'sense' => $sense, 'max_cmd_age' => $age, 'array_disk' => $arrayDisk,
        'ports' => [], 'recent' => array_slice($recent, 0, 5),
    ]);
    exit;
}

if ($action === 'drivelist') {
    header('Content-Type: text/html; charset=utf-8');
    require_once __DIR__ . '/render/diagnose.php';
    /* Assigned-ness and the /dev name both come from Unraid's own disks.ini,
       the same file the engine reads its slots from -- not from a second
       enumeration that could disagree with it about which disk is Disk 1. */
    $ini = @parse_ini_file('/var/local/emhttp/disks.ini', true);
    $drives = [];
    foreach (is_array($ini) ? $ini : [] as $name => $sec) {
        $dev = (string) ($sec['device'] ?? '');
        if ($dev === '' || !diag_disk_valid($dev)) continue;
        /* "disk3" is an array slot; a pool member or an unassigned device is
           not, and lands in the sidebar's own group. */
        $drives[] = ['dev' => $dev,
                     'role' => preg_match('/^disk\d+$/', (string) $name) ? 'Disk ' . preg_replace('/\D/', '', (string) $name)
                             : (((string) $name === 'parity') ? 'Parity' : '')];
    }
    /* A disk with a live lock is SCANNING; one with a finished run carries its
       verdict; one with neither is CLEAN only in the sense of "nothing has
       been tested", which is why the badge set has no fourth state for it --
       the drive list is a launcher, and CLEAN here means "no finding on
       record", stated the same way everywhere. */
    $verdicts = [];
    foreach ($drives as $d) {
        if (is_file(diag_lock_path($d['dev'], DIAG_ROOT))) { $verdicts[$d['dev']] = 'SCANNING'; continue; }
        $newest = null; $newestAt = -1;
        foreach (glob(DIAG_ROOT . '/' . $d['dev'] . '-*', GLOB_ONLYDIR) ?: [] as $jd) {
            if (!diag_job_valid(basename($jd))) continue;
            $at = (int) @filemtime($jd);
            if ($at > $newestAt) { $newestAt = $at; $newest = $jd; }
        }
        if ($newest === null) continue;
        foreach (diag_events_decode((string) @file_get_contents("$newest/events.ndjson")) as $e) {
            if (($e['t'] ?? '') === 'verdict') $verdicts[$d['dev']] = (string) ($e['v'] ?? '');
        }
    }
    echo renderDiagDriveList($drives, $verdicts);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action.']);
