# Plan 011: Stop the event log rendering entries from a different backend

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 2e79fca..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/event_archive.php source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php tests/event_archive_test.php tests/ajax_render_test.php`
> If any of those changed since this plan was written, compare the "Current
> state" excerpts against the live code before proceeding; on a mismatch, treat
> it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: `plans/006-ajax-render-tests.md` (DONE — this plan needs
  `tests/ajax_render_test.php` and the extracted `renderEventsTables()`)
- **Category**: bug
- **Planned at**: commit `2e79fca`, 2026-07-27

## Why this matters

The event archive is per-controller: `events_c0.json`, `events_c1.json`. But the
two backends emit **structurally different event records**, and both land in the
same file:

| Backend | Entry keys |
|---|---|
| storcli | `seq`, `time`, `code`, `description` |
| lsiutil | `seq`, `qualifier`, `data`, `timestamp` |

`renderEventsTables()` picks **one** table layout for the whole list, based on
the active backend. So the moment an archive holds both shapes, the active
renderer reaches for keys the foreign entries do not have — emitting a PHP
`Warning: Undefined array key` per missing field, and rendering rows that are
mostly empty cells.

This needs a backend switch to trigger, which is why it has not been reported.
But it is a real path, not a hypothetical one: a SAS2 box running lsiutil that
later installs `storcli` — exactly what the plugin's own Settings page and
README recommend for SAS3 cards — flips `hba_each` to the storcli backend while
`events_c0.json` still holds every lsiutil-shaped entry ever archived. **GitHub
issue #3's reporter is in precisely that position**: running lsiutil today, and
advised about storcli in the issue thread.

It was found while executing plan 006: pointing that plan's storcli and lsiutil
render tests at a shared temp archive produced exactly these warnings; giving
each its own directory removed them. That is a test-hygiene fix, and it was the
right call for 006 — but it papered over a real defect in the product, which is
what this plan addresses.

**Nothing is corrupted and no data is lost today.** `event_merge`'s dedup key is
`seq|time-or-timestamp`, which happens to work for both shapes, and the two
backends' `seq` formats differ enough (`"0x00000001"` vs `1`) that they do not
collide. The archive file is fine. Only the *display* is wrong.

## Current state

Files involved:

- `source/usr/local/emhttp/plugins/hbaviewer/event_archive.php` — 42 lines. The
  pure merge plus the injectable store. This is where the new helpers go.
- `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` — contains
  `renderEventsTables()`, the only consumer.
- `tests/event_archive_test.php` — unit tests for the pure functions.
- `tests/ajax_render_test.php` — render-layer tests (added by plan 006).

**`event_archive.php` in full** — the whole file, so you can see exactly where
the new functions belong:

```php
const EVENT_ARCHIVE_CAP = 2000;   // cap history growth (kind to the boot flash)

/* Fold `current` into `history`, dedup by seq|time, cap to EVENT_ARCHIVE_CAP.
 * Returns [kept, changed]; the caller writes only when `changed` so an
 * unchanged poll never touches the flash. */
function event_merge(array $history, array $current): array {
    $key  = fn($e) => ($e['seq'] ?? '') . '|' . ($e['time'] ?? ($e['timestamp'] ?? ''));
    $seen = [];
    foreach ($history as $e) $seen[$key($e)] = true;
    $changed = false;
    foreach ($current as $e) {
        $k = $key($e);
        if (!isset($seen[$k])) { $history[] = $e; $seen[$k] = true; $changed = true; }
    }
    if ($changed && count($history) > EVENT_ARCHIVE_CAP) {
        $history = array_slice($history, -EVENT_ARCHIVE_CAP);
    }
    return [$history, $changed];
}

/* Default per-controller store path. $dir is overridable for tests. */
function event_store_path(int $ctl, string $dir = '/boot/config/plugins/hbaviewer'): string {
    return "$dir/events_c$ctl.json";
}

function event_store_read(string $file): array {
    return is_file($file) ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];
}

