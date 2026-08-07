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

<link rel="stylesheet" href="/plugins/hbaviewer/chrome.css">
<style>
/* ── Firmware/BIOS flash tab ─────────────────────────────────────────────── */
.lu-flash-warn { background: color-mix(in srgb, var(--crit) 12%, var(--surface)); border: 1px solid color-mix(in srgb, var(--crit) 38%, transparent); border-radius: 10px; color: var(--crit-text); font-size: 13px; line-height: 1.5; padding: 12px 16px; margin-bottom: 14px; }
.lu-flash-warn strong { color: var(--crit-text); }
.lu-flash-array { border-radius: 10px; font-size: 13px; padding: 10px 16px; }
.lu-flash-array.ok  { background: color-mix(in srgb, var(--good) 12%, var(--surface)); border: 1px solid color-mix(in srgb, var(--good) 32%, transparent); color: var(--good-text); }
.lu-flash-array.bad { background: color-mix(in srgb, var(--warn) 12%, var(--surface)); border: 1px solid color-mix(in srgb, var(--warn) 32%, transparent); color: var(--warn-text); }
/* One column per controller instead of a stack. Nothing in a flash card is
   wider than its Step 2 file rows, so on a two-HBA box the whole right half of
   the frame was dead space with the second card pushed below the fold.
   auto-fit, not a literal 2: the controller count is whatever the box has — one
   card still fills the frame, three wrap to a second row. 420px is the floor at
   which Step 2 stops wrapping badly; under that (narrow window, phone) it
   collapses to a single column by itself, so this needs no media query.
   align-items:start because a controller that errored renders a two-line card,
   and stretching it to match a full one just makes a tall empty box. */
#flash-content { display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 16px; align-items: start; }
#flash-content .lu-card { margin-bottom: 0; }   /* gap owns the spacing now */
/* Each controller box is a .lu-card now, so its border, radius, padding, margin
   and background all come from there — .lu-fc keeps only the rules .lu-card has
   no opinion about, and stays as the hook flashCard() selects on. */
.lu-fc h4 { margin: 0 0 4px; color: var(--accent); font-size: 13px; }
.lu-fc .sub { color: var(--faint); font-size: 12px; margin: 0 0 14px; font-family: var(--mono); }
.lu-fstep { margin: 14px 0; }
.lu-fstep label.step { display: block; color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; }
/* Locked state (plan 037): while the array runs, Step 3 is dimmed and inert.
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
.lu-fc input[type=text]:focus { outline: none; border-color: var(--accent); }
.lu-fc pre { background: #0d0d0d; border: 1px solid var(--border-soft); border-radius: 6px; color: var(--muted); font-size: 11px; font-family: var(--mono); line-height: 1.4; max-height: 280px; overflow: auto; padding: 10px; margin: 8px 0 0; white-space: pre-wrap; }
.lu-fbtn { background: var(--accent); border: none; border-radius: 6px; color: #111; font-size: 12px; font-weight: 700; padding: 7px 16px; cursor: pointer; }
.lu-fbtn:hover { background: #d9901a; }
.lu-fbtn.danger { background: var(--crit); color: #fff; }
.lu-fbtn.danger:hover { background: #c0392b; }
.lu-fack { display: flex; align-items: center; gap: 8px; color: var(--text); font-size: 12px; margin: 8px 0; }
</style>

<div id="lu-wrap">

<div class="lu-tab-toolbar">
  <div class="lu-bay-head">
    <span style="font-size:12px;color:var(--text);">Firmware and BIOS flashing for the LSI/Broadcom controllers in this server</span>
  </div>
  <?php /* HBAviewer_Monitor, NOT HBAviewer: HBAviewer.page is Type="menu", a
           menu container with no content of its own, so /Tools/HBAviewer is not
           a page and the link silently went nowhere. The monitor is the xmenu
           entry under it — the same URL dashboard.php and settings.php have
           always used. */ ?>
  <a class="lu-settings-link" href="/Tools/HBAviewer_Monitor">&#8592; Back to HBAviewer</a>
</div>

