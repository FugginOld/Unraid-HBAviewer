<?PHP
/* Runnable checks for phy_baseline.php: the store round-trip, its degenerate
   reads, the two counter-reset signals, and the delta/rate arithmetic — the
   logic behind "has anything happened since I fixed it?" (plan 022).
   No /boot, no HTTP, no hardware: every function takes an injected path, now
   and uptime.
     php tests/phy_baseline_test.php  ->  "phy_baseline: all pass" (exit 0) */

/* phy_baseline.php carries an HTTP dispatch at the bottom. It must return
   early under CLI — if it ever stops doing so, requiring it would run the
   dispatch and this file would report a clean pass having tested nothing. */
$completed = false;
register_shutdown_function(function () use (&$completed) {
    if (!$completed) {
        fwrite(STDERR, "phy_baseline: ABORTED before assertions ran — phy_baseline.php did not return early under CLI\n");
        exit(1);
    }
});

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/phy_baseline.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

$dir  = sys_get_temp_dir() . '/hbav_baseline_' . getmypid();
$path = $dir . '/phy_baseline.json';

/* ── store: missing, round-trip, corrupt ─────────────────────────────────── */
check('missing file reads empty', phy_baseline_read($path) === []);

$phys = [
    ['phy' => 0, 'link' => 'up', 'inv' => 100, 'disp' => 5,  'sync' => 1, 'reset' => 0],
    ['phy' => 1, 'link' => 'up', 'inv' => 200, 'disp' => 10, 'sync' => 0, 'reset' => 2],
];
phy_baseline_set(0, $phys, $path, 1000, 5000);
$b = phy_baseline_read($path);
check('round-trips both phys', count($b) === 2 && isset($b['0:0'], $b['0:1']));
check('round-trips counters',  $b['0:0']['inv'] === 100 && $b['0:1']['reset'] === 2);
check('stores ts and uptime',  $b['0:0']['ts'] === 1000 && $b['0:0']['up'] === 5000);

// A corrupt file must degrade to "no baseline", never fatal on the PHY tab.
file_put_contents($path, '{not json');
check('corrupt json reads empty', phy_baseline_read($path) === []);
file_put_contents($path, 'null');
check('json null reads empty',    phy_baseline_read($path) === []);

/* ── per-controller scoping: setting c1 must not disturb c0 ──────────────── */
phy_baseline_set(0, $phys, $path, 1000, 5000);
phy_baseline_set(1, [['phy' => 0, 'inv' => 7, 'disp' => 0, 'sync' => 0, 'reset' => 0]], $path, 2000, 5000);
$b = phy_baseline_read($path);
check('c1 write leaves c0 alone', $b['0:0']['inv'] === 100 && $b['1:0']['inv'] === 7);
check('phy_baseline_for scopes',  array_keys(phy_baseline_for($b, 0)) === [0, 1]
                               && array_keys(phy_baseline_for($b, 1)) === [0]);
check('phy_baseline_ts per ctrl',  phy_baseline_ts($b, 0) === 1000 && phy_baseline_ts($b, 1) === 2000);
check('phy_baseline_ts unset',     phy_baseline_ts($b, 9) === null);

// Re-baselining one controller REPLACES its entries rather than merging, so a
// card that lost a PHY does not keep a stale row forever.
phy_baseline_set(0, [['phy' => 0, 'inv' => 999, 'disp' => 0, 'sync' => 0, 'reset' => 0]], $path, 3000, 5000);
$b = phy_baseline_read($path);
check('re-baseline replaces ctrl', count(phy_baseline_for($b, 0)) === 1 && $b['0:0']['inv'] === 999);
check('re-baseline keeps others',  isset($b['1:0']));

@unlink($path); @rmdir($dir);

/* ── delta / rate arithmetic ─────────────────────────────────────────────── */
$base = ['inv' => 100, 'disp' => 5, 'sync' => 1, 'reset' => 0, 'ts' => 1000, 'up' => 5000];

// no baseline at all -> null, so the caller renders raw counters only
check('no baseline is null', phy_baseline_delta(null, ['inv' => 1], 2000, 6000) === null);
check('empty baseline is null', phy_baseline_delta([], ['inv' => 1], 2000, 6000) === null);

