<?PHP
/* Drive bay map — where each drive physically sits in the chassis (plan 047).
 *
 * The Drives tab knows every drive's enclosure/slot, but on a direct-attach
 * backplane (a Supermicro SAS846TQ, any passthrough board) that addressing is
 * invented by storcli and has no relationship to which of 24 bays you have to
 * walk over and pull. Nothing on the machine knows the physical layout, so
 * this is the one thing this plugin persists that CANNOT be regenerated from
 * hardware: a person places each drive on a grid once, and that placement is
 * theirs.
 *
 * /boot, not /tmp — same reasoning as phy_baseline.php: a value the user SET
 * must outlive a reboot, and it is written on a click, not on a poll, so there
 * is no flash-wear budget to defend.
 *
 * IDENTITY KEY. "c<ctl>:s<eid>/<slot>" on the storcli backend (enclosure and
 * slot) and "c<ctl>:h<phy>" on lsiutil (PHY index), with "x<expander>" in
 * front of the PHY where one is in the path. All of them are the physical
 * POSITION the drive occupies, which is what a bay assignment actually means —
 * the serial and the SAS address both belong to the drive and change when you
 * replace a dead one in the same bay, and /dev/sdX is not stable across
 * reboots. The s/h letter is load-bearing: slot 3 and PHY 3 are different
 * positions, so a box that switched backend would otherwise silently place a
 * drive in the wrong bay. With the letter, old keys simply stop matching and
 * their drives reappear in the unassigned list, which is visible and fixable.
 * Any future change to this shape needs a migration, not a silent format
 * change (plan 047's maintenance note) — see bay_map_migrate_ports() for the
 * one this rule has already been paid for.
 *
 * NOT the port. Until issue #15 the storcli key was "c<ctl>:p<port>", from
 * Connected Port Number, and that is the controller port rather than the
 * drive's position: every drive behind one path reports the same one. On a
 * SAS9305-16i reporting "0(path0)" for most of its drives, assigning any one
 * of them placed ALL of them, because they shared a key. Slot is unique per
 * controller and is what the backplane label says.
 *
 * Everything above the dispatch is pure over injected paths, so
 * tests/bay_map_test.php exercises all of it with no /boot and no HTTP.
 */

require_once __DIR__ . '/config.php';

const BAY_MAP_PATH = '/boot/config/plugins/hbaviewer/bay_map.json';

/* Stored shape: "c0:s0/14" => {"row": 2, "col": 1}, both 0-indexed. A missing or
   unparseable file reads as "nothing placed yet" — a corrupt file must degrade
   to an empty grid the user can re-populate, never to a fatal on the tab.
   That promise is kept PER ENTRY, not just at the top level: every consumer
   indexes $pos['row'], and on a hand-edited file one bad value ("c0:s1": "x")
   is a TypeError that takes down the whole tab. A malformed entry is dropped
   rather than defaulted to 0,0 — a drive parked in a bay nobody put it in
   reads as a placement, and the tray is where an unplaced drive belongs. */
function bay_map_read(?string $path = null): array {
    $path ??= BAY_MAP_PATH;
    if (!is_file($path)) return [];
    $data = json_decode((string) @file_get_contents($path), true);
    if (!is_array($data)) return [];
    return array_filter($data, fn($pos) => is_array($pos) && isset($pos['row'], $pos['col']));
}

function bay_map_write(array $map, ?string $path = null): void {
    $path ??= BAY_MAP_PATH;
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode($map, JSON_PRETTY_PRINT));
}

/* Assign one drive, or clear it ($row === null). Writes the whole file each
   time: the map is a few dozen short entries and a click is a rare event, so
   read-modify-write beats any incremental format. */
function bay_map_set(string $key, ?int $row, ?int $col, ?string $path = null): void {
    $map = bay_map_read($path);
    if ($row === null) unset($map[$key]);
    else               $map[$key] = ['row' => $row, 'col' => $col];
    bay_map_write($map, $path);
}

/* One generation of undo for the two actions that can destroy the whole map at
   once: Clear, and a grid shrink that prunes everything outside the new size.

   A confirm dialog is NOT a backup. It was the only guard Clear had and it was
   not enough -- the misclick that gets through the dialog is precisely the one
   that needed the safety net, and this cost a real map on the maintainer's box
   the day it shipped. Nothing else on the machine has a copy: the map is built
   by walking to the rack and reading labels, and /boot is FAT with no snapshots
   to fall back on.

   One generation, not a history. The undo exists to catch the misclick you
   noticed immediately; anything older is a job for the flash backup. */
