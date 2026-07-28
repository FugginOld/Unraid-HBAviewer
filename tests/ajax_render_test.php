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

$completed = true;
echo $fails === 0 ? "ajax_render: all pass\n" : "ajax_render: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
