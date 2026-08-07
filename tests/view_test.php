<?PHP
/* Runnable check for view.php: status map, fallbacks, PCIe assembly.
   Needs php (present on the Unraid box):  php tests/view_test.php  */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/view.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

// status map
check('color ok',    lsi_status_color('ok')    === '#2ecc71');
check('color warn',  lsi_status_color('warn')  === '#f39c12');
check('color alert', lsi_status_color('alert') === '#e74c3c');
check('label alert', lsi_status_label('alert') === 'ALERT');

// temperature bands are gradients now: two stops each, five bands + a default
foreach (['critical' => ['#6b0f0c', '#b82820'], 'alert' => ['#9c1810', '#e8443a'],
          'warning'  => ['#a85410', '#f09428'], 'elevated' => ['#b8890a', '#f5d020'],
          'normal'   => ['#0f7a1a', '#41d141'], ''         => ['#0f7a1a', '#41d141'],
          'nonsense' => ['#0f7a1a', '#41d141']] as $band => $want) {
    check("gradient " . ($band === '' ? '(empty)' : $band), lsi_temp_gradient($band) === $want);
}
// the critical chip is the one flat fill that survived the move to gradients
check('critical chip fill', lsi_temp_color('critical') === '#922b21');
check('non-critical -> dark stop', lsi_temp_color('elevated') === '#b8890a');

// health states borrow the temperature gradients; unknown is grey, never green
check('health critical grad', lsi_health_gradient('critical') === lsi_temp_gradient('alert'));
check('health watch grad',    lsi_health_gradient('watch')    === lsi_temp_gradient('elevated'));
check('health ok grad',       lsi_health_gradient('ok')       === lsi_temp_gradient('normal'));
check('health unknown grey',  lsi_health_gradient('unknown')  === ['#4a4d4a', '#8f938f']);

// theme signal: the two light themes only, and a MISSING $display must be dark
unset($GLOBALS['display']);
check('no $display -> dark', lsi_tile_is_light() === false);
foreach (['white' => true, 'azure' => true, 'black' => false, 'gray' => false, 'martian' => false] as $theme => $want) {
    $GLOBALS['display'] = ['theme' => $theme];
    check("theme $theme", lsi_tile_is_light() === $want);
}
$GLOBALS['display'] = [];
check('$display without theme -> dark', lsi_tile_is_light() === false);
unset($GLOBALS['display']);

// gauge arc: dashoffset counts DOWN from the full arc, so 0 must be EMPTY (a
// 0C reading rendering as a full gauge is the off-by-one this guards).
$g0 = lsi_gauge_svg('g0', 0.0, ['#000', '#fff']);
check('0% arc is empty',  strpos($g0, 'stroke-dashoffset="251.3"') !== false);
check('full arc',         strpos(lsi_gauge_svg('g1', 1.0, ['#000', '#fff']), 'stroke-dashoffset="0.0"') !== false);
check('half arc',         strpos(lsi_gauge_svg('g2', 0.5, ['#000', '#fff']), 'stroke-dashoffset="125.7"') !== false);
check('frac clamped low', strpos(lsi_gauge_svg('g3', -5.0, ['#000', '#fff']), 'stroke-dashoffset="251.3"') !== false);
check('frac clamped high',strpos(lsi_gauge_svg('g4', 9.0, ['#000', '#fff']), 'stroke-dashoffset="0.0"') !== false);
// ids must be unique per gauge or two controllers cross-contaminate
check('gradient id used', strpos($g0, 'id="g0"') !== false && strpos($g0, 'url(#g0)') !== false);
check('stops emitted',    strpos($g0, 'stop-color="#000"') !== false && strpos($g0, 'stop-color="#fff"') !== false);

// full view over a representative payload
$data = [
    'temp' => 47, 'status' => 'ok',
    'model' => 'SAS2308', 'firmware' => '14.00.07.00',
    'port_name' => 'ioc0', 'board_name' => 'SAS9207-8i',
    'pci_location' => '03:00', 'pcie_width' => 'x8',
    'pcie_speed' => 'Gen3 (8.0 GT/s)', 'power_mode' => 'Full',
];
$v = lsi_hba_view($data, 1);
check('temp',       $v['temp'] === 47);
check('color',      $v['color'] === '#2ecc71');
check('label',      $v['label'] === 'NORMAL');
check('model=board',$v['model'] === 'SAS9207-8i');       // board_name wins
check('chip=model', $v['chip'] === 'SAS2308');
check('port label', $v['port_label'] === 'ioc0 (lsiutil -p1)');
check('pcie count', count($v['pcie']) === 4);
check('pcie order', $v['pcie'][0]['label'] === 'PCIe Width' && $v['pcie'][0]['value'] === 'x8');
check('temp_grad key',  $v['temp_grad'] === ['#0f7a1a', '#41d141']);
// dead field, dropped by plan 030 rather than ported to a gradient
check('no temp_color',  !array_key_exists('temp_color', $v));
check('no temp_stroke', !array_key_exists('temp_stroke', $v));

