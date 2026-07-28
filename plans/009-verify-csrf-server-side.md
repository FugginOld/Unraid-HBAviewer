# Plan 009: Verify Unraid's CSRF token server-side instead of assuming the platform did

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 0346777..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/settings.php source/usr/local/emhttp/plugins/hbaviewer/flash.php source/usr/local/emhttp/plugins/hbaviewer/config.php`
> `flash.php` is also modified by `plans/005-atomic-flash-lock.md`. If 005 is
> DONE, that diff is expected — confirm the *lock* code matches 005's target
> shape and that the dispatch structure quoted below is otherwise intact.
>
> **Sequencing**: land `plans/005-atomic-flash-lock.md` before this one. Both
> edit `flash.php`; 005 is higher priority and its change is smaller.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: MED
- **Depends on**: `plans/005-atomic-flash-lock.md` (sequencing only, to avoid a conflict in `flash.php`)
- **Category**: security
- **Planned at**: commit `0346777`, 2026-07-26

> ## The open question is now answered — the finding is confirmed
>
> **Verified on real hardware, 2026-07-27.** With the stock settings form (no
> `csrf_token` field anywhere in it), changing the Alert Threshold and saving
> **worked, and the value persisted across a reload**.
>
> That settles the ambiguity this plan was written around. Of the two
> possibilities — either Unraid enforces CSRF and the settings form has been
> silently broken, or Unraid does not enforce it and both POST endpoints are
> unprotected — **the second is true**. Saving works precisely *because* nothing
> is checking for a token.
>
> Two consequences for the executor:
>
> 1. **The finding is real.** `settings.php` and `flash.php` have no CSRF
>    protection at all, from any layer. The comments in `flash.php:72-73` and
>    `hbaviewer.php:395` asserting that "Unraid rejects POSTs without its CSRF
>    token" are simply wrong, and this plan replaces that assumption with an
>    actual check.
> **Authentication is present; CSRF protection is not — do not confuse them.**
> Observed 2026-07-27: an unauthenticated POST to
> `/plugins/hbaviewer/flash.php` is answered by nginx with `302 -> /login`. That
> is Unraid's *auth* layer and it is working. It does **nothing** against CSRF: a
> cross-site request rides on the victim's already-authenticated session, so the
> browser attaches the session cookie automatically and the redirect never fires.
> Anyone re-reading this finding and concluding "but it redirects to login, so
> we're covered" has conflated the two controls.
>
> 2. **The fail-closed risk is lower than feared.** The worry was that adding a
>    server-side check might lock the user out of their own settings on a
>    platform that never issues a usable token. Since the token is present in
>    `var.ini` on this install and the form is what fails to send it, emitting the
>    field and verifying it should simply work. Step 8's hardware check still
>    applies — confirm saving *still* works after the change — but it is now a
>    regression check rather than an open question.

## Why this matters

This plugin has two POST endpoints, and neither one checks a CSRF token. Both
rely on the assumption that Unraid's platform layer rejects token-less POSTs
before they arrive. The codebase states that assumption twice, in comments, and
never verifies it.

The settings form makes the contradiction visible. `flash.php:72-73` says *"CSRF
is enforced by Unraid's platform layer (a token-less POST never reaches here)"*,
and `hbaviewer.php:395` says *"Unraid rejects POSTs without its CSRF token"* —
yet `settings.php` submits a plain `<form method="post">` carrying no token at
all. Exactly one of those things can be true:

- **If the platform does enforce it**, the settings form is being rejected and
  saving settings is silently broken.
- **If it does not**, then neither endpoint has any CSRF protection — and the
  second endpoint is the firmware flasher.

Rather than gambling on which, this plan makes the question irrelevant: read
Unraid's token, send it from the form, and **verify it on the server**. That is
correct under either answer, needs no hardware to decide, and removes an
assumption about someone else's code from a security boundary.

The severity is bounded and worth stating plainly. Reaching either endpoint
requires an authenticated administrator's browser to be pointed at a hostile
page. The realistic worst case is an attacker flipping `ENABLE_FLASH` on, or
uploading a firmware image — not flashing it, since that additionally needs the
array stopped and a typed confirmation. Low likelihood; the flash endpoint makes
the impact worth closing anyway.

## Current state

Files involved:

- `source/usr/local/emhttp/plugins/hbaviewer/config.php` — 55 lines. Shared
  config schema, read and write. **Required by both** `settings.php` (line 5) and
  `flash.php` (line 14), which makes it the natural home for a shared helper.
- `source/usr/local/emhttp/plugins/hbaviewer/settings.php` — the settings form.
- `source/usr/local/emhttp/plugins/hbaviewer/flash.php` — the flash endpoint.
- `source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php` — reads the token
  already; the client-side reference implementation.
- `tests/config_test.php` — where the new unit tests go.

**The settings form, `settings.php:38-52`** — POST handling with no token check:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_hbaviewer'])) {
    // Map the form (checkbox-absent = off); config_write clamps to schema.
    lsi_config_write([
        'HBA_PORT'        => $_POST['port']      ?? 1,
        'ALERT_THRESHOLD' => $_POST['threshold'] ?? 80,
        'SHOW_PCIE'       => isset($_POST['show_pcie'])   ? 1 : 0,
        'SHOW_PHY'        => isset($_POST['show_phy'])    ? 1 : 0,
        'SHOW_DRIVES'     => isset($_POST['show_drives']) ? 1 : 0,
        'SHOW_EVENTS'     => isset($_POST['show_events']) ? 1 : 0,
        'SHOW_PERF'       => isset($_POST['show_perf'])   ? 1 : 0,
        'ENABLE_FLASH'    => isset($_POST['enable_flash']) ? 1 : 0,
    ]);
    $cfg   = lsi_config_read();
    $saved = true;
}
```

