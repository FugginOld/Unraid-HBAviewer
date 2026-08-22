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
| **P1-D** | One type scale | 13 distinct font sizes across a 4-file UI, including 8.5/9.5/10/10.5 within one component. | Collapse to 10 / 11 / 12.5 / 13 / 16 / 19 / 30. Bay-map micro-labels are the only justified exception — pin them at 10. |
| **P1-E** | One radius scale | 3, 6, 8, 10, 12, 14, 16, 20 all in use. | 6 (controls) / 12 (tiles) / 14 (cards) / 20 (pills). |

## P2 — Polish (real wins, no risk of regression)

| # | Item | Why |
| --- | --- | --- |
| **P2-A** | `min-height: 24px` on `.lu-bay-chip` and the in-table `.lu-refresh-btn` | WCAG 2.2 AA target size for pointer targets. The compact table button is ~22px. |
| **P2-B** | Sortable tables (`aria-sort` + click-to-sort on `.lu-table th`) | Drives and SMART are lists you scan for the worst row. Sorting by temperature or error count is the single highest-value missing interaction. |
| **P2-C** | Empty and error states per tab | `.lu-loading` and `.lu-error` exist; several tabs render a blank card when a tool is absent. State *why* (e.g. "storcli not installed") with the recovery path. |
| **P2-D** | Stale-data indicator on every polled tab | `.lu-phy-stale` proves the pattern. A panel that silently shows a five-minute-old temperature at 2am is worse than one that says so. |
| **P2-E** | `.lu-table tbody tr:hover` uses a raw `rgba(245,166,35,.05)` | Should be `color-mix(in srgb, var(--accent) 5%, transparent)` so it tracks the token. |
| **P2-G** | `title=` is the only carrier for several explanations (`render/phy.php`, `view.php`) | Mouse-only supplementary help. The visible text beside it is sufficient, so this is polish, not a floor — but a touch or keyboard user never sees the reasoning. Move the load-bearing sentences into a `.lu-ind-hint`-style sub-line or the column header. |
| **P2-F** | Chart.js colours are not themed | The Performance tab's charts should read `--accent`/`--good` off the computed style rather than carrying their own palette. |

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
