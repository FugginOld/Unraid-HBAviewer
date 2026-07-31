# Plan 024: "Locate" button — blink a drive's enclosure LED via SES

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 8286fe7..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/get_attached_drives.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_enclosures.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_drives.sh source/usr/local/emhttp/plugins/hbaviewer/flash.php`
> Expected output: **nothing**. Every excerpt below is quoted from `8286fe7`
> (`dev` tip, 2026-07-30). Any difference is a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: LOW-MEDIUM — writes to enclosure hardware (an LED), not to the
  HBA or a drive itself; scoped away from direct-attach where it can't work
- **Depends on**: none
- **Category**: feature
- **Planned at**: `8286fe7`, 2026-07-31
- **Requested by**: external roadmap review

## How much of the fleet can actually use this — check before building

This is a new feature, not a fix for any open issue. The note below is about
**addressable hardware**, because it changes how the feature should behave on
boxes that cannot support it.

Locate depends on a real SES enclosure with real slots and physical LEDs behind
it. The only real-world controller output this project has collected happens to
come from issues #5 and #6, and it suggests a meaningful share of users have no
such enclosure:

- Issue #6's SAS3416 reports a `VirtualSES` enclosure with **0 drives attached**
  and a blank `EID` column — its 15 drives are addressed `/c0/s0…` with no
  enclosure association at all.
- Issue #5's two reporters (SAS3224, IT and IR firmware) show the same blank-EID
  signature.
- The maintainer's own 9400-16i **does** have a populated `VirtualSES`, so this
  splits even across similar cards.

A `VirtualSES` synthesised by the HBA for direct-attached drives generally has no
physical LEDs behind it — there is no backplane to blink. So on those boxes the
button either does nothing or must not appear.

That does not sink the plan; a real expander backplane is exactly where locate
earns its keep. But it does two things to the scope: the feature must **detect
and hide itself** where it cannot work (the plan's existing
"visible-but-disabled" note is the right instinct — prefer that over a button
that silently fails), and the acceptance test needs a box with a genuine
backplane, which may not be the maintainer's.

## Why this matters

The Attached Drives tab already tells you a slot, an enclosure, a SAS
address — everything except which physical bay that actually is. Finding
the drive a PHY error or SMART warning points at means cross-referencing a
slot number against a case label, or worse, pulling drives one at a time.
A "locate" button that blinks the drive's fault/ident LED closes that gap
directly, and it's the single most-requested class of feature for tools
like this because it's the last step between "the software knows" and "the
human can act."

## Current state

### `scripts/parse/storcli_enclosures.sh` — the gate this feature needs

```awk
function emit(){
    ...
    direct = (product ~ /VirtualSES/) ? "true" : "false"
    printf "{\"eid\":\"%s\",...,\"direct\":%s}", eid, ..., direct
}
```

The parser **already distinguishes** a direct-attach "virtual" enclosure
(the HBA's own synthetic enclosure for drives with no real backplane) from
a genuine SES expander/backplane enclosure, via the `direct` field. This is
exactly the signal locate needs: **SES locate only works on a real
enclosure with an addressable SES device** (`/dev/sesN` on Linux). A
direct-attached drive has no enclosure to blink through, and the plugin
already knows which is which — no new detection work required for the
gate itself.

### `scripts/get_attached_drives.sh` — where a locate call would be composed

storcli path merges enclosure + drive data per controller
(`drv_storcli()`); lsiutil path builds an OS-device ↔ SAS-address join via
sysfs (`drv_lsiutil()`, using `/sys/class/sas_end_device/`). A locate
action needs, per drive: which enclosure (`eid`), which slot within it,
and confirmation that enclosure's `direct` flag is `false`.

### `flash.php` — the pattern for a "the plugin writes to hardware" endpoint

This plugin has exactly one prior mutating surface, and its shape is the
template to follow:

```php
/* Array must be STOPPED before flashing. A missing/unreadable var.ini or any
   non-STOPPED state fails safe -> block. */
