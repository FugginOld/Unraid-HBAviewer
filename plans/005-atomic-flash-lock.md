# Plan 005: Claim the flash single-flight lock atomically

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 0346777..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/flash.php tests/flash_php_test.php`
> If either file changed since this plan was written, compare the "Current
> state" excerpts against the live code before proceeding; on a mismatch,
> treat it as a STOP condition.
>
> **Read this before starting**: the code you are changing sits directly in
> front of an operation that can permanently destroy a user's hardware. The
> change itself is small. Do not expand it.

## Why this matters

`flash.php` enforces a single-flight rule: only one firmware flash may run at a
time. It enforces it by checking whether a lock file exists, and then — seven
lines later — creating it. Between those two statements, a second request can
run the same check, see no lock, and proceed. Both requests then pass the gate,
both create the lock, and both launch `flash_hba.sh flash` against the same
controller.

Two concurrent flash tools writing to the same HBA is precisely the scenario the
single-flight rule exists to prevent, and this is the one code path in the entire
plugin that can permanently brick a card. Every other guard here — the opt-in
toggle, the array-stopped check, the typed `FLASH` confirmation, the upload
confinement — is implemented correctly and holds. This one has a hole in it.

Triggering it requires two near-simultaneous submissions: an impatient
double-click on the Flash button, a browser retrying a request, two admins on
the same box. Unlikely, not impossible, and the cost of being wrong is a dead
controller.

The fix is to claim the lock with an operation that cannot be raced —
`fopen($lock, 'x')`, which fails if the file already exists — instead of
checking and then creating.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `0346777`, 2026-07-26

## Current state

Files involved:

- `source/usr/local/emhttp/plugins/hbaviewer/flash.php` — the flash endpoint.
  Pure guard functions at lines 21–62; HTTP dispatch from line 65; the `flash`
  action at lines 112–147.
- `tests/flash_php_test.php` — unit tests for the pure guards.

The racy sequence, `flash.php:112-147`:

```php
if ($action === 'flash') {
    header('Content-Type: application/json');
    $chip   = preg_replace('/[^A-Za-z0-9]/', '', $_POST['chip'] ?? '');
    $ctl    = (string) ($_POST['ctl'] ?? '');
    $fwName  = flash_safe_name((string) ($_POST['firmware'] ?? ''), ['bin', 'rom', 'fw']);
    $biosNm  = ($_POST['bios'] ?? '') !== '' ? flash_safe_name((string) $_POST['bios'], ['rom', 'bin']) : null;
    $fw     = $fwName !== null ? FLASH_DIR . '/' . $fwName : '';
    $lock   = FLASH_DIR . '/flash.lock';

    $pf = flash_preflight([
        'enable'  => $enable,
        'stopped' => flash_array_stopped(),
        'ctl'     => $ctl,
        'fw'      => $fw,
        'confirm' => $_POST['confirm'] ?? '',
        'locked'  => is_file($lock),
    ]);
    if (!$pf['ok'])   { echo json_encode(['error' => $pf['error']]); exit; }
    if ($chip === '') { echo json_encode(['error' => 'Missing controller chip.']); exit; }

    // Single-flight: claim the lock, clear prior artifacts, launch ONE detached
    // job that captures stdout+stderr and records its exit code. Never auto-relaunched.
    @touch($lock);
    @unlink(FLASH_DIR . '/flash.log');
    @unlink(FLASH_DIR . '/flash.status');
    $bios = ($biosNm !== null && is_file(FLASH_DIR . '/' . $biosNm)) ? FLASH_DIR . '/' . $biosNm : '';
    $cmd  = 'bash ' . escapeshellarg(FLASH_SCRIPTS . '/flash_hba.sh') . ' flash '
          . escapeshellarg($chip) . ' ' . escapeshellarg($ctl) . ' ' . escapeshellarg($fw)
          . ($bios !== '' ? ' ' . escapeshellarg($bios) : '');
    $inner = "$cmd > " . escapeshellarg(FLASH_DIR . '/flash.log') . ' 2>&1; '
           . 'echo $? > ' . escapeshellarg(FLASH_DIR . '/flash.status') . '; '
           . 'rm -f ' . escapeshellarg($lock);
    shell_exec('nohup sh -c ' . escapeshellarg($inner) . ' >/dev/null 2>&1 &');
    echo json_encode(['ok' => true, 'state' => 'flashing']);
    exit;
}
```

The race is `'locked' => is_file($lock)` on line 127 against `@touch($lock)` on
line 134.

The pure preflight it feeds, `flash.php:40-62`, which stays exactly as it is:

```php
/* Pure preflight gate for a flash request. Returns [ok=>bool, error=>string].
   The handler injects real values; tests inject fakes. Order = user-friendliest
   failure first, but every check is a hard block. */
