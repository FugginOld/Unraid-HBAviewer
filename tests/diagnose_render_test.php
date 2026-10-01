<?PHP
/* Runnable checks for render/diagnose.php. Pure functions over a decoded job,
   so the whole Verdict screen is testable with no /tmp, no job and no disk.
     php tests/diagnose_render_test.php  ->  "diagnose_render: all pass" */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/render/diagnose.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/diagnose_lib.php';

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

/* ── counter movement is tri-state: absence is not health ──────────────── */
// No counter event at all: the counters were never collected (no triage ran),
// which is a different fact from "collected and flat" -- must not read 'none'.
check('with no counter events, the third card reads not run, not none',
      diag_evidence_cards([])[2]['result'] === 'not run');
// Counter events present but every delta is zero: this IS "collected and
// flat", so the other side of the tri-state must still read 'none'.
$flatCounters = diag_events_decode(
    "{\"t\":\"counter\",\"key\":\"disp\",\"before\":210,\"after\":210}\n" .
    "{\"t\":\"counter\",\"key\":\"uncorr\",\"before\":3,\"after\":3}\n");
check('counter events present with all-zero deltas still reads none',
      diag_evidence_cards($flatCounters)[2]['result'] === 'none');

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

/* ── STANDBY: a Diagnose that reached a sleeping disk, nothing read ────── */
$sw = diag_verdict_words('STANDBY');
check('STANDBY title says left asleep', str_contains($sw['title'], 'Left asleep'));

$standbySteps = diag_next_steps('STANDBY', false);
check('STANDBY next steps say to spin the drive up',
      str_contains(strtolower(implode(' ', $standbySteps)), 'spin'));
check('STANDBY next steps say HBAviewer never wakes a drive on purpose',
      str_contains(strtolower(implode(' ', $standbySteps)), 'never wakes a sleeping drive'));

$standbyHtml = renderDiagVerdict([
    'disk' => 'sdd', 'verdict' => 'STANDBY', 'why' => 'left asleep -- Diagnose never spins a disk up',
    'events' => [], 'sense' => [], 'max_cmd_age' => null,
    'array_disk' => true, 'ports' => [], 'recent' => [],
]);
check('the STANDBY screen shows the Left asleep title',
      str_contains($standbyHtml, 'Left asleep'));
check('the STANDBY screen carries both next steps',
      str_contains($standbyHtml, 'Spin the drive up')
      && str_contains($standbyHtml, 'never wakes a sleeping drive'));
// No block was read, so all three evidence cards -- VERIFY, READ, and counter
// movement -- must read 'not run', never 'clean' or 'none': absence is not
// health (docs/review-policy.md).
check('all three evidence cards read not run on the STANDBY screen',
      substr_count($standbyHtml, 'not run') === 3);
check('the STANDBY screen says no range was tested',
      str_contains($standbyHtml, 'No range was tested'));

/* ── POWER_UNKNOWN: smartctl could not name the power state, nothing read ─ */
$pw = diag_verdict_words('POWER_UNKNOWN');
check('POWER_UNKNOWN has its own title, not the did-not-classify fallback',
      str_contains($pw['title'], 'Power state unknown'));
$pwHtml = renderDiagVerdict([
    'disk' => 'sdx', 'verdict' => 'POWER_UNKNOWN', 'why' => 'power state unknown',
    'events' => [], 'sense' => [], 'max_cmd_age' => null,
    'array_disk' => true, 'ports' => [], 'recent' => [],
]);
check('the POWER_UNKNOWN screen says it never tests a drive it cannot prove is awake',
      str_contains($pwHtml, 'cannot prove is awake'));
check('the POWER_UNKNOWN screen gives no parity advice',
      !str_contains(strtolower($pwHtml), 'parity check'));
check('all three evidence cards read not run on the POWER_UNKNOWN screen',
      substr_count($pwHtml, 'not run') === 3);
