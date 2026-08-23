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

/* ioc_count is the ONE field in this index that merges two separate controllers
   into a single flashable card. Add it to a board that does not have two IOCs
   and the plugin offers "Controller /c0, /c1" for two unrelated HBAs, then
   writes one image to both. Adding "ioc_count": 2 to SAS9300-8i currently
   survives every other assertion in this suite, and the hardware facts behind
   the field are otherwise recorded only in a planning ledger. So pin the set:
   a board joins it only when someone has the board in hand. */
$withIoc = [];
foreach ($idx['boards'] as $name => $b) {
    if (array_key_exists('ioc_count', $b)) $withIoc[] = $name;
}
sort($withIoc);
check('exactly the confirmed dual-IOC boards carry ioc_count', $withIoc === ['SAS9300-16i']);
if ($withIoc !== ['SAS9300-16i']) echo "      got: " . implode(', ', $withIoc) . "\n";
// And its value: 1 would silently un-merge the card, 3 would refuse to merge it.
check('SAS9300-16i declares two IOCs', ($idx['boards']['SAS9300-16i']['ioc_count'] ?? null) === 2);

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

// New-2: every version fw_compare() will ever see from the index -- latest_it
// and every rom_profiles[*].version -- must itself be a bare dotted number.
// fw_compare()'s intval() reads a typo like 'P20' or '16.00.12.00-IT' as 0 on
// whichever part doesn't parse, silently mis-sorting instead of failing loud.
$badVer = [];
foreach ($idx['boards'] as $name => $b) {
    $vs = [$b['latest_it'] ?? null];
    foreach (($b['rom_profiles'] ?? []) as $pv) {
        if (is_array($pv) && isset($pv['version'])) $vs[] = $pv['version'];
    }
    foreach ($vs as $vv) {
        if ($vv !== null && !preg_match('/^\d+(\.\d+)*\z/', (string) $vv)) $badVer[] = $name;
    }
}
check('every indexed version is a bare dotted number', $badVer === []);
if ($badVer) echo "      " . implode(', ', $badVer) . "\n";

// Settled by the 2026-08-08 bundle: the live 9305-24i reports SAS3224 and runs
// MPTFW-15.00.00.00-IT. SAS3324 does not exist; it was a typo in the manifest
// builder that leaked into an "unconfirmed, may be RAID-on-Chip" list.
check('SAS9305-24i is present and IT-capable', !empty($idx['boards']['SAS9305-24i']['it_capable']));
check('SAS9305-24i chip is SAS3224', ($idx['boards']['SAS9305-24i']['chip'] ?? '') === 'SAS3224');
/* Downgraded 2026-08-11. The live card reported MPTFW-15.00.00.00-IT, which
   proves the part is IT-capable and says nothing about 16.00.12.00 on this
   board -- the only version ever seen on a 24i is BELOW the listed one.
   'confirmed' means equality is meaningful in both directions; here it would
   claim that matching proves current, on no evidence. Pinned so restoring it
   is a decision someone makes and edits a test for. */
check('SAS9305-24i is observed-floor, not confirmed',
      ($idx['boards']['SAS9305-24i']['confidence'] ?? '') === 'observed-floor');
check('the unverified_chips typo block is gone', !isset($idx['unverified_chips']));

// The most useful field in the file, and the one a UI change could drop.
check('SAS9300-8i carries its SATA controller-reset note',
      str_contains((string) ($idx['boards']['SAS9300-8i']['notes'] ?? ''), 'controller-reset'));

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/firmware_index.php';

// Both board-naming conventions must collapse to one key. SAS3 and earlier
// report "SAS9305-24i"; SAS3.5 reports "HBA 9400-16i".
check('normalize strips the SAS prefix',  fw_normalize('SAS9305-24i') === '930524i');
check('normalize strips the HBA prefix',  fw_normalize('HBA 9400-16i') === '940016i');
check('normalize is case-insensitive',    fw_normalize('sas9305-24i') === fw_normalize('SAS9305-24i'));

