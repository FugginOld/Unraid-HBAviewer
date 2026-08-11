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

/* The firmware and BIOS selects are populated from one listing, so the two
   flash_safe_name allowlists must agree or a file the page offered is silently
   dropped on arrival -- a .fw picked as BIOS used to vanish and the flash ran
   without it. The comment in flash.php says they must match; nothing failed if
   someone narrowed one back. Same defect class as a rule with two homes. */
check('firmware and BIOS accept the same extensions',
      substr_count($flashSrc, "['bin', 'rom', 'fw']") >= 3);
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

/* ── The maintainer lock ──────────────────────────────────────────────────────
   Flashing is disabled for everyone in this release, over and above the user's
   own ENABLE_FLASH toggle, until the path has been tested on more hardware.

   These assertions are deliberately annoying: unlocking has to be a decision
   somebody makes and edits a test for, not something that happens because a
   line drifted during a merge.

   The lock is now computed from an unlock FILE rather than written as a
   literal, so the assertion is on the source and not on the runtime value.
   LSI_FLASH_LOCKED === true would pass or fail depending on whether the
   machine running the suite happens to have that file -- green in CI, red on
   the maintainer's own unlocked box, which is a test reporting where it ran
   rather than what the code says. What has to hold is the POLARITY: absent
   file means locked. Dropping the '!' is the mutant that matters, and it
   fails this.

   The source-order check is crude but it is the only property that matters and
   it cannot be tested any other way without HTTP: the 403 must come BEFORE any
   line that can reach flash_hba.sh. A lock placed after the dispatch is not a
   lock. Same technique bundle_coverage_test.sh already uses.

   $flashSrc is already loaded above, by the no-upload block. */
$cfgSrc = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/config.php');
check('the lock defaults to on, and unlocking needs a file that ships with nothing',
      str_contains($cfgSrc, "define('LSI_FLASH_LOCKED', !file_exists(LSI_FLASH_UNLOCK));"));
check('the unlock file lives on the flash drive, so it survives a reboot',
      str_contains($cfgSrc, "const LSI_FLASH_UNLOCK = '/boot/config/plugins/hbaviewer/.flash-unlock';"));
check('the lock explains itself to the user',    trim(LSI_FLASH_LOCK_NOTE) !== '');

// Match the invocation, not the string: the file's header comment names
// flash_hba.sh several lines above the lock, and an earlier draft of this
// assertion matched that and failed. FLASH_SCRIPTS . '/flash_hba.sh' appears
// only where the script is actually run.
$lockPos  = strpos($flashSrc, 'if (LSI_FLASH_LOCKED)');
$firstRun = strpos($flashSrc, "FLASH_SCRIPTS . '/flash_hba.sh'");
check('flash.php refuses on the lock',           $lockPos !== false);
check('the flasher is invoked at all',           $firstRun !== false);
check('the refusal precedes every flash_hba.sh invocation',
      $lockPos !== false && $firstRun !== false && $lockPos < $firstRun);

// The UI half. Not the real gate -- flash.php is -- but a page offering buttons
// the endpoint would refuse is its own kind of bug, so both surfaces are pinned.
// NOT $viewSrc: that name is taken above by view.php, the Overview renderer.
// Two different files, and reusing the name would have left whichever check
// was written second reading the wrong source.
$settingsSrc   = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/settings.php');
$flashViewSrc  = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/flash_view.php');
check('settings disables the toggle while locked',
      str_contains($settingsSrc, "LSI_FLASH_LOCKED ? 'disabled' : ''"));
check('settings hides the way in while locked',
      str_contains($settingsSrc, '!LSI_FLASH_LOCKED && (int)$cfg[\'ENABLE_FLASH\'] === 1'));
check('settings holds the saved value while locked',
      str_contains($settingsSrc, "LSI_FLASH_LOCKED\n            ? (int) (lsi_config_read()['ENABLE_FLASH'] ?? 0)"));
check('the firmware page leads with the lock',
      str_contains($flashViewSrc, '<?php if (LSI_FLASH_LOCKED): ?>'));

/* ── One way in ───────────────────────────────────────────────────────────────
   settings.php's comment has always said its button is "the way in to firmware
   flashing, and the only one" -- deliberately not on the Monitor, so reaching
   the flasher means passing the page where you turned it on and read the danger
   notice. It was not the only one. The page also declared Menu="Utilities",
   which put a second tile beside HBAviewer's own in Settings -> Utilities: a
   door straight to the flasher that skipped the notice entirely, and one that
   stayed visible on a locked install for anyone who had ever ticked the box.

   Hanging the page off HBAviewer_Settings instead keeps the same /Settings/
   URL -- the root is inherited from the parent, which is itself Utilities --
   so the hardcoded href below still resolves. That pairing is the fragile
   part, and it is why the href is pinned here next to the Menu it depends on:
   they are one fact stored in two files, and nothing else would notice them
   drifting apart until a user clicked the button and got a 404. */
$flashPage = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/HBAviewer_Flash.page');
check('the firmware page is not a second Utilities tile',
      !str_contains($flashPage, 'Menu="Utilities"'));
check('it hangs off the settings page instead',
      str_contains($flashPage, 'Menu="HBAviewer_Settings"'));
check('the button in settings points at the URL that placement produces',
      str_contains($settingsSrc, 'href="/Settings/HBAviewer_Flash"'));

echo $fails === 0 ? "flash_php: all pass\n" : "flash_php: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
