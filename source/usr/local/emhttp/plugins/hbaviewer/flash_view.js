    function fesc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }

    /* The firmware verdict, in full. This is the surface where a flash actually
       happens, so unlike the Overview's one-liner it shows the reason a verdict
       was withheld — a suppressed comparison is a fact about the index's limits,
       not about the card, and the user is entitled to know which. */
    function fwVerdictBlock(v) {
        if (!v || !v.status || v.status === 'unknown') return '';
        // Colour is not recomputed here: ajax_info.php already ran the one
        // verdict through fw_verdict_color() and sent the answer as v.color —
        // amber-on-terminal is a rule with exactly one home, and a second copy
        // in JS is a second place for it to quietly go wrong on the very page
        // where a flash actually happens.
        var rows = [], label = '', colour = v.color || '';
        if (v.status === 'behind')  { label = 'BEHIND'; }
        if (v.status === 'current') { label = 'CURRENT'; }
        if (v.status === 'ahead')   { label = 'AHEAD OF INDEX'; }
        if (label) {
            rows.push(['Firmware', '<strong'+(colour?' style="color:'+colour+'"':'')+'>'+label+'</strong>']);
            if (v.latest) rows.push(['Latest known IT', fesc(v.latest)]);
            if (v.branch) rows.push(['Branch', fesc(v.branch)+(v.terminal?' (terminal)':' (not final — this is a floor, not a ceiling)')]);
        } else {
            rows.push(['Firmware', '<span class="lu-muted">no verdict</span>']);
        }
        if (v.reason)     rows.push(['Why', fesc(v.reason)]);
        if (v.confidence) rows.push(['Confidence', fesc(v.confidence)+(v.index_date?' · index '+fesc(v.index_date):'')]);
        if (v.note)       rows.push(['Note', fesc(v.note)]);
        return '<div class="lu-fstep">'+rows.map(function(r){
            return '<div><label class="step" style="display:inline-block;min-width:130px">'+r[0]+'</label>'+r[1]+'</div>';
        }).join('')+'</div>';
    }

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
                + fwVerdictBlock(c.firmware_verdict)
                /* Step 1 is the TOOL, not Verify. Verify needs the flash tool, and
                   the tool upload used to live in Step 2 — so Step 1 could not be
                   completed until part of Step 2 had been, and the only way to
                   discover which tool you needed was to press Verify and read the
                   failure. The answer is knowable from the chip, so the page asks
                   flash.php for it and says so up front. Filled in async by
                   luFlashTool(); rendered empty so the card paints immediately. */
                + '<div class="lu-fstep"><label class="step">Step 1 — the flash tool for this card</label>'
                +   '<div id="flash-tool-info-'+i+'" class="lu-muted" style="font-size:12px">Checking…</div></div>'
                + '<div class="lu-fstep"><label class="step">Step 2 — verify the tool sees THIS card (controller /c'+i+' only)</label>'
                +   '<button class="lu-fbtn" onclick="luFlashList('+i+')">Verify /c'+i+'</button>'
                +   '<pre id="flash-list-'+i+'" style="display:none"></pre></div>'
                /* No heading here: luFlashDrop() renders 3a and 3b with their own
                   labels, because whether each is present is decided per file
                   kind and a single heading could not say "optional" for one and
                   not the other. */
                + '<div class="lu-fstep">'
                +   '<div id="flash-drop-'+i+'" class="lu-muted" style="font-size:12px">Checking…</div></div>'
                + lockNote
                + '<div class="lu-fstep'+lockCls+'"><label class="step">Step 4 — confirm &amp; flash</label>'
                +   '<label class="lu-fack"><input type="checkbox" id="flash-ack-'+i+'"'+lockAttr+'> I understand a wrong image can permanently brick this controller.</label>'
                +   'Type <strong>FLASH</strong>: <input type="text" id="flash-confirm-'+i+'" placeholder="FLASH"'+lockAttr+'> '
                +   '<button class="lu-fbtn danger" onclick="luFlashGo('+i+')"'+lockAttr+'>Flash /c'+i+'</button></div>'
                + '<pre id="flash-log-'+i+'" style="display:none"></pre>'
                + '</div>';
            }).join('');
            // Cards are in the DOM; now fill each Step 1. One request per card
            // rather than one for the page: a mixed box (a 9300 and a 9400) needs
            // a different answer per controller, which is the case a single
            // page-level "pick your tool" control could never get right.
            ctls.forEach(function(c,i){ if (!c.error) luFlashTool(i); });
            // One listing for the page, not one per card: the drop directory is
            // shared, and asking N times for the same answer is how the last
            // round of requests-per-card got out of hand.
            luFlashDrop(ctls);
          })
          .catch(function(){ el.innerHTML = '<div class="lu-error">Failed to load controllers.</div>'; });
    };

    /* Step 3 for every card, from one listing of the shared drop directory.
       There is no upload button anywhere on this page and there cannot be: a
       multipart POST to any .php behind Unraid's nginx never completes, because
       auth_request hands its subrequest the original Content-Length with no body
       and PHP's multipart parser waits forever on it. Measured -- the same POST
       to the same script answers in 12ms urlencoded and never answers at all as
       multipart. So the user places files and this shows what it found. */
    window.luFlashDrop = function (ctls) {
        fetch('/plugins/hbaviewer/flash.php', {method:'POST', body:new URLSearchParams({action:'dropfiles', csrf_token:flashCsrf})})
          .then(function(r){ return r.json(); })
          .then(function(d){
            var dir = d.dir || '/boot/config/plugins/hbaviewer/flash';
            var imgs = d.images || [];
            var ext = function (n) { var m = /\.([^.]+)$/.exec(n||''); return m ? m[1].toLowerCase() : ''; };
            // Split by extension so each step offers only files of its own kind.
            // LSI ships BIOS as .rom and firmware as .bin; .fw goes with firmware
            // rather than being hidden, because a file the user can see in the
            // folder and cannot select reads as a bug.
            var bioses = imgs.filter(function (f) { return ext(f.name) === 'rom'; });
            var fws    = imgs.filter(function (f) { return ext(f.name) !== 'rom'; });
            var opt = function (f) {
                return '<option value="'+fesc(f.name)+'">'+fesc(f.name)+'  ('+Math.round(f.size/1024)+' KB)</option>';
            };
            var missing = function (what, hint) {
                return '<span style="color:var(--warn-text)">No ' + what + ' found.</span>'
                     + '<div class="lu-muted" style="margin-top:6px">Put ' + hint + ' in <code>'+fesc(dir)+'</code>. '
                     + 'Then reload this page.</div>';
            };
            ctls.forEach(function(c,i){
              if (c.error) return;
              var box = document.getElementById('flash-drop-'+i);
              if (!box) return;
              // A select, not a text field: the filename is passed to the
              // flasher, and a typo here is a failed flash at best.
              var bios = bioses.length
                  ? '<select id="flash-bios-'+i+'"><option value="">— none —</option>'+bioses.map(opt).join('')+'</select>'
                  : missing('BIOS image', 'your BIOS file (<code>.rom</code>)');
              var fw = fws.length
                  ? '<select id="flash-fw-'+i+'">'+fws.map(opt).join('')+'</select>'
                  : missing('firmware image', 'your firmware file (<code>.bin</code>)');
              box.innerHTML =
                  '<label class="step">Step 3a — (optional) the model-correct BIOS file, a <code>.rom</code></label>'
                + bios
                + '<label class="step" style="margin-top:14px">Step 3b — the model-correct firmware file, a <code>.bin</code></label>'
                + fw;
            });
          })
          .catch(function(){
            ctls.forEach(function(c,i){
              var box = document.getElementById('flash-drop-'+i);
              if (box) box.textContent = 'Could not read the flash folder.';
            });
          });
    };

    /* Fill Step 1 for one card: which tool its chip needs, whether it is here,
       and the upload only when it is not. Never renders a Browse button for a
       tool that is already present -- the commonest case (9400/9500 on storcli)
       should read as "nothing to do", not as another form to fill in. */
    window.luFlashTool = function (i) {
        var box = document.getElementById('flash-tool-info-'+i);
        if (!box) return;
        fetch('/plugins/hbaviewer/flash.php', {method:'POST', body:new URLSearchParams({action:'toolinfo', chip:flashChip(i), csrf_token:flashCsrf})})
          .then(function(r){ return r.json(); })
          .then(function(t){
            if (t.status === 'roc') {
              box.innerHTML = '<span style="color:var(--crit-text)">This is a RAID-on-Chip (MegaRAID) part. '
                + 'No IT firmware exists for it at any version and it cannot be crossflashed — nothing here can flash it.</span>';
              return;
            }
            if (t.status === 'unknown' || !t.name) {
              box.innerHTML = '<span style="color:var(--warn-text)">No flash tool is known for this chip. '
                + 'Please open an issue with a diagnostic bundle rather than guessing at a tool.</span>';
              return;
            }
            if (t.status === 'found') {
              box.innerHTML = 'Flashed with <strong>'+fesc(t.name)+'</strong> — found at <code>'+fesc(t.path)+'</code>. '
                + 'Nothing to do here; continue to Step 2.';
              return;
            }
            var dir = '/boot/config/plugins/hbaviewer/flash';
            box.innerHTML = 'Flashed with <strong>'+fesc(t.name)+'</strong>, which is <strong>not installed</strong>. '
              + 'Broadcom does not permit bundling it.'
              + '<div class="lu-muted" style="margin-top:10px">Put <code>'+fesc(t.name)+'</code> in '
              + '<code>'+fesc(dir)+'</code>, named exactly <code>'+fesc(t.name)+'</code>. '
              + 'Use the Linux version — the bare binary with no extension, not the .exe or the EFI build. '
              + 'Then reload this page.</div>';
          })
          .catch(function(){ box.textContent = 'Could not determine the flash tool for this card.'; });
    };

    window.luFlashList = function (i) {
        var pre = document.getElementById('flash-list-'+i);
        pre.style.display='block'; pre.textContent='Running…';
        fetch('/plugins/hbaviewer/flash.php', {method:'POST', body:new URLSearchParams({action:'listall', chip:flashChip(i), ctl:i, csrf_token:flashCsrf})})
          .then(function(r){ return r.text(); })
          .then(function(t){ pre.textContent = t || '(no output)'; })
          .catch(function(){ pre.textContent='Request failed.'; });
    };


    window.luFlashGo = function (i) {
        var log = document.getElementById('flash-log-'+i);
        // Straight off the Step 3 selects. These used to come from data-fw /
        // data-bios attributes that the upload response set -- there is no
        // upload now, and the selects only ever contain names the server found
        // in the drop directory, so there is nothing to mistype.
        var fwEl   = document.getElementById('flash-fw-'+i);
        var biosEl = document.getElementById('flash-bios-'+i);
        var fw   = fwEl   ? fwEl.value   : '';
        var bios = biosEl ? biosEl.value : '';
        var ack = document.getElementById('flash-ack-'+i).checked;
        var confirmTxt = document.getElementById('flash-confirm-'+i).value;
        if (!flashArrayStopped) { alert('The array is not stopped. Stop it on the Main tab and reload this page.'); return; }
        if (!ack) { alert('Tick the acknowledgement box first.'); return; }
        if (confirmTxt !== 'FLASH') { alert('Type FLASH (all caps) to confirm.'); return; }
        // Either alone is a real operation -- sasNflash takes -f, -b or both -- so
        // the only thing that makes no sense is neither. flash.php re-checks this
        // and additionally refuses BIOS-only on the storcli generation, where the
        // BIOS is part of the firmware package.
        if (!fw && !bios) { alert('Select a firmware image, a BIOS image, or both. Put them in the flash folder shown in Step 3, then reload this page.'); return; }
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
