<?PHP
/* Single home for the lsiutil KEY=value config: schema, defaults, read, write.
   Any PHP page that needs config require_once's this and calls lsi_config_read().
   The writer clamps every value to schema, so the file on disk is never garbage.
   Shell reads the same file via config.sh (PORT/ALERT only). */

const LSI_CFG = '/boot/config/plugins/hbaviewer/hbaviewer.cfg';

// key => [default, min, max]   (SHOW_* are booleans expressed as 0/1)
const LSI_SCHEMA = [
    'HBA_PORT'        => [1,  1, 8],
    // Not a temperature any more: the FIRST BAND at which the badge complains,
    // stored as that band's floor (66 elevated / 76 warning / 86 alert / 96
    // critical). Kept as an int with the old key and clamp so existing configs
    // need no migration — any legacy value maps to whichever band contains it.
    'ALERT_THRESHOLD' => [76, 1, 150],
    'SHOW_PCIE'       => [1,  0, 1],
    'SHOW_PHY'        => [1,  0, 1],
    'SHOW_DRIVES'     => [1,  0, 1],
    'SHOW_EVENTS'     => [1,  0, 1],
    'SHOW_PERF'       => [1,  0, 1],   // Performance (real-time graphs) tab
    'ENABLE_FLASH'    => [0,  0, 1],   // advanced: unlocks the Firmware/BIOS tab
    'ENABLE_NOTIFY'   => [0,  0, 1],   // opt-in: cron notifies on health status changes
];

function lsi_clamp(string $key, $val): int {
    [, $min, $max] = LSI_SCHEMA[$key];
    return max($min, min($max, (int)$val));
}

/* Defaults overlaid with the cfg file, every value clamped to schema (typed int).
   $path defaults to the live cfg; tests pass a temp file. */
function lsi_config_read(?string $path = null): array {
    $path ??= LSI_CFG;
    $cfg = [];
    foreach (LSI_SCHEMA as $k => $spec) $cfg[$k] = $spec[0];

    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos($line, '=') !== false) {
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                if (isset(LSI_SCHEMA[$k])) $cfg[$k] = lsi_clamp($k, trim($v));
            }
        }
    }
    return $cfg;
}

/* Persist a raw (possibly untrusted) array. Missing keys fall back to default;
   every value is clamped before it touches disk. */
function lsi_config_write(array $raw, ?string $path = null): void {
    $path ??= LSI_CFG;
    $lines = [];
    foreach (LSI_SCHEMA as $k => $spec) {
        $lines[] = "$k=" . lsi_clamp($k, $raw[$k] ?? $spec[0]);
    }
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, implode("\n", $lines) . "\n");
}
