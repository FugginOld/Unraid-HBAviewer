<?PHP
/* Firmware/BIOS Update — its own page, not a tab (plan 055).
 *
 * Flashing is the one destructive action in a plugin that is otherwise entirely
 * read-only. It sat as the tenth tab in the same strip as Overview and SMART,
 * one stray click from monitoring, on a page people leave open. The tab button
 * was already coloured --crit because it did not belong there; this finishes
 * that thought rather than restating it in red.
 *
 * The flashing logic is untouched and still lives in flash.php. This file is
 * the view: markup, styling and the browser half, moved verbatim from
 * hbaviewer.php so the refactor changed no behaviour.
 *
 * Shared chrome (tokens, cards, buttons) comes from chrome.css, which the main
 * page links too. The rules below are flash-only and stay with the one page
 * that uses them.
 */

require_once __DIR__ . '/config.php';

$cfg         = lsi_config_read();
$enableFlash = $cfg['ENABLE_FLASH'];

/* Array must be stopped before flashing. Read once (cheap, no hardware); the
   flash.php preflight is the authoritative gate — this banner is advisory.
   This page reads its OWN csrf token: it posts to flash.php, and the main
   page's token does not reach here. */
$arrayStopped = false;
$csrfToken    = '';
if ($enableFlash) {
    $vi = @parse_ini_file('/var/local/emhttp/var.ini');
    $arrayStopped = is_array($vi) && strtoupper((string) ($vi['mdState'] ?? '')) === 'STOPPED';
    $csrfToken    = is_array($vi) ? (string) ($vi['csrf_token'] ?? '') : '';  // Unraid requires this on POST
}
?>

<link rel="stylesheet" href="/plugins/hbaviewer/tokens.css?v=<?= (int) @filemtime(__DIR__ . '/tokens.css') ?>">
<link rel="stylesheet" href="/plugins/hbaviewer/chrome.css?v=<?= (int) @filemtime(__DIR__ . '/chrome.css') ?>">
<style>
/* ── Firmware/BIOS flash tab ─────────────────────────────────────────────── */
.lu-flash-warn { background: color-mix(in srgb, var(--crit) 12%, var(--surface)); border: 1px solid color-mix(in srgb, var(--crit) 38%, transparent); border-radius: 10px; color: var(--crit-text); font-size: 13px; line-height: 1.5; padding: 12px 16px; margin-bottom: 14px; }
.lu-flash-warn strong { color: var(--crit-text); }
.lu-flash-array { border-radius: 10px; font-size: 13px; padding: 10px 16px; }
.lu-flash-array.ok  { background: color-mix(in srgb, var(--good) 12%, var(--surface)); border: 1px solid color-mix(in srgb, var(--good) 32%, transparent); color: var(--good-text); }
.lu-flash-array.bad { background: color-mix(in srgb, var(--warn) 12%, var(--surface)); border: 1px solid color-mix(in srgb, var(--warn) 32%, transparent); color: var(--warn-text); }
/* One column per controller instead of a stack. Nothing in a flash card is
   wider than its Step 3 file rows, so on a two-HBA box the whole right half of
   the frame was dead space with the second card pushed below the fold.
   auto-fit, not a literal 2: the controller count is whatever the box has — one
   card still fills the frame, three wrap to a second row. 420px is the floor at
   which Step 3 stops wrapping badly; under that (narrow window, phone) it
   collapses to a single column by itself, so this needs no media query.
   align-items:start because a controller that errored renders a two-line card,
   and stretching it to match a full one just makes a tall empty box. */
/* min(420px, 100%): see the note at .lu-ov-grid in chrome.css. A bare 420px
   floor makes the single collapsed column wider than a phone viewport. */
#flash-content { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(420px, 100%), 1fr)); gap: 16px; align-items: start; }
#flash-content .lu-card { margin-bottom: 0; }   /* gap owns the spacing now */
/* Each controller box is a .lu-card now, so its border, radius, padding, margin
   and background all come from there — .lu-fc keeps only the rules .lu-card has
   no opinion about, and stays as the hook flashCard() selects on. */
