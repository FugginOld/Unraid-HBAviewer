<?PHP
/* Runnable check for event_archive.php: the dedup rule, the flash-wear cap, and
   the store round-trip — the merge that used to be welded inside the HTTP
   handler and had no coverage.
     php tests/event_archive_test.php  ->  "event_archive: all pass" (exit 0) */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/event_archive.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}
$ev = fn($seq, $time) => ['seq' => $seq, 'time' => $time, 'description' => "d$seq"];

// dedup: same seq|time is not appended twice; a new one is.
$hist = [$ev('1', 'a'), $ev('2', 'b')];
[$kept, $changed] = event_merge($hist, [$ev('2', 'b'), $ev('3', 'c')]);
check('dedup drops seen seq|time', count($kept) === 3);
check('dedup keeps new entry',     $kept[2]['seq'] === '3');
check('changed true on new',       $changed === true);

// no change when current is a subset of history -> caller must skip the write.
[, $changed2] = event_merge($hist, [$ev('1', 'a')]);
check('changed false when subset', $changed2 === false);

// same seq, different time counts as a distinct event (ring-buffer wrap reuses seq).
[$kept3] = event_merge([$ev('1', 'a')], [$ev('1', 'z')]);
check('seq reused at new time kept', count($kept3) === 2);

// lsiutil entries key on `timestamp` (no `time`); still dedup correctly.
$lu = fn($seq, $ts) => ['seq' => $seq, 'timestamp' => $ts];
[$keptlu, $chlu] = event_merge([$lu(1, 'x')], [$lu(1, 'x'), $lu(2, 'y')]);
check('lsiutil timestamp dedup', count($keptlu) === 2 && $chlu === true);

// cap: history stays at EVENT_ARCHIVE_CAP, keeping the newest entries.
$big = [];
for ($i = 0; $i < EVENT_ARCHIVE_CAP + 50; $i++) $big[] = $ev((string) $i, 't');
[$capped] = event_merge($big, [$ev('NEW', 'later')]);
check('cap holds at limit',   count($capped) === EVENT_ARCHIVE_CAP);
check('cap keeps newest',     end($capped)['seq'] === 'NEW');

// ordering: history first, then appended current, in input order.
[$ord] = event_merge([$ev('1', 'a')], [$ev('2', 'b'), $ev('3', 'c')]);
check('ordering preserved', array_column($ord, 'seq') === ['1', '2', '3']);

// store round-trip through a temp dir (no /boot).
$dir  = sys_get_temp_dir() . '/hbav_ev_' . getmypid();
$file = event_store_path(0, $dir);
check('missing store reads empty', event_store_read($file) === []);
event_store_write($file, $kept);
check('store round-trips', event_store_read($file) === $kept);
@unlink($file); @rmdir($dir);

// ── entry shape: the two backends emit different records into one archive ────
$sc = ['seq'=>'0x01','time'=>'Wed Jun  3 20:33:17 2020','code'=>'0x00','description'=>'Firmware init'];
$lu = ['seq'=>1,'qualifier'=>'0x0001','data'=>'00000000','timestamp'=>'00000000:000012ab'];
check('shape storcli',  event_shape($sc) === 'storcli');
check('shape lsiutil',  event_shape($lu) === 'lsiutil');
check('shape unknown',  event_shape(['seq'=>'9']) === '');
check('shape empty',    event_shape([]) === '');

// visible: keep only what the active backend can render, drop nothing on disk
$mixed = [$lu, $sc, $lu, $sc];
check('visible storcli count',  count(event_visible($mixed, 'storcli')) === 2);
check('visible lsiutil count',  count(event_visible($mixed, 'lsiutil')) === 2);
check('visible storcli shape',  event_shape(event_visible($mixed, 'storcli')[0]) === 'storcli');
check('visible reindexes',      array_keys(event_visible($mixed, 'storcli')) === [0, 1]);
check('visible preserves order',
    event_visible([$sc, $lu, $sc], 'storcli')[0]['description'] === 'Firmware init');
// empty backend: infer from the first entry, matching the renderer's key-sniff
check('visible infers storcli', count(event_visible([$sc, $lu], '')) === 1);
check('visible infers lsiutil', count(event_visible([$lu, $sc], '')) === 1);
check('visible unknown passes through', count(event_visible([['seq'=>'9']], '')) === 1);
check('visible empty list',     event_visible([], 'storcli') === []);

/* StorCLI2 is a different TOOL emitting the same record SHAPE as classic
   storcli, so one renderer serves both and the shape is what callers ask about.
   Folding here rather than in each renderer is also what keeps the event
   archive's own shape test working: no archived entry is ever tagged storcli2. */
check('shape: storcli2 folds onto storcli', lsi_backend_shape('storcli2') === 'storcli');
check('shape: storcli is itself',           lsi_backend_shape('storcli')  === 'storcli');
check('shape: lsiutil is itself',           lsi_backend_shape('lsiutil')  === 'lsiutil');
check('shape: empty stays empty',           lsi_backend_shape('')         === '');

// Documents the trap, not our code: passing the raw tool name straight through
// hides everything, because 'storcli2' is never a shape event_shape() produces.
// The renderer-level check in ajax_render_test.php pins the actual call site
// that has to fold it first.
check('visible: an unshaped storcli2 backend would hide everything',
    count(event_visible($mixed, 'storcli2')) === 0);

echo $fails === 0 ? "event_archive: all pass\n" : "event_archive: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
