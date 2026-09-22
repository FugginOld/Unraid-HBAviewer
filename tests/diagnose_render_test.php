<?PHP
/* Runnable checks for render/diagnose.php. Pure functions over a decoded job,
   so the whole Verdict screen is testable with no /tmp, no job and no disk.
     php tests/diagnose_render_test.php  ->  "diagnose_render: all pass" */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/render/diagnose.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

/* ── decoding is forgiving ─────────────────────────────────────────────── */
$nd = "{\"t\":\"phase\",\"phase\":\"targeted\"}\n{ broken\n{\"t\":\"verdict\",\"v\":\"MEDIA\"}\n";
$ev = diag_events_decode($nd);
check('a valid line decodes',            count($ev) === 2);
// A truncated last line is normal -- the engine appends and the reader can
// arrive mid-write. Throwing there would blank the screen over a byte.
check('a broken line is skipped, not fatal', $ev[1]['v'] === 'MEDIA');
check('an empty file decodes to nothing',    diag_events_decode('') === []);

/* ── the verdict banner reads as prose, not as a code ──────────────────── */
$w = diag_verdict_words('TRANSPORT');
check('TRANSPORT has a title',  $w['title'] !== '');
// The spec's own sentence: the point is that replacing the drive will not fix
// it, and the banner has to say so or the user replaces the drive.
check('TRANSPORT says replacing the drive would not fix it',
      str_contains(strtolower($w['lead']), 'would not fix'));
check('MEDIA points at the platters',
      str_contains(strtolower(diag_verdict_words('MEDIA')['lead']), 'platters')
      || str_contains(strtolower(diag_verdict_words('MEDIA')['lead']), 'media'));
check('CLEAN says nothing reproduced',
      str_contains(strtolower(diag_verdict_words('CLEAN')['lead']), 'not reproduce')
      || str_contains(strtolower(diag_verdict_words('CLEAN')['lead']), 'no fault'));
// An unknown verdict must render as unknown, never as clean. Absence is not
// health, and a green banner over an unreadable run is a lie.
$u = diag_verdict_words('WAT');
check('an unknown verdict is not rendered as clean',
      $u['title'] !== diag_verdict_words('CLEAN')['title']);

/* ── three evidence cards: the reasoning, not just the conclusion ──────── */
$events = diag_events_decode(
    "{\"t\":\"chunk\",\"lba\":0,\"n\":64,\"op\":\"verify\",\"ms\":9,\"ok\":true}\n" .
    "{\"t\":\"chunk\",\"lba\":0,\"n\":64,\"op\":\"read\",\"ms\":900,\"ok\":false}\n" .
    "{\"t\":\"counter\",\"key\":\"disp\",\"before\":210,\"after\":214}\n" .
    "{\"t\":\"verdict\",\"disk\":\"sdb\",\"v\":\"TRANSPORT\",\"why\":\"verify clean, read failed\"}\n");
$cards = diag_evidence_cards($events);
check('there are exactly three evidence cards', count($cards) === 3);
check('the first card is SCSI VERIFY',  str_contains($cards[0]['title'], 'VERIFY'));
check('the second card is SCSI READ',   str_contains($cards[1]['title'], 'READ'));
check('the third card is counter movement',
      str_contains(strtolower($cards[2]['title']), 'counter'));
check('a clean verify reads as clean',  str_contains(strtolower($cards[0]['result']), 'clean'));
check('a failed read reads as failed',  str_contains(strtolower($cards[1]['result']), 'fail'));

/* ── the tested-ranges table ───────────────────────────────────────────── */
$rows = diag_ranges_rows($events, ['0x3' => 4], 92);
check('one row per tested range',            count($rows) === 1);
check('the row names the start LBA',         in_array('0', array_map('strval', $rows[0]), true));
check('the sense key evidence is carried',   str_contains(implode(' ', $rows[0]), '0x3'));
// Over a minute is a LINK timeout, not a media retry: drive-internal recovery
// gives up in 7-30 s. Saying so on the row is the difference between the user
// suspecting the drive and suspecting the cable.
check('a cmd_age over a minute is called a timeout',
      str_contains(strtolower(implode(' ', $rows[0])), 'timeout'));
