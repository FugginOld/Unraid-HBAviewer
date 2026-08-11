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
$good = ['enable'=>1, 'stopped'=>true, 'ctl'=>'0', 'card'=>true, 'fw'=>$fw, 'confirm'=>'FLASH', 'locked'=>false, 'dir'=>$dropDir];
$err  = fn($ov) => flash_preflight(array_merge($good, $ov))['error'];

check('preflight ok',            flash_preflight($good)['ok'] === true);
check('block disabled',          str_contains($err(['enable'=>0]),  'disabled'));
check('block array running',     str_contains($err(['stopped'=>false]), 'STOPPED'));
check('block bad ctl',           str_contains($err(['ctl'=>'x']),   'controller'));
/* A dual-IOC board is one card and arrives as a list of its controllers -- the
   preflight has to let that through, and only that. The pattern itself is
   exercised exhaustively further down; these two prove the gate uses it. */
check('accept a dual-IOC card\'s controller list',
      flash_preflight(array_merge($good, ['ctl'=>'0,1']))['ok'] === true);
check('block a malformed controller list', str_contains($err(['ctl'=>'0,,1']), 'controller'));
check('block a list longer than any real card', str_contains($err(['ctl'=>'0,1,2,3,4,5,6,7']), 'controller'));
/* 'card' is the membership-and-chip answer, injected because it needs the live
   hardware (flash_ctl_is_card / flash_card_chips, exercised further down).
   It FAILS CLOSED on absence, like every other gate here. It used to be
   `array_key_exists('card', $in) && !$in['card']`, which made the most
   dangerous gate in the plugin the only one that defaulted to allow: deleting
   the 'card' => … line from the dispatch then broke nothing behavioural, only a
   str_contains() on that literal line — which also went red for a cosmetic
   reflow of its whitespace. This third case is the one that cannot be fooled by
   either. */
check('block a list that is not one of this box\'s cards',
      str_contains($err(['card'=>false]), 'not one of the controller cards'));
$unchecked = $good; unset($unchecked['card']);
check('block when nobody checked at all',
      str_contains(flash_preflight($unchecked)['error'], 'not one of the controller cards'));
check('accept one that is', flash_preflight($good)['ok'] === true);
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

/* ── A dual-IOC board is ONE card, flashed as one ────────────────────────────
   -fwall means every controller in the SYSTEM, not every controller on this
   card. On a box with a 9300-16i and a 9300-8i it would write the 16i image to
   the 8i. The loop in flash_hba.sh replaces it and must stay replaced; -listall
   is barred from the verify path for the same reason.

   Matched against the CODE, with comments stripped. Asserting over the whole
   file made flash_hba.sh the one file forbidden from naming the flags it must
   never use, which is a poor trade on a script whose comments are most of its
   safety argument. $shSrc is loaded above, by the missing-tool-message block. */
$shCode = (string) preg_replace('/^\s*#.*$/m', '', $shSrc);
check('the flasher never uses -fwall',        !str_contains($shCode, 'fwall'));
check('verification never uses -listall',     !str_contains($shCode, '-listall'));
// ...and the prose is still expected to explain why, or the next person deletes
// the loop as pointless complexity.
check('the comments still say why not',       str_contains($shSrc, '-fwall') && str_contains($shSrc, '-listall'));

/* The partial flash is the one genuinely new failure the loop introduces: a
   board left with its two IOCs on different firmware. It must never be reported
   as a generic failure -- rebooting on a half-flashed board is what turns a
   failed update into a dead card. */
check('a partial flash says so explicitly',   str_contains($shSrc, 'PARTIAL FLASH'));
check('and tells the operator not to reboot', str_contains($shSrc, 'Do NOT reboot'));
/* Its OWN exit code. Sharing 6 with "nothing was written" made the dangerous
   state and the safe one record the same value in flash.status, leaving only
   free text between a safe retry and a dead card. 7 is the partial; flash.php
   turns it into done=partial and the page into its own banner. */
