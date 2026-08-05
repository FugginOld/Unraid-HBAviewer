<?PHP
/* HBAviewer HBA Health — five sub-indicators with a worst-of rollup.
 *
 * scripts/get_hba_health.sh emits a stateless SAMPLE per controller (raw
 * readings + a timestamp, no judgement). Everything below is PURE over its
 * inputs: the ring append/reset rule, the rate arithmetic, the five state
 * rules, and the rollup — so tests/health_test.php exercises all of it with
 * no /tmp, no HTTP, no hardware. Only the store read/write pair touches a
 * path, and that path is always injectable.
 *
 * See event_archive.php for the persistence shape this copies. The one
 * deliberate difference: this ring lives in /tmp (RAM), not /boot, so there
 * is no conditional-write rule to defend flash wear — every sample is
 * appended unconditionally (see plan 020, "Write policy").
 */

const HEALTH_RING_CAP   = 240;   // ~4h at the 60s page refresh; RAM, so no wear budget
const HEALTH_STALE_SECS = 900;   // newest sample older than this -> unknown

/* /tmp, not /boot: the ring is only meaningful within one boot (PHY error
   counters reset to zero on driver reload, which is what a reboot does — a
   sample that survived a reboot would be thrown away on the first read
   after it anyway). $dir is injectable so tests never touch a real path. */
function health_store_path(int $ctl, string $dir = '/tmp'): string {
    return "$dir/hbav_health_c$ctl.json";
}
function health_store_read(string $file): array {
    return is_file($file) ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];
}
function health_store_write(string $file, array $ring): void {
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
    @file_put_contents($file, json_encode($ring));
}

/* Append $sample to $ring, capped to HEALTH_RING_CAP. Drops the whole ring
   FIRST when the baseline is invalid — either $sample's uptime is LOWER than
   the newest stored sample's (reboot), OR any PHY counter on a phy present in
   both samples went DOWN (driver reloaded without a reboot, e.g.
   `modprobe -r mpt3sas`). Both signals mean the counters restarted from zero;
   keeping the old baseline would produce a negative rate. */
function health_ingest(array $ring, array $sample): array {
    $newest = $ring ? end($ring) : null;
    if ($newest !== null) {
        $reset = ($sample['uptime'] ?? 0) < ($newest['uptime'] ?? 0);
        if (!$reset) {
            $prevByIdx = [];
            $prevCount = [];
            foreach ($newest['phys'] ?? [] as $p) { $prevByIdx[$p['idx']] = $p; $prevCount[$p['idx']] = ($prevCount[$p['idx']] ?? 0) + 1; }
            $curCount = [];
            foreach ($sample['phys'] ?? [] as $p) $curCount[$p['idx']] = ($curCount[$p['idx']] ?? 0) + 1;
            foreach ($sample['phys'] ?? [] as $p) {
                $prev = $prevByIdx[$p['idx']] ?? null;
                if ($prev === null) continue;
                /* A duplicate index means the two samples cannot be paired PHY-for-PHY, so a
                   "decrease" is not evidence of anything. Skip the reset check for that index
                   rather than wiping a ring that may be hours old (issue #12). */
                if (($prevCount[$p['idx']] ?? 0) > 1 || ($curCount[$p['idx']] ?? 0) > 1) continue;
                foreach (['inv', 'disp', 'sync', 'rst'] as $k) {
                    if (($p[$k] ?? 0) < ($prev[$k] ?? 0)) { $reset = true; break 2; }
                }
            }
        }
        if ($reset) $ring = [];
    }
    $ring[] = $sample;
    if (count($ring) > HEALTH_RING_CAP) $ring = array_slice($ring, -HEALTH_RING_CAP);
    return array_values($ring);
}

/* Per-PHY per-counter rates in events/hour between the OLDEST and NEWEST
   sample in $ring — not a sliding average, the two ends of whatever window
   the ring currently holds. That is the whole reason cadence does not need
   to be fixed: a 30-minute gap and a 1-hour gap both produce a correct
   events/hour figure from the same formula (see tests/health_test.php).
   Returns [] when fewer than two samples span >= 60 seconds — not enough
   signal yet, so the caller must report `unknown`, never `ok`.
   Deltas are clamped at 0: health_ingest already resets the ring on any
   counter decrease, so a negative delta here would mean that guard was
   bypassed — clamping is a second line of defense, not the primary one. */
