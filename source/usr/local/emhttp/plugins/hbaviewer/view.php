<?PHP
/* Shared interpretation of the get_hba_info.sh JSON for display.
   One home for status->color/label, the model/chip/firmware fallbacks, and the
   PCIe-row assembly. The monitor page, the dashboard tile, and the AJAX refresh
   endpoint all consume this — each keeps its own markup/CSS, none re-derives.
   Values are returned RAW; each consumer escapes for its own medium. */

function lsi_status_color(string $s): string {
    return match ($s) { 'alert' => '#e74c3c', 'warn' => '#f39c12', default => '#2ecc71' };
}
function lsi_status_label(string $s): string {
    return match ($s) { 'alert' => 'ALERT', 'warn' => 'WARNING', default => 'NORMAL' };
}

/* Temperature band -> colour. SEPARATE from lsi_status_color on purpose: the
   thermometer shows heat, the badge shows the whole-controller rollup (which also
   reflects drive and PHY problems). Conflating them is what made issue #8 read as
   a false temperature warning. Hexes are contrast-measured against the plugin's
   own card surfaces (#232323 / #1c1c1c / #2a2a2a); all clear 3:1.
   'critical' is a FILL behind white text, not a foreground — #922b21 measures
   1.94:1 as a stroke on a dark card and is unreadable. Do not "promote" it. */
function lsi_temp_color(string $band): string {
    return match ($band) {
        'critical' => '#922b21',
        'alert'    => '#e74c3c',
        'warning'  => '#e67e22',
        'elevated' => '#f1c40f',
        default    => '#2ecc71',
    };
}
/* Where a band must be drawn as a stroke or glow rather than a fill, critical
   needs a lighter red to stay legible (4.93:1 vs 1.94:1). */
function lsi_temp_stroke(string $band): string {
    return $band === 'critical' ? '#ff5252' : lsi_temp_color($band);
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

/* Timestamp in the user's configured format. Unraid stores the display
   preference in dynamix's config and also exposes $display to page scripts, so
   try the in-memory global first and fall back to reading the file. date()
   already renders in the system timezone — only the 12/24-hour choice needs
   resolving here, so a missing config degrades to the previous 24-hour output
   rather than guessing.
   ponytail: Unraid writes strftime-style formats (e.g. "%I:%M %p"), translated by
   the helper above; a plain date() format is still accepted for the case where
   $display carries one. Anything else drops back to 24-hour. */
function lsi_time(?int $when = null): string {
    $when ??= time();
    $fmt = '';
    if (isset($GLOBALS['display']['time']) && is_string($GLOBALS['display']['time'])) {
        $fmt = trim($GLOBALS['display']['time']);
    }
    if ($fmt === '') {
        $cfg = @parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true);
        if (is_array($cfg) && isset($cfg['display']['time']) && is_string($cfg['display']['time'])) {
            $fmt = trim($cfg['display']['time']);
        }
    }
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

    return [
        'temp'       => $data['temp'] ?? '',
        'status'     => $status,
        'color'      => lsi_status_color($status),
        'label'      => lsi_status_label($status),
        'temp_band'   => $data['temp_band'] ?? '',
        'temp_color'  => lsi_temp_color($data['temp_band'] ?? ''),
        'temp_stroke' => lsi_temp_stroke($data['temp_band'] ?? ''),
        'temp_label'  => lsi_band_label($data['temp_band'] ?? ''),
        'cfg_band'       => $data['cfg_band'] ?? '',
        'cfg_band_label' => lsi_band_label($data['cfg_band'] ?? ''),
        'model'      => !empty($data['board_name']) ? $data['board_name'] : ($data['model'] ?? 'Unknown'),
        'chip'       => $data['model']     ?? 'Unknown',
        'firmware'   => $data['firmware']  ?? 'Unknown',
        'fw_old'     => !empty($data['fw_old']),      // SAS2 pre-P20 flag
        'bios'       => $data['bios']        ?? '',   // storcli only
        'mode'       => $data['mode']        ?? '',   // IT/IR (storcli)
        'drives'     => $data['drive_count'] ?? '',   // connected drive count (storcli)
        'port_name'  => $portName,
        'port_label' => $portLabel,
        'pcie'       => $pcie,
    ];
}
