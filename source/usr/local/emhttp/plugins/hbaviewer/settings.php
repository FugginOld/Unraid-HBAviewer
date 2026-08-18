<?PHP
/* HBAviewer Settings — full settings form.
   Reached via the HBAviewer icon card in Unraid Settings > System Settings. */

require_once __DIR__ . '/config.php';
$cfg   = lsi_config_read();
$saved = false;

// Backend detection — controller generation via sysfs + tool path lookup. Both
// are instant (no hardware enumeration), so the page never lags.
//
// Generation comes from each SCSI host's proc_name, NOT from which driver module
// is loaded, and this must stay in step with scripts/lib.sh hba_has_sas2/3. The
// merged mpt3sas driver registers SAS2 controllers under the mpt2sas personality,
// so issue #3's box has no mpt2sas module while its SAS9207-8i reports
// proc_name=mpt2sas. Keying off /sys/module called that card a SAS3 controller,
// demanded storcli for it, and hid the lsiutil Port row it actually needs.
// mpi3mr is the SAS4 driver (9600 series). It needs StorCLI2, a DIFFERENT binary
// from the classic storcli — not a newer one — so the two are probed separately
// below.
$hw = [];          // one entry per SAS host, for the read-only diagnostic row
$has_sas2 = false; // any host on the mpt2sas/mptsas personality -> bundled lsiutil
$has_sas3 = false; // any host on the mpt3sas personality        -> needs storcli
$has_sas4 = false; // any host on mpi3mr — 24G/SAS4, 9600 series -> needs StorCLI2
foreach (glob('/sys/class/scsi_host/host*/') ?: [] as $h) {
    $drv = trim((string) @file_get_contents($h . 'proc_name'));
    if (!in_array($drv, ['mpt3sas', 'mpt2sas', 'mptsas', 'mpi3mr'], true)) continue;
    if      ($drv === 'mpt3sas') { $has_sas3 = true; }
    elseif  ($drv === 'mpi3mr')  { $has_sas4 = true; }
    else                         { $has_sas2 = true; }
    $board = trim((string) @file_get_contents($h . 'board_name'));
    $fw    = trim((string) @file_get_contents($h . 'version_fw'));
    $hw[]  = ($board !== '' ? $board : 'unknown board') . " ($drv"
           . ($fw !== '' ? ", fw $fw" : '') . ')';
}
$hw_detail = $hw ? implode(' · ', $hw) : 'no mpt2sas/mpt3sas/mpi3mr hosts found';
// One implementation of this lookup, and it lives in the shell. This page used
// to carry its own four-path list against lib.sh's eight, already missing
// /usr/local/bin/storcli* -- two copies of one question that had drifted apart.
// Sourcing lib.sh runs nothing: its top level only assigns variables and
// defines functions. shell_exec is not new here; the old fallback used it too.
//
// storcli_candidates rather than find_storcli, because a box with a 9600
// typically has BOTH tools installed (the dkaser plugin ships storcli and
// storcli2 alike) and the classic one simply enumerates nothing there.
// find_storcli returns the first hit only, which would tell a 9600 owner they
// have "storcli" and never mention the tool their card actually needs. One
// shell call still answers both questions -- the list is already deduplicated
// and ordered, so the first name of each kind is the one the backend will use.
$cands = trim((string) shell_exec(
    'bash -c ". ' . __DIR__ . '/scripts/lib.sh 2>/dev/null; storcli_candidates" 2>/dev/null'
));
$storcli = $storcli2 = '';
foreach (preg_split('/\R/', $cands, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
    if (basename($p) === 'storcli2') { if ($storcli2 === '') $storcli2 = $p; }
    elseif ($storcli === '')         { $storcli  = $p; }
}

if ($has_sas4 && $storcli2 === '') {
    $backend_label = 'StorCLI2 — NOT INSTALLED';
    $backend_note  = 'A controller was found on the mpi3mr driver (SAS4, 9600 series). It needs StorCLI2 — the classic storcli cannot read these cards. The dkaser/unraid-storcli plugin ships one as storcli2.'
        . ($has_sas2 || $has_sas3 ? ' Another controller generation is also present and uses its own backend.' : '');
} elseif ($has_sas4) {
    $backend_label = 'StorCLI2';
    $backend_note  = 'SAS4 / 9600-series controller detected.'
        . ($has_sas2 || $has_sas3 ? ' Another controller generation is also present and uses its own backend.' : '')
        . ' Note the Lite StorCLI2 build has no event-log command; the full Broadcom build does.';
} elseif ($storcli !== '') {
    $backend_label = 'storcli';
    $backend_note  = $has_sas2
        ? 'storcli is installed and is tried first; the bundled lsiutil covers any SAS2 card it does not enumerate.'
        : 'SAS3 / SAS3.5 controller detected.';
} elseif ($has_sas2) {
    $backend_label = 'lsiutil (bundled)';
    $backend_note  = $has_sas3
        ? 'SAS2 controller detected. A SAS3 controller is also present and needs storcli.'
        : 'SAS2 controller detected.';
} elseif ($has_sas3) {
    $backend_label = 'storcli — NOT INSTALLED';
    $backend_note  = 'A controller was found on the mpt3sas driver, which the bundled lsiutil cannot read through. Install storcli via the dkaser/unraid-storcli plugin (Community Applications).';
} else {
    $backend_label = 'none detected';
    $backend_note  = 'No supported HBA controller (mpt2sas / mpt3sas / mpi3mr) was found.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_hbaviewer'])) {
    // Map the form (checkbox-absent = off); config_write clamps to schema.
    // config_update, not config_write: this page has no field for BAY_ROWS,
    // BAY_COLS, BAY_LOCK, BAY_WARN_TEMP or LOCATE_MAX_SECS, and a plain write
    // would reset all five to defaults — unlocking a locked map and snapping
    // the grid back to 6x4 — every time somebody toggled a tab here.
    lsi_config_update([
        'HBA_PORT'        => $_POST['port']      ?? 1,
        'ALERT_THRESHOLD' => $_POST['threshold'] ?? 76,
        'SHOW_PCIE'       => isset($_POST['show_pcie'])   ? 1 : 0,
        'SHOW_PHY'        => isset($_POST['show_phy'])    ? 1 : 0,
        'SHOW_DRIVES'     => isset($_POST['show_drives']) ? 1 : 0,
        'SHOW_EVENTS'     => isset($_POST['show_events']) ? 1 : 0,
        'SHOW_PERF'       => isset($_POST['show_perf'])   ? 1 : 0,
        // A disabled checkbox posts nothing, which the line below would read as
        // "off" and write — silently discarding a setting the user never
        // changed and cannot currently change back. While locked, keep what is
        // on disk so their choice is still there when flashing returns.
        'ENABLE_FLASH'    => LSI_FLASH_LOCKED
            ? (int) (lsi_config_read()['ENABLE_FLASH'] ?? 0)
            : (isset($_POST['enable_flash']) ? 1 : 0),
        'ENABLE_NOTIFY'   => isset($_POST['enable_notify']) ? 1 : 0,
        'PCIE_EXPECT_WIDTH' => $_POST['pcie_width'] ?? 0,
        'PCIE_EXPECT_GEN'   => $_POST['pcie_gen']   ?? 0,
    ]);
    $cfg   = lsi_config_read();
    $saved = true;
}

