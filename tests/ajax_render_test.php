<?PHP
/* Runnable checks for the ajax_info.php render layer: the table builders behind
   the PHY / Drives / Event Log / SMART tabs. These are the ~250 lines that
   produce what the user actually looks at, and until now nothing covered them.
   No HTTP, no hardware — ajax_info.php returns early under CLI, so requiring it
   loads the render functions without running any dispatch.
     php tests/ajax_render_test.php  ->  "ajax_render: all pass" (exit 0) */

/* If ajax_info.php ever stops returning early under CLI, requiring it will run
   the real dispatch and exit(0) mid-require — every assertion below would be
   skipped and this file would report a clean pass having tested nothing. Fail
   loudly instead. */
$completed = false;
register_shutdown_function(function () use (&$completed) {
    if (!$completed) {
        fwrite(STDERR, "ajax_render: ABORTED before assertions ran — ajax_info.php did not return early under CLI\n");
        exit(1);
    }
});

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php';

$fails = 0;
/* "Is this column present", asked without pinning the header's markup. These
   checks used to match '<th>Name</th>' literally, and P2-B -- which wrapped
   every header in a sort button -- failed seventeen of them at once while
   every column was exactly where it had always been. What they mean is the
   column, not the tag around it. */
function hasCol(string $html, string $name): bool {
    return (bool) preg_match('~<th(?:\s[^>]*)?>(?:<[^>]+>)*\s*' . preg_quote($name, '~') . '\s*<~', $html);
}
/* "Is column $a to the left of column $b", and BOTH have to be there.
   These were three strpos() comparisons on literal '<th>Name</th>' markup, and
   they had a second problem beyond the markup: a missing column makes strpos()
   return false, false coerces to 0, and `false < 12` is TRUE -- so the check
   passed most loudly exactly when the column it names had disappeared. Asking
   for both positions explicitly is what closes that. */
function colBefore(string $html, string $a, string $b): bool {
    if (!hasCol($html, $a) || !hasCol($html, $b)) return false;
    $pa = colPos($html, $a);
    $pb = colPos($html, $b);
    return $pa !== null && $pb !== null && $pa < $pb;
}
function colPos(string $html, string $name): ?int {
    $re = '~<th(?:\s[^>]*)?>(?:<[^>]+>)*\s*' . preg_quote($name, '~') . '\s*<~';
    return preg_match($re, $html, $m, PREG_OFFSET_CAPTURE) ? (int) $m[0][1] : null;
}
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

/* ── the render functions are reachable at all ───────────────────────────── */
check('phy fn exists',    function_exists('renderPhyTables'));
check('drives fn exists', function_exists('renderDrivesTables'));
check('events fn exists', function_exists('renderEventsTables'));
check('smart fn exists',  function_exists('renderSmartTable'));

/* ── PHY: the backend field picks the column set (no key-sniffing) ────────── */
$phyStorcli = ['backend' => 'storcli', 'controllers' => [['phys' => [
    ['phy'=>0,'link'=>'up','speed'=>'12.0Gb/s','sas_addr'=>'5000cca0','inv'=>0,'disp'=>0,'sync'=>0,'reset'=>0],
    ['phy'=>1,'link'=>'down','speed'=>'unknown','sas_addr'=>'','inv'=>3,'disp'=>1,'sync'=>0,'reset'=>0],
]]]];
$h = renderPhyTables($phyStorcli);
check('phy storcli has speed col',   hasCol($h, 'Speed'));
check('phy storcli has sas col',     hasCol($h, 'Attached SAS Address'));
check('phy storcli link up badge',   str_contains($h, 'lu-link-up'));
check('phy storcli link down badge', str_contains($h, 'lu-link-down'));
check('phy storcli flags errors',    str_contains($h, 'lu-err-val'));
check('phy storcli uppercases sas',  str_contains($h, '5000CCA0'));

$phyLsi = ['backend' => 'lsiutil', 'controllers' => [['phys' => [
    ['phy'=>0,'link'=>'up','inv'=>1,'disp'=>2,'sync'=>3,'reset'=>4],
]]]];
$h = renderPhyTables($phyLsi);
check('phy lsiutil omits speed col', !hasCol($h, 'Speed'));
check('phy lsiutil has counters',    hasCol($h, 'Invalid DWords'));

/* ── PHY Device column (issue #11) ────────────────────────────────────────────
   Same join as the Drives tab, one hop further: PHY -> drive (sas_addr prefix on
   storcli, phy index on lsiutil) -> /dev. The drives payload arrives from the
   60s cache, so an empty one must still render the table — with em dashes, not
   a fatal. The storcli address pair below is a real one (plan 027's capture):
   the PHY's and the drive's differ in the last hex digit. */
$phyDrv = ['controllers' => [['drives' => [
    ['slot'=>'0/5','serial'=>'ZA1ABCDE','sas_address'=>'5000CCA25319FB47'],
]]]];
$h = renderPhyTables(['backend'=>'storcli','controllers'=>[['phys'=>[
    ['phy'=>0,'link'=>'up','speed'=>'12.0Gb/s','sas_addr'=>'5000CCA25319FB45','inv'=>0,'disp'=>0,'sync'=>0,'reset'=>0],
    ['phy'=>1,'link'=>'up','speed'=>'12.0Gb/s','sas_addr'=>'5000CCA999999999','inv'=>0,'disp'=>0,'sync'=>0,'reset'=>0],
]]]], [], null, null, $phyDrv, ['ZA1ABCDE' => '/dev/sdg']);
check('phy storcli has device col',   hasCol($h, 'Device'));
check('phy storcli device resolves',  str_contains($h, '<code>/dev/sdg</code>'));
check('phy storcli device follows phy col',
      colBefore($h, 'Device', 'Link'));
// PHY 1 matches no drive (a VirtualSES PHY is the real-world case) — em dash,
// never the drive that happens to sit on the neighbouring PHY.
check('phy storcli unmatched device is em dash', substr_count($h, '<code>/dev/sdg</code>') === 1);
// No drives cached yet: the table still renders, every Device cell blank.
$h = renderPhyTables($phyStorcli);
check('phy device col renders without drives', hasCol($h, 'Device') && !str_contains($h, '/dev/'));

$h = renderPhyTables($phyLsi, [], null, null,
    ['controllers' => [['drives' => [['phy'=>'0','os_name'=>'/dev/sdb']]]]]);
check('phy lsiutil device from os_name', str_contains($h, '<code>/dev/sdb</code>'));

/* ── PHY: degenerate inputs must not fatal ───────────────────────────────── */
check('phy controller error row', str_contains(
    renderPhyTables(['backend'=>'storcli','controllers'=>[['error'=>'no response']]]), 'no response'));
check('phy empty phys', str_contains(
    renderPhyTables(['backend'=>'storcli','controllers'=>[[]]]), 'No PHY data.'));
check('phy multi heads controllers', str_contains(
    renderPhyTables(['backend'=>'storcli','controllers'=>[['phys'=>[]],['phys'=>[]]]]), 'Controller /c1'));
check('phy single omits head', !str_contains(
    renderPhyTables(['backend'=>'storcli','controllers'=>[['phys'=>[]]]]), 'Controller /c0'));

/* The backend field is the ONLY input that picks columns. CONTEXT.md says so.
   These are characterization checks, not regression ones: the key-sniff they
   replaced could only fire when `backend` was absent entirely, which no live
   payload is -- hba_each stamps both paths and the {"error":…} payload returns
   before any renderer runs. First pair: a stated backend decides even when the
   keys look like the other one. Second pair: with NO backend at all, the
   renderers now fall to the lsiutil table rather than guessing from keys. */
$sniffBait = ['backend' => 'lsiutil', 'controllers' => [['phys' => [
    ['phy' => 0, 'link' => 'up', 'speed' => '12.0 Gbps', 'sas_addr' => 'AABB',
     'inv' => 0, 'disp' => 0, 'sync' => 0, 'reset' => 0],
]]]];
$sniffOut = renderPhyTables($sniffBait);
check('phy: stated backend wins over storcli-looking keys',
    str_contains($sniffOut, 'Invalid DWords') && !str_contains($sniffOut, 'Attached SAS Address'));

$drvBait = ['backend' => 'lsiutil', 'controllers' => [['drives' => [
    ['slot' => '0', 'model' => 'X', 'serial' => 'S', 'state' => 'JBOD',
     'sas_address' => 'AABB', 'size' => '1 TB', 'link' => '12.0Gb/s', 'firmware' => 'A'],
]]]];
// 'Encl:Slot' is the storcli drives header and 'Bus:Tgt' the lsiutil one --
// those are the discriminators. ('Enclosure' is NOT: it appears only in a PHY
// topology summary, so asserting on it passes on both branches and tests
// nothing.) Asserting both directions proves which table rendered, not merely
// which one did not.
$drvOut = renderDrivesTables($drvBait);
check('drives: stated backend wins over storcli-looking keys',
    str_contains($drvOut, 'Bus:Tgt') && !str_contains($drvOut, 'Encl:Slot'));

// No backend stated: no guessing. These two FAIL before the deletion and pass
// after, which is the only behavioural difference the change makes.
$noBackendPhy = ['controllers' => [['phys' => [
    ['phy' => 0, 'link' => 'up', 'speed' => '12.0 Gbps', 'sas_addr' => 'AABB',
     'inv' => 0, 'disp' => 0, 'sync' => 0, 'reset' => 0],
]]]];
$noBackendPhyOut = renderPhyTables($noBackendPhy);
check('phy: an unstamped payload does not sniff its way to storcli columns',
    str_contains($noBackendPhyOut, 'Invalid DWords') && !str_contains($noBackendPhyOut, 'Attached SAS Address'));

$noBackendDrv = ['controllers' => [['drives' => [
    ['slot' => '0', 'model' => 'X', 'serial' => 'S', 'state' => 'JBOD',
     'sas_address' => 'AABB', 'size' => '1 TB', 'link' => '12.0Gb/s', 'firmware' => 'A'],
]]]];
$noBackendDrvOut = renderDrivesTables($noBackendDrv);
check('drives: an unstamped payload does not sniff its way to storcli columns',
    str_contains($noBackendDrvOut, 'Bus:Tgt') && !str_contains($noBackendDrvOut, 'Encl:Slot'));

// 'Qualifier' is the lsiutil events header and 'Code' the storcli one -- the
// entries below are storcli-shaped (seq/time/code/description) but the
// payload carries no 'backend' key, so an unstamped payload must not sniff
// the entry shape into rendering the storcli table.
$evSniffDir = sys_get_temp_dir() . '/hbav_events_sniff_' . getmypid();
@mkdir($evSniffDir, 0755, true);
array_map('unlink', glob("$evSniffDir/*.json") ?: []);
$noBackendEv = ['controllers' => [['entries' => [
    ['seq' => '1', 'time' => '2026-07-01 10:00:00', 'code' => '0x0113', 'description' => 'Drive inserted'],
]]]];
$noBackendEvOut = renderEventsTables($noBackendEv, $evSniffDir);
check('events: an unstamped payload does not sniff its way to storcli columns',
    str_contains($noBackendEvOut, 'Qualifier') && !str_contains($noBackendEvOut, 'Code'));
array_map('unlink', glob("$evSniffDir/*.json") ?: []);
@rmdir($evSniffDir);
// A storcli2 payload must reach the storcli tables. Before lsi_backend_shape
// existed it fell through to the lsiutil branch, because the field matched
// neither 'storcli' nor ''.
$sc2Phy = ['backend' => 'storcli2', 'controllers' => [['phys' => [
    ['phy' => 0, 'link' => 'up', 'speed' => '22.5 Gbps', 'sas_addr' => 'AABB',
     'inv' => 0, 'disp' => 0, 'sync' => 0, 'reset' => 0],
]]]];
check('phy: a storcli2 payload gets the storcli columns',
    str_contains(renderPhyTables($sc2Phy), 'Attached SAS Address'));

/* ── PHY error baseline: the three display states (plan 022) ──────────────
   The raw-counter table is unchanged by this feature — it is purely additive,
   so the no-baseline case must render exactly what it always did. */
$phyBase = ['backend' => 'storcli', 'controllers' => [['phys' => [
    ['phy'=>0,'link'=>'up','speed'=>'12.0Gb/s','sas_addr'=>'5000cca0','inv'=>250,'disp'=>0,'sync'=>0,'reset'=>0],
]]]];

// (a) no baseline -> raw only, and NOT a delta of zero (which would read as
// "no errors" rather than "no reference point").
$h = renderPhyTables($phyBase, [], 4600, 9000);
check('phy no baseline omits delta', !str_contains($h, 'lu-phy-delta'));
check('phy no baseline offers button', str_contains($h, 'Set Baseline')
                                    && str_contains($h, 'luPhyBaseline(0, this)'));

// (b) baseline an hour old, inv 100 -> 250: delta 150, rate 150/hr.
$bl = ['0:0' => ['inv'=>100,'disp'=>0,'sync'=>0,'reset'=>0,'ts'=>1000,'up'=>5000]];
$h  = renderPhyTables($phyBase, $bl, 1000 + 3600, 5000 + 3600);
// The HEADLINE is the count since the baseline; the driver's cumulative
// counter is demoted to its own line. Resetting the baseline therefore sends
// the column to 0, which is what pressing the button looks like it does.
check('phy delta is the headline', str_contains($h, '>150</span>')
                                || str_contains($h, '>150<'));
check('phy cumulative demoted',    str_contains($h, 'since driver load: 250'));
check('phy baseline time shown', str_contains($h, 'Baseline set'));
check('phy offers reset',     str_contains($h, 'Reset Baseline'));
check('phy raw counter kept', str_contains($h, '250'));

// (c) counter restart -> NEVER a negative count anywhere; the bar asks for a
// fresh baseline instead. Both signals, independently.
foreach ([
    'reboot'        => [90000, 120],     // uptime below the stored 5000
    'driver reload' => [90000, 99000],   // uptime fine, counter below baseline
] as $why => [$when, $up]) {
    $cur = $why === 'reboot' ? $phyBase : ['backend'=>'storcli','controllers'=>[['phys'=>[
        ['phy'=>0,'link'=>'up','speed'=>'12.0Gb/s','sas_addr'=>'5000cca0','inv'=>3,'disp'=>0,'sync'=>0,'reset'=>0],
    ]]]];
    $h = renderPhyTables($cur, $bl, $when, $up);
    check("phy $why shows stale note", str_contains($h, 'lu-phy-stale'));
    check("phy $why omits delta",      !str_contains($h, 'lu-phy-delta'));
    check("phy $why has no negative",  !preg_match('/&Delta;-|-\d+\/hr/', $h));
    // The note says "press Reset Baseline"; the button must say the same words.
    check("phy $why button matches note", str_contains($h, '>Reset Baseline</button>')
                                       && !str_contains($h, '>Set Baseline</button>'));
}

