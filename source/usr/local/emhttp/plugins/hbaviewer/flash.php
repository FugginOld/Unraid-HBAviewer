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

const FLASH_DIR     = '/tmp/hbav_flash';                              // job artifacts: log, status, lock
/* The one directory the user drops everything into: the flash tool, the
 * firmware image and the optional BIOS. There is no upload form, because
 * uploading is not possible here -- see the note above the 'dropfiles' action.
 *
 * On /boot, for the same reason the tool is: flashing requires the array to be
 * STOPPED, and /mnt/user and /mnt/cache are unmounted when it is. Anything under
 * appdata would be present in every rehearsal and absent for every real flash.
 * /boot is always mounted and survives reboots. */
const FLASH_DROP    = '/boot/config/plugins/hbaviewer/flash';
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
    /* Firmware and BIOS are each optional, but not both. sas2flash/sas3flash
       take -f, -b, or both, so flashing a BIOS on its own is a real operation
       the tool supports and this used to refuse. storcli is different: on the
       9400/9500 generation the BIOS travels inside the firmware package, so
       there is no separate BIOS file and an image is mandatory — the caller
       passes 'bios_ok' to say which kind of tool this is.
       Both paths are confined and existence-checked; a name that survives
       flash_safe_name can still point outside the drop directory. */
    $dir  = $in['dir'] ?? FLASH_DROP;
    $fw   = (string) ($in['fw']   ?? '');
    $bios = (string) ($in['bios'] ?? '');

    if ($fw === '' && $bios === '')
        return ['ok' => false, 'error' => 'Select a firmware image, a BIOS image, or both. Place them in the flash folder, then reload.'];
    if ($fw === '' && empty($in['bios_ok']))
        return ['ok' => false, 'error' => 'This controller is flashed through storcli, where the BIOS is part of the firmware package. Select a firmware image.'];

    foreach ([['firmware', $fw], ['BIOS', $bios]] as [$label, $path]) {
        if ($path === '') continue;
        if (strpos($path, $dir . '/') !== 0)
            return ['ok' => false, 'error' => ucfirst($label) . ' path is not permitted.'];
        if (!is_file($path))
            return ['ok' => false, 'error' => ucfirst($label) . ' image not found in the flash folder.'];
    }
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

/* What has the user dropped in? Read-only listing of FLASH_DROP, so the page
   can offer the files that are actually there instead of a text box to mistype
   a filename into.
   
   There is no upload action, and there cannot be one. A multipart POST to any
   .php behind Unraid's nginx never completes: auth_request issues its subrequest
   to /auth-request.php carrying the original Content-Length but no body, PHP
   starts its rfc1867 parser and blocks on bytes that never arrive, and the
   request dies at fastcgi_read_timeout. Measured on a live box -- the identical
   POST to the identical script returns HTTP 302 in 12ms urlencoded and never
   returns at all as multipart. That is a platform issue no plugin can work
   around except by not sending multipart, so the user places files directly
   and this lists them. */
if ($action === 'dropfiles') {
    header('Content-Type: application/json');
    $out = ['dir' => FLASH_DROP, 'images' => []];
    foreach (glob(FLASH_DROP . '/*') ?: [] as $f) {
        if (!is_file($f)) continue;
        $base = basename($f);
        $ext = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
        if (in_array($ext, ['bin', 'rom', 'fw'], true)) {
            $out['images'][] = ['name' => $base, 'size' => (int) filesize($f)];
        }
    }
    usort($out['images'], fn($a, $b) => strcmp($a['name'], $b['name']));
    echo json_encode($out);
    exit;
}

/* Which flash tool does this chip need, and is it here? Read-only, no
   controller index, touches no hardware. The firmware page asks BEFORE offering
   Verify, so a user learns which tool to supply instead of discovering it from
   a failure — Step 1 used to require a tool that Step 2 was where you uploaded.
   Delegates to flash_hba.sh so the chip->tool mapping has exactly one home. */
if ($action === 'toolinfo') {
    header('Content-Type: application/json');
    $chip = preg_replace('/[^A-Za-z0-9]/', '', $_POST['chip'] ?? $_GET['chip'] ?? '');
    if ($chip === '') { echo json_encode(['status' => 'unknown']); exit; }
    $raw = (string) shell_exec('bash ' . escapeshellarg(FLASH_SCRIPTS . '/flash_hba.sh')
         . ' tool ' . escapeshellarg($chip) . ' 2>/dev/null');
    $out = ['family' => '', 'name' => '', 'path' => '', 'status' => 'unknown'];
    foreach (explode("\n", $raw) as $line) {
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        if (array_key_exists($k, $out)) $out[$k] = $v;
    }
    echo json_encode($out);
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
    // Same extensions as the firmware select: the page builds BOTH dropdowns from
    // one listing, so a .fw offered as BIOS was silently dropped here and the
    // flash ran without the BIOS the user picked.
    $biosNm  = ($_POST['bios'] ?? '') !== '' ? flash_safe_name((string) $_POST['bios'], ['bin', 'rom', 'fw']) : null;
    // Images live where the user dropped them, not where an upload put them.
    $fw     = $fwName  !== null ? FLASH_DROP . '/' . $fwName : '';
    $bios   = $biosNm  !== null ? FLASH_DROP . '/' . $biosNm : '';
    $lock   = FLASH_DIR . '/flash.lock';
    // Whether a BIOS-only flash is even meaningful is a property of the tool
    // family, so ask the one place that knows the chip->tool map rather than
    // matching chip prefixes here for a second time.
    $fam    = trim((string) shell_exec('bash ' . escapeshellarg(FLASH_SCRIPTS . '/flash_hba.sh')
            . ' tool ' . escapeshellarg($chip) . ' 2>/dev/null | sed -n "s/^family=//p"'));
    $biosOk = ($fam === 'sas2' || $fam === 'sas3');

    // Claim single-flight BEFORE the gate, so the check and the claim can't be
    // interleaved by a second request. Any refusal below hands the lock back.
    $owned = flash_claim_lock($lock);

    $pf = flash_preflight([
        'enable'  => $enable,
        'stopped' => flash_array_stopped(),
        'ctl'     => $ctl,
        'fw'      => $fw,
        'bios'    => $bios,
        'bios_ok' => $biosOk,
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
    // $bios was validated by the preflight above; no second existence check.
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