function lu_checked(int $val): string { return $val ? 'checked' : ''; }
?>

<style>
/* Original HBAviewer palette in the new component format. Matches the Monitor. */
#lu-settings-wrap {
    /* Chrome tokens follow Unraid's theme variables (confirmed present on
       white/black/gray/azure — see plan 021); each keeps its original literal
       as the CSS fallback so a missing variable renders exactly as before. */
    --bg:        var(--background-color, #161616);
    --surface:   var(--shade-bg-color, #1c1c1c);
    /* One step further from --surface than the page is — darker on dark themes,
       lighter on light ones. No single Unraid variable expresses that, so nudge
       --surface 8% toward the text colour, which points the right way in both. */
    --surface-2: color-mix(in srgb, var(--shade-bg-color, #232323) 92%, var(--text-color, #dddddd) 8%);
    --border:      var(--border-color, #333333);
    --border-soft: var(--border-color, #2a2a2a);
    /* ponytail: one text colour; --muted/--faint kept as aliases so the call sites stay untouched */
    --text: var(--text-color, #dddddd); --muted: var(--text-color, #dddddd); --faint: var(--text-color, #dddddd);
    --accent:#f5a623; --good:#2ecc71; --warn:#f39c12; --crit:#e74c3c;
    /* Body-text variants of the status colours. The raw --good/--warn/--crit are
       tuned as fills and badges; as TEXT they measure 1.5-2.2:1 on a light theme's
       card. Mixing 50% toward --text-color lands 4.6-10.2:1 in every theme. */
    --crit-text: color-mix(in srgb, var(--crit) 50%, var(--text-color, #dddddd));
    --good-text: color-mix(in srgb, var(--good) 50%, var(--text-color, #dddddd));
    --warn-text: color-mix(in srgb, var(--warn) 50%, var(--text-color, #dddddd));
    --mono: ui-monospace,"SF Mono","Cascadia Code",Menlo,monospace;
    /* 1000px so the two-column grid below gets ~468px per column — near the 532px
       a section had when the page was one 580px column. It also caps the grid at
       two tracks: a third would need 1112px of content box. */
    font-family: inherit; max-width: 1000px; margin: 20px auto; color: var(--text);
    background: radial-gradient(700px 300px at 85% -20%, var(--shade-bg-color, #242424) 0%, rgba(0,0,0,0) 55%), var(--bg);
    border: 1px solid var(--border-soft); border-radius: 16px; padding: 22px 24px;
}
.lu-s-card { background: linear-gradient(180deg,var(--surface-2),var(--surface)); border: 1px solid var(--border-soft); border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; }
/* Two columns that PACK, via CSS columns rather than a grid. This was a grid,
   and a grid row is as tall as its tallest card: every short section left a
   hole beside its taller neighbour (Display Panels under HBA Connection,
   Notifications beside Diagnostic Bundle, the whole right half beside
   Export/API). `break-inside: avoid` below is what makes columns safe — the
   one thing the grid was chosen to avoid, in one line, and it must stay: a
   section split across a column break can put a label and its control on
   different columns. `columns: 360px 2` caps it at two tracks and collapses to
   one below ~750px, no invented breakpoint (same intent as the auto-fit
   minmax it replaces). Sits INSIDE <form> so the POST is unaffected, and wraps
   only the cards so the Save button stays below it in normal flow.
   Advanced is deliberately OUTSIDE this container: a column layout has no
   full-width span, and that section unlocks firmware writes, so it must read
   as a footer rather than a peer of the routine toggles. Adding a section is
   now free — it packs wherever it fits, no ragged half-row to plan around. */
.lu-s-grid { columns: 360px 2; column-gap: 16px; }
.lu-s-grid > .lu-s-card { break-inside: avoid; margin: 0 0 16px; }
.lu-s-card h3 { margin: 0 0 16px; color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: 0.09em; border-bottom: 1px solid var(--border-soft); padding-bottom: 10px; display: flex; align-items: center; gap: 8px; }
.lu-s-card h3::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: var(--accent); box-shadow: 0 0 8px var(--accent); flex: 0 0 auto; }
.lu-s-row { display: flex; align-items: flex-start; gap: 16px; margin-bottom: 14px; }
.lu-s-row:last-child { margin-bottom: 0; }
.lu-s-label { flex: 0 0 180px; font-size: 13px; color: var(--text); padding-top: 8px; }
.lu-s-label small { display: block; font-size: 11px; color: var(--faint); margin-top: 3px; line-height: 1.4; }
.lu-s-control { flex: 1; }
.lu-s-control input[type=number] { width: 90px; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; color: var(--text); padding: 7px 10px; font-size: 14px; font-family: var(--mono); }
.lu-s-control input[type=number]:focus { outline: none; border-color: var(--accent); }
/* A card the maintainer has switched off. Greyed by opacity rather than by
   recolouring: the danger notice inside it keeps its own colour relationships,
   and its text stays selectable and readable by a screen reader. The heading
   dot loses its glow so a locked card is distinguishable at a glance, not only
   by reading it. pointer-events stays ON — the note must remain selectable, and
   the one interactive control is `disabled`, which is the real lock. */
.lu-s-card.is-locked { opacity: .72; }
.lu-s-card.is-locked h3::before { background: var(--faint); box-shadow: none; }
.lu-toggle { display: flex; align-items: center; gap: 10px; padding: 8px 0; cursor: pointer; }
.lu-toggle input[type=checkbox] { width: 16px; height: 16px; accent-color: var(--accent); cursor: pointer; }
.lu-toggle input[type=checkbox]:disabled { cursor: not-allowed; }
.lu-toggle span { font-size: 13px; color: var(--text); }
.lu-toggle small { font-size: 11px; color: var(--faint); margin-left: auto; }
.lu-notice { background: color-mix(in srgb, var(--good) 12%, var(--surface)); border: 1px solid color-mix(in srgb, var(--good) 30%, transparent); border-radius: 8px; color: var(--good-text); font-size: 12px; padding: 9px 14px; margin-bottom: 14px; }
.lu-danger { background: color-mix(in srgb, var(--crit) 12%, var(--surface)); border: 1px solid color-mix(in srgb, var(--crit) 36%, transparent); border-radius: 8px; color: var(--crit-text); font-size: 12px; line-height: 1.5; padding: 10px 14px; margin-bottom: 14px; }
.lu-danger strong { color: var(--crit-text); }
.lu-btn { background: var(--accent); border: none; border-radius: 6px; color: #111; font-size: 13px; font-weight: 700; padding: 9px 24px; cursor: pointer; letter-spacing: 0.03em; margin-right: 10px; }
.lu-btn:hover { background: #d9901a; }
/* The action row. Save and Open Monitor stay left where the eye lands after
   filling a form; the firmware button is pushed to the far right by
   margin-left:auto, away from the two buttons someone presses routinely. */
.lu-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 10px 0; }
/* Red, and the only red button on the page. It leads to the one screen here
   that writes to hardware, so it must not read as a peer of Save. */
.lu-btn.danger { background: var(--crit); color: #fff; margin-right: 0; margin-left: auto; }
.lu-btn.danger:hover { background: #c0392b; }
.lu-link { font-size: 12px; color: var(--accent); text-decoration: none; }
.lu-link:hover { text-decoration: underline; }
</style>

<div id="lu-settings-wrap">

  <?php if ($saved): ?>
  <div class="lu-notice">Settings saved.</div>
  <?php endif; ?>

  <form method="post">

    <div class="lu-s-grid">

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

      <div class="lu-s-row">
        <div class="lu-s-label">
          Detected Hardware
          <small>Read-only. Quote this when reporting an issue.</small>
        </div>
        <div class="lu-s-control" style="padding-top:8px">
          <span style="font-family:var(--mono);font-size:12px"><?= htmlspecialchars($hw_detail) ?></span>
        </div>
      </div>

      <?php /* SAS4 only. The StorCLI2 the storcli plugin ships is Broadcom's
               Lite build, which has no `show events` command at all — so the
               Event Log tab reports that rather than rendering an empty table.
               Broadcom's full build fixes it but cannot be bundled (proprietary)
               or even downloaded unattended (JS portal behind bot management),
               so the one manual step is the download and scripts/install_storcli2.sh
               does everything after it.
               Shown only when a SAS4 card is present, and the steps stay folded
               away once the helper's install path exists. */ ?>
      <?php if ($has_sas4): $full_sc2 = is_executable('/opt/MegaRAID/storcli2/storcli2'); ?>
      <div class="lu-s-row">
        <div class="lu-s-label">
          Firmware Event Log
          <small>SAS4 / 9600 only. Needs Broadcom's full StorCLI2.</small>
        </div>
        <div class="lu-s-control" style="padding-top:8px">
          <span style="color:<?= $full_sc2 ? '#7ac943' : '#f5a623' ?>;font-weight:600">
            <?= $full_sc2 ? 'Full StorCLI2 installed' : 'Full StorCLI2 not installed' ?>
          </span>
          <small style="display:block;color:var(--text);margin-top:3px;line-height:1.5">
            <?php if ($full_sc2): ?>
              Found at <code>/opt/MegaRAID/storcli2/storcli2</code>. The Event Log tab
              should work. Re-run the helper below after a Broadcom update, or if you
              ever replace the flash drive.
            <?php else: ?>
              The StorCLI2 that the <em>storcli</em> plugin installs is Broadcom's
              <strong>Lite</strong> build. It runs every other tab perfectly well, but
              has no event-log command, so the Event Log tab will say so. Everything
              else on this page works without doing anything below.
            <?php endif; ?>
          </small>

          <?php /* max-width + overflow-wrap throughout: this card sits in a CSS
                   multi-column grid (.lu-s-grid, columns 360px), so anything
                   that refuses to wrap does not merely overflow its own box —
                   it paints across the neighbouring column. The paths and the
                   command below are all longer than the column is wide. */ ?>
          <details style="margin-top:8px;max-width:100%">
            <summary style="cursor:pointer;color:#f5a623">
              How to install the full StorCLI2 (once per server)
            </summary>
            <div style="margin-top:8px;line-height:1.6;max-width:100%;overflow-wrap:anywhere">
              <strong>1.</strong> On any machine, download <strong>StorCLI2</strong> from
              <a href="https://docs.broadcom.com/docs/1232743171" target="_blank" rel="noreferrer">Broadcom's site</a>.
              It cannot be fetched automatically — the page is JavaScript-driven behind
              bot protection, and the tool is proprietary, so it is not shipped here.<br>

              <strong>2.</strong> Copy that <code>.zip</code> onto this server — any share
              will do, for example <code>/mnt/user/isos/</code>.<br>

              <strong>3.</strong> On <em>this server</em> (Unraid terminal, or SSH — not on
              your desktop), run:
              <?php /* pre-wrap, not the default pre: the command is ~100 characters
                       with no space to break at until the end, and a non-wrapping
                       pre sets a min-content width the flex control cannot shrink
                       below. It still copies as one line. */ ?>
              <pre style="margin:6px 0;padding:8px;font-size:12px;max-width:100%;white-space:pre-wrap;overflow-wrap:anywhere;background:var(--bg);border:1px solid var(--border-soft);border-radius:6px">bash <?= htmlspecialchars(__DIR__) ?>/scripts/install_storcli2.sh /path/to/the-file.zip</pre>

              <strong>4.</strong> Reload this page. This row should turn green, and the
              Event Log tab will start working. Nothing needs restarting.<br>

              <small style="color:var(--text-muted, #888)">
                The helper unpacks the archive, checks the binary really is StorCLI2, copies it
                to <code>/boot/config/plugins/hbaviewer/tools/</code> so it survives a reboot,
                and adds three lines to <code>/boot/config/go</code> that restore it at boot —
                <code>/opt</code> is RAM here, and the flash cannot keep the execute bit. It
                backs up <code>go</code> first and is safe to re-run. Pass <code>--no-go</code>
                to skip the boot-time part.
              </small>
            </div>
          </details>
        </div>
      </div>
      <?php endif; ?>

      <?php /* Host Link normally works this out: the slot's own ceiling is read
               from the upstream bridge, so a card in a narrower slot is judged
               against the slot rather than against itself (issues #13/#14).
               These are for boards whose bridge publishes nothing. Left at 0 on
               almost every machine. */ ?>
      <div class="lu-s-row">
        <div class="lu-s-label">
          Expected PCIe Width
          <small>0 = detect from the slot. Set only if Host Link misjudges your link; a link below this still warns.</small>
        </div>
        <div class="lu-s-control">
          <input type="number" name="pcie_width" value="<?= (int)$cfg['PCIE_EXPECT_WIDTH'] ?>" min="0" max="32">
        </div>
      </div>
      <div class="lu-s-row">
        <div class="lu-s-label">
          Expected PCIe Generation
          <small>0 = detect. 1&ndash;6, where 3 is 8.0 GT/s and 4 is 16.0 GT/s.</small>
        </div>
        <div class="lu-s-control">
          <input type="number" name="pcie_gen" value="<?= (int)$cfg['PCIE_EXPECT_GEN'] ?>" min="0" max="6">
        </div>
      </div>

      <?php if ($has_sas2): ?>
      <div class="lu-s-row">
        <div class="lu-s-label">
          lsiutil Port (fallback)
          <small>Every SAS2 card lsiutil lists is read automatically, so this normally does nothing. It is used only if that list cannot be read, and then it names the single port to fall back to. Leave it at 1 unless support asks.</small>
        </div>
        <div class="lu-s-control">
          <input type="number" name="port" value="<?= (int)$cfg['HBA_PORT'] ?>" min="1" max="8">
        </div>
      </div>
      <?php endif; ?>

      <div class="lu-s-row">
        <div class="lu-s-label">
          Badge Sensitivity
          <small>Temperature colours are fixed (Normal &le;65, Elevated 66&ndash;75, Warning 76&ndash;85, Alert 86&ndash;95, Critical &gt;95 &deg;C). This chooses the first band at which the Overview badge and dashboard tile start reporting a problem &mdash; and, when Notifications are enabled below, the point at which HBAviewer notifies you.</small>
        </div>
        <div class="lu-s-control">
          <select name="threshold">
<?php
$bands = [66 => 'Elevated (66 °C and above)', 76 => 'Warning (76 °C and above)',
          86 => 'Alert (86 °C and above)',    96 => 'Critical (above 95 °C)'];
// Select the band containing the stored value, so a legacy 80 shows "Warning".
$curr = (int) $cfg['ALERT_THRESHOLD'];
$sel  = 96; foreach (array_keys($bands) as $floor) { if ($curr < $floor) { break; } $sel = $floor; }
if ($curr < 66) $sel = 66;
foreach ($bands as $floor => $label) {
    printf('<option value="%d"%s>%s</option>', $floor, $floor === $sel ? ' selected' : '', htmlspecialchars($label));
}
?>
          </select>
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
      <h3>Notifications</h3>
      <p style="font-size:12px;color:var(--text);margin:0 0 14px">Off by default. When on, HBAviewer checks every 10 minutes and sends one notification each time a controller's health status <em>changes</em> &mdash; never a repeat while it stays the same. Delivery (browser, email, agents) follows your Unraid <a class="lu-link" href="/Settings/Notifications">Notification Settings</a>.</p>
      <label class="lu-toggle">
        <input type="checkbox" name="enable_notify" <?= lu_checked((int)$cfg['ENABLE_NOTIFY']) ?>>
        <span>Notify on health status changes</span>
        <small>uses Unraid's own notification system</small>
      </label>
    </div>

    <?php /* Pairs beside Notifications in the two-column grid rather than
             spanning: it is a routine, read-only support action, and the span
             is reserved for Advanced precisely because that section unlocks
             firmware writes and must not read as a peer of the routine
             controls. Two toggles + a button also fit a single column.

             The button uses formaction to post THIS form to bundle.php: a
             download needs its own response, a second <form> cannot be nested
             inside this one, and formaction is the native way to do it — no
             JS, and Unraid's page framework still injects its csrf_token into
             the one form on the page. Only the clicked button's name is
             submitted, so this never triggers a settings save, and the two
             checkboxes below are ignored by lsi_config_write (schema-driven).
             Neither setting is persisted: both are per-download choices. */ ?>
    <div class="lu-s-card">
      <h3>Diagnostic Bundle</h3>
      <p style="font-size:12px;color:var(--text);margin:0 0 14px">Collects everything needed to debug a controller problem &mdash; the raw storcli/lsiutil output, the sysfs state, and what HBAviewer made of both &mdash; into one archive you can attach to a <a class="lu-link" href="https://github.com/Fuggin/Unraid-HBAviewer/issues">GitHub issue</a>. Read-only: nothing is changed on your controller. Takes a few seconds.</p>
      <label class="lu-toggle">
        <input type="checkbox" name="bundle_anon" checked>
        <span>Anonymise</span>
        <small>replaces serials, WWNs, SAS addresses &amp; hostname</small>
      </label>
      <label class="lu-toggle">
        <input type="checkbox" name="bundle_smart">
        <span>Include SMART</span>
        <small>slower &mdash; ~1s per drive; sleeping drives stay asleep</small>
      </label>
      <p style="font-size:11px;color:var(--faint);margin:10px 0 14px;line-height:1.5">Anonymising keeps drive <em>models</em>, sizes, firmware versions, temperatures and error counters &mdash; hiding those would make the bundle useless. It replaces every identifier with a same-length stand-in, using one mapping for the whole bundle, so the report still hangs together. Your flash drive GUID, licence key and share names are never collected at all.</p>
      <button class="lu-btn" type="submit" name="make_bundle" value="1"
              formaction="/plugins/hbaviewer/bundle.php" formmethod="post">Generate diagnostic bundle</button>
    </div>

    <?php
    // HTTP_HOST is client-supplied (the Host: header) and not validated by
    // PHP — escape it before interpolating into markup, or a crafted Host
    // header becomes reflected XSS. Falls back to a relative URL rather than
    // rendering a broken absolute one when the header is missing.
    $host       = htmlspecialchars($_SERVER['HTTP_HOST'] ?? '', ENT_QUOTES);
    $exportBase = $host !== '' ? '//' . $host : '';
    $exportJson = $exportBase . '/plugins/hbaviewer/export.php';
    $exportProm = $exportBase . '/plugins/hbaviewer/export.php?format=prometheus';
    ?>
    <div class="lu-s-card">
      <h3>Export / API</h3>
      <p style="font-size:12px;color:var(--text);margin:0 0 14px">A read-only JSON snapshot of every controller's model, temperature, status, band and drive count &mdash; plus the same data in Prometheus text format.</p>
      <p style="margin:0 0 8px"><a class="lu-link" href="<?= $exportJson ?>" target="_blank" rel="noopener"><code><?= $exportJson ?></code></a></p>
      <p style="margin:0 0 14px"><a class="lu-link" href="<?= $exportProm ?>" target="_blank" rel="noopener"><code><?= $exportProm ?></code></a></p>
      <p style="font-size:11px;color:var(--faint);margin:0;line-height:1.5">Both URLs are session-gated, same as every other page in this plugin &mdash; a Prometheus scraper outside a logged-in webGui session <strong>cannot</strong> poll them. They work from a logged-in browser, a Homepage-style widget behind the same login, or a logged-in <code>curl</code>.</p>
    </div>

    </div><!-- /.lu-s-grid — Advanced is a full-width footer, see the CSS -->

    <div class="lu-s-card<?= LSI_FLASH_LOCKED ? ' is-locked' : '' ?>">
      <h3>Advanced — Firmware Flashing</h3>
      <?php if (LSI_FLASH_LOCKED): ?>
      <div class="lu-danger">
        <strong>&#9888; Disabled in this release.</strong>
        <p style="margin:8px 0 0"><?= htmlspecialchars(LSI_FLASH_LOCK_NOTE) ?></p>
      </div>
      <?php else: ?>
      <div class="lu-danger">
        <strong>&#9888; Danger:</strong> Flashing HBA firmware/BIOS can permanently
        <strong>brick</strong> your controller if the wrong image is used. The array
        must be <strong>stopped</strong> before flashing. The flash tools
        (sas2flash / sas3flash) are not bundled — you supply the model-correct image
        and tool. Leave this off unless you know exactly what you are doing.
      </div>
      <?php endif; ?>
      <label class="lu-toggle">
        <?php /* Disabled, not removed: the tick still shows what you had set, and
                 the save handler holds that value rather than reading the absent
                 POST field as "off". */ ?>
        <input type="checkbox" name="enable_flash" <?= lu_checked((int)$cfg['ENABLE_FLASH']) ?>
               <?= LSI_FLASH_LOCKED ? 'disabled' : '' ?>>
        <span>Enable firmware/BIOS flashing (advanced)</span>
        <small><?= LSI_FLASH_LOCKED
            ? 'unavailable until further testing — your setting is remembered'
            : 'save, then use the Firmware/BIOS Update button below' ?></small>
      </label>
    </div>

    <div class="lu-actions">
      <button class="lu-btn" type="submit" name="save_hbaviewer" value="1">Save Settings First</button>
      <?php if ($saved): ?>
      <a class="lu-btn" href="/Tools/HBAviewer_Monitor" style="text-decoration:none;display:inline-block"
         onclick="return confirm('The HBA Monitor reads live information from your controller(s).\n\nThe first load can take up to 60 seconds while it queries the hardware. After you press OK, the Monitor opens and shows a \'Loading HBA information\' banner until it is ready.\n\nPress OK to continue.')">Open HBAviewer Monitor</a>
      <?php endif; ?>
      <?php /* One of two ways in; the Monitor's tab strip carries the other,
               and both are gated on this same pair of conditions so neither can
               appear until the box below is ticked and the maintainer lock is
               off. $cfg is re-read after a save, so this appears the moment the
               box is ticked and saved.

               The href must track HBAviewer_Flash.page's Menu, which decides
               the URL root: Menu="HBAviewer" hangs the page off HBAviewer.page,
               and that is a Tools page. Pinned in flash_php_test.php. */ ?>
      <?php if (!LSI_FLASH_LOCKED && (int)$cfg['ENABLE_FLASH'] === 1): ?>
      <a class="lu-btn danger" href="/Tools/HBAviewer_Flash" style="text-decoration:none;display:inline-block">&#9888; Firmware/BIOS Update</a>
      <?php endif; ?>
    </div>

  </form>
</div>
