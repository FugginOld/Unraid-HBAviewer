<?PHP
/* HBAviewer notifications — fire Unraid's own `notify` when a controller's
 * health status CHANGES, and only then.
 *
 * Everything except lsi_notify_send() and the store's two file calls is pure,
 * so tests/notify_test.php covers the whole decision path with no /boot, no
 * hardware and no notify binary. The shell-out is injectable for the same
 * reason (see lsi_notify_run's $send).
 *
 * Deliberately NOT smart: one notification per transition, no dwell counting,
 * no deadband, no flap suppression — plan 020 owns that. The bar here is
 * "don't spam", not "be clever".
 *
 * Store shape matches phy_baseline.php (plan 022): *_read(?string $path)/
 * *_write(array, ?string $path), a const default path, path always injectable.
 * If both ship they should fold into one small JSON-under-/boot module.
 */

const LSI_NOTIFY_STATE = '/boot/config/plugins/hbaviewer/notify_state.json';
const LSI_NOTIFY_BIN   = '/usr/local/emhttp/webGui/scripts/notify';

/* {controller_key: {status, notified_at}} — last status we told the user about. */
function lsi_notify_state_read(?string $path = null): array {
    $path ??= LSI_NOTIFY_STATE;
    return is_file($path) ? (json_decode((string) @file_get_contents($path), true) ?: []) : [];
}
function lsi_notify_state_write(array $state, ?string $path = null): void {
    $path ??= LSI_NOTIFY_STATE;
    @mkdir(dirname($path), 0755, true);
    @file_put_contents($path, json_encode($state));
}

/* Stable identity for one controller across polls. Array position is NOT stable
   (adding or pulling a card renumbers /cN), and board_name alone is not unique
   on a box with two identical cards — so pair it with the PCI location, which
   is unique per slot and present from both backends. Index only as a last
   resort, when the backend reported neither. */
function lsi_notify_key(array $c, int $idx): string {
    $k = trim(($c['board_name'] ?? '') . '@' . ($c['pci_location'] ?? ''), " \t@");
    return $k !== '' ? $k : "ctrl-$idx";
}

/* [key => status] for every controller that actually reported one. A controller
   whose read errored has no 'status' and is skipped entirely — a failed read is
   not a recovery to `ok`, and must never fire a notification. */
function lsi_notify_statuses(array $controllers): array {
    $out = [];
    foreach (array_values($controllers) as $i => $c) {
        if (!is_array($c) || !isset($c['status']) || $c['status'] === '') continue;
        $out[lsi_notify_key($c, $i)] = (string) $c['status'];
    }
    return $out;
}

/* $previous, $current: [controller_key => status] ('ok'|'warn'|'alert').
   Returns ['controller','from','to'] per controller whose status changed.
   A controller ABSENT from $previous (first run, or newly installed hardware)
   is not a transition — "a card was found" is not news. */
function notify_transitions(array $previous, array $current): array {
    $out = [];
    foreach ($current as $key => $status) {
        if (isset($previous[$key]) && $previous[$key] !== $status) {
            $out[] = ['controller' => $key, 'from' => $previous[$key], 'to' => $status];
        }
    }
    return $out;
}

/* Unraid's three importance levels. Anything back down to ok is a recovery,
   which is `normal` — informational, no siren. */
function lsi_notify_importance(string $to): string {
    return ['alert' => 'alert', 'warn' => 'warning'][$to] ?? 'normal';
}

/* Subject + description for one transition. $name is the human label (board
   name or chip model); the key is an internal identifier, not a headline. */
function lsi_notify_message(array $t, string $name): array {
    $words = ['ok' => 'OK', 'warn' => 'Warning', 'alert' => 'Alert'];
    $to    = $words[$t['to']]   ?? $t['to'];
    $from  = $words[$t['from']] ?? $t['from'];
    return [
        $t['to'] === 'ok' ? "$name recovered" : "$name is now $to",
        "HBA health went from $from to $to.",
    ];
}

function lsi_notify_send(string $subject, string $description, string $importance): void {
    shell_exec(LSI_NOTIFY_BIN
        . ' -e ' . escapeshellarg('HBAviewer')
        . ' -s ' . escapeshellarg($subject)
        . ' -d ' . escapeshellarg($description)
        . ' -i ' . escapeshellarg($importance)
        . ' >/dev/null 2>&1');
}

/* Orchestration: current statuses vs the stored ones -> notify each change ->
   store what we now know. $send and $path are injectable so a test can run the
   whole path without a notify binary or /boot. Returns the transitions fired.

   notified_at on a first-seen controller is when we first RECORDED it, not when
   we told anyone — nothing was sent for it (see notify_transitions). */
function lsi_notify_run(array $controllers, ?callable $send = null, ?string $path = null, ?int $now = null): array {
    $send ??= 'lsi_notify_send';
    $now  ??= time();

    $prev    = lsi_notify_state_read($path);
    $current = lsi_notify_statuses($controllers);

    $prevStatus = [];
    foreach ($prev as $k => $row) {
        if (isset($row['status'])) $prevStatus[$k] = (string) $row['status'];
    }

    $names = [];
    foreach (array_values($controllers) as $i => $c) {
        if (!is_array($c)) continue;
        $names[lsi_notify_key($c, $i)] =
            ($c['board_name'] ?? '') ?: (($c['model'] ?? '') ?: 'HBA controller');
    }

    $transitions = notify_transitions($prevStatus, $current);
    foreach ($transitions as $t) {
        [$subject, $description] = lsi_notify_message($t, $names[$t['controller']] ?? $t['controller']);
        $send($subject, $description, lsi_notify_importance($t['to']));
    }

    // Rewrite the store from the CURRENT set only: a card that is gone stops
    // being tracked, so re-adding it later is a first sighting, not a change.
    $state = [];
    foreach ($current as $k => $status) {
        $state[$k] = (($prev[$k]['status'] ?? null) === $status)
            ? $prev[$k]                                   // unchanged: keep the original timestamp
            : ['status' => $status, 'notified_at' => $now];
    }
    lsi_notify_state_write($state, $path);

    return $transitions;
}
