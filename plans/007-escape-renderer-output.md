# Plan 007: Escape every hardware-sourced value in the AJAX renderers

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat e1ee859..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php`
> Baseline re-pinned 2026-07-27 to `e1ee859`, at which plan 006 is DONE and
> merged (`23b9646`) and its render-layer test net is in place. Any difference
> from that baseline is a STOP condition.
>
> **Dependency**: plan 006 must be DONE. Confirm two things before starting —
> `grep -c 'function renderPhyTables' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php`
> prints `1`, and `tests/ajax_render_test.php` exists and passes. Without that
> net this plan edits the most-viewed code in the plugin with nothing to catch a
> regression.
>
> **Line numbers in this plan are from commit `0346777`, before plan 006's
> refactor moved the code into functions.** They are stale by design and are
> navigation hints only — **locate each site by the code snippet given, never by
> line number.** All five snippets were re-verified present and unchanged at
> `e1ee859`; 006's refactor moved this code without altering it.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: `plans/006-ajax-render-tests.md` (must be DONE first)
- **Category**: security
- **Planned at**: commit `0346777`, 2026-07-26

## Why this matters

The AJAX renderers escape most of what they print, and miss about a dozen
fields. The misses are not in one place — they are scattered across the PHY,
Drives and Event Log tables, sitting directly beside near-identical lines that
*are* escaped. The pattern is clearly intent plus oversight rather than a
decision.

Be accurate about the severity: every one of these values originates in HBA
firmware, `storcli` output, or Linux sysfs. Exploiting them means controlling
what a SAS controller reports about itself — not a realistic attack against a
plugin whose UI is already behind Unraid's admin login. This is a **low
severity** finding and this plan does not pretend otherwise.

It is still worth doing, for two reasons that have nothing to do with attackers.
First, an unescaped field is a **correctness** bug before it is a security one:
a drive model containing `<` or `&` renders as broken markup or vanishes from
the table, and diagnosing "why is one drive missing from the Drives tab" is
miserable. Second, the inconsistency is a maintenance hazard — the next person
extending these tables has no way to tell which convention the file follows,
because it visibly follows both.

The fix is mechanical and the test net from plan 006 already exists to catch
regressions.

## Current state

File involved:

- `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` — after plan 006,
  the render code lives in `renderPhyTables()`, `renderDrivesTables()` and
  `renderEventsTables()`.

**The complete list of unescaped sites.** Each was confirmed by reading the code
at commit `0346777`. Line numbers are pre-refactor; find each by its snippet.

### In `renderPhyTables()` — storcli branch (was lines 267–280)

```php
                $hasErr = (($p['inv'] ?? 0) + ($p['disp'] ?? 0) + ($p['sync'] ?? 0) + ($p['reset'] ?? 0)) > 0;
                $ec = function ($v) use ($hasErr) {
                    return $hasErr && $v > 0 ? '<span class="lu-err-val">' . $v . '</span>' : $v;
                };
                $rows[] = [
                    $p['phy'],
                    luLinkBadge($p['link']),
                    htmlspecialchars($p['speed']),
                    !empty($p['sas_addr']) ? '<code>' . strtoupper($p['sas_addr']) . '</code>' : '<span class="lu-muted">—</span>',
                    $ec($p['inv'] ?? 0),
                    $ec($p['disp'] ?? 0),
                    $ec($p['sync'] ?? 0),
                    $ec($p['reset'] ?? 0),
                ];
```

Unescaped: `$p['phy']`, `strtoupper($p['sas_addr'])`, and `$v` inside `$ec`.
Note `$p['speed']` on the line between them **is** escaped — that is the
inconsistency in miniature.

### In `renderPhyTables()` — lsiutil branch (was lines 286–295)

```php
                $hasErr = ($p['inv'] + $p['disp'] + $p['sync'] + $p['reset']) > 0;
                $rows[] = [
                    $p['phy'],
                    luLinkBadge($p['link']),
                    $hasErr ? '<span class="lu-err-val">'.$p['inv'].'</span>'   : $p['inv'],
                    $hasErr ? '<span class="lu-err-val">'.$p['disp'].'</span>'  : $p['disp'],
                    $hasErr ? '<span class="lu-err-val">'.$p['sync'].'</span>'  : $p['sync'],
                    $hasErr ? '<span class="lu-err-val">'.$p['reset'].'</span>' : $p['reset'],
                ];
