<?php
// Overview tab: the temperature tile and the plain/grouped controller card renderers.

/* The temperature tile: the half-circle gauge, the reading, and the band chip
   under it. ONE copy, because two callers draw it now -- a plain card once per
   board, a grouped card once per IOC -- and two dies read through two slightly
   different instruments is a discrepancy nobody could explain.
   $i keys the gradient id, which must be unique across every gauge in the DOM
   (the Health tab lives in the same document and uses its own prefix). */
function luTempTile(array $v, int $i): string {
    // Critical renders as an inverted chip (white on solid fill) — #922b21
    // measures 1.94:1 as plain text on a dark card and is unreadable there.
    $tempChip = ($v['temp_band'] ?? '') === 'critical'
        ? '<span style="background:' . lsi_temp_color('critical') . ';color:#fff;padding:2px 7px;border-radius:2px;font-weight:700">CRITICAL</span>'
        : htmlspecialchars($v['temp_label']);   // colour comes from the tile's --mark
    // The gauge reads 0-110C.
    $frac = $v['temp'] !== '' ? max(0.0, min(1.0, (float) $v['temp'] / 110)) : 0.0;
    return '<div class="lu-gauge lu-tile' . (lsi_tile_is_light() ? ' light' : '') . '" id="lu-circle-' . $i . '">'
         . '<div class="lu-arc-wrap">'
         . lsi_gauge_svg('lu-grad-' . $i, $frac, $v['temp_grad'])
         . '<div class="lu-arc-readout">'
         . '<span class="val" id="lu-val-' . $i . '">' . ($v['temp'] !== '' ? $v['temp'] : 'N/A') . '</span>'
         . '<span class="unit">' . ($v['temp'] !== '' ? '&deg;C' : 'no sensor') . '</span></div></div>'
         . '<span class="lu-temp-band">' . $tempChip . '</span>'
         . '</div>';
}

/* The rows that describe the BOARD: what it is, and what is running on it.
   Both the plain card and a grouped board's parent open with exactly these,
   which is the whole reason they live here. The firmware expression below in
   particular carries a flex-layout trap that had to be fixed once already --
   a second copy of it is a second chance to miss the next fix. */
function luIdentityRows(array $v, string $driver, string $fwClause): string {
    return '<p>Model: <span>' . htmlspecialchars($v['model']) . '</span></p>'
         . '<p>Chip: <span>' . htmlspecialchars($v['chip']) . '</span></p>'
         // The verdict clause is strictly more informative than the bare
         // pre-P20 flag — it names the exact version — so once it has
         // something to say, the older flag steps aside rather than
         // repeating the same fact in a second amber. A suppressed or
         // unknown verdict has nothing to say, and the flag still does.
         /* Version and verdict live in ONE span. .lu-meta p is a flex row with
            justify-content:space-between, so every direct child becomes a
            separately-spaced column: a second span sent the version to the
            middle of the row and the verdict to the right edge, with the label
            stranded on the left. Keeping them in one child preserves the
            label-left / value-right shape every other row in this card has.
            The pre-P20 chip had the same defect before the verdict existed —
            it just only showed on SAS2 cards, so nobody had seen it. */
         . '<p>Firmware: <span>' . htmlspecialchars($v['firmware'])
         . ($v['fw_old'] && $fwClause === '' ? ' <span style="color:#f39c12" title="P20 is the IT-mode baseline for SAS2"><svg class="lu-i" aria-hidden="true"><use href="#lu-i-warn"/></svg> pre-P20</span>' : '')
         . $fwClause . '</span></p>'
         . ($v['bios'] !== '' ? '<p>BIOS: <span>' . htmlspecialchars($v['bios']) . '</span></p>' : '')
         . ($driver    !== '' ? '<p>Driver: <span>' . htmlspecialchars($driver) . '</span></p>' : '')
         . ($v['mode'] !== '' ? '<p>Mode: <span>' . htmlspecialchars($v['mode']) . '</span></p>' : '');
}

/* The rows that describe ONE die: what is attached to it, and which lsiutil
   port answers for it. On a plain card these sit in the single meta block
   between the identity rows and the sensitivity pair; on a grouped board they
   sit in each IOC's sub-card, because two dies do not share a drive list. */
function luDieRows(array $v): string {
    return ($v['drives']    !== '' ? '<p>Drives: <span>' . htmlspecialchars($v['drives']) . ' connected</span></p>' : '')
         . ($v['port_name'] !== '' ? '<p>lsiutil Port: <span>' . htmlspecialchars($v['port_label']) . '</span></p>' : '');
}

/* Which band the badge is tuned to, and when the reading was taken. Both are
   properties of the poll rather than of a die, so a grouped card shows them
   once on the parent instead of repeating them under every IOC. */