// A stale controller must not poison a healthy one on the same card list.
$two = ['backend' => 'storcli', 'controllers' => [
    ['phys' => [['phy'=>0,'link'=>'up','speed'=>'12.0Gb/s','sas_addr'=>'','inv'=>250,'disp'=>0,'sync'=>0,'reset'=>0]]],
    ['phys' => [['phy'=>0,'link'=>'up','speed'=>'12.0Gb/s','sas_addr'=>'','inv'=>1,  'disp'=>0,'sync'=>0,'reset'=>0]]],
]];
$bl2 = $bl + ['1:0' => ['inv'=>100,'disp'=>0,'sync'=>0,'reset'=>0,'ts'=>1000,'up'=>5000]];
$h = renderPhyTables($two, $bl2, 1000 + 3600, 5000 + 3600);
check('phy per-controller isolation', substr_count($h, 'lu-phy-stale') === 1
                                   && substr_count($h, 'lu-phy-delta') === 4);

// The lsiutil column set carries the baseline too — one mechanism, both backends.
$h = renderPhyTables(['backend'=>'lsiutil','controllers'=>[['phys'=>[
    ['phy'=>0,'link'=>'up','inv'=>250,'disp'=>0,'sync'=>0,'reset'=>0],
]]]], $bl, 1000 + 3600, 5000 + 3600);
check('phy lsiutil delta rendered', str_contains($h, '>150<')
                                && str_contains($h, 'since driver load: 250'));

// The point of the whole feature, stated as a test: a link that was bad and
// has been fixed reads ZERO and is no longer flagged, while its cumulative
// counter -- which no baseline can clear -- stays visible underneath.
$blFixed = ['0:0' => ['inv'=>250,'disp'=>0,'sync'=>0,'reset'=>0,'ts'=>1000,'up'=>5000]];
$hFixed  = renderPhyTables($phyBase, $blFixed, 1000 + 3600, 5000 + 3600);
check('a fixed link reads zero',       str_contains($hFixed, '>0<'));
check('a fixed link is not flagged',   !str_contains($hFixed, 'lu-err-val'));
check('a fixed link keeps its total',  str_contains($hFixed, 'since driver load: 250'));

/* ── PHY top offenders (plan 027): the SAS-address join and the ranking ─────
   phy_drive_label() address pairs below are the real capture from the plan
   (9400-16i + 9400-8i, 24 drives): the PHY's sas_addr and the drive's
   sas_address are two ports of one dual-ported device and differ only in the
   LAST hex digit, by a vendor-specific, non-fixed offset — so the join
   compares the first 15 hex digits, uppercased. */
check('phy_drive_label fn exists',      function_exists('phy_drive_label'));
check('phy_drive_label storcli exact pair', phy_drive_label(
    [['slot' => '0/12', 'sas_address' => '5000CCA25319FB47']],
    ['phy' => 0, 'sas_addr' => '5000CCA25319FB45']
) === '0/12');
// Seagate: -1 on the last nibble.
check('phy_drive_label storcli seagate offset', phy_drive_label(
    [['slot' => '0/13', 'sas_address' => '5000C500AEBADCE8']],
    ['phy' => 1, 'sas_addr' => '5000C500AEBADCE9']
) === '0/13');
// Toshiba: -2 on the last nibble — proves no fixed offset is assumed.
check('phy_drive_label storcli toshiba offset', phy_drive_label(
    [['slot' => '0/15', 'sas_address' => '50000399384073B0']],
    ['phy' => 2, 'sas_addr' => '50000399384073B2']
) === '0/15');
// Collision guard: two drives share the 15-digit prefix -> null, never a guess.
check('phy_drive_label storcli prefix collision -> null', phy_drive_label(
    [
        ['slot' => '0/12', 'sas_address' => '5000CCA25319FB47'],
        ['slot' => '0/99', 'sas_address' => '5000CCA25319FB4A'],
    ],
    ['phy' => 0, 'sas_addr' => '5000CCA25319FB45']
) === null);
check('phy_drive_label storcli no match -> null', phy_drive_label(
    [['slot' => '0/1', 'sas_address' => '5000C500FFFFFFFF']],
    ['phy' => 0, 'sas_addr' => '5000CCA25319FB45']
) === null);
check('phy_drive_label lsiutil phy match', phy_drive_label(
    [['phy' => 2, 'os_name' => '/dev/sda'], ['phy' => 3, 'os_name' => '/dev/sdb']],
    ['phy' => 3]
) === '/dev/sdb');
check('phy_drive_label case-insensitive sas_address', phy_drive_label(
    [['slot' => '0/12', 'sas_address' => '5000cca25319fb47']],
    ['phy' => 0, 'sas_addr' => '5000CCA25319FB45']
) === '0/12');
/* Issue #11: with a serial map the offender row names the bay AND the /dev the
   rest of Unraid calls it. Without one it still names the bay — the map is
   empty while the drives cache warms, and a nameless offender is no worse than
   the row that shipped before. */
check('phy_drive_label adds the dev name', phy_drive_label(
    [['slot' => '0/12', 'serial' => 'ZA1ABCDE', 'sas_address' => '5000CCA25319FB47']],
    ['phy' => 0, 'sas_addr' => '5000CCA25319FB45'],
    ['ZA1ABCDE' => '/dev/sdg']
) === '0/12 · /dev/sdg');
check('phy_drive fn exposes the matched drive',
    (phy_drive([['slot' => '0/12', 'sas_address' => '5000CCA25319FB47']],
               ['phy' => 0, 'sas_addr' => '5000CCA25319FB45'])['slot'] ?? null) === '0/12');

check('phy_top_offenders fn exists', function_exists('phy_top_offenders'));
$rate = fn(int $inv) => ['reset' => false, 'delta' => ['inv' => $inv, 'disp' => 0, 'sync' => 0, 'reset' => 0],
                          'rate' => ['inv' => (float) $inv, 'disp' => 0.0, 'sync' => 0.0, 'reset' => 0.0]];

$off = phy_top_offenders([['phy' => 0], ['phy' => 1]], [0 => $rate(10), 1 => $rate(50)], []);
check('phy_top_offenders ranks descending', $off[0]['phy'] === 1 && $off[1]['phy'] === 0);

$off = phy_top_offenders([['phy' => 2], ['phy' => 1]], [0 => $rate(10), 1 => $rate(10)], []);
check('phy_top_offenders ties break by phy index ascending', $off[0]['phy'] === 1 && $off[1]['phy'] === 2);

$off = phy_top_offenders([['phy' => 0], ['phy' => 1]], [0 => null, 1 => $rate(5)], []);
check('phy_top_offenders excludes null delta (not present at 0)', count($off) === 1 && $off[0]['phy'] === 1);

$off = phy_top_offenders([['phy' => 0], ['phy' => 1]], [0 => ['reset' => true], 1 => $rate(5)], []);
check('phy_top_offenders excludes stale reset', count($off) === 1 && $off[0]['phy'] === 1);

check('phy_top_offenders excludes measured all-zero',
      phy_top_offenders([['phy' => 0]], [0 => $rate(0)], []) === []);

$physN = []; $deltasN = [];
for ($k = 0; $k < 10; $k++) { $physN[] = ['phy' => $k]; $deltasN[$k] = $rate($k + 1); }
check('phy_top_offenders honours limit', count(phy_top_offenders($physN, $deltasN, [], 3)) === 3);

$warned = false;
set_error_handler(function () use (&$warned) { $warned = true; return true; });
$offEmpty = phy_top_offenders([], [], []);
restore_error_handler();
check('phy_top_offenders empty phys/deltas -> []', $offEmpty === []);
check('phy_top_offenders empty input stays quiet', $warned === false);

// A PHY whose drive does not resolve (no drives payload at all) still ranks.
$off = phy_top_offenders([['phy' => 5, 'sas_addr' => '5000CCA25319FB45']], [0 => $rate(3)], []);
check('phy_top_offenders unresolved drive still ranks', count($off) === 1 && $off[0]['drive'] === null);

/* ── PHY tab (plan 050): the baseline rate is a long-run average, and the
   Health tab's own ring, read READ-ONLY, can show a far more recent one
   beside it. luPhyCell tested directly with fixture $recent/$ringSpanSecs —
   no disk I/O needed, the whole point of keeping the data assembly
   (phy_recent_rate) separate from the HTML (luPhyCell). ────────────────── */
check('luPhyCell fn exists',       function_exists('luPhyCell'));
check('phy_recent_rate fn exists', function_exists('phy_recent_rate'));

// $v is the driver's cumulative counter, $d['delta'] the count since the
// baseline. They are DELIBERATELY different numbers here: a cell that printed
// the wrong one would still pass if they matched.
$dBase = ['reset' => false, 'delta' => ['inv' => 115], 'rate' => ['inv' => 1.9]];

// No ring at all (null/null): the count since baseline leads, the cumulative
// counter follows on its own line, and nothing about a recent window is claimed.
$cell = luPhyCell(4505, false, $dBase, 'inv', null, null);
check('luPhyCell leads with the count since baseline', str_contains($cell, '>115<'));
check('luPhyCell names the cumulative counter honestly', str_contains($cell, 'since driver load: 4505'));
check('luPhyCell does not call the cumulative counter a lifetime', !str_contains($cell, 'lifetime'));
check('luPhyCell omits the recent column with no ring', !str_contains($cell, 'in the last'));

// Ring usable, this PHY quiet lately: the recent figure joins the cumulative
// one — the cell answers "since I fixed it" and "lately" at once.
$recentQuiet = ['idx' => 5, 'inv' => 0.0, 'disp' => 0.0, 'sync' => 0.0, 'rst' => 0.0];
$cell = luPhyCell(4505, false, $dBase, 'inv', $recentQuiet, 46189);
check('luPhyCell recent column appears when the ring is usable', str_contains($cell, '0/hr in the last'));
check('luPhyCell keeps the cumulative counter alongside the recent rate',
    str_contains($cell, 'since driver load: 4505') && str_contains($cell, '0/hr in the last'));

// A fault that just started: one tick since baseline flags the cell, even
// though the cumulative counter dwarfs it.
$dQuietHistory = ['reset' => false, 'delta' => ['inv' => 1], 'rate' => ['inv' => 0.1]];
$recentHot     = ['idx' => 5, 'inv' => 40.0, 'disp' => 0.0, 'sync' => 0.0, 'rst' => 0.0];
$cell = luPhyCell(9000, false, $dQuietHistory, 'inv', $recentHot, 600);
check('luPhyCell flags a single new error', str_contains($cell, 'lu-err-val'));
check('luPhyCell still shows the recent rate', str_contains($cell, '40/hr in the last'));

// The inverse, and the case that started this: a big cumulative counter with
// nothing new since the baseline is NOT flagged. The caller still passes
// $err = true from the raw counter; the delta overrides it.
$dFixed = ['reset' => false, 'delta' => ['inv' => 0], 'rate' => ['inv' => 0.0]];
$cell   = luPhyCell(13924, true, $dFixed, 'inv', $recentQuiet, 46189);
check('luPhyCell clears the flag on a fixed link', !str_contains($cell, 'lu-err-val'));
check('luPhyCell reads zero on a fixed link',      str_contains($cell, '>0<'));

// The 'reset' counter is named 'rst' in the Health ring's own rows (sysfs'
// field name) but 'reset' in phy_baseline's (the PHY tab's own field name) —
// luPhyCell must bridge that, not silently show nothing for that column.
$dReset    = ['reset' => false, 'delta' => ['reset' => 2], 'rate' => ['reset' => 0.5]];
$recentRst = ['idx' => 5, 'inv' => 0.0, 'disp' => 0.0, 'sync' => 0.0, 'rst' => 3.0];
$cell = luPhyCell(2, false, $dReset, 'reset', $recentRst, 600);
check('luPhyCell maps the reset counter onto the ring\'s rst key', str_contains($cell, '3.0/hr in the last'));

// No baseline at all: unchanged (no lu-phy-delta div at all) regardless of
// whether a recent rate is available — a raw counter with no reference point
// must not sprout a rate from the ring alone.
check('luPhyCell omits the delta entirely with no baseline',
    luPhyCell(9, false, null, 'inv', $recentHot, 600) === '9');

// phy_recent_rate(): the pure ring lookup, keyed by PHY index, that feeds
// the cells above in renderPhyTables().
$hSample = function (int $t, int $uptime, int $idx, int $inv): array {
    return ['t' => $t, 'uptime' => $uptime, 'phys' => [['idx' => $idx, 'inv' => $inv, 'disp' => 0, 'sync' => 0, 'rst' => 0]]];
};
$ringUsable = [$hSample(1000, 100, 5, 0), $hSample(1000 + 3600, 3700, 5, 36)];
check('phy_recent_rate finds the matching PHY index', phy_recent_rate($ringUsable, 5)['inv'] === 36.0);
check('phy_recent_rate null for a PHY the ring never saw', phy_recent_rate($ringUsable, 9) === null);
check('phy_recent_rate null on an empty ring', phy_recent_rate([], 5) === null);
$ringTooShort = [$hSample(1000, 100, 5, 0), $hSample(1030, 130, 5, 10)];
check('phy_recent_rate null under a 60s ring span', phy_recent_rate($ringTooShort, 5) === null);

// Wiring: renderPhyTables must key the ring read by the CONTROLLER the row
// belongs to (health_store_path($i)), never by position — controller 1's
// recent rate must never leak onto controller 0's row, or vice versa.
$phyRing0 = health_store_path(0);
$phyRing1 = health_store_path(1);
$phyRingSaved0 = is_file($phyRing0) ? file_get_contents($phyRing0) : null;
$phyRingSaved1 = is_file($phyRing1) ? file_get_contents($phyRing1) : null;
health_store_write($phyRing0, [$hSample(1000, 100, 0, 0), $hSample(1000 + 3600, 3700, 0, 72)]);  // ctl 0: 72/hr recent
health_store_write($phyRing1, [$hSample(1000, 100, 0, 0), $hSample(1000 + 3600, 3700, 0, 15)]);  // ctl 1: 15/hr recent