.lu-fc h4 { margin: 0 0 4px; color: var(--accent); font-size: 13px; }
.lu-fc .sub { color: var(--faint); font-size: 12px; margin: 0 0 14px; font-family: var(--mono); }
.lu-fstep { margin: 14px 0; }
.lu-fstep label.step { display: block; color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; }
/* Locked state (plan 037): while the array runs, Step 4 is dimmed and inert.
   COSMETIC ONLY — flash.php's flash_array_stopped() and luFlashGo's
   !flashArrayStopped alert are the actual gate. Deleting this CSS must still
   leave flashing blocked; if it ever doesn't, the safety model has inverted.
   0.45 measured 2.3-2.8:1 on the light themes (white/azure); 0.6 keeps every
   theme >= 3.3:1. .lu-flock is a SIBLING of the locked step, not a child:
   opacity applies to the whole subtree, so a child can never be less
   transparent than its parent — the plan's `.is-locked .lu-flock{opacity:1}`
   would have been a no-op and left the explanation as dim as what it explains. */
.lu-fstep.is-locked { opacity: 0.6; pointer-events: none; }
.lu-flock { color: var(--warn-text); font-size: 12px; margin: 14px 0 0; }
.lu-fc input[type=file] { color: var(--muted); font-size: 12px; }
.lu-fc input[type=text] { background: var(--bg); border: 1px solid var(--border); border-radius: 6px; color: var(--text); padding: 6px 9px; font-size: 13px; width: 120px; font-family: var(--mono); }
/* Ring restored — see the same note in settings.php. This field names the
   firmware file that is about to be written to a controller; it is the last
   place in the plugin to leave a keyboard user guessing where they are. */
