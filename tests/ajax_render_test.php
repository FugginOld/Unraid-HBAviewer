<?PHP
/* Runnable checks for the ajax_info.php render layer: the table builders behind
   the PHY / Drives / Event Log / SMART tabs. These are the ~250 lines that
   produce what the user actually looks at, and until now nothing covered them.
   No HTTP, no hardware — ajax_info.php returns early under CLI, so requiring it
   loads the render functions without running any dispatch.
     php tests/ajax_render_test.php  ->  "ajax_render: all pass" (exit 0) */

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

$evLsi = ['backend' => 'lsiutil', 'controllers' => [['entries' => [
    ['seq'=>'7','qualifier'=>'0x02','data'=>'00 11 22','timestamp'=>'0x0001d4c0'],
]]]];
$h = renderEventsTables($evLsi, $dir);
check('events lsiutil col set', str_contains($h, '<th>Qualifier</th>') && !str_contains($h, '<th>Description</th>'));
check('events note rendered', str_contains(
    renderEventsTables(['backend'=>'storcli','controllers'=>[['note'=>'expert mode required','entries'=>[]]]], $dir),
    'expert mode required'));

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

/* ── luTable: headers are escaped, cells are passed through as markup ─────── */
$t = luTable(['A & B'], [['<code>x</code>']]);
check('luTable escapes headers', str_contains($t, 'A &amp; B'));
check('luTable cells are html',  str_contains($t, '<code>x</code>'));

echo $fails === 0 ? "ajax_render: all pass\n" : "ajax_render: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
