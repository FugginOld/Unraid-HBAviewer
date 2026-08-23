# Foreground reads

Every place this plugin shells out from PHP, and whether it may block.

Written 2026-08-22, after `dashboard.php` was found running a 60-second-ceiling
hardware read inline inside Unraid's **own** Dashboard page — which held a
php-fpm worker for the controller's read time and made the whole webGui
unresponsive for ~10s, once a minute, for as long as the plugin had been
installed.

The defect was not that one line was written badly. It was that nothing said
which call sites are allowed to block. This file is that statement. **Keep it
current when adding a `shell_exec`.**

## The rule

| Where the code runs | May it block? |
|---|---|
| A page Unraid renders (`dashboard.php`, `settings.php`, any `.page`) | **No.** The user did not ask for HBAviewer; they asked for a page that happens to contain it, and a worker held here queues everything behind it. |
| The plugin's own XHR endpoint (`ajax_info.php`, `locate.php`, `phy_baseline.php`) | Its own request only, and only as long as its own JS is prepared to wait. Still holds a worker. |
| A user-initiated action (`flash.php`, `bundle.php`) | Yes. The user pressed a button and is watching a spinner. |
| Cron / CLI (`scripts/notify_check.php`) | Yes. No request exists. |

`cached_read()` is how a page-rendered consumer reads something slow: it serves
the cached body, launches a detached producer, and returns immediately.

## The register

### Detached — these are the mechanism, not the hazard

| Site | What |
|---|---|
| `cached_read.php:24` | `nohup sh -c … &` — the single-flight producer launcher. Everything below that says "goes through `cached_read`" ends up here. |
| `ajax_info.php:116` | `nohup` SMART collection. Fire-and-forget; the tab polls for the result. |
| `flash.php:438` | `nohup` the flash itself. Must outlive the request. |
| `locate.php:150` | `nohup` the blink loop. Same. |

### Rendered inside someone else's page — the dangerous class

| Site | Reads | Blocks? |
|---|---|---|
| `dashboard.php` | `get_hba_info.sh` via `cached_read('overview', 60, serve_stale)` | **No.** This is the fix. A test fails if any foreground exec reappears in this file. |
| `settings.php:50` | `storcli_candidates` from `lib.sh` | Yes, but bounded: it stats a fixed list of paths looking for storcli binaries. No hardware, no controller, no unbounded wait. Acceptable — and worth re-checking if that function ever grows a probe. |

### The plugin's own endpoints — block their own request

| Site | Reads |
|---|---|
| `ajax_info.php:169` | Overview, via `cached_read`. **Non-blocking.** |
| `ajax_info.php:91` | `get_metrics.sh` (Performance polling) |
| `ajax_info.php:187` | `get_hba_info.sh` |
| `ajax_info.php:240` | `get_hba_health.sh` |
| `ajax_info.php:261` | the per-tab script map (drives / phy / events) |
| `ajax_info.php:135, 141, 291` | device and drive lookups |
| `render/drives.php:23` | `lsblk` — bounded, no hardware |
| `phy_baseline.php:140` | re-reads counters after a baseline reset |

**Investigated 2026-08-23, deliberately left alone.** Putting these behind
`cached_read()` was tried once and reverted — `render/phy.php` carries the
post-mortem. A cache only pays off for repeated access inside its TTL, and
these tabs load on a click: the TTL had always expired by the next visit, so
every visit got the empty `warming` answer, re-launched the producer, and the
data never appeared (issue #11). The Overview works on that path only because
it POLLS, which keeps its own cache warm. Converting the list tabs would make
them slower and no safer.

What was actually wrong on that path is fixed instead: the producer's stderr
was folded into the payload, so one warning made the cached JSON undecodable —
the mechanism behind half of issue #11, and still live for the two consumers
that do use `cached_read`.

**So this stands as accepted risk:** the tab endpoints still read hardware
synchronously. That is a smaller problem than the dashboard was — it blocks
only the request the plugin's own JS made, and only while someone has the
Monitor open — but it is the same shape, and it still holds a php-fpm worker
for the duration. The Overview was moved to `cached_read` for exactly this
reason and the rest were not. If the freeze is ever reported again with the
Dashboard *closed*, this table is where to look first.

### User-initiated — blocking is the point

`flash.php:124, 337, 359, 380` · `bundle.php:46` · `locate.php:120` ·
`notify.php:88` · `scripts/notify_check.php:23`

## Checking it

```bash
grep -rn "shell_exec\|exec(\|proc_open\|passthru\|system(" \
  source/usr/local/emhttp/plugins/hbaviewer --include=*.php | grep -v chart.umd
```

Anything not in this file is either new or was missed. `tests/ajax_render_test.php`
pins the one that caused the incident: `dashboard.php` may not contain a
foreground exec at all.