check('compare equal',   fw_compare('16.00.12.00', '16.00.12.00') === 0);
check('compare older',   fw_compare('15.00.00.00', '16.00.12.00') < 0);
check('compare newer',   fw_compare('17.00.00.00', '16.00.12.00') > 0);
// A short version must not sort above a long one: 16 is 16.0.0.0, not "more".
check('compare pads the shorter side', fw_compare('16', '16.00.12.00') < 0);
// Leading zeros are decimal, not octal, and "00" must equal 0.
check('compare treats 00 as zero', fw_compare('16.00.12.00', '16.0.12.0') === 0);

$idx = fw_load();
check('index loads', is_array($idx));

// The card from the 2026-08-08 bundle. This is the worked example in the spec
// and the case the whole feature exists for.
$reporter = [
    'board' => 'SAS9305-24i', 'chip' => 'SAS3224', 'firmware' => '15.00.00.00',
    'subvendor_id' => '0x1000', 'topology' => 'internal',
];
$v = fw_evaluate($reporter, $idx);
check('reporter 9305-24i is behind',        $v['status'] === 'behind');
check('reporter names the latest version',  ($v['latest'] ?? '') === '16.00.12.00');
check('reporter branch is terminal',        ($v['terminal'] ?? null) === true);
/* The property here is that the tier is SURFACED, not what it happens to be.
   Hardcoding the value made this fail the moment the 24i was re-tiered, which
   is a test reporting a data edit as a plumbing break. Compare against the
   index instead: still catches a verdict that drops confidence entirely (null
   never equals a real tier), and the value itself is pinned with the data. */
check('reporter surfaces the index confidence',
      ($v['confidence'] ?? null) === ($idx['boards'][fw_normalize('SAS9305-24i')]['confidence'] ?? 'MISSING'));
check('reporter carries the board note',    str_contains((string) ($v['note'] ?? ''), 'NOT interchangeable'));

$v = fw_evaluate(['board' => 'SAS9305-24i', 'firmware' => '16.00.12.00',
                  'subvendor_id' => '0x1000', 'topology' => 'internal'], $idx);
check('matching version is current', $v['status'] === 'current');

$v = fw_evaluate(['board' => 'SAS9305-24i', 'firmware' => '17.00.00.00',
                  'subvendor_id' => '0x1000', 'topology' => 'internal'], $idx);
check('newer than index is ahead, not behind', $v['status'] === 'ahead');

// New-1: fw_track_version() must not treat "a rom_profile was supplied" as
// "this board has profiles". SAS9305-24i has no rom_profiles key at all, so a
// caller populating rom_profile (nothing does yet, but the plan lists it as
// an accepted $ctl key) must not lose its verdict to a false "no known
// version" -- latest_it is right there.
$v = fw_evaluate($reporter + ['rom_profile' => 'IT'], $idx);
check('a board with no rom_profiles compares normally despite a supplied rom_profile',
      $v['status'] === 'behind' && ($v['latest'] ?? '') === '16.00.12.00');

// THE gate that matters. An M1015 or H310 reaching the generic image is a
// crossflash, not an upgrade, and telling someone otherwise does real harm.
$v = fw_evaluate(['board' => 'SAS9211-8i', 'firmware' => '20.00.00.00',
                  'subvendor_id' => '0x1014', 'topology' => 'internal'], $idx);
check('OEM subvendor is out of scope', $v['status'] === 'oem_out_of_scope');
check('OEM reason says crossflash',    str_contains((string) $v['reason'], 'crossflash'));

// C2: an unreadable subvendor_id ('') must be exactly as out-of-scope as a
// confirmed OEM one -- never "assume generic". Board name alone doesn't save
// it: Dell H310s and IBM M1015s routinely report the generic SAS9211-8i.
$v = fw_evaluate(['board' => 'SAS9211-8i', 'firmware' => '20.00.00.00',
                  'subvendor_id' => '', 'topology' => 'internal'], $idx);
