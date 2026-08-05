<?PHP
/* Runnable check for health.php: the rate arithmetic, the ring's two reset
   signals, the ring cap, the five state rules (with unknown), and the
   worst-of rollup (with unknown precedence) — the logic that used to not
   exist at all (issue #8: a PHY error rendered as an unexplained amber pill).
     php tests/health_test.php  ->  "health: all pass" (exit 0) */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/health.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

/* A minimal valid sample. $t = epoch, $inv..$rst = phy 0's counters. */
function sample(int $t, int $uptime, int $inv, int $disp = 0, int $sync = 0, int $rst = 0, array $extra = []): array {
    return array_merge([
        't' => $t, 'uptime' => $uptime, 'temp' => 50, 'temp_band' => 'normal',
        'fw' => '24.00.00.00', 'drives' => 16, 'read_ok' => true,
        'link' => ['width' => 8, 'max_width' => 8, 'speed' => '8.0 GT/s', 'max_speed' => '8.0 GT/s'],
        'phys' => [['idx' => 0, 'inv' => $inv, 'disp' => $disp, 'sync' => $sync, 'rst' => $rst, 'rate' => '12.0_Gbit']],
    ], $extra);
}

// ── 1. Rate arithmetic: one hour apart, inv 0->100, gives 100/hr ────────────
$ring = [sample(1000, 100, 0), sample(1000 + 3600, 100 + 3600, 100)];
$rates = health_rates($ring);
check('rate one-hour gap', count($rates) === 1 && abs($rates[0]['inv'] - 100.0) < 0.001);

// ── 2. Rate over an irregular gap: 30 min apart, 0->100, gives 200/hr ───────
$ring2 = [sample(2000, 200, 0), sample(2000 + 1800, 200 + 1800, 100)];
$rates2 = health_rates($ring2);
check('rate irregular 30min gap', count($rates2) === 1 && abs($rates2[0]['inv'] - 200.0) < 0.001);

// no rate under a 60s span
$ringShort = [sample(3000, 300, 0), sample(3030, 330, 50)];
check('rate under 60s span is empty', health_rates($ringShort) === []);

// no rate on a single sample
check('rate single sample is empty', health_rates([sample(3000, 300, 0)]) === []);

// no negative rate ever comes out (defense in depth alongside the ring reset)
$ringDrop = [sample(4000, 400, 100), sample(4000 + 3600, 400 + 3600, 10)];
$ratesDrop = health_rates($ringDrop);
check('rate never negative', $ratesDrop[0]['inv'] >= 0);

// ── 3. Uptime reset: lower uptime than newest stored -> ring dropped ───────
$ring3 = health_ingest([], sample(1000, 100000, 5));
$ring3 = health_ingest($ring3, sample(1100, 100 /* rebooted: uptime far lower */, 5));
check('uptime reset drops ring', count($ring3) === 1);
check('uptime reset keeps the new sample', $ring3[0]['uptime'] === 100);

// ── 4. Counter-decrease reset: uptime unchanged, a PHY counter went down ───
$ring4 = health_ingest([], sample(1000, 500, 100));
$ring4 = health_ingest($ring4, sample(1100, 600 /* uptime still rising */, 10 /* inv dropped: driver reload */));
check('counter-decrease reset drops ring', count($ring4) === 1);
check('counter-decrease reset keeps the new sample', $ring4[0]['phys'][0]['inv'] === 10);

// a normal, monotonically-increasing ingest never resets
$ringOk = health_ingest([], sample(1000, 500, 0));
$ringOk = health_ingest($ringOk, sample(1100, 600, 5));
check('monotonic ingest does not reset', count($ringOk) === 2);

