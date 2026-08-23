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

/* ── Feed the health ring ────────────────────────────────────────────────────
 * The ring behind the Health tab's link-integrity indicator is appended to ONE
 * SAMPLE PER TAB RENDER, so its span is however often a human happened to look
 * -- 24 hours if you open it daily, seconds if you refresh twice. That is the
 * wrong shape for the question it answers. HEALTH_MIN_CLEAR_SECS is 1800,
 * so below a 30-minute span the indicator will not issue an all-clear at all,
 * and a visit-driven ring is often narrower than that. Opening the tab again to
 * "check" made it worse.
 *
 * This cron already runs every 10 minutes, so appending here gives 6 samples an
 * hour: HEALTH_RING_CAP of 240 becomes 40 hours of continuous history, always
 * wider than the minimum-clear window after the first half hour.
 *
 * Deliberately INSIDE the ENABLE_NOTIFY guard above. This file's contract is
 * that a disabled feature does not poll silicon every ten minutes, and taking
 * the ring outside that guard would start a hardware read on every install that
 * wants nothing. The trade: trend history for the people who asked to be told
 * about health changes, which is the population that wants it. Decoupling the
 * two would need a setting of its own.
 *
 * Second composer call, not a reuse: the ring's sample is get_hba_health.sh's
 * shape (phys, uptime, counters), which get_hba_info.sh does not carry.
 *
 * Ingested through the SAME health_ingest() the tab uses -- one append rule,
 * including its reboot and counter-reset detection. A second rule here would be
 * a second thing to keep correct. */
require_once "$plugin/health.php";

$hraw = shell_exec('bash ' . escapeshellarg(__DIR__ . '/get_hba_health.sh') . ' 2>/dev/null');
$hdec = json_decode((string) $hraw, true);
if (is_array($hdec)) {
    foreach (($hdec['controllers'] ?? []) as $i => $ctl) {
        if (!is_array($ctl) || isset($ctl['error'])) continue;
        $file = health_store_path((int) $i);
        health_store_write($file, health_ingest(health_store_read($file), $ctl));
    }
}