```

Unescaped: `$p['phy']` and all four counters, in both ternary arms.

### In `renderDrivesTables()` — storcli branch (was line 342)

```php
                    !empty($d['sas_address']) ? '<code>' . strtoupper($d['sas_address']) . '</code>' : '<span class="lu-muted">—</span>',
```

Unescaped: `strtoupper($d['sas_address'])`. Every other cell in this row —
`slot`, `port`, `model`, `serial`, `state`, `size`, `link`, `firmware` — is
already escaped.

### In `renderDrivesTables()` — lsiutil branch (was lines 353–356)

```php
                $os  = !empty($d['os_name'])     ? '<code>' . $d['os_name'] . '</code>'                : '<span class="lu-muted">—</span>';
                $sas = !empty($d['sas_address']) ? '<code>' . strtoupper($d['sas_address']) . '</code>' : '<span class="lu-muted">—</span>';
                $phy = isset($d['phy']) && $d['phy'] !== '' ? 'PHY ' . $d['phy']                        : '<span class="lu-muted">—</span>';
                $rows[] = [$d['bus'] . ':' . $d['target'], $phy, $sas, $os];
```

Unescaped: `$d['os_name']`, `$d['sas_address']`, `$d['phy']`, `$d['bus']`,
`$d['target']` — the entire branch.

### In `renderEventsTables()` — lsiutil branch (was lines 399–405)

```php
            foreach (array_reverse($entries) as $e) {
                $rows[] = [
                    $e['seq'],
                    '<code>' . $e['qualifier'] . '</code>',
                    '<code>' . htmlspecialchars($e['data']) . '</code>',
                    '<code>' . $e['timestamp'] . '</code>',
                ];
            }