check('POWER_UNKNOWN sorts with STANDBY, below CLEAN',
      (DIAG_BADGE_RANK['POWER_UNKNOWN'] ?? 9) === DIAG_BADGE_RANK['STANDBY']);

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
// The fixture above has its worst drive in the UNASSIGNED group, so a
// hardcoded "unassigned always renders first" rule would pass that check by
// coincidence. Pin the actual rule with the worst drive on the OTHER side:
// an assigned disk with the worst badge, against an unassigned one that's
// healthy -- assigned must still render first.
$list2 = renderDiagDriveList(
    [['dev' => 'sda', 'role' => 'Disk 1'], ['dev' => 'sdz', 'role' => '']],
    ['sda' => 'MEDIA', 'sdz' => 'CLEAN']);
check('an assigned disk with the worst badge still sorts first',
      strpos($list2, 'sda') < strpos($list2, 'sdz'));

/* ── the Phase 1 bug this phase fixes: parity and pool are ASSIGNED ─────────
   End to end through diag_array_disk(): a MEDIA verdict on a parity or pool
   disk must get the rebuild advice, never "not assigned to the array or a
   pool" -- which invites writing to a disk parity or the pool depends on. */
$pini = sys_get_temp_dir() . '/hbav_render_ini_' . getmypid() . '.ini';
file_put_contents($pini, "[\"parity\"]\nname=\"parity\"\ndevice=\"sdp\"\n"
                       . "[\"cache\"]\nname=\"cache\"\ndevice=\"sdc\"\n");
foreach (['sdp' => 'parity', 'sdc' => 'pool'] as $dev => $what) {
    $h = renderDiagVerdict(['disk' => $dev, 'verdict' => 'MEDIA', 'why' => '', 'events' => [],
        'sense' => [], 'max_cmd_age' => null, 'array_disk' => diag_array_disk($dev, $pini),
        'ports' => [], 'recent' => []]);
    check("a $what disk's MEDIA verdict gets the rebuild advice, not the unassigned one",
          str_contains($h, 'REBUILD') && !str_contains($h, 'not assigned to the array or a pool'));
}
@unlink($pini);

/* ── the Repair screen (Phase 2a): evidence, never a control ───────────── */
$ledger = [
    ['chunk_start' => 10240, 'confirm_count' => 2, 'first_run' => 'r1', 'last_run' => '20260102-000000', 'last_class' => 'media',        'updated' => 1],
    ['chunk_start' => 20480, 'confirm_count' => 1, 'first_run' => 'r1', 'last_run' => 'r1',              'last_class' => 'media',        'updated' => 1],
    ['chunk_start' => 30720, 'confirm_count' => 0, 'first_run' => 'r1', 'last_run' => 'r2',              'last_class' => 'transport',    'updated' => 1],
    ['chunk_start' => 4096,  'confirm_count' => 3, 'first_run' => 'r0', 'last_run' => 'r3',              'last_class' => 'intermittent', 'updated' => 1],
    ['chunk_start' => 8192,  'confirm_count' => 2, 'first_run' => 'r0', 'last_run' => 'r4',              'last_class' => 'transport',    'updated' => 1],
];
$rep = renderDiagRepair(['disk' => 'sdc', 'array_disk' => false, 'serial' => 'S1', 'rows' => $ledger]);
check('unassigned: every confirm_count >= 2 row is listed',
      str_contains($rep, '<td>10240</td>') && str_contains($rep, '<td>4096</td>') && str_contains($rep, '<td>8192</td>'));
check('unassigned: a single-run media row is not', !str_contains($rep, '20480'));
check('unassigned: a never-confirmed transport row is not in the table', !str_contains($rep, '30720'));
check('but it is counted in the link line', str_contains($rep, '1 range failed only over the link'));
// A row media-confirmed twice whose LATEST run was transport did fail VERIFY
// twice: it belongs in the table, and "failed only over the link" is false for it.
check('a confirmed row whose latest run was transport stays in the table and out of the link count',
      str_contains($rep, '<td>8192</td>') && !str_contains($rep, '2 ranges failed only over the link'));
check('rows are ordered by start LBA',
      strpos($rep, '<td>4096</td>') < strpos($rep, '<td>8192</td>')
      && strpos($rep, '<td>8192</td>') < strpos($rep, '<td>10240</td>'));
