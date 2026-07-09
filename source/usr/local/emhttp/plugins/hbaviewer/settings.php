<?PHP
/* HBAviewer Settings — full settings form.
   Reached via the HBAviewer icon card in Unraid Settings > System Settings. */

require_once __DIR__ . '/config.php';
$cfg   = lsi_config_read();
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_hbaviewer'])) {
    // Map the form (checkbox-absent = off); config_write clamps to schema.
    lsi_config_write([
        'HBA_PORT'        => $_POST['port']      ?? 1,
        'ALERT_THRESHOLD' => $_POST['threshold'] ?? 80,
        'SHOW_PCIE'       => isset($_POST['show_pcie'])   ? 1 : 0,
        'SHOW_PHY'        => isset($_POST['show_phy'])    ? 1 : 0,
        'SHOW_DRIVES'     => isset($_POST['show_drives']) ? 1 : 0,
        'SHOW_EVENTS'     => isset($_POST['show_events']) ? 1 : 0,
    ]);
    $cfg   = lsi_config_read();
    $saved = true;
}

function lu_checked(int $val): string { return $val ? 'checked' : ''; }
?>

<style>
#lu-settings-wrap { font-family: inherit; max-width: 560px; margin: 20px auto; }
.lu-s-card { background: #1c1c1c; border: 1px solid #333; border-radius: 6px; padding: 20px 24px; margin-bottom: 16px; }
.lu-s-card h3 { margin: 0 0 16px; color: #bbb; font-size: 12px; text-transform: uppercase; letter-spacing: 0.07em; border-bottom: 1px solid #2a2a2a; padding-bottom: 10px; }
.lu-s-row { display: flex; align-items: flex-start; gap: 16px; margin-bottom: 14px; }
.lu-s-row:last-child { margin-bottom: 0; }
.lu-s-label { flex: 0 0 180px; font-size: 13px; color: #ccc; padding-top: 8px; }
.lu-s-label small { display: block; font-size: 11px; color: #555; margin-top: 3px; line-height: 1.4; }
.lu-s-control { flex: 1; }
.lu-s-control input[type=number] { width: 90px; background: #111; border: 1px solid #3a3a3a; border-radius: 4px; color: #ddd; padding: 7px 10px; font-size: 14px; }
.lu-s-control input[type=number]:focus { outline: none; border-color: #f5a623; }
.lu-toggle { display: flex; align-items: center; gap: 10px; padding: 8px 0; cursor: pointer; }
.lu-toggle input[type=checkbox] { width: 16px; height: 16px; accent-color: #f5a623; cursor: pointer; }
.lu-toggle span { font-size: 13px; color: #ddd; }
.lu-toggle small { font-size: 11px; color: #555; margin-left: auto; }
.lu-notice { background: #1a2a1a; border: 1px solid #2a4a2a; border-radius: 4px; color: #8c8; font-size: 12px; padding: 8px 14px; margin-bottom: 14px; }
.lu-btn { background: #f5a623; border: none; border-radius: 4px; color: #111; font-size: 13px; font-weight: 700; padding: 9px 24px; cursor: pointer; letter-spacing: 0.03em; margin-right: 10px; }
.lu-btn:hover { background: #d9901a; }
.lu-link { font-size: 12px; color: #f5a623; text-decoration: none; }
.lu-link:hover { text-decoration: underline; }
</style>

<div id="lu-settings-wrap">

  <?php if ($saved): ?>
  <div class="lu-notice">Settings saved.</div>
  <?php endif; ?>

  <form method="post">

    <div class="lu-s-card">
      <h3>HBA Connection</h3>

      <div class="lu-s-row">
        <div class="lu-s-label">
          lsiutil Port
          <small>Run lsiutil without arguments to list ports. Usually 1.</small>
        </div>
        <div class="lu-s-control">
          <input type="number" name="port" value="<?= (int)$cfg['HBA_PORT'] ?>" min="1" max="8">
        </div>
      </div>

      <div class="lu-s-row">
        <div class="lu-s-label">
          Alert Threshold (°C)
          <small>Unraid notification fires when temperature reaches this value.</small>
        </div>
        <div class="lu-s-control">
          <input type="number" name="threshold" value="<?= (int)$cfg['ALERT_THRESHOLD'] ?>" min="1" max="150">
        </div>
      </div>
    </div>

    <div class="lu-s-card">
      <h3>Display Panels</h3>
      <p style="font-size:12px;color:#555;margin:0 0 14px">Temperature is always shown. Toggle additional panels below.</p>

      <label class="lu-toggle">
        <input type="checkbox" name="show_pcie" <?= lu_checked((int)$cfg['SHOW_PCIE']) ?>>
        <span>PCIe Information</span>
        <small>Width &amp; speed in the Overview</small>
      </label>
      <label class="lu-toggle">
        <input type="checkbox" name="show_phy" <?= lu_checked((int)$cfg['SHOW_PHY']) ?>>
        <span>PHY Health</span>
        <small>SAS link state &amp; error counters per port</small>
      </label>
      <label class="lu-toggle">
        <input type="checkbox" name="show_drives" <?= lu_checked((int)$cfg['SHOW_DRIVES']) ?>>
        <span>Attached Drives</span>
        <small>SAS addresses, enclosure/slot, OS device names</small>
      </label>
      <label class="lu-toggle">
        <input type="checkbox" name="show_events" <?= lu_checked((int)$cfg['SHOW_EVENTS']) ?>>
        <span>Event Log</span>
        <small>HBA firmware event log (requires expert mode)</small>
      </label>
    </div>

    <button class="lu-btn" type="submit" name="save_hbaviewer" value="1">Save Settings</button>
    <a class="lu-link" href="/Tools/HBAviewer_Monitor">← Open HBAviewer monitor</a>

  </form>
</div>
