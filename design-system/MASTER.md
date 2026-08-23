# HBAviewer — Design System (MASTER)

Global source of truth for HBAviewer's UI. **Extracted from the shipped code**
(`chrome.css`, `render/*.php`, `view.php`, `hbaviewer.js`, and the inline
`<style>` blocks in `dashboard.php` / `settings.php` / `flash_view.php`), not
invented. Where the code and this file disagree, the code is the bug.

Page-specific deviations go in `design-system/pages/<page>.md` and override
this file. Nothing overrides §0.

---

## 0. Platform constraints (non-negotiable)

HBAviewer renders **inside** the Unraid webGui, which already owns the page.

| Constraint | Consequence |
| --- | --- |
| Unraid ships four themes (white, black, gray, azure) | Every chrome colour resolves from an Unraid theme variable with the plugin's original literal as fallback. No hardcoded surface, text, or border hex. |
| No theme sets `prefers-color-scheme` | CSS alone cannot detect light vs dark. Light/dark branches are decided **server-side** (`lsi_tile_is_light()`) and applied as a class. |
| Plugin is PHP + vanilla JS + one plain CSS file | No build step, no framework, no Tailwind, no npm. Chart.js is the only vendored dependency. |
| The webGui's reset is not ours | Anything the layout depends on (`box-sizing`, `line-height`) is stated, not inherited. |
| Unraid theme sheets can outrank ours on cascade order | Shorthand-vs-longhand collisions are fixed with one scoped `!important`, documented at the rule. |
| Markup is emitted from PHP strings and JS `createElement` | All interpolated values pass `htmlspecialchars()` / `textContent`. No `innerHTML` with payload data. |

---

## 1. Tokens

Declared once, in **`tokens.css`**, on `#lu-wrap, #lu-settings-wrap`. Any page
that reads a shared token must link that file; a test enforces both halves.

### Chrome
```
--bg          var(--background-color, #161616)
--surface     var(--shade-bg-color, #1c1c1c)
--surface-2   color-mix(in srgb, var(--shade-bg-color,#232323) 92%, var(--text-color,#ddd) 8%)
--border      var(--border-color, #333)
--border-soft var(--border-color, #2a2a2a)
--text        var(--text-color, #dddddd)
--muted       alias of --text          (ponytail: one ink, ~40 call sites kept)
--faint       alias of --text
--mono        ui-monospace, "SF Mono", "Cascadia Code", "JetBrains Mono", Menlo, monospace
```
`--surface-2` is "one step further from `--surface` than the page is" — darker on
dark themes, lighter on light ones. No single Unraid variable expresses that.

Because `--text`/`--muted`/`--faint` are the same variable, **de-emphasis is done
with `opacity`, never with a colour swap** (a colour swap is a no-op here).

### Signal
```
--accent   #f5a623   brand / active / focus-adjacent
--accent-2 #88aaff   inline code
--good     #2ecc71   --good-text = 50% mixed toward --text
--warn     #f39c12   --warn-text = 50% mixed toward --text
--crit     #e74c3c   --crit-text = 50% mixed toward --text
```
**Rule:** the raw `--good/--warn/--crit` are tuned as *fills and badges*. As body
text they measure 1.5–2.2:1 on a light theme card. Text always uses the
`-text` variant (4.6–10.2:1 in every theme).

### Bay-map status (verbatim from the plan-047 handoff — signal, do not retint)
```
ok #3fb950 · warn #d29922 · fail #f85149 · rebuild #58a6ff · nodata #6e7681
```
Used as rails, borders, and `color-mix` tints over the themed surface — never as
a flat panel background and never as small text.

---

## 2. Scales

| Scale | Values in use |
| --- | --- |
| Space | 4 6 8 10 12 14 16 18 20 22 24 (4px rhythm; `gap` owns grid spacing, not margins) |
| Radius | 3 6 8 10 12 14 16 20 |
| Type | 8.5 9.5 10 10.5 11 11.5 12 12.5 13 14 16 19 30 |
| Frame | `#lu-wrap` max-width **1560px**, padding **22px 24px 26px** |
| Grid floors | overview 420 · health 440 · perf 220 · bay cell 236 |

Type and radius scales have drifted (see Gap P2-A). Treat the **bold** values as
canonical when adding UI: **10.5 / 11 / 12.5 / 13 / 16** and radius **6 / 12 / 14**.

