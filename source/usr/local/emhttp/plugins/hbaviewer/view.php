<?PHP
/* Shared interpretation of the get_hba_info.sh JSON for display.
   One home for status->color/label, the model/chip/firmware fallbacks, and the
   PCIe-row assembly. The monitor page, the dashboard tile, and the AJAX refresh
   endpoint all consume this — each keeps its own markup/CSS, none re-derives.
   Values are returned RAW; each consumer escapes for its own medium. */

require_once __DIR__ . '/firmware_index.php';

function lsi_status_color(string $s): string {
    return match ($s) { 'alert' => '#e74c3c', 'warn' => '#f39c12', default => '#2ecc71' };
}
function lsi_status_label(string $s): string {
    return match ($s) { 'alert' => 'ALERT', 'warn' => 'WARNING', default => 'NORMAL' };
}

/* HBA Health tab's five-indicator palette (plan 020). Reuses lsi_status_color's
   ok/warning/critical hexes, extended with the two states it lacks: `watch`
   sits between ok and warning; `unknown` is grey and must NEVER read as
   healthy — a card that cannot be read is not a card that is fine. */
function lsi_health_color(string $s): string {
    return match ($s) {
        'critical' => '#e74c3c', 'warning' => '#e67e22',
        'watch'    => '#f1c40f', 'unknown' => '#7c807c',
        default    => '#2ecc71',
    };
}

/* The same five states as [dark, light] gradient stops, for the marks the
   Health tab DRAWS (the gauge arc and the indicator bars) rather than writes
   as text. Borrowed from the temperature bands so the tab does not carry a
   second palette; `unknown` is the one state with no thermal analogue, and it
   is grey because a card that cannot be read is not a card that is fine. */
function lsi_health_gradient(string $state): array {
    return match ($state) {
        'critical' => lsi_temp_gradient('alert'),
        'warning'  => lsi_temp_gradient('warning'),
        'watch'    => lsi_temp_gradient('elevated'),
        'unknown'  => ['#4a4d4a', '#8f938f'],
        default    => lsi_temp_gradient('normal'),
    };
}

/* Temperature band -> [dark, light] gradient stops. Each band is a gradient,
   not a flat colour, so the mark carries its own internal contrast and reads on
   any surface. This replaced a flat palette that had been contrast-measured
   against the plugin's own dark cards — a measurement plan 021 invalidated the
   moment those cards started following the Unraid theme (bands fell to 1.36:1
   on `white`). Do not "simplify" these back to single hexes.
   SEPARATE from lsi_status_color on purpose: the thermometer shows heat, the
   badge shows the whole-controller rollup (which also reflects drive and PHY
   problems). Conflating them is what made issue #8 read as a false temperature
   warning. */
function lsi_temp_gradient(string $band): array {
    return match ($band) {
        'critical' => ['#6b0f0c', '#b82820'],
        'alert'    => ['#9c1810', '#e8443a'],
        'warning'  => ['#a85410', '#f09428'],
        'elevated' => ['#b8890a', '#f5d020'],
        default    => ['#0f7a1a', '#41d141'],
    };
}

/* Survives the move to gradients for ONE caller: the critical chip, which is a
   flat fill behind white text and needs no gradient. #922b21 is that fill and
   is not a foreground — it measures 1.94:1 as a stroke on a dark card. Do not
   "promote" it. Any other band falls back to its dark stop, so this function
   never introduces a sixth hex. */
function lsi_temp_color(string $band): string {
    return $band === 'critical' ? '#922b21' : lsi_temp_gradient($band)[0];
}

/* The gradient stop that reads as TEXT drawn straight onto the page's own
   surfaces: a light theme needs the dark stop, a dark theme the light one.
   Marks that sit ON the instrument tile do NOT use this — the tile supplies
   its own background and CSS picks the colour there. */
function lsi_temp_text(string $band): string {
    [$dark, $light] = lsi_temp_gradient($band);
    return lsi_tile_is_light() ? $dark : $light;
}

function lsi_band_label(string $band): string {
    return match ($band) {
        'critical' => 'CRITICAL', 'alert' => 'ALERT', 'warning' => 'WARNING',
        'elevated' => 'ELEVATED', default => 'NORMAL',
    };
}

/* strftime -> date() token translation. Unraid's Display Settings store the time
   format strftime-style (e.g. "%I:%M %p"), but PHP's strftime() is deprecated in
   8.1 and gone in 9, so translate rather than call it. Returns '' for any token
   we don't know, which the caller treats as "fall back to 24-hour" — better a
   plain timestamp than a mangled one. Literal letters are backslash-escaped so
   date() cannot reinterpret them as format characters. */