check('empty subvendor_id is out of scope, not assumed generic', $v['status'] === 'oem_out_of_scope');

// I2: gate order is load-bearing. OEM must outrank every gate after it, not
// just win when nothing else applies.
check('OEM outranks not-indexed',
      fw_evaluate(['board'=>'SAS9999-99i','firmware'=>'1.0.0.0','subvendor_id'=>'0x1014'], $idx)['status'] === 'oem_out_of_scope');
check('OEM outranks RAID-on-Chip',
      fw_evaluate(['board'=>'MegaRAID 9361-8i','chip'=>'SAS3108','firmware'=>'4.0.0.0','subvendor_id'=>'0x1014'], $idx)['status'] === 'oem_out_of_scope');

// C1: a non-numeric firmware string must never reach fw_compare(), where
// intval() reads it as 0.0.0.0 and reports a false BEHIND. 'Unknown' is
// scripts/parse/hba.sh's undecoded-hex sentinel; the MPTFW banner is what an
// unparsed lsiutil version string looks like if it ever reached this far.
$v = fw_evaluate(['board' => 'SAS9211-8i', 'firmware' => 'Unknown',
                  'subvendor_id' => '0x1000', 'topology' => 'internal'], $idx);
check('non-numeric firmware sentinel is unknown, not behind', $v['status'] === 'unknown');

$v = fw_evaluate(['board' => 'SAS9305-24i', 'firmware' => 'MPTFW-15.00.00.00-IT',
                  'subvendor_id' => '0x1000', 'topology' => 'internal'], $idx);
check('unparsed banner string is unknown, not behind', $v['status'] === 'unknown');

// The multipath suppression, which is why topology detection had to exist.
$v = fw_evaluate(['topology' => 'unknown'] + $reporter, $idx);
check('affected board with unknown topology is suppressed', $v['status'] === 'suppressed');
check('suppressed still shows the detected version', ($v['detected'] ?? '') === '15.00.00.00');
check('suppressed carries no verdict',   !isset($v['latest']));

// A board with no multipath track compares regardless of topology.
$v = fw_evaluate(['board' => 'SAS9201-16i', 'firmware' => '19.00.00.00',
                  'subvendor_id' => '0x1000', 'topology' => 'unknown'], $idx);
check('unaffected board compares despite unknown topology', $v['status'] === 'behind');

// RAID-on-Chip: no IT firmware exists at any version. Distinct from a failed
// lookup, because the answer is "never", not "not yet known".
$v = fw_evaluate(['board' => 'MegaRAID 9361-8i', 'chip' => 'SAS3108',
                  'firmware' => '4.00.00.00', 'subvendor_id' => '0x1000'], $idx);
check('RAID-on-Chip reports no_it_firmware', $v['status'] === 'no_it_firmware');

// Profile-aware board with no resolved profile: same version ships in
// incompatible capability profiles, so the number alone means little.
$v = fw_evaluate(['board' => 'HBA 9400-16i', 'firmware' => '24.00.00.00',
                  'subvendor_id' => '0x1000', 'topology' => 'internal'], $idx);
check('unresolved ROM profile is suppressed', $v['status'] === 'suppressed');

// I1: a profile string the index does NOT recognise must suppress too, not
// silently fall through to the standard track's number. A 9405W on the
// IT_Nexus_Multipath profile correctly runs 15.00.01.00 by design (index
// note); comparing that against the 21.x standard track would be a false
// BEHIND on a correctly configured card.
$v = fw_evaluate(['board' => 'HBA 9405W-16i', 'firmware' => '15.00.01.00',
                  'subvendor_id' => '0x1000', 'topology' => 'internal',
                  'rom_profile' => 'zzz'], $idx);
check('unrecognised rom_profile is suppressed, not compared against the wrong track',
      $v['status'] === 'suppressed');

