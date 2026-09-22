<?PHP
/* Runnable checks for disk_alerts.php -- the two checks salvaged from
   sas_error_monitor.sh, and the two bugs they must not carry in.
     php tests/disk_alerts_test.php  ->  "disk_alerts: all pass" (exit 0) */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/disk_alerts.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}
$state = sys_get_temp_dir() . '/hbav_da_' . getmypid() . '.json';
@unlink($state);

/* ── grown defect list: only an INCREASE is news ───────────────────────── */
check('an increase is a rise',
      disk_alert_defect_rises(['ata-X' => 4], ['ata-X' => 7])
      === [['disk' => 'ata-X', 'from' => 4, 'to' => 7]]);
check('an unchanged count is not a rise',
      disk_alert_defect_rises(['ata-X' => 4], ['ata-X' => 4]) === []);
// A defect list that came back smaller is not a drive healing. It is a read
// this code cannot explain, and notifying on it trains the user to ignore the
// channel that carries the real one.
check('a decrease is not a rise',
      disk_alert_defect_rises(['ata-X' => 9], ['ata-X' => 4]) === []);
// First sighting is history, not an event -- the rule notify_transitions()
// applies to a newly installed card, for the same reason.
check('a first sighting is not a rise',
      disk_alert_defect_rises([], ['ata-X' => 12]) === []);
check('a vanished disk is not a rise',
      disk_alert_defect_rises(['ata-X' => 4], []) === []);

/* ── the run: state in, notifications out, state back ──────────────────── */
$sent = [];
$send = function (string $s, string $d, string $i) use (&$sent) { $sent[] = [$s, $d, $i]; };

@unlink($state);
$sent = [];
$r = disk_alert_run(['ata-X' => 4], ['max_seq' => 100, 'events' => []], $send, $state, 1_700_000_000);
check('a first run notifies nothing',         $sent === []);
check('a first run still records the cursor', $r['seq'] === 100);

$sent = [];
$r = disk_alert_run(['ata-X' => 6], ['max_seq' => 140, 'events' => []], $send, $state, 1_700_000_100);
check('the second run notifies the rise', count($sent) === 1);
check('the notification names the disk',  str_contains($sent[0][0], 'ata-X'));
check('a defect rise is a warning',       $sent[0][2] === 'warning');

/* ── kernel medium errors ──────────────────────────────────────────────── */
$sent = [];
$r = disk_alert_run(['ata-X' => 6], ['max_seq' => 160, 'events' => [
        ['seq' => 155, 'dev' => 'sdf', 'text' => 'critical medium error, dev sdf, sector 1234567'],
     ]], $send, $state, 1_700_000_200);
check('a medium error notifies',    count($sent) === 1);
check('a medium error is an alert', $sent[0][2] === 'alert');
check('the cursor advances past it', $r['seq'] === 160);

// The cursor is the whole point: without it one bad sector notifies every ten
// minutes for as long as the box is up.
$sent = [];
disk_alert_run(['ata-X' => 6], ['max_seq' => 160, 'events' => []], $send, $state, 1_700_000_300);
check('a cursor already past the event is silent', $sent === []);

// A reboot restarts /dev/kmsg at 0, leaving the stored cursor AHEAD of the
// whole buffer -- which would silence the channel until the box organically
// passed the old number. Hours of no alerts, feature still ticked.
$sent = [];
$r = disk_alert_run(['ata-X' => 6], ['max_seq' => 8, 'events' => [
        ['seq' => 5, 'dev' => 'sdf', 'text' => 'critical medium error, dev sdf, sector 9'],
     ]], $send, $state, 1_700_000_400);
check('a sequence reset is detected and not swallowed', count($sent) === 1);
check('and the cursor follows the buffer back down',    $r['seq'] === 8);

/* ── boot_id: authoritative detection of double reboot edge case ───────────── */
// The scenario: a first reset brings the cursor down to a low value (say 8).
// A SECOND reboot happens before the next check. Ordinary boot-time kernel
// log volume easily produces more than 8 messages before the next cron check,
// so by the time disk_alert_run() runs again, max_seq (e.g. 20) is no longer
// < prev['seq'] (8) -- the sequence heuristic alone would miss the reset and
// the cursor would stay stale, silently losing a genuine early-sequence event.
// Boot-id mismatch is the authoritative reset signal.
@unlink($state);
$sent = [];
// First boot (boot_id_1): record a low cursor (e.g. 8)
$r = disk_alert_run(['ata-X' => 6], ['max_seq' => 8, 'events' => []], $send, $state, 1_700_000_500, 'boot_id_1');
check('first boot records boot_id_1',               count($sent) === 0);

$sent = [];
// SECOND reboot (boot_id_2, much higher max_seq due to boot-time log volume):
// The sequence-only heuristic would NOT trigger reset (20 > 8).
// Boot_id mismatch MUST detect and fire the reset.
$r = disk_alert_run(['ata-X' => 6], ['max_seq' => 20, 'events' => [
        ['seq' => 3, 'dev' => 'sdf', 'text' => 'critical medium error, dev sdf, sector 999'],
     ]], $send, $state, 1_700_000_600, 'boot_id_2');
check('boot_id mismatch detects second reboot',     $r['seq'] === 20);
check('genuine early-sequence event is NOT lost',   count($sent) === 1);
check('early-seq event after reboot reaches alerts', $sent[0][2] === 'alert');

// Verify backward compatibility: omitting bootId falls through to sequence heuristic.
@unlink($state);
$sent = [];
$r = disk_alert_run(['ata-X' => 4], ['max_seq' => 100, 'events' => []], $send, $state, 1_700_000_700);
check('5-arg call (no bootId) still works',        count($sent) === 0);

@unlink($state);
echo $fails === 0 ? "disk_alerts: all pass\n" : "disk_alerts: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