function lsi_strftime_to_date(string $f): string {
    static $map = [
        'H' => 'H', 'k' => 'G', 'I' => 'h', 'l' => 'g',
        'M' => 'i', 'S' => 's', 'p' => 'A', 'P' => 'a',
        'R' => 'H:i', 'T' => 'H:i:s', 'r' => 'h:i:s A', '%' => '\\%',
    ];
    $out = ''; $len = strlen($f);
    for ($i = 0; $i < $len; $i++) {
        if ($f[$i] === '%' && $i + 1 < $len) {
            $tok = $f[++$i];
            if (!isset($map[$tok])) return '';
            $out .= $map[$tok];
            continue;
        }
        $out .= ctype_alpha($f[$i]) ? '\\' . $f[$i] : $f[$i];
    }
    return $out;
}

/* One dynamix display preference. Unraid exposes $display to page scripts, but
   NOT to the AJAX endpoints — those are fetched directly, with no dynamix
   bootstrap — so fall back to the config file dynamix wrote it to. '' when
   neither has it, which every caller treats as "keep the previous default". */
function lsi_display_pref(string $key): string {
    if (isset($GLOBALS['display'][$key]) && is_string($GLOBALS['display'][$key])) {
        return trim($GLOBALS['display'][$key]);
    }
    $cfg = @parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true);
    return (is_array($cfg) && isset($cfg['display'][$key]) && is_string($cfg['display'][$key]))
        ? trim($cfg['display'][$key]) : '';
}

/* Which instrument-tile treatment to use. Unraid's themes are `white`, `black`,
   `azure` and `gray`; the two light ones get the filled panel. Absent or
   unrecognised -> dark treatment, which is what shipped before plan 030.
   Do NOT try to detect this in CSS: no Unraid theme sets prefers-color-scheme,
   and the variables that do differ cannot be branched on from a stylesheet. */
function lsi_tile_is_light(): bool {
    return in_array(lsi_display_pref('theme'), ['white', 'azure'], true);
}

/* The half-circle gauge, shared by the Overview card, the dashboard tile and
   the Health tab — three copies of one geometry, and the first two had already
   drifted apart once. viewBox 0 0 200 112; the arc is an r=80 semicircle from
   (20,100) to (180,100), so its length is pi*80 = 251.3. dashoffset counts DOWN
   from that full length, so $frac 0 leaves the arc completely EMPTY — a 0 °C
   reading must not render as a full gauge.
   $id must be unique per gauge ON THE PAGE: two <linearGradient>s sharing an id
   make every gauge render the first one's colours. */
const LSI_ARC_LEN = 251.3;

function lsi_gauge_svg(string $id, float $frac, array $stops): string {
    $frac = max(0.0, min(1.0, $frac));
    $d    = 'M20,100 A80,80 0 0 1 180,100';
    return '<svg class="lu-arc" viewBox="0 0 200 112" aria-hidden="true">'
         . '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="1" y2="0">'
         . '<stop offset="0" stop-color="' . $stops[0] . '"/>'
         . '<stop offset="1" stop-color="' . $stops[1] . '"/>'
         . '</linearGradient></defs>'
         . '<path class="lu-arc-bg" d="' . $d . '"/>'
         . '<path class="lu-arc-fg" d="' . $d . '" stroke="url(#' . $id . ')"'
         . ' stroke-dasharray="' . LSI_ARC_LEN . '"'
         . ' stroke-dashoffset="' . number_format(LSI_ARC_LEN * (1 - $frac), 1, '.', '') . '"/>'
         . '</svg>';
}

/* How old something is, in the coarsest unit that still says it: "just now",
   "6 min", "3 h", "2 d". Deliberately one unit and no "ago" — callers put it in
   their own sentence. This exists so a cached reading always states its own age
   rather than being presented as if it were live. */
function lsi_age_str(int $secs): string {
    if ($secs < 60)    return 'just now';
    if ($secs < 3600)  return intdiv($secs, 60) . ' min';
    if ($secs < 86400) return intdiv($secs, 3600) . ' h';
    return intdiv($secs, 86400) . ' d';
}

/* Timestamp in the user's configured format. date() already renders in the
   system timezone — only the 12/24-hour choice needs resolving here, so a
   missing preference degrades to the previous 24-hour output rather than
   guessing.
   ponytail: Unraid writes strftime-style formats (e.g. "%I:%M %p"), translated by
   the helper above; a plain date() format is still accepted for the case where
   $display carries one. Anything else drops back to 24-hour. */
function lsi_time(?int $when = null): string {
    $when ??= time();
    $fmt  = lsi_display_pref('time');
    if ($fmt === '' || strlen($fmt) > 32) {
        return date('H:i:s', $when);
    }
    // Unraid writes strftime formats; a date() format is still accepted for the
    // case where $display carries one.
    if (strpos($fmt, '%') !== false) {
        $d = lsi_strftime_to_date($fmt);
        return $d === '' ? date('H:i:s', $when) : date($d, $when);
    }
    if (!preg_match('/^[A-Za-z:\.\- ]+$/', $fmt)) {
        return date('H:i:s', $when);
    }
    return date($fmt, $when);
}

