# 053 — A locate that cannot start reports success

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 480fc86..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/locate.php source/usr/local/emhttp/plugins/hbaviewer/scripts/locate_drive.sh source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php tests/locate_test.php`
> Expected output: **nothing**. Every excerpt below is quoted from `480fc86`
> (`dev` tip, 2026-08-05). Any difference is a STOP condition.
>
> **Worktree note**: a fresh worktree may be cut from `main`, not `dev` — a trap
> that has cost three plans now (049, 050, 052). `git switch dev` also *fails*
> inside a worktree, because `dev` is checked out in the main tree. Run
> `git log --oneline -1`, then use the one command in "Git workflow" below,
> which lands on the right base either way.

## Status

Not started. Raised by the 2026-08-05 pre-merge review of `dev` and recorded in
`plans/README.md` under "Open defects from the 2026-08-05 branch review". P2 —
no data is at risk, but the feature tells the user it did something it did not,
and it shipped in 2026.08.05.

## Why this matters

`locate_drive.sh` exits 3 when `/dev/bsg/<addr>` does not exist. `locate.php`
spawns it with all output discarded, sleeps 250 ms, and answers `{"ok":true}`
regardless. So: the person reads a confirmation dialog, agrees to keep a drive
awake, presses Start — and nothing blinks, no error appears, and the button
sits back down to "Locate" as though they had never pressed it.

That is worse than an error. An error names a drive that cannot be located; a
silent no-op reads as "the light is too subtle to see", which is exactly what
the confirm dialog just warned them about. They will stand in front of the rack
looking for a rhythm that was never started.

**This is 048's own narrowing surfacing.** Step 1 of that plan confirmed every
`sdX` on the maintainer's box resolves to an existing bsg node — which is
precisely what left the failure path unexercised. 048 even captured the
*inverse* case (`0:0:0:0` is a bsg node with no block device behind it) and not
this one.

## The one thing that makes this cheap

**The client already handles the error.** `luLocatePost()` in
`hbaviewer.php:1063-1073`:

```javascript
            .then(function (j) {
                if (!j.ok) { alert(j.error || 'Locate failed.'); return; }
                luLocateApply(j.active || []);
            })
```

The contract exists and both callers — the Drives-row button and the bay-cell
button — already go through it. The server simply never sends `ok:false` on
this path. **No JavaScript changes in this plan.**

## Current state

### `locate.php:103-116` — the defect

```php
if ($action === 'start') {
    // Idempotent: a second press while it is already blinking is a no-op, not a
    // second process reading the same drive twice as often.
    if (!locate_running($addr)) {
        $max = lsi_clamp('LOCATE_MAX_SECS', lsi_config_read()['LOCATE_MAX_SECS']);
        shell_exec('nohup bash ' . escapeshellarg(LOCATE_SCRIPT) . ' '
                 . escapeshellarg($addr) . ' ' . (int) $max . ' >/dev/null 2>&1 &');
        // The script writes its own marker; give it a moment so the response
        // can report the state the caller is about to render.
        usleep(250000);
    }
    echo json_encode(['ok' => true, 'active' => locate_active()]);
    exit;
}
```

`'ok' => true` is a literal. Nothing between the spawn and the response can
change it.

### `locate_drive.sh:41-49` — the only two ways it can fail before the loop

```bash
case "$ADDR" in
    ''|*[!0-9:]*) echo "usage: locate_drive.sh <H:C:T:L> [max_secs]" >&2; exit 2 ;;
esac
case "$MAX" in ''|*[!0-9]*) MAX=300 ;; esac

[ -e "$BSG_DIR/$ADDR" ] || { echo "no such device: $BSG_DIR/$ADDR" >&2; exit 3; }

PIDFILE="$PID_DIR/hbav_locate_${ADDR//:/_}.pid"
echo $$ > "$PIDFILE"
```

Exit 2 cannot fire from the web path — `locate_addr_valid()` has already
rejected anything that is not four numbers and colons. **Exit 3 is the entire
failure surface**, and the marker is written on the very next line, so "the
marker did not appear" is a complete test for "it did not start".

**Do not change this script.** It reports its failure correctly on stderr and
in its exit code; the caller throws both away. The defect is in the caller.

### `locate.php:86-101` — the pattern to copy

The stop handler already solved the "wait for the marker to settle, but
bounded" problem:

```php
        for ($i = 0; $i < 20 && locate_running($addr); $i++) usleep(50000);   // ≤1s