// ── 4b. Duplicate idx (issue #12): an expander PHY collapses onto the same
//    idx as the controller's own PHY -- NOT as a zero reading, but carrying
//    its OWN, DIFFERENT counter from a device the controller doesn't own
//    (measured on the reporter's capture: phy-0:0:10 and phy-0:0:11 both
//    read invalid_dword_count=4 while the real phy-0:10 reads its own
//    number). $prevByIdx keeps whichever entry enumerates last, so the real
//    PHY's own reading gets compared against the expander's -- and when the
//    expander's number is the higher one, that reads as a decrease and
//    wipes the ring on every single sample. Both samples below are
//    identical (a quiet real PHY, idx 0 inv=0, behind a chattier expander
//    PHY that also collapses to idx 0, inv=4) -- nothing actually changed,
//    but without the guard the unpairable idx 0 still looks like a reset. ──
$dupPhys = function (): array {
    return [
        ['idx' => 0, 'inv' => 0, 'disp' => 0, 'sync' => 0, 'rst' => 0, 'rate' => '12.0_Gbit'], // controller's own PHY -- quiet
        ['idx' => 0, 'inv' => 4, 'disp' => 0, 'sync' => 0, 'rst' => 0, 'rate' => ''],           // expander PHY collapsed onto the same idx -- its own counter, unrelated to the real PHY's
    ];
};
$ringDup = health_ingest([], sample(1000, 500, 0, 0, 0, 0, ['phys' => $dupPhys()]));
$ringDup = health_ingest($ringDup, sample(1100, 600, 0, 0, 0, 0, ['phys' => $dupPhys()]));
check('duplicate idx does not wipe the ring', count($ringDup) === 2);

// a genuine decrease on a UNIQUE index still resets even when some other,
// unrelated index in the same samples happens to be duplicated
$mixedPhys = function (int $uniqueInv): array {
    return array_merge(
        [['idx' => 1, 'inv' => $uniqueInv, 'disp' => 0, 'sync' => 0, 'rst' => 0, 'rate' => '12.0_Gbit']],
        [
            ['idx' => 0, 'inv' => 5, 'disp' => 0, 'sync' => 0, 'rst' => 0, 'rate' => '12.0_Gbit'],
            ['idx' => 0, 'inv' => 0, 'disp' => 0, 'sync' => 0, 'rst' => 0, 'rate' => ''],
        ]
    );
};
$ringMixed = health_ingest([], sample(1000, 500, 0, 0, 0, 0, ['phys' => $mixedPhys(100)]));
$ringMixed = health_ingest($ringMixed, sample(1100, 600, 0, 0, 0, 0, ['phys' => $mixedPhys(10) /* idx 1 dropped: real reset */]));
check('unique-index decrease still resets despite an unrelated duplicate', count($ringMixed) === 1);

// ── 5. Ring cap: 300 ingested samples leave exactly HEALTH_RING_CAP ────────
$ringCap = [];
for ($i = 0; $i < 300; $i++) $ringCap = health_ingest($ringCap, sample(1000 + $i, 500 + $i, $i));
check('ring cap holds at limit', count($ringCap) === HEALTH_RING_CAP);
check('ring cap keeps newest', end($ringCap)['phys'][0]['inv'] === 299);
check('ring cap discards oldest first', $ringCap[0]['phys'][0]['inv'] === (300 - HEALTH_RING_CAP));

// ── 6. unknown on a single sample: link_integrity is unknown, not ok ───────
$indOne = health_indicators([sample(1000, 100, 0)], [], 1000);
check('link_integrity unknown on single sample', $indOne['link_integrity']['state'] === 'unknown');

// ── 7. Staleness: newest sample older than HEALTH_STALE_SECS -> unknown ───
$indFresh = health_indicators([sample(1000, 100, 0)], [], 1000 + 60);
check('controller ok when fresh', $indFresh['controller']['state'] === 'ok');
$indStale = health_indicators([sample(1000, 100, 0)], [], 1000 + HEALTH_STALE_SECS + 1);
check('controller unknown when stale', $indStale['controller']['state'] === 'unknown');
$indFailed = health_indicators([sample(1000, 100, 0, 0, 0, 0, ['read_ok' => false])], [], 1000);
check('controller unknown on read_ok=false', $indFailed['controller']['state'] === 'unknown');
// Issue #11: printed values are sentence-cased, not bare lowercase tokens.
check('controller ok value is capitalised',
    health_indicators([sample(1000, 100, 0)], [], 1000)['controller']['value'] === 'OK');
check('controller failed value is capitalised', $indFailed['controller']['value'] === 'Read failed');
/* Every indicator must carry a non-empty `reason`: ajax_info.php prints it as
   the hint line under the row, so a blank one leaves a bare number with nothing
   naming it — the thing issue #11 reported. */
