<?php
// Events tab: renders the per-controller archived event log.
/* ── Event Log (per controller; persisted to /boot across reboots) ─────────── */
/* $dir is the archive location; it is injectable so tests can point the store
   at a temp directory instead of the boot flash. */
function renderEventsTables(array $data, string $dir = '/boot/config/plugins/hbaviewer'): string {
    $ctls    = $data['controllers'] ?? [$data];
    $storcli = ($data['backend'] ?? '') === 'storcli';
    return luCardPerController($ctls, function (int $i, array $ctl) use ($dir, $storcli, $data): string {
        $out = '';
        if (!empty($ctl['note'])) $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['note']) . '</p>';

        $file = event_store_path($i, $dir);
        [$archived, $changed] = event_merge(event_store_read($file), $ctl['entries'] ?? []);
        if ($changed) event_store_write($file, $archived);
        // Archive everything, display only what this backend's table can format.
        // A box that switched backend keeps its old entries on disk; showing them
        // through the wrong renderer produces undefined-key warnings and blank rows.
        $entries = event_visible($archived, $data['backend'] ?? '');
        $hidden  = count($archived) - count($entries);
        if (empty($entries)) { $out .= '<p class="lu-muted">No log entries.</p>'; return $out; }
        $out .= '<p class="lu-muted" style="font-size:11px;margin:0 0 8px">'
              . count($entries) . ' entries &middot; archived to /boot (survives reboots &amp; ring-buffer wrap)'
              . ($hidden > 0 ? ' &middot; ' . $hidden . ' from a previous backend not shown' : '') . '</p>';

        // The backend field, and nothing else -- see the PHY renderer above.
        if ($storcli) {
            // storcli backend: seq, time, code, human-readable description (newest first)
            $rows = [];
            foreach (array_reverse($entries) as $e) {
                $rows[] = [
                    '<code>' . htmlspecialchars($e['seq']) . '</code>',
                    htmlspecialchars($e['time']),
                    '<code>' . htmlspecialchars($e['code']) . '</code>',
                    htmlspecialchars($e['description']),
                ];
            }
            $out .= luTable(['Seq', 'Time', 'Code', 'Description'], $rows);
        } else {
            // lsiutil backend: seq, qualifier, data, timestamp (hex)
            $rows = [];
            foreach (array_reverse($entries) as $e) {
                $rows[] = [
                    htmlspecialchars((string) $e['seq']),
                    '<code>' . htmlspecialchars((string) $e['qualifier']) . '</code>',
                    '<code>' . htmlspecialchars($e['data']) . '</code>',
                    '<code>' . htmlspecialchars((string) $e['timestamp']) . '</code>',
                ];
            }
            $out .= luTable(['Seq', 'Qualifier', 'Data', 'Timestamp'], $rows);
        }
        return $out;
    });
}
