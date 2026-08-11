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
// lsi_controllers(), and which of them are one physical CARD. The flash action
// re-derives the grouping server-side so a posted controller list can be
// checked against the cards that actually exist — see flash_ctl_is_card().
require_once __DIR__ . '/view.php';
require_once __DIR__ . '/card_group.php';

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

/* Confine a client-supplied filename to a safe basename with an allowed
   extension. Strips any path, whitelists the charset, rejects empties/dotfiles.
   Returns the safe basename or null.
   The page only ever offers names the server itself found in the drop
   directory, so this is defence in depth rather than the primary check — the
   value still arrives in a POST field and is still nobody's to trust. */
function flash_safe_name(string $name, array $allowedExt): ?string {
    $base = basename(str_replace('\\', '/', $name));       // kill any path component
    $base = preg_replace('/[^A-Za-z0-9._-]/', '', $base);   // whitelist charset
    if ($base === '' || $base[0] === '.') return null;       // no empty, no dotfiles
    $ext = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
    return in_array($ext, $allowedExt, true) ? $base : null;
}

/* Validate the controller argument and return its parts, or null.
 *
 * A dual-IOC board is one card and arrives as a list of its controller numbers
 * ("0,1"), so this is not a single integer any more. Three properties, all of
 * them because the value ends up as the -c argument of a tool that writes
 * firmware, and both call sites need exactly the same answer:
 *
 *   shape  — digits and commas only, no empty element. Anchored with \z rather
 *            than $, or a trailing newline slips through.
 *   size   — at most LSI_MAX_IOCS entries. Shape alone accepts 0,1,2,3,4,5,6,7,
 *            which writes one image to eight controllers: exactly the -fwall
 *            blast radius the loop in flash_hba.sh exists to avoid.
 *   unique — no controller written twice in one run.
 *
 * This is a bound, not an identity. Only flash_ctl_is_card() below can say the
 * list is THIS CARD's, and the mutating action uses both. */
const LSI_MAX_IOCS = 4;   // largest ioc_count in the index is 2; 4 is slack

function flash_ctl_list(string $ctl): ?array {
    if (!preg_match('/^\d+(,\d+)*\z/', $ctl)) return null;
    $parts = explode(',', $ctl);
    if (count($parts) > LSI_MAX_IOCS)            return null;
    if (count($parts) !== count(array_unique($parts))) return null;
    return $parts;
}

/* Is $ctl exactly one of this box's cards?
 *
 * The whole justification for looping the card's indices instead of -fwall is
 * "the card's OWN controllers, nothing else", and nothing but this enforces it:
 * a crafted POST or a stale page can otherwise name any set of controllers that
 * passes the shape check. $cards comes from flash_cards_from(), over the same
 * lsi_group_cards() the Overview and the firmware page's JSON use, so the set of
 * acceptable lists is by construction the set of cards the page can offer.
 *
 * $cards is a map "0,1" => chip, keyed by the card's whole controller list, so
 * the lookup is exact string equality and not a subset test: half a dual-IOC
 * card is not a card, and writing one of its two IOCs is the partial flash this
 * feature spends its error handling trying to prevent.
 * No cards at all (backend error, no controllers) means nothing matches and
 * every flash is refused — the same read is what draws the card the operator
 * pressed Flash on, so there is nothing legitimate to lose.
 *
 * The CHIP is checked here too, against the same live read, because it decides
 * which flash tool runs. Re-deriving the controller list from hardware and then
 * taking the client's word for the chip left half the tuple trusted: the vector
 * this whole check exists for is a stale page, and a stale page carries a stale
 * data-chip exactly as readily as a stale data-ctl. `ctl=0,1&chip=SAS2008`
 * against a box whose card 0,1 is a SAS3008 otherwise passed membership and
 * reached flash_hba.sh, which resolves sas2flash and runs it. */
function flash_ctl_is_card(string $ctl, string $chip, array $cards): bool {
    $want = $cards[$ctl] ?? '';
    return $want !== '' && $want === $chip;
}

/* Every card on this box: "0,1" => the chip that card actually reports.
 *
 * Read at flash time rather than trusted from the client. Same pipeline and
 * same grouper as ajax_info.php's overview JSON, so the acceptable lists are by
 * construction the cards the page can offer. [] on any backend failure, which
 * flash_ctl_is_card() turns into a refusal.
 *
 * The chip is passed through the SAME alnum filter the dispatch applies to the
 * client's value. Comparing a filtered string against an unfiltered one would
 * refuse every flash on any backend whose model string carries a space or a
 * dash — a gate that fails closed on working hardware is still a broken gate. */
