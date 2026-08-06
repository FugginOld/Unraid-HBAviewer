# 054 — Stop trusts a PID from `/tmp` and signals it as root

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 9bc490b..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/locate.php source/usr/local/emhttp/plugins/hbaviewer/scripts/locate_drive.sh tests/locate_test.php`
> Expected output: **nothing**. Every excerpt below is quoted from `9bc490b`
> (`dev` tip, 2026-08-05). Any difference is a STOP condition.
>
> **Worktree note**: a fresh worktree may be cut from `main`, not `dev`, and
> `git switch dev` FAILS inside a worktree because `dev` is checked out in the
> main tree. This has cost four plans now. Run `git log --oneline -1`, then use
> the command in "Git workflow" below, which lands on the right base either way.

## Status

Not started. The last of the three defects the 2026-08-05 pre-merge review of
`dev` left unowned; the other two became plans 052 and 053. P3 — see the honest
severity note below, which is the most important paragraph in this plan.

## Why this matters — and why the security framing is the weaker half

The review filed this as "`kill` trusts a PID read from world-writable `/tmp`":
`LOCATE_PID_DIR` is `/tmp`, `locate.php` casts the file's contents to `int` and
signals it as root, so anything able to write `/tmp` can make the Stop button
kill an arbitrary root process.

**Taken as a security finding, that is weak on this appliance, and this repo has
already rejected the same argument once.** From the "considered and rejected"
section of `plans/README.md`:

> **Predictable `/tmp` paths** (`hbav_overview.out`, `lsiutil_smart.json`,
> `hbav_flash/`) enabling symlink attacks — not worth code on a single-root
> appliance where anyone who can create those files is already root.

The precondition here is identical: to plant the marker you must already be
root. A plan that contradicts a recorded rejection without saying so is how a
ledger stops being trustworthy, so it is said here plainly.

**What makes this worth fixing is the other half, which needs no attacker at
all: PID reuse.** `locate_drive.sh` removes its marker with `trap … EXIT`, and
**SIGKILL bypasses traps**. So a locate loop killed with `kill -9`, OOM-killed,
or lost to a crash leaves its marker behind holding PID N. Linux recycles PIDs.
When some unrelated process later gets PID N:

- `locate_running()` sees `/proc/N` exists and reports that drive as blinking
  when it is not — the button reads STOP over a drive doing nothing;
- pressing it makes the plugin **`kill` an unrelated process, as root**.

No attacker, no `/tmp` write, just a `kill -9` and enough uptime. That is a
correctness bug in a feature that ships in 2026.08.05, and the fix for it
closes the security framing as a side effect.

## Current state

### `locate.php:19` — the directory, which is NOT changing

```php
const LOCATE_PID_DIR = '/tmp';
```

### `locate.php:34-48` — the check that is too weak

```php
function locate_pid(string $addr, ?string $dir = null): ?int {
    $f = locate_pid_path($addr, $dir);
    if (!is_file($f)) return null;
    $pid = (int) trim((string) @file_get_contents($f));
    return $pid > 0 ? $pid : null;
}

/* Running means the marker exists AND that process is still alive. A stale
   marker — killed with -9, or left behind by a crash — must read as NOT
   running, or the button never comes back and the drive can never be located
   again without a reboot. */
