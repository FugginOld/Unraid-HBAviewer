<?php
/* docs/review-policy.md names specific code as "rejected on sight" — parser
   files that must stay separate, functions that must keep their names, an exit
   code that must stay distinct from its neighbour. That list was written from
   ARCHITECTURE.md's prose rather than from the source, so nothing stopped it
   describing code that had since been renamed or removed.
 *
 * A policy protecting something that no longer exists is worse than no policy:
 * it reads as active protection while the thing it guards has already gone, and
 * the next reviewer trusts it.
 *
 * So: only the MECHANICALLY checkable claims, and only the ones whose absence
 * would make a line of that document false. The prose is not tested here, and
 * neither is whether any of these designs are still the right ones — that is a
 * decision, and this file is a tripwire.
 *
 * Deliberately NOT a duplicate of the behaviour tests. flash_php_test.php pins
 * that the flash preflight reads hardware before claiming the lock because
 * getting it wrong strands a lock on a crashed read. The same assertion appears
 * below for a different reason: the policy document cites that ordering, so the
 * document is wrong the moment it stops holding. Same fact, two jobs.
 */

$plugin = __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer';
require_once "$plugin/event_archive.php";   // lsi_backend_shape()
require_once "$plugin/flash.php";           // returns before dispatch under CLI

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    if ($ok) { echo "PASS  $name\n"; } else { echo "FAIL  $name\n"; $fails++; }
}

/* ── The StorCLI2 parsers stay separate ─────────────────────────────────────
   Four files, not one parameterised parser. The policy names them because the
   simplification is attractive on paper and was measured to be wrong: the two
   tools emit different section headers and key spellings, and a merged parser
   grows a branch per difference until it is two parsers sharing a filename. */
foreach (['storcli2_overview', 'storcli2_phy', 'storcli2_drives', 'storcli2_enclosures'] as $p) {
    check("parse/$p.sh exists", is_file("$plugin/scripts/parse/$p.sh"));
}

/* ...and the event parser is the one that is deliberately SHARED. The events
   payload is the same shape from both tools, so a storcli2_events.sh would be a
   copy to keep in step. Two halves: the file the storcli2 path reaches for, and
   the absence of a forked one. */
$evComposer = (string) file_get_contents("$plugin/scripts/get_event_log.sh");
check('the storcli2 event path uses the shared parser',
      (bool) preg_match('~ev_storcli2\(\).*?parse/storcli_events\.sh~s', $evComposer));
check('no forked storcli2 event parser exists',
      !is_file("$plugin/scripts/parse/storcli2_events.sh"));

/* ── One rendering path for both storcli tools ──────────────────────────────
   Called, not grepped: the claim is about what the function DOES. StorCLI2 is a
   different tool emitting the same record shape, so renderers ask about the
   shape and get 'storcli' for both. */
check('lsi_backend_shape folds storcli2 onto storcli',
      lsi_backend_shape('storcli2') === 'storcli');
check('and leaves the other backends alone',
      lsi_backend_shape('storcli') === 'storcli' && lsi_backend_shape('lsiutil') === 'lsiutil');

/* ── Exit 7 is not exit 6 ───────────────────────────────────────────────────
   6 means nothing was written and the card is safe. 7 means one IOC of a
   dual-controller board was written and the other was not, so the board is
   mismatched and must not be rebooted. Sharing one code made those two record
   the same value in flash.status and render as the same generic error, with
   only free text telling them apart. */
$flashSh = (string) file_get_contents("$plugin/scripts/flash_hba.sh");
check('flash_hba.sh still exits 7 on a partial flash',
      (bool) preg_match('~die "PARTIAL FLASH\.[^"]*" 7~', $flashSh));
check('and 6 when nothing was written',
      (bool) preg_match('~die "flash of /c\$one failed and nothing was written" 6~', $flashSh));

$flashPhp = (string) file_get_contents("$plugin/flash.php");
check('flash.php still maps exit 7 to a partial result',
      (bool) preg_match('~\$exit === 7\)\s*\$res\[.done.\] = .partial.~', $flashPhp));

/* ── The flash helpers keep their names ─────────────────────────────────────
   Named in the policy, so a rename would leave it protecting nothing. Loaded
   through flash.php's CLI guard, so these are real declarations rather than
   text matches. */
foreach (['flash_preflight', 'flash_cards_from', 'flash_ctl_list', 'flash_ctl_is_card',
          'flash_card_chips', 'flash_claim_lock', 'flash_safe_name'] as $fn) {
    check("$fn() still exists", function_exists($fn));
}

/* ── The hardware read stays above the lock ─────────────────────────────────
   flash_card_chips() can take a minute on a slow controller. Claiming the lock
   first means a crash during that read strands it. */
