<?PHP
/* HBAviewer event-log archive.
 *
 * Persists the firmware event ring-buffer so history survives reboots and
 * ring-buffer wrap. The merge is PURE over its inputs; the store is a thin
 * read/write pair keyed by an injectable path — so the dedup rule and the
 * flash-wear cap are testable without /boot or HTTP.
 */

const EVENT_ARCHIVE_CAP = 2000;   // cap history growth (kind to the boot flash)

/* Fold `current` into `history`, dedup by seq|time, cap to EVENT_ARCHIVE_CAP.
 * Returns [kept, changed]; the caller writes only when `changed` so an
 * unchanged poll never touches the flash. */
function event_merge(array $history, array $current): array {
    $key  = fn($e) => ($e['seq'] ?? '') . '|' . ($e['time'] ?? ($e['timestamp'] ?? ''));
    $seen = [];
    foreach ($history as $e) $seen[$key($e)] = true;
    $changed = false;
    foreach ($current as $e) {
        $k = $key($e);
        if (!isset($seen[$k])) { $history[] = $e; $seen[$k] = true; $changed = true; }
    }
    if ($changed && count($history) > EVENT_ARCHIVE_CAP) {
        $history = array_slice($history, -EVENT_ARCHIVE_CAP);
    }
    return [$history, $changed];
}

/* Default per-controller store path. $dir is overridable for tests. */
function event_store_path(int $ctl, string $dir = '/boot/config/plugins/hbaviewer'): string {
    return "$dir/events_c$ctl.json";
}

function event_store_read(string $file): array {
    return is_file($file) ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];
}

function event_store_write(string $file, array $entries): void {
    @mkdir(dirname($file), 0755, true);
    @file_put_contents($file, json_encode($entries));
}
