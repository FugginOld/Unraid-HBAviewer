<?PHP
/* Which controllers are the same physical card.
 *
 * A SAS9300-16i is one board carrying two SAS3008 IOCs: two PCI functions, two
 * storcli indices, two die temperature sensors. Everything below the UI is
 * right to see two controllers; only the display should say "one card".
 *
 * The key is card_id, the PCI root port -- the slot. Two controllers sharing
 * one are on one board, because two cards cannot occupy one slot.
 *
 * The guard matters more than the rule. Server boards and risers can put
 * several SLOTS behind one motherboard PCIe switch, in which case two genuinely
 * separate cards share a root port. Merging those is worse than the split
 * display this replaces, so a shared slot alone is never enough: the board must
 * be one the index says carries that many IOCs, and the count must match
 * exactly. Everything unrecognised stays split, which is the old behaviour. */

require_once __DIR__ . '/firmware_index.php';   // fw_normalize()

/* normalized board name (fw_normalize -- same key space fw_load() re-keys the
   index into) => expected IOC count. Absent from the index, or absent from a
   board's entry, means one -- the overwhelmingly common case, and the one that
   cannot merge anything. */
function lsi_ioc_counts(?array $idx): array {
    $out = [];
    foreach (($idx['boards'] ?? []) as $board => $b) {
        if (!is_array($b)) continue;
        $out[fw_normalize((string) $board)] = max(1, (int) ($b['ioc_count'] ?? 1));
    }
    return $out;
}

/* Returns groups of INDICES into $ctls. Groups are sorted by their first
   member, so they appear in the order that member was first seen -- but a
   group's members are NOT necessarily contiguous in $ctls: a lone controller
   between two members of an earlier bucket still emits its own group in
   between, e.g. [16i@X, 8i@Y, 16i@X] -> [[0,2],[1]]. A controller that does
   not group comes back as a group of one, so callers never special-case. */
function lsi_group_cards(array $ctls, array $iocCounts): array {
    // Bucket by slot+board. Both, not just slot: two different boards in one
    // slot cannot be one card, and that shape is exactly what a riser produces.
    $buckets = [];
    foreach ($ctls as $i => $c) {
        $card  = (string) ($c['card_id'] ?? '');
        $board = (string) ($c['board_name'] ?? '');
        // Unresolvable slot: no ancestry, no PCI address, or a backend that
        // reports neither. Two unknowns are not a match, so give each its own
        // bucket keyed by index and let it fall through as a group of one.
        $key = $card === '' ? "?$i" : "$card\0$board";
        $buckets[$key][] = $i;
    }

    $groups = [];
    foreach ($buckets as $members) {
        $board = (string) ($ctls[$members[0]]['board_name'] ?? '');
        $want  = $iocCounts[fw_normalize($board)] ?? 1;
        // Exactly, not "at least": a count that does not match means the
        // topology is not the one this board is known to have, and guessing
        // from there is how two cards become one.
        if (count($members) > 1 && count($members) === $want) {
            $groups[] = $members;
            continue;
        }
        foreach ($members as $i) { $groups[] = [$i]; }
    }

    // Buckets are insertion-ordered but a group of one emitted from a later
    // bucket can precede an earlier index, so sort by first member to restore
    // enumeration order -- the Overview renders in the order the composer found
    // the cards and must keep doing so.
    usort($groups, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    return $groups;
}

/* Groups, each with the member whose reading should stand for it.

   A card gets ONE tile on the dashboard and a dual-IOC board has two dies, so
   something has to choose which temperature is shown. The hottest: it is the
   one that will trip a threshold, and it is what the status colour derives
   from. Building the whole view from that member -- rather than taking the max
   number and the band from somewhere else -- is what keeps the gauge, the
   band, the pill colour and the number all describing the same die.

   `key` is the FIRST member's index, always, even when the representative is
   the second. Unraid persists dashboard layout per tile key; keying on the
   representative would move a user's tile when the other die got hotter. */
function lsi_group_reps(array $ctls, array $iocCounts): array {
    $out = [];
    foreach (lsi_group_cards($ctls, $iocCounts) as $g) {
        $rep = $g[0];
        foreach ($g as $m) {
            if ((int) ($ctls[$m]['temp'] ?? 0) > (int) ($ctls[$rep]['temp'] ?? 0)) $rep = $m;
        }
        $out[] = ['members' => $g, 'key' => $g[0], 'rep' => $rep];
    }
    return $out;
}