/* The CALL expression, not the bare name: 'flash_card_chips()' first matches
   the function's own declaration comment near the top of the file, which sits
   above the lock no matter what order the calls are in. The first draft of this
   check did exactly that and passed against a deliberately reordered
   flash.php. */
$posChips = strpos($flashPhp, 'flash_ctl_is_card($ctl, $chip, flash_card_chips())');
$posLock  = strpos($flashPhp, 'flash_claim_lock($lock)');
check('flash_card_chips() is still called before flash_claim_lock()',
      $posChips !== false && $posLock !== false && $posChips < $posLock);
// The behaviour test is what defends the ordering; this checks it is still
// there, because the policy cites it as the reason the ordering is safe to rely
// on. A policy citing a deleted test is the failure mode this file exists for.
check('and flash_php_test.php still pins that ordering',
      str_contains((string) file_get_contents(__DIR__ . '/flash_php_test.php'),
                   'the hardware read happens before the lock is claimed'));

/* ── The backend seam keeps its names ───────────────────────────────────────
   Shell, so this is a text check: these three are what the composers dispatch
   through, and the policy names them as the seam not to collapse. */
$libSh = (string) file_get_contents("$plugin/scripts/lib.sh");
foreach (['hba_is_sas_proc', 'hba_driver', 'use_storcli'] as $fn) {
    check("$fn() still exists in lib.sh", (bool) preg_match('~^' . $fn . '\(\)~m', $libSh));
}

/* ── flash_rc is reset per iteration ────────────────────────────────────────
   It is only assigned on failure, so a value inherited from the environment
   made iteration 1 report a SUCCESSFUL write as "nothing was written" — the
   most dangerous lie the loop can tell, because the operator then re-runs from
   a state the script has misdescribed. Position, not presence: an assignment
   above the loop is exactly the bug. */
$posFor  = strpos($flashSh, "for one in \$(printf '%s' \"\$ctl\" | tr ',' ' '); do");
$posDone = $posFor === false ? false : strpos($flashSh, "
done
", $posFor);
/* Slice the loop BODY and look in there, rather than comparing first-occurrence
   offsets. Offsets were the first draft and they were ambiguous: an assignment
   hoisted above the loop still leaves one inside it in most edits, so "the
   first flash_rc= is after the for" can hold while the bug is present. The
   body either contains the reset or it does not. */
$loopBody = ($posFor !== false && $posDone !== false)
    ? substr($flashSh, $posFor, $posDone - $posFor) : '';
check('flash_rc is reset inside the loop body',
      $loopBody !== '' && str_contains($loopBody, 'flash_rc=""'));
// ...and nowhere above it, which is the actual bug: a value inherited from the
// environment, or set once before the loop, survives into iteration 2.
check('and not hoisted above the loop',
      $posFor !== false && !str_contains(substr($flashSh, 0, $posFor), 'flash_rc='));

/* ── The two switches stay two switches ─────────────────────────────────────
   TRACK_HISTORY hidden behind ENABLE_NOTIFY is the collapse the policy names;
   it has to exist as its own key to be its own switch. */
$cfgPhp = (string) file_get_contents("$plugin/config.php");
check('TRACK_HISTORY is a config key of its own',
      (bool) preg_match("~'TRACK_HISTORY'\s*=>~", $cfgPhp)
      && (bool) preg_match("~'ENABLE_NOTIFY'\s*=>~", $cfgPhp));

/* ── The notify branch returns, it does not exit ────────────────────────────
   Position again, not presence: the only exit(0) allowed is the gate at the
   top, before either feature runs. One below it skips the history sample.

   Tokenised, not grepped: the comment on the line the policy is about SAYS
   "rather than exit(0)", and a text search reads that as the bug it warns
   against. */
$notify = (string) file_get_contents("$plugin/scripts/notify_check.php");
$exits  = [];
foreach (token_get_all($notify) as $t) {
    if (is_array($t) && $t[0] === T_EXIT) $exits[] = $t[2];   // line numbers
}
check('the both-off gate is still there',
      (bool) preg_match('~if \(!\$doNotify && !\$doHistory\) exit\(0\);~', $notify));
// Exactly one: the gate. A second is one below it, skipping the history sample.
check('and it is the only exit in the file', count($exits) === 1);

/* ── install-verify proves WHICH build it verified ──────────────────────────
   A content diff of the extracted package against the installed tree, and it
   has to come before the checks that report on that tree. */
$iv       = (string) file_get_contents(__DIR__ . '/../docs/install-verify.sh');
$posDiff  = strpos($iv, 'diff -r "$VERIFYTMP/usr/local/emhttp/plugins/hbaviewer" "$PLUGIN"');
check('install-verify diffs the package against the installed tree', $posDiff !== false);
check('and does so before it renders anything from that tree',
      $posDiff !== false && $posDiff < strpos($iv, 'renderHealthTables'));

echo $fails === 0 ? "review_policy: all pass\n" : "review_policy: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