check('the block count is the chunk size', str_contains($rep, '<td>2048</td>'));
preg_match_all('/onclick="([A-Za-z]+)\(/', $rep, $m);
check('the only handlers are navigation and column sort -- no repair control',
      array_diff(array_unique($m[1]), ['luDiagShow', 'luSort']) === []
      && !preg_match('/<(input|select|textarea)\b/', $rep));
check('nothing on the screen names a repair tool',
      !str_contains($rep, 'sg_reassign') && !str_contains($rep, 'write-sector'));

$arr = renderDiagRepair(['disk' => 'sdb', 'array_disk' => true, 'rows' => $ledger]);
check('array disk: direct sector repair is refused, and why', str_contains(strtolower($arr), 'bypasses parity'));
check('array disk: all three options are shown together',
      str_contains($arr, 'Replace') && str_contains($arr, 'Rebuild onto itself')
      && str_contains($arr, 'Keep in service and watch'));
check('array disk: no table, even when the ledger has confirmed rows',
      !str_contains($arr, '<table') && !str_contains($arr, '10240'));
preg_match_all('/onclick="([A-Za-z]+)\(/', $arr, $m);
check('array disk: no control beyond navigation', array_diff(array_unique($m[1]), ['luDiagShow']) === []);

$none = renderDiagRepair(['disk' => 'sdc', 'array_disk' => false, 'serial' => 'S1', 'rows' => [$ledger[2]]]);
check('empty state: no media range yet says so', str_contains(strtolower($none), 'no media-class range'));
check('and is not a false all-clear', !str_contains(strtolower($none), 'no issues'));
check('and still counts the transport row', str_contains($none, '1 range failed only over the link'));
$one = renderDiagRepair(['disk' => 'sdc', 'array_disk' => false, 'serial' => 'S1', 'rows' => [$ledger[1]]]);
check('empty state: media on one run only says a second run is needed',
      str_contains(strtolower($one), 'one run only') && str_contains(strtolower($one), 'second run'));
$zero = renderDiagRepair(['disk' => 'sdc', 'array_disk' => false, 'serial' => 'S1', 'rows' => []]);
check('an empty ledger is the no-media note, not a blank screen',
      str_contains(strtolower($zero), 'no media-class range'));
check('both empty states point back to Diagnose',
      str_contains($none, 'Run Diagnose on this drive again') && str_contains($one, 'Run Diagnose on this drive again'));
// Unknown serial: the history cannot be matched to this drive, so it shows
// NOTHING -- not a table (possibly another drive's), and not the no-media note
// (which would read as a finding about this drive).
$nos = renderDiagRepair(['disk' => 'sdc', 'array_disk' => false, 'serial' => '', 'rows' => $ledger]);
check('unknown serial: says the history cannot be matched',
      str_contains(strtolower($nos), 'serial could not be read'));
check('unknown serial: no table and no ledger-derived note',
      !str_contains($nos, '<table') && !str_contains($nos, '10240')
      && !str_contains(strtolower($nos), 'no media-class range') && !str_contains($nos, 'failed only over the link'));

$evilR = renderDiagRepair(['disk' => '<img src=x>', 'array_disk' => false, 'serial' => 'S1', 'rows' => [
    ['chunk_start' => 1, 'confirm_count' => 2, 'first_run' => 'a', 'last_run' => '<script>bad()</script>',
     'last_class' => 'media', 'updated' => 1]]]);
check('repair: the disk name is escaped', !str_contains($evilR, '<img src=x>'));
check('repair: ledger text is escaped',   !str_contains($evilR, '<script>bad()'));

// The "Blocks" column is the engine's CHUNK. Two languages, one number.
$sh = (string) file_get_contents(__DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh');
check("the displayed block count is the engine's own CHUNK",
      preg_match('/^CHUNK="(\d+)"/m', $sh, $cm) === 1 && (int) $cm[1] === DIAG_LEDGER_CHUNK);

echo $fails === 0 ? "diagnose_render: all pass\n" : "diagnose_render: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