// Matched on the PARTIAL FLASH die itself rather than on its closing words, so
// rewording the recovery advice cannot silently unpin the exit code.
check('a partial flash has its own exit code',
      (bool) preg_match('/die "PARTIAL FLASH\..*" 7$/m', $shSrc));
/* The recovery it names must be one the server will actually accept. It used to
   say "re-run the flash for /c$one" -- and flash_ctl_is_card() refuses a list
   that is not a whole card, so the single instruction given in the one state
   this feature exists to make loud was rejected by the gate. Re-running the
   whole card rewrites both controllers, which is both accepted and safe. */
check('and directs the operator at the WHOLE CARD, which the gate accepts',
      str_contains($shSrc, 'WHOLE CARD'));
check('and never at the failed controller alone',
      !str_contains($shSrc, 'Re-run the flash for /c'));
check('a clean failure keeps the old one',     str_contains($shSrc, 'nothing was written" 6'));
check('flash.php maps 7 to its own state',     str_contains($flashSrc, "\$exit === 7)      \$res['done'] = 'partial'"));
check('and the page renders that state',       str_contains($jsSrc, "d.done === 'partial'"));
/* flash_rc is assigned only on FAILURE and read to decide whether the write
   failed, so an uninitialised one inherited from the environment made the first
   iteration report a SUCCESSFUL write as "nothing was written" -- the most
   dangerous lie this loop can tell, since the operator then re-runs from a
   state the script has misdescribed. flash_test.sh proves the behaviour; this
   pins the line, because the behaviour is only observable with a poisoned env. */
check('the failure flag is reset every iteration', str_contains($shCode, 'flash_rc=""'));

/* ── The controller argument reaches a script that writes firmware ───────────
   ONE validator, called from both sites, so they cannot drift -- the pattern is
   spelled once and a grep for it finds the single home rather than two copies
   that have to be compared by eye. */
check('the controller list is validated as digits and commas only',
      str_contains($flashSrc, "preg_match('/^\\d+(,\\d+)*\\z/'"));
check('the pattern has exactly one home',
      substr_count($flashSrc, "'/^\\d+(,\\d+)*\\z/'") === 1);
check('the preflight goes through it',  str_contains($flashSrc, "flash_ctl_list((string) (\$in['ctl'] ?? '')) === null"));
check('and so does the listall action', str_contains($flashSrc, "flash_ctl_list(\$ctl) === null"));

check('a controller list passes',  flash_ctl_list('0,1') === ['0', '1']);
check('a bare index still passes', flash_ctl_list('3')   === ['3']);
// \z, not $: '0,1\n' matches /^\d+(,\d+)*$/ and must not reach the flasher.
check('a trailing newline is refused', flash_ctl_list("0,1\n") === null);
foreach ([',', '0,', ',1', '0,,1', '0;1', '0 1', '-1', '', 'x', '0,x'] as $mal) {
    check("the controller check refuses '" . $mal . "'", flash_ctl_list($mal) === null);
}
/* Shape is not size. '0,1,2,3,4,5,6,7' is a perfectly well-formed list and
   writes one image to eight controllers -- precisely the -fwall blast radius
   the loop exists to avoid, reachable from a crafted POST or a stale page.
   The largest ioc_count in the index is 2, so the cap closes the fan-out
   without ever refusing a real card. */
check('a list longer than any real card is refused', flash_ctl_list('0,1,2,3,4,5,6,7') === null);
check('and one just over the cap',                   flash_ctl_list('0,1,2,3,4') === null);
check('a list at the cap is allowed',                flash_ctl_list('0,1,2,3') !== null);
check('a controller repeated in one run is refused', flash_ctl_list('1,1') === null);
check('the cap is named, not a magic number',        LSI_MAX_IOCS === 4);

