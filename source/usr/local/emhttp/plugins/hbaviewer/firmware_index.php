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
   multipath profiles) and which is not ordered at all. */
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
   caller renders 'unknown' rather than guessing. */
function fw_load(?string $path = null): ?array {
    $p = $path ?? FW_INDEX_FILE;
    if (!is_readable($p)) return null;
    $raw = json_decode((string) @file_get_contents($p), true);
    if (!is_array($raw) || empty($raw['boards']) || !is_array($raw['boards'])) return null;
    $keyed = [];
    foreach ($raw['boards'] as $name => $b) {
        if (!is_array($b)) continue;
        $b['_display_name'] = $name;
        $keyed[fw_normalize((string) $name)] = $b;
    }
    $raw['boards'] = $keyed;
    return $raw;
}

/* Which version this board is measured against. A resolved ROM profile has its
   own track; without one, the board's standard track. */
function fw_track_version(array $b, ?string $profile): ?string {
    if ($profile !== null && !empty($b['rom_profiles'][$profile]['version'])) {
        return (string) $b['rom_profiles'][$profile]['version'];
    }
    return isset($b['latest_it']) ? (string) $b['latest_it'] : null;
}

/* $ctl keys: board, chip, firmware, subvendor_id, topology, rom_profile.
   Pass $idx to avoid re-reading the file per controller. */
function fw_evaluate(array $ctl, ?array $idx = null): array {
    $board    = (string) ($ctl['board']        ?? '');
    $chip     = (string) ($ctl['chip']         ?? '');
    $fw       = (string) ($ctl['firmware']     ?? '');
    $subven   = strtolower((string) ($ctl['subvendor_id'] ?? ''));
    $topology = (string) ($ctl['topology']     ?? 'unknown');
    $profile  = isset($ctl['rom_profile']) ? (string) $ctl['rom_profile'] : null;

    if ($idx === null) {
        return ['status' => 'unknown', 'reason' => 'the firmware index could not be read'];
    }
    $date = isset($idx['updated']) ? (string) $idx['updated'] : null;
    $base = ['detected' => $fw, 'index_date' => $date];

    // Gate 2 — OEM rebrand. The most consequential suppression in the file: an
    // M1015 or an H310 carries different NVDATA and BIOS, and reaching the
    // generic version is a crossflash, a different and riskier operation.
    if ($subven !== '' && $subven !== '0x1000') {
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
    $mp = array_map('fw_normalize', $idx['multipath_track']['affected_boards'] ?? []);
    if (in_array($key, $mp, true) && $topology !== 'internal') {
        return ['status' => 'suppressed', 'reason' =>
            'this board has a separate multi-path firmware track, and the topology '
          . 'could not be confirmed as internal — a multi-path card correctly runs '
          . 'a version well below the standard branch'] + $base;
    }

    // Gate 7 — unresolved ROM profile. The same version ships in incompatible
    // capability profiles, so the number alone proves little.
    if (!empty($b['rom_profiles']) && $profile === null) {
        return ['status' => 'suppressed', 'reason' =>
            'the installed ROM profile could not be determined, and this board ships '
          . 'the same version in profiles with different capabilities'] + $base;
    }

    // Gate 8 — compare.
    $latest = fw_track_version($b, $profile);
    if ($latest === null) {
        return ['status' => 'unknown', 'reason' => 'no known version for this board'] + $base;
    }
    if ($fw === '') {
        return ['status' => 'unknown', 'reason' => 'no firmware version detected on the adapter'] + $base;
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

/* Amber is reserved for a TERMINAL branch. On a non-terminal branch the known
   version is a floor, not a ceiling, so "behind" is informational rather than
   something to act on. Hexes match chrome.css's status palette. */
function fw_verdict_color(array $v): string {
    $s = $v['status'] ?? '';
    if ($s === 'current') return '#3fb950';
    if ($s === 'behind' && !empty($v['terminal'])) return '#d29922';
    return '';
}
