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
    // String setting (plan 029) — chip/label id of the intake sensor, '' = off.
    // 4th element marks it 'str'; every other row is implicitly 'int' (see
    // lsi_schema_type). min/max are unused for 'str' and kept as 0 filler.
    'INLET_SENSOR'    => ['', 0, 0, 'str'],
];

function lsi_schema_type(string $key): string {
    return LSI_SCHEMA[$key][3] ?? 'int';
}

function lsi_clamp(string $key, $val): int {
    [, $min, $max] = LSI_SCHEMA[$key];
    return max($min, min($max, (int)$val));
}

/* chip/label, both from a fixed charset. Anything else -> '' (off). This is a
   whitelist, not an escape: it is the primary defence, and lsi_cfg_quote is
   the backstop. */
function lsi_sanitise_sensor(string $v): string {
    // \z, not $ — PCRE's $ also matches just before a trailing newline, which
    // would let "abc/def\n" through and write a multi-line cfg value.
    return preg_match('~^[A-Za-z0-9._-]{1,64}/[A-Za-z0-9._ -]{1,64}\z~', $v) ? $v : '';
}

/* Values are sourced as bash by scripts/config.sh, so a string value must be
   emitted single-quoted with embedded quotes escaped. Without this, a value
   containing a space, `$`, backtick or `;` becomes shell code running as root
   in every composer. */
function lsi_cfg_quote(string $v): string {
    return "'" . str_replace("'", "'\\''", $v) . "'";
}

/* Reverse of lsi_cfg_quote, for the read side — a written-then-read value must
   be byte-identical to what went in. */
function lsi_cfg_unquote(string $v): string {
    if (strlen($v) >= 2 && $v[0] === "'" && $v[strlen($v) - 1] === "'") {
        $v = str_replace("'\\''", "'", substr($v, 1, -1));
    }
    return $v;
}

/* Defaults overlaid with the cfg file, every value clamped to schema (typed int,
   or sanitised string). $path defaults to the live cfg; tests pass a temp file. */
function lsi_config_read(?string $path = null): array {
    $path ??= LSI_CFG;
    $cfg = [];
    foreach (LSI_SCHEMA as $k => $spec) $cfg[$k] = $spec[0];

    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos($line, '=') !== false) {
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                if (!isset(LSI_SCHEMA[$k])) continue;
                $cfg[$k] = lsi_schema_type($k) === 'str'
                    ? lsi_sanitise_sensor(lsi_cfg_unquote(trim($v)))
                    : lsi_clamp($k, trim($v));
            }
        }
    }
    return $cfg;
}

/* Persist a raw (possibly untrusted) array. Missing keys fall back to default;
   every value is clamped/sanitised (and, for strings, shell-quoted) before it
   touches disk. */
function lsi_config_write(array $raw, ?string $path = null): void {
    $path ??= LSI_CFG;
    $lines = [];
    foreach (LSI_SCHEMA as $k => $spec) {
        if (lsi_schema_type($k) === 'str') {
            $lines[] = "$k=" . lsi_cfg_quote(lsi_sanitise_sensor((string)($raw[$k] ?? $spec[0])));
        } else {
            $lines[] = "$k=" . lsi_clamp($k, $raw[$k] ?? $spec[0]);
        }
    }
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, implode("\n", $lines) . "\n");
}