function flash_array_stopped(string $varini = FLASH_VARINI): bool { ... }
```

Locate is much lower-stakes than flashing (toggling an LED can't corrupt
anything), so it does **not** need an array-stopped gate or a typed
confirmation string — but it should still live in its own small,
guard-function-first file the way `flash.php` does, rather than growing
inside `ajax_info.php`'s read-only surface.

## Scope

**In scope**:

- SES enclosure detection: map a controller+enclosure(`eid`) to a real
  `/dev/sesN` device. `lsutil`/`storcli` don't expose this directly — this
  needs either `sg_ses --index` enumeration matched against the
  enclosure's vendor/product string already parsed, or matching via
  `/sys/class/enclosure/*/` (Linux's native SES class, present when
  `ses` kernel module is loaded) cross-referenced by the enclosure's slot
  count. **Confirm which sysfs/sg_ses surface is actually reachable on a
  real Unraid box before committing to one** — this is the one piece of
  this plan with real hardware-dependent uncertainty.
- A new `locate.php` (or a scoped addition — decide based on how small it
  ends up) with a pure preflight (`locate_preflight`: valid controller,
  valid enclosure not flagged `direct`, valid slot) and the actual
  `sg_ses --set=ident` (or `--set=fault`, decide which LED — ident/locate
  is the conventional "find me" LED, fault implies a problem state and
  shouldn't be lit by a manual locate) call
- Auto-off timeout (e.g. 10 minutes) so a locate doesn't stay lit forever
  if the user navigates away — implement as a detached `sleep N &&
  sg_ses --clear=ident` background job, mirroring how `flash.php`
  launches its own detached job today (check that mechanism before
  reinventing it)
- A "Locate" button per drive row on the Attached Drives tab, disabled
  (not hidden — visible-but-disabled communicates "not available here"
  better than absence) when the drive's enclosure is `direct:true`

**Out of scope**:

- Any LED control beyond ident/locate (no fault-LED setting — that should
  reflect real fault state, not be user-toggleable)
- SATA drives behind a non-SES backplane that doesn't support per-slot
  addressing (some consumer backplanes have no addressable LEDs at all —
  detect absence and disable, don't error)
- Any change to the enclosure/drive parsers' existing JSON contract beyond
  what's needed to carry the `/dev/sesN` mapping through to the button

## Steps

### Step 1: Confirm the SES device mapping path (hardware-dependent — do this first)

Before writing the endpoint, confirm on a real box (or via `sg_ses`/sysfs
documentation for the kernel version Unraid ships) how to go from
`(controller, eid)` — what the plugin already has — to a `/dev/sesN` path.
Candidates, in likely order of reliability on Unraid's kernel:

- `/sys/class/enclosure/*/` — each subdirectory usually links back to the
  originating SCSI host/target, which can be cross-referenced against the
  controller's host number the same way `get_phy_health.sh`'s sysfs walk
  already does for PHYs.
- `sg_ses --list` output correlated by vendor/product string (already
  parsed) — messier, fallback if sysfs doesn't give a clean host link.

**This determines everything downstream.** If neither path reliably maps
on the hardware available, stop and report rather than guessing — a
locate feature that blinks the *wrong* drive's LED is actively harmful.

### Step 2: `locate.php` — pure preflight

```php
function locate_preflight(array $enclosure, int $slot): array {
    if (!empty($enclosure['direct']))
        return ['ok' => false, 'error' => 'This drive has no addressable enclosure (direct-attached).'];
    if ($slot < 0)
        return ['ok' => false, 'error' => 'Invalid slot.'];
    // ... ses device resolved in Step 1 must be present too
    return ['ok' => true];
}
```

Unit-test the direct-attach rejection explicitly — it's the one guard this
whole feature depends on to not attempt something impossible.

### Step 3: The locate/clear calls and the auto-off job

```bash
sg_ses --index=<slot> --set=ident /dev/sesN
# schedule the auto-off the same way flash.php's detached job works —
# check its launcher before writing a second, inconsistent mechanism
( sleep 600; sg_ses --index=<slot> --clear=ident /dev/sesN ) &
```

Also add a manual "stop locating" action for the obvious case (found the
drive, don't want to wait 10 minutes for the LED to give up).

### Step 4: UI — button on the Attached Drives tab

Disabled state when `direct:true`, with a tooltip/title explaining why
(reuses the exact reasoning already sitting in the `direct` field's
comment in `storcli_enclosures.sh`).

## Test plan

- `locate_preflight()` — pure, unit-test the direct-attach rejection,
  invalid-slot rejection, and the happy path.
- Step 1's SES-device resolution, once confirmed, should also be a pure
  function over parsed enclosure data + an injectable sysfs root (same
  `SYS_SCSI_HOST`-style override pattern plan 010 established), so it can
  be fixture-tested without real hardware.
- The actual `sg_ses` call and the auto-off timer are not unit-testable —
  hardware verification item, same posture as `flash.php`'s own guards
  (pure logic tested; the dangerous call itself verified on real hardware).

## Done criteria

- [ ] Step 1's SES mapping approach confirmed against real hardware or
      authoritative kernel documentation, not assumed
- [ ] `locate_preflight()` rejects direct-attach and invalid slots, unit-tested
- [ ] Locate button disabled (with explanation) on direct-attach drives,
      enabled on real-enclosure drives
- [ ] Auto-off job mirrors `flash.php`'s existing detached-job mechanism
      rather than introducing a second one
- [ ] `bash -n` / `php -l` clean on every touched file
- [ ] `bash tests/run.sh` → `--- all pass ---`, new preflight cases added

## STOP conditions

- The drift check prints anything.
- Step 1 cannot confirm a reliable controller/enclosure → `/dev/sesN`
  mapping on available hardware or documentation. Report the gap; do not
  ship a guess.
- The locate button is reachable (even if it then errors) on a
  `direct:true` drive — the gate must be enforced before any `sg_ses` call
  is attempted, not just in the UI.
- `sg_ses` is invoked with anything other than `--set=ident`/`--clear=ident`
  — fault-LED control is explicitly out of scope.

## Maintenance notes

- **The `direct` flag already existing in `storcli_enclosures.sh` is what
  makes this plan tractable at all** — it means the hard "is this even
  possible for this drive" question was already answered by plan history,
  not something this plan has to solve from scratch.
- **This plugin has one prior mutating surface (`flash.php`) and its
  guard-functions-first, detached-job pattern is the house style.** A
  reviewer should check that this plan followed it rather than inventing
  a second convention for "the plugin writes to hardware."