function health_rates(array $ring): array {
    if (count($ring) < 2) return [];
    $oldest = $ring[0];
    $newest = $ring[count($ring) - 1];
    $dtSecs = ($newest['t'] ?? 0) - ($oldest['t'] ?? 0);
    if ($dtSecs < 60) return [];
    $dtHours = $dtSecs / 3600.0;

    $oldByIdx = [];
    $oldCount = [];
    foreach ($oldest['phys'] ?? [] as $p) { $oldByIdx[$p['idx']] = $p; $oldCount[$p['idx']] = ($oldCount[$p['idx']] ?? 0) + 1; }
    $newCount = [];
    foreach ($newest['phys'] ?? [] as $p) $newCount[$p['idx']] = ($newCount[$p['idx']] ?? 0) + 1;

    $rates = [];
    foreach ($newest['phys'] ?? [] as $p) {
        $prev = $oldByIdx[$p['idx']] ?? null;
        if ($prev === null) continue;
        /* Same pairing guard as health_ingest(): a duplicate index cannot be
           matched PHY-for-PHY between the two samples, so no rate can be
           trusted for it (issue #12). */
        if (($oldCount[$p['idx']] ?? 0) > 1 || ($newCount[$p['idx']] ?? 0) > 1) continue;
        $rates[] = [
            'idx'  => $p['idx'],
            'inv'  => max(0, ($p['inv']  ?? 0) - ($prev['inv']  ?? 0)) / $dtHours,
            'disp' => max(0, ($p['disp'] ?? 0) - ($prev['disp'] ?? 0)) / $dtHours,
            'sync' => max(0, ($p['sync'] ?? 0) - ($prev['sync'] ?? 0)) / $dtHours,
            'rst'  => max(0, ($p['rst']  ?? 0) - ($prev['rst']  ?? 0)) / $dtHours,
        ];
    }
    return $rates;
}

/* Severity ordering, one place, so it cannot drift between the five
   indicators and the rollup. `unknown` deliberately has NO rank — it is
   handled separately in health_rollup(): it wins only when nothing else is
   `warning` or worse. */
function health_rank(string $state): int {
    return ['ok' => 0, 'watch' => 1, 'warning' => 2, 'critical' => 3][$state] ?? -1;
}

/* Rate thresholds, events/hour. Loss-of-sync and phy-reset have NO watch
   tier on purpose: unlike invalid dwords, which trickle in from ordinary
   marginal signalling, those two mean the link actually dropped and
   re-established. */
function health_rate_state(string $counter, float $rate): string {
    if ($rate <= 0) return 'ok';
    if ($counter === 'inv' || $counter === 'disp') {
        if ($rate <= 50)  return 'watch';
        if ($rate <= 500) return 'warning';
        return 'critical';
    }
    // sync, rst
    return $rate < 10 ? 'warning' : 'critical';
}

/* First numeric token of a negotiated_linkrate string like "12.0_Gbit" or a
   sysfs link_speed string like "8.0 GT/s". null when there is nothing to
   parse (empty / "Unknown"). */
function health_rate_number(string $s): ?float {
    return preg_match('/([0-9]+(?:\.[0-9]+)?)/', $s, $m) ? (float) $m[1] : null;
}

/* Events/hour as a display string. One decimal below 10, integer at or above
   it: a genuine 0.4/hr loss-of-sync is a `warning` (the link dropped and came
   back), and printing it with %.0f rendered the indicator as
   "errors rising (0/hr)" — a self-contradiction that reads as a broken tab.
   A true zero still prints "0/hr", not "0.0/hr". Below that, a floor: the
   ring window is whatever's between two renders of the Health tab, which can
   span many hours (open today, open tomorrow), so a single event can still
   divide down to a rate that rounds to "0.0" at one decimal while being a
   genuine warning. 0.05 is exactly where number_format(…, 1) starts rounding
   to "0.0"; below it we say "<0.1/hr" instead of lying with a zero. */