```

Start should wait the same way rather than sleeping a flat 250 ms. It is more
robust on a loaded box **and faster in the common case** — the marker normally
appears in a few milliseconds, and today every successful locate costs the user
a quarter-second stall for nothing.

### `locate.php:23-32` — the convention new helpers must follow

```php
function locate_addr_valid(string $addr): bool {
    return (bool) preg_match('/^\d{1,4}:\d{1,4}:\d{1,4}:\d{1,4}$/', $addr);
}

function locate_pid_path(string $addr, ?string $dir = null): string {
    return ($dir ?? LOCATE_PID_DIR) . '/' . LOCATE_PREFIX . str_replace(':', '_', $addr) . '.pid';
}
```

Every path a helper touches is injectable, which is the whole reason
`tests/locate_test.php` runs with no `/proc`, no `/tmp` and no hardware. The
new helper must be the same shape.

## Scope

**In**: `source/usr/local/emhttp/plugins/hbaviewer/locate.php`,
`tests/locate_test.php`.

**Out**:

- **`scripts/locate_drive.sh`** — correct as written. See above.
- **`hbaviewer.php` / any JavaScript** — the client already alerts on
  `ok:false`. If you find yourself editing JS, you have misread the plan.
- **The `/tmp` PID-ownership weakness** (`LOCATE_PID_DIR` is world-writable, so
  `kill` trusts a PID anyone could have written). Real, recorded in
  `plans/README.md`, and **its own plan** — it amends a decision 048 made
  deliberately, which this plan does not.
- **The `stop` handler.** It is being *read* as the pattern to copy, not edited.
- **Making the confirm dialog conditional on reachability.** Tempting — do not.
  It would mean probing the device on render for every row.

## Git workflow

Branch from `dev` (`480fc86`), not `main`. Inside a worktree `git switch dev`
fails, because `dev` is checked out in the main tree; create the branch at the
commit instead, which works either way:

```bash
git log --oneline -1                            # expect 480fc86 or a descendant
git switch -c advisor/053-locate-start-failure 480fc86
```

One commit per step, message ending in `(plan 053)`.

## Steps

### Step 1: A reachability helper, pure over an injected path

Next to the other helpers in `locate.php`, above the dispatch:

```php
const LOCATE_BSG_DIR = '/dev/bsg';

/* Can this address be located at all? locate_drive.sh reads /dev/bsg/<addr>
   with smartctl and exits 3 when that node is absent -- which is the whole of
   its pre-loop failure surface, since locate.php has already validated the
   address shape. Checking here rather than parsing the script's exit code
   costs one stat and buys an error message that names the actual reason. */
function locate_reachable(string $addr, ?string $bsgDir = null): bool {
    return file_exists(($bsgDir ?? LOCATE_BSG_DIR) . '/' . $addr);
}
```

`file_exists`, not `is_file`: a bsg node is a character device, and `is_file()`
is false for one.

### Step 2: Make the start handler tell the truth

Replace the `start` branch body:

```php
if ($action === 'start') {
    // Idempotent: a second press while it is already blinking is a no-op, not a
    // second process reading the same drive twice as often.
    if (!locate_running($addr)) {
        /* Refuse before spawning when the device cannot be read. The script
           would exit 3 here, and its stderr goes to /dev/null -- so without
           this the caller is told the locate started, the button falls back to
           "Locate", and the person goes looking for a blink that was never
           started. An error naming the drive is the whole point. */
        if (!locate_reachable($addr)) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'active' => locate_active(),
                              'error' => 'No /dev/bsg node for ' . $addr
                                       . ' — this drive cannot be located.']);
            exit;
        }
        $max = lsi_clamp('LOCATE_MAX_SECS', lsi_config_read()['LOCATE_MAX_SECS']);
        shell_exec('nohup bash ' . escapeshellarg(LOCATE_SCRIPT) . ' '
                 . escapeshellarg($addr) . ' ' . (int) $max . ' >/dev/null 2>&1 &');
        /* Wait for the marker the same bounded way stop waits for it to go.
           The script writes it on the line after the bsg check, so this
           normally returns in a few milliseconds -- the flat 250ms sleep this
           replaces was paid by every successful locate. */
        for ($i = 0; $i < 20 && !locate_running($addr); $i++) usleep(50000);   // ≤1s
        /* Belt and braces: anything else that stops the loop reaching its
           marker -- the script missing, bash unavailable, the spawn refused --
           must not be reported as a start either. */
        if (!locate_running($addr)) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'active' => locate_active(),
                              'error' => 'Locate did not start for ' . $addr . '.']);
            exit;
        }
    }
    echo json_encode(['ok' => true, 'active' => locate_active()]);
    exit;
}
```

Both failure replies still carry `active`, so a UI that repaints from the
response stays truthful about every *other* drive rather than freezing.

### Step 3: Tests

In `tests/locate_test.php`, add a section following the existing numbered
style. The dispatch itself does not run under CLI, so test the helper and the
decision it drives, not the HTTP round trip — the same boundary every other
check in that file respects:

```php
/* ── N. Reachability: the gate that stops a silent no-op (plan 053) ─────── */
$bsg = sys_get_temp_dir() . '/hbav_bsg_test_' . getmypid();
@mkdir($bsg, 0777, true);
touch($bsg . '/0:0:1:0');
@mkdir($bsg . '/2:0:0:0');   // stands in for a character device: exists, not a regular file
check('an address with a bsg node is reachable',   locate_reachable('0:0:1:0', $bsg));
check('an address without one is not',             !locate_reachable('9:9:9:9', $bsg));
/* A real bsg node is a character device, so is_file() is false for it. If this
   check ever fails, locate_reachable has been "tidied" to is_file() and every
   locate on real hardware will be refused -- the exact inverse of this plan. */
