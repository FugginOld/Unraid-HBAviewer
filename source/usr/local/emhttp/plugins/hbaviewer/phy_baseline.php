<?PHP
/* Per-PHY error baseline — "has anything happened SINCE I fixed it?" (plan 022).
 *
 * The PHY tab's four counters are cumulative from the last driver load, so a
 * cable that logged 40,000 invalid dwords two months ago and one that logged
 * its first 40,000 last night look identical. A baseline is one user-pressed
 * snapshot; everything after it is rendered as a delta and a rate.
 *
 * /boot, not /tmp — unlike health.php's ring (a rolling ~4h window that is
 * only meaningful within one boot), a baseline the user SET must outlive a
 * reboot, and it is written once per button press rather than once per poll,
 * so there is no flash-wear budget to defend.
 *
 * That persistence is exactly what makes the reset trap bite: the stored
 * baseline outlives the counters it measured, so after a driver reload
 * `current - baseline` goes negative. phy_baseline_delta() detects that with
 * the same two signals health_ingest() uses (uptime down = reboot; any counter
 * down = driver reload) and reports it as `reset` — the UI then asks the user
 * to re-baseline rather than silently rebasing a reference point they chose.
 *
 * Everything above the dispatch is pure over injected inputs (path, now,
 * uptime), so tests/phy_baseline_test.php exercises all of it with no /boot,
 * no HTTP and no hardware.
 */

const PHY_BASELINE_PATH = '/boot/config/plugins/hbaviewer/phy_baseline.json';

// The four cumulative counters both backends converge on (parse/phy.sh and
// parse/storcli_phy.sh emit the same names).
const PHY_COUNTERS = ['inv', 'disp', 'sync', 'reset'];

/* Stored shape: "ctrl:phy" => {inv, disp, sync, reset, ts, up}. Missing or
   unparseable file reads as "no baselines" — a corrupt file must degrade to
   today's raw-counter display, never to a fatal on the PHY tab. */
function phy_baseline_read(?string $path = null): array {
    $path ??= PHY_BASELINE_PATH;
    if (!is_file($path)) return [];
    $data = json_decode((string) @file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function phy_baseline_write(array $baseline, ?string $path = null): void {
    $path ??= PHY_BASELINE_PATH;
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0755, true);
    @file_put_contents($path, json_encode($baseline, JSON_PRETTY_PRINT));
}

/* Whole seconds of host uptime. 0 when unreadable (non-Linux, container), which
   phy_baseline_delta() treats as "cannot compare" — it then relies on the
   counter-decrease signal alone rather than guessing a reboot happened. */
function phy_baseline_uptime(string $proc = '/proc/uptime'): int {
    if (!is_file($proc)) return 0;
    return (int) (float) strtok((string) @file_get_contents($proc), " \n");
}

/* Capture one controller's PHYs at "now". Replaces that controller's entries
   and leaves every other controller's alone, so the per-controller button
   never disturbs a card the user did not press it on. */
function phy_baseline_set(int $ctrl, array $phys, ?string $path = null, ?int $now = null, ?int $uptime = null): void {
    $b   = phy_baseline_read($path);
    $now ??= time();
    $uptime ??= phy_baseline_uptime();
    foreach (array_keys(phy_baseline_for($b, $ctrl)) as $idx) unset($b["$ctrl:$idx"]);
    foreach ($phys as $p) {
        if (!isset($p['phy'])) continue;
        $e = ['ts' => $now, 'up' => $uptime];
        foreach (PHY_COUNTERS as $k) $e[$k] = (int) ($p[$k] ?? 0);
        $b["$ctrl:" . (int) $p['phy']] = $e;
    }
    phy_baseline_write($b, $path);
}

/* One controller's entries, keyed by PHY index. */
function phy_baseline_for(array $baselines, int $ctrl): array {
    $out = [];
    foreach ($baselines as $key => $e) {
        $parts = explode(':', (string) $key, 2);
        if (count($parts) === 2 && (string) (int) $parts[0] === $parts[0]
            && (int) $parts[0] === $ctrl && is_array($e)) {
            $out[(int) $parts[1]] = $e;
        }
    }
    return $out;
}

/* When this controller was baselined, or null if it was not. */
function phy_baseline_ts(array $baselines, int $ctrl): ?int {
    foreach (phy_baseline_for($baselines, $ctrl) as $e) {
        if (isset($e['ts'])) return (int) $e['ts'];
    }
    return null;
}

/* One PHY's delta since baseline.
     null                          -> no baseline; caller renders raw only
     ['reset' => true]             -> counters restarted; baseline is unusable
     ['reset' => false, 'ts', 'delta' => [...], 'rate' => [...]]
   The rate divisor is floored at one minute so a baseline set seconds ago
   cannot produce an absurd per-hour figure (or a division by zero). */
function phy_baseline_delta(?array $base, array $phy, int $now, int $uptime = 0): ?array {
    if (!is_array($base) || !isset($base['ts'])) return null;

    // Signal 1 — host uptime went DOWN since the snapshot: the box rebooted.
    if ($uptime > 0 && (int) ($base['up'] ?? 0) > 0 && $uptime < (int) $base['up']) {
        return ['reset' => true];
    }

    $hours = max(($now - (int) $base['ts']) / 3600.0, 1 / 60.0);
    $out   = ['reset' => false, 'ts' => (int) $base['ts'], 'delta' => [], 'rate' => []];
    foreach (PHY_COUNTERS as $k) {
        $d = (int) ($phy[$k] ?? 0) - (int) ($base[$k] ?? 0);
        // Signal 2 — a counter went DOWN: the driver reloaded without a reboot
        // (modprobe -r mpt3sas), which uptime alone cannot see. A negative
        // delta is never rendered; the UI asks for a fresh baseline instead.
        if ($d < 0) return ['reset' => true];
        $out['delta'][$k] = $d;
        $out['rate'][$k]  = $d / $hours;
    }
    return $out;
}

/* ── HTTP dispatch (POST only) ───────────────────────────────────────────────
   This file is also require_once'd by ajax_info.php for the READ path, so the
   dispatch must fire only for a real reset request — hence the guard on the
   POST field rather than flash.php's bare SAPI check.
   CSRF is enforced by Unraid's platform layer, which hash_equals-checks every
   POST before our code runs and then unsets the field; a second check here
   would see null and deny everything (plan 009, REJECTED). Do not add one. */
if (PHP_SAPI === 'cli') return;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['reset_baseline'])) return;

header('Content-Type: text/plain; charset=utf-8');
$ctl = (string) $_POST['reset_baseline'];
if (!preg_match('/^\d+$/', $ctl)) { http_response_code(400); echo 'Invalid controller.'; exit; }

// Read the hardware fresh rather than trusting counters the browser rendered:
// a baseline is the user's reference point, and a stale or tampered one is
// worse than no baseline at all. User-triggered and rare, so the storcli scan
// this costs is acceptable here (it would not be on a poll).
$raw  = shell_exec('bash ' . escapeshellarg('/usr/local/emhttp/plugins/hbaviewer/scripts/get_phy_health.sh') . ' 2>/dev/null');
$data = json_decode((string) $raw, true);
$ctls = is_array($data) ? ($data['controllers'] ?? [$data]) : [];
$phys = $ctls[(int) $ctl]['phys'] ?? [];
if (!$phys) { http_response_code(502); echo 'No PHY data for controller /c' . (int) $ctl . '.'; exit; }

phy_baseline_set((int) $ctl, $phys);
echo 'ok';
exit;
