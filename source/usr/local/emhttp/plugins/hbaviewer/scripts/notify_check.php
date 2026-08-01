<?PHP
/* HBAviewer cron entrypoint — the ONLY thing in this plugin that runs without a
 * browser. Everything else happens on page load; this exists so a card that
 * goes critical at 3am is reported at 3am.
 *
 * Invoked from cron (installed/removed by hbaviewer.plg, never by hand):
 *   /usr/bin/php /usr/local/emhttp/plugins/hbaviewer/scripts/notify_check.php
 *
 * Toggle off = one small file read and exit: no hardware query, no requires,
 * nothing to notify. A disabled feature must not poll silicon every 10 minutes.
 */

$plugin = __DIR__ . '/..';
require_once "$plugin/config.php";

$cfg = lsi_config_read();
if (empty($cfg['ENABLE_NOTIFY'])) exit(0);

require_once "$plugin/view.php";     // lsi_controllers(): the one payload-shape rule
require_once "$plugin/notify.php";

// Same composer the Overview uses — no PHP/HTTP round trip, no second read path.
$json = shell_exec('bash ' . escapeshellarg(__DIR__ . '/get_hba_info.sh') . ' 2>/dev/null');
$data = json_decode((string) $json, true);
// Backend produced nothing parseable: stay quiet. Treating an unreadable poll as
// a status would notify on a transient the user cannot act on.
if (!is_array($data)) exit(0);

lsi_notify_run(lsi_controllers($data));