function event_store_write(string $file, array $entries): void {
    @mkdir(dirname($file), 0755, true);
    @file_put_contents($file, json_encode($entries));
}
```

**The consumer**, `renderEventsTables()` in `ajax_info.php`. Locate it by name,
not line number. The relevant opening — this is the part you change:

```php
function renderEventsTables(array $data, string $dir = '/boot/config/plugins/hbaviewer'): string {
    $ctls    = $data['controllers'] ?? [$data];
    $storcli = ($data['backend'] ?? '') === 'storcli';
    $multi   = count($ctls) > 1;
    $out   = '';
    foreach ($ctls as $i => $ctl) {
        if ($multi) $out .= luCtlHead($i);
        if (isset($ctl['error'])) { $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['error']) . '</p>'; continue; }
        if (!empty($ctl['note'])) $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['note']) . '</p>';

        $file = event_store_path($i, $dir);
        [$entries, $changed] = event_merge(event_store_read($file), $ctl['entries'] ?? []);
        if ($changed) event_store_write($file, $entries);
        if (empty($entries)) { $out .= '<p class="lu-muted">No log entries.</p>'; continue; }
        $out .= '<p class="lu-muted" style="font-size:11px;margin:0 0 8px">'
              . count($entries) . ' entries &middot; archived to /boot (survives reboots &amp; ring-buffer wrap)</p>';

        // storcli backend if stamped; fall back to key-sniff pre-rollout.
        if ($storcli || (($data['backend'] ?? '') === '' && isset($entries[0]['description']))) {
```

Two things to notice, because the fix depends on both:

1. **The backend is already known** — `$data['backend']` is stamped by
   `hba_each` in `scripts/lib.sh`. The renderer uses it to pick the table.
   The empty-backend key-sniff on `isset($entries[0]['description'])` is a
   pre-rollout fallback that must keep working.
2. **The write happens before the render.** Whatever you filter for display must
   be filtered *after* `event_store_write`, so the archive on disk keeps every
   entry. Losing history would be a worse bug than the one being fixed.

**The exact shapes**, confirmed from the golden files:

`tests/expected/storcli_events.json`:

```json
{"entries":[{"seq":"0x00000001","time":"Wed Jun  3 20:33:17 2020","code":"0x00000000","description":"Firmware initialization started (PCI ID 00ac/1000/3000/1000)"}]}
```

`tests/expected/events_entries.json`:

```json
{"entries":[{"seq":1,"qualifier":"0x0001","data":"00000000 00000000 00000000","timestamp":"00000000:000012ab"}]}
```

So `description` uniquely identifies a storcli entry and `qualifier` uniquely
identifies an lsiutil one. That is the discriminator this plan uses.

**Repo conventions that apply here:**

- Pure, injectable helpers live in `event_archive.php` and are unit-tested; the
  file's own header states the intent: *"The merge is PURE over its inputs; the
  store is a thin read/write pair keyed by an injectable path — so the dedup rule
  and the flash-wear cap are testable without /boot or HTTP."* Keep the new
  helpers pure and put them in that file.
- Deliberate simplifications carry a `ponytail:` comment naming the ceiling and
  the upgrade path — see `scripts/get_metrics.sh:16-19` and
  `scripts/collect_smart.sh:10-11`.
- Test files use a `check(string $name, bool $ok): void` helper printing
  `PASS  `/`FAIL  `, a `$fails` counter, a summary line, and
  `exit($fails === 0 ? 0 : 1)`. Both files you are editing already define it.
- Short closures are used freely — `fn($e) => ...` appears in `event_merge`
  itself.

Relevant vocabulary from `source/usr/local/emhttp/plugins/hbaviewer/CONTEXT.md`
(the module glossary — the executor has not read it):

> **event archive** — `event_archive.php` (`event_merge`)
> Persists the firmware event ring-buffer to `/boot` so history survives reboots
> and ring-buffer wrap. `event_merge(history, current) -> [kept, changed]` is
> pure (dedup by `seq|time`, cap at `EVENT_ARCHIVE_CAP`);
> `event_store_{path,read,write}` is the injectable store. `ajax_info.php`
> `type=events` is a thin read→merge→write caller.

Keep that shape: the new helpers are pure, and the renderer stays a thin caller.

## Commands you will need

| Purpose            | Command                                                              | Expected on success        |
|--------------------|----------------------------------------------------------------------|----------------------------|
| PHP lint           | `find source tests -name '*.php' -print0 \| xargs -0 -r -n1 php -l`  | exit 0, "No syntax errors" |
| Archive unit tests | `php tests/event_archive_test.php`                                   | `event_archive: all pass`, exit 0 |
| Render tests       | `php tests/ajax_render_test.php`                                     | `ajax_render: all pass`, exit 0 |
| Full test suite    | `bash tests/run.sh`                                                  | `--- all pass ---`, exit 0, **zero** `Warning:`/`Deprecated:` lines |

If `php` is absent, `tests/run.sh` falls back to a `php:8.2-cli` Docker
container. **This plan adds tests, so you must be able to run the PHP half.** If
neither `php` nor Docker works, STOP and report.

## Scope

**In scope**:

- `source/usr/local/emhttp/plugins/hbaviewer/event_archive.php` (add two pure helpers)
- `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` (`renderEventsTables` only)
- `tests/event_archive_test.php` (unit tests for the helpers)
- `tests/ajax_render_test.php` (the integration regression test)

**Out of scope** (do NOT touch, even though they look related):

- **`event_merge` itself.** It is pure, covered, and correct — its dedup key
  already tolerates both shapes and the two backends' `seq` formats do not
  collide. Do not teach it about backends; that would put display concerns into
  a storage function.
- **`event_store_path` / the archive filename.** Splitting the archive into
  `events_c0_storcli.json` / `events_c0_lsiutil.json` is a plausible alternative
  fix, but it orphans every existing archive on upgrade — the history users
  already have would silently stop being read. Rejected for that reason; do not
  implement it.
- **Deleting or rewriting foreign entries.** The archive keeps everything. This
  plan changes what is *displayed*, never what is stored.
- `scripts/parse/events.sh` and `scripts/parse/storcli_events.sh` — the two entry
  shapes are correct for their backends and are golden-tested. Do not normalise
  them into a common shape; that would invalidate both goldens and lose
  backend-specific fields.
- The `smart`, `phy`, `drives` and `overview` renderers.

## Git workflow

- Branch: `advisor/011-event-archive-entry-shapes`, cut from `dev`
- One commit. Message style matches this repo's history — short imperative
  subject, no conventional-commit prefix. Suggested:
  `Show only event entries the active backend can render`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add the two pure helpers

Append to `source/usr/local/emhttp/plugins/hbaviewer/event_archive.php`, after
`event_store_write`:

```php

/* ── Entry shape ─────────────────────────────────────────────────────────────
   The two backends emit structurally different event records — storcli gives
   seq/time/code/description, lsiutil gives seq/qualifier/data/timestamp — and
   both are archived to the same per-controller file. A box that changes backend
   (a SAS2 system where the user later installs storcli) therefore accumulates
   both shapes, and a renderer built for one shape hits undefined keys on the
   other. These two helpers let the caller show only what it can format. */

/* Which backend produced this entry: 'storcli' | 'lsiutil' | '' when unknown. */
function event_shape(array $entry): string {
    if (isset($entry['description'])) return 'storcli';
    if (isset($entry['qualifier']))   return 'lsiutil';
    return '';
}

/* The entries $backend's table can actually render. Nothing is deleted — the
   archive on disk keeps every entry; this only decides what is displayed.
   An empty $backend falls back to the shape of the first entry, matching the
   renderer's own pre-rollout key-sniff.
   ponytail: hide foreign entries rather than render a second table for them.
   If anyone asks to see pre-switch history, render both tables instead. */
function event_visible(array $entries, string $backend): array {
    if ($backend === '') $backend = event_shape($entries[0] ?? []);
    if ($backend === '') return $entries;
    return array_values(array_filter($entries, fn($e) => event_shape($e) === $backend));
}
```

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/event_archive.php` → "No syntax errors detected"

### Step 2: Unit-test the helpers

Add to `tests/event_archive_test.php`, immediately before its final `echo` /
`exit` block. The `check()` helper is already defined at the top of that file.

```php
// ── entry shape: the two backends emit different records into one archive ────
$sc = ['seq'=>'0x01','time'=>'Wed Jun  3 20:33:17 2020','code'=>'0x00','description'=>'Firmware init'];
$lu = ['seq'=>1,'qualifier'=>'0x0001','data'=>'00000000','timestamp'=>'00000000:000012ab'];
check('shape storcli',  event_shape($sc) === 'storcli');
check('shape lsiutil',  event_shape($lu) === 'lsiutil');
check('shape unknown',  event_shape(['seq'=>'9']) === '');
check('shape empty',    event_shape([]) === '');

// visible: keep only what the active backend can render, drop nothing on disk
$mixed = [$lu, $sc, $lu, $sc];
check('visible storcli count',  count(event_visible($mixed, 'storcli')) === 2);
check('visible lsiutil count',  count(event_visible($mixed, 'lsiutil')) === 2);
check('visible storcli shape',  event_shape(event_visible($mixed, 'storcli')[0]) === 'storcli');
check('visible reindexes',      array_keys(event_visible($mixed, 'storcli')) === [0, 1]);
check('visible preserves order',
    event_visible([$sc, $lu, $sc], 'storcli')[0]['description'] === 'Firmware init');
// empty backend: infer from the first entry, matching the renderer's key-sniff
check('visible infers storcli', count(event_visible([$sc, $lu], '')) === 1);
check('visible infers lsiutil', count(event_visible([$lu, $sc], '')) === 1);
check('visible unknown passes through', count(event_visible([['seq'=>'9']], '')) === 1);
check('visible empty list',     event_visible([], 'storcli') === []);
```

**Verify**: `php tests/event_archive_test.php` → ends `event_archive: all pass`,
exit 0, including the thirteen new `PASS` lines.

### Step 3: Use it in the renderer

In `renderEventsTables()` in `ajax_info.php`, replace these four lines:

```php
        [$entries, $changed] = event_merge(event_store_read($file), $ctl['entries'] ?? []);
        if ($changed) event_store_write($file, $entries);
        if (empty($entries)) { $out .= '<p class="lu-muted">No log entries.</p>'; continue; }
        $out .= '<p class="lu-muted" style="font-size:11px;margin:0 0 8px">'
              . count($entries) . ' entries &middot; archived to /boot (survives reboots &amp; ring-buffer wrap)</p>';
```

with:

```php
        [$archived, $changed] = event_merge(event_store_read($file), $ctl['entries'] ?? []);
        if ($changed) event_store_write($file, $archived);
        // Archive everything, display only what this backend's table can format.
        // A box that switched backend keeps its old entries on disk; showing them
        // through the wrong renderer produces undefined-key warnings and blank rows.
        $entries = event_visible($archived, $data['backend'] ?? '');
        $hidden  = count($archived) - count($entries);
        if (empty($entries)) { $out .= '<p class="lu-muted">No log entries.</p>'; continue; }
        $out .= '<p class="lu-muted" style="font-size:11px;margin:0 0 8px">'
              . count($entries) . ' entries &middot; archived to /boot (survives reboots &amp; ring-buffer wrap)'
              . ($hidden > 0 ? ' &middot; ' . $hidden . ' from a previous backend not shown' : '') . '</p>';
```

Three details that matter:

1. **The write uses `$archived`, not `$entries`.** The full merged set goes to
   disk; the filtered set is display-only. Getting this backwards would delete
   the user's history — the exact opposite of what the archive exists for.
2. **`$hidden` is reported.** Silently dropping rows from a history view is how
   you generate a "my event log shrank" bug report. One clause avoids it.
3. **Everything below is unchanged.** The `if ($storcli || ...)` branch and both
   table bodies stay exactly as they are — they now simply never see a foreign
   entry.

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` → "No syntax errors detected"

**Verify**: `grep -c 'event_visible' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` → prints `1`

**Verify**: `grep -c 'event_store_write($file, $archived)' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` → prints `1`

### Step 4: Add the integration regression test

This is the test that proves the reported symptom is gone. Add to
`tests/ajax_render_test.php`, immediately before its final `echo` / `exit`
block.

```php
/* ── Mixed-shape archive: a box that changed backend ──────────────────────────
   storcli and lsiutil emit different event records into the same per-controller
   archive. Before this was handled, the active renderer hit undefined keys on
   the foreign-shaped rows and emitted PHP warnings. */
$dirMix = sys_get_temp_dir() . '/hbav_events_mix_' . getmypid();
@mkdir($dirMix, 0755, true);
array_map('unlink', glob("$dirMix/*.json") ?: []);

// Seed the archive with lsiutil history, as a SAS2 box would have.
renderEventsTables(['backend'=>'lsiutil','controllers'=>[['entries'=>[
    ['seq'=>1,'qualifier'=>'0x0001','data'=>'00000000','timestamp'=>'00000000:000012ab'],
    ['seq'=>2,'qualifier'=>'0x0002','data'=>'deadbeef','timestamp'=>'00000000:000012ac'],
]]]], $dirMix);

// Now the user installs storcli: same controller, same archive, new shape.
$warned = false;
set_error_handler(function () use (&$warned) { $warned = true; return true; });
$h = renderEventsTables(['backend'=>'storcli','controllers'=>[['entries'=>[
    ['seq'=>'0x01','time'=>'2026-07-01 10:00:00','code'=>'0x0113','description'=>'Drive inserted'],
]]]], $dirMix);
restore_error_handler();

check('mixed archive: no PHP warning',   $warned === false);
check('mixed archive: storcli row shown', str_contains($h, 'Drive inserted'));
check('mixed archive: lsiutil rows hidden', !str_contains($h, 'deadbeef'));
check('mixed archive: storcli columns',  str_contains($h, '<th>Description</th>'));
check('mixed archive: counts visible only', str_contains($h, '1 entries'));
check('mixed archive: reports hidden',   str_contains($h, '2 from a previous backend not shown'));

// The archive on disk must still hold every entry — nothing is deleted.
$onDisk = json_decode((string) file_get_contents("$dirMix/events_c0.json"), true);
check('mixed archive: history preserved on disk', count($onDisk) === 3);

array_map('unlink', glob("$dirMix/*.json") ?: []);
@rmdir($dirMix);
```

**Verify**: `php tests/ajax_render_test.php` → ends `ajax_render: all pass`,
exit 0, including the seven new `PASS` lines.

### Step 5: Prove the test catches the bug

A regression test you have not seen fail proves nothing. Temporarily revert the
renderer and confirm the new checks go red.

```bash
git stash push -- source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php
php tests/ajax_render_test.php; echo "exit=$?"
git stash pop
```

**Verify**: with the renderer reverted the run **fails** (`exit=1`) and reports
`FAIL  mixed archive: no PHP warning` among others. Then after `git stash pop`:

```bash
php tests/ajax_render_test.php; echo "exit=$?"
```

**Verify**: `exit=0`, `ajax_render: all pass`.

> **Note on `git stash push -- <path>`**: if the working tree has no changes to
> that file (because you already committed), the stash is a no-op and the test
> will pass, telling you nothing. Run this step **before** committing, and
> confirm the file actually reverted with
> `grep -c 'event_visible' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php`
> → must print `0` while stashed.

### Step 6: Lint and full suite

**Verify**: `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` → exit 0

**Verify**: `bash tests/run.sh` → `--- all pass ---`, exit 0

**Verify**: the suite output contains **zero** `Warning:` or `Deprecated:` lines:
`bash tests/run.sh 2>&1 | grep -ci 'warning:\|deprecated:'` → prints `0`

## Test plan

**New unit tests** — thirteen cases in `tests/event_archive_test.php`, following
the structure of the existing `event_merge` blocks in that file:

| Group | Covers |
|---|---|
| `event_shape` (4) | both shapes, an unknown record, an empty array |
| `event_visible` (9) | filtering per backend, key reindexing, order preservation, backend inference from the first entry, unknown-shape pass-through, empty list |

**New integration test** — seven cases in `tests/ajax_render_test.php`, modelled
on that file's existing events block, which likewise seeds a temp archive
directory and cleans it up. It reproduces the actual scenario: seed an lsiutil
archive, then render as storcli against the same directory.

The two that matter most:

- **`mixed archive: no PHP warning`** — the reported symptom, asserted directly
  via a temporary error handler.
- **`mixed archive: history preserved on disk`** — proves the fix hides rather
  than deletes. If this ever fails, the change has become a data-loss bug.

Step 5 verifies the tests fail against unfixed code, which is what makes them
regression tests rather than decoration.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'function event_shape' source/usr/local/emhttp/plugins/hbaviewer/event_archive.php` prints `1`
- [ ] `grep -c 'function event_visible' source/usr/local/emhttp/plugins/hbaviewer/event_archive.php` prints `1`
- [ ] `grep -c 'event_visible' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` prints `1`
- [ ] `grep -c 'event_store_write($file, $archived)' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` prints `1` — the **full** set is what reaches disk
- [ ] `grep -c 'ponytail:' source/usr/local/emhttp/plugins/hbaviewer/event_archive.php` prints `1`
- [ ] `php tests/event_archive_test.php` exits 0 with the 13 new `PASS` lines
- [ ] `php tests/ajax_render_test.php` exits 0 with the 7 new `PASS` lines
- [ ] Step 5 demonstrated the new checks failing against the reverted renderer, then passing again
- [ ] `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` exits 0
- [ ] `bash tests/run.sh` exits 0 and prints `--- all pass ---`
- [ ] `bash tests/run.sh 2>&1 | grep -ci 'warning:\|deprecated:'` prints `0`
- [ ] `git status --porcelain` shows exactly four modified files: `event_archive.php`, `ajax_info.php`, `tests/event_archive_test.php`, `tests/ajax_render_test.php`
- [ ] `plans/README.md` status row for 011 updated

## STOP conditions

Stop and report back (do not improvise) if:

- `renderEventsTables()` does not match the excerpt in "Current state", or
  `event_archive.php` differs from the full file quoted above.
- You cannot run the PHP tests (no `php`, no Docker). This plan is mostly tests.
- Step 5 shows the new checks **passing** against the reverted renderer. That
  means they are not testing what they claim — most likely the `git stash push`
  was a no-op because you committed first. Re-read the note in that step.
- The `mixed archive: history preserved on disk` check fails. You have made the
  fix delete archived entries, which is worse than the bug. Do not adjust the
  assertion to match — fix the write.
- You find yourself changing `event_merge`, `event_store_path`, or either
  `parse/*events*.sh` parser. All are explicitly out of scope; report the
  reasoning instead.
- You conclude the archive should be split into per-backend files. That is the
  rejected alternative — it orphans existing history on upgrade. Report it
  rather than implementing it.

## Maintenance notes

- **The discriminator is a key-presence check**, not a stamped field:
  `description` means storcli, `qualifier` means lsiutil. That works because the
  two parsers emit disjoint key sets, both golden-tested
  (`tests/expected/storcli_events.json`, `tests/expected/events_entries.json`).
  If a third backend is ever added — or either parser gains a `description` or
  `qualifier` field — `event_shape()` is the single place to update, and the
  golden files are what will tell you the shapes changed.
- **`event_visible` hides; it never deletes.** That is deliberate and the test
  suite enforces it. If someone later "tidies up" by filtering before the write,
  every user who has ever switched backend silently loses their pre-switch
  history at the next poll.
- **The hidden-count clause is the user-visible half of the fix.** Without it a
  backend switch looks like data loss. If the wording changes, keep the fact.
- **Upgrade path if anyone asks to see pre-switch history**: render a second
  table for the foreign shape rather than trying to normalise the two record
  formats into one. The fields genuinely differ — `description` is human-readable
  text, `qualifier`/`data` are hex — and merging them would lose information.
  That is what the `ponytail:` comment records.
- **What a reviewer should scrutinise**: that `event_store_write` receives
  `$archived` and not `$entries`. That one variable is the difference between a
  display fix and a data-loss bug.
