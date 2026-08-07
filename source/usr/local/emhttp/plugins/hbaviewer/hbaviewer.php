<?PHP
/* HBAviewer HBA Temperature Monitor — main plugin page */

require_once __DIR__ . '/config.php';

// Only config is read server-side (instant). The hardware read is deferred to
// AJAX (ajax_info.php?type=overview_html) so the page shell paints immediately
// and shows a "Loading HBA information" banner instead of blocking for storcli.
$cfg         = lsi_config_read();
$showPhy     = $cfg['SHOW_PHY'];
$showDrives  = $cfg['SHOW_DRIVES'];
$showEvents  = $cfg['SHOW_EVENTS'];
$showPerf    = $cfg['SHOW_PERF'];
$enableFlash = $cfg['ENABLE_FLASH'];
// Array must be stopped before flashing. Read the state once (cheap, no hardware);
// the flash.php preflight is the authoritative gate — this banner is advisory.
$arrayStopped = false;
$csrfToken    = '';
if ($enableFlash) {
    $vi = @parse_ini_file('/var/local/emhttp/var.ini');
    $arrayStopped = is_array($vi) && strtoupper((string) ($vi['mdState'] ?? '')) === 'STOPPED';
    $csrfToken    = is_array($vi) ? (string) ($vi['csrf_token'] ?? '') : '';  // Unraid requires this on POST
}
?>

<style>
/* ── Design tokens: original HBAviewer palette in the new component format ── */
/* One width for every tab. This was `fit-content` so the panel could hug the
   active tab's contents and Overview would not sit in dead space — but hidden
   tabs contribute nothing to max-content, so the frame resized on every tab
   switch, and a panel that changes shape as you move along the strip reads as
   the page reloading rather than as a tab changing.
   Drives is the widest tab and so sets the reference; filling the available
   width up to the cap is at least that wide without hard-coding a number that
   would only be right on one box's controller data. The cost is the dead space
   either side of Overview that fit-content was avoiding, which is the trade
   asked for: a steady frame beats a snug one. */
