<?php
// Health tab: per-controller metadata lookup and the indicator-row renderer.
/* ── HBA Health (per controller; five indicator rows + a rollup pill) ──────
   Cosmetic-only best-effort board/chip label pulled from the existing 60s
   overview cache (get_hba_info.sh already maintains it) — get_hba_health.sh
   itself emits no board/chip fields, since health.php's ring/rate logic
   never needs them. Missing cache -> just the /cN label, nothing breaks. */
function luHealthCtlMeta(int $i): array {
    $cache = getenv('LSI_CACHE') ?: '/tmp/lsiutil_dash.json';
    if (!is_file($cache)) return ['board' => '', 'chip' => ''];
    $d = json_decode((string) @file_get_contents($cache), true);
    $ctls = lsi_controllers(is_array($d) ? $d : []);
    $c = $ctls[$i] ?? [];
    return ['board' => $c['board_name'] ?? '', 'chip' => $c['model'] ?? ''];
}

/* $cfg is injected so this stays testable without /boot; the caller passes the
   live config. Only host_link reads it (the expected-PCIe-link settings). */
function renderHealthTables(array $data, array $cfg = []): string {
    // Display only — every band edge below is compared in °C.
    $unit = (int) ($cfg['TEMP_UNIT'] ?? 0);
    $ctls = $data['controllers'] ?? [$data];
    return luCardPerController($ctls, function (int $i, array $ctl) use ($cfg, $unit): string {
        $out = '';
        // The only place that touches the /tmp ring — see health.php's header.
        $file  = health_store_path($i);
        $ring  = health_ingest(health_store_read($file), $ctl);
        health_store_write($file, $ring);

        $rates = health_rates($ring);
        $ind   = health_indicators($ring, $rates, time(), $cfg);
        [$state, $reason] = health_rollup($ind);

        $meta  = luHealthCtlMeta($i);
        $fw    = (string) ($ctl['fw'] ?? '');
        $pill  = lsi_health_color($state);

        $out .= '<div class="lu-health-head">'
              . '<span class="lu-health-title">'
              . ($meta['board'] !== '' ? htmlspecialchars($meta['board']) . ' &middot; ' : '')
              . '/c' . $i
              . ($meta['chip'] !== '' ? ' &middot; ' . htmlspecialchars($meta['chip']) : '')
              . ($fw !== '' ? ' &middot; FW ' . htmlspecialchars($fw) : '')
              . '</span>'
              . '<span class="lu-health-pill" style="color:' . $pill . ';background:color-mix(in srgb,' . $pill . ' 15%, transparent)">'
              . htmlspecialchars(ucfirst($state)) . ' &mdash; ' . htmlspecialchars($reason)
              . '</span></div>';

        // Gauge + band meter share one instrument tile. The gauge reads
        // "N / total indicators ok" — a count of what health_indicators()
        // actually returned, NOT a 0-100 score (plan 030, option A): the
        // indicators are categorical and a manufactured score that drifts from
        // 89 to 87 for unexplainable reasons is worse than no number.
        $g      = health_gauge($ind);
        $gStops = lsi_health_gradient($state);
        $out .= '<div class="lu-tile lu-health-tile' . (lsi_tile_is_light() ? ' light' : '')
              . '" style="--td:' . $gStops[0] . ';--tl:' . $gStops[1] . '">'
              . '<div class="lu-gauge"><div class="lu-arc-wrap">'
              . lsi_gauge_svg('lu-hgrad-' . $i, $g['frac'], $gStops)
              . '<div class="lu-arc-readout count"><span class="val">' . $g['ok'] . ' / ' . $g['total'] . '</span>'
              . '<span class="unit">indicators ok</span></div></div></div>';

        // Only thermal earns a band meter: it is the one continuous metric with
        // meaningful bands. Scaled 0-110C with segment boundaries at the
        // plan-018 band cut-points (65/75/85/95): each label's inline `left`
        // below is that boundary's true percentage of 110 — NOT evenly spaced
        // — and must stay in step with the .lu-band-seg flex weights in
        // hbaviewer.php; both encode the same band edges, just in different files.
        $temp = $ctl['temp'] ?? null;
        if ($temp !== null && $temp !== '') {
            $pct = max(0, min(100, ((float) $temp / 110) * 100));
            $out .= '<div class="lu-band-meter"><div class="lu-band-track">'
                  . '<span class="lu-band-seg s0"></span><span class="lu-band-seg s1"></span>'
                  . '<span class="lu-band-seg s2"></span><span class="lu-band-seg s3"></span><span class="lu-band-seg s4"></span>'
                  . '<span class="lu-band-marker" style="left:' . number_format($pct, 1) . '%" title="' . htmlspecialchars(lsi_temp_str($temp, $unit)) . '"></span>'
                  . '</div><div class="lu-band-labels">'
                  . '<span style="left:0%">0</span><span style="left:59.09%">65</span>'
                  . '<span style="left:68.18%">75</span><span style="left:77.27%">85</span>'
                  . '<span style="left:86.36%">95</span><span style="left:100%">110</span></div></div>';
        }
        $out .= '</div>';

        // Order and labels mirror hbaviewer.php's header sentence ("Thermal, link
        // integrity, topology, host link, and read health"), which is also
        // health_indicators()'s return order. Every key it returns must appear
        // here: the gauge above counts all of them, so an omitted row makes the
        // count contradict the list beneath it (plan 031 — `thermal` was missing).
        $out .= '<div class="lu-indicator-rows">';
        foreach (['thermal' => 'Thermal', 'link_integrity' => 'Link Integrity', 'topology' => 'Topology', 'host_link' => 'Host Link', 'controller' => 'Read Health'] as $key => $label) {
            $row = $ind[$key] ?? ['state' => 'unknown', 'value' => '—'];
            // The reason string health_indicators() already computes for the rollup
            // pill, printed under its own row too. Without it a row reads "Link
            // Integrity 0/hr" with nothing saying 0 what (issue #11) — the number
            // is only meaningful next to the sentence that names it.
            $hint = (string) ($row['reason'] ?? '');
            [$bDark, $bLight] = lsi_health_gradient($row['state']);
            // Sprite ids live in hbaviewer.php's #lu-wrap. Most match $key; these
            // two do not, and a mismatch renders an empty icon slot silently.
            $icon = ['link_integrity' => 'link', 'host_link' => 'hostlink'][$key] ?? $key;
            $out .= '<div class="lu-indicator-row">'
                  . '<span class="lu-ind-dot" style="--gd:' . $bDark . ';--gl:' . $bLight . '"></span>'
                  . '<svg class="lu-ind-icon" aria-hidden="true"><use href="#lu-i-' . $icon . '"/></svg>'
                  . '<span class="lu-indicator-label">' . htmlspecialchars($label) . '</span>'
                  . '<span class="lu-indicator-value">' . htmlspecialchars((string) ($row['value'] ?? '')) . '</span>'
                  . ($hint !== '' ? '<span class="lu-ind-hint">' . htmlspecialchars($hint) . '</span>' : '')
                  . '</div>';
        }
        $out .= '</div>';
        return $out;
    });
}