$fast = diag_ranges_rows($events, [], 8);
check('a short cmd_age is not called a timeout',
      !str_contains(strtolower(implode(' ', $fast[0])), 'timeout'));

/* ── next steps, and the array-disk rule ───────────────────────────────── */
$steps = diag_next_steps('TRANSPORT', false);
check('TRANSPORT next steps are numbered actions', count($steps) >= 3);
check('they include moving the drive to another bay or cable',
      str_contains(strtolower(implode(' ', $steps)), 'cable'));
check('and explain how to read whether the fault followed the slot',
      str_contains(strtolower(implode(' ', $steps)), 'slot'));

// Phase 2's guarded reassign flow does not exist yet, so a MEDIA verdict on an
// ARRAY disk must state the rebuild/replace path in plain language. Writing a
// block straight to an assigned disk bypasses parity, and the next parity
// check flags it as a mismatch -- so "repair the sector" is never the answer
// here, whatever Phase 2 eventually offers for unassigned disks.
$ma = diag_next_steps('MEDIA', true);
check('MEDIA on an array disk gives rebuild/replace guidance',
      str_contains(strtolower(implode(' ', $ma)), 'rebuild'));
check('and never offers to repair the sector directly',
      !str_contains(strtolower(implode(' ', $ma)), 'sg_reassign')
      && !str_contains(strtolower(implode(' ', $ma)), 'write-sector'));
$mu = diag_next_steps('MEDIA', false);
check('MEDIA on an unassigned disk differs from the array case', $mu !== $ma);

/* ── the whole screen ──────────────────────────────────────────────────── */
$html = renderDiagVerdict([
    'disk' => 'sdb', 'verdict' => 'TRANSPORT', 'why' => 'verify clean, read failed x3',
    'events' => $events, 'sense' => ['0x3' => 4], 'max_cmd_age' => 92,
    'array_disk' => true, 'ports' => ['0x500...abc' => ['sdb', 'sdc']],
    'recent' => [['job' => 'sdc-1', 'disk' => 'sdc', 'verdict' => 'CLEAN']],
]);
check('the screen names the disk',            str_contains($html, 'sdb'));
check('the screen carries the verdict',       str_contains($html, 'TRANSPORT'));
check('the screen carries the tested ranges', str_contains($html, 'VERIFY'));
check('the screen carries the topology',      str_contains($html, 'sdc'));
check('the screen lists recent verdicts',     str_contains($html, 'sdc-1'));
// Every value here came off a disk or out of a kernel log. A model string with
// an angle bracket in it is the whole reason luTable and every renderer in
// this repo escape.
$evil = renderDiagVerdict([
    'disk' => '<img src=x>', 'verdict' => 'MEDIA', 'why' => '"><script>bad()</script>',
    'events' => [], 'sense' => [], 'max_cmd_age' => null,
    'array_disk' => false, 'ports' => [], 'recent' => [],
]);
check('the disk name is escaped', !str_contains($evil, '<img src=x>'));
check('the reason is escaped',    !str_contains($evil, '<script>bad()'));

/* ── the drive list sidebar ────────────────────────────────────────────── */
$drives = [
    ['dev' => 'sdb', 'role' => 'Disk 1'],
    ['dev' => 'sdc', 'role' => ''],
    ['dev' => 'sdd', 'role' => 'Disk 2'],
];
$list = renderDiagDriveList($drives, ['sdb' => 'CLEAN', 'sdc' => 'MEDIA', 'sdd' => 'STANDBY']);
check('the drive list renders every drive',
      str_contains($list, 'sdb') && str_contains($list, 'sdc') && str_contains($list, 'sdd'));
// Worst-first: the reason the list exists is to put the drive you should look
// at next at the top, so an alphabetical list defeats the feature.
check('MEDIA sorts above CLEAN', strpos($list, 'sdc') < strpos($list, 'sdb'));
check('unassigned drives are their own group',
      str_contains(strtolower($list), 'unassigned'));

echo $fails === 0 ? "diagnose_render: all pass\n" : "diagnose_render: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