<?php if (!$enableFlash): ?>
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
      <strong>&#9888; Firmware / BIOS flashing.</strong> A wrong or mismatched image
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
    /* Step 3 writes hardware, so it is greyed out and disabled while the array
       runs. Steps 1 (read-only listing) and 2 (uploads to the plugin's own tools
       dir) stay live on purpose — staging the image before the array goes down
       is what keeps the outage short. `disabled` as well as pointer-events
       because a pointer-only lock is still keyboard-reachable, which is a worse
       trap than an enabled button. Read once at render: stopping the array needs
       a page reload, same as the banner already says. */
    var lockCls  = flashArrayStopped ? '' : ' is-locked';
    var lockAttr = flashArrayStopped ? '' : ' disabled';
    var lockNote = flashArrayStopped ? '' : '<div class="lu-flock">Locked while the array is running — stop the array on the Main tab, then reload this page.</div>';
    // Unraid rejects POSTs without its CSRF token. Prefer Unraid's own fresh JS
    // global; fall back to the token we read from var.ini at render time.
    var flashCsrf = (typeof csrf_token !== 'undefined' && csrf_token) ? csrf_token : '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';

    function fesc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }

    function flashCard(i){ return document.querySelector('.lu-fc[data-ctl="'+i+'"]'); }
    // Errored controllers render a card with data-ctl but no data-chip, so the
    // lookup can succeed while the attribute is absent. Coalesce to '' — chip is
    // only ever sent as a POST field, and URLSearchParams would stringify a null
    // into the literal "null", which flash.php's alnum filter happily accepts.
    function flashChip(i){ var c=flashCard(i); return c ? (c.getAttribute('data-chip') || '') : ''; }

    window.luFlashInit = function () {
        var el = document.getElementById('flash-content');
        if (!el) return;
        fetch('/plugins/hbaviewer/ajax_info.php?type=overview')
          .then(function(r){ return r.json(); })
          .then(function(d){
            var ctls = (d && d.controllers) || [];
            if (!ctls.length) { el.innerHTML = '<div class="lu-error">No controllers detected (or backend error).</div>'; return; }
            el.innerHTML = ctls.map(function(c,i){
              if (c.error) return '<div class="lu-fc lu-card first" data-ctl="'+i+'"><h4>Controller /c'+i+'</h4><div class="lu-error">'+fesc(c.error)+'</div></div>';
              var chip = c.model || '';
              return '<div class="lu-fc lu-card first" data-ctl="'+i+'" data-chip="'+fesc(chip)+'">'
                + '<h4>Controller /c'+i+' — '+fesc(chip||'unknown chip')+'</h4>'
                + '<p class="sub">Current firmware: '+fesc(c.firmware||'?')+(c.bios?' · BIOS: '+fesc(c.bios):'')+'</p>'
                + '<div class="lu-fstep"><label class="step">Step 1 — verify the flash tool sees THIS card (controller /c'+i+' only)</label>'
                +   '<button class="lu-fbtn" onclick="luFlashList('+i+')">Verify /c'+i+'</button>'
                +   '<pre id="flash-list-'+i+'" style="display:none"></pre></div>'
                + '<div class="lu-fstep"><label class="step">Step 2 — upload the model-correct image (+ optional BIOS / tool)</label>'
                +   'Firmware (.bin/.rom): <input type="file" id="flash-fw-'+i+'"><br><br>'
                +   'BIOS (optional, .rom): <input type="file" id="flash-bios-'+i+'"><br><br>'
                +   'Flash tool if not installed (sas2flash/sas3flash): <input type="file" id="flash-tool-'+i+'"> '
                +   '<button class="lu-fbtn" onclick="luFlashUpload('+i+')">Upload</button> '
                +   '<span id="flash-up-'+i+'" style="font-size:12px"></span></div>'
                + lockNote
                + '<div class="lu-fstep'+lockCls+'"><label class="step">Step 3 — confirm &amp; flash</label>'
                +   '<label class="lu-fack"><input type="checkbox" id="flash-ack-'+i+'"'+lockAttr+'> I understand a wrong image can permanently brick this controller.</label>'
                +   'Type <strong>FLASH</strong>: <input type="text" id="flash-confirm-'+i+'" placeholder="FLASH"'+lockAttr+'> '
                +   '<button class="lu-fbtn danger" onclick="luFlashGo('+i+')"'+lockAttr+'>Flash /c'+i+'</button></div>'
                + '<pre id="flash-log-'+i+'" style="display:none"></pre>'
                + '</div>';
            }).join('');
          })
          .catch(function(){ el.innerHTML = '<div class="lu-error">Failed to load controllers.</div>'; });
    };

    window.luFlashList = function (i) {
        var pre = document.getElementById('flash-list-'+i);
        pre.style.display='block'; pre.textContent='Running…';
        fetch('/plugins/hbaviewer/flash.php', {method:'POST', body:new URLSearchParams({action:'listall', chip:flashChip(i), ctl:i, csrf_token:flashCsrf})})
          .then(function(r){ return r.text(); })
          .then(function(t){ pre.textContent = t || '(no output)'; })
          .catch(function(){ pre.textContent='Request failed.'; });
    };

    window.luFlashUpload = function (i) {
        var out = document.getElementById('flash-up-'+i); out.style.color='var(--muted)'; out.textContent='Uploading…';
        var fw=document.getElementById('flash-fw-'+i).files[0];
        var bios=document.getElementById('flash-bios-'+i).files[0];
        var tool=document.getElementById('flash-tool-'+i).files[0];
        if (!fw && !tool) { out.style.color='var(--crit-text)'; out.textContent='Choose a firmware file first.'; return; }
        var fd = new FormData(); fd.append('action','upload'); fd.append('csrf_token', flashCsrf);
        if (fw) fd.append('firmware', fw);
        if (bios) fd.append('bios', bios);
        if (tool) fd.append('tool', tool);
        fetch('/plugins/hbaviewer/flash.php', {method:'POST', body:fd})
          .then(function(r){ return r.json(); })
          .then(function(d){
            if (d.error) { out.style.color='var(--crit-text)'; out.textContent=d.error; return; }
            var c=flashCard(i);
            if (d.firmware) c.setAttribute('data-fw', d.firmware);
            if (d.bios) c.setAttribute('data-bios', d.bios);
            out.style.color='var(--good-text)';
            out.textContent='Stored: '+[d.firmware, d.bios, d.tool?('tool '+d.tool):''].filter(Boolean).join(', ');
          })
          .catch(function(){ out.style.color='var(--crit-text)'; out.textContent='Upload failed.'; });
    };

    window.luFlashGo = function (i) {
        var log = document.getElementById('flash-log-'+i);
        var c = flashCard(i);
        var fw = c.getAttribute('data-fw'); var bios = c.getAttribute('data-bios') || '';
        var ack = document.getElementById('flash-ack-'+i).checked;
        var confirmTxt = document.getElementById('flash-confirm-'+i).value;
        if (!flashArrayStopped) { alert('The array is not stopped. Stop it on the Main tab and reload this page.'); return; }
        if (!ack) { alert('Tick the acknowledgement box first.'); return; }
        if (confirmTxt !== 'FLASH') { alert('Type FLASH (all caps) to confirm.'); return; }
        if (!fw) { alert('Upload a firmware image first.'); return; }
        if (!window.confirm('FINAL confirmation: flash controller '+i+' now?\n\nThis can brick the card if the image is wrong. Do not power off or reboot until it finishes.')) return;
        log.style.display='block'; log.textContent='Starting flash…';
        fetch('/plugins/hbaviewer/flash.php', {method:'POST', body:new URLSearchParams({action:'flash', chip:flashChip(i), ctl:i, firmware:fw, bios:bios, confirm:confirmTxt, csrf_token:flashCsrf})})
          .then(function(r){ return r.json(); })
          .then(function(d){
            if (d.error) { log.textContent='Refused: '+d.error; return; }
            luFlashPoll(i);
          })
          .catch(function(){ log.textContent='Request failed.'; });
    };

    window.luFlashPoll = function (i) {
        var log = document.getElementById('flash-log-'+i);
        fetch('/plugins/hbaviewer/flash.php?action=status')
          .then(function(r){ return r.json(); })
          .then(function(d){
            log.textContent = d.log || '(waiting for output…)';
            if (d.running) { setTimeout(function(){ luFlashPoll(i); }, 2000); return; }
            if (d.done === 'success') log.textContent += '\n\n✔ Flash completed. REBOOT the server to load the new firmware. (Linux flashers update the BIOS but cannot erase it.)';
            else if (d.done === 'error') log.textContent += '\n\n✖ Flash tool exited with an error (code '+d.exit+'). Read the log above; do NOT reboot — reflash the correct image first.';
          })
          .catch(function(){ log.textContent += '\n(status poll failed — retrying)'; setTimeout(function(){ luFlashPoll(i); }, 3000); });
    };

    // The tab strip used to call this on first activation. Here the page IS the
    // tab, so it runs on load.
    luFlashInit();
</script>
<?php endif; ?>