$twoCtlPhy = ['backend' => 'storcli', 'controllers' => [
    ['phys' => [['phy'=>0,'link'=>'up','speed'=>'12.0Gb/s','sas_addr'=>'','inv'=>250,'disp'=>0,'sync'=>0,'reset'=>0]]],
    ['phys' => [['phy'=>0,'link'=>'up','speed'=>'12.0Gb/s','sas_addr'=>'','inv'=>250,'disp'=>0,'sync'=>0,'reset'=>0]]],
]];
$blBothPhy = ['0:0' => ['inv'=>100,'disp'=>0,'sync'=>0,'reset'=>0,'ts'=>1000,'up'=>5000],
              '1:0' => ['inv'=>100,'disp'=>0,'sync'=>0,'reset'=>0,'ts'=>1000,'up'=>5000]];
$h = renderPhyTables($twoCtlPhy, $blBothPhy, 1000 + 3600, 5000 + 3600);
check('phy recent rate keyed per controller, not position',
    str_contains($h, '72/hr in the last') && str_contains($h, '15/hr in the last'));

if ($phyRingSaved0 === null) @unlink($phyRing0); else file_put_contents($phyRing0, $phyRingSaved0);
if ($phyRingSaved1 === null) @unlink($phyRing1); else file_put_contents($phyRing1, $phyRingSaved1);

// Top offenders header now says what the rate answers (plan 050 Step 2) —
// wording, not a golden file, so it belongs beside the offenders it labels.
$offHdr = renderPhyTables(['backend' => 'storcli', 'controllers' => [['phys' => [
    ['phy'=>0,'link'=>'up','speed'=>'12.0Gb/s','sas_addr'=>'','inv'=>250,'disp'=>0,'sync'=>0,'reset'=>0],
]]]], $bl, 1000 + 3600, 5000 + 3600);
check('top offenders header states the rate is an average since baseline',
    str_contains($offHdr, 'Errors/hr — average since baseline'));

/* ── Drives: backend picks columns; enclosure summary renders ────────────── */
$drvStorcli = ['backend' => 'storcli', 'controllers' => [[
    'enclosures' => [['eid'=>'8','product'=>'VirtualSES','vendor'=>'LSI','slots'=>'8','drives'=>'4','direct'=>1]],
    'drives' => [['slot'=>'8/0','port'=>'14','model'=>'ST8000NM','serial'=>'ZA1ABCDE','state'=>'JBOD',
                  'size'=>'7.276 TB','sas_address'=>'5000c500a1b2c3d4','link'=>'12.0Gb/s','firmware'=>'SN02']],
]]];
$h = renderDrivesTables($drvStorcli);
check('drives storcli col set',    hasCol($h, 'Encl:Slot') && hasCol($h, 'Firmware'));
check('drives enclosure summary',  str_contains($h, 'VirtualSES') && str_contains($h, 'direct-attach'));
check('drives smart button',       str_contains($h, 'luSmart(this') && str_contains($h, 'ZA1ABCDE'));
check('drives uppercases sas',     str_contains($h, '5000C500A1B2C3D4'));

// Issue #6: storcli's own enclosure counts (8 slots / 0 drives) are real but
// describe nothing — this controller's drives carry no eid at all, so the
// counts must not render, and the summary must say why.
$drvNoEncl = ['backend' => 'storcli', 'controllers' => [[
    'enclosures' => [['eid'=>'0','product'=>'VirtualSES','vendor'=>'BROADCOM',
                      'slots'=>'8','drives'=>'0','direct'=>1]],
    'drives' => [['slot'=>'0','port'=>'8','model'=>'ST26000NM','serial'=>'ZXA069R6',
                  'state'=>'JBOD','sas_address'=>'5000C500EA001805','size'=>'23.647 TB',
                  'link'=>'12.0Gb/s','firmware'=>'SN02']],
]]];
$h = renderDrivesTables($drvNoEncl);
check('drives no-encl suppresses counts', !str_contains($h, '8 slots') && !str_contains($h, '0 drives'));
check('drives no-encl keeps product/mode', str_contains($h, 'VirtualSES') && str_contains($h, 'direct-attach'));
check('drives no-encl states why', str_contains($h, 'drives are addressed without an enclosure'));
check('drives no-encl row still renders', str_contains($h, 'ZXA069R6'));

// A mixed controller (some drives carry an eid, some don't) keeps its counts —
// some drives really are behind that enclosure.
$drvMixed = ['backend' => 'storcli', 'controllers' => [[
    'enclosures' => [['eid'=>'0','product'=>'VirtualSES','vendor'=>'BROADCOM',
                      'slots'=>'8','drives'=>'1','direct'=>1]],
    'drives' => [
        ['slot'=>'0/1','port'=>'14','model'=>'ST8000NM','serial'=>'ZA1ABCDE','state'=>'JBOD',
         'size'=>'7.276 TB','sas_address'=>'5000c500a1b2c3d4','link'=>'12.0Gb/s','firmware'=>'SN02'],
        ['slot'=>'2','port'=>'15','model'=>'ST8000NM','serial'=>'ZA1FGHIJ','state'=>'JBOD',
         'size'=>'7.276 TB','sas_address'=>'5000c500a1b2c3d5','link'=>'12.0Gb/s','firmware'=>'SN02'],
    ],
]]];
$h = renderDrivesTables($drvMixed);
check('drives mixed keeps counts', str_contains($h, '8 slots') && str_contains($h, '1 drives'));

// An enclosure with no drives array at all has nothing to contradict its
// counts — absence of evidence is not evidence, so the counts stay.
$drvEmptyList = ['backend' => 'storcli', 'controllers' => [[
    'enclosures' => [['eid'=>'0','product'=>'VirtualSES','vendor'=>'BROADCOM',
                      'slots'=>'8','drives'=>'0','direct'=>1]],
    'drives' => [],
]]];
$h = renderDrivesTables($drvEmptyList);
check('drives empty list keeps counts', str_contains($h, '8 slots') && str_contains($h, '0 drives'));

$drvLsi = ['backend' => 'lsiutil', 'controllers' => [['drives' => [
    ['bus'=>'0','target'=>'3','phy'=>'2','sas_address'=>'5000c500a1b2c3d4','os_name'=>'/dev/sdb'],
]]]];
$h = renderDrivesTables($drvLsi);
check('drives lsiutil col set', hasCol($h, 'Bus:Tgt') && hasCol($h, 'Device'));
check('drives lsiutil no smart btn', !str_contains($h, 'luSmart(this'));

/* ── Device column (issue #11): encl:slot and bus:target line up with nothing
   on Unraid's Main page, so every drive/PHY row leads with /dev/sdX. The
   lsiutil backend resolves it itself (os_name); storcli reports no /dev name at
   all and is joined by SERIAL — the WWN differs by a nibble between storcli and
   /dev, the serial matches exactly (same key the SMART button uses). The map is
   injected here, so nothing in this suite shells out to lsblk. */
check('drives lsiutil device is os_name, no map needed', str_contains($h, '<code>/dev/sdb</code>'));
check('drives device column leads the row',
      colBefore($h, 'Device', 'Bus:Tgt'));

$h = renderDrivesTables($drvStorcli, ['ZA1ABCDE' => '/dev/sdg']);
check('drives storcli device by serial', str_contains($h, '<code>/dev/sdg</code>'));

/* The Unraid column: the same slot name on all four surfaces, so a row here can
   be matched against Main without tracking /dev/sdX by eye. */
$hRole = renderDrivesTables($drvStorcli, ['ZA1ABCDE' => '/dev/sdg'], ['/dev/sdg' => 'Disk 1']);
check('drives table has an Unraid column',  hasCol($hRole, 'Unraid'));
check('drives table names the slot',        str_contains($hRole, '<td>Disk 1</td>'));
check('drives lsiutil has an Unraid column',
      hasCol(renderDrivesTables($drvLsi, [], ['/dev/sdb' => 'Parity']), 'Unraid'));
check('drives lsiutil names the slot',
      str_contains(renderDrivesTables($drvLsi, [], ['/dev/sdb' => 'Parity']), '<td>Parity</td>'));
// A drive the array does not use gets an em dash, never a blank cell that reads
// as "not looked up".
check('an unassigned drive shows an em dash',
      substr_count(renderDrivesTables($drvStorcli, ['ZA1ABCDE' => '/dev/sdg'], []), '<span class="lu-muted">—</span>') > 0);

$hSmartRole = renderSmartTable(['drives'=>[['dev'=>'/dev/sdp','serial'=>'X','model'=>'M','smart'=>['health'=>'PASSED']]]],
                               null, ['/dev/sdp' => 'Parity']);
check('SMART table has an Unraid column', hasCol($hSmartRole, 'Unraid'));
check('SMART table names the slot',       str_contains($hSmartRole, '<td>Parity</td>'));

$hPhyRole = renderPhyTables(['backend'=>'storcli','controllers'=>[['phys'=>[
    ['phy'=>0,'link'=>'up','speed'=>'12.0Gb/s','sas_addr'=>'5000CCA25319FB45','inv'=>0,'disp'=>0,'sync'=>0,'reset'=>0],
]]]], [], null, null, $phyDrv, ['ZA1ABCDE' => '/dev/sdg'], ['/dev/sdg' => 'Disk 1']);
check('PHY table has an Unraid column', hasCol($hPhyRole, 'Unraid'));
check('PHY table names the slot',       str_contains($hPhyRole, '<td>Disk 1</td>'));
check('PHY lsiutil table has an Unraid column',
      str_contains(renderPhyTables($phyLsi, [], null, null,
          ['controllers' => [['drives' => [['phy'=>'0','os_name'=>'/dev/sdb']]]]], [], ['/dev/sdb' => 'Parity']),
          '<td>Parity</td>'));
check('drives storcli device leads the row',
      colBefore($h, 'Device', 'Encl:Slot'));
// Serials come off lsblk in whatever case the drive reports; the map is keyed
// uppercase and the lookup must not care.
check('drives storcli device serial match is case-insensitive',
      str_contains(renderDrivesTables(
          ['backend'=>'storcli','controllers'=>[['drives'=>[['slot'=>'0','port'=>'1','model'=>'M','serial'=>'za1abcde',
            'state'=>'JBOD','size'=>'1 TB','sas_address'=>'5000C500A1B2C3D4','link'=>'12.0Gb/s','firmware'=>'F']]]]],
          ['ZA1ABCDE' => '/dev/sdg']), '<code>/dev/sdg</code>'));
// An unmatched serial must render an em dash, never a neighbouring drive's name.
check('drives storcli unmatched device is em dash',
      str_contains(renderDrivesTables($drvStorcli, ['SOMEONEELSE' => '/dev/sdz']), '<span class="lu-muted">—</span>')
      && !str_contains(renderDrivesTables($drvStorcli, ['SOMEONEELSE' => '/dev/sdz']), '/dev/sdz'));

/* ── Bay map join (plan 047) ──────────────────────────────────────────────────
   Three payloads meet here: the drive list, the background SMART cache (keyed
   by serial) and the stored positions (keyed by port/PHY). The load-bearing
   case is the third check: a drive nobody has SMART for must be `nodata`, not
   green — a bay coloured healthy for a drive that was never read is the whole
   failure this feature would introduce if it got that wrong. */
$bmDrives = ['backend' => 'storcli', 'controllers' => [['drives' => [
    ['slot'=>'0/0','port'=>'14','model'=>'ST8000NM','serial'=>'PLACED01','size'=>'7.276 TB'],
    ['slot'=>'0/1','port'=>'15','model'=>'ST8000NM','serial'=>'NOSMART1','size'=>'7.276 TB'],
    ['slot'=>'0/2','port'=>'16','model'=>'ST8000NM','serial'=>'TRAYDRV1','size'=>'7.276 TB'],
]]]];
$bmSmart = ['drives' => [
    ['dev'=>'/dev/sda','serial'=>'PLACED01','smart'=>['health'=>'PASSED','temp'=>'34','defects'=>'0','pending'=>'0']],
    ['dev'=>'/dev/sdc','serial'=>'TRAYDRV1','smart'=>['health'=>'PASSED','temp'=>'41','defects'=>'0','pending'=>'2']],
]];
$bm = bay_map_assemble($bmDrives, $bmSmart, ['c0:s0/0'=>['row'=>1,'col'=>2], 'c0:s0/1'=>['row'=>0,'col'=>0]],
                       6, 4, ['PLACED01'=>'/dev/sda']);
check('baymap carries the grid dims',   $bm['rows'] === 6 && $bm['cols'] === 4);
// The view needs the lock state in the same payload, or it renders an editable
// map over a store that will refuse every edit.
check('baymap reports unlocked by default', $bm['locked'] === false);
check('baymap passes the lock through',
      bay_map_assemble($bmDrives, null, [], 6, 4, [], true)['locked'] === true);
check('baymap places mapped drives',    count($bm['placed']) === 2);
check('baymap trays unmapped drives',   count($bm['unassigned']) === 1 && $bm['unassigned'][0]['serial'] === 'TRAYDRV1');
$placed = array_column($bm['placed'], null, 'serial');
check('baymap placed keeps its position', $placed['PLACED01']['row'] === 1 && $placed['PLACED01']['col'] === 2);
check('baymap joins SMART by serial',     $placed['PLACED01']['state'] === 'ok' && $placed['PLACED01']['temp'] === 34);
check('baymap resolves the /dev name',    $placed['PLACED01']['dev'] === '/dev/sda');
check('baymap placed-but-unread is nodata, NOT ok',
      $placed['NOSMART1']['state'] === 'nodata' && $placed['NOSMART1']['temp'] === null);
check('baymap flags pending sectors as warn', $bm['unassigned'][0]['state'] === 'warn');
check('baymap keys storcli drives on slot',   $placed['PLACED01']['key'] === 'c0:s0/0');
// The cell prints six fields; each has to survive the join.
check('baymap carries model, serial and size',
      $placed['PLACED01']['model'] === 'ST8000NM' && $placed['PLACED01']['serial'] === 'PLACED01'
      && $placed['PLACED01']['size'] === '7.276 TB');
/* The bay card sets the capacity number and its unit at different sizes, so
   they are split server-side rather than parsed in the view. */
check('baymap splits capacity from its unit',
      $placed['PLACED01']['cap'] === '7.276' && $placed['PLACED01']['cap_unit'] === 'TB');
