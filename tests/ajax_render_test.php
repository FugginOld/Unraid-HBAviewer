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
check('phy storcli has speed col',   str_contains($h, '<th>Speed</th>'));
check('phy storcli has sas col',     str_contains($h, '<th>Attached SAS Address</th>'));
check('phy storcli link up badge',   str_contains($h, 'lu-link-up'));
check('phy storcli link down badge', str_contains($h, 'lu-link-down'));
check('phy storcli flags errors',    str_contains($h, 'lu-err-val'));
check('phy storcli uppercases sas',  str_contains($h, '5000CCA0'));

$phyLsi = ['backend' => 'lsiutil', 'controllers' => [['phys' => [
    ['phy'=>0,'link'=>'up','inv'=>1,'disp'=>2,'sync'=>3,'reset'=>4],
]]]];
$h = renderPhyTables($phyLsi);
check('phy lsiutil omits speed col', !str_contains($h, '<th>Speed</th>'));
check('phy lsiutil has counters',    str_contains($h, '<th>Invalid DWords</th>'));

/* ── PHY: degenerate inputs must not fatal ───────────────────────────────── */
check('phy controller error row', str_contains(
    renderPhyTables(['backend'=>'storcli','controllers'=>[['error'=>'no response']]]), 'no response'));
check('phy empty phys', str_contains(
    renderPhyTables(['backend'=>'storcli','controllers'=>[[]]]), 'No PHY data.'));
check('phy multi heads controllers', str_contains(
    renderPhyTables(['backend'=>'storcli','controllers'=>[['phys'=>[]],['phys'=>[]]]]), 'Controller /c1'));
check('phy single omits head', !str_contains(
    renderPhyTables(['backend'=>'storcli','controllers'=>[['phys'=>[]]]]), 'Controller /c0'));

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
check('phy delta rendered',   str_contains($h, '&Delta;150'));
check('phy rate rendered',    str_contains($h, '150/hr'));
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
check('phy lsiutil delta rendered', str_contains($h, '&Delta;150') && str_contains($h, '150/hr'));

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

/* ── Drives: backend picks columns; enclosure summary renders ────────────── */
$drvStorcli = ['backend' => 'storcli', 'controllers' => [[
    'enclosures' => [['eid'=>'8','product'=>'VirtualSES','vendor'=>'LSI','slots'=>'8','drives'=>'4','direct'=>1]],
    'drives' => [['slot'=>'8/0','port'=>'14','model'=>'ST8000NM','serial'=>'ZA1ABCDE','state'=>'JBOD',
                  'size'=>'7.276 TB','sas_address'=>'5000c500a1b2c3d4','link'=>'12.0Gb/s','firmware'=>'SN02']],
]]];
$h = renderDrivesTables($drvStorcli);
check('drives storcli col set',    str_contains($h, '<th>Encl:Slot</th>') && str_contains($h, '<th>Firmware</th>'));
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
check('drives lsiutil col set', str_contains($h, '<th>Bus:Tgt</th>') && str_contains($h, '<th>OS Device</th>'));
check('drives lsiutil no smart btn', !str_contains($h, 'luSmart(this'));
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
check('events storcli col set', str_contains($h, '<th>Description</th>'));
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
check('events lsiutil col set', str_contains($h, '<th>Qualifier</th>') && !str_contains($h, '<th>Description</th>'));

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
check('mixed archive: storcli columns',  str_contains($h, '<th>Description</th>'));
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
// Two samples 120s apart with flat counters: link_integrity needs >= 60s of ring
// to be anything but `unknown`, and identical counters make it `ok`.
$hRender = function (array $ctl, array $seed) use ($hRing): string {
    health_store_write($hRing, [$seed]);
    return renderHealthTables(['controllers' => [$ctl]]);
};
$okDark  = lsi_health_gradient('ok')[0];
$rowsOf  = fn(string $h) => substr_count($h, 'class="lu-indicator-row"');
$greenOf = fn(string $h) => substr_count($h, '<span class="lu-ind-dot" style="--gd:' . $okDark . ';');

$now = time();
$h = $hRender($hs($now, 3600, '77', 'warning'), $hs($now - 120, 3480, '76', 'warning'));

check('health five rows render', $rowsOf($h) === 5);
foreach (['Thermal', 'Link Integrity', 'Topology', 'Host Link', 'Read Health'] as $lbl) {
    check("health row '$lbl'", str_contains($h, '<span class="lu-indicator-label">' . $lbl . '</span>'));
}
// Order must match hbaviewer.php's header sentence and health_indicators()'s keys.
$pos = array_map(fn($l) => strpos($h, ">$l</span>"), ['Thermal', 'Link Integrity', 'Topology', 'Host Link', 'Read Health']);
$sorted = $pos; sort($sorted, SORT_NUMERIC);
check('health rows in header order', !in_array(false, $pos, true) && $pos === $sorted);
check('health thermal shows temp', str_contains($h, '<span class="lu-indicator-value">77°C</span>'));

/* Row icons (plan 032). Two indicator keys do not match their sprite id
   (`link_integrity` -> lu-i-link, `host_link` -> lu-i-hostlink); a mismatch
   renders an empty icon slot silently, so assert the ids AND that every one is
   actually defined in hbaviewer.php's sprite. */
preg_match_all('~<use href="#(lu-i-[a-z]+)"/>~', $h, $mIco);
check('health rows emit five icons', $mIco[1] === ['lu-i-thermal', 'lu-i-link', 'lu-i-topology', 'lu-i-hostlink', 'lu-i-controller']);

$shell = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php');
preg_match_all('~<symbol id="(lu-i-[a-z]+)"~', $shell, $mSym);
check('every icon resolves to a defined symbol', $mIco[1] && !array_diff($mIco[1], $mSym[1]));
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
$h = $hRender($hs($now, 3600, null, ''), $hs($now - 120, 3480, null, ''));
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
$h = $hRender($down, $hs($now - 120, 3480, '45', 'normal'));
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
    check("shell: tab-$tab pane has no card wrapper",
          (bool) preg_match('~<div id="tab-' . $tab . '" class="lu-tab-pane[^"]*">\s*<div class="lu-tab-toolbar">~', $shell));
}

array_map('unlink', glob("$cdir/*.json") ?: []);
@rmdir($cdir);
if ($hSaved1 === null) @unlink($hRing1); else file_put_contents($hRing1, $hSaved1);
if ($hSaved === null) @unlink($hRing); else file_put_contents($hRing, $hSaved);

$completed = true;
echo $fails === 0 ? "ajax_render: all pass\n" : "ajax_render: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
