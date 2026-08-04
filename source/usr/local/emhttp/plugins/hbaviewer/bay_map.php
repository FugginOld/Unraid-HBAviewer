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
 * IDENTITY KEY. "c<ctl>:p<port>" on the storcli backend (Connected Port
 * Number) and "c<ctl>:h<phy>" on lsiutil (PHY index). Both are the physical
 * wire the drive is on, which is what a bay assignment actually means — the
 * serial changes when you replace a dead drive in the same bay, and /dev/sdX
 * is not stable across reboots. The p/h letter is load-bearing: port 3 and PHY
 * 3 are different positions, so a box that switched backend would otherwise
 * silently place a drive in the wrong bay. With the letter, old keys simply
 * stop matching and their drives reappear in the unassigned list, which is
 * visible and fixable. Any future change to this shape needs a migration, not
 * a silent format change (plan 047's maintenance note).
 *
 * Everything above the dispatch is pure over injected paths, so
 * tests/bay_map_test.php exercises all of it with no /boot and no HTTP.
 */

require_once __DIR__ . '/config.php';

const BAY_MAP_PATH = '/boot/config/plugins/hbaviewer/bay_map.json';

/* Stored shape: "c0:p14" => {"row": 2, "col": 1}, both 0-indexed. A missing or
   unparseable file reads as "nothing placed yet" — a corrupt file must degrade
   to an empty grid the user can re-populate, never to a fatal on the tab. */
function bay_map_read(?string $path = null): array {
    $path ??= BAY_MAP_PATH;
    if (!is_file($path)) return [];
    $data = json_decode((string) @file_get_contents($path), true);
    return is_array($data) ? $data : [];
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

/* The identity key for one drive row, or null when this drive carries neither
   identifier (a controller whose payload predates either field, or a drive
   storcli reported with an empty Connected Port Number). Null means "cannot be
   placed" and the caller must leave it out of both lists rather than invent a
   key that would collide with a real one. */
function bay_map_key(int $ctl, array $drive): ?string {
    if (isset($drive['phy']) && $drive['phy'] !== '')   return "c$ctl:h" . (int) $drive['phy'];
    if (isset($drive['port']) && $drive['port'] !== '') return "c$ctl:p" . (int) $drive['port'];
    return null;
}

/* Whether a string could have come out of bay_map_key(). The POST dispatch
   below is a trust boundary: without this, a crafted key writes arbitrary JSON
   object keys into a file on the boot flash. */
function bay_map_key_valid(string $key): bool {
    return (bool) preg_match('/^c\d{1,3}:[ph]\d{1,4}$/', $key);
}

/* Grid dimensions. Read is a plain config read; the write MERGES over the
   current config, because lsi_config_write() writes every schema key and falls
   back to the default for anything the array omits — passing only the two bay
   keys would reset HBA_PORT, ALERT_THRESHOLD and every SHOW_* toggle every
   time someone nudged the grid size. Clamping is lsi_config_write()'s job,
   the same clamp the Settings page goes through. */
function bay_map_dims(?string $path = null): array {
    $cfg = lsi_config_read($path);
    return ['rows' => (int) $cfg['BAY_ROWS'], 'cols' => (int) $cfg['BAY_COLS']];
}

function bay_map_dims_set(int $rows, int $cols, ?string $path = null): void {
    lsi_config_write(['BAY_ROWS' => $rows, 'BAY_COLS' => $cols] + lsi_config_read($path), $path);
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

if ($action === 'dims') {
    // Clamp BEFORE pruning: pruning to an unclamped 9999x9999 would keep every
    // assignment and then the grid would render at the clamped size with drives
    // stranded outside it.
    $rows = lsi_clamp('BAY_ROWS', $_POST['rows'] ?? 0);
    $cols = lsi_clamp('BAY_COLS', $_POST['cols'] ?? 0);
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
