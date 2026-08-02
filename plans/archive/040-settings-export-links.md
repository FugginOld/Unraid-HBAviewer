# Plan 040: Make the export endpoint discoverable from Settings

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat fd2f296..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/settings.php source/usr/local/emhttp/plugins/hbaviewer/export.php`
> Expected output: **nothing**. Every excerpt below is quoted from `fd2f296`
> (`dev` tip, 2026-08-02). Any difference is a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW. Static markup in one card on one page. No endpoint change, no
  new setting, nothing persisted.
- **Depends on**: **025** (DONE, merged at `fd2f296` — `export.php` exists)
- **Category**: discoverability / docs
- **Planned at**: `fd2f296`, 2026-08-02

## Why this matters

Plan 025 shipped `export.php` and **nothing anywhere mentions it.** Verified at
`fd2f296`:

```bash
grep -rn 'export\.php' --include=*.php --include=*.md --include=*.page source/ README.md
# (no output outside export.php itself)
```

A feature reachable only by reading the source is a feature nobody uses. The
Settings page already carries the right precedent one card over — the
Diagnostic Bundle section explains what a thing does and hands you the control.

**The caveat matters as much as the links.** The endpoint is session-gated, so
a Prometheus scraper cannot poll it. A page that lists a URL called
`?format=prometheus` without saying that is actively misleading: the first user
to point Prometheus at it will file a bug, and they will be right to. Better
the page says so than the issue tracker discovers it.

## Current state

### `settings.php:108-124` — the card and grid CSS to reuse

```css
.lu-s-card { background: linear-gradient(180deg,var(--surface-2),var(--surface)); border: 1px solid var(--border-soft); border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; }
.lu-s-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(360px, 100%), 1fr)); gap: 16px; align-items: start; margin-bottom: 16px; }
.lu-s-grid > .lu-s-card { margin-bottom: 0; }
.lu-s-grid > .lu-s-span { grid-column: 1 / -1; }
```

### `settings.php:255-286` — the exemplar card, and why it does not span

```php
    <?php /* Pairs beside Notifications in the two-column grid rather than
             spanning: it is a routine, read-only support action, and the span
             is reserved for Advanced precisely because that section unlocks
             firmware writes and must not read as a peer of the routine
             controls. … */ ?>
    <div class="lu-s-card">
      <h3>Diagnostic Bundle</h3>
      <p style="font-size:12px;color:var(--text);margin:0 0 14px">Collects everything needed to debug a controller problem …</p>
