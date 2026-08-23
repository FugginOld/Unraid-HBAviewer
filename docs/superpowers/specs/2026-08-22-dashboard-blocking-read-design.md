# The dashboard tile blocks the whole webGui — design

Reported 2026-08-22: "when the application is refreshing, the whole Unraid
instance is unresponsive for 10 seconds."

This is the most serious defect currently known in the plugin. It is not a
display bug and it is not confined to HBAviewer's own pages.

## The mechanism

`dashboard.php:19`:

```php
$raw = shell_exec('timeout 60 bash ' . escapeshellarg($SCRIPT) . ' 2>/dev/null') ?? '';
```

That is a **synchronous** hardware read, with a **60-second** ceiling, executed
inline while Unraid renders its own Dashboard page.

The comment above it says the read "stays cheap on every tile refresh" because
`get_hba_info.sh` self-caches for 60s. That is true exactly while the cache is
warm. It says nothing about the request that finds it **cold** — and one request
per minute always does. That request runs storcli or lsiutil against the
hardware and holds a PHP worker for as long as the controller takes to answer:
~10s on the reporter's box, and the code is prepared to wait six times longer.

The blast radius is the whole webGui rather than the plugin because of where it
runs. The Dashboard is the page Unraid users leave open; its tiles render
server-side in the page request. A worker held for ten seconds is a worker not
serving anything else, and Unraid's php-fpm pool is small.

## The plugin already solved this, one file over

`ajax_info.php:169` reads the same script through the same 60s TTL:

```php
$r = cached_read('overview', 60, 'bash ' . escapeshellarg("$scripts/get_hba_info.sh"));
```

`cached_read()` (`cached_read.php`) is the "slow read → serve cached → detached
job" orchestration: freshness check, single-flight launch behind a lock, atomic
tmp→rename swap. Its header states the rule this bug breaks in as many words:

> The foreground request **NEVER** blocks on the producer — a cold storcli scan
> can exceed the web timeout — so it returns `{state: ready|warming}` and the JS
> polls.

So the plugin has a correct non-blocking reader, a warming protocol, and a
client that already implements the poll (`hbaviewer.js`, `data-overview="warming"`
→ retry in 4s). The dashboard tile is the one consumer that does not use any of
it.

Two caches are involved and they are not the same thing:

| | Where | What it protects |
|---|---|---|
| Script self-cache, 60s | `get_hba_info.sh` → `/tmp/lsiutil_dash.json` | The hardware, from being read twice in a minute |
| `cached_read`, 60s | `/tmp/hbav_overview.out` | **The request**, from waiting for the script at all |

The dashboard has the first and not the second. The first is what makes the bug
intermittent — and therefore what has kept it alive, because nine refreshes in
ten are instant.

## What the tile must gain

A tile that does not block must be able to render before the data exists. The
Overview solved this with a warming state and a client poll; the dashboard tile
cannot poll, because Unraid renders it server-side and owns the refresh.

That is the one real design question here, and it has a cheap answer: the tile
renders its **last known good** values whenever a result file exists, whatever
its age, and shows the warming state only on a genuinely cold start — first ever
render, or `/tmp` cleared by a reboot. Unraid re-renders the Dashboard on its
own schedule, so a tile showing 60-second-old temperatures for one cycle is
correct behaviour for a dashboard tile, and is what the reporter already
believes is happening.

This means `cached_read()` needs one addition, because today it returns
`{state:'warming', body:''}` and discards a stale result it could have served.
The Overview wants that — it has a spinner and a poll. The dashboard wants the
stale body. An opt-in (`$opts['serve_stale']`) keeps the Overview's contract
byte-identical while giving the tile what it needs; the alternative, having the
tile read `/tmp/hbav_overview.out` itself, would duplicate the freshness and
swap rules that file exists to own.

## Scope

In:

- `dashboard.php` stops calling `shell_exec` and reads through `cached_read()`.
- `cached_read()` gains `serve_stale`, defaulting off.
- The tile renders a cold-start state.

Out:

- The 60s TTLs. Both are defensible and neither causes this.
- `get_hba_info.sh`'s own cache. It is doing its job.
- Any other `shell_exec` in the plugin — but see the sweep below, because this
  bug is a class, not an instance.

## The sweep this implies

The defect is not that one line was written badly; it is that nothing stops a
foreground request from blocking on hardware. Every entry point that renders
inside someone else's page request has the same exposure. The plan's last task
enumerates them and states, per call site, whether it can block and why that is
or is not acceptable — so the next one is caught by reading a list rather than
by a user reporting a frozen server.

## Verification

The suite cannot see this: it is a property of the request, not of the output.
The check that matters is on hardware, and it is specific — with the plugin
installed, clear the caches, load the Dashboard, and confirm the page returns
immediately with a warming or stale tile rather than after the read:

```bash
rm -f /tmp/hbav_overview.out /tmp/hbav_overview.lock /tmp/lsiutil_dash.json
time curl -s -o /dev/null 'http://localhost/Dashboard'
```

Before: roughly the controller's read time. After: immediate, every time,
including the first.