// normal: one hour later, inv 100 -> 250, i.e. delta 150 at 150/hr
$cur = ['inv' => 250, 'disp' => 5, 'sync' => 1, 'reset' => 0];
$d = phy_baseline_delta($base, $cur, 1000 + 3600, 5000 + 3600);
check('delta normal',      $d['reset'] === false && $d['delta']['inv'] === 150);
check('rate one hour',     abs($d['rate']['inv'] - 150.0) < 1e-9);
check('delta zero counter', $d['delta']['disp'] === 0 && $d['rate']['disp'] === 0.0);
check('delta carries ts',   $d['ts'] === 1000);

// half an hour -> the same 150 errors read as 300/hr
$d = phy_baseline_delta($base, $cur, 1000 + 1800, 5000 + 1800);
check('rate half hour', abs($d['rate']['inv'] - 300.0) < 1e-9);

// the 1-minute floor: 10 seconds after baselining, 5 errors must read as
// 300/hr (5 / (1/60)), not 1800/hr — and must never divide by zero.
$d = phy_baseline_delta($base, ['inv' => 105, 'disp' => 5, 'sync' => 1, 'reset' => 0], 1010, 5010);
check('rate floored at 1 minute', abs($d['rate']['inv'] - 300.0) < 1e-9);
$d = phy_baseline_delta($base, ['inv' => 105, 'disp' => 5, 'sync' => 1, 'reset' => 0], 1000, 5000);
check('rate at zero elapsed',     is_finite($d['rate']['inv']) && abs($d['rate']['inv'] - 300.0) < 1e-9);

/* ── the reset trap: both signals, independently ─────────────────────────── */
// Signal 1 — uptime DECREASED: the box rebooted. Counters happen to still be
// higher here, so only the uptime signal can catch this one.
$d = phy_baseline_delta($base, ['inv' => 400, 'disp' => 9, 'sync' => 2, 'reset' => 0], 90000, 120);
check('reset by reboot', $d === ['reset' => true]);

// Signal 2 — a counter DECREASED with uptime still rising: the driver reloaded
// without a reboot (modprobe -r mpt3sas), which uptime alone cannot see.
$d = phy_baseline_delta($base, ['inv' => 3, 'disp' => 0, 'sync' => 0, 'reset' => 0], 90000, 99000);
check('reset by driver reload', $d === ['reset' => true]);

// A decrease in ANY of the four counters counts, not just inv.
foreach (PHY_COUNTERS as $k) {
    $cur = ['inv' => 100, 'disp' => 5, 'sync' => 1, 'reset' => 0];
    $cur[$k] = $base[$k] - 1;   // one below baseline
    if ($cur[$k] < 0) { $cur[$k] = 0; $base[$k] = 1; }
    check("reset detected on $k", phy_baseline_delta($base, $cur, 90000, 99000) === ['reset' => true]);
    $base = ['inv' => 100, 'disp' => 5, 'sync' => 1, 'reset' => 0, 'ts' => 1000, 'up' => 5000];
}

// Unknown uptime (0 — non-Linux, container) must not be read as a reboot; the
// counter signal still has to work.
$d = phy_baseline_delta($base, ['inv' => 150, 'disp' => 5, 'sync' => 1, 'reset' => 0], 4600, 0);
check('uptime 0 is not a reboot', $d['reset'] === false && $d['delta']['inv'] === 50);
$d = phy_baseline_delta($base, ['inv' => 1, 'disp' => 5, 'sync' => 1, 'reset' => 0], 4600, 0);
check('uptime 0 still sees counter reset', $d === ['reset' => true]);

// A baseline stored before uptime was recorded (up = 0) cannot be compared
// either — fall back to the counter signal rather than crying reboot.
$old = ['inv' => 100, 'disp' => 5, 'sync' => 1, 'reset' => 0, 'ts' => 1000, 'up' => 0];
$d = phy_baseline_delta($old, ['inv' => 150, 'disp' => 5, 'sync' => 1, 'reset' => 0], 4600, 30);
check('baseline without uptime compares counters', $d['reset'] === false && $d['delta']['inv'] === 50);

/* ── a missing counter key must not fatal ────────────────────────────────── */
$d = phy_baseline_delta(['inv' => 0, 'ts' => 1000, 'up' => 10], ['inv' => 5], 4600, 20);
check('absent keys default to 0', $d['reset'] === false && $d['delta']['sync'] === 0);

$completed = true;
echo $fails === 0 ? "phy_baseline: all pass\n" : "phy_baseline: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
