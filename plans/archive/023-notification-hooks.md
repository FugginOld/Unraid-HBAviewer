# Plan 023: Notify through Unraid's `notify` on health status transitions

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat cc5a66d..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/hba.sh source/usr/local/emhttp/plugins/hbaviewer/view.php source/usr/local/emhttp/plugins/hbaviewer/settings.php source/usr/local/emhttp/plugins/hbaviewer/config.php hbaviewer.plg`
> Expected output: **nothing**. Every excerpt below is quoted from `cc5a66d`
> (`dev` tip, 2026-08-01). Any difference is a STOP condition.
>
> **Baseline re-stamped 2026-08-01.** This plan was written against `8286fe7`.
> Since then plans 021 and 030 changed `view.php` (+150/-43) and `settings.php`
> (+31), so the original drift check no longer passed. Both cited regions were
> re-read and are **unchanged in substance**: `lsi_hba_view()` still opens
> `$status = $data['status'] ?? 'ok';` (now at `view.php:193`), and
> `settings.php:174` still carries plan 001's "HBAviewer does not send
> notifications" sentence that this plan must update. `hba.sh` and `config.php`
> were never touched. The drift was entirely in the temperature-gradient and
> theme-token work, which this plan does not go near.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MEDIUM — this plan's cron piece is new territory for the
  plugin (see "Why this is bigger than it looks")
- **Depends on**: none — deliberately built on the `status` field that
  already exists, not on plan 020's unfinished rollup
- **Category**: feature
- **Planned at**: `8286fe7`, 2026-07-31
- **Requested by**: external roadmap review, converting
  `plans/README.md`'s own "Direction 1: Real alerting" note into a plan

## Why this matters

`plans/README.md` flags this as wanted under **Direction 1, "Real alerting"**,
which already sketches the shape:

> The plugin already computes a health rollup covering failed drives, PHY error
> counters and pre-P20 firmware, and can currently tell nobody about any of it.
> A cron script calling `/usr/local/emhttp/webGui/scripts/notify` on the existing
> `status` field would turn a dashboard into a monitor. Needs last-notified state
> to avoid spamming.

The plugin computes a health status and can currently tell nobody about it
unless someone has the page open. Someone whose HBA crosses into `alert`
overnight finds out next time they happen to look, which defeats the point of
having a status at all.

**One interaction to handle deliberately.** Plan 001 removed a Settings-page
claim that the plugin sends notifications, because it did not — the page now
reads "HBAviewer does not send notifications", and the index records that this
was confirmed by sweeping the repo for `notify` call sites and finding zero.
Shipping this plan means changing that sentence back. It must not be changed
back until the notification path is proven end to end on hardware: a page that
promises alerts it does not deliver is the exact defect 001 existed to fix.

## Why this is bigger than it looks

Everything else in this plugin runs **on page load** — a browser hits
`ajax_info.php`, a script shells out, JSON comes back, the page renders.
There is no cron, no daemon, no background process (`hbaviewer.plg` has no
cron install block — grep it, there's nothing there). A notification that
should fire "as soon as the card goes critical, whether or not anyone has
the tab open" needs *something* to run periodically, and this plugin has
never needed that before.

This plan therefore has two independent pieces:

1. The **status-transition + notify logic** (small, pure, testable).
2. **Getting that logic to run periodically without a browser tab open**
   (the actually new part — a cron entry via `.plg`, which is unfamiliar
   territory for this codebase and should be reviewed with extra care).

If the maintainer would rather ship (1) as an on-page-load check first
(fires when someone happens to load the dashboard, same limitation as
today but at least *notifies* instead of silently sitting there) and defer
(2) to a follow-up, split this plan at that seam — see Scope.

## Current state

### `scripts/parse/hba.sh` — where `status` comes from today

```bash
CFG_BAND=$(band_of "$ALERT")
if [ -n "$TEMP" ]; then
    TEMP_BAND=$(band_of "$TEMP")
    ti=$(band_index "$TEMP_BAND"); ci=$(band_index "$CFG_BAND")
    if   [ "$ti" -gt "$ci" ]; then STATUS="alert"
    elif [ "$ti" -eq "$ci" ]; then STATUS="warn"
    else STATUS="ok"; fi
    TEMPJSON="$TEMP"
else
    STATUS="ok"; TEMPJSON='""'; TEMP_BAND=""