function bay_map_backup(?string $path = null): void {
    $path ??= BAY_MAP_PATH;
    // Nothing to protect if there is no map yet, and an empty backup would
    // overwrite a good one with the state that is about to be regretted.
    if (is_file($path) && bay_map_read($path) !== []) @copy($path, $path . '.bak');
}

function bay_map_has_backup(?string $path = null): bool {
    return is_file(($path ?? BAY_MAP_PATH) . '.bak');
}

/* Restores and CONSUMES the backup: undo is a one-shot, so a second press
   cannot silently re-apply a map the person has since edited on purpose. */
function bay_map_restore(?string $path = null): bool {
    $path ??= BAY_MAP_PATH;
    if (!is_file($path . '.bak')) return false;
    if (!@copy($path . '.bak', $path)) return false;
    @unlink($path . '.bak');
    return true;
}

/* Called whenever the grid shrinks. Drives whose stored position no longer
   fits are returned to the caller AND removed from the store, so they land
   back in the unassigned list instead of sitting at coordinates the grid no
   longer has. Removing them is the point: a silently retained out-of-grid
   position is a drive the person can neither see nor re-place. */
function bay_map_prune_to_dims(int $rows, int $cols, ?string $path = null): array {
    $map = bay_map_read($path);
    $dropped = [];
    foreach ($map as $key => $pos) {
        if ((int) ($pos['row'] ?? 0) >= $rows || (int) ($pos['col'] ?? 0) >= $cols) {
            $dropped[] = $key;
            unset($map[$key]);
        }
    }
    if ($dropped) bay_map_write($map, $path);
    return $dropped;
}

/* Reduce a PASTED map to what may safely be written, and say how much was
   dropped. This is a trust boundary: the text came from a person's clipboard,
   so every key is checked against the shape bay_map_key() produces (without
   which a crafted key writes arbitrary JSON object keys onto the boot flash)
   and every position against the grid actually configured.

   Duplicate positions are dropped rather than kept, because the map renders one
   drive per bay and the loser would be stored but invisible -- present in the
   file, absent from the screen, and impossible to find. The server already
   enforces one-drive-per-bay on assign; a paste must not be the way around it.

   Silent truncation would be worse than useless here, so the count of what was
   dropped is returned and the UI reports it. */
function bay_map_sanitize(array $in, int $rows, int $cols): array {
    $map = $seen = [];
    $skipped = 0;
    foreach ($in as $key => $pos) {
        if (!is_string($key) || !bay_map_key_valid($key)
            || !is_array($pos) || !isset($pos['row'], $pos['col'])
            || !is_numeric($pos['row']) || !is_numeric($pos['col'])) { $skipped++; continue; }
        $row = (int) $pos['row'];
        $col = (int) $pos['col'];
        if ($row < 0 || $col < 0 || $row >= $rows || $col >= $cols) { $skipped++; continue; }
        if (isset($seen["$row:$col"])) { $skipped++; continue; }
        $seen["$row:$col"] = true;
        $map[$key] = ['row' => $row, 'col' => $col];
    }
    return ['map' => $map, 'skipped' => $skipped];
}

/* The identity key for one drive row, or null when this drive carries no
   positional identifier at all (a controller whose payload predates these
   fields, or a drive storcli reported with an empty slot). Null means "cannot
   be placed" and the caller must leave it out of both lists rather than invent
   a key that would collide with a real one. */
function bay_map_key(int $ctl, array $drive): ?string {
    if (isset($drive['phy']) && $drive['phy'] !== '') {
        // An expander-attached drive's PHY number is the expander's, so it is
        // unique only within that expander.
        $exp = (string) ($drive['expander'] ?? '');
        return "c$ctl:" . ($exp !== '' ? "x$exp" : '') . 'h' . (int) $drive['phy'];
    }
    // storcli's slot, already "<eid>/<slot>" (or bare when the drive reports no
    // enclosure) by the time it reaches here -- see parse/storcli_drives.sh.
    // Checked against the key grammar rather than trusted: this string ends up
    // as a JSON object key in a file on the boot flash, and a slot that does
    // not look like a slot is a drive that cannot be placed, not a new format.
    $slot = (string) ($drive['slot'] ?? '');
    if ($slot !== '' && preg_match('#^\d{1,4}(/\d{1,4})?$#', $slot)) return "c$ctl:s$slot";
    return null;
}