function health_rate_str(float $rate): string {
    if ($rate > 0 && $rate < 0.05) return '<0.1/hr';
    return number_format($rate, $rate > 0 && $rate < 10 ? 1 : 0) . '/hr';
}

/* The five indicators, each ['state', 'reason', 'value'] — 'reason' is the
   human string the rollup borrows verbatim from the worst one; 'value' is
   what the indicator row prints. $ring's last element is the current sample;
   $rates is health_rates($ring), passed in rather than recomputed so callers
   (and tests) can feed a synthetic rate set without needing real history. */
function health_indicators(array $ring, array $rates, int $now): array {
    $newest = $ring ? end($ring) : null;

    // ── thermal: temp_band, plan 018's bands (NOT the spec's four-state table) ──
    $band = $newest['temp_band'] ?? '';
    $thermalMap = ['normal' => 'ok', 'elevated' => 'watch', 'warning' => 'warning', 'alert' => 'critical', 'critical' => 'critical'];
    if (isset($thermalMap[$band])) {
        $temp  = $newest['temp'] ?? '';
        $thermal = [
            'state'  => $thermalMap[$band],
            'reason' => ($temp !== '' && $temp !== null) ? "{$temp}°C — " . ucfirst($band) . " band" : ucfirst($band),
            'value'  => ($temp !== '' && $temp !== null) ? "{$temp}°C" : '—',
        ];
    } else {
        $thermal = ['state' => 'unknown', 'reason' => 'No temperature reading', 'value' => '—'];
    }

    // ── link_integrity: worst PHY, worst counter, its index names the reason ──
    if (empty($rates)) {
        $link_integrity = ['state' => 'unknown', 'reason' => 'Not enough samples yet — needs two reads at least a minute apart', 'value' => '—'];
    } else {
        $labels = ['inv' => 'invalid dword', 'disp' => 'disparity', 'sync' => 'loss of sync', 'rst' => 'reset problem'];
        $worstState = 'ok'; $worstRank = 0; $worstValue = '0/hr';
        $worstReason = 'No new cabling errors on any PHY (0 per hour)';
        foreach ($rates as $r) {
            foreach ($labels as $k => $label) {
                $s = health_rate_state($k, $r[$k]);
                $rank = health_rank($s);
                if ($rank > $worstRank) {
                    $worstRank  = $rank;
                    $worstState = $s;
                    $worstReason = sprintf('PHY %s %s errors rising (%s)', $r['idx'], $label, health_rate_str($r[$k]));
                    $worstValue  = health_rate_str($r[$k]);
                }
            }
        }
        $link_integrity = ['state' => $worstState, 'reason' => $worstReason, 'value' => $worstValue];
    }

    // ── topology: drive count vs the ring's own baseline ───────────────────────
    /* ponytail: drive count only. Per-PHY downshift detection was removed after real
       hardware showed every LSI card carries a virtual SES PHY negotiating at
       3.0 Gbit one index past its last data port — indistinguishable by rate from a
       genuinely downtrained SATA link, so the check produced a permanent false
       warning. Re-adding it needs attached-device-type correlation (is there a block
       device behind this PHY?), not a rate comparison. Per-PHY rates are already
       visible on the PHY Health tab. */
    $drivesSeen     = array_column($ring, 'drives');
    $baselineDrives = $drivesSeen ? max($drivesSeen) : 0;
    $curDrives      = (int) ($newest['drives'] ?? 0);
    if ($baselineDrives < 1) {
        // Nothing this controller ever reported a drive: an unreadable count, not
        // eight vanished disks. "0 drives / All drives present" reads as a broken
        // tab on a card with a full backplane (issue #11).
        $topology = ['state' => 'unknown', 'reason' => 'No drive count available for this controller', 'value' => '—'];
    } elseif ($curDrives < $baselineDrives) {
        $topology = ['state' => 'critical', 'reason' => "Drive missing — {$curDrives} of the {$baselineDrives} seen before", 'value' => "{$curDrives}/{$baselineDrives}"];
    } else {
        $topology = ['state' => 'ok', 'reason' => "All {$curDrives} attached drives present", 'value' => $curDrives . ' drive' . ($curDrives === 1 ? '' : 's')];
    }

    // ── host_link: current PCIe width/speed vs this slot's capability ──────────
    $link = $newest['link'] ?? [];
    $w  = (int) ($link['width'] ?? 0);  $mw = (int) ($link['max_width'] ?? 0);
    $s  = health_rate_number((string) ($link['speed'] ?? ''));
    $ms = health_rate_number((string) ($link['max_speed'] ?? ''));
    $widthDown = $mw > 0 && $w > 0 && $w < $mw;
    $speedDown = $ms !== null && $s !== null && $s < $ms;
    if ($widthDown || $speedDown) {
        $host_link = [
            'state'  => 'warning',
            'reason' => sprintf('PCIe link downtrained: x%d %s of x%d %s capable', $w, $link['speed'] ?? '', $mw, $link['max_speed'] ?? ''),
            'value'  => "x{$w} / x{$mw}",
        ];
    } else {
        $host_link = ['state' => 'ok', 'reason' => $w > 0
            ? "PCIe slot running at its full x{$w} width" . (($link['speed'] ?? '') !== '' ? " and {$link['speed']}" : '')
            : 'No PCIe downtraining reported', 'value' => $w > 0 ? "x{$w}" : '—'];
    }

    // ── controller: can we even trust the rest of this row? ────────────────────
    if ($newest === null) {
        $controller = ['state' => 'unknown', 'reason' => 'No samples yet', 'value' => '—'];
    } elseif (empty($newest['read_ok'])) {
        $controller = ['state' => 'unknown', 'reason' => 'The last read of this controller failed', 'value' => 'Read failed'];
    } elseif (($age = $now - ($newest['t'] ?? 0)) > HEALTH_STALE_SECS) {
        $controller = ['state' => 'unknown', 'reason' => "Stale — no reading in {$age}s", 'value' => "{$age}s old"];
    } else {
        $controller = ['state' => 'ok', 'reason' => 'Controller answered the last query normally', 'value' => 'OK'];
    }

    return [
        'thermal'        => $thermal,
        'link_integrity' => $link_integrity,
        'topology'       => $topology,
        'host_link'      => $host_link,
        'controller'     => $controller,
    ];
}

