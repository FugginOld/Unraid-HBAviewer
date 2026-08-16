<?PHP
/* Known-firmware verdict — is this card running current IT firmware?
 *
 * Deliberately conservative: every path that could produce a WRONG "out of
 * date" verdict returns a suppressed status instead. A missed update notice is
 * harmless. Telling someone to reflash a correctly configured card is not, and
 * on an OEM-rebranded adapter the operation being suggested is not even the one
 * described — it is a crossflash.
 *
 * Seven states, not a boolean: "not current" and "should update" are different
 * claims, and "no IT firmware exists at any version" is a third thing again.
 *
 * The index is read from disk and nothing here touches the network. Facts that
 * drive a hard verdict live on branches marked terminal (P16, P20), which by
 * definition receive no new version, so a bundled file cannot go stale in a way
 * that matters. A wrong entry is fixed by a release.
 *
 * Verdict strings (reason, note) are NOT pre-escaped. board/chip come straight
 * from the HBA's own ROM product string -- the only untrusted content that
 * reaches any verdict field -- and reason/note quote them. A renderer must
 * htmlspecialchars() before printing.
 *
 * Pure functions only above the CLI guard, so tests can require this file. */

const FW_INDEX_FILE = __DIR__ . '/data/known-firmware.json';

/* Collapse the two naming conventions to one key. SAS3 and earlier report
   'SAS9305-24i'; SAS3.5 reports 'HBA 9400-16i'. Both must find their board. */
function fw_normalize(string $name): string {
    $n = strtolower($name);
    $n = (string) preg_replace('/^(sas|hba)\s*/', '', $n);
    return (string) preg_replace('/[^a-z0-9]/', '', $n);
}

/* Dotted-quad compare, shorter side zero-padded. NEVER used on NVDATA, whose
   format varies ('24.00.00.22' on 9400, hex-style '0F.0b.91.xx' on 9405W
   multipath profiles) and which is not ordered at all. Both operands must be
   a bare dotted-quad: $fw is shape-guarded at fw_evaluate()'s one call site
   below; $latest comes from the index and is guaranteed by
   tests/firmware_index_test.php's version-shape assertion rather than at
   runtime -- intval() on a non-numeric part silently reads as 0 rather than
   failing, so a bad hand-edited index entry would otherwise mis-sort silently
   instead of failing the test suite. */
function fw_compare(string $a, string $b): int {
    $pa = array_map('intval', explode('.', $a));
    $pb = array_map('intval', explode('.', $b));
    $n  = max(count($pa), count($pb));
    for ($i = 0; $i < $n; $i++) {
        $x = $pa[$i] ?? 0;
        $y = $pb[$i] ?? 0;
        if ($x !== $y) return $x <=> $y;
    }
    return 0;
}

/* Read the index and re-key its boards on their normalized form, so lookup is
   convention-agnostic. Returns null on anything unreadable or shapeless — the
   caller renders 'unknown' rather than guessing.
   Memoized by resolved path: fw_evaluate()'s two-arg signature exists so a
   request evaluating many controllers reads the file once, not once per
   controller — but every caller that still calls fw_load() itself (view.php's
   lsi_hba_view() does, once per card) would otherwise re-read and re-parse the
   same ~14KB file per card regardless. The cache lives here, the one function
   every path already goes through, so the win reaches all of them without
   touching a single call site. A miss (unreadable/malformed) is cached too —
   a missing index should not be re-stat'd per controller either. */
function fw_load(?string $path = null): ?array {
    static $cache = [];
    $p = $path ?? FW_INDEX_FILE;
    if (array_key_exists($p, $cache)) return $cache[$p];
    if (!is_readable($p)) return $cache[$p] = null;
    $raw = json_decode((string) @file_get_contents($p), true);
    if (!is_array($raw) || empty($raw['boards']) || !is_array($raw['boards'])) return $cache[$p] = null;
    $keyed = [];
    foreach ($raw['boards'] as $name => $b) {
        if (!is_array($b)) continue;
        $b['_display_name'] = $name;
        $keyed[fw_normalize((string) $name)] = $b;
    }
    $raw['boards'] = $keyed;
    return $cache[$p] = $raw;
}

