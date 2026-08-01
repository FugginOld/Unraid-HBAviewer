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
/* fit-content, not a fixed width: the panel hugs whatever the active tab
   contains, so Overview shrinks to the HBA cards instead of leaving dead space
   either side. Consequence: the panel's width now differs per tab, since hidden
   tabs contribute nothing to max-content. max-width caps it on a wide screen and
   margin:auto re-centres it once shrunk. */
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
    font-family: inherit; width: fit-content; max-width: 1560px; margin: 20px auto;
    color: var(--text);
    background:
        radial-gradient(900px 350px at 85% -20%, var(--shade-bg-color, #242424) 0%, rgba(0,0,0,0) 55%),
        var(--bg);
    border: 1px solid var(--border-soft); border-radius: 16px; padding: 22px 24px 26px;
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
.lu-ov-grid { display: flex; gap: 16px; flex-wrap: wrap; justify-content: center; }
/* Cards size to their own widest row — in practice the PCIe row, since the four
   fields are one unwrapped line — instead of a guessed fixed maximum. fit-content
   resolves to min(max-content, available), so a card takes exactly the width its
   content needs when there is room, and shrinks (letting .lu-pcie-row wrap) when
   there is not. max-width:100% keeps it inside #lu-wrap on a narrow window. */
.lu-ov-grid .lu-card { flex: 0 1 auto; width: fit-content; max-width: 100%; margin-bottom: 0; }
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

/* ── Misc ────────────────────────────────────────────────────────────────── */
.lu-error {
    background: color-mix(in srgb, var(--crit) 10%, var(--surface)); border: 1px solid color-mix(in srgb, var(--crit) 40%, transparent);
    border-radius: 8px; padding: 14px 18px; color: var(--crit-text); font-size: 13px; margin-bottom: 12px;
}
.lu-muted  { color: var(--faint); font-size: 13px; }
.lu-loading { color: var(--faint); font-size: 13px; padding: 22px 0; text-align: center; }
.lu-tab-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.lu-refresh-btn {
    background: transparent; border: 1px solid var(--border); border-radius: 6px; color: var(--muted);
    font-size: 11px; font-weight: 600; padding: 5px 12px; cursor: pointer; text-transform: uppercase; letter-spacing: 0.05em; transition: border-color .15s, color .15s;
}
.lu-refresh-btn:hover { border-color: var(--accent); color: var(--accent); }

/* ── Firmware/BIOS flash tab ─────────────────────────────────────────────── */
.lu-flash-warn { background: color-mix(in srgb, var(--crit) 12%, var(--surface)); border: 1px solid color-mix(in srgb, var(--crit) 38%, transparent); border-radius: 10px; color: var(--crit-text); font-size: 13px; line-height: 1.5; padding: 12px 16px; margin-bottom: 14px; }
.lu-flash-warn strong { color: var(--crit-text); }
.lu-flash-array { border-radius: 10px; font-size: 13px; padding: 10px 16px; margin-bottom: 16px; }
.lu-flash-array.ok  { background: color-mix(in srgb, var(--good) 12%, var(--surface)); border: 1px solid color-mix(in srgb, var(--good) 32%, transparent); color: var(--good-text); }
.lu-flash-array.bad { background: color-mix(in srgb, var(--warn) 12%, var(--surface)); border: 1px solid color-mix(in srgb, var(--warn) 32%, transparent); color: var(--warn-text); }
.lu-fc { border: 1px solid var(--border-soft); border-radius: 10px; padding: 16px 18px; margin-bottom: 16px; background: var(--bg); }
.lu-fc h4 { margin: 0 0 4px; color: var(--accent); font-size: 13px; }
.lu-fc .sub { color: var(--faint); font-size: 12px; margin: 0 0 14px; font-family: var(--mono); }
.lu-fstep { margin: 14px 0; }
.lu-fstep label.step { display: block; color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; }
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
.lu-indicator-row { display: flex; align-items: center; gap: 10px; padding: 7px 2px; border-bottom: 1px dashed var(--border-soft); font-size: 12.5px; }
.lu-indicator-row:last-child { border-bottom: none; }
/* Was a flat coloured dot; a gradient bar carries its own internal contrast and
   is readable on any theme surface. --gd/--gl set inline per row from
   lsi_health_gradient(). */
.lu-ind-bar {
    width: 30px; height: 9px; border-radius: 3px; flex: 0 0 auto;
    background: linear-gradient(180deg, rgba(255,255,255,.26), rgba(255,255,255,0) 55%, rgba(0,0,0,.13)),
                linear-gradient(90deg, var(--gd), var(--gl));
}
.lu-indicator-label { color: var(--faint); flex: 1; }
.lu-indicator-value { color: var(--text); font-family: var(--mono); font-variant-numeric: tabular-nums; text-align: right; }

/* ── Performance tab ─────────────────────────────────────────────────────── */
.lu-perf-ctl { margin-bottom: 22px; }
.lu-perf-ctl h4 { margin: 0 0 10px; color: var(--accent); font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; }
.lu-perf-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
.lu-perf-cell { background: var(--bg); border: 1px solid var(--border-soft); border-radius: 10px; padding: 9px 12px 6px; }
.lu-perf-cell .cap { font-size: 10px; color: var(--faint); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; display: flex; justify-content: space-between; align-items: baseline; }
.lu-perf-cell .cap b { color: var(--text); font-weight: 600; font-size: 13px; font-family: var(--mono); font-variant-numeric: tabular-nums; }
.lu-perf-canvas { position: relative; height: 88px; }
</style>

<div id="lu-wrap">

<!-- ── Tab bar ───────────────────────────────────────────────────────────── -->
<div class="lu-tabs">
  <button class="lu-tab-btn active" data-tab="overview" onclick="luTab('overview')">Overview</button>
  <button class="lu-tab-btn" data-tab="health" onclick="luTab('health')">HBA Health</button>
  <?php if ($showPhy):    ?><button class="lu-tab-btn" data-tab="phy"    onclick="luTab('phy')">PHY Health</button><?php endif; ?>
  <?php if ($showDrives): ?><button class="lu-tab-btn" data-tab="drives" onclick="luTab('drives')">Drives</button><?php endif; ?>
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
  <div class="lu-card first">
    <div class="lu-tab-toolbar">
      <span style="font-size:12px;color:var(--text);">Thermal, link integrity, topology, host link, and read health — each judged independently</span>
      <button class="lu-refresh-btn" onclick="luReloadTab('health')">Refresh</button>
    </div>
    <div id="health-content"><div class="lu-loading">Loading…</div></div>
  </div>
</div>

<!-- ── PHY Health tab ────────────────────────────────────────────────────── -->
<?php if ($showPhy): ?>
<div id="tab-phy" class="lu-tab-pane">
  <div class="lu-card first">
    <div class="lu-tab-toolbar">
      <span style="font-size:12px;color:var(--text);">SAS link status, speed, and error counters per physical port</span>
      <button class="lu-refresh-btn" onclick="luReloadTab('phy')">Refresh</button>
    </div>
    <div id="phy-content"><div class="lu-loading">Loading…</div></div>
  </div>
</div>
<?php endif; ?>

<!-- ── Drives tab ────────────────────────────────────────────────────────── -->
<?php if ($showDrives): ?>
<div id="tab-drives" class="lu-tab-pane">
  <div class="lu-card first">
    <div class="lu-tab-toolbar">
      <span style="font-size:12px;color:var(--text);">Devices attached to the HBA</span>
      <button class="lu-refresh-btn" onclick="luReloadTab('drives')">Refresh</button>
    </div>
    <div id="drives-content"><div class="lu-loading">Loading…</div></div>
  </div>
</div>
<?php endif; ?>

<!-- ── Event Log tab ─────────────────────────────────────────────────────── -->
<?php if ($showEvents): ?>
<div id="tab-events" class="lu-tab-pane">
  <div class="lu-card first">
    <div class="lu-tab-toolbar">
      <span style="font-size:12px;color:var(--text);">HBA firmware event log (newest first)</span>
      <span>
        <button class="lu-refresh-btn" onclick="luCopy('events', this)">Copy</button>
        <button class="lu-refresh-btn" onclick="luReloadTab('events')">Refresh</button>
      </span>
    </div>
    <div id="events-content"><div class="lu-loading">Loading…</div></div>
  </div>
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
  <div class="lu-card first">
    <div class="lu-tab-toolbar">
      <span style="font-size:12px;color:var(--text);">Real-time throughput / IOPS / %util / latency / PHY-error-rate / temp &middot; sampled ~2s in your browser (last ~5&nbsp;min; resets on reload)</span>
    </div>
    <div id="perf-content"><div class="lu-loading">Waiting for first samples…</div></div>
  </div>
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
    <div id="flash-content"><div class="lu-loading">Loading controllers…</div></div>
  </div>
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
            })
            .catch(function () {
                el.innerHTML = '<div class="lu-error">Request failed.</div>';
            });
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
    // Unraid rejects POSTs without its CSRF token. Prefer Unraid's own fresh JS
    // global; fall back to the token we read from var.ini at render time.
    var flashCsrf = (typeof csrf_token !== 'undefined' && csrf_token) ? csrf_token : '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
    function fesc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
    function flashCard(i){ return document.querySelector('.lu-fc[data-ctl="'+i+'"]'); }
    function flashChip(i){ var c=flashCard(i); return c?c.getAttribute('data-chip'):''; }

    window.luFlashInit = function () {
        var el = document.getElementById('flash-content');
        if (!el) return;
        fetch('/plugins/hbaviewer/ajax_info.php?type=overview')
          .then(function(r){ return r.json(); })
          .then(function(d){
            var ctls = (d && d.controllers) || [];
            if (!ctls.length) { el.innerHTML = '<div class="lu-error">No controllers detected (or backend error).</div>'; return; }
            el.innerHTML = ctls.map(function(c,i){
              if (c.error) return '<div class="lu-fc"><h4>Controller /c'+i+'</h4><div class="lu-error">'+fesc(c.error)+'</div></div>';
              var chip = c.model || '';
              return '<div class="lu-fc" data-ctl="'+i+'" data-chip="'+fesc(chip)+'">'
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
                + '<div class="lu-fstep"><label class="step">Step 3 — confirm &amp; flash</label>'
                +   '<label class="lu-fack"><input type="checkbox" id="flash-ack-'+i+'"> I understand a wrong image can permanently brick this controller.</label>'
                +   'Type <strong>FLASH</strong>: <input type="text" id="flash-confirm-'+i+'" placeholder="FLASH"> '
                +   '<button class="lu-fbtn danger" onclick="luFlashGo('+i+')">Flash /c'+i+'</button></div>'
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
            var box = document.createElement('div'); box.className = 'lu-perf-ctl';
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