// I1: fw_track_version's profile branch had no test at all. A RESOLVED
// multipath profile must compare within its own track and must not degrade
// to latest_it (HBA 9400-16i's rom_profiles are plain filename strings, not
// {version: ...} objects -- that shape must return null, not a stray value).
$v = fw_evaluate(['board' => 'HBA 9405W-16i', 'firmware' => '15.00.01.00',
                  'subvendor_id' => '0x1000', 'topology' => 'internal',
                  'rom_profile' => 'IT_Nexus_Multipath'], $idx);
check('resolved multipath profile compares within its own track', $v['status'] === 'current');

$v = fw_evaluate(['board' => 'SAS9999-99i', 'firmware' => '1.0.0.0',
                  'subvendor_id' => '0x1000', 'topology' => 'internal'], $idx);
check('unindexed board is unknown', $v['status'] === 'unknown');

$v = fw_evaluate($reporter, null);
check('no index at all is unknown', $v['status'] === 'unknown');

// I3: only the terminal=>true direction was ever produced end-to-end by
// fw_evaluate() itself (the reporter's P16 board); the non-terminal 'behind'
// path -- where amber must NOT fire -- had no real board exercising it. HBA
// 9500-16i is P28, terminal:false, with no rom_profiles to complicate it.
$v = fw_evaluate(['board' => 'HBA 9500-16i', 'firmware' => '20.00.00.00',
                  'subvendor_id' => '0x1000', 'topology' => 'internal'], $idx);
check('behind on a non-terminal branch is not amber',
      $v['status'] === 'behind' && fw_verdict_color($v) === '');

// I3: a 'weak'-confidence board must report its own tier, never upgrade
// itself to 'confirmed' by falling through some other board's metadata.
$v = fw_evaluate(['board' => 'HBA 9405W-16i', 'firmware' => '20.00.00.00',
                  'subvendor_id' => '0x1000', 'topology' => 'internal',
                  'rom_profile' => 'Mixed_TriMode'], $idx);
check('weak-confidence board reports weak, not confirmed', ($v['confidence'] ?? '') === 'weak');

// Colour is reserved for a terminal branch in BOTH directions. On a
// non-terminal branch "latest" is a floor, not a ceiling: behind renders
// informational, and current is not proof of current either -- green there was
// unconditional, so the observed-floor boards (9500-8i, 9500-16i, 9400-8i) got a
// hard tick from data the index's own comment says does not support one.
check('behind on a terminal branch is amber',
      fw_verdict_color(['status' => 'behind', 'terminal' => true]) === '#d29922');
check('behind on a non-terminal branch has no colour',
      fw_verdict_color(['status' => 'behind', 'terminal' => false]) === '');
check('current on a terminal branch is green',
      fw_verdict_color(['status' => 'current', 'terminal' => true]) === '#3fb950');
check('current on a non-terminal branch has no colour',
      fw_verdict_color(['status' => 'current', 'terminal' => false]) === '');
// End to end, not just the helper: HBA 9500-16i is P28/terminal:false, and its
// own observed-floor "latest" is what a matching card reports as current.
$vc = fw_evaluate(['board' => 'HBA 9500-16i', 'firmware' => '28.00.00.00',
                   'subvendor_id' => '0x1000', 'topology' => 'internal'], $idx);
check('a real observed-floor board reads current with no colour',
      $vc['status'] === 'current' && fw_verdict_color($vc) === '');

/* Round-1 review (Important, I1): fw_evaluate()'s two-arg signature exists so
   a request evaluating N controllers reads the index once — but view.php's
   lsi_hba_view() calls fw_load() itself on every card, so without a cache
   inside fw_load() a 4-controller Overview still re-read and re-parsed the
   ~14KB file 4 times. fw_load() now memoizes by resolved path: a file that
   changes after the first read must not un-cache, or it is not a cache. */
$memoPath = sys_get_temp_dir() . '/hbav_fwidx_memo_' . getmypid() . '.json';
file_put_contents($memoPath, json_encode(['schema_version' => 1, 'updated' => '2026-01-01',
    'boards' => ['X' => ['chip' => 'C', 'it_capable' => true, 'latest_it' => '1.0',
                          'branch' => 'P1', 'confidence' => 'confirmed']],
    'branches' => ['P1' => ['terminal' => true]]]));