foreach (health_indicators([sample(1000, 100, 0)], [], 1000) as $k => $ind) {
    check("indicator '$k' has a hint reason", ($ind['reason'] ?? '') !== '');
}

// ── topology: drive count vs the ring's own baseline ONLY. The per-PHY
//    downtrain check that used to live here was removed — real hardware (a
//    9400-16i and a 9400-8i) showed every LSI card carries a virtual SES PHY
//    that negotiates 3.0 Gbit permanently, one index past the last data port,
//    which made that rule warn forever. These three cases are what shipped
//    broken; the third is the regression test for the bug itself.
$indTopoOk = health_indicators([sample(1000, 100, 0)], [], 1000);
check('topology ok when drives match baseline', $indTopoOk['topology']['state'] === 'ok');
check('topology value is the drive count',       $indTopoOk['topology']['value'] === '16 drives');

$ringMissing = [
    sample(1000, 100, 0, 0, 0, 0, ['drives' => 16]),
    sample(2000, 200, 0, 0, 0, 0, ['drives' => 8]),
];
$indTopoMissing = health_indicators($ringMissing, [], 2000);
check('topology critical when a drive is missing', $indTopoMissing['topology']['state'] === 'critical');
check('topology reason names both counts',
    str_contains($indTopoMissing['topology']['reason'], '8') && str_contains($indTopoMissing['topology']['reason'], '16'));

/* Issue #11: a controller that has NEVER reported a drive is an unreadable
   count, not a vanished backplane — the lsiutil backend hardcoded 0 and the row
   read "0 drives / All drives present" on a 9207-8i with eight disks on it. It
   must degrade to `unknown` (and so must NOT count towards the ok gauge). */
$indTopoNone = health_indicators([sample(1000, 100, 0, 0, 0, 0, ['drives' => 0])], [], 1000);
check('topology unknown when no count is available', $indTopoNone['topology']['state'] === 'unknown');
check('topology never claims "0 drives" present', !str_contains($indTopoNone['topology']['value'], '0 drive'));
check('topology one drive is not pluralised',
    health_indicators([sample(1000, 100, 0, 0, 0, 0, ['drives' => 1])], [], 1000)['topology']['value'] === '1 drive');

// Regression: a virtual SES PHY negotiating at 3.0 Gbit alongside 12.0 Gbit
// data PHYs must NOT flag topology. This must fail if the downshift rule is
// ever re-added without attached-device-type correlation.
$ringVirtualSes = [sample(1000, 100, 0, 0, 0, 0, [
    'drives' => 16,
    'phys' => [
        ['idx' => 0,  'inv' => 0, 'disp' => 0, 'sync' => 0, 'rst' => 0, 'rate' => '12.0_Gbit'],
        ['idx' => 16, 'inv' => 0, 'disp' => 0, 'sync' => 0, 'rst' => 0, 'rate' => '3.0_Gbit'],
    ],
])];
$indTopoSes = health_indicators($ringVirtualSes, [], 1000);
check('topology ignores a slow virtual-SES PHY', $indTopoSes['topology']['state'] === 'ok');

// ── 8. Worst-of rollup: four ok, one critical -> critical, reason is its own ─
$indicators8 = [
    'a' => ['state' => 'ok', 'reason' => 'a-ok'],
    'b' => ['state' => 'ok', 'reason' => 'b-ok'],
    'c' => ['state' => 'ok', 'reason' => 'c-ok'],
    'd' => ['state' => 'ok', 'reason' => 'd-ok'],
    'e' => ['state' => 'critical', 'reason' => 'e-critical-reason'],
];
[$rollState8, $rollReason8] = health_rollup($indicators8);
check('rollup worst-of picks critical', $rollState8 === 'critical');
check('rollup reason is the worst indicator\'s', $rollReason8 === 'e-critical-reason');

// ── 9. unknown precedence: unknown + all ok -> unknown; unknown + warning -> warning
$indicators9a = [
    'a' => ['state' => 'unknown', 'reason' => 'u-reason'],
    'b' => ['state' => 'ok', 'reason' => 'ok'], 'c' => ['state' => 'ok', 'reason' => 'ok'],
    'd' => ['state' => 'ok', 'reason' => 'ok'], 'e' => ['state' => 'ok', 'reason' => 'ok'],
];
[$rollState9a] = health_rollup($indicators9a);
check('rollup: unknown beats all-ok', $rollState9a === 'unknown');

