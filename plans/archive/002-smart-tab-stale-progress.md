# Plan 002: Stop the SMART tab wedging forever on a dead collector's progress file

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 0346777..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php`
> If that file changed since this plan was written, compare the "Current state"
> excerpt against the live code before proceeding; on a mismatch, treat it as a
> STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `0346777`, 2026-07-26

## Execution record

**Status: DONE — merged to `dev`. Ships in the next release.**

| Field | Value |
| ----- | ----- |
| Executed | 2026-07-27, by a dispatched executor subagent in an isolated worktree |
| Commit | `04b7335` — "Time out a dead SMART collector's progress marker" |
| Merged | `6e19e68` into `dev` |
| Based on | `dca10c9` (Release 2026.07.27); drift check clean, excerpt matched exactly |
| Diff | 1 file, 11 insertions, 2 deletions — `ajax_info.php` only |

**Review findings (verified independently, not taken from the executor's report):**

- Scope clean. One file, nothing outside the in-scope list, no uncommitted
  leftovers in the worktree.
- All done criteria re-run and passing: the const and its single use, refresh
  unlinking both files, the bare `is_file($prog)` gone, and — the one that
  matters for the JS — **both** progress messages still carrying
  `data-smart="collecting"`.
- Control flow confirmed by reading the region: a stale marker skips the
  progress branch and falls through to the `shell_exec` collector launch, so the
  tab self-heals rather than returning an empty response.
- `php -l` clean across all 14 files; `bash tests/run.sh` exits 0 with both
  halves passing (Docker was available this run, unlike for plan 001).
- Behaviour checked against the decision table rather than greps alone:

  | Marker state | Outcome |
  |---|---|
  | absent | launch fresh collector |
  | 5s old (live) | report progress |
  | 299s old (slow but live) | report progress |
  | 301s old (dead) | **launch fresh collector — the fix** |
  | 600s old (dead) | launch fresh collector |
  | 5s old + Refresh | **launch fresh collector — the escape hatch** |

**Still outstanding**: the manual repro on real hardware (plant a stale
`.progress` file, confirm the tab recovers). Not possible off-box; the executor
correctly said so rather than claiming it.

## Why this matters

The SMART tab collects drive health in a detached background job and polls a
progress marker file while it runs. The marker has no freshness check. If the
collector dies — killed, `smartctl` wedged on a bad drive, the PHP request that
spawned it torn down — the marker survives and every subsequent request takes
the "still collecting" branch. The tab then reads "Collecting SMART… N drives"
forever.

Worse, the Refresh button cannot recover it: the refresh path unlinks the
*cache* file but never the *marker*, so the one control the user has is the one
thing that does not help. The only escape is a reboot, because `/tmp` is tmpfs
on Unraid.

After this plan, a marker older than the collection budget is treated as
evidence of a dead collector: the request falls through, launches a fresh
collector, and the tab recovers on its own. Refresh also clears the marker, so
the user has a working manual escape as well.

## Current state

File involved:

- `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` — the AJAX endpoint.
  The `smart_all` handler is lines 29–52.

The handler exactly as it exists today, `ajax_info.php:29-52`:

```php
/* ── SMART tab: all drives, collected in the background ─────────────────────
   Returns the cached table if fresh; otherwise reports progress (or launches a
   detached collector) so the request never blocks — the tab polls this. */
if ($type === 'smart_all') {
    header('Content-Type: text/html; charset=utf-8');
    $cache = '/tmp/lsiutil_smart.json';
    $prog  = $cache . '.progress';
    if (($_GET['refresh'] ?? '') === '1') { @unlink($cache); }

    if (is_file($cache) && (time() - filemtime($cache)) < 600) {
        echo renderSmartTable(json_decode((string) file_get_contents($cache), true) ?: []);
        exit;
    }
    if (is_file($prog)) {
        echo '<div class="lu-loading" data-smart="collecting">Collecting SMART… '
           . htmlspecialchars(trim((string) file_get_contents($prog)))
           . ' drives (you can use other tabs)</div>';
        exit;
    }
    shell_exec('nohup bash ' . escapeshellarg("$scripts/collect_smart.sh") . ' >/dev/null 2>&1 &');
    echo '<div class="lu-loading" data-smart="collecting">Collecting SMART in the background — this can take ~20s '
       . 'for all drives. You can switch to other tabs; results appear here when ready.</div>';
    exit;
}
```

The two defects are on line 36 (`@unlink($cache)` without `$prog`) and line 42
(`is_file($prog)` with no age test).

**Why an age test is the right check** — the collector rewrites the marker on
every drive, so a live collector keeps its mtime moving. From
`source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh:24-32`:

```bash
lsblk -S -o NAME,WWN,SERIAL,MODEL -n 2>/dev/null | awk '$2 ~ /^0x/' | while read -r name wwn serial model; do
    i=$(( i + 1 )); echo "$i/$total" > "$PROG"
    smart=$(bash "$DIR/read_smart.sh" "/dev/$name")
    ...
done
printf ']}' >> "$TMP"

