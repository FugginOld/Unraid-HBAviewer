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

require_once __DIR__ . '/view.php';   // lsi_age_str(): the one "how old" formatter, reused for "how long"
require_once __DIR__ . '/config.php'; // lsi_temp_str(): °C/°F display for the thermal indicator

// One sample per Health-tab render, not a timer -- there is no cron or daemon
// here, so the ring's span is however often someone actually opens the tab
// (open today, open tomorrow, and the ring is 24h wide; refresh twice in a
// minute, and it's seconds wide). The cap exists to bound RAM, not to encode
// a cadence.
const HEALTH_RING_CAP   = 240;
const HEALTH_STALE_SECS = 900;   // newest sample older than this -> unknown
// Below this span, a "0/hr" all-clear is not evidence: a PHY logging 2
// events/hour is expected to log zero in a ten-minute window. Growth is
// exempt -- a single counter tick still warns immediately no matter how
// young the ring is (see health_indicators()'s link_integrity block; plan 050).
const HEALTH_MIN_CLEAR_SECS = 1800;

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

/* The ring's outer span in seconds, or null exactly when health_rates()
   would also return [] for it (fewer than two samples, or under 60s apart —
   same gate, kept in sync deliberately: a caller uses this span to LABEL the
   very window health_rates() measured over, so the two must agree on what
   "not enough yet" means). Does not touch health_rates() itself — this is a
   read-only sibling, not a refactor of the pinned rate arithmetic. */