```

**The new card follows this exactly**: plain `lu-s-card` inside `.lu-s-grid`,
never `lu-s-span`. The span belongs to Advanced/Firmware Flashing, and the
comment above says why — a routine read-only feature must not render as a peer
of the section that unlocks firmware writes.

## Scope

**In scope**: `source/usr/local/emhttp/plugins/hbaviewer/settings.php` — one new
card, placed in the existing `.lu-s-grid` next to Diagnostic Bundle.

**Out of scope — do not touch**:

- `export.php`. Its output, routes and behaviour are fixed and hardware-verified.
  This plan adds no query parameter, no new format, and no endpoint.
- Any `<input>`, `name=` attribute, or anything `lsi_config_write()` reads. **This
  card stores nothing** — it is display-only, and adding a setting here would
  put a value through a schema that has no place for it.
- `hbaviewer.php`, the tab pages, and the README (see Maintenance notes).
- The `lu-s-span` class. See above.

## Steps

### Step 1: the card

Place it inside `.lu-s-grid`, immediately after the Diagnostic Bundle card.
Content, in this order:

1. `<h3>Export / API</h3>`
2. One short paragraph, same style as the Bundle card's
   (`font-size:12px;color:var(--text);margin:0 0 14px`): what it is — a
   read-only JSON snapshot of every controller's model, temperature, status,
   band and drive count, plus a Prometheus text format of the same data.
3. The two URLs, each as a clickable `<a class="lu-link">` wrapping a `<code>`,
   so a click opens it in a tab (which doubles as the test) and the text is
   still selectable for copying.
4. The caveat, in the muted 11px style the Bundle card uses for its footnote
   (`font-size:11px;color:var(--faint)`), stating plainly that both URLs
   require an active Unraid webGui session, that a Prometheus scraper therefore
   **cannot** poll them, and that they work from a logged-in browser, a
   Homepage-style widget behind the same login, or a logged-in `curl`.

### Step 2: build the URLs from the request, and escape the host

```php
$host = htmlspecialchars($_SERVER['HTTP_HOST'] ?? '', ENT_QUOTES);
```

Two things this is not optional about:

- **Derive the host from the request**, never hardcode an IP or `localhost`.
  Users reach this page on a LAN IP, a hostname, a custom port (Unraid's webGui
  is commonly not on :80), or through a reverse proxy. A hardcoded host is
  wrong for most of them.
- **`htmlspecialchars` it.** `HTTP_HOST` is client-supplied — it comes from the
  `Host:` header and is not validated by PHP. Interpolating it raw into markup
  is reflected XSS. This is the one security-relevant line in the plan; do not
  simplify it away.

If `HTTP_HOST` is empty, fall back to a relative URL (`/plugins/hbaviewer/export.php`)
rather than rendering a broken absolute one.

### Step 3: no copy button

Do **not** add one. `navigator.clipboard` is `undefined` in an insecure context,
and Unraid's webGui is commonly plain HTTP on a LAN — a copy button would
silently do nothing for most users. `hbaviewer.php`'s `luCopy()` handles this
with an `execCommand('copy')` fallback, but it lives on the *other* page and
settings.php has no JS of its own; importing a helper for two short URLs is not
worth it. Selectable `<code>` is enough.

If a future maintainer adds one anyway, the two-branch pattern to copy is at
`hbaviewer.php`'s `window.luCopy` — clipboard API with a range-selection
fallback — not a bare `navigator.clipboard.writeText`.

## Test plan

**There is no automated coverage for `settings.php` and this plan does not add
any.** Say so in your report rather than letting a green suite imply otherwise.
The page mixes POST handling with full-page markup and no existing test renders
it; building that harness is its own plan, not a rider on a display card.

What to run instead:

- `php -l source/usr/local/emhttp/plugins/hbaviewer/settings.php` → no syntax errors
- `bash tests/run.sh` → `--- all pass ---` (proves only that nothing leaked into
  the tested surfaces)
- `git diff -- tests/expected/` empty

## Done criteria

- [ ] `grep -c 'export\.php' settings.php` → `2` (the two URLs)
- [ ] `grep -c 'lu-s-span' settings.php` unchanged from before the edit — the new
      card must not span
- [ ] `grep -c 'htmlspecialchars($_SERVER\['"'"'HTTP_HOST'"'"'\]' settings.php` → `1`
      — the host is escaped exactly once, where it is derived
- [ ] The caveat text contains the words "session" and "Prometheus" — a reviewer
      must be able to find it by grep
- [ ] `grep -c 'name=' settings.php` unchanged — this card adds no form field
- [ ] `php -l` clean, `bash tests/run.sh` → `--- all pass ---`
- [ ] `git diff --name-only` lists only `settings.php` and `plans/`

## STOP conditions

- The drift check prints anything.
- `export.php` appears in the diff.
- The host is hardcoded, or interpolated without `htmlspecialchars`.
- The card uses `lu-s-span`.
- Any `<input name=…>` is added — this card persists nothing.
- A copy button using `navigator.clipboard` with no fallback is added.

## Maintenance notes

- **The caveat is the load-bearing part.** If an auth scheme ever lands and the
  endpoint becomes scrapeable, this text must change in the same commit —
  otherwise the page will be telling users it cannot do the thing it now does.
- **The README is deliberately untouched here.** It is worth a matching section
  (Community Applications renders it, so it is the other place people discover
  features), but README wording is the maintainer's voice and belongs in their
  hands rather than an executor's.
- **`HTTP_HOST` behind a reverse proxy** reports the proxy's host, which is
  usually what the user wants. If anyone reports the wrong URL, the fix is
  `X-Forwarded-Host` handling — and that needs its own thought about which
  headers are trusted, so do not add it reflexively.