```

Unescaped: `$e['seq']`, `$e['qualifier']`, `$e['timestamp']`. `$e['data']` on
the line between them is escaped — the same one-of-these-is-not-like-the-others
pattern.

**Sites that are already correct and must NOT be changed:**

- The whole storcli events branch (`seq`, `time`, `code`, `description` — all escaped).
- The enclosure summary in `renderDrivesTables()` (`eid`, `product`, `vendor`, `slots`, `drives` — all escaped).
- Every cell in `renderSmartTable()`.
- Every cell in `renderOverviewCards()`.
- `luTable()` itself, which escapes headers and passes cells through as markup —
  that is deliberate, because callers legitimately hand it `<code>` and `<span>`
  wrappers. **Do not** add escaping inside `luTable`; it would double-escape
  every already-correct call site in the file.

**Repo conventions that apply here:**

- The escaping idiom throughout is `htmlspecialchars($value)` with no flags —
  see `ajax_info.php` line 154 (`htmlspecialchars($hdr)`), 217
  (`htmlspecialchars($v['model'])`), 336 (`htmlspecialchars($d['slot'])`).
  Match it exactly; do not introduce `ENT_QUOTES` here, because the surrounding
  code does not use it in element content.
- The one place `ENT_QUOTES` **is** used is inside an HTML attribute —
  `ajax_info.php:333`, `htmlspecialchars($serial, ENT_QUOTES)` in the SMART
  button's `onclick`. That is correct and stays.
- Numeric-looking values still get escaped. They arrive from `json_decode` of
  shell-generated JSON and are not guaranteed to be integers; a defensive
  `htmlspecialchars((string) $v)` costs nothing and removes the need for the
  reader to reason about it.

Relevant vocabulary from `source/usr/local/emhttp/plugins/hbaviewer/view.php:1-6`
(the executor has not read this file) — it states the escaping contract this
plan enforces:

> Shared interpretation of the get_hba_info.sh JSON for display. … **Values are
> returned RAW; each consumer escapes for its own medium.**

`ajax_info.php` is a consumer. Escaping is its job, and this plan makes it do
that job everywhere rather than in most places.

## Commands you will need

| Purpose          | Command                                                              | Expected on success        |
|------------------|----------------------------------------------------------------------|----------------------------|
| PHP lint         | `find source tests -name '*.php' -print0 \| xargs -0 -r -n1 php -l`  | exit 0, "No syntax errors" |
| Render tests     | `php tests/ajax_render_test.php`                                     | `ajax_render: all pass`, exit 0 |
| Full test suite  | `bash tests/run.sh`                                                  | `--- all pass ---`, exit 0 |

You must be able to run the PHP tests. `tests/run_php.sh` falls back to a
`php:8.2-cli` Docker container when `php` is absent.

## Scope

**In scope**:

- `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` (the five sites above)
- `tests/ajax_render_test.php` (add escaping assertions)

**Out of scope** (do NOT touch, even though they look related):

- `luTable()` — see above. Adding escaping there double-escapes the rest of the file.
- `renderSmartTable()` and `renderOverviewCards()` — already correct throughout.
- The storcli events branch and the enclosure summary — already correct.
- `source/usr/local/emhttp/plugins/hbaviewer/scripts/collect_smart.sh` and
  `scripts/parse/storcli_drives.sh`, which emit drive model/serial into JSON
  without escaping quotes or backslashes. That is a **different** bug (malformed
  JSON, not malformed HTML) at a different layer, and `collect_smart.sh:10-11`
  already carries a `ponytail:` comment acknowledging it as a known deferral.
  Fixing it means changing shell JSON emission, which needs its own golden-test
  work. Do not start it here.
- `source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php` — its client-side
  `fesc()` helper is the JS equivalent and is applied consistently already.

## Git workflow

- Branch: `advisor/007-escape-renderer-output`
- One commit. Message style matches this repo's history — short imperative
  subject, no conventional-commit prefix. Suggested:
  `Escape the remaining hardware-sourced values in the AJAX tables`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Confirm the net from plan 006 is in place and green

```bash
php tests/ajax_render_test.php
```

**Verify**: ends with `ajax_render: all pass`, exit code 0.

If this file does not exist or does not pass, STOP — plan 006 is a hard
dependency and this plan's safety rests entirely on it.

### Step 2: Escape the PHY storcli branch

In `renderPhyTables()`, storcli branch, change the `$ec` closure and the row:

```php
                $hasErr = (($p['inv'] ?? 0) + ($p['disp'] ?? 0) + ($p['sync'] ?? 0) + ($p['reset'] ?? 0)) > 0;
                $ec = function ($v) use ($hasErr) {
                    $s = htmlspecialchars((string) $v);
                    return $hasErr && $v > 0 ? '<span class="lu-err-val">' . $s . '</span>' : $s;
                };
                $rows[] = [
                    htmlspecialchars((string) $p['phy']),
                    luLinkBadge($p['link']),
                    htmlspecialchars($p['speed']),
                    !empty($p['sas_addr']) ? '<code>' . htmlspecialchars(strtoupper($p['sas_addr'])) . '</code>' : '<span class="lu-muted">—</span>',
                    $ec($p['inv'] ?? 0),
                    $ec($p['disp'] ?? 0),
                    $ec($p['sync'] ?? 0),
                    $ec($p['reset'] ?? 0),
                ];
```

Note the ordering inside the closure: escape into `$s`, but keep the **numeric**
comparison `$v > 0` on the original value. Escaping first and comparing the
string would change the highlighting behaviour.

**Verify**: `php tests/ajax_render_test.php` → still `ajax_render: all pass`

### Step 3: Escape the PHY lsiutil branch

```php
                $hasErr = ($p['inv'] + $p['disp'] + $p['sync'] + $p['reset']) > 0;
                $ev = fn($v) => htmlspecialchars((string) $v);
                $rows[] = [
                    htmlspecialchars((string) $p['phy']),
                    luLinkBadge($p['link']),
                    $hasErr ? '<span class="lu-err-val">'.$ev($p['inv']).'</span>'   : $ev($p['inv']),
                    $hasErr ? '<span class="lu-err-val">'.$ev($p['disp']).'</span>'  : $ev($p['disp']),
                    $hasErr ? '<span class="lu-err-val">'.$ev($p['sync']).'</span>'  : $ev($p['sync']),
                    $hasErr ? '<span class="lu-err-val">'.$ev($p['reset']).'</span>' : $ev($p['reset']),
                ];
