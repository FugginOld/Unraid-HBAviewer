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
// A card that reports its own port labels itself with it, not with the one
// Settings names -- otherwise cards 2 and 3 of a multi-card box both tell you
// to run -p1, which reads card 1 (issue #18).
$own = lsi_hba_view(['port_name' => 'ioc2', 'port' => 3], 1);
check('own port wins',  $own['port_label'] === 'ioc2 (lsiutil -p3)');
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

/* Same check, one directory down: every <script src> the plugin renders must
   name a file that actually ships. Cheap before plan 057 and worth little;
   essential after it, because the page's whole behaviour now arrives through
   two of these. A mistyped path renders a page that looks completely normal
   and does nothing at all — no tab switching, no bay map, no Locate — and the
   404 is only visible in a browser console nobody has open. `?v=` cache
   busters are stripped; *.min.js is skipped, since the vendored Chart.js is
   gitignored and fetched by build.sh, so it is absent from a fresh checkout. */
$badSrc = [];
foreach (glob("$dir/*.php") ?: [] as $src) {
    if (!preg_match_all('~<script[^>]+src="/plugins/hbaviewer/([^"?]+)~',
                        (string) file_get_contents($src), $m)) continue;
    foreach ($m[1] as $asset) {
        if (str_ends_with($asset, '.min.js')) continue;
        if (!is_file("$dir/$asset")) $badSrc[] = basename($src) . " -> $asset (no such file)";
    }
}
check('every <script src> names a file that ships', $badSrc === []);
if ($badSrc) foreach ($badSrc as $b) echo "      $b\n";

/* The firmware page must not appear in Unraid's menu unless flashing is on.
   Gating the BUTTON in settings.php is not enough — a .page file with no Cond
   is listed under Settings regardless, so the entry showed up for people who
   had never enabled flashing and could not use it. The Cond is the only thing
   that hides the menu entry itself; flash_view.php's own ENABLE_FLASH check
   stays as the second line of defence for a direct URL. */
check('the firmware page is menu-gated on ENABLE_FLASH',
      isset($pages['HBAviewer_Flash'])
      && preg_match('~^Cond="[^"]*ENABLE_FLASH~m', $pages['HBAviewer_Flash']));
check('the firmware view still guards itself for a direct URL',
      str_contains((string) file_get_contents("$dir/flash_view.php"), "ENABLE_FLASH"));

/* The firmware verdict rides on the view model so both surfaces read one
   answer. The Overview card and the firmware page must never disagree about
   whether a card is behind. */
$vm = lsi_hba_view([
    'board_name' => 'SAS9305-24i', 'model' => 'SAS3224', 'firmware' => '15.00.00.00',
    'subvendor_id' => '0x1000', 'topology' => 'internal', 'status' => 'ok',
], 1, 0);
check('view model carries a firmware verdict', isset($vm['firmware_verdict']['status']));
check('view model verdict is behind', ($vm['firmware_verdict']['status'] ?? '') === 'behind');

/* The clause is suppressed on every state that has no verdict to give: a bare
   colourless marker next to a version reads as a fault the user cannot act on. */
check('behind renders a clause',   fw_overview_clause(['status' => 'behind', 'latest' => '16.00.12.00', 'terminal' => true]) !== '');
check('suppressed renders nothing', fw_overview_clause(['status' => 'suppressed', 'detected' => '15.00.00.00']) === '');
check('unknown renders nothing',    fw_overview_clause(['status' => 'unknown']) === '');
check('oem renders nothing',        fw_overview_clause(['status' => 'oem_out_of_scope']) === '');
/* Round-1 review (Important, minor): the other two states that DO render were
   never asserted at all — only 'behind' had a positive check, so blanking the
   'current' or 'ahead' branch entirely was invisible to this suite. */
check('current renders a clause', fw_overview_clause(['status' => 'current']) !== '');
check('ahead renders a clause',   fw_overview_clause(['status' => 'ahead']) !== '');

/* The colour rule has ONE home, fw_verdict_color(). This clause used to hardcode
   the green, so making green conditional on a terminal branch reached the JSON
   endpoint and the flash page and left this server-rendered Overview green. Both
   directions, and the tick itself renders either way — it is the colour that is
   withheld, not the clause. */
check('current on a terminal branch is green here too',
    str_contains(fw_overview_clause(['status' => 'current', 'terminal' => true]), 'color:#3fb950'));
check('current on a non-terminal branch is not green here either',
    !str_contains(fw_overview_clause(['status' => 'current', 'terminal' => false]), '#3fb950'));
check('behind on a non-terminal branch is not amber here either',
    !str_contains(fw_overview_clause(['status' => 'behind', 'latest' => '16.00.12.00']), '#d29922'));

/* Round-1 review (Important, minor): 'latest' is the one field this clause
   prints, and it is board-derived, untrusted content per firmware_index.php's
   own docblock. Dropping the htmlspecialchars() call survives every other
   assertion here — none of them put HTML-special characters in 'latest'. */
$xssClause = fw_overview_clause(['status' => 'behind', 'latest' => '<script>x</script>', 'terminal' => true]);
check('the clause escapes an untrusted latest version',
    str_contains($xssClause, '&lt;script&gt;') && !str_contains($xssClause, '<script>'));

/* Round-1 review (Important, minor): view.php's own verdict call maps
   $data['model'] to fw_evaluate()'s 'chip' key — the field Gate 3 (RAID-on-Chip)
   matches on. Blanking that mapping is invisible to every check above, which
   all name a board directly; this one only resolves through the chip. */
$roc = lsi_hba_view([
    'board_name' => 'MegaRAID 9361-8i', 'model' => 'SAS3108', 'firmware' => '4.30.00.00',
    'subvendor_id' => '0x1000', 'topology' => 'internal', 'status' => 'ok',
], 1, 0);
check('the chip mapping reaches the verdict (RAID-on-Chip detected via chip, not board)',
    ($roc['firmware_verdict']['status'] ?? '') === 'no_it_firmware');

echo $fails === 0 ? "view: all pass\n" : "view: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