---

## 3. Layout rules

1. **One frame width for every tab.** `#lu-wrap` is fixed-width, not
   `fit-content`. Hidden tabs contribute nothing to `max-content`, so a hugging
   frame resized on every tab switch and read as a page reload. Dead space beside
   Overview is the accepted trade.
2. **`auto-fit`, never `auto-fill`.** `auto-fit` collapses empty tracks, so two
   controllers take half the frame each instead of two of three columns.
3. **Responsive without media queries.** The `minmax()` floor *is* the
   breakpoint. Only reach for a media query when a floor cannot express it.
4. **`align-items: start`** on card grids — an errored two-line card must not
   stretch beside a full one.
5. **Absolute units in `max-width` caps inside grid items.** A percentage
   resolves against an indefinite track basis during sizing and silently
   collapses to the floor. Keep the numbers in step with §2's Frame row.
6. **`width: 100%` is required alongside `margin: auto` centring** in a grid
   item — auto inline margins switch stretching off.
7. **Tables scroll, never squeeze.** `.lu-tscroll { overflow-x: auto }`; the bay
   grid does the same via `.lu-bay-scroll`.
8. **Nesting depth ≤ 1 card.** A dual-IOC board is one card with sub-sections
   divided by a rule and an indent. A card inside a card reads as two boards.

---

## 4. Typography rules

- Card titles: 11px / 600 / `0.09em` / uppercase, with a 6px accent dot.
- Table headers: 10.5px / 600 / uppercase / `0.06em` / `nowrap`.
- Body and table cells: 12.5–13px, `line-height: 1.4` **stated**.
- **Every number is `var(--mono)` + `font-variant-numeric: tabular-nums`.** Non-
  negotiable: counters, temps, rates, capacities, and PCIe fields all sit in
  columns that must not jitter between polls.
- Labels are sentence-case words; values are mono. One left edge per value
  column — nothing in a bay cell is centre-aligned.
- Truncation (`text-overflow: ellipsis`) is allowed only in `.lu-bay-val`, where
  the cell floor is fixed. Everywhere else, wrap.
- **Identifiers reflow, they do not overflow.** `<code>` carries every device
  name, SAS address, serial and URL in this plugin, and a session host is a
  40-character hash with no break opportunity in it — the Export/API card
  rendered one straight out through its right edge. `overflow-wrap: anywhere`
  on `<code>`, never `word-break: break-all`, which would also chop ordinary
  words mid-letter. `anywhere` additionally lets a container's min-content
  width shrink, which `break-word` does not — the Settings page's columns
  layout needs that before it will narrow a card at all.

---

## 5. Colour & status rules

- **Colour is signal, not decoration.** A bay stays neutral until something needs
  attention, so the two that do are the only two the eye lands on. Normal
  temperatures are grey; a green number would read as an alert.
- **Never colour-only.** State carries a dot/rail/chip *and* a word.
- **Small graphical status objects use a two-layer gradient, not a flat fill.**
  Flat status colours fail the 3:1 non-text floor on the white theme
  (ok 2.74, watch 1.50, warning 2.15). `.lu-ind-dot`'s gradient is load-bearing;
  do not "simplify" it to a solid colour.
- **One status vocabulary.** The dot carries state; the Tabler glyph beside it
  inherits label ink so it is not read as a second signal.
- Instrument tiles (`.lu-tile`) supply their own background so the marks on them
  stop depending on the theme behind them. `.light` is set server-side.

---

## 6. Motion rules

- Motion is reserved for **state that is genuinely changing**: the rebuild stripe
  and the locate pulse. Nothing else animates position.
- Transitions are 0.15s (chrome), 0.16s (bay cell), 0.4s (gauge / badge colour).
- `prefers-reduced-motion` is handled **by preserving the signal, not by dropping
  it**: the rebuild stripe keeps its pattern and loses the scroll; the locate
  pulse becomes a steady outline. A motion-only signal must degrade to a static
  one.
- No scroll-triggered reveal, no entrance choreography. This is an instrument
  panel; content is present when the tab is.

---

## 7. Interaction rules

- Each tab owns a `Refresh` button in `.lu-tab-toolbar`; on per-controller tabs
  the toolbar sits at pane level, not inside the first card.
