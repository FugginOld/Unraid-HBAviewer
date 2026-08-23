# Dashboard Non-Blocking Read Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Unraid Dashboard page must never wait on a controller read. `dashboard.php` reads through `cached_read()` like every other consumer, and serves its last known good values while a detached producer refreshes them.

**Architecture:** `cached_read()` gains an opt-in `serve_stale` that returns the existing result body with `state => 'stale'` instead of discarding it. `dashboard.php` drops its `shell_exec` and takes that path. The tile renders three states — ready, stale, and a cold start with no data at all — where today it renders two and blocks to avoid the third.

**Tech Stack:** PHP 8 (no framework), bash producers, PHP unit tests via `tests/run_php.sh`, golden-file shell tests via `tests/run.sh`.

**Spec:** `docs/superpowers/specs/2026-08-22-dashboard-blocking-read-design.md`

## Global Constraints

- Run from the repo root: `cd c:/Users/Joe/Documents/GitHub/Unraid-HBAviewer`.
- Full verification is `bash tests/run.sh` (~3 min). It must print `--- all pass ---` at the end of **every** task.
- **`cached_read()`'s existing contract must not move.** `ajax_info.php:169` and the `data-overview="warming"` poll in `hbaviewer.js` depend on a stale result returning `{state:'warming', body:''}`. `serve_stale` defaults **off**; `tests/cached_read_test.php` must keep passing untouched, and if a change there is unavoidable the plan is wrong.
- **Do not "fix" the 60s TTLs or `get_hba_info.sh`'s own cache.** Neither causes this. The spec says why.
- No golden in `tests/expected/` should change. If one does, something rendered differently and that is a finding, not a regeneration.
- Commit after every task. Message style: a sentence saying what changed and why, no `feat:`/`chore:` prefixes.
- **This lands before `2026-08-22-dashboard-card-grouping.md`.** Both rewrite `dashboard.php`'s render path; this one changes where `$data` comes from, that one changes how `$controllers` become tiles. Doing them in the other order means rebasing the harder change onto the more urgent one.

---

### Task 1: `serve_stale` in `cached_read()`

Adds the option with no callers. Nothing changes on screen.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/cached_read.php`
- Test: `tests/cached_read_test.php`

**Interfaces:**
- Produces: `cached_read($key, $ttl, $producer, ['serve_stale' => true])` → `['state' => 'stale', 'body' => '<last result>']` when a result file exists but is older than `$ttl`. Unchanged (`warming`, empty body) when the option is absent or the file does not exist. Task 2 consumes it.

- [ ] **Step 1: Write the failing tests**

Append to `tests/cached_read_test.php`. Four cases, because three of them are the ones that make this safe rather than merely working:

```php
// A stale result is still a result. The dashboard tile has no way to poll --
// Unraid renders it server-side -- so 60-second-old temperatures beat a blank
// tile, and beat blocking the page to avoid one.
// The producer must STILL be launched: serving stale is not the same as
// deciding the data is fine.
// And the default must not move: the Overview's warming poll is built on an
// empty body meaning "nothing to show yet".
```

1. `serve_stale` + stale file → `state === 'stale'`, body is the old content, **and** the launcher was called.
2. `serve_stale` + **no** file at all → `state === 'warming'`, empty body (a cold start has nothing to serve; the tile must handle this, not be handed `''` as if it were data).
3. **No** `serve_stale` + stale file → `state === 'warming'`, empty body — the existing behaviour, asserted from the outside so a later refactor cannot quietly change it.
4. `serve_stale` + **fresh** file → `state === 'ready'`. The option must not turn a fresh read into a stale one.

Use the injected `now` and `launch` opts the existing tests use — do not touch the clock or shell out.

- [ ] **Step 2: Implement**

In `cached_read()`, after the freshness check and **after** the launch block (the producer is launched either way — serving stale must not stop the refresh):

```php
if (!empty($opts['serve_stale']) && is_file($result) && filesize($result) > 0) {
    return ['state' => 'stale', 'body' => (string) file_get_contents($result)];
}
```

Placed after the launch so a stale-serving caller still triggers the refresh it is standing in for. Keep the `filesize > 0` guard for the same reason the fresh path has it: never serve a truncated file.

- [ ] **Step 3: Verify** — `bash tests/run.sh` prints `--- all pass ---`.

---

### Task 2: The tile stops blocking

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/dashboard.php` (lines 14-29)
- Test: `tests/dashboard_test.php` if it exists; otherwise add the render assertions to `tests/ajax_render_test.php` beside the other renderer checks.

