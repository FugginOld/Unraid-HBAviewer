<?php
// SMART tab: cache read/age, per-drive state, and the table renderer.
/* ── The background SMART cache: one reader, one health rule ────────────────
   collect_smart.sh writes {"drives":[{dev,serial,model,smart:{…}}]} here. Both
   the SMART tab and the bay map read it, and they must never disagree about a
   drive, so neither gets its own copy of "where is it / is it fresh / is it
   healthy" (plan 047's STOP condition).
   Returns null when there is no usable cache — deliberately NOT [], because a
   collected-and-genuinely-empty cache (a box with no drives) has to be
   distinguishable from one that was never collected, or the SMART tab would
   relaunch its collector on every visit forever. */
function smart_cache_read(?string $path = null): ?array {
    $path ??= SMART_CACHE_PATH;
    if (!is_file($path)) return null;
    $d = json_decode((string) file_get_contents($path), true);
    return is_array($d) ? $d : null;
}

/* Seconds since the cache was written, or null when there is none. Every
   caller that renders cached SMART data must show this — a reading with no
   stated age is a reading the reader assumes is live. */
function smart_cache_age(?string $path = null, ?int $now = null): ?int {
    $path ??= SMART_CACHE_PATH;
    if (!is_file($path)) return null;
    return max(0, ($now ?? time()) - (int) filemtime($path));
}

/* One drive's SMART verdict: 'ok' | 'warn' | 'fail' | 'nodata'.
   'nodata' covers both "never collected" and "asleep, deliberately not woken"
   — the two cases where we know nothing. It is a distinct state, not a
   fall-through to healthy: a bay coloured green for a drive nobody has read
   is the exact failure this is here to prevent. */
function smart_state(array $s): string {
    $health = strtoupper((string) ($s['health'] ?? ''));
    if ($health === '') return 'nodata';
    if ($health !== 'OK' && $health !== 'PASSED') return 'fail';
    return ((int) ($s['defects'] ?? 0) > 0 || (int) ($s['pending'] ?? 0) > 0) ? 'warn' : 'ok';
}

/* The colours those states have always rendered as, kept in step across the
   SMART table, the per-drive line and the bay map. */
function smart_state_color(string $state): string {
    return ['fail' => '#e74c3c', 'warn' => '#f39c12', 'ok' => '#2ecc71'][$state] ?? '';
}

/* Render the background-collected SMART cache as a table. $ageSecs is how old
   that collection is; it is printed above the table because the cache is now
   kept until someone refreshes it, and an unlabelled table of week-old
   temperatures reads exactly like a live one. */
function renderSmartTable(array $data, ?int $ageSecs = null, array $roles = [], array $udMounts = [],
                          int $unit = 0): string {
    $drives = $data['drives'] ?? [];
    if (!$drives) return '<p class="lu-muted">No drives found.</p>';
    $age = $ageSecs === null ? '' :
        '<p class="lu-muted" style="font-size:11px;margin:0 0 8px">Collected ' . htmlspecialchars(lsi_age_str($ageSecs))
        . ' ago &middot; kept until you press Refresh</p>';
    $dash = '<span class="lu-muted">—</span>';
    $rows = [];
    foreach ($drives as $d) {
        $s     = $d['smart'] ?? [];
        $state = smart_state($s);
        $hb    = $state === 'nodata'
            ? '<span class="lu-muted">standby</span>'
            : '<span style="color:' . smart_state_color($state) . ';font-weight:700">'
              . htmlspecialchars($s['health']) . '</span>';
        $cell = fn($v, $suf = '') => ($v ?? '') !== '' ? htmlspecialchars((string) $v) . $suf : $dash;
        $rows[] = [
            '<code>' . htmlspecialchars($d['dev'] ?? '') . '</code>',
            lsi_role_cell($d['dev'] ?? null, $roles, $udMounts),
            htmlspecialchars($d['model'] ?? ''),
            ($s['transport'] ?? '') !== '' ? htmlspecialchars(strtoupper($s['transport'])) : $dash,
            '<code>' . htmlspecialchars($d['serial'] ?? '') . '</code>',
            $hb,
            /* Not $cell(): the suffix is part of the converted string now,
               and $cell would append a second one. */
            ($s['temp'] ?? '') !== '' ? htmlspecialchars(lsi_temp_str($s['temp'], $unit)) : $dash,
            $cell($s['defects'] ?? ''),
            $cell($s['pending'] ?? ''),
            ($s['power_on_hours'] ?? '') !== '' ? number_format((int) $s['power_on_hours']) . 'h' : $dash,
        ];
    }
    return $age . luTable(['Device', 'Unraid', 'Model', 'Type', 'Serial', 'Health', 'Temp', 'Reallocated', 'Pending', 'Power-On'], $rows);
}