$firstRead = fw_load($memoPath);
file_put_contents($memoPath, 'not valid json at all');   // changed after the first read
check('fw_load memoizes by path (a later change is not re-read)', fw_load($memoPath) === $firstRead);
@unlink($memoPath);

// An unreadable/missing index must be cached as a miss too, so a genuinely
// absent index is not re-stat'd once per controller either.
$missingPath = sys_get_temp_dir() . '/hbav_fwidx_missing_' . getmypid() . '.json';
@unlink($missingPath);
check('fw_load caches a miss', fw_load($missingPath) === null);
file_put_contents($missingPath, json_encode(['schema_version' => 1, 'updated' => '2026-01-01',
    'boards' => ['X' => ['chip' => 'C', 'it_capable' => true]]]));
check('fw_load keeps a cached miss even after the file later appears', fw_load($missingPath) === null);
@unlink($missingPath);

/* ── Structural invariants of the index ──────────────────────────────────────
   Ported from a proposed schema-2 validator (docs/proposals/) after checking
   that the current data already satisfies all four. That is the point: they are
   true today by care rather than by enforcement, and each one is a rule the
   index states in prose and could not check. Keeping them here means schema 1
   gets the guarantees without the migration, and a schema-2 loader inherits a
   passing suite instead of writing these from scratch. */

/* Re-read the file rather than reusing $idx: by this point $idx is fw_load()'s
   view, whose board keys have been through fw_normalize() -- SAS9300-16i is
   stored as 930016i. Checking the multipath list against those keys reports all
   eight as dangling, which is how this block failed the first time it ran.
   Same normalisation mismatch that made the card-grouping map match nothing.
   These invariants are about the data as AUTHORED, so they read the file. */
$RAW = json_decode((string) file_get_contents($INDEX), true);
$B   = $RAW['boards']   ?? [];
$BR  = $RAW['branches'] ?? [];

/* 'confirmed' means equality is meaningful in BOTH directions, which is only
   true on a terminal branch — on a floor, matching proves nothing. A confirmed
   row on a non-terminal branch is the single most consequential hand-edit error
   possible here: it turns "you are at the floor" into "you are up to date". */
$badConfirmed = [];
foreach ($B as $name => $b) {
    if (($b['confidence'] ?? '') !== 'confirmed') continue;
    $br = (string) ($b['branch'] ?? '');
    if (empty($BR[$br]['terminal'])) $badConfirmed[] = "$name/$br";
}
check('no board claims confirmed on a non-terminal branch', $badConfirmed === []);
if ($badConfirmed) echo "      " . implode(', ', $badConfirmed) . "
";

/* The multipath list suppresses verdicts, so a name that matches no board is a
   suppression that silently never fires. */
$dangling = array_values(array_filter(
    $RAW["multipath_track"]["affected_boards"] ?? [],
    static fn(string $m): bool => !isset($B[$m])));
check('every multipath-affected board resolves to a real board', $dangling === []);
if ($dangling) echo "      " . implode(', ', $dangling) . "
";

/* Every indexed chip must reach a tool, or the firmware page offers a card and
   then dies at exit 3 when you press Flash.

   The patterns are PARSED OUT OF flash_hba.sh rather than copied here. A copy
   is what made this check worthless once already: the real arms were
   SAS30*|SAS31* and SAS34*|SAS35* while a transcription of them elsewhere
   claimed SAS32* and SAS36*|SAS38* too, so five indexed boards -- both 9305s,
   the 9405W and both 9500s -- could not reach a flasher, while a check written
   against the copy reported all thirteen fine. Fixed in 894666d; reading the
   shell is what stops the copy drifting again. */