$indicators9b = [
    'a' => ['state' => 'unknown', 'reason' => 'u-reason'],
    'b' => ['state' => 'warning', 'reason' => 'w-reason'],
    'c' => ['state' => 'ok', 'reason' => 'ok'], 'd' => ['state' => 'ok', 'reason' => 'ok'], 'e' => ['state' => 'ok', 'reason' => 'ok'],
];
[$rollState9b, $rollReason9b] = health_rollup($indicators9b);
check('rollup: warning beats unknown', $rollState9b === 'warning');
check('rollup: reason is the warning indicator\'s', $rollReason9b === 'w-reason');

// all five unknown -> still unknown (nothing ranked at all)
$indicatorsAllUnknown = array_fill_keys(['a', 'b', 'c', 'd', 'e'], ['state' => 'unknown', 'reason' => 'x']);
[$rollAllUnknown] = health_rollup($indicatorsAllUnknown);
check('rollup: all-unknown is unknown', $rollAllUnknown === 'unknown');

// ── 10. Threshold boundaries ────────────────────────────────────────────────
check('50/hr invalid dword is watch',    health_rate_state('inv', 50)  === 'watch');
check('51/hr invalid dword is warning',  health_rate_state('inv', 51)  === 'warning');
check('500/hr invalid dword is warning', health_rate_state('inv', 500) === 'warning');
check('501/hr invalid dword is critical', health_rate_state('inv', 501) === 'critical');
check('0/hr invalid dword is ok',         health_rate_state('inv', 0)   === 'ok');
check('1/hr loss-of-sync is warning (no watch tier)', health_rate_state('sync', 1) === 'warning');
check('9/hr loss-of-sync is warning',                 health_rate_state('sync', 9) === 'warning');
check('10/hr loss-of-sync is critical',               health_rate_state('sync', 10) === 'critical');
check('1/hr phy-reset is warning (no watch tier)',    health_rate_state('rst', 1) === 'warning');
check('10/hr phy-reset is critical',                  health_rate_state('rst', 10) === 'critical');

// link_integrity end-to-end at a boundary: worst PHY's index in the reason
$ratesBoundary = [['idx' => 4, 'inv' => 51, 'disp' => 0, 'sync' => 0, 'rst' => 0]];
$indBoundary = health_indicators([sample(1000, 100, 0)], $ratesBoundary, 1000);
check('link_integrity state matches worst rate', $indBoundary['link_integrity']['state'] === 'warning');
check('link_integrity reason names the PHY index', str_contains($indBoundary['link_integrity']['reason'], 'PHY 4'));

// ── link_integrity rendered value/reason: the "rising (0/hr)" self-contradiction ──
// a sub-1.0 loss-of-sync rate must render as 0.4/hr, not round to 0, in both
// the value and the reason string, and must still rank as warning.
$ratesSubOne = [['idx' => 5, 'inv' => 2.0, 'disp' => 1.1, 'sync' => 0.4, 'rst' => 0.0]];
$indSubOne = health_indicators([sample(1000, 100, 0)], $ratesSubOne, 1000);
check('link_integrity sub-1.0 rate value is 0.4/hr', $indSubOne['link_integrity']['value'] === '0.4/hr');
check('link_integrity sub-1.0 rate reason is 0.4/hr', str_contains($indSubOne['link_integrity']['reason'], '0.4/hr'));
check('link_integrity sub-1.0 rate reason has no (0/hr)', !str_contains($indSubOne['link_integrity']['reason'], '(0/hr)'));
check('link_integrity sub-1.0 rate state is warning', $indSubOne['link_integrity']['state'] === 'warning');
check('link_integrity sub-1.0 rate names loss of sync', str_contains($indSubOne['link_integrity']['reason'], 'loss of sync'));

