<?PHP
/* HBAviewer firmware/BIOS flash endpoint — the ONLY mutating surface, kept
 * deliberately separate from the read-only ajax_info.php.
 *
 * Every action is gated by the opt-in ENABLE_FLASH toggle. The mutating action
 * passes a hard preflight (array stopped, valid controller, confirmed image,
 * single-flight lock) before it launches a detached job. The dangerous work
 * itself lives in scripts/flash_hba.sh.
 *
 * The guard functions are pure over injected inputs and unit-tested; the HTTP
 * dispatch at the bottom runs only when served (not under the CLI test runner).
 */

require_once __DIR__ . '/config.php';

const FLASH_DIR     = '/tmp/hbav_flash';                              // uploads + job artifacts
const FLASH_TOOLS   = '/boot/config/plugins/hbaviewer/tools';        // persisted user-uploaded flashers
const FLASH_VARINI  = '/var/local/emhttp/var.ini';                   // Unraid array state
const FLASH_SCRIPTS = '/usr/local/emhttp/plugins/hbaviewer/scripts';

/* Array must be STOPPED before flashing. A missing/unreadable var.ini or any
   non-STOPPED state fails safe -> block. */
function flash_array_stopped(string $varini = FLASH_VARINI): bool {
    if (!is_file($varini)) return false;
    $ini = @parse_ini_file($varini);
    return is_array($ini) && strtoupper((string) ($ini['mdState'] ?? '')) === 'STOPPED';
}

/* Confine an uploaded filename to a safe basename with an allowed extension.
   Strips any path, whitelists the charset, rejects empties/dotfiles. Returns
   the safe basename or null. */
function flash_safe_name(string $name, array $allowedExt): ?string {
    $base = basename(str_replace('\\', '/', $name));       // kill any path component
    $base = preg_replace('/[^A-Za-z0-9._-]/', '', $base);   // whitelist charset
    if ($base === '' || $base[0] === '.') return null;       // no empty, no dotfiles
    $ext = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
    return in_array($ext, $allowedExt, true) ? $base : null;
}

/* Pure preflight gate for a flash request. Returns [ok=>bool, error=>string].
   The handler injects real values; tests inject fakes. Order = user-friendliest
   failure first, but every check is a hard block. */
function flash_preflight(array $in): array {
    if ((int) ($in['enable'] ?? 0) !== 1)
        return ['ok' => false, 'error' => 'Firmware flashing is disabled. Enable it in Settings first.'];
    if (empty($in['stopped']))
        return ['ok' => false, 'error' => 'The array must be STOPPED before flashing. Stop it on the Main tab, then retry.'];
    if (!preg_match('/^\d+$/', (string) ($in['ctl'] ?? '')))
        return ['ok' => false, 'error' => 'Invalid controller index.'];
    $fw = (string) ($in['fw'] ?? '');
    if ($fw === '')
        return ['ok' => false, 'error' => 'No firmware image selected. Upload it first.'];
    if (strpos($fw, FLASH_DIR . '/') !== 0)
        return ['ok' => false, 'error' => 'Firmware path is not permitted.'];
    if (!is_file($fw))
        return ['ok' => false, 'error' => 'Firmware image not found. Upload it first.'];
    if (($in['confirm'] ?? '') !== 'FLASH')
        return ['ok' => false, 'error' => 'Type FLASH (all caps) to confirm.'];
    if (!empty($in['locked']))
        return ['ok' => false, 'error' => 'A flash is already in progress.'];
    return ['ok' => true, 'error' => ''];
}

/* Claim the single-flight lock ATOMICALLY. 'x' fails when the file already
   exists, so of two concurrent requests exactly one can win — unlike
   is_file()-then-touch(), which let both pass the gate and launch a flash at
   the same controller. Returns true if THIS caller now owns the lock, in which
   case it must release it on any subsequent refusal. */
function flash_claim_lock(string $lock): bool {
    $fh = @fopen($lock, 'x');
    if ($fh === false) return false;
    fclose($fh);
    return true;
}

/* ── HTTP dispatch (served only; skipped under the CLI test runner) ─────────── */
if (PHP_SAPI === 'cli') return;