mv -f "$TMP" "$OUT"
rm -f "$PROG"
```

So the marker's mtime advances once per drive and the file is removed on clean
completion. A marker that has not been touched for minutes means the loop is
not running.

**Choosing the budget.** Each drive costs roughly one `smartctl` call. The UI
already tells the user "~20s for all drives". A stalled `smartctl` can block for
well over a minute on a failing disk, so the budget must be generous enough not
to kill a slow-but-live collection. **300 seconds** between marker updates is
the value this plan uses: far beyond any healthy per-drive read, far below the
"wedged until reboot" status quo.

**Repo conventions that apply here:**

- Deliberate simplifications are marked with a `ponytail:` comment naming the
  ceiling and the upgrade path. Existing examples:
  `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_metrics.sh:16-19` and
  `source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh:10-11`.
  Match this style for the new constant.
- Named constants in the PHP files use `const` at file scope in uppercase — see
  `source/usr/local/emhttp/plugins/hbaviewer/flash.php:16-19` and
  `source/usr/local/emhttp/plugins/hbaviewer/event_archive.php:10`
  (`const EVENT_ARCHIVE_CAP = 2000;   // cap history growth (kind to the boot flash)`).
  Follow that pattern.
- Freshness checks in this codebase are written as
  `(time() - filemtime($f)) < $ttl` — see `ajax_info.php:38` and
  `source/usr/local/emhttp/plugins/hbaviewer/cached_read.php:25`. Match it.

Relevant vocabulary from `source/usr/local/emhttp/plugins/hbaviewer/CONTEXT.md`
(the module glossary — the executor has not read this file). The "cached read"
entry describes the sibling pattern this handler informally follows:

> The "slow read → serve cached → detached job" orchestration in one place:
> freshness, single-flight lock, atomic tmp→rename swap. Returns
> `{state: ready|warming, body}`; the foreground never blocks and the JS polls
> the `warming` marker.

Note that `cached_read.php` already solves the equivalent problem correctly, at
`cached_read.php:32`:

```php
    if (!is_file($lock) || ($now - filemtime($lock)) > $lockTtl) {
```

with `$lockTtl = 120` and the comment "a dead job's lock can't wedge us
forever". This plan brings the SMART handler in line with that existing,
already-reasoned posture. **Do not** refactor the SMART handler to *use*
`cached_read()` — see "Out of scope".

## Commands you will need

| Purpose         | Command                                                              | Expected on success        |
|-----------------|----------------------------------------------------------------------|----------------------------|
| PHP lint        | `find source tests -name '*.php' -print0 \| xargs -0 -r -n1 php -l`  | exit 0, "No syntax errors" |
| Full test suite | `bash tests/run.sh`                                                  | `--- all pass ---`, exit 0 |

If `php` is not installed locally, `tests/run.sh` falls back to a
`php:8.2-cli` Docker container. If neither is available, the shell half must
still pass; say so in your report.

## Scope

**In scope** (the only file you should modify):

- `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php`

**Out of scope** (do NOT touch, even though they look related):

- `source/usr/local/emhttp/plugins/hbaviewer/cached_read.php` — do not refactor
  the SMART handler to use `cached_read()`. It looks like the same problem, but
  `cached_read` returns a body and this handler needs to render a progress
  count from the marker's *contents*, not just its existence. Rewriting it is a
  much larger change with a much larger blast radius than the two-line fix this
  plan calls for.
- `source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh` — do not
  add trap handlers or lock files to the collector. A collector that is
  `kill -9`'d can never clean up after itself, so the reader must be the one to
  time it out. That is what this plan does.
- The 600-second cache TTL on line 38. That is a separate policy and it is fine.
- The `luSmartAll` JavaScript in
  `source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php:325-339`. The polling
  loop keys off the `data-smart="collecting"` marker in the HTML and needs no
  change — once the server stops emitting that marker, the JS stops polling by
  itself.

## Git workflow

- Branch: `advisor/002-smart-tab-stale-progress`
- One commit. Message style matches this repo's history — short imperative
  subject, no conventional-commit prefix (e.g. `Add ca_profile.xml for HBAviewer
  plugin metadata`). Suggested: `Time out a dead SMART collector's progress marker`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add the staleness constant

In `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php`, immediately after
the `$scripts` assignment on line 16, add:

```php

/* A collector that was killed (or whose smartctl wedged) leaves its progress
   marker behind, and /tmp only clears on reboot — so treat a marker this stale
   as a dead job and start a fresh collection instead of reporting progress
   forever. The collector rewrites the marker once per drive, so a live one
   never goes this quiet.
   ponytail: a wall-clock timeout, not a liveness check on the PID — a PID file
   is the upgrade path if a collector ever legitimately stalls this long. */
const SMART_PROGRESS_TTL = 300;
```

**Verify**: `grep -n 'SMART_PROGRESS_TTL' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php`
→ prints the `const` line

### Step 2: Make Refresh clear the marker as well as the cache

Replace line 36:

```php
    if (($_GET['refresh'] ?? '') === '1') { @unlink($cache); }
```

with:

```php
    if (($_GET['refresh'] ?? '') === '1') { @unlink($cache); @unlink($prog); }
```

