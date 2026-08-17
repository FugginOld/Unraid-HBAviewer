<?php
// Shared table/card helpers used by more than one tab.
/* ── Shared helpers ────────────────────────────────────────────────────────── */
/* Wrapped in its own scroller, not left to overflow the card. Plan 050 added
   "· N/hr in the last 10 min" to every error cell on the PHY tab, which roughly
   doubled the width of four columns and pushed the table out through the right
   edge of its panel — the data was rendered and unreachable. The wrapper is on
   luTable rather than on that one tab because every wide table has the same
   exposure, and overflow-x:auto costs nothing on a table that already fits. */
function luTable(array $headers, array $rows): string {
    $h = '<div class="lu-tscroll"><table class="lu-table"><thead><tr>';
    foreach ($headers as $hdr) $h .= '<th>' . htmlspecialchars($hdr) . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $cols) {
        $h .= '<tr>';
        foreach ($cols as $cell) $h .= '<td>' . $cell . '</td>';
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}

/* One card per controller, and the three rules every tab shares about it: the
   card shell carries data-ctl so the JS can find it, a heading appears only when
   there is more than one controller, and an errored controller still gets its
   own CLOSED card rather than bare text floating between its neighbours'.
   Four renderers each carried these seven lines, byte-identical apart from one
   word of comment, and each documented the contract by pointing at
   renderOverviewCards. $body renders only what is inside the card, and is not
   called at all for a controller that reported an error. */
function luCardPerController(array $ctls, callable $body): string {
    $multi = count($ctls) > 1;
    $out   = '';
    foreach ($ctls as $i => $ctl) {
        $out .= '<div class="lu-card first" data-ctl="' . $i . '">';
        if ($multi) $out .= luCtlHead($i);
        if (isset($ctl['error'])) {
            $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['error']) . '</p></div>';
            continue;
        }
        $out .= $body($i, $ctl) . '</div>';
    }
    return $out;
}

/* ── PHY Health (per controller; columns adapt to the detected backend) ────── */
function luCtlHead(int $i): string {
    // No top margin: this is now the first child of its controller's card, and
    // the card already supplies 18px of padding above it.
    return '<h3 style="margin:0 0 7px;color:#f5a623;font-size:12px;'
         . 'text-transform:uppercase;letter-spacing:0.06em;">Controller /c' . $i . '</h3>';
}
