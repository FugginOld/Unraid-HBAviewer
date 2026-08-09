<?PHP
/* Runnable checks for flash.php guards: filename confinement, the array-stopped
   gate, and the preflight — the safety logic that stands between a web request
   and a card-bricking flash. Pure functions, no HTTP, no flashing.
     php tests/flash_php_test.php  ->  "flash_php: all pass" (exit 0) */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/flash.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

// ── flash_safe_name: confine uploads to a safe basename + allowed extension ──
check('safe name keeps good',   flash_safe_name('SAS9300_8i_IT.bin', ['bin']) === 'SAS9300_8i_IT.bin');
check('safe name strips path',  flash_safe_name('../../etc/x.bin', ['bin']) === 'x.bin');
check('safe name kills dotfile',flash_safe_name('.bashrc', ['bin','rom']) === null);
check('safe name bad ext',      flash_safe_name('payload.sh', ['bin','rom']) === null);
check('safe name empty',        flash_safe_name('', ['bin']) === null);
check('safe name cleans chars', flash_safe_name('fw v2;rm.bin', ['bin']) === 'fwv2rm.bin');
check('safe name traversal+badext', flash_safe_name('../../etc/passwd', ['bin']) === null);

// ── flash_array_stopped: only STOPPED passes; missing/other fails safe ───────
$ini = sys_get_temp_dir() . '/hbav_varini_' . getmypid() . '.ini';
file_put_contents($ini, "mdState=\"STOPPED\"\n");
check('array stopped -> true',  flash_array_stopped($ini) === true);
file_put_contents($ini, "mdState=\"STARTED\"\n");
check('array started -> false', flash_array_stopped($ini) === false);
@unlink($ini);
check('missing varini -> false', flash_array_stopped($ini) === false);

// ── flash_preflight: happy path + every hard block ───────────────────────────
// The drop dir is injected rather than hardcoded: it is on /boot in production
// and a test must not write there. flash_preflight takes 'dir' for this.
$dropDir = sys_get_temp_dir() . '/hbav_drop_' . getmypid();
@mkdir($dropDir, 0755, true);
@mkdir(FLASH_DIR, 0755, true);
$fw = $dropDir . '/unit.bin';
file_put_contents($fw, 'x');
$good = ['enable'=>1, 'stopped'=>true, 'ctl'=>'0', 'fw'=>$fw, 'confirm'=>'FLASH', 'locked'=>false, 'dir'=>$dropDir];
$err  = fn($ov) => flash_preflight(array_merge($good, $ov))['error'];

check('preflight ok',            flash_preflight($good)['ok'] === true);
check('block disabled',          str_contains($err(['enable'=>0]),  'disabled'));
check('block array running',     str_contains($err(['stopped'=>false]), 'STOPPED'));
check('block bad ctl',           str_contains($err(['ctl'=>'x']),   'controller'));
/* Firmware and BIOS are each optional, but not both: sasNflash takes -f, -b or
   both, so a BIOS-only flash is a real operation this used to refuse outright.
   'bios_ok' says whether the tool family supports it -- on storcli the BIOS is
   part of the firmware package, so there is no separate file to flash and an
   image stays mandatory. */
check('block neither fw nor bios', str_contains($err(['fw'=>'', 'bios'=>'']), 'firmware image, a BIOS image, or both'));
$biosOnly = ['fw'=>'', 'bios'=>$dropDir . '/unit.rom', 'bios_ok'=>true];
file_put_contents($dropDir . '/unit.rom', 'x');
check('BIOS-only passes on a sasNflash card', flash_preflight(array_merge($good, $biosOnly))['ok'] === true);
check('BIOS-only refused on a storcli card',
      str_contains($err(array_merge($biosOnly, ['bios_ok'=>false])), 'part of the firmware package'));
// Confinement applies to the BIOS path too, not just the firmware one.
check('block bios outside the drop dir',
      str_contains($err(['bios'=>'/tmp/evil.rom', 'bios_ok'=>true]), 'not permitted'));
check('block bios that does not exist',
      str_contains($err(['bios'=>$dropDir . '/nope.rom', 'bios_ok'=>true]), 'not found'));
check('block path escape',       str_contains($err(['fw'=>'/tmp/evil.bin']), 'not permitted'));
check('block fw outside the drop dir', str_contains($err(['fw'=>'/boot/x.bin']), 'not permitted'));
check('block no confirm',        str_contains($err(['confirm'=>'flash']), 'Type FLASH'));
check('block locked',            str_contains($err(['locked'=>true]), 'in progress'));
@unlink($fw); @unlink($dropDir . '/unit.rom'); @rmdir($dropDir);

// ── flash_claim_lock: exactly one claimant wins, and a release re-arms it ────
// This is the single-flight guarantee that stands between a double-submit and
// two flash tools writing to the same controller at once.
$lk = sys_get_temp_dir() . '/hbav_lock_' . getmypid() . '.lock';
@unlink($lk);
check('claim lock: first wins',      flash_claim_lock($lk) === true);
check('claim lock: second refused',  flash_claim_lock($lk) === false);
check('claim lock: third refused',   flash_claim_lock($lk) === false);
@unlink($lk);
check('claim lock: re-arms after release', flash_claim_lock($lk) === true);
@unlink($lk);