// a true zero rate still renders as 0/hr, not 0.0/hr
$indZero = health_indicators([sample(1000, 100, 0)], [], 1000);
check('link_integrity no-samples value is em dash', $indZero['link_integrity']['value'] === '—');
$ratesZero = [['idx' => 0, 'inv' => 0.0, 'disp' => 0.0, 'sync' => 0.0, 'rst' => 0.0]];
$indAllZero = health_indicators([sample(1000, 100, 0)], $ratesZero, 1000);
check('link_integrity all-zero rate default value is 0/hr', $indAllZero['link_integrity']['value'] === '0/hr');

// health_rate_str() directly, across the whole boundary
check('rate str 0 -> 0/hr',      health_rate_str(0)      === '0/hr');
check('rate str 0.4 -> 0.4/hr',  health_rate_str(0.4)    === '0.4/hr');
check('rate str 9.99 -> 10.0/hr', health_rate_str(9.99)  === '10.0/hr');
check('rate str 10 -> 10/hr',    health_rate_str(10)     === '10/hr');
check('rate str 70 -> 70/hr',    health_rate_str(70)     === '70/hr');

// ── health_rate_str floor: a long ring window can divide one event down to a
// rate that rounds to "0.0" at one decimal — same self-contradiction, one
// decimal out. A single loss-of-sync event over ~24h between two tab
// renders is 1/24 = 0.042/hr, still `warning`, and must not print as zero.
check('rate str 0.04 -> <0.1/hr', health_rate_str(0.04) === '<0.1/hr');
check('rate str 0.05 -> 0.1/hr',  health_rate_str(0.05) === '0.1/hr');
check('rate str 0 still -> 0/hr (floor does not catch true zero)', health_rate_str(0) === '0/hr');

$ratesFloor = [['idx' => 5, 'inv' => 0.0, 'disp' => 0.0, 'sync' => 0.04, 'rst' => 0.0]];
$indFloor = health_indicators([sample(1000, 100, 0)], $ratesFloor, 1000);
check('link_integrity floor rate state is warning', $indFloor['link_integrity']['state'] === 'warning');
check('link_integrity floor rate value is <0.1/hr', $indFloor['link_integrity']['value'] === '<0.1/hr');
check('link_integrity floor rate reason has <0.1/hr', str_contains($indFloor['link_integrity']['reason'], '<0.1/hr'));
check('link_integrity floor rate reason has no (0.0/hr)', !str_contains($indFloor['link_integrity']['reason'], '(0.0/hr)'));

// ── health_rank: single source of truth, unknown unranked ──────────────────
check('rank ok < watch < warning < critical',
    health_rank('ok') < health_rank('watch')
    && health_rank('watch') < health_rank('warning')
    && health_rank('warning') < health_rank('critical'));
check('rank unknown is unranked', health_rank('unknown') === -1);

// ── health_gauge: a COUNT of what health_indicators() returned, not a score ──
$st = fn(string ...$states) => array_map(fn($s) => ['state' => $s], $states);
check('gauge 0 of N',   health_gauge($st('warning', 'critical', 'unknown')) === ['ok' => 0, 'total' => 3, 'frac' => 0.0]);
check('gauge N of N',   health_gauge($st('ok', 'ok', 'ok', 'ok')) === ['ok' => 4, 'total' => 4, 'frac' => 1.0]);
$mixed = health_gauge($st('ok', 'watch', 'ok', 'unknown', 'ok'));
check('gauge mixed count', $mixed['ok'] === 3 && $mixed['total'] === 5);
check('gauge mixed frac',  abs($mixed['frac'] - 0.6) < 1e-9);
// empty input must not divide by zero — the tab renders before any sample lands
check('gauge empty', health_gauge([]) === ['ok' => 0, 'total' => 0, 'frac' => 0.0]);
// counts whatever came back, so a sixth indicator needs no edit here
check('gauge counts real indicators', health_gauge(health_indicators($ring, $rates, 1000))['total'] === count(health_indicators($ring, $rates, 1000)));

// ── store round-trip through a temp dir (no /tmp collision with real runs) ──
$dir  = sys_get_temp_dir() . '/hbav_health_' . getmypid();
$file = health_store_path(0, $dir);
check('missing store reads empty', health_store_read($file) === []);
health_store_write($file, $ring);
check('store round-trips', health_store_read($file) === $ring);
@unlink($file); @rmdir($dir);

echo $fails === 0 ? "health: all pass\n" : "health: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
