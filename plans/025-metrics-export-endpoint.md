# Plan 025: Read-only export endpoint for Grafana / Homepage / external monitoring

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 8286fe7..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php source/usr/local/emhttp/plugins/hbaviewer/view.php`
> Expected output: **nothing**. Every excerpt below is quoted from `8286fe7`
> (`dev` tip, 2026-07-30). Any difference is a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW — additive, read-only, no existing contract touched
- **Depends on**: none
- **Category**: feature
- **Planned at**: `8286fe7`, 2026-07-31
- **Requested by**: external roadmap review

## A naming collision to avoid — read before starting

`ajax_info.php` **already has a `?type=metrics`** endpoint:

```php
$type = in_array($_GET['type'] ?? '', ['overview','overview_html','phy','drives','events','smart','smart_all','metrics'])
        ? $_GET['type'] : 'overview';
...
if ($type === 'metrics') {
    // Performance tab: instant counter snapshot (browser computes the rates)
    // get_metrics.sh touches only /proc + /sys + the overview cache
```

That endpoint is the Performance tab's ~2s-polled raw counter feed
(throughput/IOPS/PHY counters from `/proc/diskstats` + sysfs) — the
*browser* computes rates from successive polls, and the server stays
stateless. **This plan is a different thing**: a stable, low-frequency,
already-summarized snapshot (temp, health status, PHY error rate if plan
022 shipped, drive count) meant for an external scraper, not the in-page
JS. Reusing the name `metrics` for this would collide with the existing
contract. **Pick a distinct name — `export` is used throughout this plan;
change it if the maintainer prefers something else, but do not reuse
`metrics`.**

## A gap between the motivation and the scope — decide before building

The motivation below is Grafana/Prometheus scraping. The scope (correctly)
keeps the endpoint behind Unraid's existing webGui session and puts API-key
auth out of scope as a separate, security-sensitive plan.

Those two do not meet. **A Prometheus scraper cannot hold an Unraid session**,
so as scoped this ships the endpoint without enabling the use case that
justifies it. That is not an argument for bolting auth on here — the scope call
is right, and an unauthenticated endpoint exposing hardware inventory is exactly
the kind of thing that deserves its own plan and its own review.

It does mean being honest about what lands: a **structured JSON view usable by
anything sharing the browser session** (a Homepage widget behind the same login,
a logged-in `curl`, the plugin's own future pages), not turnkey Grafana
integration. If turnkey scraping is the actual goal, this plan is a prerequisite
rather than the deliverable, and the auth plan is the one that unblocks it.

## Why this matters

Everything the plugin knows about controller health currently lives behind
HTML tables meant for a human looking at the page. People running
Grafana/Prometheus or a Homepage-style dashboard alongside Unraid have no
way to pull `HBA temp = 62°C` into their existing stack without scraping
rendered HTML, which is exactly the kind of external-tool friction a
small JSON (and optionally Prometheus text-format) endpoint removes
cheaply, since the data is already fully structured before it hits the
HTML renderer.

## Current state

### `view.php`'s `lsi_hba_view()` — the shape this plan summarizes

```php
function lsi_hba_view(array $data, int $port, int $idx = 0): array {
    $status = $data['status'] ?? 'ok';
    ...
    return [
        'temp' => ..., 'status' => $status, 'color' => ..., 'label' => ...,
        'temp_band' => ..., 'cfg_band' => ..., 'model' => ..., 'chip' => ...,
        'firmware' => ..., 'fw_old' => ..., 'bios' => ..., 'mode' => ...,
        'drives' => ..., 'port_name' => ..., 'pcie' => [...],
    ];
}
```

Already exactly the per-controller summary an exporter needs — this plan
is a thin serialization pass over data that's already assembled for the
Overview, not a new data-gathering path.

## Scope

**In scope**:

- New `type=export` branch in `ajax_info.php` (or a standalone
  `export.php` if keeping it out of the existing dispatch is cleaner —
  decide based on how much of `ajax_info.php`'s existing setup, e.g.
  `cached_read.php`'s freshness/lock handling, it's worth reusing)
- JSON shape: array of controllers, each with `model`, `temp`, `status`,
  `temp_band`, `drive_count`, and — **only if plan 022 has shipped** — PHY
  error rate summary; omit that field entirely rather than emitting
  `null` when 022 hasn't landed yet, so consumers can detect its absence
  by key-not-present
- Optional `?format=prometheus` on the same endpoint, emitting Prometheus
  text exposition format (`hbaviewer_temp_celsius{controller="..."} 62`
  etc.) — genuinely optional, ship JSON first and treat Prometheus format
  as a fast-follow if it's cheap once JSON exists
- No new auth — reuses whatever session gate the rest of the plugin's
  pages already sit behind (Unraid's webGui login), consistent with the
  "local network only" posture already stated in the README

**Out of scope**:

- Any historical/time-series storage — this endpoint reports current
  state only, on each scrape; Prometheus's own TSDB is where history
  lives if someone wants it, not this plugin
- Authentication beyond Unraid's existing session (no separate API key
  scheme) — if the maintainer wants scrape-without-login later, that's a
  separate, security-sensitive plan of its own

## Steps

### Step 1: Decide file placement

Check how much `ajax_info.php`'s top-of-file setup (`cached_read.php`,
`event_archive.php` requires) the export endpoint actually needs — it
almost certainly doesn't need `event_archive.php` at all. If the overlap
is small, a standalone `export.php` requiring only `view.php` +
`config.php` is cleaner than growing the existing dispatch's `in_array`
list and its cognitive load.

### Step 2: Build the summary array

Loop `lsi_controllers($data)` (already used elsewhere per `view.php`'s own
doc comment: "Controllers from a decoded backend payload... so consumers
can loop uniformly regardless of backend or contract version") through
`lsi_hba_view()`, then project down to the export shape — do not invent a
second controller-decoding path.

```php
$export = array_map(function ($idx, $ctrl) use ($port) {
    $v = lsi_hba_view($ctrl, $port, $idx);
    return [
        'controller'  => $idx,
        'model'       => $v['model'],
        'temp_c'      => $v['temp'],
        'status'      => $v['status'],
        'temp_band'   => $v['temp_band'],
        'drive_count' => $v['drives'],
    ];
}, array_keys($controllers), $controllers);
```

**Verify**: `php -l` clean; a direct call against a fixture payload
produces the expected flat array (add a small unit test alongside
whatever tests `lsi_hba_view` already has).

### Step 3: Prometheus format (optional fast-follow)

```php
if (($_GET['format'] ?? '') === 'prometheus') {
    header('Content-Type: text/plain; version=0.0.4');
    foreach ($export as $c) {
        printf("hbaviewer_temp_celsius{controller=\"%d\",model=\"%s\"} %s\n", $c['controller'], $c['model'], $c['temp_c']);
        printf("hbaviewer_status{controller=\"%d\",model=\"%s\",status=\"%s\"} 1\n", $c['controller'], $c['model'], $c['status']);
    }
    exit;
}
```

Keep this additive and easy to drop if the maintainer decides it's not
worth the surface area — JSON is the feature, Prometheus is a convenience
format on top of the same data.

## Test plan

- Direct unit test of the summary-building function over one or two
  fixture controller payloads (reuse existing fixtures under
  `tests/fixtures/` rather than inventing new ones where an existing one
  already has the right shape).
- `bash tests/run.sh` stays green; this is purely additive so no existing
  golden should move.

## Done criteria

- [ ] Endpoint name does not collide with the existing `type=metrics`
      (confirm by grepping `ajax_info.php`'s `in_array` list after the change)
- [ ] `php -l` clean
- [ ] Summary function unit-tested against at least one multi-controller
      fixture
- [ ] `bash tests/run.sh` → `--- all pass ---`, zero existing goldens changed
- [ ] If Prometheus format shipped: output validated against the
      Prometheus text-exposition format spec (correct `# TYPE`/`# HELP`
      lines if included, correct metric-name charset)

## STOP conditions

- The drift check prints anything.
- The new endpoint reuses the string `metrics` for its `type` value or
  file name — that's the exact collision this plan exists to avoid.
- The endpoint re-derives controller data through a new parsing path
  instead of reusing `lsi_controllers()`/`lsi_hba_view()`.

## Maintenance notes

- **This endpoint's contract is now something external tools depend on.**
  Once shipped, changing field names in `lsi_hba_view()`'s output shape
  becomes a two-consumer change (the Overview page and this endpoint) —
  worth a comment at the export function noting that.
