# Plan 025 (v2): Read-only JSON/Prometheus export of controller state

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat e6b6bab..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/view.php source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php source/usr/local/emhttp/plugins/hbaviewer/cached_read.php source/usr/local/emhttp/plugins/hbaviewer/config.php tests/run_php.sh`
> Expected output: **nothing**. Every excerpt below is quoted from `e6b6bab`
> (`dev` tip, 2026-08-02). Any difference is a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW — additive, read-only, no existing contract touched
- **Depends on**: none
- **Category**: feature
- **Planned at**: `e6b6bab`, 2026-08-02 (**v2** — see History)
- **Requested by**: external roadmap review

## History — what v1 left open, and how it resolved

v1 was written 2026-07-31 against `8286fe7`. Since then `view.php` and
`ajax_info.php` have changed by 361 lines, and three of v1's open questions
answered themselves:

| v1 said | Now |
|---|---|
| "New `type=export` branch in `ajax_info.php` **or** a standalone `export.php` — decide based on overlap" | **Decided: standalone `export.php`.** The repo has since grown three standalone endpoints — `health.php`, `phy_baseline.php`, `bundle.php` — and `ajax_info.php`'s dispatch list is now nine types long. The precedent is settled; do not grow the `in_array` list |
| "PHY error rate summary — **only if plan 022 has shipped**" | 022 shipped. But the answer is still **omit it** — for a different and better reason, see "What this deliberately does not export" |
| Nothing about health | Plan 020 shipped `health.php`, a five-indicator rollup that looks like the ideal export payload and **is a trap**. See below |

**v1's central honesty holds and is repeated here**: this endpoint sits behind
Unraid's webGui session, so **a Prometheus scraper cannot reach it**. What ships
is a structured view usable by anything sharing the browser session — a Homepage
widget behind the same login, a logged-in `curl`, the plugin's own future pages.
Turnkey Grafana scraping needs an auth scheme, which is a separate,
security-sensitive plan. Do not add one here.

## Why this matters

Everything the plugin knows about controller health currently lives inside HTML
tables built for a human. Pulling "HBA temp = 62 °C" into an existing dashboard
means scraping rendered markup — and the data was fully structured right up
until the moment it hit the renderer. This is a thin serialization pass over an
already-warm cache, not a new data path.

## Current state

### `view.php:187-189` — the controller decoder to reuse

```php
function lsi_controllers(array $data): array {
    return $data['controllers'] ?? [$data];
}
```

### `view.php:193+` — `lsi_hba_view()`, the per-controller summary

Its return array (fields relevant here):

```php
    return [
        'temp'       => $data['temp'] ?? '',
        'status'     => $status,
        'temp_band'   => $data['temp_band'] ?? '',
        'cfg_band'       => $data['cfg_band'] ?? '',
        'model'      => !empty($data['board_name']) ? $data['board_name'] : ($data['model'] ?? 'Unknown'),
        'chip'       => $data['model']     ?? 'Unknown',
        'firmware'   => $data['firmware']  ?? 'Unknown',
        'fw_old'     => !empty($data['fw_old']),      // SAS2 pre-P20 flag
        'mode'       => $data['mode']        ?? '',   // IT/IR (storcli)
        'drives'     => $data['drive_count'] ?? '',   // connected drive count (storcli)
        'pcie'       => $pcie,
        …
    ];
```

It also returns presentation-only keys (`color`, `label`, `temp_grad`,
`temp_label`, `cfg_band_label`, `port_label`). **Those must not reach the
export** — a colour string is not a metric, and exporting it would make the
theme part of an external contract.

### `ajax_info.php:115-135` — how a cached overview read is handled

```php
    $r = cached_read('overview', 60, 'bash ' . escapeshellarg("$scripts/get_hba_info.sh"));
    if ($r['state'] === 'ready') {
        $raw  = $r['body'];
        $data = $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($data) && !isset($data['error'])) { … }