function flash_preflight(array $in): array {
    if ((int) ($in['enable'] ?? 0) !== 1)
        return ['ok' => false, 'error' => 'Firmware flashing is disabled. Enable it in Settings first.'];
    ...
    if (!empty($in['locked']))
        return ['ok' => false, 'error' => 'A flash is already in progress.'];
    return ['ok' => true, 'error' => ''];
}
```

Two facts that make the fix safe:

1. `FLASH_DIR` is created before any action runs — `flash.php:74`,
   `@mkdir(FLASH_DIR, 0755, true);` — so `fopen(..., 'x')` will not fail merely
   because the directory is missing.
2. The lock is released by the detached job itself, in the `$inner` command
   string: `'rm -f ' . escapeshellarg($lock)`. That stays unchanged. This plan
   only changes how the lock is *acquired*.

**Repo conventions that apply here:**

- The design principle for this file is stated in its own header,
  `flash.php:10-12`: *"The guard functions are pure over injected inputs and
  unit-tested; the HTTP dispatch at the bottom runs only when served (not under
  the CLI test runner)."* Anything guard-shaped belongs **above** the
  `if (PHP_SAPI === 'cli') return;` line at `flash.php:65`, so tests can reach it.
- Guard functions carry a block comment explaining what they enforce and why —
  see `flash_array_stopped` (`flash.php:21-27`) and `flash_safe_name`
  (`flash.php:29-38`).
- Tests use a bare `check(string $name, bool $ok)` helper and print
  `PASS  <name>` / `FAIL  <name>`, ending with a summary line and an exit code.
  See `tests/flash_php_test.php:9-14` for the exact helper — copy its shape.

Relevant vocabulary from `source/usr/local/emhttp/plugins/hbaviewer/CONTEXT.md`
(the module glossary — the executor has not read it):

> **flash (mutating)** — `flash.php` + `scripts/flash_hba.sh`
> The ONE place HBAviewer writes to hardware, kept off the read-only path.
> Opt-in (`ENABLE_FLASH`, default off). `flash.php` owns the guards —
> `flash_preflight` (array STOPPED via `flash_array_stopped`, valid controller,
> confirmed image, single-flight lock), `flash_safe_name` (upload confinement) —
> all pure and unit-tested; the HTTP dispatch is skipped under CLI.

Use the term **single-flight** in comments and names — that is this codebase's
established word for the rule you are fixing.

## Commands you will need

| Purpose         | Command                                                              | Expected on success        |
|-----------------|----------------------------------------------------------------------|----------------------------|
| PHP lint        | `find source tests -name '*.php' -print0 \| xargs -0 -r -n1 php -l`  | exit 0, "No syntax errors" |
| Flash PHP tests | `php tests/flash_php_test.php`                                       | `flash_php: all pass`, exit 0 |
| Flash shell tests | `bash tests/flash_test.sh`                                         | `flash: all pass`, exit 0  |
| Full test suite | `bash tests/run.sh`                                                  | `--- all pass ---`, exit 0 |

If `php` is not installed locally, `tests/run.sh` falls back to a
`php:8.2-cli` Docker container. **This plan adds a PHP unit test, so you must be
able to run the PHP half.** If neither `php` nor Docker is available, STOP and
report — do not add a test you cannot execute.

## Scope

**In scope**:

- `source/usr/local/emhttp/plugins/hbaviewer/flash.php`
- `tests/flash_php_test.php`

**Out of scope** (do NOT touch, even though they look related):

- `flash_preflight` itself. Its `locked` check is correct and is covered by an
  existing test (`tests/flash_php_test.php:48`). You are changing what the
  *caller* passes in, not the function.
- `source/usr/local/emhttp/plugins/hbaviewer/scripts/flash_hba.sh` — the script
  that performs the flash. Do not add locking there. The lock's purpose is to
  stop a second job from ever being launched; enforcing it inside the job is
  both later and harder.
- The lock release in the `$inner` command string. It stays as `rm -f`.
- `source/usr/local/emhttp/plugins/hbaviewer/cached_read.php` — it has the same
  check-then-act shape at lines 32–33, but there the worst case is a duplicate
  hardware scan, and it has a `lock_ttl` escape hatch that this path deliberately
  does not. Leave it alone; it is noted in Maintenance notes below.
- Stale-lock recovery (a lock left behind by a machine that lost power
  mid-flash). `/tmp` is tmpfs on Unraid, so a reboot clears it — and a flash
  that was interrupted by a power loss is a situation that should require human
  attention, not automatic retry. Explicitly not in scope.
- Any change to the browser-side flash flow in
  `source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php:469-501`. The JS
  guards are described in that file as "only fast feedback"; the server is
  authoritative and that is the correct posture.

## Git workflow

- Branch: `advisor/005-atomic-flash-lock`
- One commit. Message style matches this repo's history — short imperative
  subject, no conventional-commit prefix. Suggested:
  `Claim the flash single-flight lock atomically`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add a pure, testable lock-claim guard

In `source/usr/local/emhttp/plugins/hbaviewer/flash.php`, add this function
immediately after `flash_preflight` ends (after line 62) and **before** the
`/* ── HTTP dispatch ... */` comment on line 64. It must be above
`if (PHP_SAPI === 'cli') return;` so the test runner can reach it.

```php
/* Claim the single-flight lock ATOMICALLY. 'x' fails when the file already
   exists, so of two concurrent requests exactly one can win — unlike
   is_file()-then-touch(), which let both pass the gate and launch a flash at
   the same controller. Returns true if THIS caller now owns the lock, in which
   case it must release it on any subsequent refusal. */