function flash_card_chips(): array {
    return flash_cards_from((array) json_decode(
        (string) shell_exec('bash ' . escapeshellarg(FLASH_SCRIPTS . '/get_hba_info.sh') . ' 2>/dev/null'),
        true));
}

/* The pure half of flash_card_chips(): one backend payload in, the card map out.
 * Split off so the gate that decides what may be written is unit-testable
 * against the real pipeline goldens instead of a copy of its body in a test.
 *
 * Fails closed on a DEGRADED read. A per-controller parser error (storcli's
 * overview emits {"error":…} when a controller's temperature is unreadable)
 * carries no card_id, so it never buckets with its sibling and the dual board
 * comes back as two groups of one instead of one group of two. Left alone, the
 * surviving half is a perfectly valid single-controller "card" and flashing it
 * writes one IOC of a two-IOC board — reported as success, with an instruction
 * to reboot: exactly the mismatch this feature exists to prevent. So a group
 * smaller than the ioc_count its board declares is dropped, and a 9300-16i with
 * one unreadable IOC is unflashable until the read is clean. Boards that declare
 * no count default to 1 and are unaffected. */
function flash_cards_from(array $data): array {
    if (isset($data['error'])) return [];
    $ctls   = lsi_controllers($data);
    $counts = lsi_ioc_counts(fw_load());
    $out    = [];
    foreach (lsi_group_cards($ctls, $counts) as $g) {
        $first = $ctls[$g[0]] ?? [];
        $board = (string) ($first['board_name'] ?? '');
        /* RAID-on-Chip, refused by BOARD as well as by chip.
         *
         * flash_hba.sh refuses five chips that only ever ship as MegaRAID. That
         * net cannot catch the entry-level cards which share silicon with an
         * indexed HBA: a MegaRAID 9440-8i is a SAS3408, the same chip as the
         * HBA 9400-8i, so it matches SAS34* and is handed storcli. Same for the
         * 9341-8i on SAS3008 against the 9300-8i. No IT firmware exists for
         * either, and the verdict path already declines them (they are not
         * indexed) — it is only the flash path that would offer a tool.
         *
         * Keyed on the reported board name rather than a PCI subdevice because
         * the subdevice IDs are not to hand, and the name is what the backend
         * already gives us. It costs nothing and closes the whole family
         * instead of the two models we happen to know about. No indexed board
         * carries this string. */
        if (stripos($board, 'megaraid') !== false) continue;
        /* OEM rebrands, the same gate fw_evaluate() applies to a verdict.
         *
         * A Dell H310 or IBM M1015 carries a different SubVendor ID and ships
         * different NVDATA and BIOS, so writing a generic Broadcom image to one
         * is a CROSSFLASH, not an upgrade — a materially riskier operation than
         * the page describes. It also covers the MegaRAID the name match above
         * cannot: an OEM-rebranded RAID card need not carry "MegaRAID" in its
         * product string, but it will carry a foreign subvendor.
         *
         * PRESENT-AND-WRONG refuses; ABSENT allows. That asymmetry is
         * deliberate and is where this differs from the verdict path, which
         * suppresses on an unreadable value. Suppressing a verdict costs a
         * badge; refusing a flash costs the operator their card, and a backend
         * that simply does not publish subsystem_vendor would make every card
         * on that machine unflashable. A gate that fails closed on working
         * hardware is still a broken gate.
         *
         * Deliberate crossflashing is a real thing people do with these cards.
         * It stays possible from a console; what it stops being is a button. */
        $subven = strtolower(trim((string) ($first['subvendor_id'] ?? '')));
        if ($subven !== '' && $subven !== '0x1000') continue;
        if (count($g) < ($counts[fw_normalize($board)] ?? 1)) continue;
        $out[implode(',', $g)] = (string) preg_replace('/[^A-Za-z0-9]/', '',
            (string) ($first['model'] ?? ''));
    }
    return $out;
}

/* Pure preflight gate for a flash request. Returns [ok=>bool, error=>string].
   The handler injects real values; tests inject fakes. Order = user-friendliest
   failure first, but every check is a hard block. */