fi
```

`status` is `ok` / `warn` / `alert`, computed per-controller from the
temperature band relative to the configured alert band. **Confirm before
Step 1** whether any other signal (PHY error floor, drive failure) also
feeds `STATUS` elsewhere — `storcli_overview.sh` emits the same three-value
field independently and should be checked for parity. `plans/README.md`'s
own wording ("failed drives, PHY error counters and pre-P20 firmware")
describes a broader rollup than this single excerpt shows; resolve that
discrepancy by reading both parser files fully before assuming scope.

### `view.php` — where it's consumed for rendering

```php
function lsi_hba_view(array $data, int $port, int $idx = 0): array {
    $status = $data['status'] ?? 'ok';
    // ... 'status' => $status, 'color' => lsi_status_color($status), ...
```

Purely a render mapping — no state, no history, nothing to compare against
a previous poll. That comparison is what this plan adds.

### `config.php` — the persistence pattern to follow (see plan 022 also)

Same `LSI_CFG` KEY=value schema-clamped file pattern. This plan needs a
settings toggle (notifications on/off) which belongs in `LSI_SCHEMA`
directly, and a small last-notified-state store, which — like plan 022's
PHY baseline — is a new small JSON file under
`/boot/config/plugins/hbaviewer/`. **If plan 022 has already shipped when
this plan is picked up, reuse its storage module rather than writing a
third copy of the same read/write pattern.**

**Status of plan 022 as of 2026-08-01: executed on branch
`advisor/022-phy-error-baseline`, NOT merged to `dev`** and awaiting a hardware
check. It created `phy_baseline.php`, whose read/write half is a clean JSON
store in exactly the directory this plan needs.

Do **not** branch from 022 or import its file — an unmerged, unverified branch
is not a dependency worth taking, and if it changes after a hardware test you
inherit the churn. Write this plan's own small store, but keep it the *same
shape* (`*_read(?string $path = null)` / `*_write(array $d, ?string $path = null)`,
a `const` default path, path injectable so tests never touch `/boot`) so the two
can be folded into one module later without a rewrite. If 022 has merged by the
time you start, read `phy_baseline.php` and match it exactly.

## Scope

**In scope — piece 1 (transition + notify logic)**:

- New `LSI_SCHEMA` key, e.g. `ENABLE_NOTIFY => [0, 0, 1]` (default off,
  matching `ENABLE_FLASH`'s opt-in posture), plus a Settings row for it
  (`settings.php`, same checkbox pattern as `show_phy`/`enable_flash`)
- Last-notified-state store: one row per controller identity (use
  `board_name`+`port`/`ctrl-index` — decide a stable key in Step 1, since
  controller order can shift), storing the last status notified and when
- A pure `notify_transitions(array $previous, array $current): array`
  function returning the list of transitions that should fire — testable
  without touching the real `notify` binary
- Calling Unraid's notify script
  (`/usr/local/emhttp/webGui/scripts/notify -e "HBAviewer" -s "<subject>"
  -d "<description>" -i "<normal|warning|alert>"`) once per transition,
  with `ok`→`warn`→`alert` mapped to increasing importance

**In scope — piece 2 (the runner)**:

- A cron entry via `hbaviewer.plg`'s install block (new — nothing like
  this exists in the `.plg` today) running a small headless script on an
  interval (5–15 min is a reasonable default; make it configurable if
  cheap, hardcoded is fine if not)
- That script calls the same read path the Overview already uses (via the
  existing bash composers directly, not through PHP/HTTP) and feeds the
  result into piece 1's transition check

**Out of scope**:

- Any change to what `STATUS` means or how it's computed (that's plan
  018/020 territory)
- Any notification channel other than Unraid's own `notify` (no email/
  Discord/webhook direct integration — `notify` already fans out to
  whatever agents the user has configured in Unraid's own Notification
  Settings, which is the correct integration point)
- Flap suppression / hysteresis beyond the trivial "only notify once per
  transition, not once per poll" — plan 020 owns the more sophisticated
  dwell/deadband logic; this plan's job is "don't spam," not "be smart"

## Steps

### Step 1: Decide the controller identity key and confirm the notify script path

Controller order in the JSON array isn't guaranteed stable across reboots
if hardware is added/removed. Pick a stable identity —
`board_name` is the best candidate already present in the payload (see
`view.php`'s `lsi_hba_view`), falling back to array index if `board_name`
is empty (some cards report it blank).

Confirm `/usr/local/emhttp/webGui/scripts/notify`'s exact flag set against
the currently-targeted Unraid version — this is a standard, documented
Unraid script but its flags have had minor changes across major versions;
verify against 7.2+ (this plugin's stated floor) rather than assuming.

### Step 2: `notify_transitions()` — pure logic, no I/O

```php
// $previous, $current: [controller_key => status] maps ('ok'|'warn'|'alert')
// Returns list of ['controller'=>key,'from'=>status,'to'=>status] for every
// controller whose status changed since the last check. A controller absent
// from $previous (first run, or newly added hardware) is NOT a transition —
// don't fire a notification for "a card was found," only for a status change.
function notify_transitions(array $previous, array $current): array {
    $out = [];
    foreach ($current as $key => $status) {
        if (isset($previous[$key]) && $previous[$key] !== $status) {
            $out[] = ['controller' => $key, 'from' => $previous[$key], 'to' => $status];
        }
    }
    return $out;
}
```

**Verify** with a direct unit test: no-previous-entry case emits nothing;
same-status case emits nothing; `ok`→`warn`→`alert`→`ok` sequence emits
exactly the three transitions, in order.

### Step 3: Wire the store, the settings toggle, and the notify call

- Add `ENABLE_NOTIFY` to `LSI_SCHEMA` in `config.php`, following the exact
  pattern of `ENABLE_FLASH`.
- Add the Settings row in `settings.php`, matching `show_phy`'s checkbox
  markup and the POST handler's existing `isset($_POST['...']) ? 1 : 0`
  idiom.
- New small store (or reuse plan 022's, per "Relationship" note above) for
  `{controller_key: {status, notified_at}}`.
- A thin orchestration function: read current statuses from the overview
  payload → read last-notified store → `notify_transitions()` → for each
  transition, shell out to `notify` → write the new store state.

### Step 4: The cron runner (piece 2)

This is the part with no existing precedent in the repo — treat it with
the caution plan 022's "Relationship to plan 020" section asks for on
persistence. Concretely:

- A small standalone PHP or bash entrypoint (not served over HTTP — this
  runs from cron, so it should look more like `scripts/capture.sh` in
  invocation style than like `ajax_info.php`) that: reads config, exits
  immediately if `ENABLE_NOTIFY` is off, otherwise runs the same composer
  chain the Overview uses and calls Step 3's orchestration function.
- Install/remove the cron entry from `hbaviewer.plg`'s existing install
  (`Run="/bin/bash"` block around line 86) and remove (`Method="remove"`
  block around line 109) sections — **do not hardcode a crontab edit
  outside those hooks**, or the entry survives plugin removal.
- Respect `ENABLE_NOTIFY` at the cron-entry level too (either don't
  register the cron job until the toggle is on, or have the entrypoint
  no-op instantly when it's off) — a disabled feature should cost
  ~nothing per invocation, not silently poll hardware every 10 minutes
  for no reason.

## Test plan

- `notify_transitions()` is pure — cover no-previous / same-status /
  multi-step sequence / controller-removed-then-readded as direct unit
  tests, no fixtures needed.
- The store read/write follows plan 022's precedent (or is genuinely new —
  either way, temp-path-injectable, tested the same way `config.php`'s
  functions are).
- The actual `notify` call is the one piece that can't be unit-tested
  meaningfully without a live Unraid box — stub/mock the shell-out in
  tests and treat the real call as a hardware-verification item, same
  posture as plan 019's layout work.
- `bash tests/run.sh` stays green; new cases are additive.

## Done criteria

- [ ] `notify_transitions()` unit tests cover all four cases above
- [ ] `ENABLE_NOTIFY` toggle present in `LSI_SCHEMA`, Settings page, and
      defaults to `0`
- [ ] Store round-trips through a temp path
- [ ] Cron entry installs via `hbaviewer.plg`'s install block and is
      removed via its remove block — verified by reading both blocks after
      the edit, not just the install side
- [ ] Disabled-toggle path costs no hardware read (confirm by tracing the
      entrypoint's early-exit)
- [ ] `php -l` / `bash -n` clean on every touched file
- [ ] `bash tests/run.sh` → `--- all pass ---`

## STOP conditions

- The drift check prints anything.
- You find yourself building flap suppression, dwell counts, or a deadband
  — that's plan 020's job; this plan's "don't spam" bar is only
  "one notification per transition."
- The cron entry has no corresponding removal in the `.plg`'s
  `Method="remove"` block — an orphaned cron job that survives plugin
  uninstall is a real bug, not a nitpick.
- A controller newly appearing in the current-status map fires a
  notification — see Step 2's comment; that's a false "transition," not
  a real one.

## Maintenance notes

- **This plan and plan 022 both want a small persisted-JSON-under-`/boot`
  pattern.** If both ship, look at whether they should share one storage
  module rather than three near-identical read/write pairs (the third
  being plan 020's eventual history store).
- **The cron piece is the plugin's first background process.** Whoever
  reviews this should scrutinize the `.plg` diff more than the PHP —
  install-hook mistakes are the kind of thing that only surfaces on a
  clean install/uninstall cycle, which the existing test suite cannot
  exercise at all.
