<?PHP
/* HBAviewer Settings — full settings form.
   Reached via the HBAviewer icon card in Unraid Settings > System Settings. */

require_once __DIR__ . '/config.php';
$cfg   = lsi_config_read();
$saved = false;

// Backend detection — driver via sysfs + storcli path lookup. Both are instant
// (no hardware enumeration), so the page never lags. SAS2 (6 Gb) cards use the
// mpt2sas driver + bundled lsiutil; SAS3/3.5 use mpt3sas + system storcli.
$has_sas2 = is_dir('/sys/module/mpt2sas');
$has_sas3 = is_dir('/sys/module/mpt3sas');
$storcli  = '';
foreach (['/usr/local/sbin/storcli','/usr/local/sbin/storcli64','/usr/sbin/storcli','/usr/sbin/storcli64'] as $c) {
    if (is_executable($c)) { $storcli = $c; break; }
}
if ($storcli === '') {
    $w = trim((string) shell_exec('command -v storcli storcli64 2>/dev/null'));
    if ($w !== '') $storcli = strtok($w, "\n");
}
if ($has_sas2 && !$has_sas3) {
    $backend_label = 'lsiutil (bundled)';
    $backend_note  = 'SAS2 controller detected (mpt2sas driver).';
} elseif ($has_sas3) {
    if ($storcli !== '') {
        $backend_label = 'storcli';
        $backend_note  = 'SAS3 / SAS3.5 controller detected (mpt3sas driver).';
    } else {
        $backend_label = 'storcli — NOT INSTALLED';
        $backend_note  = 'SAS3 / SAS3.5 controller detected, but storcli is missing. Install it via the dkaser/unraid-storcli plugin (Community Applications).';
    }
} else {
    $backend_label = 'none detected';
    $backend_note  = 'No supported HBA driver (mpt2sas / mpt3sas) is loaded.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_hbaviewer'])) {
    // Map the form (checkbox-absent = off); config_write clamps to schema.
    lsi_config_write([
        'HBA_PORT'        => $_POST['port']      ?? 1,
        'ALERT_THRESHOLD' => $_POST['threshold'] ?? 80,
        'SHOW_PCIE'       => isset($_POST['show_pcie'])   ? 1 : 0,
        'SHOW_PHY'        => isset($_POST['show_phy'])    ? 1 : 0,
        'SHOW_DRIVES'     => isset($_POST['show_drives']) ? 1 : 0,
        'SHOW_EVENTS'     => isset($_POST['show_events']) ? 1 : 0,
        'SHOW_PERF'       => isset($_POST['show_perf'])   ? 1 : 0,
        'ENABLE_FLASH'    => isset($_POST['enable_flash']) ? 1 : 0,
    ]);
    $cfg   = lsi_config_read();
    $saved = true;
}

function lu_checked(int $val): string { return $val ? 'checked' : ''; }
?>