/* Controllers from a decoded backend payload. Accepts the multi-controller
   contract {"controllers":[...]} and (defensively) a legacy flat single object,
   so consumers can loop uniformly regardless of backend or contract version. */
function lsi_controllers(array $data): array {
    return $data['controllers'] ?? [$data];
}

/* The Overview's one-line firmware clause. Rendered only for the three states
   that carry an actual comparison — a suppressed or unknown verdict has a
   reason worth reading, and a one-line summary cannot carry it, so the row
   shows the version alone and the firmware page explains. A colourless marker
   with no explanation next to a version reads as a fault nobody can act on. */
function fw_overview_clause(array $verdict): string {
    $s = $verdict['status'] ?? '';
    if ($s === 'ahead') {
        return ' <span class="lu-muted" title="Newer than the plugin&#39;s index — the index is stale, not this card">newer than index</span>';
    }
    if ($s !== 'current' && $s !== 'behind') return '';
    // One rule, one home: fw_verdict_color() decides both colours, including the
    // green. A hardcoded hex here meant a change to that rule reached the JSON
    // endpoint and the flash page but left this server-rendered Overview green
    // on a board the index only calls a floor. flash_php_test.php greps for the
    // literals, so do not name one even in a comment.
    $colour = fw_verdict_color($verdict);
    $span   = ' <span' . ($colour !== '' ? ' style="color:' . $colour . '"' : ' class="lu-muted"');
    if ($s === 'current') {
        return $span . ' title="Matches the newest IT firmware in the plugin&#39;s index">&#10003; current</span>';
    }
    return $span . ' title="Newest IT firmware known for this board">&#9650; '
         . htmlspecialchars((string) ($verdict['latest'] ?? '')) . ' known</span>';
}

/* $data = one controller's JSON; $port = configured lsiutil port; $idx = its
   position in the controllers list (for the storcli /cN label). */
function lsi_hba_view(array $data, int $port, int $idx = 0): array {
    $status = $data['status'] ?? 'ok';
    $portName = $data['port_name'] ?? 'ioc0';
    // lsiutil cards name a port ("ioc0 (lsiutil -p1)"); storcli cards name the
    // controller index ("Controller /c0") since port_name is empty there.
    $portLabel = $portName !== '' ? "$portName (lsiutil -p$port)" : "Controller /c$idx";

    $pcie = [];
    foreach ([
        'pcie_width'   => 'PCIe Width',
        'pcie_speed'   => 'PCIe Speed',
        'power_mode'   => 'Power Mode',
        'pci_location' => 'PCI Location',
    ] as $key => $label) {
        if (!empty($data[$key])) $pcie[] = ['label' => $label, 'value' => $data[$key]];
    }

    // One verdict, two surfaces. Computed here rather than in either renderer so
    // the Overview card and the firmware page cannot drift apart about whether
    // this card is behind.
    $verdict = fw_evaluate([
        'board'        => $data['board_name']   ?? '',
        'chip'         => $data['model']        ?? '',
        'firmware'     => $data['firmware']     ?? '',
        'subvendor_id' => $data['subvendor_id'] ?? '',
        'topology'     => $data['topology']     ?? 'unknown',
    ], fw_load());

    return [
        'temp'       => $data['temp'] ?? '',
        'status'     => $status,
        'color'      => lsi_status_color($status),
        'label'      => lsi_status_label($status),
        'temp_band'   => $data['temp_band'] ?? '',
        // [dark, light] stops; the consumers paint gradients, never a flat hex.
        'temp_grad'   => lsi_temp_gradient($data['temp_band'] ?? ''),
        'temp_label'  => lsi_band_label($data['temp_band'] ?? ''),
        'cfg_band'       => $data['cfg_band'] ?? '',
        'cfg_band_label' => lsi_band_label($data['cfg_band'] ?? ''),
        'model'      => !empty($data['board_name']) ? $data['board_name'] : ($data['model'] ?? 'Unknown'),
        'chip'       => $data['model']     ?? 'Unknown',
        'firmware'   => $data['firmware']  ?? 'Unknown',
        'fw_old'     => !empty($data['fw_old']),      // SAS2 pre-P20 flag
        'firmware_verdict' => $verdict,
        'bios'       => $data['bios']        ?? '',   // storcli only
        'mode'       => $data['mode']        ?? '',   // IT/IR — storcli, and lsiutil via MPTFW suffix
        'drives'     => $data['drive_count'] ?? '',   // connected drive count (storcli)
        'port_name'  => $portName,
        'port_label' => $portLabel,
        'pcie'       => $pcie,
    ];
}