$SH = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/scripts/flash_hba.sh');
$fn = preg_match('/^flasher_for_chip\(\)\s*\{(.*?)^\}/ms', $SH, $m) ? $m[1] : '';
check('flasher_for_chip() was found in flash_hba.sh', $fn !== '');
// Only the arms that yield a tool. The refusal arm (return 2) and the catch-all
// (return 1) match chips too, but deliberately provide no flasher.
$globs = [];
if (preg_match_all('/^\s*([A-Za-z0-9_*|]+)\)\s*echo\s+\w+/m', $fn, $mm)) {
    foreach ($mm[1] as $arm) { foreach (explode('|', $arm) as $g) $globs[] = $g; }
}
check('flash-tool globs were parsed out of the shell', count($globs) >= 3);
$unreachable = [];
foreach ($B as $name => $b) {
    $c = (string) ($b['chip'] ?? '');
    $hit = false;
    foreach ($globs as $g) { if (fnmatch($g, $c)) { $hit = true; break; } }
    if (!$hit) $unreachable[] = "$name($c)";
}
check('every indexed chip matches a flash-tool glob in flash_hba.sh', $unreachable === []);
if ($unreachable) echo "      " . implode(', ', $unreachable) . "
";

/* A chip on the no-IT-firmware list that is ALSO an indexed board is a direct
   contradiction: one half of the file says flash it, the other says it cannot
   be flashed at any version. */
$contradictions = [];
foreach (array_keys($RAW["no_it_firmware"] ?? []) as $rc) {
    if ($rc === '_comment') continue;
    foreach ($B as $name => $b) {
        if (($b['chip'] ?? '') === $rc) $contradictions[] = "$name/$rc";
    }
}
check('no refused chip is also an indexed board', $contradictions === []);
if ($contradictions) echo "      " . implode(', ', $contradictions) . "
";


/* ── HBA 9500-8i, from real hardware (bundle 2026-08-23) ─────────────────────
   The first 9500 this repo has seen. Three things it settled, each pinned here
   because all three were previously assumptions in the index rather than
   observations:

   1. The board runs P31. The index said P28, so a card on current firmware was
      told "newer than index" -- correct handling of a stale index, but the
      index was the thing that was wrong.
   2. It HAS a legacy option ROM. The entry said `"bios": null` with a note
      claiming that was expected rather than missing, and asked for
      confirmation. storcli reports Bios Version = 09.27.00.00_14.00.00.00.
   3. It is on mpt3sas and the storcli backend, not mpi3mr. */
$fw9500 = fn(string $v) => fw_evaluate([
    'board' => 'HBA 9500-8i', 'chip' => 'SAS3808', 'firmware' => $v,
    'subvendor_id' => '0x1000', 'topology' => 'unknown',
], fw_load());

check('a 9500-8i on the observed firmware reads as current',
      $fw9500('31.00.00.00')['status'] === 'current');
// The exact version the index used to call newest. A card there is now behind,
// which is the whole point of raising an observed floor.
check('the version the index used to name is now behind',
      $fw9500('28.00.00.00')['status'] === 'behind');
$idx9500 = fw_load()['boards'][fw_normalize('HBA 9500-8i')] ?? [];
check('the option ROM is recorded, not null',
      ($idx9500['bios'] ?? null) === '09.27.00.00_14.00.00.00');
/* The 16i is the same generation and very likely the same track. Likely is not
   observed, and an index whose own contract calls observed-floor "seen on a
   real card" must not promote a guess into one. */
$idx16i = fw_load()['boards'][fw_normalize('HBA 9500-16i')] ?? [];
// array_key_exists, not ??: null ?? 'x' is 'x', so the coalesce cannot tell
// "the key is absent" from "the key is null" -- and null is the value under
// test. The first draft of this check used ?? and failed against correct data.
check('the unobserved 16i was not moved with it',
      ($idx16i['latest_it'] ?? '') === '28.00.00.00'
      && array_key_exists('bios', $idx16i) && $idx16i['bios'] === null);

echo $fails === 0 ? "firmware_index: all pass\n" : "firmware_index: FAILURES\n";
exit($fails === 0 ? 0 : 1);