This is what makes the Refresh button an actual escape hatch rather than a
no-op when the tab is wedged.

**Verify**: `grep -n 'unlink($cache); @unlink($prog)' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php`
→ prints one line

### Step 3: Age-gate the progress branch

Replace line 42:

```php
    if (is_file($prog)) {
```

with:

```php
    if (is_file($prog) && (time() - filemtime($prog)) < SMART_PROGRESS_TTL) {
```

Change nothing else in that branch — the rendered message and the
`data-smart="collecting"` attribute stay exactly as they are, because the JS
polling loop depends on that attribute.

Note the control flow this produces, and confirm you understand it before
moving on: when the marker is stale, this branch is skipped, execution reaches
the `shell_exec(... collect_smart.sh ...)` launch on line 48, and the new
collector immediately overwrites the stale marker on its first drive. No
explicit unlink is needed on that path.

**Verify**: `grep -n 'filemtime($prog)) < SMART_PROGRESS_TTL' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php`
→ prints one line

### Step 4: Lint and run the suite

**Verify**: `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` → exit 0

**Verify**: `bash tests/run.sh` → ends with `--- all pass ---`, exit code 0

## Test plan

**No new automated tests, and this is a deliberate decision — read why before
deciding to add some.**

The `smart_all` handler is top-level dispatch code inside `ajax_info.php`, not a
function. Reaching it requires the file to execute its full request-handling
path, which shells out to hardware-reading scripts. There is no fixture harness
in this repo that can drive it — every PHP test here
(`tests/config_test.php`, `tests/view_test.php`, `tests/event_archive_test.php`,
`tests/cached_read_test.php`, `tests/flash_php_test.php`) covers *pure
functions* by requiring the file and calling them directly.

Extracting this handler into a testable pure function is the right long-term
move, but it belongs with the wider render-layer extraction in
`plans/006-ajax-render-tests.md`, not bolted onto a two-line bug fix.

The existing suite passing unchanged is the regression check for this plan.

**Manual verification (do this if you have an Unraid box; it is the real proof):**

1. Open the Monitor and switch to the SMART tab. Let it finish collecting.
2. Simulate a dead collector:
   ```bash
   echo "3/12" > /tmp/lsiutil_smart.json.progress
   touch -d '10 minutes ago' /tmp/lsiutil_smart.json.progress
   rm -f /tmp/lsiutil_smart.json
   ```
3. Reload the SMART tab. **Before this fix** it shows "Collecting SMART… 3/12
   drives" indefinitely. **After this fix** it launches a fresh collection and
   populates the table within roughly 20 seconds.
4. Repeat step 2, then click **Refresh** instead of reloading. It must also
   recover.

Record in your report whether you were able to run this manual check or not.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'SMART_PROGRESS_TTL' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` prints `2` (the const and its one use)
- [ ] `grep -c 'unlink($cache); @unlink($prog)' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` prints `1`
- [ ] `grep -n 'if (is_file($prog)) {' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` returns no matches
- [ ] `grep -c 'data-smart="collecting"' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` prints `2` (both messages still carry the attribute the JS polls on)
- [ ] `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` exits 0
- [ ] `bash tests/run.sh` exits 0 and prints `--- all pass ---`
- [ ] `git status --porcelain` shows exactly one modified source file: `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` (plus `plans/README.md`)
- [ ] `plans/README.md` status row for 002 updated

## STOP conditions

Stop and report back (do not improvise) if:

- The `smart_all` handler does not match the excerpt in "Current state" — in
  particular if someone has already added an age check, or moved the handler
  into a function. The fix may already be in, or may need to go somewhere else.
- The `data-smart="collecting"` attribute is missing from either message after
  your edit. The JS at `hbaviewer.php:334` tests for it with
  `/data-smart="collecting"/` and the tab will silently stop polling without it.
- You find yourself wanting to modify `collect_smart.sh` to clean up its own
  marker. That does not solve the problem — a hard-killed process cannot run
  cleanup — and it is out of scope. Report it instead.
- `bash tests/run.sh` fails. It passes at commit `0346777` for the shell half.

## Maintenance notes

- **The 300-second budget is a guess grounded in one number**: the UI's own
  "~20s for all drives" estimate, padded heavily for a stalling `smartctl`. If
  anyone reports the SMART tab restarting collection on a large array while it
  was legitimately still working, raise `SMART_PROGRESS_TTL` — do not remove the
  check.
- **The same class of bug does not exist in `cached_read.php`**, which already
  has `lock_ttl` for exactly this reason. If a third "detached job plus marker
  file" surface is ever added, it needs its own timeout from day one; that is
  now the established pattern in this codebase, in two places.
- **What a reviewer should scrutinise**: that the stale path really does fall
  through to the collector launch on line 48 rather than returning an empty
  response, and that both progress messages still carry
  `data-smart="collecting"`. Those two things are what keep the tab
  self-healing.
- **Deferred out of this plan**: extracting `smart_all` into a testable
  function. Tracked as part of `plans/006-ajax-render-tests.md`.