```

The short-closure form `fn($v) => ...` is already used in this file — see
`ajax_info.php:79` (`$f = fn($v) => ...`) and `:181` (`$cell = fn($v, $suf = '') => ...`).

**Verify**: `php tests/ajax_render_test.php` → still `ajax_render: all pass`

### Step 4: Escape the Drives storcli SAS address

```php
                    !empty($d['sas_address']) ? '<code>' . htmlspecialchars(strtoupper($d['sas_address'])) . '</code>' : '<span class="lu-muted">—</span>',
```

**Verify**: `php tests/ajax_render_test.php` → still `ajax_render: all pass`

### Step 5: Escape the Drives lsiutil branch

```php
                $os  = !empty($d['os_name'])     ? '<code>' . htmlspecialchars($d['os_name']) . '</code>'                : '<span class="lu-muted">—</span>';
                $sas = !empty($d['sas_address']) ? '<code>' . htmlspecialchars(strtoupper($d['sas_address'])) . '</code>' : '<span class="lu-muted">—</span>';
                $phy = isset($d['phy']) && $d['phy'] !== '' ? 'PHY ' . htmlspecialchars((string) $d['phy'])              : '<span class="lu-muted">—</span>';
                $rows[] = [
                    htmlspecialchars((string) $d['bus']) . ':' . htmlspecialchars((string) $d['target']),
                    $phy, $sas, $os,
                ];
```

**Verify**: `php tests/ajax_render_test.php` → still `ajax_render: all pass`

### Step 6: Escape the Events lsiutil branch

```php
            foreach (array_reverse($entries) as $e) {
                $rows[] = [
                    htmlspecialchars((string) $e['seq']),
                    '<code>' . htmlspecialchars((string) $e['qualifier']) . '</code>',
                    '<code>' . htmlspecialchars($e['data']) . '</code>',
                    '<code>' . htmlspecialchars((string) $e['timestamp']) . '</code>',
                ];
            }
```

**Verify**: `php tests/ajax_render_test.php` → still `ajax_render: all pass`

### Step 7: Add the escaping assertions

Append to `tests/ajax_render_test.php`, immediately before the final
`echo`/`exit` block. These are the regression tests: they fail against the
pre-plan-007 code and pass after it.

```php
/* ── Hostile-ish hardware strings must not reach the page as markup ────────
   Every value below arrives from HBA firmware, storcli text, or sysfs. None of
   it is attacker-controlled in any realistic scenario — but a drive model
   containing < or & is a plain correctness problem, and consistency here is
   what stops the next person guessing which convention this file follows. */
$X   = '<img src=x onerror=alert(1)>';
$ESC = '&lt;img src=x onerror=alert(1)&gt;';

$h = renderPhyTables(['backend'=>'storcli','controllers'=>[['phys'=>[
    ['phy'=>$X,'link'=>'up','speed'=>$X,'sas_addr'=>$X,'inv'=>0,'disp'=>0,'sync'=>0,'reset'=>0],
]]]]);
check('phy storcli escapes phy',   !str_contains($h, $X));
check('phy storcli escapes sas',   str_contains($h, strtoupper($ESC)) || str_contains($h, $ESC));

$h = renderPhyTables(['backend'=>'lsiutil','controllers'=>[['phys'=>[
    ['phy'=>$X,'link'=>'up','inv'=>0,'disp'=>0,'sync'=>0,'reset'=>0],
]]]]);
check('phy lsiutil escapes phy', !str_contains($h, $X));

