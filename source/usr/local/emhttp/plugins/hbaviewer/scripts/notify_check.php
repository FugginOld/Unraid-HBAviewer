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
/* Two independent opt-ins, either of which gives this file work to do. Both
   default off, because the contract above is that a disabled feature does not
   poll silicon every ten minutes -- and with neither ticked, this is still one
   small file read and an exit. */
$doNotify  = !empty($cfg['ENABLE_NOTIFY']);
$doHistory = !empty($cfg['TRACK_HISTORY']);
if (!$doNotify && !$doHistory) exit(0);

require_once "$plugin/view.php";     // lsi_controllers(): the one payload-shape rule
require_once "$plugin/notify.php";

if ($doNotify) {
    // Same composer the Overview uses — no PHP/HTTP round trip, no second read path.
    $json = shell_exec('bash ' . escapeshellarg(__DIR__ . '/get_hba_info.sh') . ' 2>/dev/null');
    $data = json_decode((string) $json, true);
    // Backend produced nothing parseable: stay quiet. Treating an unreadable poll
    // as a status would notify on a transient the user cannot act on. `return`
    // rather than exit(0): this must not skip the history sample below, which is
    // a separate feature reading a separate composer.
    if (is_array($data)) lsi_notify_run(lsi_controllers($data));
}

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
 * Behind TRACK_HISTORY, its own switch. It was briefly a rider on
 * ENABLE_NOTIFY -- the file's contract is that a disabled feature does not
 * poll silicon every ten minutes, and that guard was already here. But the
 * two are different features, and testing it on real hardware made the cost
 * obvious: a health-history feature that only runs once you find and tick a
 * NOTIFICATIONS toggle is one nobody discovers. Same contract, honoured with
 * a switch of its own.
 *
 * Second composer call, not a reuse: the ring's sample is get_hba_health.sh's
 * shape (phys, uptime, counters), which get_hba_info.sh does not carry.
 *
 * Ingested through the SAME health_ingest() the tab uses -- one append rule,
 * including its reboot and counter-reset detection. A second rule here would be
 * a second thing to keep correct. */
if ($doHistory) {
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
}