/* Which version this board is measured against. A resolved ROM profile has its
   own track; without one, the board's standard track. A profile key that
   EXISTS but carries no 'version' (a plain filename string, as on HBA
   9400-16i, rather than an object) returns null instead of silently falling
   back to latest_it -- that fallback would compare a resolved special-purpose
   profile against the wrong track's number. */
function fw_track_version(array $b, ?string $profile): ?string {
    if ($profile !== null && !empty($b['rom_profiles'])) {
        $p = $b['rom_profiles'][$profile] ?? null;
        $v = is_array($p) ? ($p['version'] ?? null) : null;
        return ($v !== null && $v !== '') ? (string) $v : null;
    }
    return isset($b['latest_it']) ? (string) $b['latest_it'] : null;
}

/* $ctl keys: board, chip, firmware, subvendor_id, topology, rom_profile.
   $idx is required: the caller loads it once via fw_load() and passes it in,
   so evaluating many controllers does not re-read the file per controller.
   Pass null only when the index is genuinely unavailable -- that is the
   documented "index unreadable" gate below, not a convenience default. */
function fw_evaluate(array $ctl, ?array $idx): array {
    $board    = (string) ($ctl['board']        ?? '');
    $chip     = (string) ($ctl['chip']         ?? '');
    $fw       = (string) ($ctl['firmware']     ?? '');
    $subven   = strtolower((string) ($ctl['subvendor_id'] ?? ''));
    $topology = (string) ($ctl['topology']     ?? 'unknown');
    $profile  = isset($ctl['rom_profile']) ? (string) $ctl['rom_profile'] : null;

    // Built before gate 1 and merged into every return, including it: a
    // renderer reads $verdict['detected'] unconditionally, and an undefined
    // index on a missing-index verdict is a warning Unraid's webgui can leak.
    $base = ['detected' => $fw, 'index_date' => null];

    // Gate 1 — index unreadable.
    if ($idx === null) {
        return ['status' => 'unknown', 'reason' => 'the firmware index could not be read'] + $base;
    }
    $base['index_date'] = isset($idx['updated']) ? (string) $idx['updated'] : null;

    // Gate 2 — OEM rebrand, or a subvendor we couldn't read at all. The most
    // consequential suppression in the file: an M1015 or an H310 carries
    // different NVDATA and BIOS, and reaching the generic version is a
    // crossflash, a different and riskier operation. An unreadable
    // subvendor_id ('') is exactly as out-of-scope as a confirmed OEM one --
    // never treated as "assume generic", per scripts/lib.sh's contract that
    // an unreadable attribute yields empty and suppresses, not a default that
    // happens to look generic.
    if ($subven !== '0x1000') {
        return ['status' => 'oem_out_of_scope', 'reason' =>
            'OEM-rebranded adapter — the index covers generic Broadcom images only, '
          . 'and reaching one from here would be a crossflash, not an upgrade'] + $base;
    }

    // Gate 3 — RAID-on-Chip. No IT firmware exists at any version and the part
    // cannot be crossflashed to one. "Never", not "not yet known".
    foreach (($idx['no_it_firmware'] ?? []) as $roc => $_ignored) {
        if ($roc === '_comment' || $chip === '') continue;
        if (stripos($chip, (string) $roc) !== false) {
            return ['status' => 'no_it_firmware', 'reason' =>
                "$roc is a RAID-on-Chip part — no IT firmware exists at any version"] + $base;
        }
    }

    // Gate 4 — not indexed.
    $key = fw_normalize($board);
    if ($key === '' || !isset($idx['boards'][$key])) {
        return ['status' => 'unknown', 'reason' =>
            "this board is not in the index" . ($board !== '' ? " ($board)" : '')] + $base;
    }
    $b = $idx['boards'][$key];

    // Gate 5 — indexed, but no IT firmware published.
    if (empty($b['it_capable'])) {
        return ['status' => 'no_it_firmware', 'reason' =>
            'no IT firmware is published for this board'] + $base;
    }

    // Gate 6 — multipath. These boards run an independent version track, so a
    // card on it correctly reports a version far below the standard branch.
    // is_array guards a hand edit that dropped the array brackets entirely;
    // is_string then filters a hand-edited non-string element. Either shape
    // fault would otherwise TypeError inside the verdict path.
    $affected = $idx['multipath_track']['affected_boards'] ?? [];
    $mpBoards = array_filter(is_array($affected) ? $affected : [], 'is_string');
    $mp = array_map('fw_normalize', $mpBoards);
    if (in_array($key, $mp, true) && $topology !== 'internal') {
        return ['status' => 'suppressed', 'reason' =>
            'this board has a separate multi-path firmware track, and the topology '
          . 'could not be confirmed as internal — a multi-path card correctly runs '
          . 'a version well below the standard branch'] + $base;
    }

    // Gate 7 — unresolved OR unrecognised ROM profile. The same version ships
    // in incompatible capability profiles, so the number alone proves little,
    // and a profile string the index doesn't recognise must not silently fall
    // through and get compared against the standard track's number.
    if (!empty($b['rom_profiles']) && ($profile === null || !isset($b['rom_profiles'][$profile]))) {
        return ['status' => 'suppressed', 'reason' =>
            'the installed ROM profile could not be determined, and this board ships '
          . 'the same version in profiles with different capabilities'] + $base;
    }

    // Gate 8 — compare.
    $latest = fw_track_version($b, $profile);
    if ($latest === null) {
        return ['status' => 'unknown', 'reason' => 'no known version for this board'] + $base;
    }
    // The detected string must be a bare dotted-quad before it reaches
    // fw_compare(). An empty string (storcli's "unreadable" sentinel), the
    // literal 'Unknown' (scripts/parse/hba.sh's undecoded-hex sentinel), or a
    // whole banner like 'MPTFW-15.00.00.00-IT' would otherwise intval() its
    // non-numeric parts to 0 and compare as 0.0.0.0 -- a false BEHIND.
    if (!preg_match('/^\d+(\.\d+)*\z/', $fw)) {
        return ['status' => 'unknown', 'reason' => 'no usable firmware version detected on the adapter'] + $base;
    }

    $branch = isset($b['branch']) ? (string) $b['branch'] : null;
    $meta = $base + [
        'latest'     => $latest,
        'branch'     => $branch,
        'terminal'   => $branch !== null && !empty($idx['branches'][$branch]['terminal']),
        'confidence' => (string) ($b['confidence'] ?? 'unknown'),
        'note'       => isset($b['notes']) ? (string) $b['notes'] : null,
    ];

    $cmp = fw_compare($fw, $latest);
    if ($cmp === 0) return ['status' => 'current', 'reason' => null] + $meta;
    if ($cmp > 0)   return ['status' => 'ahead', 'reason' =>
        'this adapter is newer than the index — the index is stale, not the card'] + $meta;
    return ['status' => 'behind', 'reason' => null] + $meta;
}

/* Colour is reserved for a TERMINAL branch, in BOTH directions. On a
   non-terminal branch the known version is a floor, not a ceiling: "behind" is
   informational rather than something to act on, and "current" is not proof of
   current — the index's own observed-floor comment says so, and the 9500-8i,
   9500-16i (P28) and 9400-8i (P24) all sit on such a branch. A hard green tick
   there overstates data the index declines to guarantee, and the Overview has
   no confidence line to carry the caveat. Hexes match chrome.css's palette. */
function fw_verdict_color(array $v): string {
    $s = $v['status'] ?? '';
    if (empty($v['terminal'])) return '';
    if ($s === 'current') return '#3fb950';
    if ($s === 'behind')   return '#d29922';
    return '';
}