$h = renderDrivesTables(['backend'=>'storcli','controllers'=>[['drives'=>[
    ['slot'=>'8/0','port'=>'14','model'=>$X,'serial'=>'S1','state'=>'JBOD',
     'size'=>'8 TB','sas_address'=>$X,'link'=>'12.0Gb/s','firmware'=>'SN02'],
]]]]);
check('drives storcli escapes model', !str_contains($h, $X));
check('drives storcli escapes sas',   !str_contains($h, strtoupper($X)));

$h = renderDrivesTables(['backend'=>'lsiutil','controllers'=>[['drives'=>[
    ['bus'=>$X,'target'=>'3','phy'=>$X,'sas_address'=>$X,'os_name'=>$X],
]]]]);
check('drives lsiutil escapes os_name', !str_contains($h, $X));
check('drives lsiutil escapes bus',     !str_contains($h, $X));
check('drives lsiutil escapes sas',     !str_contains($h, strtoupper($X)));

$edir = sys_get_temp_dir() . '/hbav_esc_' . getmypid();
@mkdir($edir, 0755, true);
$h = renderEventsTables(['backend'=>'lsiutil','controllers'=>[['entries'=>[
    ['seq'=>$X,'qualifier'=>$X,'data'=>$X,'timestamp'=>$X],
]]]], $edir);
check('events lsiutil escapes seq',       !str_contains($h, $X));
check('events lsiutil escapes qualifier', !str_contains($h, $X));
check('events lsiutil escapes timestamp', !str_contains($h, $X));
array_map('unlink', glob("$edir/*.json") ?: []);
@rmdir($edir);

// The already-correct branches stay correct — guard against a regression that
// "fixes" escaping by moving it into luTable and double-escaping everything.
$h = renderDrivesTables(['backend'=>'storcli','controllers'=>[[
    'enclosures'=>[['eid'=>'8','product'=>'VirtualSES','vendor'=>'LSI','slots'=>'8','drives'=>'4','direct'=>1]],
    'drives'=>[['slot'=>'8/0','port'=>'14','model'=>'A & B','serial'=>'S1','state'=>'JBOD',
                'size'=>'8 TB','sas_address'=>'5000c5','link'=>'12.0Gb/s','firmware'=>'SN02']],
]]]);
check('no double escaping', str_contains($h, 'A &amp; B') && !str_contains($h, 'A &amp;amp; B'));
```

**Verify**: `php tests/ajax_render_test.php` → `ajax_render: all pass`, exit 0,
with all thirteen new checks passing.

### Step 8: Prove the tests would have caught the bug

A regression test you have never seen fail proves nothing. Temporarily revert
one fix and confirm the suite goes red.

```bash
git stash push source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php
php tests/ajax_render_test.php; echo "exit=$?"
git stash pop
```

**Verify**: with the source change stashed, the test run **fails** (`exit=1`)
and reports `FAIL` on the escaping checks. Then, after `git stash pop`:

```bash
php tests/ajax_render_test.php; echo "exit=$?"
```

**Verify**: `exit=0`, `ajax_render: all pass`.

### Step 9: Sweep for anything missed, then run the full suite

Confirm no unescaped interpolation remains in the three render functions:

```bash
grep -nE "\\\$(p|d|e)\['[a-z_]+'\]" source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php \
  | grep -v htmlspecialchars