function luSensitivityRows(array $v, int $threshold): string {
    return '<p>Badge Sensitivity: <span>' . htmlspecialchars($v['cfg_band_label']) . ' (' . $threshold . '&deg;C+)</span></p>'
         . '<p>Last read: <span>' . lsi_time() . '</span></p>';
}

/* The health badge. The id is the handle the poller updates in place and must
   be unique per controller, so it is emitted only when there is a controller
   to name: a grouped board's parent badge shows a worst-of rollup rather than
   any one die's reading, and deliberately carries no id. */
function luBadgeRow(string $label, ?int $i = null): string {
    return '<p>HBA Health: <span class="lu-badge"'
         . ($i !== null ? ' id="lu-badge-' . $i . '"' : '')
         . '>' . $label . '</span></p>';
}

/* One controller, one card -- the markup this page has always emitted. Pulled
   out of renderOverviewCards's loop so a dual-IOC board can compose a grouped
   card from the same pieces instead of a second copy of them drifting apart. */
function renderControllerCard(array $c, int $i, array $cfg, string $driver): string {
    $port      = $cfg['HBA_PORT'];
    $threshold = $cfg['ALERT_THRESHOLD'];
    $showPcie  = $cfg['SHOW_PCIE'];
    $out = '';
    if (isset($c['error'])) {
        return '<div class="lu-card first"><div class="lu-error"><strong>Controller ' . $i . ':</strong> '
             . htmlspecialchars($c['error']) . '</div></div>';
    }
    $v = lsi_hba_view($c, $port, $i);
    // ?? [] rather than trusting the key exists: an absent verdict must
    // render as nothing, never as a TypeError that blanks the whole panel.
    $fwClause = fw_overview_clause($v['firmware_verdict'] ?? []);
    [$gDark, $gLight] = $v['temp_grad'];
    $out .= '<div class="lu-card first" style="--td:' . $gDark . ';--tl:' . $gLight . ';--sc:' . $v['color'] . '" data-ctl="' . $i . '">'
          . '<div class="lu-overview-row">'
          . luTempTile($v, $i)
          . '<div class="lu-meta">'
          // Identity, then this die's own rows, then the poll's -- the order
          // the page has always had. A grouped board splits these same three
          // groups across its parent and its sub-cards instead.
          . luIdentityRows($v, $driver, $fwClause)
          . luDieRows($v)
          . luSensitivityRows($v, $threshold)
          . luBadgeRow($v['label'], $i)
          . '</div></div>';
    if ($showPcie && (($c['pcie_width'] ?? '') || ($c['pcie_speed'] ?? ''))) {
        $out .= '<hr class="lu-divider"><div class="lu-pcie-row">';
        foreach ($v['pcie'] as $item) {
            $out .= '<div class="lu-pcie-item">' . $item['label'] . ': <span>' . htmlspecialchars($item['value']) . '</span></div>';
        }
        $out .= '</div>';
    }
    return $out . '</div>';
}

/* A dual-IOC board: one card for the board, one sub-card per controller.
 *
 * Board-level fields come from the first member. Both IOCs of a 9300-16i report
 * the same model, chip, firmware, BIOS and PCIe link, because those describe the
 * card; reading them from member 0 rather than merging avoids inventing a rule
 * for a disagreement that cannot happen on a working board.
 *
 * Temperature is the exception and must never be merged: two dies, two sensors.
 * On the maintainer's card they read 60C and 62C -- close because they share
 * airflow and load, not because they are one reading.
 *
 * $group holds INDICES into $ctls and they are NOT necessarily contiguous:
 * lsi_group_cards() sorts groups by first member, so an unrelated card sitting
 * between the two IOCs yields [[0,2],[1]]. Everything below indexes by the
 * member number, never by position. */
