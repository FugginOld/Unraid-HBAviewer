# Plan 024 (v2): "Locate" button — blink a drive's slot LED through sysfs

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 2e0f1fb..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php source/usr/local/emhttp/plugins/hbaviewer/flash.php source/usr/local/emhttp/plugins/hbaviewer/phy_baseline.php source/usr/local/emhttp/plugins/hbaviewer/cached_read.php source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_drives.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh tests/run_php.sh`
> Expected output: **nothing**. Every excerpt below is quoted from `2e0f1fb`
> (`dev` tip, 2026-08-02). Any difference is a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MEDIUM. Writes to hardware (an LED) as root, driven by a value
  that arrives over HTTP. The write itself is harmless; **choosing the wrong
  target is not** — a locate that blinks the wrong bay is worse than no
  locate, because the user pulls a live drive. The path-construction rule in
  Step 3 is the control, and it is not negotiable.
- **Depends on**: none
- **Category**: feature
- **Planned at**: `2e0f1fb`, 2026-08-02 (**v2** — see History)
- **Requested by**: external roadmap review

## History — what v1 got wrong, and why this is a rewrite not a refresh

v1 was written 2026-07-31 against `8286fe7` without hardware evidence. A probe
on the maintainer's box on 2026-08-02 falsified three of its load-bearing
assumptions. Recorded so nobody re-derives them:

| v1 said | Hardware says |
|---|---|
| Call `sg_ses --set=ident /dev/sesN` | The kernel's enclosure class exposes a **writable `locate`** per slot (`drivers/misc/enclosure.c:650`, `DEVICE_ATTR(locate, S_IRUGO \| S_IWUSR, …)`). `echo 1 >` does it with no dependency, and `echo 0` is the auto-off. `sg_ses` *is* installed on Unraid, but reaching for it here would be a binary and an argument string where a file write suffices |
| Gate on `direct:true` — "a `VirtualSES` has no physical LEDs behind it" | **False on real hardware.** The maintainer's box reports `Product Identification = VirtualSES` on both controllers, and the kernel still registered **48 and 40 components** with real slot numbers, `device/block/sdX` links and writable `locate` on every one. v1's gate would have disabled the feature on hardware where it works |
| Mapping `(controller, eid) → /dev/sesN` is "the one piece with real hardware-dependent uncertainty"; may not be resolvable | Resolved. The enclosure's sysfs symlink contains the controller's PCI address, and component `slot` numbers match storcli's `Slt` exactly. Evidence below |
| "The acceptance test needs a box with a genuine backplane, which may not be the maintainer's" | The maintainer's box has 24 addressable slots across two enclosures. It is testable there |

**Do not restore any of the above.** If a future card genuinely has no LEDs,
the capability check in Step 2 catches it by construction — because it asks
the kernel whether a writable `locate` exists rather than inferring it from a
product string.

## Hardware evidence — captured 2026-08-02, this is the fixture basis

Verbatim from the maintainer's box (9400-16i + 9400-8i). **Every fixture this
plan creates must be built from these shapes**, not invented — plan 038 found
`tests/fixtures/hba_ioc.txt` describing a PCIe Gen5 link on a 2012 SAS2 card
because someone reverse-engineered a fixture from an assumption.

```
$ ls -l /sys/class/enclosure/
0:0:0:0 -> ../../devices/pci0000:c0/0000:c0:01.1/0000:c1:00.0/host0/port-0:0/end_device-0:0/target0:0:0/0:0:0:0/enclosure/0:0:0:0/
1:0:8:0 -> ../../devices/pci0000:40/0000:40:03.1/0000:65:00.0/host1/port-1:8/end_device-1:8/target1:0:8/1:0:8:0/enclosure/1:0:8:0/

$ cat /sys/class/enclosure/0:0:0:0/components   →  48      (only 16 populated)
$ cat /sys/class/enclosure/1:0:8:0/components   →  40      (only 8 populated)