and `settings.php:99`, the form open tag, with no hidden token field:

```php
  <form method="post">
```

**The flash endpoint's assumption, `flash.php:67-74`:**

```php
$cfg    = lsi_config_read();
$enable = (int) $cfg['ENABLE_FLASH'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($enable !== 1) { http_response_code(403); echo 'Firmware flashing is disabled.'; exit; }
// CSRF is enforced by Unraid's platform layer (a token-less POST never reaches
// here). The client sends Unraid's csrf_token so that layer passes it through.
@mkdir(FLASH_DIR, 0755, true);
```

**How the token is already read elsewhere**, `hbaviewer.php:19-23` — this is the
working reference for where the value lives:

```php
if ($enableFlash) {
    $vi = @parse_ini_file('/var/local/emhttp/var.ini');
    $arrayStopped = is_array($vi) && strtoupper((string) ($vi['mdState'] ?? '')) === 'STOPPED';
    $csrfToken    = is_array($vi) ? (string) ($vi['csrf_token'] ?? '') : '';  // Unraid requires this on POST
}
```

And the client already sends it on every flash request —
`hbaviewer.php:440`, `:452`, `:481` all include `csrf_token: flashCsrf`. So the
flash UI needs **no** client change; only the server needs to start checking
what is already being sent.

**The same `parse_ini_file` pattern is already used as a guard** in
`flash.php:21-27`, which is the shape to copy:

```php
/* Array must be STOPPED before flashing. A missing/unreadable var.ini or any
   non-STOPPED state fails safe -> block. */
function flash_array_stopped(string $varini = FLASH_VARINI): bool {
    if (!is_file($varini)) return false;
    $ini = @parse_ini_file($varini);
    return is_array($ini) && strtoupper((string) ($ini['mdState'] ?? '')) === 'STOPPED';
}
```

Note the convention it establishes: **an unreadable `var.ini` fails safe, and
"fails safe" means refuse.** This plan follows the same rule.

**Repo conventions that apply here:**

- Guard functions are pure over injected inputs, take a defaulted path
  parameter so tests can pass a temp file, and are unit-tested. See
  `flash_array_stopped` above and its test at `tests/flash_php_test.php:25-32`.
- `config.php` is the "single home" for shared config concerns (its own header
  says so) and is the only file both endpoints already require.