check('a node that is not a regular file still counts as reachable',
      !is_file($bsg . '/2:0:0:0') && locate_reachable('2:0:0:0', $bsg));
check('a missing bsg directory reads as unreachable',
      !locate_reachable('0:0:1:0', $bsg . '/nope'));
@unlink($bsg . '/0:0:1:0'); @rmdir($bsg . '/2:0:0:0'); @rmdir($bsg);
```

Every one of those four must be able to fail; Step 4 proves two of them can.
**A check that cannot fail is worse than no check** — this repo has shipped
three of them, recorded in the 050 notes in `plans/README.md`.

### Step 4: Mutation-test it

Change `locate_reachable()` to `return true;`, re-run
`php tests/locate_test.php`, confirm the "without one is not" and "missing
directory" checks fail, then restore. A test that passes against the unfixed
code proves nothing (plan 051 Step 5, plan 052 Step 6).

## Test plan

```bash
php  tests/locate_test.php     # locate: all pass
bash tests/locate_sh_test.sh   # unchanged, must stay green
bash tests/run.sh
php  tests/run_php.sh
```

`php` may not be on PATH; `tests/run_php.sh` falls back to a `php:8.2-cli`
container and that is the repo's documented path, not a deviation. If neither
a local `php` nor Docker is available, say so rather than skipping quietly.

## Done criteria

- [ ] `locate_reachable()` exists, takes an injectable directory, and uses
      `file_exists` rather than `is_file`
- [ ] Starting a locate for an address with no bsg node returns
      `ok:false` with an error naming the address, and does **not** spawn
- [ ] A successful start still returns `ok:true` with the address in `active`
- [ ] The flat `usleep(250000)` is gone, replaced by the bounded poll
- [ ] The new checks fail against the unfixed code (Step 4)
- [ ] `tests/locate_sh_test.sh` is unchanged and green
- [ ] Whole suite green
- [ ] No file outside `locate.php` and `tests/locate_test.php` is modified

## STOP conditions

- The drift check reports any change, or you are not on `480fc86` or a
  descendant.
- **Any edit reaches `scripts/locate_drive.sh`.** Its behaviour is correct and
  `tests/locate_sh_test.sh` pins it.
- **Any edit reaches JavaScript.** The client already handles `ok:false`; if it
  appears not to, re-read `luLocatePost()` before changing anything.
- The `/tmp` PID-directory ownership question is touched. Different plan.
- `locate_running()`, `locate_active()` or the `stop` handler change shape.
  A locate that is genuinely running must keep reporting as running.
- A test is added that asserts something that cannot fail.

## Maintenance notes

- **The response gained a failure shape.** `ok:false` now carries `active` as
  well as `error`. Any future client must keep repainting from `active` on a
  failure, or one drive's refusal will freeze the display of every other.
- **`locate_reachable()` is a pre-check, not a guarantee.** The node can vanish
  between the check and the spawn; the post-spawn marker check is what covers
  that, which is why both exist rather than just the first.
- **This is the second finding from 048's direct-attach-only reference
  hardware** (the first being the bay-map key, plan 052). A plan whose Step 1
  says "confirmed on the maintainer's box" has confirmed the happy path and
  nothing else. Worth remembering when reading any plan's evidence section.