#lu-wrap {
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
    /* ponytail: one text colour; --muted/--faint kept as aliases so the ~40 call sites stay untouched */
    --text: var(--text-color, #dddddd); --muted: var(--text-color, #dddddd); --faint: var(--text-color, #dddddd);
    --accent:#f5a623; --accent-2:#88aaff; --track: var(--border-color, #2a2a2a);
    --good:#2ecc71; --warn:#f39c12; --crit:#e74c3c;
    /* Body-text variants of the status colours. The raw --good/--warn/--crit are
       tuned as fills and badges; as TEXT they measure 1.5-2.2:1 on a light theme's
       card. Mixing 50% toward --text-color lands 4.6-10.2:1 in every theme. */
    --crit-text: color-mix(in srgb, var(--crit) 50%, var(--text-color, #dddddd));
    --good-text: color-mix(in srgb, var(--good) 50%, var(--text-color, #dddddd));
    --warn-text: color-mix(in srgb, var(--warn) 50%, var(--text-color, #dddddd));
    --mono: ui-monospace,"SF Mono","Cascadia Code","JetBrains Mono",Menlo,monospace;
    font-family: inherit; width: 100%; max-width: 1560px; margin: 20px auto;
    color: var(--text);
    background:
        radial-gradient(900px 350px at 85% -20%, var(--shade-bg-color, #242424) 0%, rgba(0,0,0,0) 55%),
        var(--bg);
    border: 1px solid var(--border-soft); border-radius: 16px; padding: 22px 24px 26px;
    /* Stated here rather than inherited from the webGui: with width:100% and
       48px of padding, content-box would push the panel 50px past its parent
       and put a horizontal scrollbar on the page. Nothing else in this plugin
       sets box-sizing, so this must not depend on Unraid's reset. Scoped to the
       wrapper — children keep whatever they had. */
    box-sizing: border-box;
}

/* ── Tabs (underline) ────────────────────────────────────────────────────── */
.lu-tabs { display: flex; align-items: stretch; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 20px; overflow-x: auto; }
.lu-tab-btn {
    appearance: none; background: none; border: none; cursor: pointer;
    color: var(--faint); font-family: inherit; font-size: 12.5px; font-weight: 600; letter-spacing: 0.02em;
    padding: 11px 14px 12px; position: relative; white-space: nowrap; transition: color 0.15s; text-transform: none;
}
.lu-tab-btn:hover  { color: var(--muted); }
.lu-tab-btn.active { color: var(--accent); }
.lu-tab-btn.active::after { content: ""; position: absolute; left: 10px; right: 10px; bottom: -1px; height: 2px; background: var(--accent); border-radius: 2px 2px 0 0; box-shadow: 0 0 12px -1px var(--accent); }
.lu-settings-link {
    margin-left: auto; padding: 11px 14px; font-size: 12.5px; font-weight: 600; letter-spacing: 0.02em;
    color: var(--text); text-decoration: none; transition: color 0.15s;
}
.lu-settings-link:hover { color: var(--accent); }
.lu-tab-btn[data-tab="flash"] { color: var(--crit-text); }
.lu-tab-btn[data-tab="flash"]:hover, .lu-tab-btn[data-tab="flash"].active { color: var(--crit); }
.lu-tab-pane { display: none; }
.lu-tab-pane.active { display: block; }

/* ── Cards ───────────────────────────────────────────────────────────────── */
.lu-card {
    background: linear-gradient(180deg, var(--surface-2), var(--surface));
    border: 1px solid var(--border-soft); border-radius: 14px; padding: 18px 20px; margin-bottom: 16px;
    box-shadow: 0 1px 0 rgba(255,255,255,.03) inset, 0 12px 32px -24px rgba(0,0,0,.9);
}
.lu-card.first { border-radius: 14px; }
.lu-card h3 {
    margin: 0 0 14px; font-size: 11px; font-weight: 600; letter-spacing: 0.09em;
    text-transform: uppercase; color: var(--muted); display: flex; align-items: center; gap: 8px;
}
.lu-card h3::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: var(--accent); box-shadow: 0 0 8px var(--accent); flex: 0 0 auto; }
.lu-divider { border: none; border-top: 1px solid var(--border-soft); margin: 16px 0; }

/* ── Overview + temperature ring ─────────────────────────────────────────── */
/* The same auto-fit grid #flash-content and #health-content use, so all three
   card tabs lay out by one rule rather than three.
   Cards used to be width:fit-content in a centred flex row, sizing to their own
   widest row (the PCIe one, four fields on an unwrapped line). That hugged the
   content, which was right while the frame hugged it back — now the frame is a
   fixed width, so hugging just left a gutter down both sides.
   auto-fit, not auto-fill: it COLLAPSES the empty tracks, so two controllers
   take half the frame each instead of sitting in two of three columns with the
   third left empty. That distinction is the whole reason this fills.
   The 420px floor is where .lu-pcie-row starts wrapping, and it doubles as the
   responsive rule — a narrow window collapses to one column with no media
   query, exactly as the other two tabs do. */
.lu-ov-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 16px; align-items: start; }
.lu-ov-grid .lu-card { margin-bottom: 0; }   /* gap owns the spacing now */
.lu-overview-row { display: flex; align-items: center; justify-content: flex-start; gap: 22px; }
/* Gauge and its band label read as one unit — the band describes the number
   above it, which is not what a row buried in the field list conveyed. */
.lu-gauge { display: flex; flex-direction: column; align-items: center; gap: 8px; flex: 0 0 auto; }
.lu-temp-band { font-size: 11px; font-weight: 700; letter-spacing: 0.08em; font-family: var(--mono); color: var(--mark); }

/* ── The instrument tile ──────────────────────────────────────────────────
   A panel that supplies its own background to the marks sitting on it, so they
   stop depending on what the Unraid theme puts behind them. The class is added
   server-side from lsi_tile_is_light() — no Unraid theme sets
   prefers-color-scheme, so CSS alone cannot tell light from dark here.
   --td/--tl are the band's gradient stops, set inline per card; --mark is the
   colour of the number and the band label, which is white on the filled panel
   and the band's own light stop when there is no panel. That difference is
   deliberate: floating on a dark card with no panel, white loses its
   association with the arc. */
.lu-tile {
    padding: 12px 16px 13px; border-radius: 12px;
    border: 1px solid #2e2e2e; background: transparent;
    --gauge-track: #3a3a3a; --mark: var(--tl, #41d141);
}
.lu-tile.light {
    background: #6e6e6e; border-color: #5c5c5c;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.22);
    --gauge-track: #5a5a5a; --mark: #fff;
}
/* Half-circle gauge. The geometry lives in lsi_gauge_svg() (view.php) — this
   only sizes and strokes it. ponytail: no vertical sheen on the arc; an SVG
   stroke cannot carry the overlay the flat bars use, and the arc's own
   dark->light sweep already gives it internal contrast. */
.lu-arc { display: block; width: 138px; height: 78px; }
.lu-arc-bg, .lu-arc-fg { fill: none; stroke-width: 14; stroke-linecap: round; }
.lu-arc-bg { stroke: var(--gauge-track); }
.lu-arc-fg { transition: stroke-dashoffset 0.4s; }
.lu-arc-wrap { position: relative; }
.lu-arc-readout {
    position: absolute; inset: 0; display: flex; flex-direction: column;
    justify-content: flex-end; align-items: center; padding-bottom: 2px; line-height: 1;
}
.lu-arc-readout .val  { font-family: var(--mono); font-size: 30px; font-weight: 600; font-variant-numeric: tabular-nums; color: var(--mark); }
.lu-arc-readout .unit { font-size: 11px; letter-spacing: 0.05em; color: var(--mark); margin-top: 5px; }
/* The Health gauge reads "N / total" — 5+ characters against the temperature
   readout's 2 — and the arc's inner clear space is only ~100px wide (radius 80
   less the 14 stroke, at 138/200 scale). 30px overruns it; 19px does not. */
.lu-arc-readout.count .val { font-size: 19px; }
.lu-meta { flex: 1; min-width: 0; }
.lu-meta p       { margin: 4px 0; font-size: 12.5px; color: var(--faint); display: flex; justify-content: space-between; gap: 10px; border-bottom: 1px dashed var(--border-soft); padding-bottom: 3px; }
.lu-meta p span  { color: var(--text); font-weight: 500; font-family: var(--mono); font-variant-numeric: tabular-nums; }
.lu-badge {
    display: inline-flex; align-items: center; gap: 6px; margin-top: 8px;
    padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: 0.05em;
    color: var(--sc, var(--good)); background: color-mix(in srgb, var(--sc, var(--good)) 15%, transparent);
    transition: color 0.4s, background 0.4s;
}
.lu-badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
/* The health pill now lives in the meta list, where `.lu-meta p span` would
   otherwise repaint it with the field-value treatment (mono, --text) and destroy
   the status colour. The rule below outranks that one, so restate the pill's own
   typography and colour here. */
.lu-meta p span.lu-badge {
    margin-top: 0; font-family: inherit; font-weight: 700; font-size: 11px;
    color: var(--sc, var(--good));
}

/* ── PCIe row ────────────────────────────────────────────────────────────── */
/* Spacing matches the dashboard tile's footer row (dashboard.php .lu-d-foot-row)
   deliberately — the same four PCIe fields appear in both places and they should
   not read differently. Centred, not edge-justified: at the 1560px page width
   an edge-justified row flung the four items to the card's edges. */
.lu-pcie-row { display: flex; justify-content: center; gap: 16px; flex-wrap: wrap; align-items: baseline; }
.lu-pcie-item { font-size: 12px; color: var(--faint); white-space: nowrap; }
.lu-pcie-item span { color: var(--text); font-weight: 500; font-family: var(--mono); }

/* ── Tables ──────────────────────────────────────────────────────────────── */
.lu-tscroll { overflow-x: auto; }
.lu-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.lu-table th {
    text-align: left; padding: 8px 12px; color: var(--faint);
    font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em;
    border-bottom: 1px solid var(--border); white-space: nowrap;
}
.lu-table td { padding: 9px 12px; color: var(--text); border-bottom: 1px solid var(--border-soft); font-variant-numeric: tabular-nums; }
.lu-table tr:last-child td { border-bottom: none; }
.lu-table tbody tr:hover td { background: rgba(245,166,35,.05); }
.lu-table code { color: var(--accent-2); font-size: 12px; font-family: var(--mono); }

/* ── Link + error badges ─────────────────────────────────────────────────── */
.lu-link-up   { color: var(--good); font-weight: 700; font-size: 11px; letter-spacing: 0.03em; }
.lu-link-down { color: var(--crit); font-weight: 700; font-size: 11px; }
.lu-err-val   { color: var(--warn); font-weight: 600; }

/* ── PHY error baseline (plan 022) ───────────────────────────────────────── */
.lu-phy-bar   { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 10px 0 8px; }
.lu-phy-delta { color: var(--faint); font-size: 11px; font-family: var(--mono); font-variant-numeric: tabular-nums; margin-top: 2px; white-space: nowrap; opacity: 0.85; }
.lu-phy-stale { color: var(--warn-text); font-size: 13px; }

/* ── Misc ────────────────────────────────────────────────────────────────── */
.lu-error {
    background: color-mix(in srgb, var(--crit) 10%, var(--surface)); border: 1px solid color-mix(in srgb, var(--crit) 40%, transparent);
    border-radius: 8px; padding: 14px 18px; color: var(--crit-text); font-size: 13px; margin-bottom: 12px;
}
.lu-muted  { color: var(--faint); font-size: 13px; }
.lu-loading { color: var(--faint); font-size: 13px; padding: 22px 0; text-align: center; }
.lu-tab-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
/* On the per-controller tabs the toolbar sits at pane level (the cards are one
   per HBA, and the toolbar describes the tab, not the first HBA), so it no
   longer inherits .lu-card's 20px inset — restate just that, not a whole card. */
.lu-tab-pane > .lu-tab-toolbar { padding: 0 20px; }
.lu-refresh-btn {
    background: transparent; border: 1px solid var(--border); border-radius: 6px; color: var(--muted);
    font-size: 11px; font-weight: 600; padding: 5px 12px; cursor: pointer; text-transform: uppercase; letter-spacing: 0.05em; transition: border-color .15s, color .15s;
}
.lu-refresh-btn:hover { border-color: var(--accent); color: var(--accent); }

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

/* ── HBA Health tab (plan 020: five sub-indicators + a worst-of rollup) ───── */
/* One column per controller instead of a stack, the same move #flash-content
   made and for the same reason: nothing in a health card is as wide as the
   frame, so on a two-HBA box the right half was empty and the second card sat
   below the fold.
   The floor is 440px, NOT the ~492px the instrument tile needs to keep its
   gauge and band meter on one row. That was the first attempt and it produced
   a single column on the maintainer's 951px frame: two 492px tracks plus the
   gap want 1000px, so the whole point was lost on the exact box it was built
   for. Two columns with the meter wrapped under the gauge is a better trade
   than one column of full-height cards — the card grows by the meter's height,
   the page shrinks by a whole card.
   It also un-wraps by itself: at a container of 1000px or more the tracks pass
   492px again and the tile returns to one row. Below 896px it collapses to a
   single column. Both without a media query.
   align-items:start so a controller that errored, which renders a two-line
   card, does not stretch into a tall empty box beside a full one. */
#health-content { display: grid; grid-template-columns: repeat(auto-fit, minmax(440px, 1fr)); gap: 16px; align-items: start; }
#health-content .lu-card { margin-bottom: 0; }   /* gap owns the spacing now */
.lu-health-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 0 0 14px; }
.lu-health-title { font-size: 12.5px; color: var(--text); font-weight: 600; }
.lu-health-pill {
    display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 20px;
    font-size: 11px; font-weight: 700; letter-spacing: 0.03em;
}
/* Band meter: thermal only — the one continuous metric with meaningful bands.
   Segments sized to the plan-018 cut-points (65/75/85/95) over a 0-110C scale:
   65/110, 10/110, 10/110, 10/110, 15/110 as flex-grow ratios. */
.lu-band-meter { margin: 0 0 18px; }
.lu-band-track { position: relative; display: flex; height: 10px; border-radius: 6px; overflow: hidden; background: var(--gauge-track, var(--track)); }
.lu-band-seg { display: block; height: 100%; }
/* Each band is a dark->light gradient so the segment carries its own internal
   contrast (see lsi_temp_gradient in view.php). The `flex` weights below are
   UNCHANGED by plan 030 and must stay in step with the label percentages
   emitted by ajax_info.php — both encode the same band edges. */
.lu-band-seg.s0 { flex: 65; background: linear-gradient(90deg, #0f7a1a, #41d141); }
.lu-band-seg.s1 { flex: 10; background: linear-gradient(90deg, #b8890a, #f5d020); }
.lu-band-seg.s2 { flex: 10; background: linear-gradient(90deg, #a85410, #f09428); }
.lu-band-seg.s3 { flex: 10; background: linear-gradient(90deg, #9c1810, #e8443a); }
.lu-band-seg.s4 { flex: 15; background: linear-gradient(90deg, #6b0f0c, #b82820); }
/* The vertical sheen the Unraid bars carry, laid over all five segments at
   once rather than repeated on each. */
.lu-band-track::after {
    content: ""; position: absolute; inset: 0; pointer-events: none;
    background: linear-gradient(180deg, rgba(255,255,255,.26), rgba(255,255,255,0) 55%, rgba(0,0,0,.13));
}
.lu-band-marker {
    position: absolute; top: -3px; width: 2px; height: 16px; background: #fff; z-index: 1;
    box-shadow: 0 0 4px rgba(0,0,0,.6); transform: translateX(-1px);
}
/* Labels sit at their TRUE percentage of the 0-110 scale (set inline per-span
   by the renderer), not spread evenly — six evenly-spaced labels over
   unevenly-sized segments put "85" under the 65 boundary. These percentages
   must stay in step with the `flex` weights on .lu-band-seg above; both
   encode the same band edges (65/75/85/95), just in different files. */
.lu-band-labels { position: relative; height: 12px; font-size: 10px; color: var(--faint); margin-top: 4px; font-family: var(--mono); }
.lu-band-labels span { position: absolute; transform: translateX(-50%); }
.lu-band-labels span:first-child { transform: none; }             /* 0 flush left */
.lu-band-labels span:last-child  { transform: translateX(-100%); } /* 110 flush right */
/* Gauge + band meter share one instrument tile: the gauge is the summary, the
   meter is the one continuous metric behind it, and both need the panel's own
   background rather than the theme's. */
.lu-health-tile { display: flex; align-items: center; gap: 22px; flex-wrap: wrap; margin: 0 0 16px; }
.lu-health-tile .lu-band-meter { flex: 1 1 260px; margin: 0; }
.lu-tile.light .lu-band-labels { color: #fff; }
.lu-indicator-rows { display: flex; flex-direction: column; gap: 2px; }
/* Wraps so the hint line drops below label+value. column-gap stays 10px (the
   dot/icon/label rhythm); row-gap is tight so the hint reads as part of the row
   above it, not as a row of its own. */
.lu-indicator-row { display: flex; align-items: center; flex-wrap: wrap; column-gap: 10px; row-gap: 1px; padding: 7px 2px; border-bottom: 1px dashed var(--border-soft); font-size: 12.5px; }
.lu-indicator-row:last-child { border-bottom: none; }
/* A dot again as of plan 032 — but the GRADIENT FILL IS LOAD-BEARING, not
   decoration, so it survived the shape change. Flat status colours were measured
   against the #e8e8e8 white-theme card and three of the five fail the 3:1 floor
   for a small graphical object (ok #0ca30c 2.74, watch #fab219 1.50, warning
   #ec835a 2.15). A two-layer gradient carries its own internal contrast and stays
   legible at 8px on any theme surface. Do not "simplify" this to a solid colour.
   --gd/--gl set inline per row from lsi_health_gradient(). */
.lu-ind-dot {
    width: 8px; height: 8px; border-radius: 50%; flex: 0 0 auto;
    background: linear-gradient(180deg, rgba(255,255,255,.26), rgba(255,255,255,0) 55%, rgba(0,0,0,.13)),
                linear-gradient(90deg, var(--gd), var(--gl));
}
/* Tabler glyph between the dot and the label. Inherits the label's ink so the
   icon reads as part of the label, not as a second status signal — the dot is
   the only thing that carries state. */
.lu-ind-icon { width: 15px; height: 15px; flex: none; color: var(--faint); fill: none; stroke: currentColor; }
.lu-indicator-label { color: var(--faint); flex: 1; }
.lu-indicator-value { color: var(--text); font-family: var(--mono); font-variant-numeric: tabular-nums; text-align: right; }
/* What the value MEANS, on its own line under it — right-aligned, so it hangs
   off the value it explains rather than off the label. The 2px right padding
   matches .lu-indicator-row's, keeping it flush with the value above it.
   Dimmed by opacity, not colour: --text/--muted/--faint are the same Unraid
   theme variable, so a colour swap here would be a no-op. */
.lu-ind-hint { flex: 0 0 100%; text-align: right; font-size: 11px; line-height: 1.35; color: var(--faint); opacity: .62; }

/* ── Drive bay map (plan 047, redesigned to the 1b handoff) ───────────────
   Colour is the signal, not decoration: a bay stays neutral until something
   needs attention, so the two that do are the only two your eye lands on.
   THEME NOTE. The handoff's status colours are used verbatim — they are signal
   and must mean the same thing everywhere. Its surface and text hexes are NOT:
   they are a dark-theme palette, and this plugin renders inside Unraid's white
   and azure themes too, where a hardcoded #14181d panel would be an unreadable
   hole. Surfaces, text and borders therefore keep the plugin's existing theme
   variables, and the state tints are mixed over whatever surface the theme
   gives us. The handoff explicitly asks for this ("prefer the existing token
   over the raw hex — the intent matters more than the literal value").

   Fixed 236px columns, per the handoff: the grid reads as a chassis, and the
   panel scrolls sideways rather than squeezing cells. This deliberately
   replaces the earlier expand-to-fill behaviour. */
.lu-bay-legend { display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
    padding: 0 2px 16px; margin-bottom: 16px; border-bottom: 1px solid var(--border-soft); }
.lu-bay-lg { display: flex; align-items: center; gap: 7px;
    font: 500 10.5px/1 system-ui, sans-serif; color: var(--faint); letter-spacing: .02em; }
.lu-bay-lg i { width: 9px; height: 9px; border-radius: 2px; }
.lu-bay-lg i.dashed { background: transparent; border: 1px dashed var(--border-soft); }
.lu-bay-scroll { overflow-x: auto; }
/* minmax, not a flat 236px: the cards grow to fill whatever width the frame
   has — which changes with the tab strip, since "Firmware/BIOS Update" is a
   wide label and hiding it narrows the page — while 236px stays a hard floor
   so a 12-column map still overflows into .lu-bay-scroll instead of squeezing
   every card to unreadable. 1fr alone would do the second thing. */
.lu-bay-grid { display: grid; grid-template-columns: repeat(var(--bay-cols, 4), minmax(236px, 1fr)); gap: 10px; margin: 0 0 18px; }

/* The rail is an inset shadow rather than a child element, so it follows the
   radius and the cell needs no extra DOM. --rail is set per state below. */
.lu-bay-cell {
    border-radius: 6px; overflow: hidden; cursor: pointer;
    background: var(--surface-2); border: 1px solid var(--border-soft);
    box-shadow: inset 3px 0 0 var(--rail, transparent);
    transition: background .16s ease, border-color .16s ease;
}
.lu-bay-cell.st-ok      { --rail: #3fb950; }
.lu-bay-cell.st-warn    { --rail: #d29922; border-color: #d2992266; background: color-mix(in srgb, #d29922 9%,  var(--surface-2)); }
.lu-bay-cell.st-fail    { --rail: #f85149; border-color: #f8514966; background: color-mix(in srgb, #f85149 11%, var(--surface-2)); }
.lu-bay-cell.st-rebuild { --rail: #58a6ff; border-color: #58a6ff66; background: color-mix(in srgb, #58a6ff 11%, var(--surface-2)); }
.lu-bay-cell.st-nodata  { --rail: #6e7681; border-color: #6e768166; }
/* Selection is deliberately NOT a status colour — it would be ambiguous with
   health. Mixed from the theme's own ink so it shows on light themes too,
   where the handoff's flat white-at-30% would vanish. */
.lu-bay-cell.sel    { border-color: color-mix(in srgb, var(--text) 35%, transparent); }
.lu-bay-cell.target { border-style: dashed; border-color: color-mix(in srgb, var(--accent) 55%, transparent); }
.lu-bay-cell.empty  { background: transparent; border: 1px dashed var(--border-soft); box-shadow: none;
    min-height: 140px; display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 6px; }
.lu-bay-eid   { font: 600 9.5px/1 var(--mono); color: var(--faint); opacity: .55; letter-spacing: .04em; }
.lu-bay-eword { font: 500 10px/1 system-ui, sans-serif; color: var(--faint); opacity: .4; letter-spacing: .12em; }

.lu-bay-body { padding: 10px 12px 11px; }
.lu-bay-id   { display: flex; align-items: center; gap: 7px; margin-bottom: 8px; }
.lu-bay-slot { font: 600 9.5px/1 var(--mono); color: var(--faint); letter-spacing: .04em;
    background: color-mix(in srgb, var(--text) 7%, transparent); padding: 3px 5px; border-radius: 3px; }
/* The anchor of the card: the largest mono element, because finding the bay for
   a named device is what this screen is for. */
.lu-bay-dev  { font: 600 14px/1 var(--mono); color: var(--text); letter-spacing: -.01em; }
.lu-bay-stat { margin-left: auto; font: 600 8.5px/1 system-ui, sans-serif;
    padding: 3px 5px; border-radius: 3px; letter-spacing: .08em; white-space: nowrap; }
.lu-bay-cap  { display: flex; align-items: baseline; gap: 8px; margin-bottom: 7px; }
.lu-bay-capv { font: 600 16px/1 system-ui, sans-serif; color: var(--text); letter-spacing: -.02em; }
.lu-bay-capu { font: 400 9.5px/1 system-ui, sans-serif; color: var(--faint); opacity: .8; letter-spacing: .06em; }
/* Normal temperatures are grey, not green: a green number reads as a signal and
   there is nothing to signal. Only the rail and the chip carry "healthy". */
.lu-bay-temp { margin-left: auto; font: 600 11.5px/1 var(--mono); color: var(--faint); }
.lu-bay-track { height: 3px; border-radius: 2px; overflow: hidden; margin-bottom: 11px;
    background: color-mix(in srgb, var(--text) 7%, transparent); }
.lu-bay-fill  { height: 100%; border-radius: 2px; }
.lu-bay-fill.rebuild {
    background-image: repeating-linear-gradient(115deg, #58a6ff 0 5px, rgba(88,166,255,.35) 5px 10px);
    background-size: 14px 100%; animation: lu-rebuild .7s linear infinite;
}
@keyframes lu-rebuild { from { background-position: 0 0 } to { background-position: 14px 0 } }
/* The stripe stays, the movement goes — the pattern is what distinguishes a
   rebuild from a flat bar, so it must survive the preference. */
@media (prefers-reduced-motion: reduce) { .lu-bay-fill.rebuild { animation: none; } }
/* One left edge for every value, so the eye scans a column instead of hunting
   centred text. Nothing in the cell is centre-aligned. */
/* Four tracks, so UNRAID and PORT can sit side by side and save a row on every
   card. The second label track is `auto` rather than another 42px: PORT is a
   narrower word than UNRAID, and spending the difference on the value keeps
   "Port 10" off the ellipsis at the 236px minimum cell width.
   `wide` opts a pair out and gives it the whole row. Forcing the label back to
   column 1 is what starts the new row — without it the grid would flow MODEL
   into the third track alongside PORT. It also keeps the layout correct for a
   drive with no Unraid role, where PORT is alone on the first row. */
.lu-bay-ref { display: grid; grid-template-columns: 42px 1fr auto 1fr;
    column-gap: 8px; row-gap: 4px; align-items: baseline; }
.lu-bay-lbl.wide { grid-column: 1; }
.lu-bay-val.wide { grid-column: 2 / -1; }
.lu-bay-lbl { font: 500 8.5px/1.4 system-ui, sans-serif; color: var(--faint); opacity: .65; letter-spacing: .09em; }
.lu-bay-val { font: 400 10.5px/1.4 var(--mono); color: var(--faint);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lu-bay-val.dim { opacity: .8; }

.lu-bay-tray { display: flex; flex-wrap: wrap; gap: 6px; }
.lu-bay-chip {
    border: 1px solid var(--border-soft); border-radius: 6px; padding: 5px 9px;
    font-size: 11px; font-family: var(--mono); cursor: pointer; background: var(--surface-2);
}
.lu-bay-chip.sel { outline: 2px solid var(--accent); outline-offset: -2px; }
.lu-bay-chip.dead { cursor: not-allowed; opacity: .45; }
.lu-bay-dims { display: flex; align-items: center; gap: 8px; font-size: 12px; margin: 0 0 14px; color: var(--faint); }
.lu-bay-dims input { width: 58px; padding: 4px 6px; background: var(--surface); color: var(--text);
    border: 1px solid var(--border-soft); border-radius: 6px; font-family: var(--mono); }
/* Locked: still fully readable, just inert. Dimming the map would punish the
   state you are meant to leave it in. */
.lu-bay-locked .lu-bay-cell, .lu-bay-locked .lu-bay-chip { cursor: default; }
/* The tab description and the how-to-place hint read as one sentence continuing
   across the line, so they share a flex child. It wraps rather than pushing
   Refresh off the right on a narrow window. */
.lu-bay-head { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; min-width: 0; }
.lu-bay-hint { font-size: 12px; color: var(--muted); }
/* Drag and drop. `grab` on anything that can be picked up, and a solid outline
   on whatever is under the pointer — a drop with no target feedback is a guess
   about where the drive is going to land. */
.lu-bay-cell[draggable="true"], .lu-bay-chip[draggable="true"] { cursor: grab; }
.lu-bay-cell[draggable="true"]:active, .lu-bay-chip[draggable="true"]:active { cursor: grabbing; }
.lu-bay-cell.drop { outline: 2px solid #58a6ff; outline-offset: -2px; }
.lu-bay-tray.drop { outline: 2px dashed #58a6ff; outline-offset: 2px; border-radius: 6px; }
/* Locate (plan 048). The blinking bay pulses so the screen and the rack agree
   about which drive is being pointed at. Motion is the whole signal here, so
   under prefers-reduced-motion it becomes a steady outline rather than nothing
   — the same trade the rebuild stripe makes. */
.lu-bay-loc { width: 100%; margin-top: 9px; padding: 3px 0; font-size: 9.5px; }
/* The button blinks while the drive does, so the screen and the rack are
   telling you the same thing. Motion is the signal, so reduced-motion keeps a
   steady highlight rather than dropping to nothing. */
.lu-refresh-btn.locating { border-color: #58a6ff; color: #58a6ff; animation: lu-locate-blink 1s steps(1, end) infinite; }
@keyframes lu-locate-blink {
    0%, 49%   { background: color-mix(in srgb, #58a6ff 22%, transparent); }
    50%, 100% { background: transparent; }
}
.lu-bay-cell.locating { animation: lu-locate-pulse 1s ease-in-out infinite; }
@keyframes lu-locate-pulse {
    0%, 100% { box-shadow: inset 3px 0 0 var(--rail, transparent); }
    50%      { box-shadow: inset 3px 0 0 var(--rail, transparent), 0 0 0 2px #58a6ff; }
}
@media (prefers-reduced-motion: reduce) {
    .lu-bay-cell.locating { animation: none; box-shadow: inset 3px 0 0 var(--rail, transparent), 0 0 0 2px #58a6ff; }
    .lu-refresh-btn.locating { animation: none; background: color-mix(in srgb, #58a6ff 22%, transparent); }
}

/* ── Performance tab ─────────────────────────────────────────────────────── */
/* One .lu-card per controller — spacing comes from .lu-card's margin-bottom;
   .lu-perf-ctl survives only as the hook for the heading below. */
.lu-perf-ctl h4 { margin: 0 0 10px; color: var(--accent); font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; }
.lu-perf-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
.lu-perf-cell { background: var(--bg); border: 1px solid var(--border-soft); border-radius: 10px; padding: 9px 12px 6px; }
.lu-perf-cell .cap { font-size: 10px; color: var(--faint); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; display: flex; justify-content: space-between; align-items: baseline; }
.lu-perf-cell .cap b { color: var(--text); font-weight: 600; font-size: 13px; font-family: var(--mono); font-variant-numeric: tabular-nums; }
.lu-perf-canvas { position: relative; height: 88px; }
</style>

<div id="lu-wrap">

<!-- ── HBA Health row icons ──────────────────────────────────────────────────
     Icons are Tabler Icons (https://tabler.io/icons), MIT licensed. Paths are
     verbatim from tabler/tabler-icons: temperature, plug-connected, server-2,
     topology-star-3, cpu. Keep this notice with the sprite.

     Emitted HERE, once, and NOT from ajax_info.php: that file re-renders the
     Health tab on every poll and its HTML replaces the pane's contents, so a
     sprite defined there would be re-inserted each refresh — duplicate DOM ids
     with <use> resolving against whichever copy won. Parsed once here, it
     persists across every poll.

     Ids are `lu-i-` prefixed because the plugin renders inside Unraid's webGui
     DOM, not a standalone page; unprefixed ids can collide with the shell's own
     markup. ajax_info.php's row loop maps indicator keys to these ids. -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <symbol id="lu-i-thermal" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M10 13.5a4 4 0 1 0 4 0v-8.5a2 2 0 0 0 -4 0v8.5" />
    <path d="M10 9l4 0" />
  </symbol>

  <symbol id="lu-i-link" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M7 12l5 5l-1.5 1.5a3.536 3.536 0 1 1 -5 -5l1.5 -1.5" />
    <path d="M17 12l-5 -5l1.5 -1.5a3.536 3.536 0 1 1 5 5l-1.5 1.5" />
    <path d="M3 21l2.5 -2.5" />
    <path d="M18.5 5.5l2.5 -2.5" />
    <path d="M10 11l-2 2" />
    <path d="M13 14l-2 2" />
  </symbol>

  <symbol id="lu-i-topology" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M3 7a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v2a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3v-2" />
    <path d="M3 15a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v2a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3l0 -2" />
    <path d="M7 8l0 .01" />
    <path d="M7 16l0 .01" />
    <path d="M11 8h6" />
    <path d="M11 16h6" />
  </symbol>

  <symbol id="lu-i-hostlink" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M10 19a2 2 0 1 0 -4 0a2 2 0 0 0 4 0" />
    <path d="M18 5a2 2 0 1 0 -4 0a2 2 0 0 0 4 0" />
    <path d="M10 5a2 2 0 1 0 -4 0a2 2 0 0 0 4 0" />
    <path d="M6 12a2 2 0 1 0 -4 0a2 2 0 0 0 4 0" />
    <path d="M18 19a2 2 0 1 0 -4 0a2 2 0 0 0 4 0" />
    <path d="M14 12a2 2 0 1 0 -4 0a2 2 0 0 0 4 0" />
    <path d="M22 12a2 2 0 1 0 -4 0a2 2 0 0 0 4 0" />
    <path d="M6 12h4" />
    <path d="M14 12h4" />
    <path d="M15 7l-2 3" />
    <path d="M9 7l2 3" />
    <path d="M11 14l-2 3" />
    <path d="M13 14l2 3" />
  </symbol>

  <symbol id="lu-i-controller" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M5 6a1 1 0 0 1 1 -1h12a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-12a1 1 0 0 1 -1 -1l0 -12" />
    <path d="M9 9h6v6h-6l0 -6" />
    <path d="M3 10h2" />
    <path d="M3 14h2" />
    <path d="M10 3v2" />
    <path d="M14 3v2" />
    <path d="M21 10h-2" />
    <path d="M21 14h-2" />
    <path d="M14 21v-2" />
    <path d="M10 21v-2" />
  </symbol>
</svg>

<!-- ── Tab bar ───────────────────────────────────────────────────────────── -->
<div class="lu-tabs">
  <button class="lu-tab-btn active" data-tab="overview" onclick="luTab('overview')">Overview</button>
  <button class="lu-tab-btn" data-tab="health" onclick="luTab('health')">HBA Health</button>
  <?php if ($showPhy):    ?><button class="lu-tab-btn" data-tab="phy"    onclick="luTab('phy')">PHY Health</button><?php endif; ?>
  <?php if ($showDrives): ?><button class="lu-tab-btn" data-tab="drives" onclick="luTab('drives')">Drives</button><?php endif; ?>
  <?php /* Same payload as Drives, arranged as the chassis — so it follows the
           same toggle rather than adding a second setting for one data source. */ ?>
  <?php if ($showDrives): ?><button class="lu-tab-btn" data-tab="baymap" onclick="luTab('baymap')">Array Map</button><?php endif; ?>
  <button class="lu-tab-btn" data-tab="smart" onclick="luTab('smart')">SMART</button>
  <?php if ($showEvents): ?><button class="lu-tab-btn" data-tab="events" onclick="luTab('events')">Event Log</button><?php endif; ?>
  <?php if ($showPerf):   ?><button class="lu-tab-btn" data-tab="perf"   onclick="luTab('perf')">Performance</button><?php endif; ?>
  <?php if ($enableFlash): ?><button class="lu-tab-btn" data-tab="flash" onclick="luTab('flash')">Firmware/BIOS Update</button><?php endif; ?>
  <a class="lu-settings-link" href="/Settings/HBAviewer_Settings">&#9881; Settings</a>
</div>

<!-- ── Overview tab (loaded via AJAX; banner shows until hardware read done) ─ -->
<div id="tab-overview" class="lu-tab-pane active">
  <div id="overview-content"><div class="lu-loading">Loading HBA information… (first read can take up to 60 seconds)</div></div>
</div>

<!-- ── HBA Health tab (five sub-indicators + a worst-of rollup; no config toggle) -->
<div id="tab-health" class="lu-tab-pane">
  <div class="lu-tab-toolbar">
    <span style="font-size:12px;color:var(--text);">Thermal, link integrity, topology, host link, and read health — each judged independently</span>
    <button class="lu-refresh-btn" onclick="luReloadTab('health')">Refresh</button>
  </div>
  <div id="health-content"><div class="lu-loading">Loading…</div></div>
</div>

<!-- ── PHY Health tab ────────────────────────────────────────────────────── -->
<?php if ($showPhy): ?>
<div id="tab-phy" class="lu-tab-pane">
  <div class="lu-tab-toolbar">
    <span style="font-size:12px;color:var(--text);">SAS link status, speed, and error counters per physical port</span>
    <button class="lu-refresh-btn" onclick="luReloadTab('phy')">Refresh</button>
  </div>
  <div id="phy-content"><div class="lu-loading">Loading…</div></div>
</div>
<?php endif; ?>

<!-- ── Drives tab ────────────────────────────────────────────────────────── -->
<?php if ($showDrives): ?>
<div id="tab-drives" class="lu-tab-pane">
  <div class="lu-tab-toolbar">
    <span style="font-size:12px;color:var(--text);">Devices attached to the HBA</span>
    <button class="lu-refresh-btn" onclick="luReloadTab('drives')">Refresh</button>
  </div>
  <div id="drives-content"><div class="lu-loading">Loading…</div></div>
</div>
<?php endif; ?>

<!-- ── Array Map tab (plan 047): the same drives, arranged as the chassis ─── -->
<?php if ($showDrives): ?>
<div id="tab-baymap" class="lu-tab-pane">
  <div class="lu-tab-toolbar">
    <!-- Both spans wrap in one flex child so the toolbar still has exactly two,
         and space-between keeps Refresh pinned right as the hint text changes. -->
    <div class="lu-bay-head">
      <span style="font-size:12px;color:var(--text);">Where each drive physically sits — you place them once, the map remembers</span>
      <span id="bay-hint" class="lu-bay-hint"></span>
    </div>
    <button class="lu-refresh-btn" onclick="luBayFetch()">Refresh</button>
  </div>
  <div id="baymap-content"><div class="lu-loading">Loading…</div></div>
</div>
<?php endif; ?>

<!-- ── Event Log tab ─────────────────────────────────────────────────────── -->
<?php if ($showEvents): ?>
<div id="tab-events" class="lu-tab-pane">
  <div class="lu-tab-toolbar">
    <span style="font-size:12px;color:var(--text);">HBA firmware event log (newest first)</span>
    <span>
      <button class="lu-refresh-btn" onclick="luCopy('events', this)">Copy</button>
      <button class="lu-refresh-btn" onclick="luReloadTab('events')">Refresh</button>
    </span>
  </div>
  <div id="events-content"><div class="lu-loading">Loading…</div></div>
</div>
<?php endif; ?>

<!-- ── SMART tab (all drives, collected in the background) ────────────────── -->
<div id="tab-smart" class="lu-tab-pane">
  <div class="lu-card first">
    <div class="lu-tab-toolbar">
      <span style="font-size:12px;color:var(--text);">Per-drive SMART health — collected in the background (safe: never wakes a standby drive)</span>
      <button class="lu-refresh-btn" onclick="luSmartAll(true)">Refresh</button>
    </div>
    <div id="smart-content"><div class="lu-loading">Loading…</div></div>
  </div>
</div>

<!-- ── Performance tab (real-time graphs; in-browser history only) ────────── -->
<?php if ($showPerf): ?>
<div id="tab-perf" class="lu-tab-pane">
  <div class="lu-tab-toolbar">
    <span style="font-size:12px;color:var(--text);">Real-time throughput / IOPS / %util / latency / PHY-error-rate / temp &middot; sampled ~2s in your browser (last ~5&nbsp;min; resets on reload)</span>
  </div>
  <div id="perf-content"><div class="lu-loading">Waiting for first samples…</div></div>
</div>
<?php endif; ?>

<!-- ── Firmware/BIOS Update tab (opt-in; hidden unless ENABLE_FLASH) ──────── -->
<?php if ($enableFlash): ?>
<div id="tab-flash" class="lu-tab-pane">
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

<?php if ($showPerf): ?><script src="/plugins/hbaviewer/chart.umd.min.js"></script><?php endif; ?>
<script>
(function () {
    var REFRESH_MS = 60000;
    var timer;
    var smartTimer;
    var loaded = {};

    /* ── Tab switching ────────────────────────────────────────────────────── */
    window.luTab = function (name) {
        if (window.luMetricsStop) luMetricsStop();   // pause perf polling on any switch
        document.querySelectorAll('.lu-tab-btn').forEach(function (b) {
            b.classList.toggle('active', b.dataset.tab === name);
        });
        document.querySelectorAll('.lu-tab-pane').forEach(function (p) {
            p.classList.toggle('active', p.id === 'tab-' + name);
        });
        if (name === 'smart') {
            luSmartAll(false);
        } else if (name === 'flash') {
            if (!loaded['flash']) luFlashInit();
        } else if (name === 'perf') {
            luMetricsStart();
        } else if (name === 'baymap') {
            // JSON, not an HTML fragment, so it never goes through luReloadTab.
            if (!luBay.data) luBayFetch();
        } else if (name !== 'overview' && !loaded[name]) {
            luReloadTab(name);
        }
    };

    /* ── Load / reload a tab's content via AJAX ───────────────────────────── */
    window.luReloadTab = function (name) {
        var el = document.getElementById(name + '-content');
        if (!el) return;
        el.innerHTML = '<div class="lu-loading">Loading…</div>';
        fetch('/plugins/hbaviewer/ajax_info.php?type=' + name)
            .then(function (r) { return r.text(); })
            .then(function (html) {
                el.innerHTML = html;
                loaded[name] = true;
                // The fragment carries the state at render time; this catches a
                // locate that started or expired since (plan 048).
                if (name === 'drives' && window.luLocateSync) luLocateSync();
            })
            .catch(function () {
                el.innerHTML = '<div class="lu-error">Request failed.</div>';
            });
    };

    /* ── PHY tab: snapshot one controller's error counters as the baseline ────
       Confirmed first, because it discards the previous reference point. The
       server re-reads the hardware rather than trusting anything sent from
       here, so this only picks the controller and reloads the tab. */
    window.luPhyBaseline = function (ctl, btn) {
        if (!confirm('Set the PHY error baseline for controller /c' + ctl + ' to its current counters?\n\n'
                   + 'Everything the tab shows as Δ and /hr is measured from this moment. '
                   + 'Any existing baseline for this controller is replaced.')) return;
        var label = btn.textContent;
        btn.disabled = true; btn.textContent = 'Working…';
        fetch('/plugins/hbaviewer/phy_baseline.php', {
            method: 'POST',
            body: new URLSearchParams({reset_baseline: ctl, csrf_token: flashCsrf})
        })
            .then(function (r) { return r.text(); })
            .then(function (t) {
                if (t.trim() === 'ok') { luReloadTab('phy'); return; }
                btn.disabled = false; btn.textContent = label;
                alert('Baseline not set: ' + t);
            })
            .catch(function () { btn.disabled = false; btn.textContent = label; });
    };

    /* ── SMART tab: poll the background collector until the cache is ready ──── */
    window.luSmartAll = function (force) {
        var el = document.getElementById('smart-content');
        if (!el) return;
        clearTimeout(smartTimer);   // single poll loop
        if (force) el.innerHTML = '<div class="lu-loading">Starting…</div>';
        fetch('/plugins/hbaviewer/ajax_info.php?type=smart_all' + (force ? '&refresh=1' : ''))
            .then(function (r) { return r.text(); })
            .then(function (html) {
                el.innerHTML = html;
                if (/data-smart="collecting"/.test(html)) {
                    smartTimer = setTimeout(function () { luSmartAll(false); }, 3000);
                }
            })
            .catch(function () { el.innerHTML = '<div class="lu-error">Request failed.</div>'; });
    };

    /* ── Per-drive SMART fetch (on demand; -n standby, never wakes a disk) ──── */
    window.luSmart = function (btn, serial) {
        btn.disabled = true; btn.textContent = '…';
        fetch('/plugins/hbaviewer/ajax_info.php?type=smart&serial=' + encodeURIComponent(serial))
            .then(function (r) { return r.text(); })
            .then(function (html) { btn.outerHTML = html; })
            .catch(function () { btn.disabled = false; btn.textContent = 'retry'; });
    };

    /* ── Copy a tab's rendered content to the clipboard (for support tickets) ── */
    window.luCopy = function (name, btn) {
        var el = document.getElementById(name + '-content');
        if (!el) return;
        var text = el.innerText || el.textContent || '';
        var done = function () {
            var old = btn.textContent; btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = old; }, 1200);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () {});
        } else {
            var r = document.createRange(); r.selectNode(el);
            var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r);
            try { document.execCommand('copy'); done(); } catch (e) {}
            sel.removeAllRanges();
        }
    };

    /* ── Overview: full card HTML via AJAX (banner shows until the read done) ──
       While the backend is still reading (data-overview="warming") poll every
       few seconds; once cards are in, settle into the slow auto-refresh. */
    function loadOverview() {
        var el = document.getElementById('overview-content');
        if (!el) return;
        fetch('/plugins/hbaviewer/ajax_info.php?type=overview_html')
            .then(function (r) { return r.text(); })
            .then(function (html) {
                el.innerHTML = html;
                clearTimeout(timer);
                var warming = /data-overview="warming"/.test(html);
                timer = setTimeout(loadOverview, warming ? 4000 : REFRESH_MS);
            })
            .catch(function () {
                el.innerHTML = '<div class="lu-error">Request failed — retrying…</div>';
                clearTimeout(timer);
                timer = setTimeout(loadOverview, 5000);
            });
    }

    /* ── Firmware/BIOS flash tab ─────────────────────────────────────────────
       Opt-in, single-flight (one flash at a time, enforced server-side). This UI
       drives flash.php; every real guard (array stopped, confirm, lock) is
       re-checked on the server, so the JS checks here are only fast feedback. */
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

    /* ── Drives tab: the bay map (plan 047) ───────────────────────────────────
       Lives here, below flashCsrf, because every write goes through the same
       Unraid CSRF token the flash and baseline POSTs use.
       The grid is built from the payload with createElement + textContent, not
       innerHTML: model and serial strings come off the drive itself, and a
       drive's own firmware is not a trusted source of markup. */
    /* drag = the key being dragged, over = the cell currently under the pointer.
       Both live here rather than in dataTransfer because dataTransfer is
       write-only during dragover — the browser will not let a page read what is
       being dragged until the drop, and the hover highlight needs it sooner. */
    var luBay = { data: null, sel: null, dimTimer: 0, drag: null, over: null };

    /* Two ways in. The loud one blanks the card and rebuilds all of it, which is
       right on first open and after anything that changes the TOOLBAR — lock,
       clear, undo, a resize. The quiet one leaves the card standing and repaints
       only the grid and tray, which is right after moving a single drive: there
       the full rebuild threw away the whole card and flashed "Loading…" for a
       change that touched two bays. */
    function luBayLoad(quiet) {
        var el = document.getElementById('baymap-content');
        if (!quiet) el.innerHTML = '<div class="lu-loading">Loading…</div>';
        fetch('/plugins/hbaviewer/ajax_info.php?type=baymap')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                // An error still takes the card, quiet or not: a stale map left
                // silently on screen is worse than a visible failure.
                if (d.error) { el.innerHTML = '<div class="lu-error"></div>'; el.firstChild.textContent = d.error; return; }
                luBay.data = d;
                if (quiet) { luBayPaint(); }
                else { luBay.sel = null; luBayRender(); }
                if (window.luLocateSync) luLocateSync();
            })
            .catch(function () { if (!quiet) el.innerHTML = '<div class="lu-error">Request failed.</div>'; });
    }

    /* Takes no argument ON PURPOSE. It is handed straight to luBayPost as a
       callback, which calls it with the server's reply — so a `quiet` parameter
       here would be truthy on every one of those call sites and silently turn
       the loud path into the quiet one. */
    window.luBayFetch = function () { luBayLoad(false); };
    function luBayReload() { luBayLoad(true); }

    function luBayPost(body, done) {
        body.csrf_token = flashCsrf;
        fetch('/plugins/hbaviewer/bay_map.php', {method: 'POST', body: new URLSearchParams(body)})
            .then(function (r) { return r.json(); })
            .then(function (j) {
                /* A refused write has to be un-done on screen as well as
                   reported. The grid is now painted optimistically, so without
                   this resync the map would keep showing a move the server
                   rejected — the one state the person has no way to notice. */
                if (!j.ok) { alert(j.error || 'Bay map not saved.'); luBayReload(); return; }
                if (done) done(j);
            })
            .catch(function () { alert('Bay map request failed.'); luBayReload(); });
    }

    /* Chrome once, contents on every change. Re-rendering the whole view from
       the dimension inputs' own oninput would replace the input the person is
       typing into and drop focus mid-number, so only the grid and tray repaint. */
    function luBayRender() {
        var d = luBay.data, el = document.getElementById('baymap-content');
        var dis = d.locked ? ' disabled' : '';
        el.innerHTML =
            '<div class="lu-card first">'
          /* Toolbar: Rows / Columns / lock, and no full-width sentence about
             the lock state — the disabled inputs and the glyph already say it,
             and that band of prose was the largest piece of dead space on the
             screen. The unlock button is the only thing pressable while
             locked, so it carries the explanation in its tooltip. */
          + '<div class="lu-bay-dims">'
          +   '<label>Rows <input type="number" id="bay-rows" min="1" max="12" value="' + (d.rows | 0) + '"' + dis + '></label>'
          +   '<label>Columns <input type="number" id="bay-cols" min="1" max="12" value="' + (d.cols | 0) + '"' + dis + '></label>'
          +   '<button class="lu-refresh-btn" id="bay-lock" onclick="luBayLock()" title="'
          +     (d.locked ? 'The layout is locked. Unlock it to move drives or resize the grid.'
                          : 'Lock the layout so it cannot be changed by accident.') + '">'
          +     (d.locked ? '&#128274; Unlock' : '&#128275; Lock') + '</button>'
          /* "Clear map", never "Clear array" — on an Unraid page that second
             word means the disks, and a button that reads as "erase my array"
             is a scare nobody needs to survive to use this. */
          +   '<button class="lu-refresh-btn" id="bay-clear" onclick="luBayClear()"' + dis
          +     ' title="Send every placed drive back to the unassigned list. Only the map is'
          +     ' cleared — no drive is touched.">Clear map</button>'
          /* Copy carries no `dis`: reading the map out is safe with the layout
             locked, and a locked map is exactly the finished one worth saving.
             Restore writes, so it is disabled like everything else that does. */
          +   '<button class="lu-refresh-btn" id="bay-copy" onclick="luBayCopy(this)"'
          +     ' title="Copy the map to the clipboard, so it can be kept somewhere'
          +     ' other than the boot flash.">Copy map</button>'
          +   '<button class="lu-refresh-btn" id="bay-restore" onclick="luBayRestore()"' + dis
          +     ' title="Rebuild the map from text that Copy map produced, or from a'
          +     ' bay_map.json out of a backup.">Restore map</button>'
          /* Only rendered when there is something to undo, so it is never a
             button that does nothing — and its presence is the signal that the
             last action was one of the destructive ones. */
          +   (d.has_backup && !d.locked
                  ? '<button class="lu-refresh-btn" id="bay-undo" onclick="luBayUndo()"'
                    + ' title="Put the map back as it was before the last Clear or grid resize.">'
                    + '&#8630; Undo</button>'
                  : '')
          /* The hint used to sit here. It moved to the tab header: a sentence
             in the middle of a row of controls shifted every button along it
             whenever the text changed, and the toolbar is for controls. */
          + '</div>'
          + '<div class="lu-bay-legend">'
          +   luBayLegend('#3fb950', 'Healthy')     + luBayLegend('#d29922', 'High temp')
          +   luBayLegend('#f85149', 'Failed')      + luBayLegend('#58a6ff', 'Parity rebuild')
          +   luBayLegend('#6e7681', 'No SMART data')
          +   '<span class="lu-bay-lg"><i class="dashed"></i>Empty bay</span>'
          /* The colours and temperatures above are only as current as the
             collection behind them, and that collection is now kept until
             someone refreshes it. Say its age here rather than letting a
             three-day-old temperature pass for a live one. */
          +   '<span class="lu-bay-lg" style="margin-left:auto">' + (d.smart_age
                  ? 'SMART data collected ' + d.smart_age + ' ago — refresh it on the SMART tab'
                  : 'No SMART data yet — open the SMART tab to collect it') + '</span>'
          + '</div>'
          + '<div class="lu-bay-scroll"><div class="lu-bay-grid" id="bay-grid"></div></div>'
          + '<p class="lu-muted" style="font-size:12px;margin:0 0 8px">Unassigned drives</p>'
          + '<div class="lu-bay-tray" id="bay-tray"></div>'
          + '</div>';
        /* Set, not rendered: the hint lives in the TAB header, which is outside
           #baymap-content and so is not luBayRender's to rewrite. Emptied while
           locked, because none of those gestures do anything then. */
        var hint = document.getElementById('bay-hint');
        if (hint) {
            hint.textContent = d.locked ? ''
                : 'Drag a drive into a bay, or click one then a bay. '
                + 'Drag it back to the tray — or double-click it — to empty the bay.';
        }
        if (!d.locked) {
            // change, not input: `input` fires on every keystroke, so clearing
            // the field to retype it read as "1 row" and the debounced save
            // then displaced every drive below row 0 — the accidental wipe.
            // `change` waits for the field to be committed (blur/Enter/spinner).
            document.getElementById('bay-rows').onchange = luBayDims;
            document.getElementById('bay-cols').onchange = luBayDims;
        }
        luBayPaint();
    }

    /* The confirm names the COUNT rather than asking "are you sure?", because
       the number is the thing that makes a person stop: a map of 24 bays was
       built by walking to the rack and reading labels, and nothing here
       remembers it once it is written. There is no undo to fall back on, and
       the server cannot tell an intended clear from a misclick — so this
       prompt is the only guard the action has. */
    window.luBayClear = function () {
        var n = (luBay.data.placed || []).length;
        // Already empty: say so instead of asking a question whose answer
        // changes nothing.
        if (!n) { alert('The bay map is already empty.'); return; }
        if (!confirm('Clear all ' + n + ' placed drive' + (n === 1 ? '' : 's') + ' from the bay map?\n\n'
                   + 'They go back to the unassigned list and you will have to place them again.\n'
                   + 'No drive is touched — only the map. This cannot be undone.')) return;
        luBayPost({action: 'clear'}, luBayFetch);
    };

    // No confirm on the way back: undo is the recovery path, and putting a
    // dialog in front of it would guard the safe direction.
    window.luBayUndo = function () { luBayPost({action: 'restore'}, luBayFetch); };

    /* execCommand('copy'), NOT navigator.clipboard. The modern API is gated on a
       secure context, and an Unraid webGui is normally reached over plain HTTP
       on a LAN address — where navigator.clipboard is simply undefined. This
       path is deprecated and works everywhere, which is the trade that matters
       for a button whose whole job is rescuing data. Returns false if the
       browser refuses, and the caller falls back to showing the text. */
    function luBayCopyText(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        // Off-screen but focusable; display:none would make select() a no-op.
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(ta);
        return ok;
    }

    /* Copy is the backup half. The map is hand-built by walking to the rack and
       nothing on the box can regenerate it, so it needs to be able to leave the
       box — a flash that dies, or a flash backup that quietly stopped running,
       otherwise takes it with no warning.
       Emitted in bay_map.json's own shape on purpose: what this produces can be
       pasted straight into that file, and the file's contents can be pasted
       straight back in here. One format, both directions. */
    window.luBayCopy = function (btn) {
        var m = {};
        (luBay.data.placed || []).forEach(function (p) { m[p.key] = {row: p.row, col: p.col}; });
        var n = Object.keys(m).length;
        if (!n) { alert('Nothing to copy — no drive is placed yet.'); return; }
        var text = JSON.stringify(m, null, 4);
        if (luBayCopyText(text)) {
            var old = btn.textContent;
            btn.textContent = 'Copied ' + n + ' bay' + (n === 1 ? '' : 's');
            setTimeout(function () { btn.textContent = old; }, 1600);
        } else {
            // Never leave with nothing: if the copy is refused, show the text so
            // it can still be selected by hand.
            prompt('Copy this and keep it somewhere off the flash:', text);
        }
    };

    window.luBayRestore = function () {
        var raw = prompt('Paste a saved map — either what "Copy map" produced, '
                       + 'or the contents of bay_map.json from a backup:');
        if (raw === null || !raw.trim()) return;
        // Parsed here only to reject obvious rubbish before a round trip; the
        // server re-validates every key and position regardless.
        try { JSON.parse(raw); } catch (e) { alert('That is not valid JSON.'); return; }
        luBayPost({action: 'import', map: raw.trim()}, function (j) {
            /* Say what was dropped. A restore that silently keeps 18 of 24 bays
               reads as a success and sends someone to the wrong slot. */
            if (j.skipped) {
                alert('Restored ' + j.placed + ' placement' + (j.placed === 1 ? '' : 's') + '.\n\n'
                    + j.skipped + ' entr' + (j.skipped === 1 ? 'y was' : 'ies were') + ' skipped — '
                    + 'an unrecognised drive key, a bay outside the current grid size, '
                    + 'or two drives in the same bay.');
            }
            luBayFetch();
        });
    };

    window.luBayLock = function () {
        luBayPost({action: 'lock', locked: luBay.data.locked ? '0' : '1'}, function (j) {
            luBay.data.locked = !!j.locked;
            luBay.sel = null;
            luBayRender();
        });
    };

    function luBayPaint() {
        var d = luBay.data;
        var grid = document.getElementById('bay-grid');
        if (!grid) return;
        grid.parentNode.classList.toggle('lu-bay-locked', !!d.locked);
        /* Emptying a bay is delegated to the GRID, which survives a repaint.
           A per-cell ondblclick cannot work here and shipped broken in
           2026.08.05: single-clicking a filled bay picks the drive up, that
           calls luBayPaint(), and this function replaces every cell with a
           fresh element. The browser then sees the two clicks of a double-click
           land on two DIFFERENT nodes and dispatches dblclick at their nearest
           common ancestor — this grid — so nothing on the cell ever runs.
           Assigned as a property, not addEventListener, so repainting cannot
           stack duplicate handlers. */
        grid.ondblclick = function (e) {
            if (luBay.data.locked) return;
            if (e.target.closest('button')) return;   // the Locate button, not the bay
            var hit = e.target.closest('.lu-bay-cell[data-bay-key]');
            if (!hit) return;
            luBayCommit(hit.dataset.bayKey, null, null);
        };
        /* Drag and drop, delegated to the grid for the same reason dblclick is:
           luBayPaint() replaces every cell, so a handler bound to a cell is
           bound to a node that is about to be thrown away. Click-then-click is
           deliberately kept alongside this — HTML5 drag does nothing at all on
           a touch screen, and that is the fallback rather than a second
           codepath, since both ends post the same assign action. */
        grid.ondragstart = function (e) {
            // The Locate button lives inside a draggable cell; dragging from it
            // must not pick the drive up. Same hazard the dblclick guard has.
            if (luBay.data.locked || e.target.closest('button')) { e.preventDefault(); return; }
            var cell = e.target.closest('.lu-bay-cell[data-bay-key]');
            if (!cell) { e.preventDefault(); return; }
            luBay.drag = cell.dataset.bayKey;
            e.dataTransfer.effectAllowed = 'move';
            // Firefox will not start a drag at all unless some data is set.
            e.dataTransfer.setData('text/plain', luBay.drag);
        };
        grid.ondragover = function (e) {
            if (!luBay.drag || luBay.data.locked) return;
            var cell = e.target.closest('.lu-bay-cell');
            if (!cell) return;
            // preventDefault is what marks this a valid drop target. Without it
            // the browser refuses the drop and animates the drag back.
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (luBay.over !== cell) { luBayDragClear(); luBay.over = cell; cell.classList.add('drop'); }
        };
        grid.ondrop = function (e) {
            e.preventDefault();
            var key = luBay.drag, cell = e.target.closest('.lu-bay-cell');
            luBayDragEnd();
            if (!key || !cell || luBay.data.locked) return;
            luBayCommit(key, +cell.dataset.row, +cell.dataset.col);
        };
        // Fires however the drag ends, including Escape and a drop on nothing —
        // without it the highlight sticks to a cell nobody is pointing at.
        grid.ondragend = luBayDragEnd;
        grid.innerHTML = '';
        grid.style.setProperty('--bay-cols', d.cols);
        var at = {};
        d.placed.forEach(function (p) { at[p.row + ':' + p.col] = p; });

        for (var r = 0; r < d.rows; r++) {
            for (var c = 0; c < d.cols; c++) {
                var drv  = at[r + ':' + c];
                var slot = (r + 1) + '-' + (c + 1);
                var cell = document.createElement('div');

                if (!drv) {
                    // An empty bay is drawn as a bay, not as a gap — a chassis
                    // with a hole in it is information.
                    cell.className = 'lu-bay-cell empty' + (luBay.sel ? ' target' : '');
                    var eid = document.createElement('span');
                    eid.className = 'lu-bay-eid'; eid.textContent = slot;
                    var ew = document.createElement('span');
                    ew.className = 'lu-bay-eword'; ew.textContent = 'EMPTY BAY';
                    cell.appendChild(eid); cell.appendChild(ew);
                } else {
                    var st = luBayState(drv, d.warn_temp);
                    cell.className = 'lu-bay-cell st-' + st.cls + (luBay.sel === drv.key ? ' sel' : '');

                    var body = document.createElement('div');
                    body.className = 'lu-bay-body';

                    // 1. Identity: slot chip, device path (the anchor), status chip.
                    var id = document.createElement('div');
                    id.className = 'lu-bay-id';
                    var sc = document.createElement('span');
                    sc.className = 'lu-bay-slot'; sc.textContent = slot;
                    var dv = document.createElement('span');
                    dv.className = 'lu-bay-dev'; dv.textContent = drv.dev || drv.slot || drv.key;
                    var stc = document.createElement('span');
                    stc.className = 'lu-bay-stat'; stc.textContent = st.label;
                    stc.style.color = st.col;
                    stc.style.background = st.col + '22';
                    id.appendChild(sc); id.appendChild(dv); id.appendChild(stc);
                    body.appendChild(id);

                    // 2. Capacity + temperature.
                    var cap = document.createElement('div');
                    cap.className = 'lu-bay-cap';
                    var cv = document.createElement('span');
                    cv.className = 'lu-bay-capv'; cv.textContent = drv.cap || '—';
                    var cu = document.createElement('span');
                    cu.className = 'lu-bay-capu'; cu.textContent = drv.cap_unit || '';
                    var tp = document.createElement('span');
                    tp.className = 'lu-bay-temp';
                    // No reading is said, never left to read as a temperature.
                    tp.textContent = drv.temp === null ? 'no data' : drv.temp + '°C';
                    var heat = luBayHeat(drv.temp, d.warn_temp);
                    if (heat) tp.style.color = heat;
                    cap.appendChild(cv); cap.appendChild(cu); cap.appendChild(tp);
                    body.appendChild(cap);

                    // 3. Temperature bar — a hot row is visible without reading
                    //    24 numbers. An unread drive gets an empty track, not a
                    //    zero-width bar that would read as "cold".
                    var track = document.createElement('div');
                    track.className = 'lu-bay-track';
                    if (drv.temp !== null || st.cls === 'rebuild') {
                        var fill = document.createElement('div');
                        fill.className = 'lu-bay-fill' + (st.cls === 'rebuild' ? ' rebuild' : '');
                        var pct = drv.temp === null ? 100
                                : Math.max(6, Math.min(100, ((drv.temp - 30) / 25) * 100));
                        fill.style.width = pct + '%';
                        if (st.cls !== 'rebuild') fill.style.background = heat || '#8b949e';
                        track.appendChild(fill);
                    }
                    body.appendChild(track);

                    // 4. Reference rows, one left edge for every value.
                    var ref = document.createElement('div');
                    ref.className = 'lu-bay-ref';
                    // First, because it is the identifier the person already
                    // knows: what this disk is called everywhere else in Unraid.
                    /* UNRAID and PORT share a row — both values are short, and
                       pairing them takes a row off every card in the grid. MODEL
                       and SERIAL each keep a full row: they are the long ones,
                       and halving their width only buys an ellipsis. */
                    if (drv.role) luBayRef(ref, 'UNRAID', drv.role);
                    luBayRef(ref, 'PORT',   drv.port);
                    luBayRef(ref, 'MODEL',  drv.model,  false, true);
                    luBayRef(ref, 'SERIAL', drv.serial, true,  true);
                    body.appendChild(ref);

                    // Locate lives inside the cell but is not part of
                    // click-to-move — its handler stops propagation.
                    if (drv.addr) {
                        var lb = document.createElement('button');
                        lb.className = 'lu-refresh-btn lu-bay-loc' + (drv.locating ? ' locating' : '');
                        lb.setAttribute('data-locate', drv.addr);
                        lb.textContent = drv.locating ? 'STOP' : 'Locate';
                        lb.onclick = (function (a, dv) {
                            return function (ev) { luLocate(ev, this, a, dv); };
                        })(drv.addr, drv.dev);
                        body.appendChild(lb);
                        if (drv.locating) cell.classList.add('locating');
                    }

                    cell.appendChild(body);
                }

                if (!d.locked) {
                    cell.onclick = luBayCellClick(r, c, drv);
                    // The key rides on the element; the grid's delegated
                    // dblclick reads it. A handler here could never fire.
                    if (drv) cell.dataset.bayKey = drv.key;
                    // Every bay is a drop TARGET, so both coordinates have to be
                    // readable from the element — the delegated handler has no
                    // closure over r and c the way cell.onclick does.
                    cell.dataset.row = r;
                    cell.dataset.col = c;
                    if (drv) cell.draggable = true;
                }
                grid.appendChild(cell);
            }
        }

        var tray = document.getElementById('bay-tray');
        tray.innerHTML = d.unassigned.length
            ? '' : '<span class="lu-muted" style="font-size:12px">Every detected drive is placed.</span>';
        d.unassigned.forEach(function (u) {
            var chip = document.createElement('span');
            // No key = the drive reported neither a port nor a PHY, so there is
            // nothing stable to remember it by. Shown, but not placeable.
            chip.className = 'lu-bay-chip' + (u.key === null ? ' dead' : (luBay.sel === u.key ? ' sel' : ''));
            chip.textContent = (u.dev || u.slot || u.serial || '?') + (u.role ? '  ' + u.role : '');
            chip.title = u.key === null
                ? 'This drive reports no port or PHY, so it cannot be assigned to a bay.'
                : [u.role, u.model, u.serial, u.size].filter(Boolean).join(' · ');
            if (u.key !== null && !d.locked) {
                chip.onclick = function () { luBay.sel = (luBay.sel === u.key) ? null : u.key; luBayPaint(); };
                chip.draggable = true;
                chip.dataset.trayKey = u.key;
            }
            tray.appendChild(chip);
        });

        /* The tray is the drop target for taking a drive back OUT of a bay —
           the drag equivalent of double-clicking it. Assigned after the chips
           because tray.innerHTML above replaces its contents, not the element,
           so these survive as properties on the same node. */
        tray.ondragstart = function (e) {
            var chip = e.target.closest('.lu-bay-chip[data-tray-key]');
            if (luBay.data.locked || !chip) { e.preventDefault(); return; }
            luBay.drag = chip.dataset.trayKey;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', luBay.drag);
        };
        tray.ondragover = function (e) {
            if (!luBay.drag || luBay.data.locked) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            tray.classList.add('drop');
        };
        tray.ondragleave = function () { tray.classList.remove('drop'); };
        tray.ondrop = function (e) {
            e.preventDefault();
            var key = luBay.drag;
            luBayDragEnd();
            if (!key || luBay.data.locked) return;
            // Dragging a tray chip back onto the tray is a no-op, not a POST
            // that unassigns something already unassigned.
            if (!luBay.data.placed.some(function (p) { return p.key === key; })) return;
            luBayCommit(key, null, null);
        };
        tray.ondragend = luBayDragEnd;
    }

    /* Move a drive in the LOCAL model, so the grid redraws on the spot instead
       of after a round trip. Mirrors what bay_map.php's assign does, including
       displacing whatever already occupied the target bay — if the two ever
       disagree, the quiet reload afterwards overwrites this and the server stays
       the authority.
       col === null means "back to the tray". The drive is appended there rather
       than sorted into Unraid's Main-page order, because that order is computed
       server-side (bay_tray_order) and duplicating the comparator here would be
       a second copy of the rule to keep in step. The reload settles it a moment
       later, which is the right trade for not having two sorts to maintain. */
    function luBayApply(key, row, col) {
        var d = luBay.data, moving = null;
        function pull(list) {
            return list.filter(function (e) {
                if (moving || e.key !== key) return true;
                moving = e; return false;
            });
        }
        d.placed = pull(d.placed);
        d.unassigned = pull(d.unassigned);
        if (!moving) return false;
        if (col === null) {
            delete moving.row; delete moving.col;
            d.unassigned.push(moving);
        } else {
            d.placed = d.placed.filter(function (p) {
                if (p.row !== row || p.col !== col) return true;
                delete p.row; delete p.col;
                d.unassigned.push(p);           // one drive per bay, same as the server
                return false;
            });
            moving.row = row; moving.col = col;
            d.placed.push(moving);
        }
        return true;
    }

    /* The single way a drive moves, whichever gesture asked for it — drag,
       click-then-click, or a double-click to empty. Paint first, POST second,
       reconcile last. Keeping all three gestures on this one path is why the
       optimistic update cannot drift between them. */
    function luBayCommit(key, row, col) {
        luBay.sel = null;
        if (!luBayApply(key, row, col)) return;   // nothing matched; nothing to save
        luBayPaint();
        luBayPost(col === null ? {action: 'unassign', key: key}
                               : {action: 'assign', key: key, row: row, col: col}, luBayReload);
    }

    // Drop highlights are cleared from one place, because every way a drag can
    // end has to clear them — drop, dragend, Escape, and moving to another cell.
    function luBayDragClear() {
        if (luBay.over) { luBay.over.classList.remove('drop'); luBay.over = null; }
        var t = document.getElementById('bay-tray');
        if (t) t.classList.remove('drop');
    }

    function luBayDragEnd() { luBay.drag = null; luBayDragClear(); }

    /* ── Locate: blink one drive's activity light (plan 048) ──────────────────
       The confirm fires once per page load, not once per press: the two things
       it says are properties of the technique, so a person needs them the first
       time and would resent them the tenth. */
    var luLocateWarned = false;

    window.luLocate = function (ev, btn, addr, dev) {
        // Bay cells are click-to-move and double-click-to-clear; this button
        // lives inside one and must not trigger either.
        if (ev) { ev.stopPropagation(); ev.preventDefault(); }
        var on = /locating/.test(btn.className);
        if (!on && !luLocateWarned) {
            if (!confirm('Locate blinks ' + (dev || 'this drive') + '’s ACTIVITY light by reading it '
                       + 'twice a second.\n\n'
                       + '• It is the activity light, not a dedicated locate LED. On a busy array other '
                       + 'drives blink too — look for the steady rhythm.\n'
                       + '• It wakes the drive and keeps it awake until you stop it, or it stops itself.\n\n'
                       + 'Start blinking?')) return;
            luLocateWarned = true;
        }
        luLocatePost(on ? 'stop' : 'start', addr);
    };

    function luLocatePost(action, addr) {
        var body = {action: action, csrf_token: flashCsrf};
        if (addr) body.addr = addr;
        fetch('/plugins/hbaviewer/locate.php', {method: 'POST', body: new URLSearchParams(body)})
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) { alert(j.error || 'Locate failed.'); return; }
                luLocateApply(j.active || []);
            })
            .catch(function () { alert('Locate request failed.'); });
    }

    /* Paint every Locate control from one list of blinking addresses — the
       table's buttons and the map's bays are two views of the same server-side
       state, so neither is ever guessed at from what was just clicked. */
    function luLocateApply(active) {
        /* Write the state into luBay.data BEFORE the DOM, because the map is
           repainted from that data and not from what is on screen. Touching
           only the DOM was the bug: luBayPaint() clears the grid and rebuilds
           every cell out of luBay.data, so picking a drive up -- any click on
           the map at all -- restored whatever the Locate buttons looked like at
           the last fetch.
           That is not merely cosmetic, because luLocate() reads start-vs-stop
           off the button's own class. A button stale-showing "Locate" over a
           drive that IS blinking sends `start`, which is a deliberate no-op, so
           the drive keeps going and only the SECOND press stops it. Same shape
           as the double-click bug: state applied to cells that luBayPaint()
           then throws away. */
        if (luBay.data) {
            (luBay.data.placed || []).concat(luBay.data.unassigned || []).forEach(function (drv) {
                if (drv.addr) drv.locating = active.indexOf(drv.addr) !== -1;
            });
        }
        document.querySelectorAll('[data-locate]').forEach(function (el) {
            var on = active.indexOf(el.getAttribute('data-locate')) !== -1;
            el.classList.toggle('locating', on);
            // The button is its own stop control — one place to press, and it
            // says what pressing it does rather than what is happening.
            if (el.tagName === 'BUTTON') el.textContent = on ? 'STOP' : 'Locate';
            var cell = el.closest('.lu-bay-cell');
            if (cell) cell.classList.toggle('locating', on);
        });
    }

    // On load, ask the server what is already blinking — a locate started in
    // another tab, or before this reload, must still show as running.
    window.luLocateSync = function () { luLocatePost('status', null); };

    function luBayLegend(color, label) {
        return '<span class="lu-bay-lg"><i style="background:' + color + '"></i>' + label + '</span>';
    }

    // One PORT/MODEL/SERIAL row. An absent value still emits both cells, or the
    // rows below it climb into the wrong label's place.
    /* wide = this pair takes a row to itself, value spanning to the right edge.
       Used for MODEL and SERIAL, whose values are long enough that sharing a row
       would ellipsise them — and a truncated serial is no use at all against the
       label printed on the drive, which is the one moment it is ever read. */
    function luBayRef(parent, label, text, dim, wide) {
        var l = document.createElement('span');
        l.className = 'lu-bay-lbl' + (wide ? ' wide' : '');
        l.textContent = label;
        var v = document.createElement('span');
        v.className = 'lu-bay-val' + (dim ? ' dim' : '') + (wide ? ' wide' : '');
        v.textContent = (text === null || text === undefined || text === '') ? '—' : text;
        v.title = v.textContent;   // the value is ellipsised; the tooltip is the full string
        parent.appendChild(l);
        parent.appendChild(v);
    }

    /* Which state a bay renders as, and what the chip says. Health comes from
       the backend; a hot drive is promoted here because nothing server-side
       judges drive temperature. Order is worst-first — a failed drive that is
       also hot is failed. */
    function luBayState(drv, warn) {
        if (drv.state === 'fail')    return {cls: 'fail',    col: '#f85149', label: 'FAILED'};
        // The server says WHICH rebuild — Unraid's parity reconstruct, or a
        // controller-level one. It is never blank while the state is 'rebuild'.
        if (drv.state === 'rebuild') return {cls: 'rebuild', col: '#58a6ff',
                                             label: drv.rebuild_label || 'REBUILDING'};
        if (drv.temp !== null && drv.temp >= warn)
                                     return {cls: 'warn',    col: '#d29922', label: 'HIGH TEMP'};
        // Amber for a reallocated/pending sector count too — but never labelled
        // HIGH TEMP, which would be a plain lie on a 37°C drive.
        if (drv.state === 'warn')    return {cls: 'warn',    col: '#d29922', label: 'SECTORS'};
        if (drv.state === 'nodata')  return {cls: 'nodata',  col: '#6e7681', label: 'NO SMART'};
        return {cls: 'ok', col: '#3fb950', label: 'HEALTHY'};
    }

    // Heat scale off warnTemp. Normal returns '' — the number then inherits the
    // secondary ink, because a green temperature would signal something when
    // there is nothing to signal.
    function luBayHeat(t, warn) {
        if (t === null || t === undefined) return '';
        if (t >= warn + 4) return '#f85149';
        if (t >= warn)     return '#d29922';
        if (t >= warn - 3) return '#c9a227';
        return '';
    }

    /* Single click never destroys anything. On a filled bay it picks the drive
       up so you can put it somewhere else; on an empty one it drops whatever is
       held. Emptying a bay is a double-click, handled by the grid's delegated
       ondblclick in luBayPaint — deliberate, because a stray click on a map
       somebody walked to the rack to build should not undo any of it. */
    function luBayCellClick(r, c, drv) {
        return function (e) {
            /* Ignore the second click of a double-click. Without this the
               selection toggles on and straight back off before the dblclick
               that empties the bay arrives — harmless in the end state, but it
               repaints the grid twice and flickers on the way. */
            if (e && e.detail > 1) return;
            if (drv) { luBay.sel = (luBay.sel === drv.key) ? null : drv.key; luBayPaint(); return; }
            if (!luBay.sel) return;
            luBayCommit(luBay.sel, r, c);   // reads sel before luBayCommit clears it
        };
    }

    /* Resize: reflow the grid on screen, then persist. Drives that no longer
       fit move to the tray HERE too, not just in the server's prune, so the
       preview shows what the change will actually do.
       A shrink that displaces drives asks first. Everything else about this
       view is reversible with another click; this is the one action that can
       undo a lot of someone's work at once. */
    function luBayDims() {
        var rf = document.getElementById('bay-rows'), cf = document.getElementById('bay-cols');
        var rows = parseInt(rf.value, 10), cols = parseInt(cf.value, 10);
        // A blank or half-typed field is not a resize request. Without this,
        // clearing the box to retype it read as 1 and wiped the layout below
        // the first row.
        if (!(rows >= 1 && rows <= 12) || !(cols >= 1 && cols <= 12)) return;

        var d = luBay.data, keep = [], drop = [];
        d.placed.forEach(function (p) {
            if (p.row < rows && p.col < cols) keep.push(p); else drop.push(p);
        });
        if (drop.length && !confirm(
                drop.length + (drop.length === 1 ? ' drive does' : ' drives do')
                + ' not fit in a ' + rows + ' x ' + cols + ' grid.\n\n'
                + 'They go back to the unassigned list and you will have to place them again. '
                + 'Everything that still fits keeps its bay.')) {
            rf.value = d.rows; cf.value = d.cols;   // put the fields back
            return;
        }
        drop.forEach(function (p) { d.unassigned.push(p); });
        d.placed = keep; d.rows = rows; d.cols = cols;
        luBayPaint();
        clearTimeout(luBay.dimTimer);
        luBay.dimTimer = setTimeout(function () {
            luBayPost({action: 'dims', rows: rows, cols: cols}, luBayFetch);
        }, 400);
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
            loaded['flash'] = true;
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

    /* ── Performance tab: poll instant counters, compute rates, plot ─────────
       In-browser only: a ring buffer (~5 min) of rates derived from the delta
       between two /proc/diskstats + sysfs snapshots. Runs ONLY while the tab is
       open (luTab starts/stops it). Server stays stateless. */
    var perfTimer = null, perfActive = false, perfPrev = null, perfCharts = {};
    var PERF_MAX = 150;   // ~5 min at 2s

    function perfCell(title) {
        var wrap = document.createElement('div'); wrap.className = 'lu-perf-cell';
        var cap = document.createElement('div'); cap.className = 'cap';
        var t = document.createElement('span'); t.textContent = title;
        var v = document.createElement('b'); v.textContent = '–';
        cap.appendChild(t); cap.appendChild(v);
        var cv = document.createElement('div'); cv.className = 'lu-perf-canvas';
        var canvas = document.createElement('canvas'); cv.appendChild(canvas);
        wrap.appendChild(cap); wrap.appendChild(cv);
        return { wrap: wrap, canvas: canvas, val: v };
    }
    function perfChart(canvas, colors) {
        return new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: { labels: [], datasets: colors.map(function (c) { return {
                data: [], borderColor: c, backgroundColor: 'transparent',
                borderWidth: 1.4, pointRadius: 0, tension: 0.25, spanGaps: true }; }) },
            options: {
                animation: false, responsive: true, maintainAspectRatio: false,
                scales: { x: { display: false },
                          y: { beginAtZero: true, ticks: { color:'#777', font:{size:9}, maxTicksLimit:4 }, grid: { color:'#242424' } } },
                plugins: { legend: { display: false }, tooltip: { enabled: false } }
            }
        });
    }
    function perfPush(cell, values, valText) {
        var ch = cell.chart;
        ch.data.labels.push('');
        values.forEach(function (v, i) { if (ch.data.datasets[i]) ch.data.datasets[i].data.push(v); });
        if (ch.data.labels.length > PERF_MAX) {
            ch.data.labels.shift();
            ch.data.datasets.forEach(function (ds) { ds.data.shift(); });
        }
        ch.update('none');
        cell.val.textContent = valText;
    }
    function perfBuild(ctls) {
        var host = document.getElementById('perf-content'); host.innerHTML = ''; perfCharts = {};
        var defs = [
            { key:'thr',  title:'Throughput MB/s', series:['#3aa0ff','#f5a623'] },  // read, write
            { key:'iops', title:'IOPS',            series:['#2ecc71'] },
            { key:'util', title:'% Util',          series:['#9b59b6'] },
            { key:'lat',  title:'Latency ms',      series:['#e74c3c'] },
            { key:'phy',  title:'PHY err/s',       series:['#e67e22'] },
            { key:'temp', title:'Temp °C',         series:['#1abc9c'] }
        ];
        ctls.forEach(function (c) {
            var box = document.createElement('div'); box.className = 'lu-perf-ctl lu-card first';
            box.setAttribute('data-ctl', c.idx);
            var h = document.createElement('h4'); h.textContent = 'Controller /c' + c.idx; box.appendChild(h);
            var grid = document.createElement('div'); grid.className = 'lu-perf-grid';
            var cells = {};
            defs.forEach(function (d) {
                var cell = perfCell(d.title); grid.appendChild(cell.wrap);
                cell.chart = perfChart(cell.canvas, d.series); cells[d.key] = cell;
            });
            box.appendChild(grid); host.appendChild(box); perfCharts[c.idx] = cells;
        });
    }
    function perfDriveMap(c) { var m = {}; (c.drives || []).forEach(function (d) { m[d.dev] = d; }); return m; }

    function luMetricsRender(snap) {
        var ctls = snap.controllers || [];
        if (!ctls.length) { document.getElementById('perf-content').innerHTML = '<p class="lu-muted">No SAS controllers detected.</p>'; perfPrev = null; return; }
        if (Object.keys(perfCharts).length !== ctls.length) { perfBuild(ctls); perfPrev = null; }

        if (perfPrev) {
            var dt = snap.t - perfPrev.t;
            if (dt > 0) {
                var prevById = {}; (perfPrev.controllers || []).forEach(function (c) { prevById[c.idx] = c; });
                ctls.forEach(function (c) {
                    var cells = perfCharts[c.idx]; if (!cells) return;
                    var pc = prevById[c.idx];
                    if (pc) {
                        var pm = perfDriveMap(pc), cm = perfDriveMap(c);
                        var rMB = 0, wMB = 0, iops = 0, utilSum = 0, utilN = 0, dWt = 0, dOps = 0;
                        Object.keys(cm).forEach(function (dev) {
                            var cur = cm[dev], prv = pm[dev]; if (!prv) return;
                            var dR = cur.r_sect - prv.r_sect, dWs = cur.w_sect - prv.w_sect;
                            var dRi = cur.r_io - prv.r_io, dWi = cur.w_io - prv.w_io;
                            var dTick = cur.io_ticks - prv.io_ticks, dW = cur.weighted - prv.weighted;
                            if (dR < 0 || dWs < 0 || dRi < 0 || dWi < 0 || dTick < 0 || dW < 0) return;  // counter wrap -> skip drive
                            rMB += dR * 512 / dt / 1e6; wMB += dWs * 512 / dt / 1e6;
                            iops += (dRi + dWi) / dt;
                            utilSum += Math.min(100, dTick / dt / 10); utilN++;
                            dWt += dW; dOps += (dRi + dWi);
                        });
                        var util = utilN ? utilSum / utilN : 0;
                        var lat = dOps > 0 ? dWt / dOps : 0;
                        var dPhy = (c.phy.inv + c.phy.disp + c.phy.sync + c.phy.reset)
                                 - (pc.phy.inv + pc.phy.disp + pc.phy.sync + pc.phy.reset);
                        var phyRate = dPhy >= 0 ? dPhy / dt : 0;
                        perfPush(cells.thr,  [rMB, wMB], (rMB + wMB).toFixed(1));
                        perfPush(cells.iops, [iops], Math.round(iops).toString());
                        perfPush(cells.util, [util], util.toFixed(0) + '%');
                        perfPush(cells.lat,  [lat], lat.toFixed(1));
                        perfPush(cells.phy,  [phyRate], phyRate.toFixed(1));
                    }
                    var temp = (c.temp == null) ? null : c.temp;
                    perfPush(cells.temp, [temp == null ? NaN : temp], temp == null ? '–' : temp + '°');
                });
            }
        }
        perfPrev = snap;
    }

    function luMetricsPoll() {
        if (!perfActive) return;
        fetch('/plugins/hbaviewer/ajax_info.php?type=metrics')
          .then(function (r) { return r.json(); })
          .then(function (snap) { if (!perfActive) return; luMetricsRender(snap); perfTimer = setTimeout(luMetricsPoll, 2000); })
          .catch(function () { if (perfActive) perfTimer = setTimeout(luMetricsPoll, 3000); });
    }
    window.luMetricsStart = function () {
        var host = document.getElementById('perf-content');
        if (typeof Chart === 'undefined') { host.innerHTML = '<div class="lu-error">Chart.js failed to load — reinstall the plugin (build.sh bundles chart.umd.min.js).</div>'; return; }
        perfActive = true; luMetricsPoll();
    };
    window.luMetricsStop = function () { perfActive = false; clearTimeout(perfTimer); perfPrev = null; };

    loadOverview();   // fire immediately on page load, then auto-refresh

    // Auto-open tab from URL param (?tab=xxx)
    var urlTab = new URLSearchParams(window.location.search).get('tab');
    if (urlTab && urlTab !== 'overview') { luTab(urlTab); }
})();
</script>