/* Whether a string could have come out of bay_map_key(). The POST dispatch
   below is a trust boundary: without this, a crafted key writes arbitrary JSON
   object keys into a file on the boot flash.
   `p` is still accepted so that a pre-#15 map survives being read back and
   migrated; nothing emits it any more. */
function bay_map_key_valid(string $key): bool {
    return (bool) preg_match('#^c\d{1,3}:((x[0-9A-F]{1,16})?[ph]\d{1,4}|s\d{1,4}(/\d{1,4})?)$#', $key);
}

/* One-time rewrite of pre-#15 "c<ctl>:p<port>" keys onto the slot keys that
   replaced them, driven by the drive payload currently on screen.

   A port maps onto a slot only where exactly ONE drive on that controller
   reports it. Where several do, that is the #15 collision itself: the stored
   position was never about a single drive, so there is nothing to carry over
   and the entry is left where it is — inert, matching no drive, and displaced
   the moment somebody assigns that cell. Deleting it would be the same guess
   in the other direction, and this file's rule is that a key which stops
   matching sends its drive back to the tray, visibly.

   Nothing is dropped for an absent drive, either: a controller that failed to
   enumerate, or a disk pulled for the afternoon, must not cost the placement
   somebody walked to the rack to record.

   Returns the number of keys rewritten (0 = nothing to do, and no write). */
function bay_map_migrate_ports(array $drivesData, ?string $path = null): int {
    $map = bay_map_read($path);
    if ($map === []) return 0;
    // Cheap pre-check: on every already-migrated box this is the whole cost.
    $old = array_filter(array_keys($map), fn($k) => (bool) preg_match('/^c\d{1,3}:p\d{1,4}$/', (string) $k));
    if ($old === []) return 0;

    $slotByPort = [];   // "c0:p0" => slot key, or false once a second drive claims it
    foreach ($drivesData['controllers'] ?? [$drivesData] as $i => $ctl) {
        foreach ($ctl['drives'] ?? [] as $d) {
            if (($d['port'] ?? '') === '') continue;
            $pk  = "c" . (int) $i . ":p" . (int) $d['port'];
            $new = bay_map_key((int) $i, $d);
            $slotByPort[$pk] = ($new === null || isset($slotByPort[$pk])) ? false : $new;
        }
    }

    $moved = 0;
    foreach ($old as $pk) {
        $new = $slotByPort[$pk] ?? null;
        // Already occupied means the person has placed that drive since, under
        // its new key. Theirs wins; the stale port entry is not a second vote.
        if (!is_string($new) || isset($map[$new])) continue;
        $map[$new] = $map[$pk];
        unset($map[$pk]);
        $moved++;
    }
    if ($moved) bay_map_write($map, $path);
    return $moved;
}

/* Grid dimensions. Read is a plain config read; the write goes through
   lsi_config_update(), which merges over the current config — a plain
   lsi_config_write() of just the two bay keys would reset HBA_PORT,
   ALERT_THRESHOLD and every SHOW_* toggle every time someone nudged the grid
   size. Clamping is lsi_config_write()'s job, the same clamp the Settings page
   goes through. */
function bay_map_dims(?string $path = null): array {
    $cfg = lsi_config_read($path);
    return ['rows' => (int) $cfg['BAY_ROWS'], 'cols' => (int) $cfg['BAY_COLS']];
}

function bay_map_dims_set(int $rows, int $cols, ?string $path = null): void {
    lsi_config_update(['BAY_ROWS' => $rows, 'BAY_COLS' => $cols], $path);
}

/* The lock. Enforced HERE, in the dispatch below, not only by greying out the
   UI: a lock that just hides buttons still lets a stale browser tab, a double
   submit, or anything else POST an edit through — and the thing being
   protected is a map somebody built by walking to the rack. Same merge-first
   write as the dimensions, for the same reason. */
function bay_map_locked(?string $path = null): bool {
    return (int) lsi_config_read($path)['BAY_LOCK'] === 1;
}