function health_ring_span_secs(array $ring): ?int {
    if (count($ring) < 2) return null;
    $dt = (end($ring)['t'] ?? 0) - ($ring[0]['t'] ?? 0);
    return $dt >= 60 ? $dt : null;
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
/* What this link could actually reach, and why — [width, speed|null, source].
   `source` is 'set', 'slot' or 'card', and only exists so host_link can word
   itself honestly.

   Precedence: an explicit setting, else the slot's own ceiling, else the card's
   maximum. That last is the pre-plan-056 rule and stays as the fallback for
   samples collected before slot_* existed and for platforms whose bridge
   publishes nothing — the ring survives upgrades, so old entries must not
   suddenly read as faults.

   CLAMPED to the card. A slot wider than the card is at least as common as the
   narrow slot #13 reported: the maintainer's own x8 cards sit in x16 slots, and
   judging those against the slot alone would call two healthy cards downtrained.
   The ceiling is the lower of the two, always.

   Config is INJECTED, not read here. This function and health_indicators() are
   pure over their inputs, which is what lets tests/health_test.php drive them
   with no /boot and no config file. */
function health_link_expected(array $link, array $cfg = []): array {
    $cardW = (int) ($link['max_width'] ?? 0);
    $cardS = health_rate_number((string) ($link['max_speed'] ?? ''));
    $slotW = (int) ($link['slot_width'] ?? 0);
    $slotS = health_rate_number((string) ($link['slot_speed'] ?? ''));
    $setW  = (int) ($cfg['PCIE_EXPECT_WIDTH'] ?? 0);
    $setG  = (int) ($cfg['PCIE_EXPECT_GEN'] ?? 0);

    $why = 'card';
    $w   = $cardW;
    if ($slotW > 0 && ($cardW <= 0 || $slotW < $cardW)) { $w = $slotW; $why = 'slot'; }
    if ($setW > 0) { $w = $setW; $why = 'set'; }

    $sp = $cardS;
    if ($slotS !== null && ($cardS === null || $slotS < $cardS)) { $sp = $slotS; if ($why === 'card') $why = 'slot'; }
    if ($setG > 0) { $sp = health_pcie_gen_rate($setG); $why = 'set'; }

    return [$w, $sp, $why];
}

/* PCIe generation to its GT/s lane rate. The table is the specification's, not
   a formula: Gen1-2 double, Gen3 does not (8b/10b became 128b/130b), and Gen6
   changes signalling again. Anything unrecognised expects nothing rather than
   guessing a rate the card will then be judged against. */
function health_pcie_gen_rate(int $gen): ?float {
    return [1 => 2.5, 2 => 5.0, 3 => 8.0, 4 => 16.0, 5 => 32.0, 6 => 64.0][$gen] ?? null;
}

/* The expected speed as it should READ in a message. Prefers the card's own
   spelling ("8.0 GT/s") over a bare number so the two halves of the sentence
   match; falls back to the rate when the expectation came from a setting and
   there is no string to borrow. */
function health_link_speed_label(array $link, ?float $expS): string {
    $maxStr = (string) ($link['max_speed'] ?? '');
    if ($expS !== null && $maxStr !== '' && health_rate_number($maxStr) === $expS) return $maxStr;
    return $expS === null ? '' : rtrim(rtrim(number_format($expS, 1), '0'), '.') . ' GT/s';
}

function health_indicators(array $ring, array $rates, int $now, array $cfg = []): array {
    $newest = $ring ? end($ring) : null;

    // ── thermal: temp_band, plan 018's bands (NOT the spec's four-state table) ──
    $band = $newest['temp_band'] ?? '';
    $thermalMap = ['normal' => 'ok', 'elevated' => 'watch', 'warning' => 'warning', 'alert' => 'critical', 'critical' => 'critical'];
    if (isset($thermalMap[$band])) {
        $temp    = $newest['temp'] ?? '';
        $tempStr = lsi_temp_str($temp, (int) ($cfg['TEMP_UNIT'] ?? 0));
        $thermal = [
            'state'  => $thermalMap[$band],
            'reason' => $tempStr !== '' ? "{$tempStr} — " . ucfirst($band) . " band" : ucfirst($band),
            'value'  => $tempStr !== '' ? $tempStr : '—',
        ];
    } else {
        $thermal = ['state' => 'unknown', 'reason' => 'No temperature reading', 'value' => '—'];
    }

    // ── link_integrity: worst PHY, worst counter, its index names the reason ──
    if (empty($rates)) {
        $link_integrity = ['state' => 'unknown', 'reason' => 'Not enough samples yet — needs two reads at least a minute apart', 'value' => '—'];
    } else {
        // In production $rates is always health_rates($ring), so this span
        // resolves whenever $rates is non-empty (health_ring_span_secs() gates
        // on the same >= 2 samples / >= 60s rule). Tests are allowed to pass a
        // synthetic $rates decoupled from $ring (see this function's header
        // comment) precisely so history need not be faked — that combination
        // can leave no real span to report, hence the null fallback: "just
        // now", and the all-clear floor below treats "no span" the same as
        // "too short", which is the conservative reading either way.
        $spanSecs = health_ring_span_secs($ring);
        $spanStr  = lsi_age_str($spanSecs ?? 0);

        $labels = ['inv' => 'invalid dword', 'disp' => 'disparity', 'sync' => 'loss of sync', 'rst' => 'reset problem'];
        $worstState = 'ok'; $worstRank = 0; $worstValue = '0/hr';
        $worstReason = "No new cabling errors on any PHY in the last {$spanStr}";
        foreach ($rates as $r) {
            foreach ($labels as $k => $label) {
                $s = health_rate_state($k, $r[$k]);
                $rank = health_rank($s);
                if ($rank > $worstRank) {
                    $worstRank  = $rank;
                    $worstState = $s;
                    $worstReason = sprintf('PHY %s %s errors rising (%s over the last %s)', $r['idx'], $label, health_rate_str($r[$k]), $spanStr);
                    $worstValue  = health_rate_str($r[$k]);
                }
            }
        }

        // The all-clear floor: growth already set $worstState above and is
        // NEVER touched here, at any window length. Only a still-'ok' result
        // gets downgraded, and only because the window was too short to have
        // seen a slow fault yet -- silence is not the same claim as "clean".
        if ($worstState === 'ok' && ($spanSecs === null || $spanSecs < HEALTH_MIN_CLEAR_SECS)) {
            $worstState = 'unknown';
            $worstReason = "Watched for {$spanStr} — too short to rule out a slow fault";
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

    // ── host_link: the negotiated link against what it could REACH ────────────
    $link = $newest['link'] ?? [];
    $w  = (int) ($link['width'] ?? 0);  $mw = (int) ($link['max_width'] ?? 0);
    $s  = health_rate_number((string) ($link['speed'] ?? ''));
    $ms = health_rate_number((string) ($link['max_speed'] ?? ''));
    [$expW, $expS, $why] = health_link_expected($link, $cfg);
    // A link below what it could reach. Both comparisons are against the
    // EXPECTED ceiling, never the card's own maximum — see health_link_expected.
    $widthDown = $expW > 0 && $w > 0 && $w < $expW;
    $speedDown = $expS !== null && $s !== null && $s < $expS;
    if ($widthDown || $speedDown) {
        $host_link = [
            'state'  => 'warning',
            'reason' => sprintf('PCIe link downtrained: x%d %s of x%d %s expected',
                                $w, $link['speed'] ?? '', $expW, health_link_speed_label($link, $expS)),
            'value'  => "x{$w} / x{$expW}",
        ];
    } elseif ($w <= 0) {
        $host_link = ['state' => 'ok', 'reason' => 'No PCIe downtraining reported', 'value' => '—'];
    } else {
        /* Running at the ceiling. WHAT the ceiling is decides the wording, and
           "full" is only honest when nothing is holding the card back — issue
           #14 was told its chipset-limited x4 was "running at its full x4
           width" while the card could do x8. */
        $at = 'Running at x' . $w . (($link['speed'] ?? '') !== '' ? ' ' . $link['speed'] : '');
        $slotKnown = (int) ($link['slot_width'] ?? 0) > 0;
        $anyCeiling = $expW > 0 || $expS !== null;
        $host_link = ['state' => 'ok', 'value' => 'x' . $w, 'reason' => match (true) {
            $why === 'set'  => $at . ' — matches the expected link you set',
            $why === 'slot' => $at . ' — this slot\'s maximum'
                                   . ($mw > $expW ? ' (card supports x' . $mw . ')' : ''),
            /* Nothing to compare against. The lsiutil backend reports no
               maximum at all (max_width 0, max_speed ""), so the old message
               told #14's SAS9207-8i it was at "its full x4 width" on the
               strength of no information whatsoever. Say what is known and
               stop there — an unmeasured link must not read as a verified one,
               which is this repo's rule everywhere else. */
            !$anyCeiling => $at . ' — this controller reports no maximum, so there is nothing to check it against',
            // Card maximum known, slot silent: true of the card, unknown of the
            // slot, so claiming both would be the same overreach one step down.
            !$slotKnown  => $at . ' — the full width this card reports',
            default      => $at . ' — the full width of both card and slot',
        }];
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