- Test files use `check(string $name, bool $ok): void` printing `PASS  `/`FAIL  `,
  a `$fails` counter, a summary line, and `exit($fails === 0 ? 0 : 1)`. All five
  existing test files share this helper verbatim — see `tests/view_test.php:7-12`.
- HTML attribute values are escaped with `ENT_QUOTES` — see
  `hbaviewer.php:397`, `htmlspecialchars($csrfToken, ENT_QUOTES)`.

## Commands you will need

| Purpose          | Command                                                              | Expected on success        |
|------------------|----------------------------------------------------------------------|----------------------------|
| PHP lint         | `find source tests -name '*.php' -print0 \| xargs -0 -r -n1 php -l`  | exit 0, "No syntax errors" |
| Config tests     | `php tests/config_test.php`                                          | `all pass`, exit 0         |
| PHP tests        | `bash tests/run_php.sh`                                              | all files pass, exit 0     |
| Full test suite  | `bash tests/run.sh`                                                  | `--- all pass ---`, exit 0 |

You must be able to run the PHP tests; this plan adds unit tests.
`tests/run_php.sh` falls back to `php:8.2-cli` via Docker.

## Scope

**In scope**:

- `source/usr/local/emhttp/plugins/hbaviewer/config.php` (add two helpers)
- `source/usr/local/emhttp/plugins/hbaviewer/settings.php` (emit the token; verify on POST)
- `source/usr/local/emhttp/plugins/hbaviewer/flash.php` (verify on POST)
- `tests/config_test.php` (add unit tests)

**Why `flash.php` is included even though the finding named only `settings.php`**:
the defect is one assumption — "the platform checks this for us" — expressed in
two places. Fixing the settings form while leaving the firmware flasher relying
on the same unverified assumption would fix the symptom and leave the more
dangerous instance open. One shared helper covers both, and the flash client
already sends the token.

**Out of scope** (do NOT touch, even though they look related):

- `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` — read-only GET
  endpoints. CSRF protection applies to state-changing requests; adding it to a
  read path would break the Monitor's polling for no benefit.
- The `status` and `listall` actions' GET fallbacks in `flash.php`
  (`$_GET['action']`). `status` is read-only. `listall` runs a read-only
  controller listing and is invoked by the client over POST with a token, so it
  is covered by the POST path; leave the GET fallback alone rather than widening
  this change.
- The client-side flash JS in `hbaviewer.php:437-501`. It already sends
  `csrf_token` on all three POSTs. No change needed.
- Unraid's own login/session handling. Out of this project's control.
- `lsi_config_write`'s clamping. Already correct and covered by
  `tests/config_test.php`.

## Git workflow

- Branch: `advisor/009-verify-csrf-server-side`
- Suggested commits: one for the shared helper plus tests, one for wiring it into
  the two endpoints. Message style matches this repo's history — short
  imperative subject, no conventional-commit prefix. Suggested:
  `Verify Unraid's CSRF token server-side on both POST endpoints`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add the shared helpers to config.php

Append to `source/usr/local/emhttp/plugins/hbaviewer/config.php`, after
`lsi_config_write` (after line 55):