**Interfaces:**
- Consumes: `cached_read()` with `serve_stale` from Task 1.
- Produces: a tile that renders from `ready`, `stale`, or cold-start with no data.

- [ ] **Step 1: Write the failing test**

The blocking itself is not observable from PHP — what is observable is that `dashboard.php` no longer contains the call that does it, and that each of the three states renders something coherent. Assert both:

```php
// The bug, pinned as a text check because the blocking is a property of the
// REQUEST and no unit test can see it: this file must not shell out to the
// hardware in the foreground. Every other consumer reads through cached_read().
check('the dashboard does not read hardware synchronously',
      !preg_match('~shell_exec\(\s*[\'"]timeout~', $dashSrc));
```

Then one test per state, driving the render with a fake `/tmp` dir: `ready` shows the temperature; `stale` shows the same values as `ready` (a stale tile is a normal tile — it is not marked, see Step 2); cold start shows the warming copy and **no** fabricated zeroes.

- [ ] **Step 2: Implement**

Replace the `shell_exec` block:

```php
require_once __DIR__ . '/cached_read.php';
$r    = cached_read('overview', 60, 'bash ' . escapeshellarg($SCRIPT), ['serve_stale' => true]);
$raw  = $r['body'];
$data = $raw !== '' ? json_decode($raw, true) : null;
```

Note it shares the `'overview'` key with `ajax_info.php` deliberately: same script, same TTL, same data. Two keys would mean two detached producers reading the same controller a minute apart for no gain.

Cold start (`$data === null` and `state !== 'ready'`) renders a tile saying the first read is in progress — **not** the existing `'Backend unavailable'` error, which claims something is broken when nothing is. Keep `'Backend unavailable'` for the case it means: a `ready` result that will not parse.

**Do not mark the stale state on screen.** A dashboard tile showing values from the last minute is a dashboard tile working correctly; a "stale" badge that appears for one cycle every minute is noise that would train the reporter to ignore it. Staleness worth surfacing is P2-D's job and has its own threshold.

- [ ] **Step 3: Verify** — `bash tests/run.sh` prints `--- all pass ---`.

---

### Task 3: Enumerate every foreground hardware read

The point of the task: the defect is a class. One fixed instance and no list means the next one ships the same way.

**Files:**
- Create: `docs/foreground-reads.md`
- Modify: `tests/ajax_render_test.php`

- [ ] **Step 1: Enumerate**

```bash
grep -rn "shell_exec\|exec(\|proc_open\|passthru\|system(" \
  source/usr/local/emhttp/plugins/hbaviewer --include=*.php | grep -v chart.umd
```

For each hit, record in `docs/foreground-reads.md`: the call site, whether it runs inside someone else's page request (the Dashboard tile and any `.page` do; an `ajax_*.php` endpoint the plugin's own JS calls does not), its worst-case duration, and whether it may block. `cached_read()`'s own `nohup … &` launcher is the one call that is *supposed* to be there and should be named as such, so the list does not read as seven bugs.

- [ ] **Step 2: Pin the rule**

```php
// dashboard.php renders inside Unraid's OWN Dashboard page. A synchronous
// hardware read there holds a php-fpm worker for the controller's read time
// and takes the whole webGui down with it -- reported 2026-08-22, ~10s per
// occurrence, once a minute. Nothing in this file may shell out in the
// foreground; cached_read() is the way in.
check('the dashboard tile never reads hardware in the foreground',
      !preg_match('~(shell_exec|proc_open|passthru|\bsystem)\s*\(~', $dashSrc));
```

Scoped to `dashboard.php`, not swept across the plugin: an endpoint the plugin's own JS calls may legitimately block its own request, and a sweep would fail on `cached_read.php`'s launcher, which is the fix rather than the bug.

- [ ] **Step 3: Verify** — `bash tests/run.sh` prints `--- all pass ---`. Mutate: put a `shell_exec` back into `dashboard.php` and confirm the check fails.

---

## Hardware verification

The suite proves the call is gone. Only the box proves the freeze is:

```bash
rm -f /tmp/hbav_overview.out /tmp/hbav_overview.lock /tmp/lsiutil_dash.json
time curl -s -o /dev/null 'http://localhost/Dashboard'    # must return immediately
# then, within the same minute, confirm the tile fills in on its own:
sleep 15 && curl -s 'http://localhost/Dashboard' | grep -o 'lu-d-tile' | head -1
```

Then leave the Dashboard open for five minutes and use the webGui normally. The
report is a ten-second freeze once a minute; five minutes without one is the
result.