function flash_preflight(array $in): array {
    if ((int) ($in['enable'] ?? 0) !== 1)
        return ['ok' => false, 'error' => 'Firmware flashing is disabled. Enable it in Settings first.'];
    if (empty($in['stopped']))
        return ['ok' => false, 'error' => 'The array must be STOPPED before flashing. Stop it on the Main tab, then retry.'];
    /* Shape, size and uniqueness — one spelling, shared with the 'listall'
       action below (see flash_ctl_list). */
    if (flash_ctl_list((string) ($in['ctl'] ?? '')) === null)
        return ['ok' => false, 'error' => 'Invalid controller index.'];
    /* 'card' is the membership-and-chip answer. It needs the live hardware, so
       it is injected rather than read here — but it FAILS CLOSED on absence,
       like every other gate in this function. It used to be
       `array_key_exists('card', $in) && !$in['card']`, which made the most
       dangerous gate in the plugin the only one that defaulted to allow: delete
       the 'card' => … line from the dispatch and nothing behavioural noticed,
       only a str_contains() on that literal line. */
    if (empty($in['card']))
        return ['ok' => false, 'error' => 'That is not one of the controller cards in this server, or its chip has changed since the page loaded. Reload the firmware page and try again.'];
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

// The maintainer lock outranks the user's toggle and is checked first, so a
// stale page, a bookmarked POST or a hand-rolled curl all hit the same wall.
// This is the gate that actually disables flashing; everything else is signage.
if (LSI_FLASH_LOCKED) { http_response_code(403); echo LSI_FLASH_LOCK_NOTE; exit; }
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
    // Cast before the filter: chip[]=x makes preg_replace return an ARRAY, and
    // escapeshellarg() then throws a TypeError -> a 500 instead of a refusal.
    $chip = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['chip'] ?? $_GET['chip'] ?? ''));
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
    $chip = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['chip'] ?? $_GET['chip'] ?? ''));
    $ctl  = (string) ($_POST['ctl'] ?? $_GET['ctl'] ?? '');
    /* Same validator as the flash action's, so the two cannot drift. NOT the
       membership check: this is read-only (`-list` per controller, nothing
       written) and re-deriving the grouping needs a full hardware read, which
       would put a minute between pressing Verify and seeing output on the very
       button that exists to be quick. The size and uniqueness bounds inside
       flash_ctl_list() are what keep the fan-out finite here. */
    if ($chip === '' || flash_ctl_list($ctl) === null) { echo 'Invalid controller.'; exit; }
    echo (string) shell_exec('bash ' . escapeshellarg(FLASH_SCRIPTS . '/flash_hba.sh')
        . ' list ' . escapeshellarg($chip) . ' ' . escapeshellarg($ctl) . ' 2>&1');
    exit;
}

if ($action === 'flash') {
    header('Content-Type: application/json');
    $chip   = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['chip'] ?? ''));
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

    /* Is this list one of THIS box's cards, running the chip the page claims?
     *
     * ABOVE the lock, deliberately. flash_card_chips() reads the hardware and
     * can take up to a minute on a slow controller, and flash_claim_lock() has
     * no TTL recovery the way cached_read() does — so a PHP death anywhere in
     * that window (fpm timeout, fatal, worker recycle) would orphan the lock and
     * leave every later flash refused "already in progress", with ?action=status
     * reporting a run that does not exist, until /tmp clears at reboot. On a box
     * taken offline specifically to flash, that is a bad place to get stuck.
     * Nothing about the ordering matters: this is a pure read.
     *
     * Short-circuited on the cheap shape check so a malformed list — or a
     * request that was never going to be accepted — does not pay for the read.
     * The remaining gates (array running, confirm string, lock) can still spend
     * it; they are all page-state failures a reload fixes, and splitting the
     * preflight to avoid that would put the ordering of the gates at the mercy
     * of how expensive each one is. */
    $isCard = flash_ctl_list($ctl) !== null && flash_ctl_is_card($ctl, $chip, flash_card_chips());

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
        // Computed above the lock; see the comment there. Absent means refused.
        'card'    => $isCard,
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
    /* 7 is flash_hba.sh's partial flash: one IOC of a dual-controller board
       written, the other not. It gets its own state rather than folding into
       'error' because the two demand opposite things of the operator — 'error'
       means nothing was written and the card is safe, 'partial' means the card
       is running two firmware versions and must not be rebooted. Sharing one
       code left those machine-indistinguishable, with only the log text between
       a safe retry and a dead card. */
    if (!$running && $exit === 0)          $res['done'] = 'success';
    elseif (!$running && $exit === 7)      $res['done'] = 'partial';
    elseif (!$running && $exit !== null)   $res['done'] = 'error';
    echo json_encode($res);
    exit;
}

http_response_code(400);
echo 'Unknown action.';