function bay_map_lock_set(bool $locked, ?string $path = null): void {
    lsi_config_update(['BAY_LOCK' => $locked ? 1 : 0], $path);
}

/* ── POST dispatch (served only; skipped under the CLI test runner) ──────────
   Requiring this file is read-only: every branch below needs a POST with an
   `action`, so ajax_info.php can pull the store in without risk of mutating it.
   Unraid's own layer checks csrf_token on POST; the client sends it exactly as
   phy_baseline.php's Reset Baseline button does. */
if (PHP_SAPI === 'cli') return;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['action'])) return;

header('Content-Type: application/json; charset=utf-8');
$action = (string) $_POST['action'];

// Setting the lock is the one thing a locked map still accepts.
if ($action === 'lock') {
    bay_map_lock_set(($_POST['locked'] ?? '') === '1');
    echo json_encode(['ok' => true, 'locked' => bay_map_locked()]);
    exit;
}

/* Every other action mutates the map, so the lock stops it at the server. The
   UI disables these too, but that is convenience — this is the guarantee. */
if (bay_map_locked()) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'The bay map is locked. Unlock it to make changes.']);
    exit;
}

/* Empty the whole map in one write, rather than the client posting an unassign
   per drive: a per-drive loop half-succeeds when the browser goes away
   mid-sweep, and half a cleared map is the state nobody asked for.
   No key to validate — there is no input here beyond the action itself, which
   is also why the "are you sure" lives in the client and can only live there.
   The lock above is the server-side guard, and it is the real one. */
if ($action === 'clear') {
    bay_map_backup();
    bay_map_write([]);
    echo json_encode(['ok' => true]);
    exit;
}

/* Restore from a pasted map. Backed up first like Clear is, so a paste of the
   wrong text is itself undoable — otherwise restoring a backup would be a new
   way to lose the map you already had. */
if ($action === 'import') {
    $in = json_decode((string) ($_POST['map'] ?? ''), true);
    if (!is_array($in)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'That is not a saved map.']);
        exit;
    }
    $d = bay_map_dims();
    $r = bay_map_sanitize($in, $d['rows'], $d['cols']);
    bay_map_backup();
    bay_map_write($r['map']);
    echo json_encode(['ok' => true, 'placed' => count($r['map']), 'skipped' => $r['skipped']]);
    exit;
}

if ($action === 'restore') {
    if (!bay_map_restore()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Nothing to undo.']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'dims') {
    // Clamp BEFORE pruning: pruning to an unclamped 9999x9999 would keep every
    // assignment and then the grid would render at the clamped size with drives
    // stranded outside it.
    $rows = lsi_clamp('BAY_ROWS', $_POST['rows'] ?? 0);
    $cols = lsi_clamp('BAY_COLS', $_POST['cols'] ?? 0);
    // Backed up for the same reason Clear is: shrinking the grid can strand
    // every drive outside the new size, and that is the other way to lose a
    // whole map in one action.
    bay_map_backup();
    bay_map_dims_set($rows, $cols);
    echo json_encode(['ok' => true, 'rows' => $rows, 'cols' => $cols,
                      'dropped' => bay_map_prune_to_dims($rows, $cols)]);
    exit;
}

if ($action === 'assign' || $action === 'unassign') {
    $key = (string) ($_POST['key'] ?? '');
    if (!bay_map_key_valid($key)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid drive key.']);
        exit;
    }
    if ($action === 'unassign') {
        bay_map_set($key, null, null);
        echo json_encode(['ok' => true]);
        exit;
    }
    // A position outside the current grid is rejected rather than clamped: a
    // click that lands somewhere the person did not aim at is worse than one
    // that does nothing.
    $d   = bay_map_dims();
    $row = (int) ($_POST['row'] ?? -1);
    $col = (int) ($_POST['col'] ?? -1);
    if ($row < 0 || $col < 0 || $row >= $d['rows'] || $col >= $d['cols']) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Position outside the grid.']);
        exit;
    }
    // One drive per bay: whatever was there is displaced back to the
    // unassigned list, rather than two drives sharing a cell.
    foreach (bay_map_read() as $k => $pos) {
        if ($k !== $key && (int) $pos['row'] === $row && (int) $pos['col'] === $col) {
            bay_map_set($k, null, null);
        }
    }
    bay_map_set($key, $row, $col);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
exit;
