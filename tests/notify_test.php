<?PHP
/* Runnable check for notify.php: the transition rule (the whole point — one
   notification per CHANGE, never one per poll), the controller identity key,
   and the store round-trip through a temp path.
     php tests/notify_test.php  ->  "notify: all pass" (exit 0) */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/notify.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

/* One controller payload, the fields lsi_notify_* actually reads. */
function ctrl(string $status, string $board = 'SAS9300-8i', string $pci = '00:c1:00:00'): array {
    return ['status' => $status, 'board_name' => $board, 'pci_location' => $pci, 'model' => 'SAS3008'];
}

// ── notify_transitions(): pure ───────────────────────────────────────────────
check('no previous entry -> nothing',
    notify_transitions([], ['a' => 'alert']) === []);
check('same status -> nothing',
    notify_transitions(['a' => 'warn'], ['a' => 'warn']) === []);
check('ok->warn is one transition',
    notify_transitions(['a' => 'ok'], ['a' => 'warn'])
    === [['controller' => 'a', 'from' => 'ok', 'to' => 'warn']]);
check('removed then re-added -> nothing',
    notify_transitions(['a' => 'ok'], []) === []
    && notify_transitions([], ['a' => 'alert']) === []);
check('only the changed controller fires',
    notify_transitions(['a' => 'ok', 'b' => 'ok'], ['a' => 'ok', 'b' => 'alert'])
    === [['controller' => 'b', 'from' => 'ok', 'to' => 'alert']]);

// ── identity key ─────────────────────────────────────────────────────────────
check('key uses board+pci',       lsi_notify_key(ctrl('ok'), 0) === 'SAS9300-8i@00:c1:00:00');
check('two identical boards differ',
    lsi_notify_key(ctrl('ok', 'SAS9300-8i', '00:c1:00:00'), 0)
    !== lsi_notify_key(ctrl('ok', 'SAS9300-8i', '00:65:00:00'), 1));
check('blank board falls back to pci', lsi_notify_key(['pci_location' => '0:1'], 3) === '0:1');
check('nothing reported -> index',     lsi_notify_key([], 3) === 'ctrl-3');
check('errored controller has no status',
    lsi_notify_statuses([['error' => 'No response from the HBA.']]) === []);

// ── importance + message ─────────────────────────────────────────────────────
check('alert -> alert',   lsi_notify_importance('alert') === 'alert');
check('warn -> warning',  lsi_notify_importance('warn')  === 'warning');
check('ok -> normal',     lsi_notify_importance('ok')    === 'normal');
check('recovery reads as recovery',
    lsi_notify_message(['from' => 'alert', 'to' => 'ok'], 'card')[0] === 'card recovered');

// ── lsi_notify_run(): the full poll loop against a temp store ────────────────
$dir  = sys_get_temp_dir() . '/hbav_notify_test_' . getmypid();
$file = "$dir/notify_state.json";
@mkdir($dir, 0755, true);

$sent = [];
$spy  = function (string $s, string $d, string $i) use (&$sent): void { $sent[] = [$s, $i]; };
$poll = function (array $controllers) use ($spy, $file, &$sent): array {
    $sent = [];
    lsi_notify_run($controllers, $spy, $file);
    return $sent;
};

check('missing store reads as empty', lsi_notify_state_read($file) === []);
$row = ['SAS9300-8i@00:c1:00:00' => ['status' => 'warn', 'notified_at' => 1700000000]];
lsi_notify_state_write($row, $file);
check('store round-trips through a temp path', lsi_notify_state_read($file) === $row);
@unlink($file);

check('first sighting notifies nobody',        $poll([ctrl('ok')])    === []);
check('first sighting is still recorded',
    (lsi_notify_state_read($file)['SAS9300-8i@00:c1:00:00']['status'] ?? '') === 'ok');
check('unchanged status notifies nobody',      $poll([ctrl('ok')])    === []);
check('ok->warn notifies once',                $poll([ctrl('warn')])  === [['SAS9300-8i is now Warning', 'warning']]);
check('warn persists: no repeat',              $poll([ctrl('warn')])  === []);
check('warn persists again: still no repeat',  $poll([ctrl('warn')])  === []);
check('warn->alert notifies once',             $poll([ctrl('alert')]) === [['SAS9300-8i is now Alert', 'alert']]);
check('alert persists: no repeat',             $poll([ctrl('alert')]) === []);
check('alert->ok notifies recovery',           $poll([ctrl('ok')])    === [['SAS9300-8i recovered', 'normal']]);
check('failed read is not a recovery',
    $poll([['error' => 'No response from the HBA.']]) === []);
check('re-appearing card after a failed read does not fire',
    $poll([ctrl('alert')]) === []);

// notified_at only moves when the status actually changes.
$poll([ctrl('warn')]);
$stamp = lsi_notify_state_read($file)['SAS9300-8i@00:c1:00:00']['notified_at'];
lsi_notify_run([ctrl('warn')], $spy, $file, 999999999);
check('notified_at is not bumped by an unchanged poll',
    lsi_notify_state_read($file)['SAS9300-8i@00:c1:00:00']['notified_at'] === $stamp);

@unlink($file); @rmdir($dir);

echo $fails === 0 ? "notify: all pass\n" : "notify: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
