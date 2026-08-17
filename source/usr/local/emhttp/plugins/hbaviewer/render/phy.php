<?php
// PHY tab: baseline bar, per-counter cell, drive correlation, offenders, and the table renderer.
/* Per-controller baseline bar (plan 022 Step 1: per-controller, not per-PHY —
   precise enough to baseline the card whose cable you just reseated, without a
   button on every row). Always states WHEN the baseline was taken: a baseline
   set at install and never touched measures "errors since install", which is
   the raw counter wearing a rate's clothes. */
function luPhyBaselineBar(int $ctl, ?int $ts, bool $stale): string {
    if ($stale) {
        $note = '<span class="lu-phy-stale">Baseline reset by reboot or driver reload — press Reset Baseline to re-establish.</span>';
    } elseif ($ts === null) {
        $note = '<span class="lu-muted">No baseline set — counters are cumulative since the driver loaded.</span>';
    } else {
        $note = '<span class="lu-muted">Baseline set ' . htmlspecialchars(date('Y-m-d', $ts) . ' ' . lsi_time($ts)) . '</span>';
    }
    return '<div class="lu-phy-bar">' . $note
         . '<button class="lu-refresh-btn" onclick="luPhyBaseline(' . $ctl . ', this)">'
         . ($ts === null ? 'Set Baseline' : 'Reset Baseline') . '</button></div>';
}

/* One counter cell: the raw counter exactly as before, plus a delta-since-
   baseline and a rate when this PHY has a usable baseline. Omitted entirely
   when there is none — a "0" there would read as "no errors" rather than "no
   reference point". A negative delta can never reach this: phy_baseline_delta()
   reports a counter restart as `reset`, and the controller then renders
   raw-only behind the bar's re-baseline prompt.

   That rate is an AVERAGE spanning however long ago the baseline was set —
   not a current condition. A burst of errors from days ago still divides down
   to a small "X/hr" that never reaches zero, while the Health tab's much more
   recent ring can show the link is clean right now (issue: two tabs disagreed
   with no explanation, plan 050). "since baseline" plus the title tooltip say
   what the number answers; $recent, when the Health tab's own ring is usable
   for this PHY, says what has happened lately, on its own line beneath the
   average rather than in place of it — never hide the historical number, only
   add to it. Stacked, not joined by a separator: the two together ran to about
   fifty monospace characters in every one of four counter columns, which is
   what pushed this table wider than its card (the horizontal scroller added in
   a65abc1 was treating the symptom). Two short lines cost a row of height and
   give the columns back.
   $recent is health_rates()'s per-PHY row (keyed 'rst', not 'reset' — the two
   subsystems name that counter differently, see phy_top_offenders() and
   health.php's header) or null when the ring cannot support one yet:
   $ringSpanSecs travels with it purely to word "in the last N" — absence
   prints nothing extra, never a misleading zero. */
function luPhyCell($v, bool $err, ?array $d, string $k, ?array $recent = null, ?int $ringSpanSecs = null): string {
    // With a usable baseline the HEADLINE is the count since that baseline, not
    // the cumulative counter. Resetting the baseline then does what pressing it
    // looks like it does: the column goes to 0. It also fixes what the colour
    // means — a cable you have actually fixed stops being orange, instead of
    // staying orange until the next driver reload, which is the one event the
    // plugin cannot cause and the user cannot see.
    // The cumulative value keeps a line of its own, and is deliberately NOT
    // called a lifetime: these counters are cumulative since the last DRIVER
    // LOAD, so a reboot alone sends them to zero with no cable having changed.
    $usable = $d !== null && empty($d['reset']);
    $head   = $usable ? (string) (int) $d['delta'][$k] : (string) $v;
    $err    = $usable ? ((int) $d['delta'][$k]) > 0 : $err;
    $s      = htmlspecialchars($head);
    $cell   = $err ? '<span class="lu-err-val">' . $s . '</span>' : $s;
    if (!$usable) return $cell;
    $out = '<span title="Errors on this PHY since the baseline was set. Reset the baseline to return it to zero.">' . $cell . '</span>'
         . '<div class="lu-phy-delta" title="The driver\'s own cumulative counter, which no baseline can clear — it returns to zero only when the driver reloads or the box reboots.">'
         . 'since driver load: ' . htmlspecialchars((string) $v) . '</div>';
    if ($recent !== null && $ringSpanSecs !== null) {
        $rk = $k === 'reset' ? 'rst' : $k;
        // Its own line and its own tooltip: the two numbers answer different
        // questions, which is the whole point of plan 050, and a shared title
        // describing only the average would mislabel this one.
        $out .= '<div class="lu-phy-delta" title="Rate across the Health tab\'s recent sample ring — what this link has been doing lately, independent of the long-run average above.">'
              . health_rate_str($recent[$rk]) . ' in the last ' . lsi_age_str($ringSpanSecs) . '</div>';
    }
    return $out;
}