```php

/* ── CSRF ────────────────────────────────────────────────────────────────────
   Unraid issues a per-session token and its own layer is expected to reject
   token-less POSTs. This plugin does not take that on trust: both POST surfaces
   (settings save, firmware flash) verify the token themselves, so a change of
   platform behaviour can't silently remove the only check standing in front of
   them. Same fail-safe posture as flash_array_stopped(): an unreadable var.ini
   means refuse, not allow. */

/* Unraid's current CSRF token. Empty string if var.ini is missing/unreadable
   or carries no token. $varini is injectable for tests. */
function lsi_csrf_token(string $varini = '/var/local/emhttp/var.ini'): string {
    if (!is_file($varini)) return '';
    $ini = @parse_ini_file($varini);
    return is_array($ini) ? (string) ($ini['csrf_token'] ?? '') : '';
}

/* True iff the submitted token matches. Pure over its inputs, constant-time
   compare. Fails closed: an absent expected token, or an absent/non-string
   submitted one, is a refusal — never a pass. */
function lsi_csrf_ok(string $expected, $sent): bool {
    if (!is_string($sent) || $expected === '' || $sent === '') return false;
    return hash_equals($expected, $sent);
}
```

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/config.php` → "No syntax errors detected"

### Step 2: Unit-test the helpers

Add to `tests/config_test.php`, immediately before its final summary `echo` /
`exit` lines. Match the file's existing style — it already defines the `check()`
helper used below.

```php
// ── CSRF: token read + constant-time compare, both failing closed ───────────
$vi = sys_get_temp_dir() . '/hbav_varini_csrf_' . getmypid() . '.ini';
file_put_contents($vi, "csrf_token=\"ABC123\"\nmdState=\"STARTED\"\n");
check('csrf token read',        lsi_csrf_token($vi) === 'ABC123');
@unlink($vi);
check('csrf missing file -> empty', lsi_csrf_token($vi) === '');
file_put_contents($vi, "mdState=\"STARTED\"\n");
check('csrf absent key -> empty',   lsi_csrf_token($vi) === '');
@unlink($vi);

check('csrf match',              lsi_csrf_ok('ABC123', 'ABC123') === true);
check('csrf mismatch',           lsi_csrf_ok('ABC123', 'abc123') === false);
check('csrf empty sent',         lsi_csrf_ok('ABC123', '') === false);
check('csrf null sent',          lsi_csrf_ok('ABC123', null) === false);
check('csrf array sent',         lsi_csrf_ok('ABC123', ['x']) === false);
check('csrf no expected -> deny', lsi_csrf_ok('', 'anything') === false);
check('csrf both empty -> deny',  lsi_csrf_ok('', '') === false);
check('csrf prefix not accepted', lsi_csrf_ok('ABC123', 'ABC') === false);
```

`csrf no expected -> deny` is the important one: it pins the fail-closed
decision so a later "helpful" change to allow saving when no token is available
breaks a test instead of quietly opening a hole.

**Verify**: `php tests/config_test.php` → exit 0, all checks pass including the twelve new ones

### Step 3: Send the token from the settings form

In `source/usr/local/emhttp/plugins/hbaviewer/settings.php`, read the token near
the top. Insert after line 6 (`$cfg = lsi_config_read();`):

```php
$csrfToken = lsi_csrf_token();
```

Then replace line 99:

```php
  <form method="post">
```

with:

```php
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
```

**Verify**: `grep -c 'name="csrf_token"' source/usr/local/emhttp/plugins/hbaviewer/settings.php` → prints `1`

### Step 4: Verify the token on settings POST

Replace the opening of the POST branch, `settings.php:38`:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_hbaviewer'])) {
```

with:

```php
$csrfFail = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_hbaviewer'])
    && !lsi_csrf_ok($csrfToken, $_POST['csrf_token'] ?? null)) {
    $csrfFail = true;   // refuse the write; the form still renders below
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_hbaviewer']) && !$csrfFail) {
```

The body of the branch is unchanged.

Then surface the refusal. After the "Settings saved." notice at
`settings.php:95-97`:

```php
  <?php if ($saved): ?>
  <div class="lu-notice">Settings saved.</div>
  <?php endif; ?>
```

add:

```php
  <?php if ($csrfFail): ?>
  <div class="lu-danger"><strong>Not saved.</strong> The security token was missing or expired.
  Reload this page and save again.</div>
  <?php endif; ?>
```

`lu-danger` is an existing class in this file's stylesheet (`settings.php:85`) —
no new CSS needed.

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/settings.php` → "No syntax errors detected"

**Verify**: `grep -c 'lsi_csrf_ok' source/usr/local/emhttp/plugins/hbaviewer/settings.php` → prints `1`

### Step 5: Verify the token on flash POST

In `source/usr/local/emhttp/plugins/hbaviewer/flash.php`, replace the comment
and the lines around it (`flash.php:71-74`):

```php
if ($enable !== 1) { http_response_code(403); echo 'Firmware flashing is disabled.'; exit; }
// CSRF is enforced by Unraid's platform layer (a token-less POST never reaches
// here). The client sends Unraid's csrf_token so that layer passes it through.
@mkdir(FLASH_DIR, 0755, true);
```

with:

```php
if ($enable !== 1) { http_response_code(403); echo 'Firmware flashing is disabled.'; exit; }