check('baymap passes an unparseable size through whole',
      bay_map_assemble(['controllers'=>[['drives'=>[['port'=>'1','size'=>'unknown']]]]], null, [], 6, 4)
          ['unassigned'][0]['cap'] === 'unknown');
check('baymap carries the warn temperature', $bm['warn_temp'] === 45);
// The Unraid slot name reaches the bay card and the tray chip.
$bmRole = bay_map_assemble($bmDrives, null, [], 6, 4, ['PLACED01'=>'/dev/sdp'], false, 45, null, [],
                           ['/dev/sdp' => 'Parity']);
$roleBySerial = array_column($bmRole['unassigned'], null, 'serial');
check('baymap carries the Unraid slot name', $roleBySerial['PLACED01']['role'] === 'Parity');
check('baymap leaves a non-array drive roleless', $roleBySerial['NOSMART1']['role'] === '');
/* Tray order is Unraid's Main-page order, not the controller/wire order the
   assemble loop walks in. The fixture is fed deliberately scrambled, so a pass
   cannot come from the input having been sorted already. */
$bmSortDrives = ['controllers' => [['drives' => [
    ['port'=>'1','serial'=>'D10'],   ['port'=>'2','serial'=>'CACHE'],
    ['port'=>'3','serial'=>'P1'],    ['port'=>'4','serial'=>'D2'],
    ['port'=>'5','serial'=>'NONE1'], ['port'=>'6','serial'=>'P2'],
    ['port'=>'7','serial'=>'NONE2'], ['port'=>'8','serial'=>'D1'],
]]]];
$bmSortDevs  = ['D10'=>'/dev/sdb','CACHE'=>'/dev/sdc','P1'=>'/dev/sdp','D2'=>'/dev/sdg',
                'NONE1'=>'/dev/sdaa','P2'=>'/dev/sdq','NONE2'=>'/dev/sdz','D1'=>'/dev/sdk'];
$bmSortRoles = ['/dev/sdb'=>'Disk 10','/dev/sdc'=>'Cache','/dev/sdp'=>'Parity',
                '/dev/sdg'=>'Disk 2','/dev/sdq'=>'Parity 2','/dev/sdk'=>'Disk 1'];
$bmSorted = bay_map_assemble($bmSortDrives, null, [], 6, 4, $bmSortDevs, false, 45, null, [], $bmSortRoles);
$bmSortedDevs = array_column($bmSorted['unassigned'], 'dev');
check('baymap trays in Unraid Main-page order',
      $bmSortedDevs === ['/dev/sdp','/dev/sdq','/dev/sdk','/dev/sdg','/dev/sdb','/dev/sdc',
                         '/dev/sdz','/dev/sdaa']);
/* The two orderings a naive sort gets wrong, pinned separately so a failure
   says which rule broke: "Disk 10" precedes "Disk 2" as a string, and
   "/dev/sdaa" precedes "/dev/sdz" under strcmp. Both put a person in front of
   the wrong drive. */
check('baymap trays Disk 2 ahead of Disk 10',
      array_search('/dev/sdg', $bmSortedDevs, true) < array_search('/dev/sdb', $bmSortedDevs, true));
check('baymap trays roleless drives in natural /dev order',
      array_slice($bmSortedDevs, -2) === ['/dev/sdz','/dev/sdaa']);
// Nothing resolved a /dev name for these, so the sort compares '' to '' — it
// must still return both rather than trip on the null.
check('baymap trays nameless drives without dropping them',
      count(bay_map_assemble(['controllers'=>[['drives'=>[['port'=>'1','serial'=>'X'],
                                                          ['port'=>'2','serial'=>'Y']]]]],
                             null, [], 6, 4)['unassigned']) === 2);
check('baymap warn temperature is injectable',
      bay_map_assemble($bmDrives, null, [], 6, 4, [], false, 52)['warn_temp'] === 52);
/* Rebuild is the ONE thing read from storcli's `state` field. That field is a
   RAID-topology role rather than a health verdict — which is exactly why it is
   right here and wrong for everything else: "Rbld" is not a claim about the
   drive's health, it IS the role, and nothing else reports a rebuild. */
$bmRbld = bay_map_assemble(['backend'=>'storcli','controllers'=>[['drives'=>[
    ['port'=>'3','serial'=>'REBUILD1','state'=>'Rbld'],
    ['port'=>'4','serial'=>'ONLINE01','state'=>'Onln'],
]]]], ['drives'=>[['serial'=>'REBUILD1','smart'=>['health'=>'PASSED']],
                  ['serial'=>'ONLINE01','smart'=>['health'=>'PASSED']]]], [], 6, 4);
$byS = array_column($bmRbld['unassigned'], null, 'serial');
check('baymap reports a rebuilding drive', $byS['REBUILD1']['state'] === 'rebuild');
/* Onln/UGood/JBOD are roles, not verdicts: an Onln drive with failing SMART
   must still read as failed, so only Rbld may override the SMART state. */
check('baymap does not let Onln override SMART', $byS['ONLINE01']['state'] === 'ok');
check('baymap keeps a failing Onln drive failed',
      bay_map_assemble(['controllers'=>[['drives'=>[['port'=>'4','serial'=>'S','state'=>'Onln']]]]],
          ['drives'=>[['serial'=>'S','smart'=>['health'=>'FAILED']]]], [], 6, 4)
          ['unassigned'][0]['state'] === 'fail');
/* The wire label is display-ready and backend-specific on purpose: calling an
   lsiutil PHY a "Port" would be a small lie in the exact place someone reads
   before pulling a drive out of a running array. */
check('baymap labels a storcli wire as Port', $placed['PLACED01']['port'] === 'Port 14');
check('baymap labels an lsiutil wire as PHY',
      bay_map_assemble(['backend'=>'lsiutil','controllers'=>[['drives'=>[['phy'=>'2','serial'=>'S']]]]],
                       null, [], 6, 4)['unassigned'][0]['port'] === 'PHY 2');
check('baymap port is empty when the drive reports no wire',
      $bmNoKey_port = bay_map_assemble(['backend'=>'storcli','controllers'=>[['drives'=>[['serial'=>'S']]]]],
                       null, [], 6, 4)['unassigned'][0]['port'] === '');

// No SMART cache at all (never collected) — every drive is nodata, nothing green.
$bmNone = bay_map_assemble($bmDrives, null, ['c0:p14'=>['row'=>0,'col'=>0]], 6, 4);
check('baymap with no SMART cache colours nothing',
      count(array_filter(array_merge($bmNone['placed'], $bmNone['unassigned']),
                         fn($e) => $e['state'] !== 'nodata')) === 0);

// lsiutil backend: no port anywhere, so the key comes off the PHY.
$bmLsi = bay_map_assemble(['backend'=>'lsiutil','controllers'=>[['drives'=>[
    ['bus'=>'0','target'=>'3','phy'=>'2','os_name'=>'/dev/sdb','serial'=>'LSIDRV01'],
]]]], null, ['c0:h2'=>['row'=>2,'col'=>1]], 6, 4);
check('baymap keys lsiutil drives on phy', ($bmLsi['placed'][0]['key'] ?? '') === 'c0:h2');
check('baymap lsiutil dev comes from os_name', ($bmLsi['placed'][0]['dev'] ?? '') === '/dev/sdb');

/* Issue #15, jac2424's half. The lsiutil payload carries no serial, model or
   size — parse/drives_join.sh emits bus/target/sas_address/phy/expander/os_name
   and nothing else — so a serial-only SMART join missed every drive and the bay
   cards rendered blank: no temperature, no health, no model, no capacity. /dev
   is the identifier both payloads share, and the collector's entry carries the
   rest. Same fixture as above, minus the serial the real backend never sends. */
$bmLsiSmart = ['drives' => [['dev'=>'/dev/sdb','serial'=>'LSIDRV01','model'=>'HUH721212AL',
                             'size'=>'10.9 TB','smart'=>['health'=>'PASSED','temp'=>'38',
                             'defects'=>'0','pending'=>'0']]]];
$bmLsi2 = bay_map_assemble(['backend'=>'lsiutil','controllers'=>[['drives'=>[
    ['bus'=>'0','target'=>'3','phy'=>'2','os_name'=>'/dev/sdb'],
]]]], $bmLsiSmart, ['c0:h2'=>['row'=>2,'col'=>1]], 6, 4);
$lsiCard = $bmLsi2['placed'][0] ?? [];
check('baymap falls back to the /dev join when the drive has no serial',
      ($lsiCard['temp'] ?? null) === 38 && ($lsiCard['state'] ?? '') === 'ok');
check('baymap fills model, serial and capacity from the SMART cache',
      ($lsiCard['model'] ?? '') === 'HUH721212AL' && ($lsiCard['serial'] ?? '') === 'LSIDRV01'
   && ($lsiCard['cap'] ?? '') === '10.9' && ($lsiCard['cap_unit'] ?? '') === 'TB');
/* The controller's own view wins where it has one: storcli reports model and
   size itself, and a cache entry that disagrees must not overwrite it. */
$bmPref = bay_map_assemble(['backend'=>'storcli','controllers'=>[['drives'=>[
    ['slot'=>'0/0','model'=>'FROM-STORCLI','serial'=>'PLACED01','size'=>'7.276 TB'],
]]]], ['drives'=>[['dev'=>'/dev/sda','serial'=>'PLACED01','model'=>'FROM-CACHE',
                   'size'=>'7.3 TB','smart'=>['health'=>'PASSED','temp'=>'30']]]],
    [], 6, 4, ['PLACED01'=>'/dev/sda']);
check('baymap keeps the backend model and size over the cache',
      $bmPref['unassigned'][0]['model'] === 'FROM-STORCLI'
   && $bmPref['unassigned'][0]['size'] === '7.276 TB');

// A stored position outside the current grid (hand-edited file) must land in
// the tray, not vanish and not render off-screen.
$bmOut = bay_map_assemble($bmDrives, null, ['c0:p14'=>['row'=>9,'col'=>9]], 2, 2);
check('baymap out-of-grid position falls back to the tray',
      $bmOut['placed'] === [] && count($bmOut['unassigned']) === 3);

// A drive with neither slot nor PHY cannot be placed — but must still be
// listed, or a missing drive reads as a detection bug.
$bmNoKey = bay_map_assemble(['backend'=>'storcli','controllers'=>[['drives'=>[
    ['port'=>'14','model'=>'ST8000NM','serial'=>'NOPORT01'],
]]]], null, [], 6, 4);
check('baymap unplaceable drive still appears, with a null key',
      count($bmNoKey['unassigned']) === 1 && $bmNoKey['unassigned'][0]['key'] === null);

/* ── Constants must be declared before the code that uses them ───────────────
   A `function` is hoisted and callable from anywhere in the file; a top-level
   `const` is an ordinary statement that only exists once execution reaches it.
   Declaring SMART_CACHE_PATH beside the functions that use it left it undefined
   for the endpoints ABOVE it, and the SMART tab fataled with "Undefined
   constant" — while every test here passed, because requiring this file under
   CLI returns at the dispatch guard and never reaches an endpoint.
   Two checks: the specific constants are visible under CLI (so they are above
   that guard), and no constant in the file is used before its declaration. */
check('the SMART cache path is declared above the dispatch guard', defined('SMART_CACHE_PATH'));