/* Which drive sits behind this PHY? Two backends, two keys:
     lsiutil  - drives carry `phy`; match it directly.
     storcli  - drives carry no `phy` at all. The PHY's `sas_addr` and the
                drive's `sas_address` are two ports of the same dual-ported
                device and differ in the LAST hex digit only (measured across
                24 drives: Seagate -1, HGST +2, Toshiba -2 — no fixed offset),
                so compare the first 15 digits, uppercased.
   Returns null when nothing matches AND when the 15-digit prefix is not unique
   within this controller: a top-offenders row names a physical bay, and naming
   the wrong one is worse than naming none (plan 027). */
function phy_drive(array $drives, array $phy): ?array {
    if (!$drives) return null;

    // lsiutil: drives carry `phy` directly — a straight index match.
    if (isset($drives[0]['phy'])) {
        foreach ($drives as $d) {
            // A drive behind an expander numbers its PHY in the expander's
            // namespace; these rows are the controller's own PHYs (plan 049).
            // Matching the two names the wrong bay, which this function's whole
            // contract says is worse than naming none.
            if (($d['expander'] ?? '') !== '') continue;
            if (isset($d['phy']) && (string) $d['phy'] === (string) ($phy['phy'] ?? '')) return $d;
        }
        return null;
    }

    // storcli: no `phy` field on drives — join on the SAS address prefix.
    $pfx = strtoupper(substr((string) ($phy['sas_addr'] ?? ''), 0, 15));
    if (strlen($pfx) < 15) return null;

    $matches = array_values(array_filter($drives, fn($d) =>
        strtoupper(substr((string) ($d['sas_address'] ?? ''), 0, 15)) === $pfx
    ));
    // Exactly one match is safe. Zero (no drive) or more than one (the prefix
    // collides between two drives) both resolve to null — never a guess.
    return count($matches) === 1 ? $matches[0] : null;
}

/* How a top-offenders row names the drive behind a PHY: the /dev name when it
   resolves, the enclosure bay when storcli gave one, both when both are known.
   Encl:slot alone does not line up with anything on Unraid's Main page (issue
   #11), and /dev alone loses the bay you actually have to pull. */
function phy_drive_label(array $drives, array $phy, array $devBySerial = []): ?string {
    $d = phy_drive($drives, $phy);
    if ($d === null) return null;
    $dev  = drive_dev_name($d, $devBySerial);
    $slot = isset($d['slot']) && $d['slot'] !== '' ? (string) $d['slot'] : null;
    if ($slot !== null && $dev !== null) return "$slot · $dev";
    return $dev ?? $slot;
}

/* The Health tab's own ring for this controller, read READ-ONLY — this never
   calls health_ingest(); writing the ring stays the Health tab's job alone
   (plan 050's STOP conditions). Returns the matching PHY's rate row from
   health_rates(), or null when there is nothing usable for THIS PHY: no ring,
   too short a span, or (issue #12) an unpairable duplicate index that
   health_rates() already excludes. Absence is deliberate — the caller must
   print nothing extra rather than a "0/hr" that looks measured but isn't. */
function phy_recent_rate(array $ring, int $phyIdx): ?array {
    foreach (health_rates($ring) as $r) {
        if ((int) $r['idx'] === $phyIdx) return $r;
    }
    return null;
}

/* $phys and $deltas share indices, exactly as renderPhyTables builds them.
   Rank by TOTAL errors/hour — the plain sum of the four counters' rates. No
   weighting is invented here: the per-counter thresholds used to color the
   Health tab's link-integrity indicator live in health.php, and duplicating
   that judgement in a second place would let the two disagree (plan 027). */