```

**Verify**: every remaining line is one of — a `luLinkBadge($p['link'])` call
(that helper emits a fixed literal `UP`/`DOWN` string and never interpolates its
argument), an `isset()`/`empty()`/arithmetic test, or an array key used for
lookup rather than output. If any line actually *prints* a value without
escaping, fix it and add a check for it.

**Verify**: `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` → exit 0

**Verify**: `bash tests/run.sh` → `--- all pass ---`, exit 0

## Test plan

**Added to `tests/ajax_render_test.php`** (created by plan 006) — thirteen checks:

| Area | Checks | Covers |
|---|---|---|
| PHY storcli | 2 | `phy` identifier and SAS address escaped |
| PHY lsiutil | 1 | `phy` identifier escaped |
| Drives storcli | 2 | model (regression guard on an already-correct field) and SAS address |
| Drives lsiutil | 3 | `os_name`, `bus`, `sas_address` — the wholly-unescaped branch |
| Events lsiutil | 3 | `seq`, `qualifier`, `timestamp` |
| Double-escape guard | 1 | `A & B` renders as `A &amp; B`, never `A &amp;amp; B` |

The double-escape guard is the important one for future maintenance: it fails
loudly if anyone later "simplifies" this by pushing `htmlspecialchars` into
`luTable`, which would corrupt every already-correct call site in the file.

Step 8 verifies the tests fail against unfixed code, which is the only thing
that makes them meaningful as regression tests.

**Verification**: `bash tests/run.sh` → `--- all pass ---`.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `php tests/ajax_render_test.php` exits 0 and prints `ajax_render: all pass`
- [ ] `grep -c "strtoupper(\$p\['sas_addr'\])" source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` prints `0` (the bare, unwrapped form is gone)
- [ ] `grep -c "'<code>' . \$d\['os_name'\]" source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` prints `0`
- [ ] `grep -c "'<code>' . \$e\['qualifier'\]" source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` prints `0`
- [ ] `grep -c "'<code>' . \$e\['timestamp'\]" source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` prints `0`
- [ ] `grep -c 'htmlspecialchars' source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` is **greater** than it was before this plan (record both numbers in your report)
- [ ] `luTable()` is unchanged: `git diff -- source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php | grep -c 'function luTable'` prints `0`
- [ ] Step 8 demonstrated the tests failing against unfixed source, then passing again
- [ ] `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` exits 0
- [ ] `bash tests/run.sh` exits 0 and prints `--- all pass ---`
- [ ] `git status --porcelain` shows exactly two modified files: `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` and `tests/ajax_render_test.php` (plus `plans/README.md`)
- [ ] `plans/README.md` status row for 007 updated

## STOP conditions

Stop and report back (do not improvise) if:

- `plans/006-ajax-render-tests.md` is not DONE, or
  `php tests/ajax_render_test.php` does not pass before you start. This plan
  edits rendering logic and depends on that net existing.
- Step 8 shows the tests **passing** against the stashed (unfixed) source. That
  means the assertions are not actually testing what they claim and need
  rewriting before the fix can be trusted.
- The double-escape check fails. You have escaped somewhere that was already
  escaped — most likely inside `luTable` or a helper — and every table in the
  plugin is now showing `&amp;amp;`.
- Step 9's sweep finds an unescaped output you cannot classify. Report it rather
  than guessing; some entries in that grep are legitimately fine (comparisons,
  array lookups) and blanket-escaping them would break the markup.
- You find yourself editing `collect_smart.sh` or `parse/storcli_drives.sh`.
  Those are a separate, explicitly out-of-scope bug at the JSON layer.

## Maintenance notes

- **The convention is now uniform: `ajax_info.php` escapes every value it
  prints; `luTable` escapes headers only and passes cells through as markup.**
  That split is deliberate — cells legitimately contain `<code>` and `<span>`
  wrappers built by the caller. Anyone adding a column follows the same rule:
  wrap the value, not the cell.
- **`view.php` returns values raw on purpose** (`"Values are returned RAW; each
  consumer escapes for its own medium"`). The dashboard tile and the monitor page
  are separate consumers with their own escaping. If a third consumer appears, it
  owns its own escaping too — do not push escaping down into `view.php`.
- **Still unescaped, one layer down, and knowingly so**: `collect_smart.sh:30`
  and `parse/storcli_drives.sh:11` interpolate drive model and serial into JSON
  without escaping quotes or backslashes. A drive reporting a `"` in its model
  produces malformed JSON and blanks the whole tab — worse than the HTML issue
  this plan fixed, but at a different layer and needing golden-test work in the
  shell parsers. `collect_smart.sh:10-11` documents it as a known deferral.
  Worth its own plan.
- **What a reviewer should scrutinise**: the `$ec` closure in the PHY storcli
  branch. The numeric comparison `$v > 0` must still run against the raw value,
  not the escaped string — otherwise error highlighting silently stops working
  and no test catches it, because the tests assert on escaping rather than on
  highlight-versus-value correlation.
