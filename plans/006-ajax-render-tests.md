# Plan 006: Make the AJAX render layer testable, and test it

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 7d8d4d7..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php tests/run_php.sh`
> If either file changed since this plan was re-baselined, compare the "Current
> state" excerpts against the live code before proceeding; on a mismatch,
> treat it as a STOP condition.
>
> **Re-baselined 2026-07-27 from `0346777` to `7d8d4d7`.** Plan 002 landed in
> between and modified `ajax_info.php` (added `const SMART_PROGRESS_TTL` and an
> age check in the `smart_all` handler), shifting every line below it by 9. All
> line numbers in this plan have been updated to match `7d8d4d7`. **Locate code
> by its content, not by line number** — the numbers are navigation aids only.
>
> **This is a refactor that must not change any output.** Every extraction here
> is mechanical: the same code, moved into a function, returning instead of
> echoing. If you find yourself improving the HTML while you move it, stop —
> that belongs in `plans/007-escape-renderer-output.md`.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: tests
- **Planned at**: commit `0346777`, 2026-07-26; **re-baselined to `7d8d4d7`, 2026-07-27**

## Execution record

**Status: DONE — merged to `dev`. Ships in the next release.**

| Field | Value |
| ----- | ----- |
| Executed | 2026-07-27, by a dispatched executor subagent in an isolated worktree |
| Commits | `ddc6392` extract → `aa99798` tests → `43c53c5` revision |
| Merged | `23b9646` into `dev` |
| Diff | 3 files, 185 insertions, 12 deletions |
| Review | Two rounds — REVISE then APPROVE |

**Dispatch note**: the executor was told to branch off `dev`, not accept the
default `main`-based worktree. That mattered here — `main` predates plan 002's
change to `ajax_info.php`, so a `main`-based extraction would have been taken
from the wrong file. Branching off `dev` also made `plans/` readable in the
worktree, so the plan did not need inlining.

**The refactor is inert**, which is this plan's entire claim. A
whitespace-insensitive diff of `ajax_info.php` shows only: the CLI guard block,
three `if ($type === 'x') {` → `function renderXTables(...)` swaps, three
`echo $out; exit;` → `return $out;` swaps, three one-line dispatch statements,
and the `$dir` parameter with its doc comment. No markup, logic or escaping
changed.

### Round one found a hole worth recording

The test file as originally specified **could report a clean pass having run
zero assertions.** Reverting `ajax_info.php` to its pre-refactor state and
running the tests produced no output and **exit 0**.

Cause: without the CLI guard, `require_once` executes the real dispatch, hits
the `overview` branch, echoes an error and calls `exit;` — terminating the
process mid-require, before the first `check()`. A bare `exit` is status 0, and
`run_php.sh` chains with `&&`, so it propagates as success to
`--- all pass ---`. The guarantee this plan exists to create was silently
voidable by anyone who moved or removed the guard.

Fixed with a `register_shutdown_function` before the require that fails loudly
if the file aborted early — detection from outside the require's control flow,
since nothing after it would run. **This was a defect in the plan, not in the
executor's work**; the test file followed the plan verbatim.

Verified at review, both directions:

| Check | Result |
|---|---|
| Guard reverted → must fail loudly | `ajax_render: ABORTED before assertions ran`, exit 1 |
| Guard restored → must pass | `ajax_render: all pass`, exit 0 |
| Assertions actually executing | 37 PASS lines |
| Archive cross-contamination warnings | 0 |

Round one also split the event-archive temp directories per backend. Sharing one
directory merged storcli- and lsiutil-shaped entries, and whichever branch
rendered next hit undefined-array-key warnings on the foreign rows.

### A finding this surfaced (not fixed here)

That cross-contamination is **not** purely a test artifact. A box that changes
backends over its lifetime — a SAS2 system where the user later installs
storcli — accumulates both entry shapes in the same `events_c{i}.json`, and the
renderer warns on the foreign-shaped rows. The executor's diagnosis is the
sharper one: this belongs to `event_archive.php`'s merge/store contract rather
than the renderer, since `event_merge` dedups by `seq|time` with no notion of
entry shape. Tracked as plan 011.

## Why this matters

This repository has genuinely good test discipline: golden-file tests for every
shell parser, injectable clocks and stores so the caching and archiving policies
are unit-tested, a stubbed `storcli` that replays fixtures so backend routing is
covered without hardware. Five PHP test files cover the pure helpers.

None of it reaches the HTML. Roughly 250 of `ajax_info.php`'s 421 lines build
the tables the user actually looks at — PHY health, attached drives, the event
log, the SMART summary, the overview cards — and not one line of that is
exercised by any test. It is the largest untested surface in the project and the
only one that produces what the user sees.

That gap has already cost something concrete: a set of output-escaping
inconsistencies (some fields escaped, some not) sitting in that code today,
which any characterization test over the render functions would have made
obvious. Fixing those is the next plan; this plan builds the net to catch them
in.

The obstacle is structural rather than difficult. The render code is not in
functions — it is top-level dispatch inside a file that shells out to hardware
the moment you include it. This plan extracts it into three pure functions,
guards the dispatch under CLI the way `flash.php` already does, and adds the
first test file that covers rendering.

## Current state

Files involved:

- `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` — the AJAX endpoint.
  421 lines. Dispatch and hardware calls at the top; three named render helpers
  at lines 161-248; three *un-named* inline render blocks at lines 260-419.
- `tests/run_php.sh` — the PHP test runner. Hardcodes the list of test files
  **twice**, once for local `php` and once for the Docker fallback.
- `tests/view_test.php` — the structural exemplar for the new test file.

**The file's current shape.** Lines 9–16, the head:

```php
require_once __DIR__ . '/view.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/event_archive.php';
require_once __DIR__ . '/cached_read.php';

$type    = in_array($_GET['type'] ?? '', ['overview','overview_html','phy','drives','events','smart','smart_all','metrics'])
           ? $_GET['type'] : 'overview';
$scripts = '/usr/local/emhttp/plugins/hbaviewer/scripts';
```

Everything after that either shells out to hardware or renders. Including this
file from a test today would run the `overview` branch and call
`shell_exec("bash $scripts/get_hba_info.sh ...")`.

**Already-extracted helpers** (lines 161-248) — these need no work, they are
already functions and already reachable once the CLI guard is in:

- `luTable(array $headers, array $rows): string` — line 161
- `renderSmartTable(array $data): string` — line 174
- `renderOverviewCards(array $data, array $cfg): string` — line 207
- `luCtlHead(int $i): string` — line 251
- `luLinkBadge(string $link): string` — line 255

**The three inline blocks to extract.** Each is an `if ($type === '...') { ... }`
that builds `$out` and ends with `echo $out; exit;`. Reproduced here so you can
confirm you are looking at the right code.

`ajax_info.php:260-309`, the PHY block:

```php
if ($type === 'phy') {
    $ctls    = $data['controllers'] ?? [$data];
    $storcli = ($data['backend'] ?? '') === 'storcli';
    $multi   = count($ctls) > 1;
    $out   = '';
    foreach ($ctls as $i => $ctl) {
        if ($multi) $out .= luCtlHead($i);
        if (isset($ctl['error'])) { $out .= '<p class="lu-muted">' . htmlspecialchars($ctl['error']) . '</p>'; continue; }
        $phys = $ctl['phys'] ?? [];
        if (empty($phys)) { $out .= '<p class="lu-muted">No PHY data.</p>'; continue; }
        ...
    }
    echo $out;
    exit;
}
```

`ajax_info.php:314-370`, the Drives block, same shape. `ajax_info.php:375-419`,
the Events block, same shape — but note it has a side effect, at lines 385-387:

```php
        $file = event_store_path($i);
        [$entries, $changed] = event_merge(event_store_read($file), $ctl['entries'] ?? []);
        if ($changed) event_store_write($file, $entries);
```

`event_store_path` writes under `/boot` by default. The extracted function must
take an injectable directory so tests do not touch a real boot flash — the store
already supports this. From
`source/usr/local/emhttp/plugins/hbaviewer/event_archive.php:30-33`:

```php
/* Default per-controller store path. $dir is overridable for tests. */
function event_store_path(int $ctl, string $dir = '/boot/config/plugins/hbaviewer'): string {
    return "$dir/events_c$ctl.json";
}
```

**The precedent for the CLI guard**, from
`source/usr/local/emhttp/plugins/hbaviewer/flash.php:64-65`:

```php
/* ── HTTP dispatch (served only; skipped under the CLI test runner) ─────────── */
if (PHP_SAPI === 'cli') return;
```

That is exactly the pattern to copy, and `tests/flash_php_test.php` proves it
works: it does `require_once` on `flash.php` and calls the guard functions
defined *above* the return.

**One PHP behaviour this plan depends on.** Functions declared unconditionally
at the top level of a file are registered when the file is compiled, not when
execution reaches them — so they remain callable even if an early `return`
skips past their definitions. That is what lets the render functions live at the
bottom of the file while the guard sits near the top. Step 2 verifies this
empirically rather than asking you to take it on faith.

**A consequence of that, which you must NOT try to "fix".** `const` does *not*
behave like `function` here — a file-scope `const` is executed in sequence, so
an early `return` skips it. `ajax_info.php` now has one, added by plan 002:

```php
$scripts = '/usr/local/emhttp/plugins/hbaviewer/scripts';

/* A collector that was killed (or whose smartctl wedged) leaves its progress
   marker behind ... */
const SMART_PROGRESS_TTL = 300;
```

That `const` sits **below** the point where this plan inserts the CLI guard, so
under the test runner it will never be defined. **This is fine and intentional.**
Its only use is inside the `smart_all` dispatch branch, which is also below the
guard and equally unreachable under CLI — so nothing can reference an undefined
constant. Do **not** move the `const` above the guard, and do **not** move the
guard below it to "keep them together": putting the guard after `$scripts` and
before the first dispatch branch is the whole point, and reordering to
accommodate a constant nothing reads under CLI would defeat it.

If a future test ever needs `SMART_PROGRESS_TTL`, the fix is to move that one
`const` above the guard at that time — not now, and not speculatively.

**Repo conventions that apply here:**

- Test files: `<subject>_test.php`, a `check(string $name, bool $ok): void`
  helper writing `PASS  `/`FAIL  `, a `$fails` counter, a summary line, and
  `exit($fails === 0 ? 0 : 1)`. Read `tests/view_test.php` in full — it is the
  closest exemplar (pure functions, representative payloads, fallback cases).
- Test files `require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/<file>.php';`
- Comment style: block comments explaining *why* a seam exists, not what the
  code does. See the header of `cached_read.php` for the house voice.
- Deliberate simplifications get a `ponytail:` comment naming the ceiling.

Relevant vocabulary from `source/usr/local/emhttp/plugins/hbaviewer/CONTEXT.md`
(the module glossary — the executor has not read it):

> **backend module** — `scripts/lib.sh` (`hba_each`)
> The one seam that chooses **storcli** (SAS3/3.5) vs **lsiutil** (SAS2). …
> PHP reads the explicit `backend` field to pick columns — no key-sniffing.

> **event archive** — `event_archive.php` (`event_merge`)
> Persists the firmware event ring-buffer to `/boot` so history survives reboots
> and ring-buffer wrap. `event_merge(history, current) -> [kept, changed]` is
> pure (dedup by `seq|time`, cap at `EVENT_ARCHIVE_CAP`); `event_store_{path,read,write}`
> is the injectable store.

The phrase "PHP reads the explicit `backend` field to pick columns — no
key-sniffing" describes an intentional contract, and the tests you write should
lock it in: a `backend` of `storcli` must select the storcli column set.

## Commands you will need

| Purpose          | Command                                                              | Expected on success        |
|------------------|----------------------------------------------------------------------|----------------------------|
| PHP lint         | `find source tests -name '*.php' -print0 \| xargs -0 -r -n1 php -l`  | exit 0, "No syntax errors" |
| PHP tests only   | `bash tests/run_php.sh`                                              | each file's `all pass`, exit 0 |
| New test alone   | `php tests/ajax_render_test.php`                                     | `ajax_render: all pass`, exit 0 |
| Full test suite  | `bash tests/run.sh`                                                  | `--- all pass ---`, exit 0 |

**You must be able to run the PHP tests for this plan.** `tests/run_php.sh`
falls back to a `php:8.2-cli` Docker container when `php` is absent. If neither
`php` nor Docker works, STOP and report — the entire deliverable here is a test
file.

## Scope

**In scope**:

- `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` (add CLI guard; extract three functions)
- `tests/ajax_render_test.php` (create)
- `tests/run_php.sh` (register the new test file — **two** places)

**Out of scope** (do NOT touch, even though they look related):

- **Any change to the rendered HTML.** Not the escaping, not the markup, not the
  column order, not the CSS classes. This plan is a pure refactor plus tests;
  the escaping fixes are `plans/007-escape-renderer-output.md` and depend on
  this landing first. If you "fix" escaping here, you will write tests that
  assert the fixed behaviour and lose the ability to prove the refactor changed
  nothing.
- The `overview`, `overview_html`, `metrics`, `smart` and `smart_all` dispatch
  branches (lines 31-142). They shell out to hardware and are not render-only.
  `renderSmartTable` and `renderOverviewCards` are already functions and **are**
  in scope for testing, but their dispatch branches are not to be restructured.
- `source/usr/local/emhttp/plugins/hbaviewer/event_archive.php` — already has the
  injectable store this plan needs. Covered by `tests/event_archive_test.php`.
- `source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php` — the JS that
  consumes these fragments. It keys off `data-overview="warming"` and
  `data-smart="collecting"`; as long as the HTML is unchanged, it needs nothing.

## Git workflow

- Branch: `advisor/006-ajax-render-tests`
- Suggested commits: one for the extraction (`Extract the AJAX table renderers
  into functions`), one for the tests (`Add render-layer tests for ajax_info`).
  Message style matches this repo's history — short imperative subject, no
  conventional-commit prefix.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Record the current output so you can prove the refactor is inert

Before changing anything, capture what the three blocks produce today. You will
diff against this after the extraction.

```bash
mkdir -p /tmp/hbav-before
cat > /tmp/hbav-capture.php <<'CAPTURE'
<?PHP
$plug = __DIR__ . '/source/usr/local/emhttp/plugins/hbaviewer';
$payloads = [
  'phy_storcli'    => ['backend'=>'storcli','controllers'=>[['phys'=>[['phy'=>0,'link'=>'up','speed'=>'12.0Gb/s','sas_addr'=>'5000cca0','inv'=>0,'disp'=>0,'sync'=>0,'reset'=>0],['phy'=>1,'link'=>'down','speed'=>'unknown','sas_addr'=>'','inv'=>3,'disp'=>1,'sync'=>0,'reset'=>0]]]]],
  'phy_lsiutil'    => ['backend'=>'lsiutil','controllers'=>[['phys'=>[['phy'=>0,'link'=>'up','inv'=>1,'disp'=>2,'sync'=>3,'reset'=>4]]]]],
  'drives_storcli' => ['backend'=>'storcli','controllers'=>[['enclosures'=>[['eid'=>'8','product'=>'VirtualSES','vendor'=>'LSI','slots'=>'8','drives'=>'4','direct'=>1]],'drives'=>[['slot'=>'8/0','port'=>'14','model'=>'ST8000NM','serial'=>'ZA1ABCDE','state'=>'JBOD','size'=>'7.276 TB','sas_address'=>'5000c500a1b2c3d4','link'=>'12.0Gb/s','firmware'=>'SN02']]]]],
  'events_storcli' => ['backend'=>'storcli','controllers'=>[['entries'=>[['seq'=>'11','time'=>'2026-07-01 10:00:00','code'=>'0x0113','description'=>'Drive inserted']]]]],
  'events_lsiutil' => ['backend'=>'lsiutil','controllers'=>[['entries'=>[['seq'=>'7','qualifier'=>'0x02','data'=>'00 11 22','timestamp'=>'0x0001d4c0']]]]],
];
foreach ($payloads as $name => $data) {
    $type = str_starts_with($name, 'phy') ? 'phy' : (str_starts_with($name, 'drives') ? 'drives' : 'events');
    $out = shell_exec('php ' . escapeshellarg(__DIR__ . '/tmp_render_one.php') . ' '
         . escapeshellarg($type) . ' ' . escapeshellarg(json_encode($data)));
    file_put_contents("/tmp/hbav-before/$name.html", (string) $out);
}
echo "captured\n";
CAPTURE
echo 'SKIP — see note below'
```

**Stop and read.** Capturing the *pre-refactor* output requires running code
that, today, cannot be included without hitting hardware — which is the whole
problem this plan solves. So do this instead, which is simpler and just as
rigorous:

Take a copy of the file as your reference:

```bash
cp source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php /tmp/ajax_info.before.php
rm -f /tmp/hbav-capture.php
```

After the extraction, you will diff the moved code against this copy to prove
that only indentation and the `echo`/`return` boundary changed.

**Verify**: `test -f /tmp/ajax_info.before.php` → exit 0

### Step 2: Add the CLI guard and prove the render functions survive it

In `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php`, insert immediately
after the four `require_once` lines (after line 12, before the `$type =`
assignment):

```php

/* ── Request dispatch (served only; skipped under the CLI test runner) ───────
   Everything below this line either shells out to the hardware-reading scripts
   or renders a response for one request. The render functions themselves are
   declared at file scope, so they are compiled and callable even though this
   return skips past their definitions — which is what lets tests require this
   file and exercise the table builders without touching a controller.
   Same posture as flash.php. */
if (PHP_SAPI === 'cli') return;
```

Now prove the claim in that comment, before you build anything on top of it:

```bash
php -r '
require "source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php";
var_dump(function_exists("luTable"), function_exists("renderSmartTable"), function_exists("renderOverviewCards"));
'
```

**Verify**: prints `bool(true)` three times, and produces **no** other output
(no HTML, no shell errors) — the dispatch was skipped and the functions are
still there.

If any of these is `bool(false)`, STOP. The rest of this plan assumes the guard
pattern works; find out why it did not before proceeding.

### Step 3: Extract the PHY block into a function

Replace `ajax_info.php:260-309` (the whole `if ($type === 'phy') { ... }` block)
with a function definition followed by a two-line dispatch:

```php
/* ── PHY Health (per controller; columns adapt to the detected backend) ────── */
function renderPhyTables(array $data): string {
    $ctls    = $data['controllers'] ?? [$data];
    $storcli = ($data['backend'] ?? '') === 'storcli';
    $multi   = count($ctls) > 1;
    $out   = '';

    /* ... the entire existing foreach body, unchanged ... */

    return $out;
}

if ($type === 'phy') { echo renderPhyTables($data); exit; }
```

Mechanically: keep every line of the `foreach` exactly as it is. The only edits
are the wrapper (`function renderPhyTables(array $data): string {`), the ending
(`return $out;` in place of `echo $out;` and `exit;`), and re-indentation.

**Verify** the move was inert — this diff must show only the wrapper, the
return, and whitespace:

```bash
diff <(sed -n '260,309p' /tmp/ajax_info.before.php) \
     <(sed -n "/^function renderPhyTables/,/^}/p" source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php)
```

Read the diff. Every changed line must be one of: the `if ($type === 'phy') {`
→ `function renderPhyTables(...)` swap, the `echo $out; exit;` → `return $out;`
swap, or pure indentation. **Any other difference is a bug you just introduced.**

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` → "No syntax errors detected"

### Step 4: Extract the Drives block

Same treatment for `ajax_info.php:314-370`:

```php
/* ── Attached Drives (per controller; columns adapt to the backend) ───────── */
function renderDrivesTables(array $data): string {
    /* ... existing body ... */
    return $out;
}

if ($type === 'drives') { echo renderDrivesTables($data); exit; }
```

**Verify** with the same diff technique against lines 314-370 of
`/tmp/ajax_info.before.php`, applying the same "only three kinds of change"
rule.

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` → "No syntax errors detected"

### Step 5: Extract the Events block, with an injectable archive directory

`ajax_info.php:375-419`. This one gains a parameter — the archive directory —
so tests do not write to `/boot`. The default preserves production behaviour
exactly.

```php
/* ── Event Log (per controller; persisted to /boot across reboots) ───────────
   $dir is the archive location; it is injectable so tests can point the store
   at a temp directory instead of the boot flash. */
function renderEventsTables(array $data, string $dir = '/boot/config/plugins/hbaviewer'): string {
    /* ... existing body ... */
    return $out;
}

if ($type === 'events') { echo renderEventsTables($data); exit; }
```

Inside the body, the **only** substantive change is line 385:

```php
        $file = event_store_path($i);
```

becomes:

```php
        $file = event_store_path($i, $dir);
```

Everything else stays byte-identical.

**Verify**: `grep -n 'event_store_path($i, $dir)' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` → prints one line

**Verify**: `grep -c 'event_store_path($i)' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` → prints `0`

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` → "No syntax errors detected"

### Step 6: Write the test file

Create `tests/ajax_render_test.php`. Model it on `tests/view_test.php` — same
`check()` helper, same PASS/FAIL output, same exit convention.

```php
<?PHP
/* Runnable checks for the ajax_info.php render layer: the table builders behind
   the PHY / Drives / Event Log / SMART tabs. These are the ~250 lines that
   produce what the user actually looks at, and until now nothing covered them.
   No HTTP, no hardware — ajax_info.php returns early under CLI, so requiring it
   loads the render functions without running any dispatch.
     php tests/ajax_render_test.php  ->  "ajax_render: all pass" (exit 0) */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

/* ── the render functions are reachable at all ───────────────────────────── */
check('phy fn exists',    function_exists('renderPhyTables'));
check('drives fn exists', function_exists('renderDrivesTables'));
check('events fn exists', function_exists('renderEventsTables'));
check('smart fn exists',  function_exists('renderSmartTable'));

/* ── PHY: the backend field picks the column set (no key-sniffing) ────────── */
$phyStorcli = ['backend' => 'storcli', 'controllers' => [['phys' => [
    ['phy'=>0,'link'=>'up','speed'=>'12.0Gb/s','sas_addr'=>'5000cca0','inv'=>0,'disp'=>0,'sync'=>0,'reset'=>0],
    ['phy'=>1,'link'=>'down','speed'=>'unknown','sas_addr'=>'','inv'=>3,'disp'=>1,'sync'=>0,'reset'=>0],
]]]];
$h = renderPhyTables($phyStorcli);
check('phy storcli has speed col',   str_contains($h, '<th>Speed</th>'));
check('phy storcli has sas col',     str_contains($h, '<th>Attached SAS Address</th>'));
check('phy storcli link up badge',   str_contains($h, 'lu-link-up'));
check('phy storcli link down badge', str_contains($h, 'lu-link-down'));
check('phy storcli flags errors',    str_contains($h, 'lu-err-val'));
check('phy storcli uppercases sas',  str_contains($h, '5000CCA0'));

$phyLsi = ['backend' => 'lsiutil', 'controllers' => [['phys' => [
    ['phy'=>0,'link'=>'up','inv'=>1,'disp'=>2,'sync'=>3,'reset'=>4],
]]]];
$h = renderPhyTables($phyLsi);
check('phy lsiutil omits speed col', !str_contains($h, '<th>Speed</th>'));
check('phy lsiutil has counters',    str_contains($h, '<th>Invalid DWords</th>'));

/* ── PHY: degenerate inputs must not fatal ───────────────────────────────── */
check('phy controller error row', str_contains(
    renderPhyTables(['backend'=>'storcli','controllers'=>[['error'=>'no response']]]), 'no response'));
check('phy empty phys', str_contains(
    renderPhyTables(['backend'=>'storcli','controllers'=>[[]]]), 'No PHY data.'));
check('phy multi heads controllers', str_contains(
    renderPhyTables(['backend'=>'storcli','controllers'=>[['phys'=>[]],['phys'=>[]]]]), 'Controller /c1'));
check('phy single omits head', !str_contains(
    renderPhyTables(['backend'=>'storcli','controllers'=>[['phys'=>[]]]]), 'Controller /c0'));

/* ── Drives: backend picks columns; enclosure summary renders ────────────── */
$drvStorcli = ['backend' => 'storcli', 'controllers' => [[
    'enclosures' => [['eid'=>'8','product'=>'VirtualSES','vendor'=>'LSI','slots'=>'8','drives'=>'4','direct'=>1]],
    'drives' => [['slot'=>'8/0','port'=>'14','model'=>'ST8000NM','serial'=>'ZA1ABCDE','state'=>'JBOD',
                  'size'=>'7.276 TB','sas_address'=>'5000c500a1b2c3d4','link'=>'12.0Gb/s','firmware'=>'SN02']],
]]];
$h = renderDrivesTables($drvStorcli);
check('drives storcli col set',    str_contains($h, '<th>Encl:Slot</th>') && str_contains($h, '<th>Firmware</th>'));
check('drives enclosure summary',  str_contains($h, 'VirtualSES') && str_contains($h, 'direct-attach'));
check('drives smart button',       str_contains($h, 'luSmart(this') && str_contains($h, 'ZA1ABCDE'));
check('drives uppercases sas',     str_contains($h, '5000C500A1B2C3D4'));

$drvLsi = ['backend' => 'lsiutil', 'controllers' => [['drives' => [
    ['bus'=>'0','target'=>'3','phy'=>'2','sas_address'=>'5000c500a1b2c3d4','os_name'=>'/dev/sdb'],
]]]];
$h = renderDrivesTables($drvLsi);
check('drives lsiutil col set', str_contains($h, '<th>Bus:Tgt</th>') && str_contains($h, '<th>OS Device</th>'));
check('drives lsiutil no smart btn', !str_contains($h, 'luSmart(this'));
check('drives empty', str_contains(
    renderDrivesTables(['backend'=>'storcli','controllers'=>[[]]]), 'No drives detected.'));

/* ── Events: archive dir is injectable, merge dedups, newest first ────────── */
$dir = sys_get_temp_dir() . '/hbav_events_' . getmypid();
@mkdir($dir, 0755, true);
array_map('unlink', glob("$dir/*.json") ?: []);

$evStorcli = ['backend' => 'storcli', 'controllers' => [['entries' => [
    ['seq'=>'11','time'=>'2026-07-01 10:00:00','code'=>'0x0113','description'=>'Drive inserted'],
    ['seq'=>'12','time'=>'2026-07-01 10:05:00','code'=>'0x0114','description'=>'Drive removed'],
]]]];
$h = renderEventsTables($evStorcli, $dir);
check('events storcli col set', str_contains($h, '<th>Description</th>'));
check('events wrote archive',   is_file("$dir/events_c0.json"));
check('events newest first',    strpos($h, 'Drive removed') < strpos($h, 'Drive inserted'));
check('events counts entries',  str_contains($h, '2 entries'));

// Re-render the same payload: the archive must not grow (dedup by seq|time).
renderEventsTables($evStorcli, $dir);
$archived = json_decode((string) file_get_contents("$dir/events_c0.json"), true);
check('events dedup on repeat', count($archived) === 2);

$evLsi = ['backend' => 'lsiutil', 'controllers' => [['entries' => [
    ['seq'=>'7','qualifier'=>'0x02','data'=>'00 11 22','timestamp'=>'0x0001d4c0'],
]]]];
$h = renderEventsTables($evLsi, $dir);
check('events lsiutil col set', str_contains($h, '<th>Qualifier</th>') && !str_contains($h, '<th>Description</th>'));
check('events note rendered', str_contains(
    renderEventsTables(['backend'=>'storcli','controllers'=>[['note'=>'expert mode required','entries'=>[]]]], $dir),
    'expert mode required'));

array_map('unlink', glob("$dir/*.json") ?: []);
@rmdir($dir);

/* ── SMART table: health colouring, standby, and the empty case ───────────── */
$h = renderSmartTable(['drives' => [
    ['dev'=>'/dev/sdb','model'=>'ST8000NM','serial'=>'ZA1ABCDE',
     'smart'=>['health'=>'PASSED','temp'=>'34','defects'=>'0','pending'=>'0','power_on_hours'=>'12345']],
    ['dev'=>'/dev/sdc','model'=>'WD80EFAX','serial'=>'WD-XYZ',
     'smart'=>['health'=>'PASSED','temp'=>'36','defects'=>'2','pending'=>'0','power_on_hours'=>'900']],
    ['dev'=>'/dev/sdd','model'=>'HUH721','serial'=>'K1234','smart'=>[]],
]]);
check('smart healthy green',  str_contains($h, '#2ecc71'));
check('smart defects amber',  str_contains($h, '#f39c12'));
check('smart standby row',    str_contains($h, 'standby'));
check('smart formats hours',  str_contains($h, '12,345h'));
check('smart empty message',  str_contains(renderSmartTable([]), 'No drives found.'));

/* ── luTable: headers are escaped, cells are passed through as markup ─────── */
$t = luTable(['A & B'], [['<code>x</code>']]);
check('luTable escapes headers', str_contains($t, 'A &amp; B'));
check('luTable cells are html',  str_contains($t, '<code>x</code>'));

echo $fails === 0 ? "ajax_render: all pass\n" : "ajax_render: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
```

**Verify**: `php tests/ajax_render_test.php` → ends with `ajax_render: all pass`, exit code 0

If any check fails, the extraction changed behaviour. Go back to the diffs from
Steps 3–5 rather than editing the assertion to match.

### Step 7: Register the test in the runner

`tests/run_php.sh` hardcodes the file list **twice**. Both must be updated or
the test will pass locally and silently never run in CI (CI has no local `php`
in the Docker path, and the local path is what GitHub Actions uses — get both).

In `tests/run_php.sh:10`, append to the local-php chain:

```bash
    php tests/config_test.php && php tests/view_test.php && php tests/event_archive_test.php && php tests/cached_read_test.php && php tests/flash_php_test.php && php tests/ajax_render_test.php
```

And in the Docker fallback at `tests/run_php.sh:15`, inside the `sh -c '...'`
string:

```bash
        sh -c 'php tests/config_test.php && php tests/view_test.php && php tests/event_archive_test.php && php tests/cached_read_test.php && php tests/flash_php_test.php && php tests/ajax_render_test.php'
```

**Verify**: `grep -c 'ajax_render_test.php' tests/run_php.sh` → prints `2`

**Verify**: `bash tests/run_php.sh` → all five prior files plus `ajax_render: all pass`, exit 0

### Step 8: Full suite and final inertness check

**Verify**: `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` → exit 0

**Verify**: `bash tests/run.sh` → `--- all pass ---`, exit 0

Last inertness check — confirm the refactor moved code rather than rewriting it.
The three extracted bodies should account for nearly all the changed lines:

```bash
git diff --stat source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php
git diff -w source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php | grep -E '^[+-]' | grep -vE '^[+-][+-]' | grep -vE '^\+.*(function render|return \$out|PHP_SAPI|\$dir)|^-.*(echo \$out|exit;|if \(\$type)'
```

The second command lists changed lines that are **not** part of the expected
wrapper edits, ignoring whitespace. It should print very little — the
`event_store_path($i, $dir)` change, the new dispatch one-liners, and comments.
Anything else means you rewrote logic. Review each remaining line.

Clean up: `rm -f /tmp/ajax_info.before.php`

## Test plan

**New file**: `tests/ajax_render_test.php`, structured after `tests/view_test.php`.

Coverage, by area:

- **Reachability** (4 checks) — the CLI guard leaves all four render functions
  callable. If this breaks, everything else in the file is meaningless.
- **PHY** (10 checks) — storcli vs lsiutil column selection driven by the
  `backend` field, link badges, error highlighting, SAS uppercasing, plus three
  degenerate inputs (controller error, empty PHY list, multi-controller headings).
- **Drives** (6 checks) — both backends' column sets, the enclosure summary and
  its direct-attach wording, the per-drive SMART button, SAS uppercasing, empty list.
- **Events** (7 checks) — both column sets, archive written to an **injected**
  directory, newest-first ordering, entry count, dedup on repeated render, the
  optional per-controller note.
- **SMART table** (5 checks) — green/amber health colouring, the standby row for
  a drive with no SMART data, thousands-separated power-on hours, empty case.
- **luTable** (2 checks) — headers escaped, cells passed through as markup. This
  pair documents the current contract precisely because plan 007 will change what
  callers hand in.

**What is deliberately *not* asserted here**: whether hardware-sourced values are
escaped. Several are not, and that is the subject of
`plans/007-escape-renderer-output.md`. Asserting the current (inconsistent)
behaviour would mean rewriting those assertions in the very next plan; asserting
the *desired* behaviour would make this plan fail. Plan 007 adds those checks
alongside the fix.

**Verification**: `bash tests/run.sh` → `--- all pass ---`, including
`ajax_render: all pass`.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'if (PHP_SAPI === .cli.) return;' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` prints `1`
- [ ] `php -r 'require "source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php"; exit(function_exists("renderPhyTables") && function_exists("renderDrivesTables") && function_exists("renderEventsTables") ? 0 : 1);'` exits 0 and prints nothing
- [ ] `grep -c 'function renderPhyTables\|function renderDrivesTables\|function renderEventsTables' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` prints `3`
- [ ] `grep -c 'event_store_path($i)' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` prints `0`
- [ ] `test -f tests/ajax_render_test.php`
- [ ] `php tests/ajax_render_test.php` exits 0 and prints `ajax_render: all pass`
- [ ] `grep -c 'ajax_render_test.php' tests/run_php.sh` prints `2`
- [ ] `bash tests/run_php.sh` exits 0
- [ ] `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` exits 0
- [ ] `bash tests/run.sh` exits 0 and prints `--- all pass ---`
- [ ] `git status --porcelain` shows exactly three files: `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php`, `tests/ajax_render_test.php`, `tests/run_php.sh` (plus `plans/README.md`)
- [ ] `plans/README.md` status row for 006 updated

## STOP conditions

Stop and report back (do not improvise) if:

- Step 2's verification prints `bool(false)` for any function. The CLI-guard
  approach is the foundation of this plan; if PHP is not registering those
  functions, understand why before building on it.
- The inertness diffs in Steps 3–5 or Step 8 show changed lines you cannot
  account for as wrapper/return/whitespace/`$dir`. You have rewritten logic
  during what was supposed to be a move.
- You cannot run PHP tests (no `php`, no Docker). The deliverable is a test file.
- A test you wrote fails and the honest fix is to change the *renderer*. That
  means you have found a real bug — report it rather than silently fixing it
  here, because this plan's value depends on the refactor being provably inert.
- Any of the existing five PHP test files starts failing. Nothing here should
  touch them.
- You conclude the extraction requires changing `event_archive.php`,
  `view.php`, or `config.php`. It does not — `event_store_path` already takes
  the `$dir` parameter this plan needs.

## Maintenance notes

- **The CLI guard is load-bearing and subtle.** `ajax_info.php` can now be safely
  `require`d from a test *only* because the dispatch returns early and the render
  functions are declared at file scope. If anyone wraps a render function in a
  conditional, or moves the guard below a `shell_exec`, the test file will start
  executing hardware calls. The comment at the guard explains this; keep it.
- **Two files now use the CLI-guard pattern** — `flash.php` and `ajax_info.php`.
  It is the established way to make a dispatch script testable in this codebase;
  a third should follow the same shape rather than inventing another.
- **`renderEventsTables` writes to disk.** It is the only render function with a
  side effect (the event archive). Tests must always pass an explicit `$dir`;
  a test that forgets will write to `/boot` on a real Unraid box. Consider that
  a review checkpoint for any new event test.
- **`tests/run_php.sh` duplicates its file list.** Any future test file must be
  added in both places. That duplication is a small trap worth removing
  independently, but it is out of scope here.
- **What a reviewer should scrutinise**: the Step 8 diff output. The entire
  claim of this plan is "the HTML did not change" — a reviewer who checks only
  that the tests pass has not checked that, because the tests were written
  *after* the refactor.
- **Deferred**: `renderOverviewCards` gets no direct coverage here beyond being
  reachable — it needs a config array as well as controller data, and the
  overview path has its own hardware-adjacent caching concerns. Worth adding
  later; not required to unblock plan 007.