per component:  slot   locate   device/block/<name>
  0:0:0:0  slot=12 locate=0 blk=sda      slot=8  locate=0 blk=sdn
           slot=5  locate=0 blk=sdh      slot=1  locate=0 blk=sdi
           slot=0  locate=0 blk=sdg      … 16 populated, 0–15
  1:0:8:0  slot=4  locate=0 blk=sdr      slot=0  locate=0 blk=sdp
           slot=5  locate=0 blk=sds      … 8 populated, 0–7
  (unpopulated bays exist and have locate too, with no device/block)
```

Two joins fall out, and both were checked against storcli on the same box:

1. **Enclosure → controller: the PCI address in the resolved symlink.**
   `0000:c1:00.0` is controller 0, `0000:65:00.0` is controller 1 — and those
   are exactly what `get_hba_info.sh` already builds from storcli's
   `PCI Address = 00:c1:00:00`.
2. **Component → drive: the slot number, unchanged.** sysfs `slot` 0–15 on c0
   and 0–7 on c1 line up one-for-one with storcli's `EID:Slt` rows
   (`0:0 … 0:15`, `0:0 … 0:7`). No translation table.

Note what is *not* used: the SCSI `H:C:T:L` in the directory name, and
storcli's `DID`. Spot-checking showed `DID` and the SCSI target id agreeing on
c0 and disagreeing on c1, so **neither is a join key**. Do not use them.

## Why this matters

The Drives tab already shows a slot, an enclosure and a SAS address —
everything except which physical bay that is. Acting on a PHY error or a SMART
warning today means cross-referencing a slot number against a case label, or
pulling drives one at a time. This is the last step between "the software
knows" and "the human can act", and the kernel has been offering it the whole
time.

## Current state

### `ajax_info.php:16` — the include pattern this plan follows

```php
// Read path only. phy_baseline.php's own dispatch fires solely on a POST
require_once __DIR__ . '/phy_baseline.php';
```

`phy_baseline.php` is the house pattern for "a mutating endpoint whose pure
helpers are also needed by the read-only renderer": pure functions at the top
over injected paths, a POST-only dispatch at the bottom, and `ajax_info.php`
includes it purely for the helpers. **`locate.php` must have the same shape.**

### `ajax_info.php:419-470` — where the button goes

```php
function renderDrivesTables(array $data): string {
    $ctls    = $data['controllers'] ?? [$data];
    $storcli = ($data['backend'] ?? '') === 'storcli';
    …
        if ($storcli || (($data['backend'] ?? '') === '' && isset($drives[0]['slot']))) {
            $rows = [];
            foreach ($drives as $d) {
                $serial = $d['serial'] ?? '';
                $smart  = $serial !== ''
                    ? '<button class="lu-refresh-btn" onclick="luSmart(this,\'' . htmlspecialchars($serial, ENT_QUOTES) . '\')">SMART</button>'
                    : '<span class="lu-muted">—</span>';
                $rows[] = [
                    htmlspecialchars($d['slot']),
                    …
                    $smart,
                ];
```

The SMART button is the exact precedent: one button per row, built in the row
loop, disabled-equivalent (`—`) when the row lacks what it needs. Locate is the
same shape with a different guard.

### `scripts/parse/storcli_drives.sh:12` — the `slot` field's format

```awk
(eid == "" ? slot : eid"/"slot), port, model, sn, state, wwn, size, link, fw
```

So `slot` reaches PHP as either `"12"` (enclosure-less controller, plan 017)
or `"0/12"` (`eid/slot`). **Both forms must be handled**: split on `/`, take
the last field as the slot number. An enclosure-less drive has no enclosure to
blink through and must resolve to "not available".

### `flash.php:164` — the detached-job launcher to mirror, not reinvent

```php
shell_exec('nohup sh -c ' . escapeshellarg($inner) . ' >/dev/null 2>&1 &');
```

### `cached_read.php:14` — how to get the controller's PCI address

```php
function cached_read(string $key, int $ttl, string $producer, array $opts = []): array {
```

The Drives tab's own JSON has no PCI address; the **overview** JSON carries it
as `pci_location` (`"00:c1:00:00"`). Read it through `cached_read` rather than
shelling out again — the overview is already cached for 60s.

## Scope

**In scope**:

- New `source/usr/local/emhttp/plugins/hbaviewer/locate.php` — pure resolver +
  preflight + POST dispatch (`locate` / `clear`), mirroring `phy_baseline.php`.
- `ajax_info.php` — `require_once` it, and one Locate button per drive row in
  `renderDrivesTables`'s storcli branch.
- `hbaviewer.php` — the JS `luLocate()` / `luLocateStop()` fetch calls and any
  button CSS, alongside the existing `luSmart()`.
- New `tests/locate_test.php`, registered in `tests/run_php.sh`.

**Out of scope — do not touch**:

- `sg_ses`, `sg3_utils`, or any external binary. The mechanism is a file write.
- The `fault`, `active`, `status` or `power_status` attributes. **`locate`
  only.** `fault` must reflect real fault state, not a user toggle.
- `parse/storcli_enclosures.sh` and the `direct` field. v1 wanted to gate on
  it; v2 does not use it at all. Leave both alone.
- The lsiutil backend's drive rows. The same sysfs walk *would* work there by
  joining on `device/block/sdX` (which that backend already has and storcli
  does not), but there is no SAS2-with-backplane hardware to verify it on.
  Leave the button off that branch and say so in the report.
- `flash.php`, `phy_baseline.php`, `health.php` and every parser.

## Steps

### Step 1: the pure resolver

In `locate.php`, over an **injectable** sysfs root (`?string $root = null`
defaulting to `/sys/class/enclosure`, exactly like `phy_baseline.php`'s
`?string $path = null`):

```php
/* Enumerate every enclosure component the kernel exposes:
     [ '0000:c1:00.0' => [ 12 => '/sys/class/enclosure/0:0:0:0/7', … ], … ]
   Keyed by the controller's PCI address, then by SES slot number, with the
   component's REAL directory as the value. Callers never build a path. */
function locate_map(?string $root = null): array
```

Rules, each of which the tests pin:

- Resolve each enclosure entry with `realpath()` and take the **last**
  `0000:xx:xx.x` match in the resolved path — that is the endpoint HBA, not
  the bridge above it.
- A component counts only if it has a readable `slot` **and** a writable
  `locate`. Anything else is silently skipped: that is the capability check.
- Unpopulated bays (no `device/block/*`) still map. They are real slots and
  blinking an empty bay is a legitimate "where would the next disk go".

### Step 2: preflight

```php
function locate_preflight(array $map, string $pci, string $slotField): array
```

`$slotField` is the drive JSON's `slot` (`"0/12"` or `"12"`). Return
`['ok'=>false,'error'=>…]` when: the controller's PCI address is absent from
`$map` (no enclosure behind this HBA), the slot is not an integer, or that slot
has no component. Return `['ok'=>true,'path'=>…]` otherwise, where `path` came
**from the map**, never from the caller.

Unit-test all four rejections plus the happy path.

### Step 3: the write — and the one rule that matters

```php
$path = $pre['path'] . '/locate';          // from locate_map(), never from input
file_put_contents($path, "1\n");
```

**Never concatenate a request value into a filesystem path.** The controller
index and slot arriving over HTTP are only ever used as *lookup keys* into the
map built in Step 1. This is a root process writing to sysfs; a path assembled
from `$_POST` is a write-anywhere primitive. If you find yourself building the
string, stop and re-read this step.

Validate `ctrl` as an integer and use it to index the overview's controller
list; take `pci_location` from there and normalise it to sysfs form the same
way `get_hba_info.sh` does (`00:c1:00:00` → `0000:c1:00.0`).

### Step 4: auto-off

Mirror `flash.php:164` — do not invent a second mechanism:

```php
$inner = 'sleep 600; printf 0 > ' . escapeshellarg($path);   // 0 = off; 1 is what the press wrote
shell_exec('nohup sh -c ' . escapeshellarg($inner) . ' >/dev/null 2>&1 &');
```

> **Corrected 2026-08-02.** This snippet originally read `printf 1`, which would
> have re-lit the LED ten minutes after the press instead of turning it off —
> contradicting the section title and its own comment. The executor spotted it,
> implemented `0`, and flagged it rather than silently deviating. Left visible
> here because "the plan's sample code was wrong and the executor was right to
> depart from it" is worth more to the next reader than a clean-looking plan.

Also expose a `clear` action so the user who found the drive is not waiting ten
minutes.

```
ponytail: one detached timer per press, no bookkeeping. Pressing locate twice
on the same slot means the first timer clears it early. Track timers per slot
only if that ever actually annoys someone.
```

### Step 5: the button

In the row loop next to `$smart`, following its exact shape. Enabled when
preflight passes; otherwise a muted `—` with a `title` saying why ("no
addressable enclosure slot for this drive"). Build the map **once per render**,
outside the loop.

## Test plan

`tests/locate_test.php`, following `tests/phy_baseline_test.php`'s style, and
registered in **both** places in `tests/run_php.sh` — the plain `php …` line and
the `sh -c '…'` docker line. Missing the second is how a test silently never
runs in CI.

**Build the fixture tree at runtime under `mktemp -d`. Do not commit it.**
`plans/README.md`'s Working rules record this biting twice: the enclosure
directory names here are `0:0:0:0` and the PCI directories `0000:c1:00.0`, and
**Windows/NTFS forbids `:` in filenames** — MSYS silently substitutes U+F03A, a
Private Use Area lookalike, and git stores the mangled bytes. Plan 013 lost a
day to exactly this. Generate the tree in the test's `setUp` and delete it after.

Cases, all shapes taken from the captured evidence above:

- Two enclosures under two different PCI addresses → `locate_map` keys on both.
- A component with `slot` and `locate` → present. One missing `locate` → absent.
- Populated (`device/block/sda`) and unpopulated bays → both present.
- A bridge PCI address earlier in the path → the **last** match wins.
- `"0/12"` and `"12"` slot forms → same component.
- Preflight rejects: unknown PCI, non-integer slot, unmapped slot.
- Preflight's returned path is byte-identical to the map's, never rebuilt.

## Done criteria

- [ ] `locate_map()` returns the two-enclosure shape from a generated fixture tree
- [ ] `locate_preflight()` rejects all four failure classes, unit-tested
- [ ] No filesystem path in `locate.php` is built from a request value —
      verify by reading every `file_put_contents` / `escapeshellarg` call site
- [ ] Only `locate` is written. `grep -c "fault\|power_status\|/active" locate.php` → `0`
- [ ] Auto-off uses the `nohup sh -c … &` form from `flash.php:164`
- [ ] Button present and enabled on the maintainer's 24 slots; muted `—` with a
      title on any drive that does not resolve
- [ ] `php -l` clean on all touched files; `bash tests/run.sh` → `--- all pass ---`
- [ ] `git diff -- tests/expected/` empty — this plan moves no golden
- [ ] `git status --porcelain` shows no committed fixture directory containing `:`

## STOP conditions

- The drift check prints anything.
- Any path passed to `file_put_contents` is assembled from `$_POST`/`$_GET`.
- Anything other than `locate` is written.
- `sg_ses` or any external binary appears in the diff.
- A fixture directory with `:` in its name is committed.
- `locate_map()` needs the `direct` flag, or any parser changes — it does not;
  if it seems to, the resolver has drifted from the sysfs evidence above.

## Hardware acceptance — the maintainer can do this one

Unlike v1, this is testable on the maintainer's own box. Before shipping,
confirm an LED physically lights:

### RESULT, 2026-08-02: no LED responds on the maintainer's hardware

Run and recorded. **The mechanism reaches the kernel and stops there.**

- Writing `1` to a **populated** slot's `locate` persists — slot 6 (`sdj`) was
  found still reading `1` in a later probe and cleared cleanly to `0`.
- Writing `1` to an **empty** bay does not persist — bay 21 read back `0`
  immediately. Empty SES elements will not hold an ident flag at all, so an
  empty bay can never demonstrate this feature either way.
- With `locate=1` set on **every populated slot across both enclosures**
  (16 + 8 = 24 drives, all reading back `1`), **no chassis LED changed.**

So on this box a writable `locate` attribute means only that the kernel
registered an SES element — not that anything is wired behind it. Both
enclosures are the HBA's synthesised `VirtualSES`, and nothing in SES reports
whether an element drives real hardware, so **there is no software signal that
distinguishes "will blink" from "will silently do nothing."**

Consequences, in order of importance:

1. **This plan cannot be verified on the maintainer's hardware.** It needs a
   reporter with a genuine expander backplane. v1's scepticism about
   addressable hardware turns out to have been right about the *outcome*,
   even though it was wrong about the mechanism and wrong about `VirtualSES`
   being the signal (the components are real and writable — they just aren't
   connected to anything).
2. **`is_writable($locateFile)` is not a capability check.** It is a
   *necessary* condition only. The code is correct as written and the button
   appears exactly where the kernel says a slot exists; what is missing is any
   way to know that pressing it does something. Do not try to invent a
   stronger check — there isn't one to invent.
3. **Do not merge on the strength of the unit tests.** They prove the join and
   the guards, which is all they ever claimed. The feature's actual purpose —
   a human finds a bay — has never once been observed working.

If a future reporter confirms a real blink, record their controller, backplane
and enclosure product string here; that is the first evidence this feature
works at all.

### The commands (for whoever has the right hardware)

**Component directory names are not slot numbers** — on the maintainer's box
directory `0` is slot 12 (`sda`) and directory `7` is slot 11. Directories
`16`+ happen to equal their slot numbers, but the low ones do not. Always
resolve by reading each `slot` file; never index by directory name.

```bash
E=/sys/class/enclosure/0:0:0:0
C=$(for c in "$E"/*/; do [ "$(cat "$c/slot" 2>/dev/null)" = "21" ] && { echo "$c"; break; }; done)
echo "component dir: ${C:-NOT FOUND}"
echo "slot=$(cat "$C/slot") locate=$(cat "$C/locate") populated=$(ls "$C/device/block" 2>/dev/null || echo 'empty bay')"

echo 1 > "$C/locate"; echo "locate now: $(cat "$C/locate") — look at bay 21"
echo 0 > "$C/locate"
```

Bay 21 is the maintainer's chosen target because it holds no array member.
**It is also an empty bay** (slots 0–15 are populated on that enclosure, 16+
are not), which makes a negative result ambiguous: some backplanes only drive
LEDs for occupied slots. If bay 21 stays dark, repeat on a populated slot
before concluding anything — toggling `locate` on an array member is safe, as
it only changes a light and touches no drive state.

If the attribute accepts the write but no light appears on a **populated**
slot, that is a genuine finding: the capability check needs a stronger signal
than "the file is writable", and the honest answer is probably to keep the
button and let the user discover it, since nothing in SES reports "this
element is wired to a real LED". Record the outcome either way.

## Maintenance notes

- **The kernel's enclosure class is the whole feature.** `locate`, `slot` and
  `device/block/*` come free with the `ses` module; the plugin's contribution
  is only the controller↔enclosure join and a button. If a future change
  starts parsing SES pages by hand, something has gone wrong.
- **`components` counts bays, not drives** (48 and 40 on a 16- and 8-drive
  box). Never use it as a drive count — that is the same mistake plan 017 fixed
  in the enclosure summary.
- **The same walk would give the Drives tab a `/dev` name**, which the storcli
  backend has never had (`storcli_drives.sh`'s header says so explicitly).
  Deliberately not done here — it changes a rendered table and belongs in its
  own plan — but it is the obvious follow-on and the data is already in hand.
- **Cold overview cache renders every button muted.** The PCI addresses come
  from `cached_read('overview', 60, …)`, which returns `state: warming` with an
  empty body when the cache is stale, so `$pciByCtl` is empty and every row
  falls to the disabled `—`. It self-heals on the tab's next 60-second refresh.
  Fail-closed is the right direction here — a missing button is recoverable, a
  button pointing at an unverified slot is not — but if it ever reads as "the
  feature is broken", the fix is to show a "resolving…" state rather than to
  weaken the gate.
