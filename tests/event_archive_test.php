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

echo $fails === 0 ? "event_archive: all pass\n" : "event_archive: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