// fallbacks + empty PCIe
$bare = lsi_hba_view(['temp' => 30, 'status' => 'alert'], 2);
check('model fallback', $bare['model'] === 'Unknown');
check('port name def',  $bare['port_label'] === 'ioc0 (lsiutil -p2)');
check('pcie empty',     $bare['pcie'] === []);
check('alert color',    $bare['color'] === '#e74c3c');

// multi-controller contract normalizer
$multi = lsi_controllers(['controllers' => [['temp' => 72], ['temp' => 77]]]);
check('controllers array', count($multi) === 2 && $multi[1]['temp'] === 77);
/* lsi_age_str: how old a cached reading is. The SMART cache is now kept until
   someone refreshes it, so every surface that renders it states its age —
   without that, a week-old temperature reads exactly like a live one. One
   coarse unit on purpose; the caller supplies the sentence. */
check('age just now',       lsi_age_str(0) === 'just now');
check('age under a minute', lsi_age_str(59) === 'just now');
check('age minutes',        lsi_age_str(60) === '1 min');
check('age rounds down',    lsi_age_str(119) === '1 min');
check('age hours at 3600',  lsi_age_str(3600) === '1 h');
check('age hours',          lsi_age_str(7200) === '2 h');
check('age days at 86400',  lsi_age_str(86400) === '1 d');
check('age days',           lsi_age_str(86400 * 3 + 5) === '3 d');

$flat = lsi_controllers(['temp' => 50, 'status' => 'ok']);   // legacy flat -> 1 element
check('flat wraps to one', count($flat) === 1 && $flat[0]['temp'] === 50);

// storcli controller (empty port_name) labels by controller index, not lsiutil port
$sc = lsi_hba_view(['temp' => 72, 'status' => 'ok', 'port_name' => '', 'board_name' => 'HBA 9400-16i'], 1, 1);
check('storcli port label', $sc['port_label'] === 'Controller /c1');
check('storcli model',      $sc['model'] === 'HBA 9400-16i');

/* ── Internal page links resolve ──────────────────────────────────────────────
   Every /Tools/X and /Settings/X href in the plugin must name a real X.page,
   and that page must not be Type="menu" — a menu entry is a container with no
   content of its own, so linking to one lands nowhere at all.

   Both mistakes shipped in one evening (plan 055): /Tools/HBAviewer, which is
   the Type="menu" container rather than the monitor, and /Utilities/… for a
   page whose Menu="Utilities" is actually served under /Settings/. Nothing
   caught either, because a dead internal link looks exactly like a working one
   until it is clicked. This is the cheap static check that would have. */
$dir   = __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer';
$pages = [];
foreach (glob("$dir/*.page") ?: [] as $p) {
    $pages[basename($p, '.page')] = (string) file_get_contents($p);
}
check('page files were found at all', count($pages) >= 4);

$bad = [];
foreach (glob("$dir/*.php") ?: [] as $src) {
    if (!preg_match_all('~href="/(?:Tools|Settings|Utilities)/([A-Za-z0-9_]+)"~',
                        (string) file_get_contents($src), $m)) continue;
    foreach ($m[1] as $target) {
        // Unraid's own pages (Notifications, etc.) are not ours to resolve.
        if (!str_starts_with($target, 'HBAviewer')) continue;
        if (!isset($pages[$target]))                       $bad[] = basename($src) . " -> $target (no such .page)";
        elseif (preg_match('~^Type="menu"~m', $pages[$target])) $bad[] = basename($src) . " -> $target (Type=\"menu\", a container with no content)";
    }
}
check('every internal HBAviewer link resolves to a content page', $bad === []);
if ($bad) foreach ($bad as $b) echo "      $b\n";

echo $fails === 0 ? "view: all pass\n" : "view: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