/* ── Shape is not membership either ──────────────────────────────────────────
   The entire justification for looping instead of -fwall is "the card's OWN
   controllers, nothing else", and only this enforces it. The groups are
   re-derived server-side from the live hardware at flash time -- the client is
   the one party that cannot be asked -- and the posted list must BE one of
   them. Exact equality, not a subset: half a dual-IOC card is not a card, and
   writing one of its two IOCs is the partial flash all the error handling
   downstream exists to prevent.
   Driven with the real pipeline golden through the real index, the same way
   card_group_test.php does, rather than hand-built arrays. */
$realIdx  = fw_load(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json');
$dualRaw  = json_decode((string) file_get_contents(__DIR__ . '/expected/storcli_dual.json'), true);
$dualCtls = $dualRaw['controllers'];
$dualGrp  = lsi_group_cards($dualCtls, lsi_ioc_counts($realIdx));
check('the dual-IOC golden really is one card of two', $dualGrp === [[0, 1]]);
/* The map the gate is fed: list => the chip that card actually reports. Built
   by the PRODUCER, flash_cards_from(), not by a copy of its body here. The copy
   this replaces had already drifted -- it omitted the alnum preg_replace -- so
   every assertion below was exercising the matcher against a map no production
   code path can produce. Four mutations of flash_card_chips() survived the
   whole suite because of it; see the flash_cards_from block further down. */
$dual = flash_cards_from($dualRaw);
check('the golden card is one map entry keyed by its whole list', array_keys($dual) === ['0,1']);
check('the golden card reports SAS3008', ($dual['0,1'] ?? '') === 'SAS3008');

check('that card\'s own list is accepted',     flash_ctl_is_card('0,1', 'SAS3008', $dual) === true);
check('half of it is refused',                 flash_ctl_is_card('0',   'SAS3008', $dual) === false);
check('the other half is refused',             flash_ctl_is_card('1',   'SAS3008', $dual) === false);
check('a superset is refused',                 flash_ctl_is_card('0,1,2', 'SAS3008', $dual) === false);
check('a reordered list is refused',           flash_ctl_is_card('1,0', 'SAS3008', $dual) === false);
check('a controller that is not there is refused', flash_ctl_is_card('9', 'SAS3008', $dual) === false);
// A leading zero is a different string and must not normalise into a match.
check('a leading-zero variant is refused',     flash_ctl_is_card('00,1', 'SAS3008', $dual) === false);
check('whitespace is refused',                 flash_ctl_is_card('0, 1', 'SAS3008', $dual) === false);
check('a trailing newline is refused',         flash_ctl_is_card("0,1\n", 'SAS3008', $dual) === false);

/* ── The chip is half the tuple, and it was still the client's word ──────────
   The chip decides which flash tool runs. Re-deriving the controller list from
   hardware and then trusting the posted chip left the stale-page vector — the
   one this check exists for — half open: a stale page carries a stale data-chip
   as readily as a stale data-ctl. ctl=0,1&chip=SAS2008 against a box whose card
   0,1 is a SAS3008 passed membership and reached flash_hba.sh, which resolves
   sas2flash and runs it on a SAS3 card. */
check('the right list with the WRONG chip is refused',
      flash_ctl_is_card('0,1', 'SAS2008', $dual) === false);
check('an empty chip is refused',       flash_ctl_is_card('0,1', '', $dual) === false);
check('a card reporting no chip is refused', flash_ctl_is_card('0,1', 'SAS3008', ['0,1' => '']) === false);
check('the chip match is exact, not a prefix', flash_ctl_is_card('0,1', 'SAS300', $dual) === false);

// The non-adjacent case, which is the shape an index-derived check would fail:
// [16i@X, 8i@Y, 16i@X] groups as [[0,2],[1]].
$mixedCtls = [
    ['board_name' => 'SAS9300-16i', 'card_id' => '0000:80:01.0', 'model' => 'SAS3008'],
    ['board_name' => 'SAS9300-8i',  'card_id' => '0000:00:11.0', 'model' => 'SAS2008'],
    ['board_name' => 'SAS9300-16i', 'card_id' => '0000:80:01.0', 'model' => 'SAS3008'],
];
$mixed = flash_cards_from(['controllers' => $mixedCtls]);
check('a non-adjacent card is accepted by its own list', flash_ctl_is_card('0,2', 'SAS3008', $mixed) === true);
check('the lone card between its halves too',            flash_ctl_is_card('1',   'SAS2008', $mixed) === true);
check('but not the adjacent pair that is two cards',     flash_ctl_is_card('0,1', 'SAS3008', $mixed) === false);
// ...and the neighbouring card's chip does not unlock this one.
check('one card\'s chip does not authorise another',     flash_ctl_is_card('1', 'SAS3008', $mixed) === false);
// No cards at all -- a backend error, or no controllers -- refuses everything.
// The same read draws the card the operator pressed Flash on, so there is
// nothing legitimate to lose by failing closed here.
check('an unreadable backend refuses every flash', flash_ctl_is_card('0', 'SAS3008', []) === false);

/* ── flash_cards_from(): the producer of everything above ────────────────────
   The gate is only as good as the map it is handed, and the map was built by a
   function with no test of its own: four separate mutations of it survived the
   entire suite. Each check below is the one that kills one of them.

   MUTANT A -- key the map by $g[0] instead of implode(',', $g). Refuses every
   dual flash AND accepts half of one, since the key becomes a bare index. */
check('A: the dual card is keyed by its WHOLE list, not its first index',
      array_keys(flash_cards_from($dualRaw)) === ['0,1']);
check('A: and the whole list is what the gate then accepts',
      flash_ctl_is_card('0,1', 'SAS3008', flash_cards_from($dualRaw)) === true);

/* MUTANT B -- drop the alnum filter on the model. The dispatch filters the
   CLIENT's chip, so an unfiltered map value can never equal it and every flash
   on a backend whose model carries a space or a dash is refused. A gate that
   fails closed on working hardware is still a broken gate. */
$spacedRaw = $dualRaw;
$spacedRaw['controllers'][0]['model'] = 'SAS 3008 (rev 02)';
$spacedRaw['controllers'][1]['model'] = 'SAS 3008 (rev 02)';
check('B: the model is put through the same alnum filter as the posted chip',
      (flash_cards_from($spacedRaw)['0,1'] ?? '') === 'SAS3008rev02');

/* MUTANT C -- drop the isset($data['error']) guard. get_hba_info.sh reports a
   whole-backend failure as a top-level error while still carrying whatever it
   scraped; trusting that payload means flashing off a read that failed. */
check('C: a top-level backend error yields no cards at all',
      flash_cards_from(['error' => 'storcli not found', 'controllers' => $dualCtls]) === []);

/* MUTANT D -- take end($g)'s model rather than $g[0]'s. Invisible on the golden,
   where both IOCs report the same chip; the chip picks the FLASH TOOL, so on any
   card whose members disagree it hands the gate the wrong half's answer. */
$skewRaw = $dualRaw;
$skewRaw['controllers'][1]['model'] = 'SAS2008';
check('D: the chip comes from the group\'s FIRST member',
      (flash_cards_from($skewRaw)['0,1'] ?? '') === 'SAS3008');

/* BLOCKER 1 -- a DEGRADED read must not present half a dual board as a card.
   storcli_overview.sh emits a per-controller {"error":...} when ROC temperature
   is missing. That entry has no card_id, so it never buckets with its sibling
   and the 9300-16i comes back as [[0],[1]] instead of [[0,1]]. The surviving
   half is then a perfectly well-formed single-controller "card", and flashing it
   writes ONE IOC of a two-IOC board -- exit 0, "Flash completed, REBOOT the
   server". Any group smaller than the ioc_count its board declares is dropped,
   so the card is unflashable until the read is clean. */
$degradedRaw = $dualRaw;
$degradedRaw['controllers'][1] = ['error' => 'No temperature in storcli output. Check the controller index.'];
$degraded = flash_cards_from($degradedRaw);
check('the degraded read no longer offers the surviving half as a card',
      !isset($degraded['0']) && !isset($degraded[0]));
check('and half of the dual card is REFUSED after a one-IOC read failure',
      flash_ctl_is_card('0', 'SAS3008', $degraded) === false);
check('the whole card is refused too -- it was never grouped',
      flash_ctl_is_card('0,1', 'SAS3008', $degraded) === false);
/* Fail-closed only for boards that DECLARE a count. A single-IOC board has no
   ioc_count, defaults to 1, and must be entirely unaffected by any of this. */
check('a single-IOC board is untouched by the fail-closed rule',
      flash_cards_from(['controllers' => [
          ['board_name' => 'SAS9300-8i', 'card_id' => '0000:00:11.0', 'model' => 'SAS3008'],
      ]]) === ['0' => 'SAS3008']);
/* ...and so is a board this plugin has never heard of. */
check('an unknown board still groups as one card of one',
      flash_cards_from(['controllers' => [
          ['board_name' => 'Some Future HBA', 'card_id' => '0000:00:12.0', 'model' => 'SAS4116'],
      ]]) === ['0' => 'SAS4116']);
check('no controllers at all yields no cards', flash_cards_from(['controllers' => []]) === []);

check('the flash action asks the live hardware',
      str_contains($flashSrc, 'flash_ctl_is_card($ctl, $chip, flash_card_chips())'));
/* ...and the answer actually reaches the gate. Deleting the 'card' entry from
   the preflight array is now fail-CLOSED rather than fail-open, so it cannot
   brick anything -- but it would refuse every flash on the box with no test
   noticing, which is its own kind of broken. Matched as a pattern, not a
   literal line: the previous spelling of this assertion also went red for a
   cosmetic reflow of the array's whitespace, which is a pin nobody can trust. */
check('and the answer it gets reaches the gate',
      (bool) preg_match('/[\'"]card[\'"]\s*=>\s*\$isCard\b/', $flashSrc));
check('and derives the cards the same way the page does',
      str_contains($flashSrc, 'lsi_group_cards($ctls, $counts)')
      && str_contains($flashSrc, 'lsi_ioc_counts(fw_load())'));
/* The wrapper must stay thin: everything the checks above prove lives in
   flash_cards_from(), and logic that creeps back into flash_card_chips() is
   logic no test can reach (it shells out to real hardware). */
check('flash_card_chips() only decodes and delegates',
      (bool) preg_match('/function flash_card_chips\(\): array \{\s*return flash_cards_from\(/', $flashSrc));
/* The read is a shell_exec that can take a minute, and flash_claim_lock() has
   no TTL recovery -- a PHP death between the claim and the launch orphans the
   lock, and every later flash is then refused "already in progress" until /tmp
   clears at reboot. So the read must happen BEFORE the claim. Positions, not
   prose, because this is an ordering property and nothing else can see it. */
$posRead = strpos($flashSrc, 'flash_ctl_is_card($ctl, $chip, flash_card_chips())');
$posLock = strpos($flashSrc, 'flash_claim_lock($lock)');
$posRun  = strpos($flashSrc, "shell_exec('nohup sh -c '");
check('the hardware read happens before the lock is claimed',
      $posRead !== false && $posLock !== false && $posRead < $posLock);
check('and the lock is still claimed before the job launches',
      $posLock !== false && $posRun !== false && $posLock < $posRun);
// ...and a malformed list must not pay for the read at all.
check('a bad list short-circuits the read',
      str_contains($flashSrc, 'flash_ctl_list($ctl) !== null && flash_ctl_is_card('));
// The page says a wait is coming, so nobody double-presses into the lock.
check('the page warns that the check takes time',
      str_contains($jsSrc, 'this can take up to a minute on a slow controller'));
/* Where the list comes from: the firmware page is fed one entry per CARD by
   ajax_info.php's overview JSON, and each entry carries its own controller
   number(s). The page must never use the array index as a controller number --
   a group's members are not necessarily contiguous, so the two are different
   facts (card_group.php's header has the [[0,2],[1]] case). card_group_test.php
   proves the grouping itself against real pipeline output; these pin the wiring
   on both sides of it, which no PHP test can reach (the overview branch lives
   inside the HTTP dispatch, and flash_view.js has no runtime harness). */
$ajaxSrc = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php');
// fw_load()'s index, never a hand-built map: lsi_ioc_counts() keys on
// fw_normalize(), so a literal 'SAS9300-16i' key misses every lookup and
// nothing ever groups. That defect has shipped on this feature once already.
check('the firmware page is fed cards grouped through the real index',
      str_contains($ajaxSrc, 'lsi_group_cards($ctls, lsi_ioc_counts($fwIdx))'));
check('and each entry carries its own controller list',
      str_contains($ajaxSrc, "\$card['ctl'] = implode(',', \$g);"));
check('the page sends that list, not an array index, to the verify action',
      str_contains($jsSrc, "action:'listall', chip:flashChip(ctl), ctl:ctl"));
check('and to the flash action',
      str_contains($jsSrc, "action:'flash', chip:flashChip(ctl), ctl:ctl"));
check('the card element is keyed by the controller list',
      str_contains($jsSrc, 'data-ctl="\'+ctl+\'"'));
check('the tool lookup is keyed by it too', str_contains($jsSrc, 'luFlashTool(c.ctl)'));
// The whole point: no callback in this file takes a loop index any more.
check('no handler is passed the array index as a controller number',
      !str_contains($jsSrc, 'ctl:i') && !str_contains($jsSrc, 'luFlashTool(i)')
      && !str_contains($jsSrc, 'luFlashList(\'+i+\')') && !str_contains($jsSrc, 'luFlashGo(\'+i+\')'));
// The operator has to see that Verify and Flash cover both controllers.
check('the card names every controller it covers', str_contains($jsSrc, 'ctlLabel(c.ctl)'));

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

/* ── Where the firmware page lives, and the two links that must agree ────────
   Menu= decides both the placement and the URL root, and two hardcoded hrefs
   depend on the answer. That is one fact in three files, and nothing else
   would notice them drifting apart until a user clicked and got a 404 -- so
   all three are pinned together, here.

   Menu="Utilities" put a second tile beside HBAviewer's own in Settings ->
   Utilities: a door straight to the flasher that skipped the danger notice,
   and one that stayed visible on a locked install for anyone who had ever
   ticked the box -- an icon leading to a page that only says "disabled".
   Menu="HBAviewer_Settings" removed the tile but rendered the whole flash page
   inline underneath the settings form, because Unraid stacks the children of
   an xmenu parent onto one page.

   Menu="HBAviewer" is the shape HBAviewer_Monitor.page already proves works:
   HBAviewer.page is Type="menu", a real container, so its children are
   standalone pages under /Tools/. */
$flashPage = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/HBAviewer_Flash.page');
$monSrc    = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php');
check('the firmware page is not a second Utilities tile',
      !str_contains($flashPage, 'Menu="Utilities"'));
check('nor stacked inline under the settings form',
      !str_contains($flashPage, 'Menu="HBAviewer_Settings"'));
check('it is a standalone page under the HBAviewer menu',
      str_contains($flashPage, 'Menu="HBAviewer"'));
check('the button in settings points at the URL that placement produces',
      str_contains($settingsSrc, 'href="/Tools/HBAviewer_Flash"'));
// The Monitor's tab navigates from onclick, not href -- it has to be a <button>
// to pick up the theme styling the rest of the strip gets -- so match the path
// itself rather than the attribute that carries it.
check('and so does the Monitor tab',
      str_contains($monSrc, "'/Tools/HBAviewer_Flash'"));

/* Both entrances carry the same two conditions. A tab that appeared on a
   locked install would be the Utilities tile's mistake in a new place. */
check('the Monitor tab is gated on the lock and the user toggle',
      str_contains($monSrc, "!LSI_FLASH_LOCKED && (int) \$cfg['ENABLE_FLASH'] === 1"));

echo $fails === 0 ? "flash_php: all pass\n" : "flash_php: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