function renderGroupedCard(array $ctls, array $group, array $cfg, string $driver): string {
    $port      = $cfg['HBA_PORT'];
    $threshold = $cfg['ALERT_THRESHOLD'];
    $showPcie  = $cfg['SHOW_PCIE'];
    // The FIRST MEMBER, which is not necessarily slot 0 of $ctls -- an unrelated
    // card can sort between two IOCs of one board (see this function's header).
    $first     = (int) $group[0];
    $head      = $ctls[$first];
    $hv        = lsi_hba_view($head, $port, $first);
    $fwClause  = fw_overview_clause($hv['firmware_verdict'] ?? []);
    // Worst-of, so the parent says something true about the board: a card whose
    // second IOC is overheating must not show a green badge because its first
    // one is fine.
    $rank  = ['ok' => 0, 'warn' => 1, 'alert' => 2];
    $worst = 'ok';
    foreach ($group as $i) {
        $s = (string) ($ctls[$i]['status'] ?? 'ok');
        if (($rank[$s] ?? 0) > ($rank[$worst] ?? 0)) { $worst = $s; }
    }

    $out = '<div class="lu-card first lu-card-parent" data-status="' . htmlspecialchars($worst) . '"'
         . ' style="--sc:' . lsi_status_color($worst) . '">'
         . '<div class="lu-meta">'
         // The board's own rows and the poll's, read from the first member.
         // luDieRows is absent by design: drives and lsiutil port belong to a
         // die, and each sub-card below carries its own.
         . luIdentityRows($hv, $driver, $fwClause)
         . luSensitivityRows($hv, $threshold)
         // No $i: this badge is the worst-of rollup, not any one die's reading,
         // so it must not answer to a per-controller id the poller updates.
         . luBadgeRow(lsi_status_label($worst))
         . '</div>';

    foreach ($group as $n) {
        // Cast once: $i is interpolated into ids and attributes a dozen times
        // below, and two idioms for the same value ten lines apart is how one
        // of them eventually gets forgotten.
        $i = (int) $n;
        $c = $ctls[$i];
        // --sc/--td/--tl are restated on the sub-card so this IOC's gauge and
        // badge take ITS colours, not the board rollup's.
        if (isset($c['error'])) {
            $out .= '<div class="lu-card-ioc" data-ctl="' . $i . '">'
                  . '<span class="lu-ioc-label">Controller ' . $i . '</span>'
                  . '<div class="lu-error">' . htmlspecialchars($c['error']) . '</div></div>';
            continue;
        }
        $v = lsi_hba_view($c, $port, $i);
        [$gDark, $gLight] = $v['temp_grad'];
        // PCI Location is per FUNCTION, not per slot: the two IOCs of a 9300-16i
        // answer to 00:84:00:00 and 00:86:00:00, and that address is how a card
        // is correlated with lspci and `storcli /cN`. Showing the board one of
        // them, labelled as the board's, put a wrong address on the page.
        // Gated on SHOW_PCIE alone, deliberately — NOT on pcie_width/pcie_speed
        // the way the board's row below is. Those two come from sysfs and can be
        // absent; the address comes from the backend that enumerated the card and
        // is the identity of the die, so a board with no sysfs link data now
        // shows both addresses where the flat page showed neither.
        $loc = $showPcie ? (string) ($c['pci_location'] ?? '') : '';
        $out .= '<div class="lu-card-ioc" style="--td:' . $gDark . ';--tl:' . $gLight . ';--sc:' . $v['color'] . '" data-ctl="' . $i . '">'
              . '<span class="lu-ioc-label">Controller ' . $i . '</span>'
              . '<div class="lu-overview-row">'
              . luTempTile($v, $i)
              . '<div class="lu-meta">'
              . ($loc !== '' ? '<p>PCI Location: <span>' . htmlspecialchars($loc) . '</span></p>' : '')
              . luDieRows($v)
              . luBadgeRow($v['label'], $i)
              . '</div></div></div>';
    }

    // One PCIe row for the board: width, speed and power mode are the SLOT's,
    // and both IOCs report them identically through it. PCI Location is not --
    // it belongs to each function and is rendered per sub-card above.
    if ($showPcie && (($head['pcie_width'] ?? '') || ($head['pcie_speed'] ?? ''))) {
        $out .= '<hr class="lu-divider"><div class="lu-pcie-row">';
        foreach ($hv['pcie'] as $item) {
            // Matched on the label lsi_hba_view() assigns (view.php). Renaming it
            // there would put the address back on the board — the item COUNT
            // asserted in ajax_render_test.php is what catches that, not this line.
            if ($item['label'] === 'PCI Location') continue;
            $out .= '<div class="lu-pcie-item">' . $item['label'] . ': <span>' . htmlspecialchars($item['value']) . '</span></div>';
        }
        $out .= '</div>';
    }
    return $out . '</div>';
}

/* Render the Overview cards (one per CARD, which is usually one per controller)
   — same markup the Monitor page used to emit server-side, moved here so the
   initial load is async. */
function renderOverviewCards(array $data, array $cfg): string {
    $driver = $data['driver'] ?? '';
    $out    = '<div class="lu-ov-grid">';
    $ctls   = lsi_controllers($data);
    // fw_load(), never a hand-built map: fw_load() re-keys every board through
    // fw_normalize(), so a literal 'SAS9300-16i' key would miss every lookup and
    // nothing would ever group. The read is memoized inside fw_load().
    $groups = lsi_group_cards($ctls, lsi_ioc_counts(fw_load()));
    foreach ($groups as $g) {
        // A group of one is the old path exactly -- same function, no wrapper,
        // byte-identical output. That is what keeps every existing golden green
        // and every user without a dual-IOC board seeing no change at all.
        if (count($g) === 1) {
            $out .= renderControllerCard($ctls[$g[0]], (int) $g[0], $cfg, $driver);
            continue;
        }
        $out .= renderGroupedCard($ctls, $g, $cfg, $driver);
    }
    return $out . '</div>';
}

function luLinkBadge(string $link): string {
    return strtolower($link) === 'up'
        ? '<span class="lu-link-up">UP</span>' : '<span class="lu-link-down">DOWN</span>';
}