function flash_claim_lock(string $lock): bool {
    $fh = @fopen($lock, 'x');
    if ($fh === false) return false;
    fclose($fh);
    return true;
}
```

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/flash.php` → "No syntax errors detected"

### Step 2: Use it in the flash action, and release on every refusal

Replace `flash.php:121-134` — from the `$pf = flash_preflight([` line through
`@touch($lock);` — with:

```php
    // Claim single-flight BEFORE the gate, so the check and the claim can't be
    // interleaved by a second request. Any refusal below hands the lock back.
    $owned = flash_claim_lock($lock);

    $pf = flash_preflight([
        'enable'  => $enable,
        'stopped' => flash_array_stopped(),
        'ctl'     => $ctl,
        'fw'      => $fw,
        'confirm' => $_POST['confirm'] ?? '',
        'locked'  => !$owned,
    ]);
    if (!$pf['ok'] || $chip === '') {
        // Only release a lock we actually own — if $owned is false another
        // request holds it and unlinking would break ITS single-flight.
        if ($owned) @unlink($lock);
        echo json_encode(['error' => $pf['ok'] ? 'Missing controller chip.' : $pf['error']]);
        exit;
    }

    // Single-flight lock is held. Clear prior artifacts, launch ONE detached job
    // that captures stdout+stderr and records its exit code. Never auto-relaunched.
    @unlink(FLASH_DIR . '/flash.log');
    @unlink(FLASH_DIR . '/flash.status');
```

Everything from `$bios = ...` onward is unchanged.

Three details worth confirming as you make this edit, because getting any of
them wrong reintroduces a defect:

1. `'locked' => !$owned` — a failed claim means someone else holds it, which is
   exactly what `flash_preflight` should be told.
2. `if ($owned) @unlink($lock);` — the guard on `$owned` is load-bearing. Without
   it, a request refused *because the lock was taken* would delete the winning
   request's lock.
