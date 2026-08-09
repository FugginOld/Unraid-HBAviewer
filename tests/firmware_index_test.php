<?PHP
/* Runnable checks for the bundled firmware index and the lookup that reads it.
     php tests/firmware_index_test.php  ->  "firmware_index: all pass" (exit 0)

   The index is hand-maintained data that drives a "you should reflash" claim,
   so a malformed entry is not a cosmetic problem. These assertions cover the
   invariants a hand edit can break silently: a board that claims IT capability
   with no version to compare against, a branch reference with no branch, and
   the two chip typos that hardware has now settled. */

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

$INDEX = __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json';

check('index file exists', is_readable($INDEX));
$idx = json_decode((string) file_get_contents($INDEX), true);
check('index parses as JSON', is_array($idx));
check('schema_version is 1', ($idx['schema_version'] ?? null) === 1);
check('updated is a YYYY-MM-DD date', (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($idx['updated'] ?? '')));
check('boards is a non-empty object', !empty($idx['boards']) && is_array($idx['boards']));

// A deleted mandatory field is worse than a wrong value: it_capable missing
// reads as falsy downstream and reports an IT-capable board as RAID-on-Chip;
// branch missing drops the board out of the referential-integrity check below
// instead of failing it. Presence, not truthiness -- a missing key must fail.
$MANDATORY = ['chip', 'generation', 'backend', 'it_capable', 'branch', 'confidence'];
$missingField = [];
foreach ($idx['boards'] as $name => $b) {
    foreach ($MANDATORY as $field) {
        if (!array_key_exists($field, $b)) $missingField[] = "$name.$field";
    }
}
check('every board has its mandatory fields', $missingField === []);
if ($missingField) echo "      " . implode(', ', $missingField) . "\n";

// An IT-capable board with no latest_it has nothing to compare against, so it
// would silently return 'unknown' forever rather than failing loudly here.
$noVersion = [];
foreach ($idx['boards'] as $name => $b) {
    if (!empty($b['it_capable']) && empty($b['latest_it'])) $noVersion[] = $name;
}
check('every it_capable board has a latest_it', $noVersion === []);
if ($noVersion) echo "      " . implode(', ', $noVersion) . "\n";

// A branch reference with no branch entry makes 'terminal' silently false,
// which downgrades an amber verdict to informational without saying why.
$badBranch = [];
foreach ($idx['boards'] as $name => $b) {
    if (!empty($b['branch']) && !isset($idx['branches'][$b['branch']])) $badBranch[] = "$name -> {$b['branch']}";
}
check('every board branch exists in branches', $badBranch === []);
if ($badBranch) echo "      " . implode(', ', $badBranch) . "\n";

// terminal drives whether an amber (below-floor) verdict or a red (behind a
// terminal branch) one is shown; a missing/non-bool value silently reads as
// false and downgrades every board on that branch.
$badTerminal = [];
foreach ($idx['branches'] as $name => $br) {
    if ($name === '_comment') continue;
    if (!is_bool($br['terminal'] ?? null)) $badTerminal[] = $name;
}
check('every branch has a boolean terminal', $badTerminal === []);
if ($badTerminal) echo "      " . implode(', ', $badTerminal) . "\n";

// The two structures a version-comparison lookup treats as mandatory context,
// per the brief's own interface -- a mutant can delete either wholesale and
// every board-level check above still passes.
check('no_it_firmware is present', !empty($idx['no_it_firmware']));
check('multipath_track.affected_boards all exist as boards',
      array_diff($idx['multipath_track']['affected_boards'] ?? ['MISSING'], array_keys($idx['boards'])) === []);

// A chip one keystroke away from both lists is simultaneously "flash it" and
// "no IT firmware exists at any version" -- a consumer keying on chip gets a
// confident, wrong answer depending on which list it checks first.
$itChips = [];
foreach ($idx['boards'] as $b) {
    if (!empty($b['it_capable']) && !empty($b['chip'])) $itChips[] = $b['chip'];
}
$dualRole = array_intersect($itChips, array_keys($idx['no_it_firmware'] ?? []));
check('no chip is both IT-capable and RAID-on-Chip', $dualRole === []);
if ($dualRole) echo "      " . implode(', ', $dualRole) . "\n";

$tiers = ['confirmed', 'observed-floor', 'weak'];
$badTier = [];
foreach ($idx['boards'] as $name => $b) {
    if (!in_array($b['confidence'] ?? '', $tiers, true)) $badTier[] = $name;
}
check('every board has a known confidence tier', $badTier === []);
if ($badTier) echo "      " . implode(', ', $badTier) . "\n";

// Settled by the 2026-08-08 bundle: the live 9305-24i reports SAS3224 and runs
// MPTFW-15.00.00.00-IT. SAS3324 does not exist; it was a typo in the manifest
// builder that leaked into an "unconfirmed, may be RAID-on-Chip" list.
check('SAS9305-24i is present and IT-capable', !empty($idx['boards']['SAS9305-24i']['it_capable']));
check('SAS9305-24i chip is SAS3224', ($idx['boards']['SAS9305-24i']['chip'] ?? '') === 'SAS3224');
check('the unverified_chips typo block is gone', !isset($idx['unverified_chips']));

// The most useful field in the file, and the one a UI change could drop.
check('SAS9300-8i carries its SATA controller-reset note',
      str_contains((string) ($idx['boards']['SAS9300-8i']['notes'] ?? ''), 'controller-reset'));

echo $fails === 0 ? "firmware_index: all pass\n" : "firmware_index: FAILURES\n";
exit($fails === 0 ? 0 : 1);