function locate_running(string $addr, ?string $dir = null, ?string $procDir = null): bool {
    $pid = locate_pid($addr, $dir);
    return $pid !== null && is_dir(($procDir ?? '/proc') . '/' . $pid);
}
```

The docblock already names the exact scenario — *"killed with -9"* — and then
tests for the wrong thing. "A process with this PID exists" is not "our process
is still running". 048's plan specified this rule as *"PID file exists **and**
`/proc/<pid>` exists"*, so **this is amending a plan decision, not correcting a
slip**, and the amendment is deliberate.

### `locate.php:97-112` — where the signal is sent

```php
if ($action === 'stop') {
    $pid = locate_pid($addr);
    // kill by PID only — never by name, never by pattern.
    if ($pid !== null) {
        shell_exec('kill ' . $pid . ' 2>/dev/null');
```

Note the gate: **`$pid !== null`, not `locate_running()`**. So the kill does not
even get the weak liveness check that the rest of the file uses. This is the
line that actually sends the signal.

### `scripts/locate_drive.sh:48-56` — why a stale marker exists at all

```bash
PIDFILE="$PID_DIR/hbav_locate_${ADDR//:/_}.pid"
echo $$ > "$PIDFILE"
# EXIT removes the marker however we leave, so the UI never shows a locate that
# is not running.
trap 'rm -f "$PIDFILE"' EXIT
```

Correct as written — `EXIT` covers every exit path a process can *choose*.
SIGKILL is the one it cannot. **This script is not changing.**

### `tests/locate_test.php:57-60` — the fixture shape you must update

```php
// A live locate: marker present AND the process exists.
file_put_contents(locate_pid_path('0:0:1:0', $dir), "4242\n");
@mkdir("$proc/4242", 0755, true);
check('a live marker reads as running', locate_running('0:0:1:0', $dir, $proc) === true);
```

Every "live process" fixture in this file is a bare directory with no
`cmdline`. **After this change they will all read as not-running and four
existing checks will fail** (`a live marker reads as running`, `active lists
exactly the live locates`, `active leaves the live markers alone`, `a malformed
marker name is ignored`). That is expected and is Step 3's job — it is not a
regression, and it is not a reason to weaken the guard.

## The fix, and the option deliberately not taken

**Chosen: verify the process is ours before trusting it.** Read
`/proc/<pid>/cmdline` and require it to name `locate_drive.sh`.

**Rejected: a plugin-owned `0700` PID directory.** The review offered it and it
is strictly worse here:

- it does **nothing** about PID reuse, which is the half of this that needs no
  attacker;
- it does nothing about a root attacker, which on this appliance is the only
  attacker there is;
- it requires a coordinated change across `locate.php` *and*
  `locate_drive.sh`, plus a migration for markers already in `/tmp`.

More code, more files, and it fixes the weaker half of the problem. The
`cmdline` check is three lines in one function and fixes both.

## Scope

**In**: `source/usr/local/emhttp/plugins/hbaviewer/locate.php`,
`tests/locate_test.php`.

**Out**:

- **`scripts/locate_drive.sh`.** It writes its own PID correctly and cannot
  clean up after SIGKILL by definition. `tests/locate_sh_test.sh` pins it and
  must stay unmodified and green.
- **`LOCATE_PID_DIR`.** It stays `/tmp`. Moving it is the rejected option above.
- **Any JavaScript.** Nothing about the client contract changes.
- **The `start` handler and `locate_reachable()`** (plan 053, merged today).
  They are read here, not edited.
- **Making the kill more forceful** (a `kill -9` follow-up if TERM does not
  land). The bounded wait already handles a slow exit and escalating is a
  different decision.

## Git workflow

Branch from `dev` (`9bc490b`), not `main`. Inside a worktree `git switch dev`
fails, because `dev` is checked out in the main tree; create the branch at the
commit instead, which works either way:

```bash
git log --oneline -1                              # expect 9bc490b or a descendant
git switch -c advisor/054-locate-pid-ownership 9bc490b
```

One commit per step, message ending in `(plan 054)`.

## Steps

### Step 1: Make `locate_running()` mean "our process"

Replace the function and its docblock:

```php
/* Running means the marker exists, that PID is alive, AND it is one of our own
   locate loops. The last clause is not paranoia about /tmp — on a single-root
   appliance anyone who can plant a marker is already root, and this repo has
   correctly rejected that framing before. It is about PID REUSE, which needs
   no attacker: locate_drive.sh clears its marker from a trap on EXIT, and
   SIGKILL bypasses traps. A loop killed with -9 leaves its marker holding a
   PID Linux will hand to something else, and without this check the plugin
   reports a drive as blinking when it is not and then kills a stranger's
   process as root when Stop is pressed.

   Matching the SCRIPT name, not the address: the address is also on that
   command line, but pinning it would mean any future change to how the loop is
   invoked silently breaks every Stop button. Wrong-drive is not a failure this
   can produce -- the marker file is already per-address. */
function locate_running(string $addr, ?string $dir = null, ?string $procDir = null): bool {
    $pid = locate_pid($addr, $dir);
    if ($pid === null) return false;
    $cmdline = @file_get_contents(($procDir ?? '/proc') . '/' . $pid . '/cmdline');
    // cmdline is NUL-separated argv; a substring test is enough and does not
    // care whether the loop was invoked as `bash <path>` or by any other route.
    return $cmdline !== false && str_contains($cmdline, basename(LOCATE_SCRIPT));
}
```

`basename(LOCATE_SCRIPT)` rather than the literal `'locate_drive.sh'`, so the
constant at the top of the file stays the single source of truth.

An unreadable or absent `cmdline` reads as not-running. That is the safe
direction: the cost is a Stop button that does not fire on a locate that really
is running (the loop still bounds itself and exits on its own), against killing
an unrelated root process.

### Step 2: Gate the kill on it

In the `stop` handler, replace the `$pid !== null` gate:

```php
if ($action === 'stop') {
    /* Gate on locate_running(), not merely on "a PID was in the file". This is
       the line that sends a signal as root, so it gets the strongest check we
       have rather than the weakest. locate_active() below then sweeps the
       marker, since a PID that is not our loop makes the marker stale by
       definition. */
    $pid = locate_running($addr) ? locate_pid($addr) : null;
    // kill by PID only — never by name, never by pattern.
    if ($pid !== null) {
```

Leave the rest of the handler, including the bounded wait, exactly as it is.

### Step 3: Repair the existing fixtures, then test the new rule

`tests/locate_test.php` builds every "live process" as a bare `$proc/<pid>`
directory. Each one now needs a `cmdline`. Add a tiny helper next to the
fixture setup and use it everywhere a live process is created:

```php
/* A live locate process: /proc/<pid>/cmdline naming our script, the way the
   kernel presents it — NUL-separated argv. */
function fake_proc(string $proc, int $pid, string $cmd = 'locate_drive.sh'): void {
    @mkdir("$proc/$pid", 0755, true);
    file_put_contents("$proc/$pid/cmdline", "bash\0/usr/local/…/$cmd\0" . "0:0:1:0\0");
}
```

Convert the four existing live-process fixtures (`4242`, `777`, `778`, and any
other `@mkdir("$proc/…")`) to use it, then add the new cases:

- a marker whose PID is alive but is **not** our script → **not** running
  (`fake_proc($proc, 5150, 'something_else.sh')`);
- that same case is swept by `locate_active()` like any other stale marker;
- a marker whose PID has **no `cmdline` at all** (bare directory, the
  pre-053 fixture shape) → **not** running;
- the existing live cases still read as running.

**Cleanup**: the teardown at the end of the file is
`foreach (glob("$proc/*") ?: [] as $d) @rmdir($d);` — `rmdir` fails on a
non-empty directory, so unlink each `cmdline` first or the temp tree leaks on
every run.

### Step 4: Mutation-test it

Revert Step 1's `locate_running()` to `return $pid !== null && is_dir(…)`,
re-run `php tests/locate_test.php`, and confirm the two new not-running checks
fail. Restore. A test that passes against the unfixed code proves nothing
(plans 051, 052, 053 all did this).

## Test plan

```bash
php  tests/locate_test.php     # locate: all pass
bash tests/locate_sh_test.sh   # unchanged, must stay green
bash tests/run.sh
php  tests/run_php.sh
```

`php` is not on PATH in the development environment; `tests/run_php.sh` falls
back to a `php:8.2-cli` container and **that is the repo's documented path, not
a deviation**. If neither a local `php` nor Docker works, report the PHP
verifications as blocked rather than skipping them quietly.

## Done criteria

- [ ] A marker whose PID is alive but is not `locate_drive.sh` reads as **not**
      running, and `locate_active()` sweeps it
- [ ] A marker whose PID has no `cmdline` reads as **not** running
- [ ] A genuine live locate still reads as running, and Stop still stops it
- [ ] The `stop` handler's kill is gated on `locate_running()`
- [ ] `LOCATE_PID_DIR` is still `'/tmp'` and `locate_drive.sh` is unmodified
- [ ] The new checks fail against the unfixed code (Step 4)
- [ ] `tests/locate_sh_test.sh` unchanged and green; whole suite green
- [ ] No file outside `locate.php` and `tests/locate_test.php` is modified

## STOP conditions

- The drift check reports any change, or you are not on `9bc490b` or a
  descendant.
- **Any edit reaches `scripts/locate_drive.sh`.** It cannot clean up after
  SIGKILL; that is the premise of this plan, not a bug in it.
- `LOCATE_PID_DIR` is changed, or a new PID directory is introduced. That is
  the option this plan rejected, with reasons.
- Any JavaScript is touched.
- The guard is weakened to make an existing fixture pass. The four failing
  fixtures are the expected consequence; fix the fixtures.
- A test is added that cannot fail.

## Maintenance notes

- **This amends plan 048's stated rule**, which defined running as "PID file
  exists and `/proc/<pid>` exists". The rule is now "…and that process is one
  of ours". 048's phrasing was not an oversight — it guarded staleness, which
  was the failure it had in view — so future readers should treat this as a
  decision changed on purpose, not a bug 048 shipped.
- **`/proc/<pid>/cmdline` is Linux-specific**, which is fine for an Unraid
  plugin and is why the tests inject `$procDir` rather than touching the real
  one.
- **The security framing stays rejected.** If a future audit re-raises
  "world-writable `/tmp`" as a vulnerability here, the answer is that it was
  considered twice, and what was fixed was PID reuse. Do not let it motivate
  moving the directory.
- **Third finding traceable to 048's direct-attach-only, happy-path Step 1** —
  after the bay-map key (052) and the silent start failure (053). The pattern
  is not "048 was careless"; it is that a plan verified only on the maintainer's
  own hardware has verified the path that works. Worth remembering when reading
  any plan's evidence section.