/* ── The firmware page has no upload, and must not grow one ──────────────────
   A multipart POST to any .php behind Unraid's nginx never completes.
   auth_request issues its subrequest to /auth-request.php carrying the original
   Content-Length but no body; PHP starts its rfc1867 parser and blocks on bytes
   that never arrive, and the request dies at fastcgi_read_timeout. Measured on
   a live box: the identical POST to the identical script returned HTTP 302 in
   12ms as urlencoded and never returned at all as multipart, at 1KB and at
   660KB alike, so it is the content type and not the size.

   The page therefore has no Browse button anywhere and the endpoint has no
   upload action. Re-adding either would look like a feature and behave like a
   ten-minute hang, so both are pinned shut. */
$jsSrc    = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/flash_view.js');
$flashSrc = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/flash.php');

check('the page sends no multipart body',   !str_contains($jsSrc, 'new FormData'));
check('the page has no file input',         !str_contains($jsSrc, 'type="file"'));
check('the endpoint has no upload action',  !str_contains($flashSrc, "action === 'upload'"));
check('and no move_uploaded_file',          !str_contains($flashSrc, 'move_uploaded_file'));
// What replaced it: the server lists the drop directory and the page offers
// only names it actually found there.
check('the endpoint lists the drop directory', str_contains($flashSrc, "action === 'dropfiles'"));
check('images are chosen from a select, not typed', str_contains($jsSrc, "<select id=\"flash-fw-"));
check('the flash reads images from the drop dir',   str_contains($flashSrc, "FLASH_DROP . '/' . \$fwName"));

/* ── Firmware verdict block wiring (Task 4, round-1 review) ──────────────────
   view_test.php/ajax_render_test.php test the PHP side of this feature in
   full; flash_view.js has no runtime test harness (node --check only proves
   the syntax parses), so the wiring and its two safety properties are pinned
   as source assertions — the same idiom this file already uses above for
   "no FormData", "no file input", etc. */

// I3: a mutant that deletes the call to fwVerdictBlock() left every other
// check in this suite green — the helper is tested, nothing proved it's used.
check('the card wires the firmware verdict block', str_contains($jsSrc, 'fwVerdictBlock(c.firmware_verdict)'));

// Minor: 'reason' is the field that quotes the untrusted board string
// (firmware_index.php's own docblock says verdict fields are NOT pre-escaped).
// Changing fesc(v.reason) to a bare v.reason is a mutant no PHP test can see.
check('the reason field is escaped before it is printed', str_contains($jsSrc, 'fesc(v.reason)'));

// I2: amber-on-terminal and green-on-current are fw_verdict_color()'s rule,
// computed once in ajax_info.php and sent as v.color. A second literal copy of
// either hex here would drift from the PHP rule invisibly — pinned as an
// absence, so the fix (and any future regression back to a local copy) is
// caught by grep, not by re-reading the diff.
check('the JS does not carry its own copy of the amber/green verdict colours',
    !str_contains($jsSrc, "'#d29922'") && !str_contains($jsSrc, "'#3fb950'"));
// ...and neither does view.php, which DID until this was extended: it hardcoded
// the green while delegating the amber, so "one rule, one home" was two homes and
// the server-rendered Overview would have kept a green the rule had withdrawn.
$viewSrc = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/view.php');
check('view.php does not carry its own copy of the verdict colours either',
    !str_contains($viewSrc, '#d29922') && !str_contains($viewSrc, '#3fb950'));
check('the JS reads the verdict colour instead of recomputing it', str_contains($jsSrc, 'v.color'));

/* flash_hba.sh's "not installed" message tells the user where to put the tool.
   It used to name tools/ and "upload it under Step 1" — a directory that is now
   only the legacy fallback and an upload control that no longer exists anywhere,
   and this pair-check pinned the stale STEP NUMBER, so it stayed green while the
   sentence it guarded was wrong on the one page that writes to hardware.
   Pin what actually has to agree: the directory the shell tells the user to copy
   to must be the directory PHP reads images from, and the message must not send
   them to an upload or to the legacy dir. */
$shSrc = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/scripts/flash_hba.sh');
$shMsg = preg_match('/^\s*sas2\|sas3\)\s*die "(.*)" 4 ;;/m', $shSrc, $sm) ? $sm[1] : '';
check('the shell has a missing-tool message',      $shMsg !== '');
check('it names the same drop dir PHP reads from', str_contains($shMsg, FLASH_DROP . '/'));
check('it does not name the legacy tools dir',     !str_contains($shMsg, '/hbaviewer/tools/'));
check('it does not send the user to an upload',    stripos($shMsg, 'upload') === false);

echo $fails === 0 ? "flash_php: all pass\n" : "flash_php: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