// Verify Unraid's CSRF token HERE rather than trusting the platform layer to
// have done it. The client already sends it on every POST (see the flash JS in
// hbaviewer.php); this is the server-side half that was missing. GET actions
// (status, listall) are read-only and unaffected.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !lsi_csrf_ok(lsi_csrf_token(), $_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo 'Invalid or missing security token. Reload the Monitor page and retry.';
    exit;
}

@mkdir(FLASH_DIR, 0755, true);
```

Placement matters: this must sit **after** the `ENABLE_FLASH` gate (so a
disabled plugin still answers with its own message) and **before** `@mkdir` and
every action handler, so no action can run without a valid token.

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/flash.php` → "No syntax errors detected"

**Verify**: the stale comment is gone —
`grep -c 'enforced by Unraid.s platform layer' source/usr/local/emhttp/plugins/hbaviewer/flash.php` → prints `0`

**Verify**: the check precedes every handler —
`grep -n 'lsi_csrf_ok\|if ($action ===' source/usr/local/emhttp/plugins/hbaviewer/flash.php`
→ the `lsi_csrf_ok` line number is lower than every `if ($action ===` line number

### Step 6: Confirm the CLI test guard still holds

`flash.php` returns early under CLI (`flash.php:65`) so tests can require it
without triggering dispatch. Your edit is below that line, but confirm it did
not disturb the guard — `tests/flash_php_test.php` would start executing HTTP
dispatch if it did.

**Verify**: `php tests/flash_php_test.php` → `flash_php: all pass`, exit 0, and **no** HTTP output such as `Firmware flashing is disabled.` appears

### Step 7: Full lint and suite

**Verify**: `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` → exit 0

**Verify**: `bash tests/run_php.sh` → all test files pass, exit 0

**Verify**: `bash tests/run.sh` → `--- all pass ---`, exit 0

### Step 8: Hardware check (do this if an Unraid box is available)

This is the step that resolves the original open question. Record the answer in
your report either way — it is useful information about the platform regardless
of the outcome.

1. Open **User Utilities → HBAviewer**, change the Alert Threshold, click
   **Save Settings First**. It must show "Settings saved." and the new value must
   persist across a reload.
2. Confirm the token is actually in the page: view source and check for
   `<input type="hidden" name="csrf_token" value="..."` with a non-empty value.
3. If `ENABLE_FLASH` is on, open the Monitor's Firmware/BIOS tab and click
   **Verify /c0**. It must return the controller listing, not a 403.
4. Note in your report whether settings saving worked **before** this change on
   this Unraid version. That is the direct answer to "was the platform enforcing
   CSRF or not," and it is worth writing down.

**If saving now fails with the token error**, the token being sent does not match
what `var.ini` holds — see STOP conditions.

## Test plan

**New tests** — twelve checks appended to `tests/config_test.php`, following the
structure of the `flash_array_stopped` block in `tests/flash_php_test.php:25-32`
(write a temp ini, assert, clean up):

| Group | Checks | Covers |
|---|---|---|
| `lsi_csrf_token` | 3 | reads the token; missing file → empty; absent key → empty |
| `lsi_csrf_ok` — accept | 1 | exact match passes |
| `lsi_csrf_ok` — refuse | 8 | case mismatch, empty sent, `null`, array, no expected token, both empty, prefix-only |

The two that matter most for future maintenance are `csrf no expected -> deny`
(pins the fail-closed decision) and `csrf prefix not accepted` (pins that this is
a full comparison, not a prefix test).

**Not automatically tested**: the two dispatch integrations. `settings.php` is a
template with no test harness, and `flash.php`'s dispatch returns early under
CLI by design. Both integrations are a handful of straight-line statements whose
correctness a reviewer can read directly; the logic they call is unit-tested.
Step 8 is the end-to-end check.