- `.lu-refresh-btn` is the one button in the plugin chrome. Inside a table it
  shrinks (3px/10px/10.5px) so a Locate button does not set the row height —
  and stays ≈22px tall to remain clickable.
- Drag-and-drop shows a `grab`/`grabbing` cursor and a solid outline on the
  hovered drop target. A drop with no target feedback is a guess.
- Locked state stays **fully readable and inert** — dimming punishes the state
  the user is meant to leave the map in.
- Destructive/irreversible actions (Clear map, flashing) are gated: Clear is
  undoable, flashing is behind `.flash-unlock`.

---

## 8. Icons

- SVG sprite defined once in **`icons.php`**, `require`d by the three top-level
  pages (`hbaviewer.php`, `settings.php`, `flash_view.php`) and referenced by
  `<use href="#lu-i-…">`. Fragments under `render/` reference ids freely: they
  are injected into a page that already carries the sprite.
- Current set: `thermal`, `link`, `topology`, `hostlink`, `controller`, `warn`,
  `settings` — Tabler Icons, stroked, `currentColor`, paths verbatim.
- Sizes: 15px in indicator rows; `.lu-i` is `1em` so an icon inside text tracks
  that text. `.lu-i` ships **inside `icons.php`**, not `chrome.css`, because
  `settings.php` does not link `chrome.css`.
- **No emoji, no HTML dingbat entity that can take emoji presentation.** A
  `<use>` pointing at an undefined id renders *nothing at all* — no gap, no
  fallback — so the sprite is covered by a test on both halves: every id
  referenced exists, and every page referencing one pulls the sprite in.

---

# Prioritized rules & gaps

Priority = impact on a user standing at a rack at 2am, not novelty. Each gap
names the rule from above that it violates.

## P0 — Accessibility floor — **DONE** (commit 2 of this branch)

