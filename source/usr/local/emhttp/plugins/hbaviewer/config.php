<?PHP
/* Single home for the lsiutil KEY=value config: schema, defaults, read, write.
   Any PHP page that needs config require_once's this and calls lsi_config_read().
   The writer clamps every value to schema, so the file on disk is never garbage.
   Shell reads the same file via config.sh (PORT/ALERT only). */

const LSI_CFG = '/boot/config/plugins/hbaviewer/hbaviewer.cfg';

/* ── Firmware flashing: maintainer lock ───────────────────────────────────────
 * Flashing is disabled outright, over and above the user's own ENABLE_FLASH
 * toggle, unless the unlock file below exists. No user's saved setting is
 * touched while the lock is on.
 *
 * ENABLE_FLASH is the USER saying "I might flash a card". This is the
 * MAINTAINER saying "not right now" — two different questions, so two
 * different switches rather than forcing the user's answer to a value they
 * did not choose and would silently lose on their next Save.
 *
 * Honoured at four places, and the order matters: flash.php refuses every
 * action with a 403 (the only one that is actually load-bearing — a UI that
 * merely looks disabled is not disabled), flash_view.php renders the reason
 * instead of the wizard, and settings.php greys the card and holds the stored
 * value. A page offering controls the endpoint would refuse is worse than one
 * that says plainly why there are none.
 *
 * Locked 2026-08-09: the flash path needs more testing on real hardware before
 * it is offered again. It is the one surface in this plugin that writes to a
 * controller, and a wrong image bricks it permanently — so the default while
 * in doubt is off.
 *
 * WHY A FILE AND NOT A CONSTANT. The lock has to be off on the box it is being
 * tested on and on for everyone else. Expressing that as a source difference
 * meant main and dev disagreeing about this line forever, and every merge
 * between them was a chance to ship the wrong value — the failure mode being
 * to silently hand a half-tested flasher to every user. A file moves the
 * difference off the branch and onto the machine, so the two branches carry
 * identical code and the default is locked no matter which one you install.
 *
 * It is not a security boundary and does not pretend to be: anyone who can
 * create this file already has root on the box and could run sas3flash by
 * hand. It guards against reaching the flasher by clicking, which is the
 * accident actually worth preventing.
 *
 * To test on your own machine:  touch /boot/config/plugins/hbaviewer/.flash-unlock
 * To lock it again:             rm   /boot/config/plugins/hbaviewer/.flash-unlock */
const LSI_FLASH_UNLOCK = '/boot/config/plugins/hbaviewer/.flash-unlock';
// define(), not const: a const initialiser cannot call a function. Consumers
// see the same name and cannot tell the difference.
define('LSI_FLASH_LOCKED', !file_exists(LSI_FLASH_UNLOCK));
const LSI_FLASH_LOCK_NOTE = 'Firmware/BIOS flashing is temporarily disabled while it undergoes further testing. '
    . 'This is the one part of the plugin that writes to your controller, and a wrong or mismatched image '
    . 'bricks it permanently — so it stays off until it has been verified on more hardware. '
    . 'Nothing else is affected, and your setting is remembered for when it returns.';

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
    // Drive bay map grid (plan 047). Deliberately NOT on the Settings page:
    // these are edited in the map view itself, where you can see the grid
    // change. bay_map.php owns the setter; this is only where they persist.
    'BAY_ROWS'        => [6,  1, 12],
    'BAY_COLS'        => [4,  1, 12],
    // Locks the finished map against edits. Persisted, because the accident it
    // prevents is a stray click on a map somebody spent time building.
    'BAY_LOCK'        => [0,  0, 1],
    // How long a drive-locate blink runs before stopping itself, in seconds.
    // Bounded by default rather than by the user remembering: the technique
    // keeps the drive awake for as long as it runs (plan 048).
    'LOCATE_MAX_SECS'  => [300, 30, 1800],
    // Drive temperature the bay map calls hot, in °C. NOT ALERT_THRESHOLD:
    // that one is the HBA controller chip's band floor, and a chip at 76°C is
    // ordinary while a spinning disk at 76°C is an emergency.
    'BAY_WARN_TEMP'   => [45, 20, 80],
    /* What Host Link should EXPECT of the PCIe link, when the slot cannot be
       asked. 0 = detect it, which is what almost everyone wants: the slot's own
       ceiling is read from the upstream bridge and a card in a narrower slot is
       judged against the slot, not against itself (plan 056, issues #13/#14).
       These exist for platforms whose bridge publishes nothing. Setting one is
       a CORRECTION, not a mute: a link below what you declare still warns,
       which is what #13 explicitly asked for. */
    'PCIE_EXPECT_WIDTH' => [0, 0, 32],   // lanes; 0 = detect from the slot
    'PCIE_EXPECT_GEN'   => [0, 0, 6],    // PCIe generation; 0 = detect
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

/* Change some keys and keep the rest. Every partial write must come through
   here: lsi_config_write() writes EVERY schema key and defaults anything the
   array omits, so a caller that names only the keys it cares about silently
   resets the others. That is not hypothetical — the Settings page named its
   nine form keys and so wiped the bay map's grid size and lock on every save.
   A merge open-coded at each call site is a merge the next call site forgets. */
function lsi_config_update(array $changes, ?string $path = null): void {
    lsi_config_write($changes + lsi_config_read($path), $path);
}
