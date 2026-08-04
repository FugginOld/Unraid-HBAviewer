<?PHP
/* Inlet temperature sensor (plan 029): discovery for the settings picker, and
   resolve-by-chip/label for display. Both shell out to the scripts/parse/
   hwmon_*.sh filters rather than re-walking sysfs in PHP, so there is exactly
   one place that knows hwmon's on-disk shape.
   $sysRoot is injectable (tests point it at tests/fixtures/hwmon); null means
   "let the shell script use the real /sys/class/hwmon default". */

require_once __DIR__ . '/config.php';

/* Every candidate sensor with its live reading, for the settings picker —
   including the junk (unconnected headers reading -61C, 0C etc.): the user
   needs to SEE that before choosing, not have it silently filtered. */
function lsi_inlet_candidates(?string $sysRoot = null): array {
    $script = escapeshellarg(__DIR__ . '/scripts/parse/hwmon_list.sh');
    $cmd    = "bash $script" . ($sysRoot !== null ? ' ' . escapeshellarg($sysRoot) : '') . ' 2>/dev/null';
    $out    = (string) shell_exec($cmd);

    $rows = [];
    foreach (explode("\n", trim($out)) as $line) {
        if ($line === '') continue;
        $parts = explode("\t", $line);
        if (count($parts) < 4 || !is_numeric($parts[3])) continue;
        [$chip, $label, , $mdeg] = $parts;
        $rows[] = ['chip' => $chip, 'label' => $label, 'sensor' => "$chip/$label", 'reading' => intdiv((int) $mdeg, 1000)];
    }
    return $rows;
}

/* chip/label -> current reading in whole degrees C, or null when the stored
   sensor cannot be found (chip vanished, label vanished, or nothing configured).
   NEVER falls back to a different sensor — see hwmon_resolve.sh's header. */
function lsi_inlet_reading(string $sensor, ?string $sysRoot = null): ?int {
    if ($sensor === '' || strpos($sensor, '/') === false) return null;
    [$chip, $label] = explode('/', $sensor, 2);

    $script = escapeshellarg(__DIR__ . '/scripts/parse/hwmon_resolve.sh');
    $cmd    = "bash $script " . escapeshellarg($chip) . ' ' . escapeshellarg($label)
            . ($sysRoot !== null ? ' ' . escapeshellarg($sysRoot) : '') . ' 2>/dev/null';
    $out    = trim((string) shell_exec($cmd));
    if ($out === '' || !is_numeric($out)) return null;
    return intdiv((int) $out, 1000);
}

/* controller minus inlet, integer, no clamping — a negative Delta means the
   sensor is misidentified, and hiding that hides the mistake (plan 029). */
function lsi_inlet_delta(int $controllerTemp, int $inletTemp): int {
    return $controllerTemp - $inletTemp;
}