/* What the Health gauge reads: how many indicators are `ok`, out of how many
   there ARE. Deliberately NOT a 0-100 score — the indicators are categorical,
   and a number that slides from 89 to 87 for reasons nobody can explain is
   worse than no number (plan 030, option A). Counts whatever
   health_indicators() returned, so adding a sixth indicator needs no edit here.
   'frac' is pre-divided because the empty-input case (total 0) must not reach a
   division at the call site. */
function health_gauge(array $indicators): array {
    $ok = 0;
    foreach ($indicators as $ind) if (($ind['state'] ?? '') === 'ok') $ok++;
    $total = count($indicators);
    // (float) because PHP's / hands back an int on an exact division, and the
    // caller passes this straight into a float-typed signature.
    return ['ok' => $ok, 'total' => $total, 'frac' => $total > 0 ? (float) $ok / $total : 0.0];
}

/* Worst-of rollup: max(severity), never an average — four ok and one
   critical is not "mostly fine". If any indicator is `unknown` and nothing
   else is `warning` or worse, the rollup is `unknown` (a card that cannot be
   read is not a card that is fine). Returns [state, reason]; reason is the
   worst indicator's own reason string verbatim. */
function health_rollup(array $indicators): array {
    $worst = null; $worstRank = -1; $anyUnknown = null;
    foreach ($indicators as $ind) {
        if ($ind['state'] === 'unknown') { if ($anyUnknown === null) $anyUnknown = $ind; continue; }
        $rank = health_rank($ind['state']);
        if ($rank > $worstRank) { $worstRank = $rank; $worst = $ind; }
    }
    if ($worst === null) {
        return ['unknown', $anyUnknown['reason'] ?? 'unknown'];
    }
    if ($anyUnknown !== null && $worstRank < health_rank('warning')) {
        return ['unknown', $anyUnknown['reason']];
    }
    return [$worst['state'], $worst['reason']];
}