| # | Rule | Was | Now |
| --- | --- | --- | --- |
| **P0-A** | Every interactive control has a visible focus ring | No `:focus-visible` rule existed anywhere, and two inputs actively removed the UA ring (`outline:none` plus a 1px border tint, which is not an indicator). | One `:focus-visible` rule in `chrome.css`; the two inputs restate it locally. **Amended in P1-B:** it was scoped to `#lu-wrap` alone, which left every control on the Settings page — a separate wrapper — without one. It now covers both. `outline`, not `box-shadow` — it follows the radius, survives `overflow:hidden`, and survives forced-colors. |
| **P0-B** | Clickable things are controls | Bay cells and tray chips were `div`/`span` with `.onclick`: unreachable by keyboard, unannounced by AT. | `role="button"`, `tabIndex`, an explicit `aria-label` (the cell's own text reads as an unpunctuated run of every field), and `aria-pressed` for the pick-up toggle. |
| **P0-C** | WCAG 2.2 — dragging has a single-pointer alternative | **The original entry was wrong.** Click-then-click already existed and is documented at `hbaviewer.js` as the deliberate touch fallback, so the *pointer* alternative was there. The real hole was the keyboard, and emptying a bay — double-click only, with no keyboard equivalent at all. | Delegated `grid.onkeydown` / `tray.onkeydown`: Enter or Space picks up and places, Delete empties. Delegated for the same reason the dblclick handler is — the repaint replaces every node. Named in the visible hint line, not left to be discovered. |
| **P0-D** | Tab strips announce themselves | Ten bare `<button>`s, no roles, no `aria-selected`, no arrow keys. | `role="tablist"` + `tab`/`tabpanel` + `aria-labelledby`, a roving tabindex so Tab leaves the strip in one press, and `luTabKey()` for Left/Right/Home/End. The two buttons that *navigate* carry `role="link"`, not `role="tab"` — announcing "tab 9 of 10" and then leaving the page is worse than no grouping. |
| **P0-E** | Meaningful graphics have a text alternative | **Partly wrong as originally written.** The `title=` attributes in `render/phy.php` and `view.php` sit beside text that IS visible, so they are supplementary help, not the only carrier — that is a P2, and it moved there. One case was genuine: `render/drives.php`'s no-address cell, whose entire visible content is an em dash. | `role="img"` + `aria-label` on that cell, which is what lets a bare glyph take an author-supplied name. |
| **P0-F** | Tab buttons declare their type | The eight pane tabs omitted `type="button"`, defaulting to `submit`. | Added. |

**Kept honest by:** three new checks in `tests/baymap_js_test.js` (Enter assigns, Delete unassigns, a locked map ignores both), each verified against a mutant. One over-specific regex in `tests/ajax_render_test.php` was relaxed — it pinned the pane's whole open tag while asserting only that nothing sits between the pane and its toolbar.

## P1 — Consistency debt (the thing that makes it look unowned)

| # | Rule | Current state | Fix |
| --- | --- | --- | --- |
| **P1-A** | One token block | ~~Copy-pasted into `settings.php` and `dashboard.php`~~ **DONE.** The genuine duplicate was `settings.php` alone, and it had already drifted — its `--mono` had lost `"JetBrains Mono"`, so the same number rendered in a different face depending on the page. `flash_view.php` was never a copy (it sits inside `#lu-wrap` and links `chrome.css`). `dashboard.php` shares exactly **one** token, `--crit-text`: it is injected as a `<tbody>` into Unraid's own dashboard, carries its own `--d-*` set, and linking a stylesheet into someone else's page to save one variable is the worse trade — it stays standalone **by decision, not by neglect**. Extracted to `tokens.css` with a selector list, not `:where()`: both wrappers keep the specificity they had. |
| **P1-B** | One button | ~~Three button classes~~ **DONE.** Two roles remain, which is what the plugin actually has: **ghost** (`.lu-refresh-btn`) for toolbar and refresh actions, **solid** (`.lu-btn`, `.danger`) for the one committing action on a page. The third was a near-copy of the second differing by 1px of type and a few px of padding — and each carried its own hardcoded hover hex, so changing `--accent` would have left one of them on the old hue. Hover is now derived with `color-mix` off the token. Both live in `chrome.css`, which `settings.php` now links (it didn't, which is exactly why it kept a copy); that page keeps only its **arrangement** rules, scoped to `.lu-actions`. The flash page's buttons grow 12px→13px, further past the 24px pointer-target floor rather than nearer it. |
| **P1-C** | No dingbats as icons | ~~Four glyphs~~ **DONE, for two of them.** `&#9888;` (U+26A0) and `&#9881;` (U+2699) are gone from all nine sites — the tab and page controls *and* the prose warning blocks. They take **emoji presentation** on Windows and Android, which renders them in the font's own colour and ignores whatever the element sets: a danger marker beside a firmware flasher that could not be made to look like one. `&#10003;` (✓) and `&#9650;` (▲) in `view.php` are **deliberately kept** — they are plain text glyphs that do inherit `currentColor`, they sit directly beside the word they mark, and being text they survive copy-to-clipboard into a support ticket, which an SVG does not. |
| **P1-D** | One type scale | 13 distinct font sizes across a 4-file UI. | **Open — recommended NOT to do as written.** Nearly every one of those values has a comment beside it giving the constraint that produced it: the 19px gauge readout is 19 because 30px overruns the arc's inner clear space; the in-table button is sized so a Locate button does not set the row height. Collapsing to a canonical scale overrides decisions made against real constraints, and the failure mode is visual, diffuse and untestable. The useful half is a rule, not a refactor: **stop adding new values** — §2 already states it. |
| **P1-E** | One radius scale | 3, 6, 8, 10, 12, 14, 16, 20 all in use. | **Open — same recommendation as P1-D**, for the same reason. Treat 6 / 12 / 14 / 20 as canonical for anything NEW rather than renumbering what exists. |

## P2 — Polish (real wins, no risk of regression)

| # | Item | Why |
| --- | --- | --- |
| **P2-A** | `min-height: 24px` on the small controls | **DONE.** Three sat a pixel or two under WCAG 2.2's 24px pointer floor: the in-table `.lu-refresh-btn` (~23px), `.lu-bay-chip` (~23px), and `.lu-bay-loc` (~19px — the widest of them and the shortest; width was never the problem). Stated as `min-height` rather than bought with padding, so the type inside can change later without silently dropping back under. `.lu-bay-chip` needed `inline-flex` for the floor to apply at all — it is a `<span>`, which takes its height from the line box. |
| **P2-B** | Sortable tables (`aria-sort` + click-to-sort on `.lu-table th`) | **DONE.** Every header is a `<button>` inside its `<th>` — a click handler on the `th` itself would be mouse-only, since a `th` is not focusable. `aria-sort` goes on the `th`, which is what a screen reader reads the sort state off. One `luTable()` renders all nine tables, so this landed in one place. Comparison is `localeCompare(…, {numeric: true})`: digit-run collation puts "9.095 TB" before "12.733 TB", "0/2" before "0/10", and `/dev/sdb` before `/dev/sdc` — one comparator instead of a parser per column type. Sort does not survive a tab refresh; the fragment is replaced. |
| **P2-C** | Empty and error states per tab | **DONE.** The backend's own errors were already good ("…is on the mpt3sas driver and the bundled lsiutil cannot read that"), so the gap was the four *empty* states, which answered "no data" in two or three words. One of them was worse than terse: the Event Log said **"No log entries"** even when the /boot archive held entries written by a different backend, so a box that switched backend was told its history was gone — the archive surviving that switch is the entire reason it exists. It now names the count and why they are not shown. The other three say what was observed and **claim no cause they cannot know**: a controller with no links and a backend that reports none are identical from the renderer. |
| **P2-D** | Stale-data indicator on every polled tab | **Open, and the ground moved under it.** The premise holds — a panel silently showing a five-minute-old temperature at 2am is worse than one that says so — but the blocking-read fix made the dashboard tile serve stale values *deliberately*. "Stale" is now two things: the tile doing its job, and a tab whose data quietly stopped updating. One indicator firing on both trains people to ignore it. **The threshold is a maintainer judgement**: tabs legitimately hold hour-old data (they load on click) while the tile is legitimately a minute behind. The unambiguous subset, if a smaller change is wanted: `cached_read()` noticing its own lock is older than `lock_ttl` — a producer that died — which needs no threshold at all. |
| **P2-E** | `.lu-table tbody tr:hover` used a raw accent literal | **DONE, and it was not alone.** The row highlight was an rgba triplet of `--accent`, and four inline `style=""` attributes (`render/table.php`, `settings.php` ×3) carried the hex. None would have moved with the token. All now read `var(--accent)`, and a test pins the colour to one declaration. **One exception remains, listed rather than skipped so it stays visible:** `hbaviewer.js`'s Chart.js series palette, because Chart.js takes colour values rather than CSS. That is P2-F — when it lands, delete the exception rather than widening it. |
| **P2-G** | `title=` is the only carrier for several explanations (`render/phy.php`, `view.php`) | **Open, low.** Mouse-only supplementary help; the visible text beside it already says the essential thing, which is why P0-E was demoted here — polish, not a floor. *(This row was silently deleted by a slice-based edit on 2026-08-23 and restored by the audit that found it.)* |
| **P2-F** | Chart.js colours were not themed | **DONE.** The seven Performance series hues moved into `tokens.css`; `hbaviewer.js` reads them with `getComputedStyle` once per build. Chart.js takes colour *values*, not CSS, so this is the one place in the plugin that must copy a colour out of the stylesheet — but `tokens.css` is now the only place one is *written*. Deliberately **not** wired to `--good`/`--crit`, though three match by value: those mean status, and latency is not "critical". A single neutral fallback, not seven literals — grey charts are the right visible symptom of a stylesheet that failed to load. This removed the last exception from the accent check. |

## P3 — Deferred (needs its own brainstorm)

- **P3-A** Bay map ~685 duplicated JS render lines (already tracked in the
  codebase-improvements work). Structural, touches the one unregenerable store.
- **P3-B** A dark/light *preview* in Settings. Currently the only way to check a
  theme change is to switch the whole webGui.
- **P3-C** Keyboard shortcut layer (`1`–`8` for tabs, `r` to refresh). Only after
  P0-D lands; shortcuts before ARIA is decoration over a broken floor.

---

## Anti-patterns (do not reintroduce)

- Hardcoded surface/text/border hex — it becomes an unreadable hole on the white
  theme.
- Flat status colours on small graphical objects.
- `fit-content` on the frame or on a card that can be alone in its grid.
- A colour swap between `--text`/`--muted`/`--faint` (no-op — use `opacity`).
- `innerHTML` with payload data.
- A media query where a `minmax()` floor would do.
- Regenerating goldens wholesale (`UPDATE=1` strips trailing newlines from four
  of them and the `$(cat)` comparison cannot see it).
