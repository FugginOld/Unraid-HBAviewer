<?php
// Events tab: renders the per-controller archived event log.
/* ── Event Log (per controller; persisted to /boot across reboots) ─────────── */
/* $dir is the archive location; it is injectable so tests can point the store
   at a temp directory instead of the boot flash. */
function renderEventsTables(array $data, string $dir = '/boot/config/plugins/hbaviewer'): string {
    $ctls    = $data['controllers'] ?? [$data];
    // Shape, not tool name: StorCLI2 (SAS4 / 9600) feeds these tables the same
    // record shape as the classic storcli backend, so one renderer serves both.
    $storcli = lsi_backend_shape($data['backend'] ?? '') === 'storcli';
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
        /* "No log entries" is a lie when $hidden is non-zero: the archive HAS
           entries, they were written by a different backend and this renderer
           cannot format them. Saying nothing about them reads as data loss --
           the whole point of archiving to /boot is that entries survive, and a
           user who switched backend would conclude they had not. */
        if (empty($entries)) {
            $out .= '<p class="lu-muted">' . ($hidden > 0
                ? 'No entries from this backend. ' . $hidden . ' earlier '
                  . ($hidden === 1 ? 'entry is' : 'entries are')
                  . ' archived on /boot but were written by a different backend, so they cannot be shown here.'
                : 'No log entries. The firmware log on this controller is empty — the normal state for a healthy card.')
                . '</p>';
            return $out;
        }
        $out .= '<p class="lu-muted" style="font-size:11px;margin:0 0 8px">'
              . count($entries) . ' entries &middot; archived to /boot (survives reboots &amp; ring-buffer wrap)'
              . ($hidden > 0 ? ' &middot; ' . $hidden . ' from a previous backend not shown' : '') . '</p>';

        // The backend field, and nothing else -- see the PHY renderer, render/phy.php.
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
                    // Routing is by the backend field alone now, so a record whose
                    // shape disagrees with its label arrives here missing keys
                    // instead of being sniffed onto the other branch. data also
                    // needs the cast: htmlspecialchars(null) is deprecated in 8.1+.
                    htmlspecialchars((string) ($e['seq'] ?? '')),
                    '<code>' . htmlspecialchars((string) ($e['qualifier'] ?? '')) . '</code>',
                    '<code>' . htmlspecialchars((string) ($e['data'] ?? '')) . '</code>',
                    '<code>' . htmlspecialchars((string) ($e['timestamp'] ?? '')) . '</code>',
                ];
            }
            $out .= luTable(['Seq', 'Qualifier', 'Data', 'Timestamp'], $rows);
        }
        return $out;
    });
}