```

Copy this shape exactly. `cached_read` owns freshness, the single-flight lock
and the atomic swap; the endpoint only turns a result into output.

### `bundle.php:1-17` — the standalone-endpoint header convention

```php
/* HBAviewer diagnostic bundle endpoint (plan 026).
 *
 * Read-only: … The guard function is pure over its input and unit-tested
 * (tests/bundle_php_test.php); the HTTP dispatch at the bottom runs only when
 * served, never under the CLI test runner — same shape as flash.php.
```

Match that: pure functions on top, dispatch at the bottom, `if (PHP_SAPI ===
'cli') return;` so the test runner can require the file.

## What this deliberately does not export — read before adding "just one more field"

**The health rollup (`health.php`).** It looks like the perfect payload and is a
trap. `health_indicators()` reads a ring buffer in `/tmp` that is filled by the
Health tab's own polling. A scraper hitting this endpoint on a box where nobody
has the Health tab open would read an empty or stale ring and export
`unknown` — worse than absent, because it looks like a measurement. The only fix
would be for the export to *ingest a sample itself*, which makes a read-only
endpoint a writer and lets an external scraper's polling interval silently
define the plugin's health sampling rate. **Out of scope. Say so if asked.**

**PHY error rates (`phy_baseline.php`).** Plan 022 shipped, but a rate exists
only where the user has pressed Set Baseline, and computing one needs a second
script run (`get_phy_health.sh`) per scrape. Exporting a field that is absent
for most users, at the cost of the most expensive read in the plugin, is a bad
trade for v2. If it is added later, gate it on a baseline existing for that
controller and omit the key entirely otherwise — never emit `null`.

**History.** This reports current state per scrape. Prometheus's TSDB is where
history lives.

**Auth.** See History.

## Scope

**In scope**: new `source/usr/local/emhttp/plugins/hbaviewer/export.php`, new
`tests/export_test.php`, and its registration in **both** lines of
`tests/run_php.sh`.

**Out of scope — do not touch**: `ajax_info.php` (including its `in_array`
dispatch list), `view.php`, `health.php`, `phy_baseline.php`, every parser and
every script under `scripts/`.

## Steps

### Step 1: the pure projection

```php
/* Project one controller's lsi_hba_view() output down to the export shape.
   Pure: takes the view array, returns scalars only. Presentation keys
   (color, label, *_grad, *_label) are deliberately dropped — see the plan.
   NOTE: this shape is an external contract once shipped. Renaming a key here
   breaks consumers silently; add, don't rename. */
function export_controller(int $idx, array $v): array
```

Returns exactly: `controller` (int), `model`, `chip`, `firmware`, `mode`,
`temp_c`, `status`, `temp_band`, `drive_count`, `pcie_width`, `pcie_speed`,
`fw_old` (bool).

`temp_c` and `drive_count` must be **numbers or null**, never `""` —
`lsi_hba_view()` returns `''` for a card with no sensor, and `"temp_c": ""` in
JSON is useless to a consumer. Cast when numeric, `null` when not.

`pcie_width` / `pcie_speed` come from the view's `pcie` array, which is a list
of `['label' => …, 'value' => …]` pairs; pull by label and default to `null`.

### Step 2: the dispatch

```php
if (PHP_SAPI === 'cli') return;
```

Then: read the overview through `cached_read('overview', 60, …)`, exactly as
`ajax_info.php:122` does. Map `lsi_controllers($data)` through
`lsi_hba_view($ctrl, $port, $idx)` and then `export_controller()`.

**Cold-cache behaviour is a required part of this plan, not an edge case.**
`cached_read` returns `['state' => 'warming', 'body' => '']` while the producer
runs. A metrics endpoint that answers `200 {"controllers":[]}` there teaches a
scraper that the box has no controllers, and that lands in someone's TSDB as
real data. Respond:

```php
http_response_code(503);
header('Retry-After: 5');
echo json_encode(['state' => 'warming']);
```

Same for a backend `error` key: `503`, and pass the message through. **Never
emit a partial or zero-valued sample.**

### Step 3: `?format=prometheus`

Optional but cheap. Content-Type `text/plain; version=0.0.4`.

```
# HELP hbaviewer_temp_celsius Controller temperature in degrees Celsius.
# TYPE hbaviewer_temp_celsius gauge
hbaviewer_temp_celsius{controller="0",model="HBA 9400-16i"} 62
```

Three rules the format actually requires:

- **Escape label values**: `\` → `\\`, `"` → `\"`, newline → `\n`. Model strings
  come from hardware and are not guaranteed quote-free. Write one
  `export_prom_label()` helper and unit-test it with a value containing a quote.