// ajax_info.php's dispatch/fetch requires every render/*.php file at load time
// (see the CLI-seam comment above), so the same "declared before it's used"
// guarantee has to scan those too, or a render file is a blind spot for it.
$aj = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php');
foreach (glob(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/render/*.php') as $renderFile) {
    $aj .= "\n" . file_get_contents($renderFile);
}
preg_match_all('/^const\s+([A-Z_][A-Z0-9_]*)/m', $aj, $mc, PREG_OFFSET_CAPTURE);
foreach ($mc[1] as [$cname, $declAt]) {
    check("const $cname is not used before it is declared", strpos($aj, $cname) >= $declAt);
}

/* A const used as a DEFAULT PARAMETER VALUE is the worse version of the same
   trap: the function is hoisted and callable from anywhere, but its default
   resolves at CALL time, so an endpoint above the const's declaration fatals on
   a function that looks perfectly well-defined. Any const in a signature must
   therefore live above the dispatch guard — where the CLI test runner can see
   it, which is what this check is standing in for. */
preg_match_all('/^function\s+\w+\s*\(([^)]*)\)/m', $aj, $sigs);
foreach ($sigs[1] as $params) {
    preg_match_all('/=\s*([A-Z][A-Z0-9_]{2,})\b/', $params, $defs);
    foreach (array_unique($defs[1]) as $cname) {
        check("const $cname is declared above the dispatch guard (used as a default parameter)", defined($cname));
    }
}

/* ── Unraid parity rebuild ────────────────────────────────────────────────────
   Read from the same two files Unraid's webGui renders from. The load-bearing
   check is the parity-CHECK one: a check reads the array and writes nothing, so
   painting it as a rebuild would put an animated "PARITY REBUILD" on a disk
   that is not being rebuilt. Only positive evidence of a reconstruct counts. */
$iniDir = sys_get_temp_dir() . '/hbav_ini_' . getmypid();
@mkdir($iniDir, 0755, true);
$mkIni = function (string $name, string $body) use ($iniDir): string {
    file_put_contents("$iniDir/$name", $body);
    return "$iniDir/$name";
};
$disks = $mkIni('disks.ini', "[\"parity\"]\nname=\"parity\"\ndevice=\"sdp\"\n"
                           . "[\"disk1\"]\nname=\"disk1\"\ndevice=\"sdb\"\n"
                           . "[\"cache\"]\nname=\"cache\"\ndevice=\"nvme0n1\"\n");
check('parity devices come off disks.ini', unraid_parity_devs($disks) === ['/dev/sdp']);
$disks2 = $mkIni('disks2.ini', "[\"parity\"]\ndevice=\"sdp\"\n[\"parity2\"]\ndevice=\"sdq\"\n");
check('dual parity is both disks', unraid_parity_devs($disks2) === ['/dev/sdp', '/dev/sdq']);
check('a missing disks.ini is no parity', unraid_parity_devs("$iniDir/nope.ini") === []);

/* The array slot names — the identifier every other Unraid screen uses, and
   the one a person already knows before they come here. Spelled the way Main
   spells them so the two screens can be read side by side. */
$roles = unraid_disk_roles($disks);
check('parity is named Parity',   ($roles['/dev/sdp'] ?? '') === 'Parity');
check('disk1 is named Disk 1',    ($roles['/dev/sdb'] ?? '') === 'Disk 1');
check('a pool keeps its own name', ($roles['/dev/nvme0n1'] ?? '') === 'Cache');
check('the second parity is Parity 2',
      (unraid_disk_roles($disks2)['/dev/sdq'] ?? '') === 'Parity 2');
check('a drive outside the array has no role', !isset($roles['/dev/sdzz']));
check('a missing disks.ini has no roles', unraid_disk_roles("$iniDir/nope.ini") === []);
/* Double digits must not sort or read as "Disk 1" — the whole point is telling
   two disks apart at a glance. */
check('disk10 is Disk 10',
      (unraid_disk_roles($mkIni('d10.ini', "[\"disk10\"]\nname=\"disk10\"\ndevice=\"sdk\"\n"))['/dev/sdk'] ?? '') === 'Disk 10');

check('recon while resyncing is a rebuild',
      unraid_rebuilding($mkIni('v1.ini', "mdResync=\"1234\"\nmdResyncAction=\"recon P\"\n")) === true);
check('a parity CHECK is not a rebuild',
      unraid_rebuilding($mkIni('v2.ini', "mdResync=\"1234\"\nmdResyncAction=\"check P\"\n")) === false);
check('recon with no resync running is not a rebuild',
      unraid_rebuilding($mkIni('v3.ini', "mdResync=\"0\"\nmdResyncAction=\"recon P\"\n")) === false);
check('an idle array is not a rebuild',
      unraid_rebuilding($mkIni('v4.ini', "mdState=\"STARTED\"\n")) === false);
check('a missing var.ini is not a rebuild', unraid_rebuilding("$iniDir/nope.ini") === false);

/* Verbatim from a live box (Golem, 2026-08-04), and the reason mdResync is
   checked at all: mdResyncAction is STICKY. This array is idle and has been for
   some time, yet still reports the "check P" it last ran. Matching on the
   action alone would paint a permanent rebuild on the parity disk of every
   array that has ever run an operation.
   The same capture shows the second parity slot present but unassigned
   (device=""), which must not become "/dev/". */
$golemVar = $mkIni('golem_var.ini',
    "mdResync=\"0\"\nmdResyncCorr=\"0\"\nmdResyncPos=\"0\"\nmdResyncDb=\"0\"\n"
  . "mdResyncDt=\"0\"\nmdResyncAction=\"check P\"\nmdResyncSize=\"13672382412\"\nmdState=\"STARTED\"\n");
$golemDisks = $mkIni('golem_disks.ini',
    "[\"parity\"]\nidx=\"0\"\nname=\"parity\"\ndevice=\"sdp\"\n"
  . "[\"parity2\"]\nidx=\"29\"\nname=\"parity2\"\ndevice=\"\"\n"
  . "[\"disk1\"]\nidx=\"1\"\nname=\"disk1\"\ndevice=\"sdb\"\n");
check('a live idle array with a stale action is not rebuilding', unraid_rebuilding($golemVar) === false);
check('an unassigned parity2 is not a device', unraid_parity_devs($golemDisks) === ['/dev/sdp']);
// Section headers in Unraid's ini files are quoted (["parity"]); the parser
// must see through that or every disk section is skipped.
check('quoted ini section names still match', unraid_parity_devs($golemDisks) !== []);

// End to end: the parity disk gets the chip, its neighbour does not.
$bmPar = bay_map_assemble(['controllers'=>[['drives'=>[
    ['port'=>'1','serial'=>'PARITY01'], ['port'=>'2','serial'=>'DATA0001'],
]]]], null, [], 6, 4, ['PARITY01'=>'/dev/sdp','DATA0001'=>'/dev/sdb'], false, 45, null, ['/dev/sdp']);
$byDev = array_column($bmPar['unassigned'], null, 'dev');
check('the rebuilding parity disk reads as rebuild',
      $byDev['/dev/sdp']['state'] === 'rebuild' && $byDev['/dev/sdp']['rebuild_label'] === 'PARITY REBUILD');
check('a data disk beside it is untouched',
      $byDev['/dev/sdb']['state'] !== 'rebuild' && $byDev['/dev/sdb']['rebuild_label'] === null);
// storcli's own Rbld still reports, and says which kind it is.
check('a controller rebuild is labelled RESILVER',
      bay_map_assemble(['controllers'=>[['drives'=>[['port'=>'3','serial'=>'S','state'=>'Rbld']]]]],
          null, [], 6, 4)['unassigned'][0]['rebuild_label'] === 'RESILVER');
array_map('unlink', glob("$iniDir/*") ?: []);
@rmdir($iniDir);

/* ── The SMART cache is kept until someone refreshes it ───────────────────────
   Re-reading every drive costs ~1s per drive, and the data changes over weeks,
   so a TTL made both the SMART tab and the bay map feel broken. What replaces
   it is that every surface states the collection's age. */
$sc = sys_get_temp_dir() . '/hbav_smartcache_' . getmypid() . '.json';
file_put_contents($sc, '{"drives":[{"dev":"/dev/sda","serial":"X","smart":{"health":"PASSED"}}]}');
touch($sc, time() - 86400 * 3);
check('a three-day-old cache is still served', smart_cache_read($sc) !== null);
check('cache age is reported in seconds', abs((int) smart_cache_age($sc) - 86400 * 3) < 5);
check('no cache reads as null, not empty', smart_cache_read("$sc.nope") === null);
check('no cache has no age', smart_cache_age("$sc.nope") === null);
file_put_contents($sc, 'not json');
check('a corrupt cache reads as null', smart_cache_read($sc) === null);
@unlink($sc);
// The table says how old it is, so week-old temperatures cannot pass for live.
$smartHtml = renderSmartTable(['drives'=>[['dev'=>'/dev/sda','serial'=>'X','smart'=>['health'=>'PASSED']]]], 7200);
check('the SMART table states its age', str_contains($smartHtml, 'Collected 2 h ago'));
check('the SMART table says how to update it', str_contains($smartHtml, 'until you press Refresh'));
check('no age given, no age line',
      !str_contains(renderSmartTable(['drives'=>[['dev'=>'/dev/sda','serial'=>'X','smart'=>[]]]]), 'Collected'));

/* smart_state is the one health rule the SMART tab, the per-drive line and the
   bay map all share (plan 047 STOP condition). */
check('smart_state ok',      smart_state(['health'=>'PASSED']) === 'ok');
check('smart_state ok on OK', smart_state(['health'=>'OK']) === 'ok');
check('smart_state warn on pending', smart_state(['health'=>'OK','pending'=>'3']) === 'warn');
check('smart_state warn on defects', smart_state(['health'=>'OK','defects'=>'1']) === 'warn');
check('smart_state fail',    smart_state(['health'=>'FAILED']) === 'fail');
check('smart_state nodata on empty', smart_state([]) === 'nodata');
check('smart_state nodata is uncoloured', smart_state_color('nodata') === '');

check('drive_dev_name prefers os_name', drive_dev_name(['os_name'=>'/dev/sdb','serial'=>'X'], ['X'=>'/dev/sdq']) === '/dev/sdb');
check('drive_dev_name null without serial', drive_dev_name(['serial'=>''], ['X'=>'/dev/sdq']) === null);
check('drive_dev_name null when unmatched', drive_dev_name(['serial'=>'NOPE'], ['X'=>'/dev/sdq']) === null);
check('drives empty', str_contains(
    renderDrivesTables(['backend'=>'storcli','controllers'=>[[]]]), 'No drives detected.'));

/* ── Events: archive dir is injectable, merge dedups, newest first ────────── */
$dir = sys_get_temp_dir() . '/hbav_events_' . getmypid();
@mkdir($dir, 0755, true);
array_map('unlink', glob("$dir/*.json") ?: []);

$evStorcli = ['backend' => 'storcli', 'controllers' => [['entries' => [
    ['seq'=>'11','time'=>'2026-07-01 10:00:00','code'=>'0x0113','description'=>'Drive inserted'],
    ['seq'=>'12','time'=>'2026-07-01 10:05:00','code'=>'0x0114','description'=>'Drive removed'],
]]]];
$h = renderEventsTables($evStorcli, $dir);
check('events storcli col set', hasCol($h, 'Description'));
check('events wrote archive',   is_file("$dir/events_c0.json"));
check('events newest first',    strpos($h, 'Drive removed') < strpos($h, 'Drive inserted'));
check('events counts entries',  str_contains($h, '2 entries'));

// Re-render the same payload: the archive must not grow (dedup by seq|time).
renderEventsTables($evStorcli, $dir);
$archived = json_decode((string) file_get_contents("$dir/events_c0.json"), true);
check('events dedup on repeat', count($archived) === 2);

// Own archive dir: sharing $dir with the storcli case above would merge
// lsiutil-shaped entries into a storcli-shaped archive (and vice versa),
// producing undefined-key warnings on the foreign-shaped rows — a real
// failure mode if a box switches backends, but not what this case tests.
$dirLsi = sys_get_temp_dir() . '/hbav_events_lsi_' . getmypid();
@mkdir($dirLsi, 0755, true);
array_map('unlink', glob("$dirLsi/*.json") ?: []);

$evLsi = ['backend' => 'lsiutil', 'controllers' => [['entries' => [
    ['seq'=>'7','qualifier'=>'0x02','data'=>'00 11 22','timestamp'=>'0x0001d4c0'],
]]]];
$h = renderEventsTables($evLsi, $dirLsi);
check('events lsiutil col set', hasCol($h, 'Qualifier') && !hasCol($h, 'Description'));

array_map('unlink', glob("$dirLsi/*.json") ?: []);
@rmdir($dirLsi);

$dirNote = sys_get_temp_dir() . '/hbav_events_note_' . getmypid();
@mkdir($dirNote, 0755, true);
array_map('unlink', glob("$dirNote/*.json") ?: []);

check('events note rendered', str_contains(
    renderEventsTables(['backend'=>'storcli','controllers'=>[['note'=>'expert mode required','entries'=>[]]]], $dirNote),
    'expert mode required'));

array_map('unlink', glob("$dirNote/*.json") ?: []);
@rmdir($dirNote);

array_map('unlink', glob("$dir/*.json") ?: []);
@rmdir($dir);

/* The events renderer filters the archive by BACKEND SHAPE, and archived entries
   are only ever storcli- or lsiutil-shaped -- never 'storcli2', which is a tool
   name. Passing the raw field filtered every entry away, so a 9600 showed "No
   log entries" for events it had just read. Upstream has the same defect; we
   deliberately do not. */
$dirShape = sys_get_temp_dir() . '/hbav_events_shape_' . getmypid();
@mkdir($dirShape, 0755, true);
array_map('unlink', glob("$dirShape/*.json") ?: []);

$evSeed = ['backend' => 'storcli', 'controllers' => [['entries' => [
    ['seq'=>'1','time'=>'Mon Jan  1 00:00:00 2026','code'=>'0x00','description'=>'SHAPEMARK'],
]]]];
renderEventsTables($evSeed, $dirShape); // seeds the on-disk archive

$sc2Ev = ['backend' => 'storcli2', 'controllers' => [['entries' => []]]];
check('events: a storcli2 payload still shows its archived entries',
    str_contains(renderEventsTables($sc2Ev, $dirShape), 'SHAPEMARK'));

array_map('unlink', glob("$dirShape/*.json") ?: []);
@rmdir($dirShape);

/* ── SMART table: health colouring, standby, and the empty case ───────────── */
$h = renderSmartTable(['drives' => [
    ['dev'=>'/dev/sdb','model'=>'ST8000NM','serial'=>'ZA1ABCDE',
     'smart'=>['health'=>'PASSED','temp'=>'34','defects'=>'0','pending'=>'0','power_on_hours'=>'12345']],
    ['dev'=>'/dev/sdc','model'=>'WD80EFAX','serial'=>'WD-XYZ',
     'smart'=>['health'=>'PASSED','temp'=>'36','defects'=>'2','pending'=>'0','power_on_hours'=>'900']],
    ['dev'=>'/dev/sdd','model'=>'HUH721','serial'=>'K1234','smart'=>[]],
]]);
check('smart healthy green',  str_contains($h, '#2ecc71'));
check('smart defects amber',  str_contains($h, '#f39c12'));
check('smart standby row',    str_contains($h, 'standby'));
check('smart formats hours',  str_contains($h, '12,345h'));
check('smart empty message',  str_contains(renderSmartTable([]), 'No drives found.'));
check('smart header drops Grown Defects', !str_contains($h, 'Grown Defects'));

/* transport: the label the plan exists for — SATA drives must not be shown
   SAS-only vocabulary, and an unknown transport must show a dash, never a
   guessed bus. */
$hSata = renderSmartTable(['drives' => [
    ['dev'=>'/dev/sdb','model'=>'WD80EFAX','serial'=>'WD-XYZ',
     'smart'=>['health'=>'PASSED','temp'=>'34','defects'=>'0','pending'=>'0','power_on_hours'=>'900','transport'=>'sata']],
]]);
check('smart sata renders SATA',       str_contains($hSata, 'SATA'));
check('smart sata header Reallocated', str_contains($hSata, 'Reallocated'));

$hSas = renderSmartTable(['drives' => [
    ['dev'=>'/dev/sda','model'=>'ST8000NM','serial'=>'ZA1ABCDE',
     'smart'=>['health'=>'OK','temp'=>'35','defects'=>'0','pending'=>'0','power_on_hours'=>'27409','transport'=>'sas']],
]]);
check('smart sas renders SAS', str_contains($hSas, 'SAS'));

$hNoTran = renderSmartTable(['drives' => [
    ['dev'=>'/dev/sdd','model'=>'HUH721','serial'=>'K1234',
     'smart'=>['health'=>'PASSED','temp'=>'34','defects'=>'0','pending'=>'0','power_on_hours'=>'900']],
]]);
check('smart no transport shows dash',    str_contains($hNoTran, 'lu-muted'));
check('smart no transport is not guessed', !str_contains($hNoTran, 'SATA'));

/* ── luTable: headers are escaped, cells are passed through as markup ─────── */
$t = luTable(['A & B'], [['<code>x</code>']]);
check('luTable escapes headers', str_contains($t, 'A &amp; B'));
check('luTable cells are html',  str_contains($t, '<code>x</code>'));
/* Plan 050's per-cell rates made the PHY table wider than its card, and the
   overflow columns were unreachable rather than merely ugly. The scroller is
   what makes a wide table readable, so it is pinned here. */
check('luTable is wrapped in a horizontal scroller',
      str_starts_with($t, '<div class="lu-tscroll"><table') && str_ends_with($t, '</table></div>'));

/* The card shell four renderers used to repeat verbatim. The error branch is
   the load-bearing part: an errored controller must still get its own card and
   the card must be CLOSED, or it renders as bare text floating between its
   neighbours' cards. luCtlHead appears only when there is more than one
   controller -- a single-controller box gets no heading, which is what every
   existing single-controller expectation pins. */
check('card: one card per controller', function_exists('luCardPerController')
    && substr_count(luCardPerController([[], []], fn($i, $c) => 'X'), 'lu-card first') === 2);
check('card: body output lands inside the card',
    str_contains(luCardPerController([['phys' => []]], fn($i, $c) => 'BODYMARK'), 'BODYMARK'));
check('card: single controller gets no heading',
    !str_contains(luCardPerController([[]], fn($i, $c) => ''), 'Controller /c'));
check('card: two controllers get headings',
    substr_count(luCardPerController([[], []], fn($i, $c) => ''), 'Controller /c') === 2);
check('card: an errored controller still gets a closed card',
    luCardPerController([['error' => 'no response']], fn($i, $c) => 'NEVER')
        === '<div class="lu-card first" data-ctl="0"><p class="lu-muted">no response</p></div>');
check('card: the body is not called for an errored controller',
    !str_contains(luCardPerController([['error' => 'x']], fn($i, $c) => 'NEVER'), 'NEVER'));
check('card: error text is escaped',
    str_contains(luCardPerController([['error' => '<b>x']], fn($i, $c) => ''), '&lt;b&gt;x'));
// A malformed controllers[] entry -- a composer bug, a truncated read -- must
// cost one blank card, not the whole tab. Before the closure conversion these
// were foreach bodies with no type constraint; the typed closures made a null
// entry fatal.
check('card: a null controller entry degrades instead of throwing',
    luCardPerController([null], fn(int $i, array $c) => 'BODY')
        === '<div class="lu-card first" data-ctl="0">BODY</div>');

/* ── Hostile-ish hardware strings must not reach the page as markup ────────
   Every value below arrives from HBA firmware, storcli text, or sysfs. None of
   it is attacker-controlled in any realistic scenario — but a drive model
   containing < or & is a plain correctness problem, and consistency here is
   what stops the next person guessing which convention this file follows. */
$X   = '<img src=x onerror=alert(1)>';
$ESC = '&lt;img src=x onerror=alert(1)&gt;';

$h = renderPhyTables(['backend'=>'storcli','controllers'=>[['phys'=>[
    ['phy'=>$X,'link'=>'up','speed'=>$X,'sas_addr'=>$X,'inv'=>0,'disp'=>0,'sync'=>0,'reset'=>0],
]]]]);
check('phy storcli escapes phy',   !str_contains($h, $X));
check('phy storcli escapes sas',   str_contains($h, strtoupper($ESC)) || str_contains($h, $ESC));

$h = renderPhyTables(['backend'=>'lsiutil','controllers'=>[['phys'=>[
    ['phy'=>$X,'link'=>'up','inv'=>0,'disp'=>0,'sync'=>0,'reset'=>0],
]]]]);
check('phy lsiutil escapes phy', !str_contains($h, $X));

$h = renderDrivesTables(['backend'=>'storcli','controllers'=>[['drives'=>[
    ['slot'=>'8/0','port'=>'14','model'=>$X,'serial'=>'S1','state'=>'JBOD',
     'size'=>'8 TB','sas_address'=>$X,'link'=>'12.0Gb/s','firmware'=>'SN02'],
]]]]);
check('drives storcli escapes model', !str_contains($h, $X));
check('drives storcli escapes sas',   !str_contains($h, strtoupper($X)));

$h = renderDrivesTables(['backend'=>'lsiutil','controllers'=>[['drives'=>[
    ['bus'=>$X,'target'=>'3','phy'=>$X,'sas_address'=>$X,'os_name'=>$X],
]]]]);
check('drives lsiutil escapes os_name', !str_contains($h, $X));
check('drives lsiutil escapes bus',     !str_contains($h, $X));
check('drives lsiutil escapes sas',     !str_contains($h, strtoupper($X)));

$edir = sys_get_temp_dir() . '/hbav_esc_' . getmypid();
@mkdir($edir, 0755, true);
$h = renderEventsTables(['backend'=>'lsiutil','controllers'=>[['entries'=>[
    ['seq'=>$X,'qualifier'=>$X,'data'=>$X,'timestamp'=>$X],
]]]], $edir);
check('events lsiutil escapes seq',       !str_contains($h, $X));
check('events lsiutil escapes qualifier', !str_contains($h, $X));
check('events lsiutil escapes timestamp', !str_contains($h, $X));
array_map('unlink', glob("$edir/*.json") ?: []);
@rmdir($edir);

// The already-correct branches stay correct — guard against a regression that
// "fixes" escaping by moving it into luTable and double-escaping everything.
$h = renderDrivesTables(['backend'=>'storcli','controllers'=>[[
    'enclosures'=>[['eid'=>'8','product'=>'VirtualSES','vendor'=>'LSI','slots'=>'8','drives'=>'4','direct'=>1]],
    'drives'=>[['slot'=>'8/0','port'=>'14','model'=>'A & B','serial'=>'S1','state'=>'JBOD',
                'size'=>'8 TB','sas_address'=>'5000c5','link'=>'12.0Gb/s','firmware'=>'SN02']],
]]]);
check('no double escaping', str_contains($h, 'A &amp; B') && !str_contains($h, 'A &amp;amp; B'));

/* ── Mixed-shape archive: a box that changed backend ──────────────────────────
   storcli and lsiutil emit different event records into the same per-controller
   archive. Before this was handled, the active renderer hit undefined keys on
   the foreign-shaped rows and emitted PHP warnings. */
$dirMix = sys_get_temp_dir() . '/hbav_events_mix_' . getmypid();
@mkdir($dirMix, 0755, true);
array_map('unlink', glob("$dirMix/*.json") ?: []);

// Seed the archive with lsiutil history, as a SAS2 box would have.
renderEventsTables(['backend'=>'lsiutil','controllers'=>[['entries'=>[
    ['seq'=>1,'qualifier'=>'0x0001','data'=>'00000000','timestamp'=>'00000000:000012ab'],
    ['seq'=>2,'qualifier'=>'0x0002','data'=>'deadbeef','timestamp'=>'00000000:000012ac'],
]]]], $dirMix);

// Now the user installs storcli: same controller, same archive, new shape.
$warned = false;
set_error_handler(function () use (&$warned) { $warned = true; return true; });
$h = renderEventsTables(['backend'=>'storcli','controllers'=>[['entries'=>[
    ['seq'=>'0x01','time'=>'2026-07-01 10:00:00','code'=>'0x0113','description'=>'Drive inserted'],
]]]], $dirMix);
restore_error_handler();

check('mixed archive: no PHP warning',   $warned === false);
check('mixed archive: storcli row shown', str_contains($h, 'Drive inserted'));
check('mixed archive: lsiutil rows hidden', !str_contains($h, 'deadbeef'));
check('mixed archive: storcli columns',  hasCol($h, 'Description'));
check('mixed archive: counts visible only', str_contains($h, '1 entries'));
check('mixed archive: reports hidden',   str_contains($h, '2 from a previous backend not shown'));

// The archive on disk must still hold every entry — nothing is deleted.
$onDisk = json_decode((string) file_get_contents("$dirMix/events_c0.json"), true);
check('mixed archive: history preserved on disk', count($onDisk) === 3);

array_map('unlink', glob("$dirMix/*.json") ?: []);
@rmdir($dirMix);

/* ── Health tab: the gauge and the rows must come from the same set ────────────
   health_gauge() counts whatever health_indicators() returned; the row list was
   a separate hardcoded literal that omitted `thermal`. Result on hardware:
   "4 / 5 indicators ok" printed above four rows (plan 031). The load-bearing
   assertions below are the two that reconcile the two renderings — numerator ==
   green rows, denominator == rows rendered — not the label spellings. */
$hRing = health_store_path(0);
$hSaved = is_file($hRing) ? file_get_contents($hRing) : null;   // a live box's ring, if any

// One controller sample, the shape scripts/get_hba_health.sh emits.
$hs = function (int $t, int $uptime, $temp, string $band): array {
    return ['t' => $t, 'uptime' => $uptime, 'temp' => $temp, 'temp_band' => $band,
            'fw' => '20.00.07.00', 'drives' => 8, 'read_ok' => true,
            'link' => ['width' => 8, 'max_width' => 8, 'speed' => '8.0 GT/s', 'max_speed' => '8.0 GT/s'],
            'phys' => [['idx' => 0, 'inv' => 0, 'disp' => 0, 'sync' => 0, 'rst' => 0, 'rate' => '12.0_Gbit']]];
};
// Two samples 2000s apart with flat counters: link_integrity needs >= 60s of
// ring to be anything but `unknown` at all, and (plan 050) >= HEALTH_MIN_CLEAR_SECS
// (30 min) of it clean before a bare "0/hr" counts as the all-clear rather than
// "too short to have seen a slow fault yet" — 2000s clears that floor, and
// identical counters make it `ok`.
$hRender = function (array $ctl, array $seed) use ($hRing): string {
    health_store_write($hRing, [$seed]);
    return renderHealthTables(['controllers' => [$ctl]]);
};
$okDark  = lsi_health_gradient('ok')[0];
$rowsOf  = fn(string $h) => substr_count($h, 'class="lu-indicator-row"');
$greenOf = fn(string $h) => substr_count($h, '<span class="lu-ind-dot" style="--gd:' . $okDark . ';');

$now = time();
$h = $hRender($hs($now, 3600, '77', 'warning'), $hs($now - 2000, 1600, '76', 'warning'));

check('health five rows render', $rowsOf($h) === 5);
foreach (['Thermal', 'Link Integrity', 'Topology', 'Host Link', 'Read Health'] as $lbl) {
    check("health row '$lbl'", str_contains($h, '<span class="lu-indicator-label">' . $lbl . '</span>'));
}
// Order must match hbaviewer.php's header sentence and health_indicators()'s keys.
$pos = array_map(fn($l) => strpos($h, ">$l</span>"), ['Thermal', 'Link Integrity', 'Topology', 'Host Link', 'Read Health']);
$sorted = $pos; sort($sorted, SORT_NUMERIC);
check('health rows in header order', !in_array(false, $pos, true) && $pos === $sorted);
check('health thermal shows temp', str_contains($h, '<span class="lu-indicator-value">77°C</span>'));

/* Issue #11: every row prints the reason under its value. "Link Integrity
   0/hr" with nothing saying 0-of-what was the report; the hint line is what
   answers it, so assert one per row and that the CSS class the shell styles
   actually exists. */
check('health rows each carry a hint line', substr_count($h, '<span class="lu-ind-hint">') === 5);
check('health hint explains the link rate', str_contains($h, 'No new cabling errors on any PHY'));
check('health hint explains the drive count', str_contains($h, 'All 8 attached drives present'));

/* Row icons (plan 032). Two indicator keys do not match their sprite id
   (`link_integrity` -> lu-i-link, `host_link` -> lu-i-hostlink); a mismatch
   renders an empty icon slot silently, so assert the ids AND that every one is
   actually defined in hbaviewer.php's sprite. */
preg_match_all('~<use href="#(lu-i-[a-z]+)"/>~', $h, $mIco);
check('health rows emit five icons', $mIco[1] === ['lu-i-thermal', 'lu-i-link', 'lu-i-topology', 'lu-i-hostlink', 'lu-i-controller']);

$shell = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php');
// The sprite moved out of the shell in P1-C: settings.php and flash_view.php
// need it too, and inlining it three times is how the dingbats survived on
// those pages in the first place. Symbols come from icons.php now; the shell
// only requires it.
preg_match_all('~<symbol id="(lu-i-[a-z]+)"~',
    (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/icons.php'), $mSym);
check('every icon resolves to a defined symbol', $mIco[1] && !array_diff($mIco[1], $mSym[1]));
/* The hint line is only readable as a sub-line if it is styled; unstyled it
   inherits the row's 12.5px flex and lands next to the value. The rules moved
   out of the shell into chrome.css (plan 055), so this follows them — and now
   also checks the shell LINKS that file, because a stylesheet that exists and
   is never loaded fails exactly like one that was deleted. */
$css = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/chrome.css');
check('hint line is styled', str_contains($css, '.lu-ind-hint {'));
// Cache-busted like hbaviewer.js already is: without the ?v= a browser serves
// the stylesheet it cached before the update, so a CSS fix appears not to have
// shipped until someone hard-refreshes. Cost a round of "it didn't change" on
// real hardware before it was spotted.
check('the shell loads chrome.css', str_contains($shell, 'href="/plugins/hbaviewer/chrome.css?v='));
// $shell is the SOURCE, not rendered output, so the ?v= is followed by the PHP
// tag rather than digits. What matters is that the stamp is the file's own
// mtime -- a hand-maintained version would be one more thing to forget.
check('the stylesheet stamp is chrome.css\'s own mtime', (bool) preg_match(
    '~chrome\.css\?v=<\?=[^>]*filemtime\([^)]*chrome\.css.*?\?>"~', $shell));
// Chrome is shared by two pages now, so it must stay pure CSS — a PHP tag in
// there would be served as text and silently break every rule after it.
check('chrome.css carries no PHP', !str_contains($css, '<?'));
// The sprite must be parsed once in the page shell, never re-emitted by the
// per-poll Health render, which would duplicate these ids on every refresh.
check('sprite defined once, in the shell only',
      count($mSym[1]) === count(array_unique($mSym[1])) && !str_contains($h, '<symbol'));
// The dot keeps lsi_health_gradient()'s --gd/--gl; the 30x9 bar is gone.
check('no lu-ind-bar remains', !str_contains($h, 'lu-ind-bar'));
check('dots still gradient-filled', substr_count($h, '<span class="lu-ind-dot" style="--gd:') === 5);

preg_match('~<span class="val">(\d+) / (\d+)</span>~', $h, $m);
check('health gauge reads 4 / 5',        ($m[1] ?? '') === '4' && ($m[2] ?? '') === '5');
check('health gauge numerator == green rows', (int) ($m[1] ?? -1) === $greenOf($h));
check('health gauge total == rows rendered',  (int) ($m[2] ?? -1) === $rowsOf($h));
check('health warning row not green', $greenOf($h) === 4);

/* No temperature sensor at all — the common SAS2008/9211 case. thermal is
   `unknown`, which must still render a row (with an em dash) and must NOT be
   counted as ok. */
$h = $hRender($hs($now, 3600, null, ''), $hs($now - 2000, 1600, null, ''));
check('health unknown thermal still rows', $rowsOf($h) === 5);
check('health unknown thermal em dash', str_contains($h, '<span class="lu-indicator-value">—</span>'));
check('health unknown thermal not green', $greenOf($h) === 4);
preg_match('~<span class="val">(\d+) / (\d+)</span>~', $h, $m);
check('health unknown gauge numerator == green rows', (int) ($m[1] ?? -1) === $greenOf($h));
check('health unknown gauge total == rows rendered',  (int) ($m[2] ?? -1) === $rowsOf($h));

/* Inverse of the reported case: thermal fine, something else not. Without the
   thermal row the numerator (4) exceeds the green rows shown (3) — this is the
   orientation where the numerator assertion, not the denominator, does the work. */
$down = $hs($now, 3600, '45', 'normal');
$down['link']['width'] = 4;                       // x4 in an x8 slot -> host_link warning
$h = $hRender($down, $hs($now - 2000, 1600, '45', 'normal'));
preg_match('~<span class="val">(\d+) / (\d+)</span>~', $h, $m);
check('health downtrain gauge reads 4 / 5', ($m[1] ?? '') === '4' && ($m[2] ?? '') === '5');
check('health downtrain numerator == green rows', (int) ($m[1] ?? -1) === $greenOf($h));

/* ── One .lu-card per HBA on every per-controller tab (plan 033) ──────────────
   The Overview has always given each controller its own card; Health, PHY,
   Drives and Events stacked every controller inside the pane's single card.
   The renderers now emit the card themselves, so the count must track the
   controller count on EVERY path. The load-bearing cases are the error and
   empty branches: they `continue` past the loop's normal close, so a missed
   close there leaks one controller's markup into the next controller's card
   (or renders it as bare text between cards) — which is why the div balance is
   asserted alongside the count. Zero controllers must emit no card at all. */
$cards  = fn(string $h) => substr_count($h, 'class="lu-card first"');
$ctlIds = function (string $h): array {
    preg_match_all('~data-ctl="(\d+)"~', $h, $m);
    return $m[1];
};
$balanced = fn(string $h) => substr_count($h, '<div') === substr_count($h, '</div>');

$hRing1 = health_store_path(1);
$hSaved1 = is_file($hRing1) ? file_get_contents($hRing1) : null;
$cdir = sys_get_temp_dir() . '/hbav_cards_' . getmypid();
@mkdir($cdir, 0755, true);
array_map('unlink', glob("$cdir/*.json") ?: []);

$phyC = fn() => ['phys' => [['phy'=>0,'link'=>'up','speed'=>'12.0Gb/s','sas_addr'=>'5000cca0','inv'=>0,'disp'=>0,'sync'=>0,'reset'=>0]]];
$drvC = fn() => ['drives' => [['slot'=>'8/0','port'=>'14','model'=>'ST8000NM','serial'=>'S1','state'=>'JBOD',
                              'size'=>'8 TB','sas_address'=>'5000c5','link'=>'12.0Gb/s','firmware'=>'SN02']]];
$evC  = fn($s) => ['entries' => [['seq'=>$s,'time'=>'2026-07-01 10:00:00','code'=>'0x0113','description'=>'Drive inserted']]];
$err  = ['error' => 'no response from controller'];

// name => [two-controller render, one-good-one-errored render, zero-controller render]
$tabs = [
    'health' => [
        fn() => renderHealthTables(['controllers' => [$hs($now, 3600, '45', 'normal'), $hs($now, 3600, '45', 'normal')]]),
        fn() => renderHealthTables(['controllers' => [$hs($now, 3600, '45', 'normal'), $err]]),
        fn() => renderHealthTables(['controllers' => []]),
    ],
    'phy' => [
        fn() => renderPhyTables(['backend'=>'storcli','controllers'=>[$phyC(), $phyC()]]),
        fn() => renderPhyTables(['backend'=>'storcli','controllers'=>[$phyC(), $err]]),
        fn() => renderPhyTables(['backend'=>'storcli','controllers'=>[]]),
    ],
    'drives' => [
        fn() => renderDrivesTables(['backend'=>'storcli','controllers'=>[$drvC(), $drvC()]]),
        fn() => renderDrivesTables(['backend'=>'storcli','controllers'=>[$drvC(), $err]]),
        fn() => renderDrivesTables(['backend'=>'storcli','controllers'=>[]]),
    ],
    'events' => [
        fn() => renderEventsTables(['backend'=>'storcli','controllers'=>[$evC('1'), $evC('2')]], $cdir),
        fn() => renderEventsTables(['backend'=>'storcli','controllers'=>[$evC('1'), $err]], $cdir),
        fn() => renderEventsTables(['backend'=>'storcli','controllers'=>[]], $cdir),
    ],
];
foreach ($tabs as $tab => [$two, $errored, $none]) {
    $h = $two();
    check("$tab: two controllers -> two cards", $cards($h) === 2);
    check("$tab: two distinct data-ctl",        $ctlIds($h) === ['0', '1']);
    check("$tab: two-card divs balanced",       $balanced($h));

    $h = $errored();
    check("$tab: errored controller still carded", $cards($h) === 2 && $ctlIds($h) === ['0', '1']);
    // The card must close immediately after the error paragraph, not swallow
    // whatever the next controller renders.
    check("$tab: errored controller text inside its card",
          str_contains($h, 'no response from controller</p></div>'));
    check("$tab: errored divs balanced",           $balanced($h));

    check("$tab: no controllers -> no card", $cards($none()) === 0);
}

// The renderers' own empty branches also `continue` — same leak risk, different line.
check('phy: no-PHY controller still carded', (function () use ($cards, $ctlIds, $balanced, $phyC) {
    $h = renderPhyTables(['backend'=>'storcli','controllers'=>[$phyC(), []]]);
    return $cards($h) === 2 && $ctlIds($h) === ['0','1'] && $balanced($h) && str_contains($h, 'No PHY data.');
})());
check('drives: driveless controller still carded', (function () use ($cards, $ctlIds, $balanced, $drvC) {
    $h = renderDrivesTables(['backend'=>'storcli','controllers'=>[$drvC(), []]]);
    return $cards($h) === 2 && $ctlIds($h) === ['0','1'] && $balanced($h) && str_contains($h, 'No drives detected.');
})());
// Its own archive dir: $cdir already holds c1 entries from the two-controller
// case above, and event_merge would replay them, so the controller would not be
// entry-less at all.
$edir2 = sys_get_temp_dir() . '/hbav_cards_empty_' . getmypid();
@mkdir($edir2, 0755, true);
array_map('unlink', glob("$edir2/*.json") ?: []);
check('events: entry-less controller still carded', (function () use ($cards, $ctlIds, $balanced, $evC, $edir2) {
    $h = renderEventsTables(['backend'=>'storcli','controllers'=>[$evC('1'), ['entries'=>[]]]], $edir2);
    return $cards($h) === 2 && $ctlIds($h) === ['0','1'] && $balanced($h) && str_contains($h, 'No log entries.');
})());
array_map('unlink', glob("$edir2/*.json") ?: []);
@rmdir($edir2);

/* SMART is deliberately NOT carded: renderSmartTable takes a flat drive list
   with no controller loop, so there is nothing to split per controller. */
check('smart stays uncarded', !str_contains(renderSmartTable(['drives' => [
    ['dev'=>'/dev/sdb','model'=>'ST8000NM','serial'=>'S1','smart'=>['health'=>'PASSED','temp'=>'34']],
]]), 'lu-card'));

/* The shell must no longer wrap these four panes in a card — the renderers own
   that now, and a leftover wrapper would nest every card inside another. */
foreach (['health', 'phy', 'drives', 'events'] as $tab) {
    // `[^>]*` after the class: the pane also carries role=tabpanel and
    // aria-labelledby now. What this asserts is that NOTHING SITS BETWEEN the
    // pane and its toolbar, so the open tag's own attributes are none of its
    // business — pinning them made it fail on an accessibility attribute that
    // wraps nothing.
    check("shell: tab-$tab pane has no card wrapper",
          (bool) preg_match('~<div id="tab-' . $tab . '" class="lu-tab-pane[^"]*"[^>]*>\s*<div class="lu-tab-toolbar">~', $shell));
}

/* ── Token wiring (design-system P1-A) ───────────────────────────────────────
   The token block used to be copy-pasted into settings.php, and the two copies
   drifted: settings.php's --mono had quietly lost "JetBrains Mono". It lives in
   tokens.css now, which means a page that reads a token but forgets the <link>
   renders with every colour falling back to its literal -- readable enough on a
   dark theme to survive a glance, and wrong on the other three.
   So: anything that READS a shared token must LINK the file, and nothing may
   declare one outside it. Text checks on purpose -- there is no CSS engine
   here, and the failure being guarded against is a missing line, not a
   cascade. */
$pluginDir = __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer';
foreach (['hbaviewer.php', 'settings.php', 'flash_view.php'] as $page) {
    $src = (string) file_get_contents("$pluginDir/$page");
    // The lookahead keeps --text-color and --border-color out of it: those are
    // UNRAID's variables, which need no link from us. Matching one of those
    // would let this check pass on a page that reads no shared token at all.
    check("$page reads shared tokens", (bool) preg_match('~var\\(--(bg|surface|surface-2|border|border-soft|text|muted|faint|accent|good|warn|crit|mono)(?![-\\w])~', $src));
    check("$page links tokens.css",    str_contains($src, '/plugins/hbaviewer/tokens.css'));
}
/* The point of the extraction, stated as an assertion: exactly one file in the
   plugin declares these. dashboard.php is deliberately not in scope -- it is
   injected into Unraid's own dashboard page, carries its own --d-* set, and
   shares one token with these three, so it declares none of the names below. */
$declarers = [];
foreach (glob("$pluginDir/*.{css,php}", GLOB_BRACE) ?: [] as $path) {
    if (preg_match('~(?:^|[{;])\s*--(surface-2|border-soft|good-text|mono)\s*:~m', (string) file_get_contents($path))) {
        $declarers[] = basename($path);
    }
}
check('exactly one file declares the shared tokens', $declarers === ['tokens.css']);

/* ── Sortable table headers (design-system P2-B) ─────────────────────────────
   The server's half of the sort. luSort()'s behaviour is pinned in
   sort_js_test.js; what has to be true HERE is that the control it drives
   actually reaches the page, on every table, keyboard-reachable.
   A plain <th> with a click handler would look identical and be mouse-only,
   which is the failure this checks for. */
$tbl = luTable(['Device', 'Temp'], [['/dev/sdb', '38'], ['/dev/sdc', '31']]);
check('every header carries the sort control', substr_count($tbl, '<button type="button" class="lu-sort"') === 2);
check('every header starts unsorted', substr_count($tbl, '<th aria-sort="none">') === 2);
// aria-sort belongs on the column, not on the control inside it -- a screen
// reader reads the sort state off the header cell.
check('the sort state is on the th, not the button', !preg_match('~<button[^>]*aria-sort~', $tbl));
check('header text is still escaped',
      str_contains(luTable(['<b>x</b>'], []), '&lt;b&gt;x&lt;/b&gt;'));

/* ── Button consolidation (design-system P1-B) ───────────────────────────────
   There were two solid buttons, .lu-btn and .lu-fbtn, identical but for 1px of
   type and a few px of padding -- and each carried its OWN hardcoded hover
   hex. Two literals for one colour is a bug with a delay on it: change
   --accent and one of them silently keeps the old hue. One class now, hover
   derived from the token. */
$chrome = (string) file_get_contents("$pluginDir/chrome.css");
check('the solid button is defined once', substr_count($chrome, "
.lu-btn {") === 1);
$anyFbtn = [];
foreach (glob("$pluginDir/{*.php,*.js,*.css,render/*.php}", GLOB_BRACE) ?: [] as $path) {
    if (str_contains((string) file_get_contents($path), 'lu-fbtn')) { $anyFbtn[] = basename($path); }
}
check('the second solid button is gone', $anyFbtn === []);
// The point of the consolidation: no page may re-declare .lu-btn's own
// appearance. Arrangement (margins inside .lu-actions) is a page's business
// and is deliberately still allowed.
foreach (['settings.php', 'flash_view.php'] as $page) {
    $src = (string) file_get_contents("$pluginDir/$page");
    check("$page does not redefine the button", !preg_match('~^\.lu-btn[^{]*\{[^}]*background~m', $src));
}
// Settings renders standalone, so it has to link the sheet the button lives in
// -- it did not before this, which is why it had a copy.
check('settings.php links chrome.css',
      str_contains((string) file_get_contents("$pluginDir/settings.php"), '/plugins/hbaviewer/chrome.css'));
// The focus ring has to reach that page too. It was scoped to #lu-wrap alone,
// which left every control on Settings without one.
check('the focus ring covers the settings wrapper',
      (bool) preg_match('~#lu-settings-wrap :focus-visible~', $chrome));

/* ── Icon wiring (design-system P1-C) ────────────────────────────────────────
   The dingbats are gone: U+26A0 takes emoji presentation on Windows and
   Android, which renders it in the font's own colour and ignores whatever the
   surrounding element set -- a danger marker that could not be made to look
   like one. They are sprite refs now, which fail in a quieter way: a <use>
   pointing at an id nothing defines renders NOTHING AT ALL, no gap, no
   fallback glyph, so a typo removes a warning sign from a firmware flasher and
   the page still looks fine.
   Hence both halves: every id referenced exists, and every page that
   references one pulls the sprite in. */
$refs = [];
foreach (glob("$pluginDir/{*.php,render/*.php,*.js}", GLOB_BRACE) ?: [] as $path) {
    $src = (string) file_get_contents($path);
    if (preg_match_all('~#lu-i-([a-z-]+)~', $src, $m)) {
        foreach ($m[1] as $id) { $refs[$id][] = basename($path); }
    }
}
$sprite = (string) file_get_contents("$pluginDir/icons.php");
preg_match_all('~id="lu-i-([a-z-]+)"~', $sprite, $dm);
$defined = $dm[1];
check('every icon referenced is defined in the sprite',
      $refs !== [] && array_diff(array_keys($refs), $defined) === []);
// health.php's row loop builds ids from data, so it is expected to reference
// icons no page names literally -- the reverse check would fail on those and
// is deliberately not made.

/* A fragment (render/*.php) is injected into a page that already carries the
   sprite; a top-level PAGE has to pull it in itself. settings.php is the one
   this protects: it renders on its own, and before P1-C it had no sprite,
   which is exactly why it was still using entities. */
foreach (['hbaviewer.php', 'settings.php', 'flash_view.php'] as $page) {
    $src = (string) file_get_contents("$pluginDir/$page");
    if (!str_contains($src, '#lu-i-')) continue;
    check("$page pulls in the icon sprite", str_contains($src, "require __DIR__ . '/icons.php'"));
}

// The entities these replaced, gone for good. Written as codepoints so this
// check cannot be satisfied by the very characters it is banning.
$banned = ['&#' . '9888;' => 'warning sign', '&#' . '9881;' => 'gear'];
foreach ($banned as $ent => $what) {
    $hits = [];
    foreach (glob("$pluginDir/{*.php,render/*.php}", GLOB_BRACE) ?: [] as $path) {
        if (str_contains((string) file_get_contents($path), $ent)) { $hits[] = basename($path); }
    }
    check("no $what dingbat entity survives", $hits === []);
}

array_map('unlink', glob("$cdir/*.json") ?: []);
@rmdir($cdir);
if ($hSaved1 === null) @unlink($hRing1); else file_put_contents($hRing1, $hSaved1);
if ($hSaved === null) @unlink($hRing); else file_put_contents($hRing, $hSaved);

/* ── Firmware verdict wiring (Task 4, round-1 review I3) ──────────────────────
   view_test.php pins fw_overview_clause() in isolation; nothing proved
   renderOverviewCards() actually CALLS it. A mutant that deletes the call
   site entirely left that suite green — the real render is exercised here so
   removing the wiring, not just the helper, fails a test. */
$fwOverview = ['controllers' => [[
    'status' => 'ok', 'board_name' => 'SAS9305-24i', 'model' => 'SAS3224',
    'firmware' => '15.00.00.00', 'subvendor_id' => '0x1000', 'topology' => 'internal',
]]];
$fwCfg = ['HBA_PORT' => 1, 'ALERT_THRESHOLD' => 60, 'SHOW_PCIE' => false];
$hFw = renderOverviewCards($fwOverview, $fwCfg);
check('the Overview card renders the firmware verdict clause', str_contains($hFw, '16.00.12.00 known'));

/* And renders it INSIDE the value span. `.lu-meta p` is a flex row with
   justify-content:space-between, so every direct child of the <p> becomes its
   own spaced column. Appending the clause as a sibling of the value span put
   three children on the row: the label pinned left, the version stranded in the
   middle and the verdict on the right edge — the label-left/value-right shape
   every other row has, broken on this one row only. Caught in a browser, not by
   this suite, because the assertion above only proves the text is present
   somewhere. This pins the structure that makes it land in the right place. */
check('the verdict sits inside the version span, not beside it',
      (bool) preg_match('~<p>Firmware: <span>[^<]*15\.00\.00\.00.*?known</span></span></p>~s', $hFw));

/* Round-1 review (Important, minor): a SAS2 pre-P20 card that also resolves a
   verdict used to show both '&#9888; pre-P20' and the clause on one line —
   two ambers stating the same fact. The clause is strictly more informative
   (it names the version) and wins; the older flag steps aside only when the
   clause actually has something to say. */
$sas2Behind = ['controllers' => [[
    'status' => 'ok', 'fw_old' => true, 'board_name' => 'SAS9211-8i', 'model' => 'SAS2008',
    'firmware' => '19.00.00.00', 'subvendor_id' => '0x1000', 'topology' => 'internal',
]]];
$hSas2Behind = renderOverviewCards($sas2Behind, $fwCfg);
check('a verdict clause suppresses the older pre-P20 flag',
    str_contains($hSas2Behind, '20.00.07.00 known') && !str_contains($hSas2Behind, 'pre-P20'));

// Same pre-P20 card, but an unindexed board: the verdict is 'unknown' and the
// clause is empty, so the flag it would otherwise duplicate must still show.
$sas2NoVerdict = ['controllers' => [[
    'status' => 'ok', 'fw_old' => true, 'board_name' => 'Some Unindexed Board', 'model' => 'SAS2008',
    'firmware' => '19.00.00.00', 'subvendor_id' => '0x1000', 'topology' => 'internal',
]]];
$hSas2NoVerdict = renderOverviewCards($sas2NoVerdict, $fwCfg);
check('the pre-P20 flag still shows when the verdict has nothing to say',
    str_contains($hSas2NoVerdict, 'pre-P20'));

/* ── A dual-IOC board is ONE card ─────────────────────────────────────────────
   A dual-IOC board renders as ONE card with a sub-card per controller. Both
   temperatures must survive: two dies, two sensors, and one number standing for
   two would be a wrong reading rather than a simplification. */
$dualCfg  = ['HBA_PORT' => 1, 'ALERT_THRESHOLD' => 76, 'SHOW_PCIE' => 1];
$dualData = ['driver' => 'mpt3sas 54.100.00.00', 'controllers' => [
    ['model' => 'SAS3008', 'board_name' => 'SAS9300-16i', 'card_id' => '0000:80:01.0',
     'temp' => 56, 'temp_band' => 'normal',   'cfg_band' => 'warning', 'status' => 'ok',
     'firmware' => '16.00.12.00', 'bios' => '08.15.00.00', 'mode' => 'IT',
     'pci_location' => '84:00', 'drive_count' => '2'],
    ['model' => 'SAS3008', 'board_name' => 'SAS9300-16i', 'card_id' => '0000:80:01.0',
     'temp' => 71, 'temp_band' => 'elevated', 'cfg_band' => 'warning', 'status' => 'warn',
     'firmware' => '16.00.12.00', 'bios' => '08.15.00.00', 'mode' => 'IT',
     'pci_location' => '86:00', 'drive_count' => '6'],
]];
$html = renderOverviewCards($dualData, $dualCfg);

check('one parent card for a dual-IOC board', substr_count($html, 'lu-card-parent') === 1);
check('a sub-card per controller',            substr_count($html, 'lu-card-ioc') === 2);
check('a gauge per controller',               substr_count($html, 'lu-arc-wrap') === 2);
check('a temperature band chip per controller', substr_count($html, 'lu-temp-band') === 2);
check('both temperatures are shown',          str_contains($html, '>56<') && str_contains($html, '>71<'));
check('the board name appears once',          substr_count($html, 'SAS9300-16i') === 1);
check('each IOC keeps its own drive count',
      str_contains($html, '>2 connected<') && str_contains($html, '>6 connected<'));

/* The worst-of badge, asserted on the VALUE and not merely on the wrapper's
   existence. A parent that always reports the first IOC's status is the
   likeliest way this ships broken: here IOC 0 is ok and IOC 1 is warn, so a
   parent reading NORMAL is a green light over an overheating die. */
check('the parent takes the worse status',    str_contains($html, 'lu-card-parent" data-status="warn"'));
/* The board's own badge is the one with no id -- every IOC badge carries
   `id="lu-badge-N"`. Matching on that rather than on "a WARNING somewhere after
   lu-card-parent", which the second IOC's own badge satisfies whatever the
   parent says. */
check('the parent badge reads the worse label',
      substr_count($html, '<span class="lu-badge">WARNING</span>') === 1);
check('each IOC still shows its own badge',
      str_contains($html, '>NORMAL</span>') && str_contains($html, '>WARNING</span>'));
/* Board-level facts are stated once, on the parent -- never repeated per IOC. */
check('board-level rows are not repeated per IOC',
      substr_count($html, '<p>Chip:') === 1 && substr_count($html, '<p>Firmware:') === 1
      && substr_count($html, '<p>BIOS:') === 1 && substr_count($html, '<p>Driver:') === 1
      && substr_count($html, '<p>Mode:') === 1 && substr_count($html, '<p>Last read:') === 1
      && substr_count($html, '<p>Badge Sensitivity:') === 1);

/* The case that must NOT change. A single-IOC card grows no wrapper: everyone
   without a dual board sees exactly the page they saw before. */
$soloData = ['driver' => 'mpt3sas 54.100.00.00', 'controllers' => [$dualData['controllers'][0]]];
$soloData['controllers'][0]['board_name'] = 'SAS9300-8i';
$soloData['controllers'][0]['card_id']    = '0000:00:11.0';
$solo = renderOverviewCards($soloData, $dualCfg);
check('a single-IOC card grows no parent wrapper',
      !str_contains($solo, 'lu-card-parent') && !str_contains($solo, 'lu-card-ioc'));

/* Real pipeline output for the maintainer's 9300-16i: two SAS3008s in one slot,
   both reading normal but at DIFFERENT temperatures (60 and 62) -- equal
   airflow, not one sensor. Same file tests/card_group_test.php feeds through
   the grouper, so the renderer is exercised on the hardware's own JSON. */
$realDual = json_decode((string) file_get_contents(__DIR__ . '/expected/storcli_dual.json'), true);
$realHtml = renderOverviewCards($realDual, $dualCfg);
check('the real dual-IOC capture renders one card',   substr_count($realHtml, 'lu-card-parent') === 1);
check('the real dual-IOC capture keeps both IOCs',    substr_count($realHtml, 'lu-card-ioc') === 2);
check('the real dual-IOC capture keeps both sensors',
      str_contains($realHtml, '>60<') && str_contains($realHtml, '>62<'));
/* Width, speed and power mode are the SLOT's: one row, on the board. PCI
   Location is NOT — each IOC answers to its own PCI function, and that address
   is what a person matches against lspci and `storcli /cN`. The flat page showed
   both 84:00 and 86:00; a single board-level row would have labelled
   one of them as the board's and dropped the other off the Overview entirely. */
/* The item COUNT is the real guard: the renderer skips the location by matching
   the label lsi_hba_view() gives it, so a rename there would silently restore
   the fourth item. The second clause asserts on the ADDRESS rather than on that
   same label — the row is the last thing in the card, so any `00:8…` appearing
   after it opens is a per-function address on a board-level row, whatever the
   label happens to be called. */
check('the board PCIe row carries only slot-level facts',
      substr_count($realHtml, 'lu-pcie-row') === 1
      && substr_count($realHtml, 'lu-pcie-item') === 3
      && !preg_match('~lu-pcie-row.*?00:8~s', $realHtml));
check('each IOC states its own PCI location',
      substr_count($realHtml, '<p>PCI Location:') === 2
      && str_contains($realHtml, '>84:00<') && str_contains($realHtml, '>86:00<'));
// SHOW_PCIE off hides the per-IOC location too, exactly as it hides the row.
check('SHOW_PCIE off hides the per-IOC location',
      !str_contains(renderOverviewCards($realDual, ['HBA_PORT' => 1, 'ALERT_THRESHOLD' => 76, 'SHOW_PCIE' => 0]),
                    'PCI Location'));

/* Non-adjacent members: lsi_group_cards() sorts groups by first member, so an
   unrelated card sitting BETWEEN the two IOCs still yields [[0,2],[1]]. The
   renderer must index $ctls by the member number, never by position. */
$sandwich = ['driver' => 'mpt3sas 54.100.00.00', 'controllers' => [
    $dualData['controllers'][0],
    ['model' => 'SAS2308', 'board_name' => 'SAS9207-8i', 'card_id' => '0000:00:11.0',
     'temp' => 48, 'temp_band' => 'normal', 'cfg_band' => 'warning', 'status' => 'ok',
     'firmware' => '20.00.07.00', 'mode' => 'IT', 'drive_count' => '4'],
    $dualData['controllers'][1],
]];
$sw = renderOverviewCards($sandwich, $dualCfg);
check('a card between the two IOCs still groups them', substr_count($sw, 'lu-card-parent') === 1);
check('the interloper renders as its own plain card',  str_contains($sw, 'SAS9207-8i'));
check('the grouped members are the right two',
      str_contains($sw, '>56<') && str_contains($sw, '>71<') && str_contains($sw, '>48<'));

/* ── The worst-of rollup, and WHICH member the board is read from ─────────────
   The fixtures above cannot see three real defects, because their only dual
   board is ordered (ok, warn): "worst child" and "last child" coincide, `alert`
   is never rendered, and the group's first member happens to be slot 0. This
   one puts an unrelated card in slot 0 and the two IOCs after it, in both
   orders, so the group is [1,2] and the two statuses that must be ranked are
   warn and alert.

   It kills four mutants the earlier fixtures let live: `$worst = $s`
   unconditional (parent = last child), `'alert' => 1` (alert ties warn, so a
   board with an alerting die reads WARNING), `$worst = $head['status']`, and
   `$ctls[$group[0]]` -> `array_values($ctls)[0]` (board read from slot 0
   instead of from the first MEMBER). */
$sloIoc   = ['model' => 'SAS2308', 'board_name' => 'SAS9207-8i', 'card_id' => '0000:00:11.0',
             'temp' => 48, 'temp_band' => 'normal', 'cfg_band' => 'warning', 'status' => 'ok',
             'firmware' => '20.00.07.00', 'mode' => 'IT', 'drive_count' => '4'];
$iocWarn  = $dualData['controllers'][1];                       // 71C, warn
$iocAlert = $dualData['controllers'][1];
$iocAlert['status']       = 'alert';
$iocAlert['temp']         = 79;
$iocAlert['temp_band']    = 'alert';
$iocAlert['pci_location'] = '00:88:00:00';

$A = renderOverviewCards(['driver' => 'd', 'controllers' => [$sloIoc, $iocWarn, $iocAlert]], $dualCfg);
$B = renderOverviewCards(['driver' => 'd', 'controllers' => [$sloIoc, $iocAlert, $iocWarn]], $dualCfg);
check('alert outranks warn whichever IOC carries it',
      substr_count($A, '<span class="lu-badge">ALERT</span>') === 1
   && substr_count($B, '<span class="lu-badge">ALERT</span>') === 1);
check('the parent data-status is the worst, not the last',
      str_contains($A, 'data-status="alert"') && str_contains($B, 'data-status="alert"'));
check('the parent is built from member 1, not slot 0',
      preg_match('~lu-card-parent.*?<p>Model: <span>SAS9300-16i~s', $A) === 1
   && substr_count($A, 'SAS9207-8i') === 1);

$completed = true;
echo $fails === 0 ? "ajax_render: all pass\n" : "ajax_render: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
