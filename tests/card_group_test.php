<?php
/* Grouping decides whether two rows collapse into one card. Getting it wrong in
   the permissive direction merges two SEPARATE cards, which is worse than the
   two-row display it replaces -- so every check here is about refusing to
   group, not about grouping. */
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/card_group.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    if ($ok) { echo "PASS  $name\n"; } else { echo "FAIL  $name\n"; $fails++; }
}
function ctl(string $board, string $card): array {
    return ['board_name' => $board, 'card_id' => $card];
}

// $iocCounts keys are normalized (fw_normalize), the same key space
// lsi_ioc_counts() and fw_load() both produce -- NOT the raw board_name
// strings $ctls carries. A literal 'SAS9300-16i' key here would silently
// match nothing (fw_normalize('SAS9300-16i') === '930016i') and mask exactly
// the bug that shipped: lsi_group_cards() looking up an unnormalized board
// name against a normalized index.
$counts = [fw_normalize('SAS9300-16i') => 2];

// The case the feature exists for.
$dual = [ctl('SAS9300-16i', '0000:80:01.0'), ctl('SAS9300-16i', '0000:80:01.0')];
check('two IOCs of a known dual board group',
      lsi_group_cards($dual, $counts) === [[0, 1]]);

// The riser hazard: two separate cards behind one motherboard switch.
$risers = [ctl('SAS9300-8i', '0000:80:01.0'), ctl('SAS9300-8i', '0000:80:01.0')];
check('two single-IOC boards sharing a slot do NOT group',
      lsi_group_cards($risers, $counts) === [[0], [1]]);

// Count must match exactly, not "at least".
$three = [ctl('SAS9300-16i', '0000:80:01.0'), ctl('SAS9300-16i', '0000:80:01.0'),
          ctl('SAS9300-16i', '0000:80:01.0')];
check('three controllers on a board declaring two do NOT group',
      lsi_group_cards($three, $counts) === [[0], [1], [2]]);

// Unresolvable slot. Two unknowns are not a match.
$empty = [ctl('SAS9300-16i', ''), ctl('SAS9300-16i', '')];
check('an empty card_id never groups, not even with another empty',
      lsi_group_cards($empty, $counts) === [[0], [1]]);

// Same board name, different slots: two 16i cards, four IOCs, two groups.
$two16 = [ctl('SAS9300-16i', '0000:80:01.0'), ctl('SAS9300-16i', '0000:80:01.0'),
          ctl('SAS9300-16i', '0000:00:11.0'), ctl('SAS9300-16i', '0000:00:11.0')];
check('two dual cards make two groups, not one of four',
      lsi_group_cards($two16, $counts) === [[0, 1], [2, 3]]);

// Mixed names in one slot cannot be one board.
$mixed = [ctl('SAS9300-16i', '0000:80:01.0'), ctl('SAS9300-8i', '0000:80:01.0')];
check('differing board names in one slot do NOT group',
      lsi_group_cards($mixed, $counts) === [[0], [1]]);

// A single controller is a group of one, so callers need no special case.
check('a lone controller is a group of one',
      lsi_group_cards([ctl('SAS9300-8i', '0000:00:11.0')], $counts) === [[0]]);

check('an empty controller list yields no groups',
      lsi_group_cards([], $counts) === []);

// Order is preserved, because the Overview renders in enumeration order.
check('groups come back in input order',
      lsi_group_cards(
          [ctl('SAS9300-8i', '0000:00:11.0'),
           ctl('SAS9300-16i', '0000:80:01.0'), ctl('SAS9300-16i', '0000:80:01.0')],
          $counts) === [[0], [1, 2]]);

// The count map comes from the index, keyed on the SAME normalized form
// fw_load() re-keys the index into, and a board without ioc_count means 1.
$idx = ['boards' => ['SAS9300-16i' => ['ioc_count' => 2], 'SAS9300-8i' => []]];
$fromIdx = lsi_ioc_counts($idx);
check('ioc_count is read from the index',      ($fromIdx[fw_normalize('SAS9300-16i')] ?? 0) === 2);
check('a board without ioc_count means one',   ($fromIdx[fw_normalize('SAS9300-8i')] ?? 0) === 1);
check('no index at all yields no counts',      lsi_ioc_counts(null) === []);

// A group of one that lands between two members of an earlier bucket, so the
// sort by first-member is load-bearing, not cosmetic: without it this comes
// back [[0],[2],[1]].
$interleaved = [ctl('SAS9300-8i', '0000:80:01.0'), ctl('SAS9300-16i', '0000:00:11.0'),
                ctl('SAS9300-8i', '0000:80:01.0')];
check('a lone group between two same-bucket indices still sorts by input order',
      lsi_group_cards($interleaved, $counts) === [[0], [1], [2]]);

// The gap this whole feature almost fell into: the golden proves the shell
// pipeline can EMIT a groupable pair; this proves the grouper actually
// ACCEPTS what the pipeline emits, using the real index -- not a hand-keyed
// map. fw_load() re-keys board names through fw_normalize() ('SAS9300-16i' ->
// '930016i'); lsi_ioc_counts() and lsi_group_cards() must agree on that same
// normalized key space or this never fires against real data.
$realIdx    = fw_load(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json');
$goldenCtls = json_decode(file_get_contents(__DIR__ . '/expected/storcli_dual.json'), true)['controllers'];
check('the real pipeline output groups against the real index',
      lsi_group_cards($goldenCtls, lsi_ioc_counts($realIdx)) === [[0, 1]]);

echo $fails === 0 ? "card_group: all pass\n" : "card_group: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