.lu-fc input[type=text]:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-color: var(--accent); }
.lu-fc pre { background: #0d0d0d; border: 1px solid var(--border-soft); border-radius: 6px; color: var(--muted); font-size: 11px; font-family: var(--mono); line-height: 1.4; max-height: 280px; overflow: auto; padding: 10px; margin: 8px 0 0; white-space: pre-wrap; }
/* The buttons on this page are .lu-btn from chrome.css, which this page
   already links. It used to keep its own near-identical copy of that rule. */
.lu-fack { display: flex; align-items: center; gap: 8px; color: var(--text); font-size: 12px; margin: 8px 0; }
</style>

<div id="lu-wrap">

<?php require __DIR__ . '/icons.php'; ?>

<div class="lu-tab-toolbar">
  <div class="lu-bay-head">
    <span style="font-size:12px;color:var(--text);">Firmware and BIOS flashing for the LSI/Broadcom controllers in this server</span>
  </div>
</div>

<?php /* The Monitor's strip, exactly: a full-width .lu-tabs, navigation at the
         left, Settings pushed to the right edge by lu-tab-right. Structure
         copied rather than approximated -- the first attempt nested the pair
         inside the heading toolbar, which sizes to its content, so Settings
         landed wherever the buttons happened to end instead of on the frame.
         Same container, same rule, same right edge on both pages. */ ?>
<div class="lu-tabs">
  <?php /* HBAviewer_Monitor, NOT HBAviewer: HBAviewer.page is Type="menu", a
           menu container with no content of its own, so /Tools/HBAviewer is not
           a page and the link silently went nowhere. The monitor is the xmenu
           entry under it — the same URL dashboard.php and settings.php have
           always used. */ ?>
  <button class="lu-tab-btn" type="button"
          onclick="location.href='/Tools/HBAviewer_Monitor'">&#8592; Back to HBAviewer</button>
  <button class="lu-tab-btn lu-tab-right" type="button"
          onclick="location.href='/Settings/HBAviewer_Settings'"><svg class="lu-i" aria-hidden="true"><use href="#lu-i-settings"/></svg> Settings</button>
</div>

<?php if (LSI_FLASH_LOCKED): ?>
  <!-- Locked by the maintainer, not by the user's toggle. The menu entry is
       deliberately left alone: somebody who turned flashing on and finds the
       page gone learns nothing, while a page that explains itself does. -->
  <div class="lu-card first">
    <div class="lu-danger">
      <strong><svg class="lu-i" aria-hidden="true"><use href="#lu-i-warn"/></svg> Firmware/BIOS flashing is disabled in this release.</strong>
      <p style="margin:8px 0 0"><?= htmlspecialchars(LSI_FLASH_LOCK_NOTE) ?></p>
    </div>
    <p class="lu-muted" style="margin:0">
      Everything else on the Monitor is unaffected. Watch the plugin's changelog
      for the release that turns this back on.
    </p>
  </div>
<?php elseif (!$enableFlash): ?>
  <!-- Not merely hidden: a page that offered controls flash.php would refuse is
       worse than one that says plainly why there are none. -->
  <div class="lu-card first">
    <p class="lu-muted" style="margin:0">
      Firmware/BIOS flashing is turned off. Enable it under
      <a class="lu-settings-link" style="padding:0" href="/Settings/HBAviewer_Settings">Settings &rarr; HBAviewer</a>
      if you intend to flash a controller.
    </p>
  </div>
<?php else: ?>
<div id="tab-flash">
  <div class="lu-card first">
    <div class="lu-flash-warn">
      <strong><svg class="lu-i" aria-hidden="true"><use href="#lu-i-warn"/></svg> Firmware / BIOS flashing.</strong> A wrong or mismatched image
      will <strong>permanently brick</strong> your controller. Verify the image
      matches your exact card and chip. The array must be stopped. Proceed entirely
      at your own risk.
    </div>
    <div class="lu-flash-array <?= $arrayStopped ? 'ok' : 'bad' ?>">
      <?php if ($arrayStopped): ?>
        Array is <strong>STOPPED</strong> — safe to flash.
      <?php else: ?>
        Array is <strong>NOT stopped</strong> — stop it on the Main tab, then reload
        this page. Flashing is blocked by the server until the array is stopped.
      <?php endif; ?>
    </div>
  </div>
  <div id="flash-content"><div class="lu-loading">Loading controllers…</div></div>
</div>
<?php endif; ?>

</div><!-- #lu-wrap -->

<?php if ($enableFlash): ?>
<script>
    /* Moved verbatim from hbaviewer.php (plan 055). The one line dropped was
       `loaded['flash'] = true` — tab-loader bookkeeping with nothing to book on
       a page that is not a tab, and `loaded` does not exist here. */
    var flashArrayStopped = <?= $arrayStopped ? 'true' : 'false' ?>;
    /* Step 4 writes hardware, so it is greyed out and disabled while the array
       runs. Steps 1-3 (which tool this chip needs, the read-only listing, and
       picking an image out of the drop directory) stay live on purpose —
       staging before the array goes down is what keeps the outage short. There
       is no upload anywhere on this page; the user copies files into the drop
       directory and Step 3 lists them. `disabled` as well as pointer-events
       because a pointer-only lock is still keyboard-reachable, which is a worse
       trap than an enabled button. Read once at render: stopping the array needs
       a page reload, same as the banner already says. */
    var lockCls  = flashArrayStopped ? '' : ' is-locked';
    var lockAttr = flashArrayStopped ? '' : ' disabled';
    var lockNote = flashArrayStopped ? '' : '<div class="lu-flock">Locked while the array is running — stop the array on the Main tab, then reload this page.</div>';
    // Unraid rejects POSTs without its CSRF token. Prefer Unraid's own fresh JS
    // global; fall back to the token we read from var.ini at render time.
    var flashCsrf = (typeof csrf_token !== 'undefined' && csrf_token) ? csrf_token : '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
</script>
<script src="/plugins/hbaviewer/flash_view.js?v=<?= (int) @filemtime(__DIR__ . '/flash_view.js') ?>"></script>
<?php endif; ?>