$cfg    = lsi_config_read();
$enable = (int) $cfg['ENABLE_FLASH'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($enable !== 1) { http_response_code(403); echo 'Firmware flashing is disabled.'; exit; }
// CSRF is enforced by Unraid's platform layer (a token-less POST never reaches
// here). The client sends Unraid's csrf_token so that layer passes it through.
@mkdir(FLASH_DIR, 0755, true);

if ($action === 'upload') {
    header('Content-Type: application/json');
    $out = [];
    if (!empty($_FILES['firmware']['tmp_name']) && is_uploaded_file($_FILES['firmware']['tmp_name'])) {
        $name = flash_safe_name((string) $_FILES['firmware']['name'], ['bin', 'rom', 'fw']);
        if ($name === null) { echo json_encode(['error' => 'Firmware must be a .bin / .rom file.']); exit; }
        move_uploaded_file($_FILES['firmware']['tmp_name'], FLASH_DIR . '/' . $name);
        $out['firmware'] = $name;
    }
    if (!empty($_FILES['bios']['tmp_name']) && is_uploaded_file($_FILES['bios']['tmp_name'])) {
        $name = flash_safe_name((string) $_FILES['bios']['name'], ['rom', 'bin']);
        if ($name !== null) { move_uploaded_file($_FILES['bios']['tmp_name'], FLASH_DIR . '/' . $name); $out['bios'] = $name; }
    }
    if (!empty($_FILES['tool']['tmp_name']) && is_uploaded_file($_FILES['tool']['tmp_name'])) {
        $tname = strtolower(basename(str_replace('\\', '/', (string) $_FILES['tool']['name'])));
        if (in_array($tname, ['sas2flash', 'sas3flash'], true)) {
            @mkdir(FLASH_TOOLS, 0755, true);
            move_uploaded_file($_FILES['tool']['tmp_name'], FLASH_TOOLS . '/' . $tname);
            @chmod(FLASH_TOOLS . '/' . $tname, 0755);
            $out['tool'] = $tname;
        }
    }
    echo json_encode($out ?: ['error' => 'No file uploaded.']);
    exit;
}

if ($action === 'listall') {
    header('Content-Type: text/plain; charset=utf-8');
    $chip = preg_replace('/[^A-Za-z0-9]/', '', $_POST['chip'] ?? $_GET['chip'] ?? '');
    $ctl  = (string) ($_POST['ctl'] ?? $_GET['ctl'] ?? '');
    if ($chip === '' || !preg_match('/^\d+$/', $ctl)) { echo 'Invalid controller.'; exit; }
    echo (string) shell_exec('bash ' . escapeshellarg(FLASH_SCRIPTS . '/flash_hba.sh')
        . ' list ' . escapeshellarg($chip) . ' ' . escapeshellarg($ctl) . ' 2>&1');
    exit;
}

if ($action === 'flash') {
    header('Content-Type: application/json');
    $chip   = preg_replace('/[^A-Za-z0-9]/', '', $_POST['chip'] ?? '');
    $ctl    = (string) ($_POST['ctl'] ?? '');
    $fwName  = flash_safe_name((string) ($_POST['firmware'] ?? ''), ['bin', 'rom', 'fw']);
    $biosNm  = ($_POST['bios'] ?? '') !== '' ? flash_safe_name((string) $_POST['bios'], ['rom', 'bin']) : null;
    $fw     = $fwName !== null ? FLASH_DIR . '/' . $fwName : '';
    $lock   = FLASH_DIR . '/flash.lock';

    // Claim single-flight BEFORE the gate, so the check and the claim can't be
    // interleaved by a second request. Any refusal below hands the lock back.
    $owned = flash_claim_lock($lock);

    $pf = flash_preflight([
        'enable'  => $enable,
        'stopped' => flash_array_stopped(),
        'ctl'     => $ctl,
        'fw'      => $fw,
        'confirm' => $_POST['confirm'] ?? '',
        'locked'  => !$owned,
    ]);
    if (!$pf['ok'] || $chip === '') {
        // Only release a lock we actually own — if $owned is false another
        // request holds it and unlinking would break ITS single-flight.
        if ($owned) @unlink($lock);
        echo json_encode(['error' => $pf['ok'] ? 'Missing controller chip.' : $pf['error']]);
        exit;
    }

    // Single-flight lock is held. Clear prior artifacts, launch ONE detached job
    // that captures stdout+stderr and records its exit code. Never auto-relaunched.
    @unlink(FLASH_DIR . '/flash.log');
    @unlink(FLASH_DIR . '/flash.status');
    $bios = ($biosNm !== null && is_file(FLASH_DIR . '/' . $biosNm)) ? FLASH_DIR . '/' . $biosNm : '';
    $cmd  = 'bash ' . escapeshellarg(FLASH_SCRIPTS . '/flash_hba.sh') . ' flash '
          . escapeshellarg($chip) . ' ' . escapeshellarg($ctl) . ' ' . escapeshellarg($fw)
          . ($bios !== '' ? ' ' . escapeshellarg($bios) : '');
    $inner = "$cmd > " . escapeshellarg(FLASH_DIR . '/flash.log') . ' 2>&1; '
           . 'echo $? > ' . escapeshellarg(FLASH_DIR . '/flash.status') . '; '
           . 'rm -f ' . escapeshellarg($lock);
    shell_exec('nohup sh -c ' . escapeshellarg($inner) . ' >/dev/null 2>&1 &');
    echo json_encode(['ok' => true, 'state' => 'flashing']);
    exit;
}

if ($action === 'status') {
    header('Content-Type: application/json');
    $log  = FLASH_DIR . '/flash.log';
    $stf  = FLASH_DIR . '/flash.status';
    $running = is_file(FLASH_DIR . '/flash.lock');
    $exit = is_file($stf) ? (int) trim((string) @file_get_contents($stf)) : null;
    $res  = [
        'running' => $running,
        'exit'    => $running ? null : $exit,
        'log'     => is_file($log) ? (string) file_get_contents($log) : '',
    ];
    if (!$running && $exit === 0)          $res['done'] = 'success';
    elseif (!$running && $exit !== null)   $res['done'] = 'error';
    echo json_encode($res);
    exit;
}

http_response_code(400);
echo 'Unknown action.';
