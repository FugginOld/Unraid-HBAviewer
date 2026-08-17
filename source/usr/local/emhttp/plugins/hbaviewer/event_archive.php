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
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
    @file_put_contents($file, json_encode($entries));
}

/* ── Entry shape ─────────────────────────────────────────────────────────────
   The two backends emit structurally different event records — storcli gives
   seq/time/code/description, lsiutil gives seq/qualifier/data/timestamp — and
   both are archived to the same per-controller file. A box that changes backend
   (a SAS2 system where the user later installs storcli) therefore accumulates
   both shapes, and a renderer built for one shape hits undefined keys on the
   other. These two helpers let the caller show only what it can format. */

/* Which backend produced this entry: 'storcli' | 'lsiutil' | '' when unknown. */
function event_shape(array $entry): string {
    if (isset($entry['description'])) return 'storcli';
    if (isset($entry['qualifier']))   return 'lsiutil';
    return '';
}

/* StorCLI2 is a different TOOL emitting the same record SHAPE as classic
   storcli, so one renderer serves both and the shape is what callers ask about. */
function lsi_backend_shape(string $backend): string {
    return $backend === 'storcli2' ? 'storcli' : $backend;
}

/* The entries $backend's table can actually render. Nothing is deleted — the
   archive on disk keeps every entry; this only decides what is displayed.
   An empty $backend falls back to the shape of the first entry, matching the
   renderer's own pre-rollout key-sniff.
   ponytail: hide foreign entries rather than render a second table for them.
   If anyone asks to see pre-switch history, render both tables instead. */
function event_visible(array $entries, string $backend): array {
    if ($backend === '') $backend = event_shape($entries[0] ?? []);
    if ($backend === '') return $entries;
    return array_values(array_filter($entries, fn($e) => event_shape($e) === $backend));
}
