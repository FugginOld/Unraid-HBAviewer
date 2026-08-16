<?PHP
/* Prints the Overview HTML for one backend-JSON fixture so tests/run.sh can
   diff it against a golden. The clock is the only volatile byte in the render,
   so it is normalised away.
     php tests/render_overview.php tests/expected/storcli_overview.json */
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php';
$fixture = (string) ($_SERVER['argv'][1] ?? '');
echo preg_replace('~(Last read: <span>)[^<]*~', '$1TIME', renderOverviewCards(
    (array) json_decode((string) file_get_contents($fixture), true),
    ['HBA_PORT' => 1, 'ALERT_THRESHOLD' => 76, 'SHOW_PCIE' => 1]));