<style>
/* Original HBAviewer palette in the new component format. Matches the Monitor. */
#lu-settings-wrap {
    --bg:#161616; --surface:#1c1c1c; --surface-2:#232323;
    --border:#333333; --border-soft:#2a2a2a;
    /* ponytail: one text colour; --muted/--faint kept as aliases so the call sites stay untouched */
    --text:#dddddd; --muted:#dddddd; --faint:#dddddd;
    --accent:#f5a623; --good:#2ecc71; --crit:#e74c3c;
    --mono: ui-monospace,"SF Mono","Cascadia Code",Menlo,monospace;
    font-family: inherit; max-width: 580px; margin: 20px auto; color: var(--text);
    background: radial-gradient(700px 300px at 85% -20%, #242424 0%, rgba(0,0,0,0) 55%), var(--bg);
    border: 1px solid var(--border-soft); border-radius: 16px; padding: 22px 24px;
}
.lu-s-card { background: linear-gradient(180deg,var(--surface-2),var(--surface)); border: 1px solid var(--border-soft); border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; }
.lu-s-card h3 { margin: 0 0 16px; color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: 0.09em; border-bottom: 1px solid var(--border-soft); padding-bottom: 10px; display: flex; align-items: center; gap: 8px; }
.lu-s-card h3::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: var(--accent); box-shadow: 0 0 8px var(--accent); flex: 0 0 auto; }
.lu-s-row { display: flex; align-items: flex-start; gap: 16px; margin-bottom: 14px; }
.lu-s-row:last-child { margin-bottom: 0; }
.lu-s-label { flex: 0 0 180px; font-size: 13px; color: var(--text); padding-top: 8px; }
.lu-s-label small { display: block; font-size: 11px; color: var(--faint); margin-top: 3px; line-height: 1.4; }
.lu-s-control { flex: 1; }
.lu-s-control input[type=number] { width: 90px; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; color: var(--text); padding: 7px 10px; font-size: 14px; font-family: var(--mono); }
.lu-s-control input[type=number]:focus { outline: none; border-color: var(--accent); }
.lu-toggle { display: flex; align-items: center; gap: 10px; padding: 8px 0; cursor: pointer; }
.lu-toggle input[type=checkbox] { width: 16px; height: 16px; accent-color: var(--accent); cursor: pointer; }
.lu-toggle span { font-size: 13px; color: var(--text); }
.lu-toggle small { font-size: 11px; color: var(--faint); margin-left: auto; }
.lu-notice { background: color-mix(in srgb, var(--good) 12%, var(--surface)); border: 1px solid color-mix(in srgb, var(--good) 30%, transparent); border-radius: 8px; color: #8ccc8c; font-size: 12px; padding: 9px 14px; margin-bottom: 14px; }
.lu-danger { background: color-mix(in srgb, var(--crit) 12%, var(--surface)); border: 1px solid color-mix(in srgb, var(--crit) 36%, transparent); border-radius: 8px; color: #e0a0a0; font-size: 12px; line-height: 1.5; padding: 10px 14px; margin-bottom: 14px; }
.lu-danger strong { color: var(--crit); }
.lu-btn { background: var(--accent); border: none; border-radius: 6px; color: #111; font-size: 13px; font-weight: 700; padding: 9px 24px; cursor: pointer; letter-spacing: 0.03em; margin-right: 10px; }
.lu-btn:hover { background: #d9901a; }
.lu-link { font-size: 12px; color: var(--accent); text-decoration: none; }
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
          Access Method
          <small>How HBAviewer reads controller information.</small>
        </div>
        <div class="lu-s-control" style="padding-top:8px">
          <span style="color:#f5a623;font-weight:600"><?= htmlspecialchars($backend_label) ?></span>
          <small style="display:block;color:var(--text);margin-top:3px;line-height:1.4"><?= htmlspecialchars($backend_note) ?></small>
        </div>
      </div>

      <?php if ($has_sas2): ?>
      <div class="lu-s-row">
        <div class="lu-s-label">
          lsiutil Port
          <small>Run lsiutil without arguments to list ports. Usually 1.</small>
        </div>
        <div class="lu-s-control">
          <input type="number" name="port" value="<?= (int)$cfg['HBA_PORT'] ?>" min="1" max="8">
        </div>
      </div>
      <?php endif; ?>

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
      <p style="font-size:12px;color:var(--text);margin:0 0 14px">Temperature is always shown. Toggle additional panels below.</p>

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
      <label class="lu-toggle">
        <input type="checkbox" name="show_perf" <?= lu_checked((int)$cfg['SHOW_PERF']) ?>>
        <span>Performance</span>
        <small>Real-time throughput / IOPS / %util / latency graphs</small>
      </label>
    </div>

    <div class="lu-s-card">
      <h3>Advanced — Firmware Flashing</h3>
      <div class="lu-danger">
        <strong>&#9888; Danger:</strong> Flashing HBA firmware/BIOS can permanently
        <strong>brick</strong> your controller if the wrong image is used. The array
        must be <strong>stopped</strong> before flashing. The flash tools
        (sas2flash / sas3flash) are not bundled — you supply the model-correct image
        and tool. Leave this off unless you know exactly what you are doing.
      </div>
      <label class="lu-toggle">
        <input type="checkbox" name="enable_flash" <?= lu_checked((int)$cfg['ENABLE_FLASH']) ?>>
        <span>Enable firmware/BIOS flashing (advanced)</span>
        <small>adds a Firmware/BIOS Update tab to the Monitor</small>
      </label>
    </div>

    <button class="lu-btn" type="submit" name="save_hbaviewer" value="1">Save Settings First</button>
    <?php if ($saved): ?>
    <a class="lu-btn" href="/Tools/HBAviewer_Monitor" style="text-decoration:none;display:inline-block"
       onclick="return confirm('The HBA Monitor reads live information from your controller(s).\n\nThe first load can take up to 60 seconds while it queries the hardware. After you press OK, the Monitor opens and shows a \'Loading HBA information\' banner until it is ready.\n\nPress OK to continue.')">Open HBAviewer Monitor</a>
    <?php endif; ?>

  </form>
</div>
