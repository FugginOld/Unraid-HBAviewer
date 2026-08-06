<?PHP
/* Drive locate — start/stop the activity-light blink (plan 048).
 *
 * `scripts/locate_drive.sh` does the work; this file decides whether it may
 * run, for which device, and how to stop it. Everything above the dispatch is
 * pure over injected paths, so tests/locate_test.php exercises the validation,
 * the PID bookkeeping and the stale-marker rule with no /proc, no HTTP and no
 * hardware.
 *
 * WHY A PID FILE rather than pattern-matching pkill: stopping must kill
 * exactly this drive's loop and nothing else. The upstream this idea comes
 * from stops with `pkill -f "smartlocate <arg>"`, which pattern-matches
 * process command lines — and whose quoting is broken there anyway. One file
 * per device, holding one PID, is both narrower and simpler.
 */

require_once __DIR__ . '/config.php';

const LOCATE_PID_DIR = '/tmp';
const LOCATE_SCRIPT  = '/usr/local/emhttp/plugins/hbaviewer/scripts/locate_drive.sh';
const LOCATE_PREFIX  = 'hbav_locate_';

/* A SCSI H:C:T:L address, and nothing else. This is a request value that
   becomes part of a device path, so it is validated against its own shape —
   escaping alone would still allow pointing smartctl at an arbitrary path. */
function locate_addr_valid(string $addr): bool {
    return (bool) preg_match('/^\d{1,4}:\d{1,4}:\d{1,4}:\d{1,4}$/', $addr);
}

function locate_pid_path(string $addr, ?string $dir = null): string {
    return ($dir ?? LOCATE_PID_DIR) . '/' . LOCATE_PREFIX . str_replace(':', '_', $addr) . '.pid';
}

const LOCATE_BSG_DIR = '/dev/bsg';

/* Can this address be located at all? locate_drive.sh reads /dev/bsg/<addr>
   with smartctl and exits 3 when that node is absent -- which is the whole of
   its pre-loop failure surface, since locate.php has already validated the
   address shape. Checking here rather than parsing the script's exit code
   costs one stat and buys an error message that names the actual reason. */
function locate_reachable(string $addr, ?string $bsgDir = null): bool {
    return file_exists(($bsgDir ?? LOCATE_BSG_DIR) . '/' . $addr);
}

function locate_pid(string $addr, ?string $dir = null): ?int {
    $f = locate_pid_path($addr, $dir);
    if (!is_file($f)) return null;
    $pid = (int) trim((string) @file_get_contents($f));
    return $pid > 0 ? $pid : null;
}

/* Running means the marker exists AND that process is still alive. A stale
   marker — killed with -9, or left behind by a crash — must read as NOT
   running, or the button never comes back and the drive can never be located
   again without a reboot. */
function locate_running(string $addr, ?string $dir = null, ?string $procDir = null): bool {
    $pid = locate_pid($addr, $dir);
    return $pid !== null && is_dir(($procDir ?? '/proc') . '/' . $pid);
}

/* Every address currently locating. The UI calls this on load so a locate
   started in another tab (or before a reload) is still shown as running. */
function locate_active(?string $dir = null, ?string $procDir = null): array {
    $out = [];
    foreach (glob(($dir ?? LOCATE_PID_DIR) . '/' . LOCATE_PREFIX . '*.pid') ?: [] as $f) {
        $addr = str_replace('_', ':', substr(basename($f), strlen(LOCATE_PREFIX), -4));
        if (!locate_addr_valid($addr)) continue;
        if (locate_running($addr, $dir, $procDir)) $out[] = $addr;
        else @unlink($f);   // tidy the stale marker while we are here
    }
    sort($out);
    return $out;
}

/* ── POST dispatch (served only; skipped under the CLI test runner) ──────────
   POST, not GET: Unraid's local_prepend checks csrf_token on POST only, and
   this endpoint spawns a process. The client sends the token exactly as the
   bay map and Reset Baseline buttons do. */
if (PHP_SAPI === 'cli') return;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['action'])) return;

header('Content-Type: application/json; charset=utf-8');
$action = (string) $_POST['action'];

if ($action === 'status') {
    echo json_encode(['ok' => true, 'active' => locate_active()]);
    exit;
}

$addr = (string) ($_POST['addr'] ?? '');
if (!locate_addr_valid($addr)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid device address.']);
    exit;
}

if ($action === 'stop') {
    $pid = locate_pid($addr);
    // kill by PID only — never by name, never by pattern.
    if ($pid !== null) {
        shell_exec('kill ' . $pid . ' 2>/dev/null');
        /* Then WAIT for it to actually go. A signal is asynchronous: the loop
           handles it, clears its marker and exits a moment later. Answering
           before that is answering "still blinking", and since the client
           paints itself from this reply and has nothing to re-poll with, the
           UI would keep flashing over a drive that had already stopped.
           Bounded so a wedged process cannot hold the request open. */
        for ($i = 0; $i < 20 && locate_running($addr); $i++) usleep(50000);   // ≤1s
    }
    echo json_encode(['ok' => true, 'active' => locate_active()]);
    exit;
}

if ($action === 'start') {
    // Idempotent: a second press while it is already blinking is a no-op, not a
    // second process reading the same drive twice as often.
    if (!locate_running($addr)) {
        $max = lsi_clamp('LOCATE_MAX_SECS', lsi_config_read()['LOCATE_MAX_SECS']);
        shell_exec('nohup bash ' . escapeshellarg(LOCATE_SCRIPT) . ' '
                 . escapeshellarg($addr) . ' ' . (int) $max . ' >/dev/null 2>&1 &');
        // The script writes its own marker; give it a moment so the response
        // can report the state the caller is about to render.
        usleep(250000);
    }
    echo json_encode(['ok' => true, 'active' => locate_active()]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
exit;