function phy_top_offenders(array $phys, array $deltas, array $drives, int $limit = 5, array $devBySerial = []): array {
    $rows = [];
    foreach ($phys as $n => $p) {
        $d = $deltas[$n] ?? null;
        // No baseline, or a stale one: excluded entirely. Zero would read as
        // "measured and clean" when it means "never measured".
        if ($d === null || !empty($d['reset'])) continue;
        $total = array_sum($d['rate']);
        if ($total <= 0.0) continue;   // measured and clean is not an offender
        $rows[] = [
            'phy'        => $p['phy'] ?? $n,
            'rate_total' => $total,
            'rate'       => $d['rate'],
            'drive'      => phy_drive_label($drives, $p, $devBySerial),
        ];
    }
    usort($rows, fn($a, $b) => $a['rate_total'] === $b['rate_total']
        ? $a['phy'] <=> $b['phy']
        : $b['rate_total'] <=> $a['rate_total']);
    return array_slice($rows, 0, $limit);
}

/* $baselines defaults to none, so every existing caller (and the raw-only
   fresh install) renders exactly what it rendered before this plan. $drives is
   the decoded `drives` payload (the same shape $data carries), added last and
   defaulting to empty so every existing caller still renders exactly what it
   rendered before this plan. */
function renderPhyTables(array $data, array $baselines = [], ?int $now = null, ?int $uptime = null, array $drives = [], array $devBySerial = [], array $roles = []): string {
    $ctls    = $data['controllers'] ?? [$data];
    $storcli = ($data['backend'] ?? '') === 'storcli';
    $now   ??= time();
    $uptime ??= phy_baseline_uptime();
    return luCardPerController($ctls, function (int $i, array $ctl) use ($storcli, $now, $uptime, $drives, $devBySerial, $roles, $baselines): string {
        $out = '';
        $phys = $ctl['phys'] ?? [];
        if (empty($phys)) { $out .= '<p class="lu-muted">No PHY data.</p>'; return $out; }
        // This controller's drives, for the Device column and the offenders list
        // below. Empty while the drives cache is still warming — every Device
        // cell then reads "—" and the tab renders exactly as it used to.
        $ctlDrives = $drives['controllers'][$i]['drives'] ?? [];
        $devCell = function (array $p) use ($ctlDrives, $devBySerial): string {
            $d = phy_drive($ctlDrives, $p);
            $n = $d !== null ? drive_dev_name($d, $devBySerial) : null;
            return $n !== null ? '<code>' . htmlspecialchars($n) . '</code>' : '<span class="lu-muted">—</span>';
        };
        $roleCell = function (array $p) use ($ctlDrives, $devBySerial, $roles): string {
            $d = phy_drive($ctlDrives, $p);
            return lsi_role_cell($d !== null ? drive_dev_name($d, $devBySerial) : null, $roles);
        };

        // The Health tab's ring for THIS controller (read-only — see
        // phy_recent_rate()), keyed by $i exactly as the Health tab itself
        // keys its store: never by position in $phys, or a multi-controller
        // box would show one card's recent rate on another's row (plan 050).
        // A missing or too-short ring degrades to null/null below, and the
        // cells simply omit the recent figure — absence, not a false zero.
        $ctlRing     = health_store_read(health_store_path((int) $i));
        $ctlRingSpan = health_ring_span_secs($ctlRing);

        // Resolve every PHY's delta first: a reboot or driver reload zeroes the
        // whole controller's counters at once, so one invalidated PHY condemns
        // the controller's baseline rather than just its own row.
        $bl     = phy_baseline_for($baselines, (int) $i);
        $ts     = phy_baseline_ts($baselines, (int) $i);
        $deltas = [];
        $stale  = false;
        foreach ($phys as $n => $p) {
            $d = phy_baseline_delta($bl[(int) ($p['phy'] ?? -1)] ?? null, $p, $now, $uptime);
            if ($d !== null && !empty($d['reset'])) $stale = true;
            $deltas[$n] = $d;
        }
        if ($stale) $deltas = array_map(fn() => null, $deltas);
        // $ts is passed through even when stale: a stale baseline still EXISTS,
        // so the button must read "Reset Baseline" — the same words the stale
        // note tells the user to press.
        $out .= luPhyBaselineBar((int) $i, $ts, $stale);

        // Top offenders: reuses $deltas above, never a second rate computation
        // (see this function's header). Skipped entirely while stale — the bar
        // above already asks for a re-baseline, and a second, differently-worded
        // empty state here would only contradict it.
        if (!$stale) {
            $off = phy_top_offenders($phys, $deltas, $ctlDrives, 5, $devBySerial);
            if ($ts === null) {
                $out .= '<p class="lu-muted" style="font-size:12px;margin:8px 0">Set a baseline to rank PHYs by error rate.</p>';
            } elseif (empty($off)) {
                $out .= '<p class="lu-muted" style="font-size:12px;margin:8px 0">No PHY has logged errors since the baseline.</p>';
            } else {
                $out .= '<p class="lu-muted" style="font-size:12px;margin:2px 0 3px">Top offenders</p>';
                $rows = [];
                foreach ($off as $rank => $o) {
                    $drvLabel = $o['drive'] !== null ? htmlspecialchars($o['drive']) : 'drive not identified';
                    $rows[] = [
                        (string) ($rank + 1),
                        'PHY ' . htmlspecialchars((string) $o['phy']) . ' &mdash; ' . $drvLabel,
                        // Same average-since-baseline as the per-counter cells (see
                        // luPhyCell) — the tooltip repeats that in the column header
                        // instead of per-cell, since this is a table with one.
                        number_format($o['rate_total'], 1) . '/hr',
                        // lu-muted, not lu-phy-delta: the latter's count is asserted
                        // 1:1 against the main table's per-counter cells elsewhere in
                        // this file's tests, and this breakdown is a second, distinct
                        // rendering of the same rates.
                        '<span class="lu-muted">inv ' . number_format($o['rate']['inv'], 1)
                            . ' &middot; disp ' . number_format($o['rate']['disp'], 1)
                            . ' &middot; sync ' . number_format($o['rate']['sync'], 1)
                            . ' &middot; reset ' . number_format($o['rate']['reset'], 1) . '</span>',
                    ];
                }
                $out .= luTable(['#', 'PHY', 'Errors/hr — average since baseline', 'Breakdown'], $rows);
            }
        }

        // The backend field, and nothing else. It is always stamped: hba_each
        // writes it on both paths, and the {"error":…} payload returns long
        // before any renderer runs. The key-sniff that used to sit here read
        // storcli columns onto an lsiutil payload whose keys happened to match.
        if ($storcli) {
            // storcli backend: link/speed/attached-SAS (storcli) + error counters (sysfs)
            $rows = [];
            foreach ($phys as $n => $p) {
                $hasErr = (($p['inv'] ?? 0) + ($p['disp'] ?? 0) + ($p['sync'] ?? 0) + ($p['reset'] ?? 0)) > 0;
                $d      = $deltas[$n];
                $recent = $ctlRingSpan !== null ? phy_recent_rate($ctlRing, (int) ($p['phy'] ?? -1)) : null;
                $ec = fn($k) => luPhyCell($p[$k] ?? 0, $hasErr && ($p[$k] ?? 0) > 0, $d, $k, $recent, $ctlRingSpan);
                $rows[] = [
                    htmlspecialchars((string) $p['phy']),
                    $devCell($p),
                    $roleCell($p),
                    luLinkBadge($p['link']),
                    htmlspecialchars($p['speed']),
                    !empty($p['sas_addr']) ? '<code>' . htmlspecialchars(strtoupper($p['sas_addr'])) . '</code>' : '<span class="lu-muted">—</span>',
                    $ec('inv'), $ec('disp'), $ec('sync'), $ec('reset'),
                ];
            }
            $out .= luTable(['PHY', 'Device', 'Unraid', 'Link', 'Speed', 'Attached SAS Address', 'Invalid DWords', 'Disparity Errors', 'Loss of Sync', 'Reset Problems'], $rows);
        } else {
            // lsiutil backend: SAS error counters
            $rows = [];
            foreach ($phys as $n => $p) {
                $hasErr = ($p['inv'] + $p['disp'] + $p['sync'] + $p['reset']) > 0;
                $d      = $deltas[$n];
                $recent = $ctlRingSpan !== null ? phy_recent_rate($ctlRing, (int) ($p['phy'] ?? -1)) : null;
                $ec = fn($k) => luPhyCell($p[$k], $hasErr, $d, $k, $recent, $ctlRingSpan);
                $rows[] = [
                    htmlspecialchars((string) $p['phy']),
                    $devCell($p),
                    $roleCell($p),
                    luLinkBadge($p['link']),
                    $ec('inv'), $ec('disp'), $ec('sync'), $ec('reset'),
                ];
            }
            $out .= luTable(['PHY', 'Device', 'Unraid', 'Link', 'Invalid DWords', 'Disparity Errors', 'Loss of Sync', 'Reset Problems'], $rows);
        }
        return $out;
    });
}