**Verification**: `bash tests/run.sh` → `--- all pass ---`.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'function lsi_csrf_token\|function lsi_csrf_ok' source/usr/local/emhttp/plugins/hbaviewer/config.php` prints `2`
- [ ] `grep -c 'hash_equals' source/usr/local/emhttp/plugins/hbaviewer/config.php` prints `1`
- [ ] `grep -c 'name="csrf_token"' source/usr/local/emhttp/plugins/hbaviewer/settings.php` prints `1`
- [ ] `grep -c 'lsi_csrf_ok' source/usr/local/emhttp/plugins/hbaviewer/settings.php` prints `1`
- [ ] `grep -c 'lsi_csrf_ok' source/usr/local/emhttp/plugins/hbaviewer/flash.php` prints `1`
- [ ] `grep -c 'enforced by Unraid.s platform layer' source/usr/local/emhttp/plugins/hbaviewer/flash.php` prints `0`
- [ ] In `flash.php`, the `lsi_csrf_ok` line number is lower than every `if ($action ===` line number
- [ ] `php tests/config_test.php` exits 0 with the twelve new checks passing
- [ ] `php tests/flash_php_test.php` exits 0 and emits no HTTP-dispatch output
- [ ] `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` exits 0
- [ ] `bash tests/run.sh` exits 0 and prints `--- all pass ---`
- [ ] `git status --porcelain` shows exactly four modified files: `config.php`, `settings.php`, `flash.php`, `tests/config_test.php` (plus `plans/README.md`)
- [ ] `plans/README.md` status row for 009 updated

## STOP conditions

Stop and report back (do not improvise) if:

- **Step 8 shows settings can no longer be saved on real hardware.** This is the
  one genuinely risky outcome: a fail-closed check on the settings page locks the
  user out of their own configuration. Do not "fix" it by weakening
  `lsi_csrf_ok` to allow an empty expected token — that reopens the hole and
  breaks a test that exists to prevent exactly that. Report what `var.ini`
  contains for `csrf_token` (**the key name and whether it is present — never the
  value**) and what the form submitted, and let a human decide.
- The flash tab's Verify button starts returning 403 after this change. The
  client is sending a token the server rejects; report the mismatch rather than
  removing the check.
- `php tests/flash_php_test.php` prints HTTP output such as
  `Firmware flashing is disabled.` — the CLI guard at `flash.php:65` has been
  disturbed and the test file is now executing dispatch.
- `tests/config_test.php` had failures before your change. Establish the baseline
  first.
- You find yourself adding CSRF checks to `ajax_info.php`. Those are read-only
  GET endpoints and adding a token requirement will break the Monitor's polling.

**Never write a token value into a commit, a comment, a test fixture, or your
report.** Use a placeholder such as `ABC123` in tests, as this plan does.

## Maintenance notes

- **Fail-closed is a deliberate decision with a real cost.** If `var.ini` is
  unreadable or carries no `csrf_token`, settings cannot be saved and flashing
  cannot start. On the supported platform (Unraid 6.12+, per `README.md`) the
  token is always present, so the trade is sound — but if a future Unraid removes
  or renames the key, both endpoints stop working and the symptom ("settings
  won't save") will not obviously point at CSRF. `lsi_csrf_token` is the single
  place to adjust.
- **The plugin no longer trusts the platform layer for this.** If Unraid's own
  enforcement is confirmed by someone with hardware, these checks become
  redundant — keep them anyway. Defence in depth on the endpoint that flashes
  firmware is cheap.
- **Both endpoints now share one helper in `config.php`.** A third POST surface
  must use it too; that is now the established pattern.
- **`ajax_info.php` is deliberately unprotected** because it is read-only. If a
  state-changing action is ever added there, it needs `lsi_csrf_ok` before it
  ships.
- **What a reviewer should scrutinise**: the placement of the check in
  `flash.php` — it must be before `@mkdir` and before every `if ($action === ...)`
  block, or an action could still run unauthenticated. And in `settings.php`,
  that a failed check leaves `$saved` false and renders the error, rather than
  falling through into `lsi_config_write`.
