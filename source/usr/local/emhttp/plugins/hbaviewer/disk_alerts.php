<?PHP
/* HBAviewer per-disk alerts — the two checks worth keeping from the standalone
 * sas_error_monitor.sh, merged into the cron notification pipeline rather than
 * shipped as a second script that overlaps the SMART tab.
 *
 *   1. Kernel "critical medium error" records, cursored by /dev/kmsg SEQUENCE
 *      NUMBER. The original counted dmesg lines, so once the ring buffer
 *      wrapped the count stopped growing and every later event was missed.
 *   2. An INCREASE in a disk's grown defect list. A raw count is history; the
 *      change is the event.
 *
 * Everything except disk_alert_send() is pure over injected inputs, so
 * tests/disk_alerts_test.php covers the whole decision path with no /boot, no
 * hardware and no notify binary. Store shape matches notify.php and
 * phy_baseline.php: *_read(?string $path) / *_write(array, ?string $path), a
 * const default, path always injectable.
 *
 * Disks are keyed by their stable /dev/disk/by-id name, never by sd letter --
 * sd letters shift across reboots, so a letter-keyed baseline subtracts two
 * different disks from each other and calls the difference an increase.
 */

const DISK_ALERT_STATE = '/boot/config/plugins/hbaviewer/disk_alerts.json';
const DISK_ALERT_BIN   = '/usr/local/emhttp/webGui/scripts/notify';

/* {"seq": int, "defects": {disk_id: int}} */
function disk_alert_state_read(?string $path = null): array {
    $path ??= DISK_ALERT_STATE;
    $s = is_file($path) ? (json_decode((string) @file_get_contents($path), true) ?: []) : [];
    return ['seq' => (int) ($s['seq'] ?? 0), 'defects' => (array) ($s['defects'] ?? [])];
}
function disk_alert_state_write(array $state, ?string $path = null): void {
    $path ??= DISK_ALERT_STATE;
    @mkdir(dirname($path), 0755, true);
    @file_put_contents($path, json_encode([
        'seq'     => (int) ($state['seq'] ?? 0),
        'defects' => (array) ($state['defects'] ?? []),
    ]));
}

/* [disk_id => count] twice; one row per INCREASE. Three things are
   deliberately not a rise: a disk absent from $previous (first sighting is
   history), an unchanged count, and a DECREASE — a defect list that shrank is
   a read this code cannot explain rather than a drive that healed. */
function disk_alert_defect_rises(array $previous, array $current): array {
    $out = [];
    foreach ($current as $disk => $now) {
        if (!isset($previous[$disk])) continue;
        $was = (int) $previous[$disk];
        if ((int) $now > $was) $out[] = ['disk' => (string) $disk, 'from' => $was, 'to' => (int) $now];
    }
    return $out;
}

function disk_alert_send(string $subject, string $description, string $importance): void {
    shell_exec(DISK_ALERT_BIN
        . ' -e ' . escapeshellarg('HBAviewer')
        . ' -s ' . escapeshellarg($subject)
        . ' -d ' . escapeshellarg($description)
        . ' -i ' . escapeshellarg($importance)
        . ' >/dev/null 2>&1');
}

/* $defects: [disk_id => grown-defect count], collected with -n standby by the
   caller. $kmsg: the decoded payload from scripts/parse/kmsg.sh.
   Returns what it fired and the cursor it stored. */
function disk_alert_run(array $defects, array $kmsg, ?callable $send = null,
                        ?string $path = null, ?int $now = null): array {
    $send ??= 'disk_alert_send';
    $now  ??= time();
    $prev   = disk_alert_state_read($path);

    $maxSeq = (int) ($kmsg['max_seq'] ?? 0);
    /* A reboot restarts /dev/kmsg at 0, so a stored cursor can sit AHEAD of the
       whole buffer. Left alone that silences medium-error reporting until the
       box organically passed the old number -- hours or days of nothing, with
       the setting still ticked. A max_seq below the cursor is the only evidence
       of the reset available here, and acting on it costs at most one repeat of
       an error that is still present. */
    $cursor = $maxSeq < $prev['seq'] ? 0 : $prev['seq'];

    $medium = [];
    foreach ((array) ($kmsg['events'] ?? []) as $e) {
        if ((int) ($e['seq'] ?? 0) <= $cursor) continue;
        $medium[] = $e;
        $send('Critical medium error on ' . (($e['dev'] ?? '') !== '' ? $e['dev'] : 'an unknown device'),
              (string) ($e['text'] ?? ''), 'alert');
    }

    $rises = disk_alert_defect_rises($prev['defects'], $defects);
    foreach ($rises as $r) {
        $send($r['disk'] . ' grown defect list increased',
              'Was ' . $r['from'] . ', now ' . $r['to']
            . '. The drive has remapped more sectors since the last check.', 'warning');
    }

    disk_alert_state_write(['seq' => $maxSeq, 'defects' => $defects], $path);
    return ['defects' => $rises, 'medium' => $medium, 'seq' => $maxSeq];
}