3. The error-message precedence matches the original: preflight's message wins
   when preflight failed, and `'Missing controller chip.'` is used only when
   preflight passed. The original checked these in two separate `if`s in that
   order; the merged condition preserves it.

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/flash.php` → "No syntax errors detected"

**Verify**: the racy pair is gone —
`grep -c 'is_file($lock)' source/usr/local/emhttp/plugins/hbaviewer/flash.php` → prints `1`
(the remaining one is the `status` action at line 153, which only *reports*
whether a flash is running and is not part of the race)

**Verify**: `grep -c '@touch($lock)' source/usr/local/emhttp/plugins/hbaviewer/flash.php` → prints `0`

### Step 3: Unit-test the claim

Add to `tests/flash_php_test.php`, immediately before the final `echo`/`exit`
block at lines 51–52. Follow the existing file's style — the `check()` helper is
already defined at the top of that file.

```php
// ── flash_claim_lock: exactly one claimant wins, and a release re-arms it ────
// This is the single-flight guarantee that stands between a double-submit and
// two flash tools writing to the same controller at once.
$lk = sys_get_temp_dir() . '/hbav_lock_' . getmypid() . '.lock';
@unlink($lk);
check('claim lock: first wins',      flash_claim_lock($lk) === true);
check('claim lock: second refused',  flash_claim_lock($lk) === false);
check('claim lock: third refused',   flash_claim_lock($lk) === false);
@unlink($lk);
check('claim lock: re-arms after release', flash_claim_lock($lk) === true);
@unlink($lk);
```

**Verify**: `php tests/flash_php_test.php` → ends with `flash_php: all pass`, exit code 0, and the output includes the four new `PASS  claim lock: ...` lines.

### Step 4: Confirm nothing else regressed

The existing flash guards have thorough coverage; make sure your change did not
disturb them.

**Verify**: `bash tests/flash_test.sh` → `flash: all pass`, exit 0

**Verify**: `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` → exit 0

**Verify**: `bash tests/run.sh` → `--- all pass ---`, exit 0

## Test plan

**New tests** — four cases in `tests/flash_php_test.php`, following the
structural pattern of the `flash_array_stopped` block already in that file
(`tests/flash_php_test.php:25-32`), which likewise writes a temp file, asserts
against it, and cleans up:

| Case | Asserts |
|---|---|
| `claim lock: first wins` | a free lock is claimable — the happy path |
| `claim lock: second refused` | **the regression case** — a held lock cannot be claimed again, which is exactly what `is_file()`-then-`touch()` failed to guarantee |
| `claim lock: third refused` | the refusal is stable, not a one-shot |
| `claim lock: re-arms after release` | the detached job's `rm -f` genuinely frees it for the next flash |

**What is deliberately not tested, and why.** The `$action === 'flash'` dispatch
block itself is unreachable from the test runner — `flash.php:65` returns early
under CLI, by design, so that requiring the file for tests can never
accidentally trigger a flash. Extracting the whole dispatch into a testable
function would be a large refactor of the most dangerous file in the repository,
and this plan is not the place for it. The atomic primitive is tested; its two
call sites are three lines of straight-line code that a reviewer can read.

**Verification**: `bash tests/run.sh` → all pass, including four new cases.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'function flash_claim_lock' source/usr/local/emhttp/plugins/hbaviewer/flash.php` prints `1`
- [ ] `grep -c "fopen(\$lock, 'x')" source/usr/local/emhttp/plugins/hbaviewer/flash.php` prints `1`
- [ ] `grep -c '@touch($lock)' source/usr/local/emhttp/plugins/hbaviewer/flash.php` prints `0`
- [ ] `grep -c 'is_file($lock)' source/usr/local/emhttp/plugins/hbaviewer/flash.php` prints `1` (the `status` action only)
- [ ] `flash_claim_lock` is defined **above** the `if (PHP_SAPI === 'cli') return;` line — confirm with `grep -n 'function flash_claim_lock\|PHP_SAPI' source/usr/local/emhttp/plugins/hbaviewer/flash.php` and check the line numbers
- [ ] `php tests/flash_php_test.php` exits 0 and prints four new `PASS  claim lock: ...` lines
- [ ] `bash tests/flash_test.sh` exits 0 and prints `flash: all pass`
- [ ] `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` exits 0
- [ ] `bash tests/run.sh` exits 0 and prints `--- all pass ---`
- [ ] `git status --porcelain` shows exactly two modified files: `source/usr/local/emhttp/plugins/hbaviewer/flash.php` and `tests/flash_php_test.php` (plus `plans/README.md`)
- [ ] `plans/README.md` status row for 005 updated

## STOP conditions

Stop and report back (do not improvise) if:

- You cannot run the PHP tests (no `php`, no Docker). This plan adds a test; an
  unverified test on this code path is worse than none.
- The `flash` action does not match the excerpt in "Current state" — especially
  if someone has already changed the locking. Report what is there.
- After your change, `php tests/flash_php_test.php` shows any **pre-existing**
  test failing (one of the `safe name`, `array stopped`, or `preflight` cases).
  That means the edit disturbed a guard it should not have touched.
- You find yourself wanting to add locking inside `scripts/flash_hba.sh`, or to
  make the lock auto-expire on a timeout. Both are out of scope and the second
  one is actively unwanted on this path — report the reasoning instead.
- The change appears to require touching `flash_preflight`. It does not; the
  function is correct and its `locked` input is simply computed differently now.

## Maintenance notes

- **`cached_read.php:32-33` has the same check-then-act shape and is
  deliberately left alone.** There, losing the race costs a duplicate hardware
  scan, and it has a `lock_ttl` so a dead job cannot wedge it forever. That
  trade-off is right for a read and wrong for a flash. If anyone "unifies" the
  two locking approaches, the flash path must keep the atomic claim and must not
  inherit a TTL — an expiring lock on a flash means a second flash can start
  while the first is still running.
- **The lock is released in exactly two places** after this change: the detached
  job's `rm -f` on completion, and the `if ($owned) @unlink($lock)` refusal path.
  If a third release site ever appears, check very carefully that it can only
  release a lock the caller owns.
- **A power loss mid-flash leaves the lock behind**, and that is intended: the
  next flash attempt is refused until someone reboots (tmpfs clears it) or
  removes the file by hand. A machine that lost power during a firmware write
  needs a human to look at it before another write is allowed.
- **What a reviewer should scrutinise**: the `if ($owned)` guard on the unlink.
  That single condition is the difference between a correct fix and a worse bug
  than the one being fixed — without it, a request that is refused *because
  someone else is flashing* would delete that flash's lock.