- **Omit a metric whose value is null** rather than emitting `NaN` or `0`. A
  card with no temperature sensor has no temperature, and absent is honest.
- **Status as an enum-style gauge**, one line per controller with the state as a
  label and value `1` — `hbaviewer_status{controller="0",status="ok"} 1`. Do not
  invent a numeric severity scale; nothing else in the plugin has one.

## Test plan

`tests/export_test.php`, following `tests/bundle_php_test.php`'s style
(pure functions, `check(name, bool)`, exits non-zero on failure), registered in
**both** lines of `tests/run_php.sh` — the plain `php …` line and the docker
`sh -c '…'` line. Missing the second is how a test silently never runs in CI.

Feed `export_controller()` a `lsi_hba_view()` output built from a real fixture —
`tests/expected/hba_normal.json` is a genuine single-controller payload, and
`tests/fixtures/cache_storcli_multi.json` is a real two-controller one. Assert:

- the exact key set, and that **no** presentation key (`color`, `label`,
  `temp_grad`, `temp_label`, `cfg_band_label`, `port_label`) is present
- `temp_c` is an int for a card with a sensor and `null` for one without
  (`cache_lsiutil_notemp.json` is the no-sensor fixture)
- `drive_count` is an int or null, never `""`
- two controllers project to `controller` 0 and 1 in order
- `export_prom_label()` escapes `"` and `\`
- the Prometheus renderer omits a null-valued metric entirely

## Done criteria

- [ ] `export.php` exists; `ajax_info.php` is **unchanged** (`git diff --stat` empty for it)
- [ ] `grep -c "'metrics'" export.php` → `0` — the name `metrics` belongs to the
      Performance tab's existing 2-second counter feed and must not be reused
- [ ] No presentation key appears in the export output (asserted in the test)
- [ ] Warming cache → HTTP 503 with `Retry-After`, not a 200 with an empty list
- [ ] `php -l` clean; `bash tests/run.sh` → `--- all pass ---`
- [ ] `git diff -- tests/expected/` empty — this plan moves no golden
- [ ] The suite's PHP warning count is unchanged from before the change
      (`docker run … php tests/ajax_render_test.php 2>&1 | grep -ciE 'warning|deprecated'` → `2`)
- [ ] `git diff --name-only` lists only `export.php`, `tests/export_test.php`,
      `tests/run_php.sh`, and `plans/`

## STOP conditions

- The drift check prints anything.
- `ajax_info.php` or `view.php` appears in the diff.
- The endpoint re-parses controller data instead of going through
  `lsi_controllers()` / `lsi_hba_view()`.
- The string `metrics` is used as this endpoint's name, type value, or filename.
- Any auth scheme, API key, or session bypass is added.
- The export reads `health.php`'s ring or writes anything anywhere.

## Maintenance notes

- **This shape becomes an external contract the moment it ships.** Renaming a
  key breaks a consumer with no error message. Add keys, never rename them, and
  keep the note to that effect at `export_controller()`.
- **It is session-gated, so it is not Prometheus-ready.** Anyone reporting
  "Prometheus can't scrape it" is correct and it is not a bug — the auth plan is
  the fix, and it deserves its own review.
- **The health rollup will keep looking like an obvious addition.** It is not;
  the reason is under "What this deliberately does not export", and it is about
  sampling ownership, not effort.
