# Plan 010: Stop misdiagnosing SAS2 cards that sit on the mpt3sas driver

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 0346777..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh source/usr/local/emhttp/plugins/hbaviewer/settings.php`
> `settings.php` was modified after `0346777` by the issue-3 fix (backend
> precedence). That change is expected — confirm it matches the excerpt in
> "Current state" below, which already reflects it. Any *other* difference is a
> STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug / docs
- **Planned at**: commit `0346777` (+ the uncommitted issue-3 fix), 2026-07-26

## Why this matters

When the bundled `lsiutil` cannot reach a controller, the plugin currently tells
the user this:

> storcli not found. **This looks like a SAS3/SAS3.5 (mpt3sas) controller** —
> install storcli via the dkaser/unraid-storcli plugin (Community Applications),
> then reload.

That sentence infers the *hardware generation* from the *driver module*, and the
inference is wrong. `mpt3sas` is the merged driver: it absorbed SAS2 support and
drives SAS2004–SAS2308 hardware perfectly well. So a 9207-8i owner whose kernel
binds the card to `mpt3sas` is told they own a SAS3 controller, and told to
install a tool aimed at MegaRAID controllers that may not help them at all.

The actual situation is narrower and entirely about *our* tool, not their
hardware: **lsiutil 1.70 predates `mpt3sas` and opens `/dev/mptctl`, a device
node only `mpt2sas` creates.** The card is fine. The driver is fine. The bundled
binary simply cannot talk through that driver.

This plan does not fix the capability gap — it makes the plugin stop lying about
its cause, and surfaces the two facts needed to decide whether the gap is worth
closing at all. See "The decision this plan feeds" below.

**Scope discipline matters here.** It is tempting to jump straight to a `sysfs`
backend that reads `mpt3sas`'s host attributes and sidesteps lsiutil entirely.
That may well be the right answer — but nobody currently knows how many users
are affected, because the plugin has never reported which driver claimed the
card. Build the measurement before the cure.

## Current state

Files involved:

- `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh` — the
  overview composer. The misleading refusal is in `ov_lsiutil`, lines 50–56.
- `source/usr/local/emhttp/plugins/hbaviewer/settings.php` — the settings page.
  Shows the resolved "Access Method" but never names the driver or the card.
- `source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh` — `hba_driver()`
  already detects the loaded driver and version.

The refusal exactly as it exists today, `get_hba_info.sh:50-57`:

```bash
ov_lsiutil() {
    # A pure SAS3/3.5 box (mpt3sas, no mpt2sas) with no storcli: the bundled
    # lsiutil 1.70 can't reliably read it — point the user at the storcli plugin.
    if [ -z "$(find_storcli)" ] && [ -d /sys/module/mpt3sas ] && [ ! -d /sys/module/mpt2sas ]; then
        echo '{"error":"storcli not found. This looks like a SAS3/SAS3.5 (mpt3sas) controller — install storcli via the dkaser/unraid-storcli plugin (Community Applications), then reload."}'
        return 1
    fi
    require_binary || return 1
```

The guard condition itself is **correct and stays** — `mpt3sas` present, `mpt2sas`
absent, no storcli really is the situation where nothing can read the card. Only
the *message* is wrong.

The existing driver detector, `scripts/lib.sh:80-84`, which this plan reuses
rather than duplicating:

```bash
hba_driver() {
    if   [ -r /sys/module/mpt3sas/version ]; then echo "mpt3sas $(cat /sys/module/mpt3sas/version)"
    elif [ -r /sys/module/mpt2sas/version ]; then echo "mpt2sas $(cat /sys/module/mpt2sas/version)"
    fi
}
```

The settings page's backend resolution, `settings.php` (as amended by the
issue-3 fix — this is the current text, not the `0346777` text):

```php
if ($storcli !== '') {
    $backend_label = 'storcli';
    $backend_note  = $has_sas2
        ? 'storcli is installed and is tried first; the bundled lsiutil covers any SAS2 card it does not enumerate.'
        : 'SAS3 / SAS3.5 controller detected (mpt3sas driver).';
} elseif ($has_sas2) {
    $backend_label = 'lsiutil (bundled)';
    $backend_note  = $has_sas3
        ? 'SAS2 controller detected (mpt2sas driver). mpt3sas is also loaded, but nothing here needs storcli unless a card fails to read.'
        : 'SAS2 controller detected (mpt2sas driver).';
} elseif ($has_sas3) {
    $backend_label = 'storcli — NOT INSTALLED';
    $backend_note  = 'SAS3 / SAS3.5 controller detected (mpt3sas only), but storcli is missing. Install it via the dkaser/unraid-storcli plugin (Community Applications).';
} else {
    $backend_label = 'none detected';
    $backend_note  = 'No supported HBA driver (mpt2sas / mpt3sas) is loaded.';
}
```

The `elseif ($has_sas3)` branch has the same defect as the shell message: it
asserts "SAS3 / SAS3.5 controller detected" purely because `mpt3sas` is loaded.

**Sysfs attributes this plan reads.** `mpt2sas`/`mpt3sas` publish per-host
attributes under `/sys/class/scsi_host/hostN/`. The ones this plan needs:

| Attribute | Example | Used for |
|---|---|---|
| `proc_name` | `mpt3sas` | which driver claimed this host |
| `board_name` | `SAS9207-8i` | the actual card, independent of driver |
| `version_fw` | `20.00.07.00` | firmware, independent of lsiutil |

**These are assumed, not verified — Step 1 confirms they exist before anything
depends on them.** The plan is written so that a missing attribute degrades to
the current behaviour rather than breaking.

**Repo conventions that apply here:**

- Shell composers source `lib.sh` and `config.sh`, then declare per-backend
  reads. Comments explain *why*, not *what* — see the header of
  `scripts/lib.sh`.
- Error payloads are a single-key JSON object: `{"error":"..."}`. PHP renders
  `$data['error']` into `<div class="lu-error">` — see
  `ajax_info.php:105-107`. Keep the shape.
- Deliberate simplifications carry a `ponytail:` comment naming the ceiling and
  the upgrade path — see `get_metrics.sh:16-19`, `lib.sh:80`.
- `settings.php` deliberately avoids hardware enumeration so the page is
  instant — its own comment at lines 9–11 says so. Reading a few sysfs files is
  consistent with that; **running storcli or lsiutil is not.**

Relevant vocabulary from `source/usr/local/emhttp/plugins/hbaviewer/CONTEXT.md`
(the module glossary — the executor has not read it):

> **backend module** — `scripts/lib.sh` (`hba_each`)
> The one seam that chooses **storcli** (SAS3/3.5) vs **lsiutil** (SAS2). A tab
> composer declares only *what to read per controller* for each backend;
> `hba_each` owns *which backend* (`use_storcli`), *how many controllers*
> (`storcli_count`), the *driver string* (`hba_driver`) … Add a backend, or a
> per-tab read, in one place.

That last sentence is the upgrade path this plan deliberately does **not** take
yet.

## Commands you will need

| Purpose         | Command                                                              | Expected on success        |
|-----------------|----------------------------------------------------------------------|----------------------------|
| Shell lint      | `find source tests -name '*.sh' -print0 \| xargs -0 -r -n1 bash -n`  | exit 0                     |
| PHP lint        | `find source tests -name '*.php' -print0 \| xargs -0 -r -n1 php -l`  | exit 0                     |
| Full test suite | `bash tests/run.sh`                                                  | `--- all pass ---`, exit 0 |

If `php` is absent, `tests/run.sh` falls back to a `php:8.2-cli` Docker image.
Both halves must pass.

## Scope

**In scope**:

- `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh` (the refusal message only)
- `source/usr/local/emhttp/plugins/hbaviewer/settings.php` (add a driver/card diagnostic row)

**Out of scope** (do NOT touch, even though they look related):

- **The guard condition itself** in `ov_lsiutil`. `mpt3sas && !mpt2sas && no storcli`
  correctly identifies "nothing here can read this card". Only its wording is wrong.
- **A `sysfs` backend.** Deliberately deferred — see "The decision this plan
  feeds". Adding one here would be building a cure for a disease of unmeasured
  prevalence, and it belongs in `hba_each` as a third backend with its own
  fixtures and goldens, not smuggled into a message fix.
- **Anything that loads, blacklists, binds, or unbinds a kernel driver.** Unraid
  runs from a RAM-loaded squashfs with no compiler or kernel headers, so a plugin
  cannot persist modules; and rebinding a live storage controller via
  `/sys/bus/pci/drivers/*/unbind` would tear it out from under a mounted array.
  If any part of this work seems to require it, that is a STOP condition.
- `scripts/lib.sh` `hba_driver()` — reuse it, do not rewrite it.
- The `.plg` install hooks. An install-time driver check would go stale the
  moment the user changes hardware or Unraid changes kernels; the check belongs
  at read time, which is where this plan puts it.

## Git workflow

- Branch: `advisor/010-mpt3sas-sas2-diagnosis`, cut from `dev`
- One or two commits. Message style matches this repo's history — short
  imperative subject, no conventional-commit prefix. Suggested:
  `Stop reporting SAS2 cards on mpt3sas as SAS3 controllers`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Confirm the sysfs attributes exist before depending on them

On a machine with an LSI HBA (your Unraid box), list what the driver actually
publishes:

```bash
for h in /sys/class/scsi_host/host*/; do
  [ -r "$h/proc_name" ] || continue
  case "$(cat "$h/proc_name")" in mpt3sas|mpt2sas|mptsas) ;; *) continue ;; esac
  echo "== $h"
  for a in proc_name board_name version_fw version_bios host_sas_address; do
    [ -r "$h$a" ] && printf '  %-18s %s\n' "$a" "$(cat "$h$a" 2>/dev/null)"
  done
done
```

**Verify**: at least `proc_name` and `board_name` print a value for each SAS host.

Record the full output in your report — it is the evidence the decision in
"The decision this plan feeds" rests on.

If `board_name` is **absent**, do not abandon the plan: Steps 2 and 3 are written
to fall back to a generic phrasing when it cannot be read. Note the absence and
continue.

If you have no LSI HBA on the machine, say so plainly in your report and proceed
— the code paths degrade to the generic message, and the test suite does not
depend on real hardware.

### Step 2: Replace the misleading refusal with an accurate one

In `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh`, replace
the `ov_lsiutil` guard block (lines 50–56 in "Current state") with:

```bash
ov_lsiutil() {
    # No storcli, and the card is on mpt3sas with no mpt2sas: the bundled lsiutil
    # 1.70 opens /dev/mptctl, which only mpt2sas creates, so it cannot reach this
    # controller. That is a limitation of OUR tool, not of the hardware —
    # mpt3sas is the merged driver and happily drives SAS2 cards, so do NOT
    # report the card as SAS3. Name the actual board when sysfs gives it to us.
    if [ -z "$(find_storcli)" ] && [ -d /sys/module/mpt3sas ] && [ ! -d /sys/module/mpt2sas ]; then
        local board=""
        for h in /sys/class/scsi_host/host*/; do
            [ -r "$h/board_name" ] || continue
            case "$(cat "$h/proc_name" 2>/dev/null)" in mpt3sas|mpt2sas|mptsas) ;; *) continue ;; esac
            board=$(tr -d '\n' < "$h/board_name" 2>/dev/null)
            [ -n "$board" ] && break
        done
        printf '{"error":"%s is on the mpt3sas driver, which the bundled lsiutil cannot read through. Install storcli via the dkaser/unraid-storcli plugin (Community Applications), then reload. Your card is supported — this is a limitation of the bundled tool, not a fault with the controller."}' \
            "${board:-This controller}"
        return 1
    fi
    require_binary || return 1
```

Note the two behaviours this produces:

- `board_name` readable → *"SAS9207-8i is on the mpt3sas driver, which…"*
- `board_name` absent → *"This controller is on the mpt3sas driver, which…"*

Neither claims a hardware generation, which is the whole point.

**Verify**: `bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh` → exit 0

**Verify**: the false claim is gone —
`grep -c 'looks like a SAS3' source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh` → prints `0`

**Verify**: the message is still valid JSON. The existing golden case
`route-fallback` drives this composer with no backend available; run the suite in
Step 5 and confirm it still passes.

### Step 3: Stop the Settings page asserting SAS3 from the driver alone

In `source/usr/local/emhttp/plugins/hbaviewer/settings.php`, replace **only** the
`elseif ($has_sas3)` branch shown in "Current state" with:

```php
} elseif ($has_sas3) {
    // mpt3sas with no mpt2sas. Do NOT call this a SAS3 controller — mpt3sas is
    // the merged driver and also drives SAS2 cards; the card model is the only
    // honest evidence, and the bundled lsiutil can't read through this driver
    // either way.
    $backend_label = 'storcli — NOT INSTALLED';
    $backend_note  = 'A controller was found on the mpt3sas driver, which the bundled lsiutil cannot read through. Install storcli via the dkaser/unraid-storcli plugin (Community Applications).';
}
```

Then add a read-only diagnostic row so users can see — and report — what the
plugin actually detected. Insert it immediately after the existing "Access
Method" row (the `lu-s-row` containing `$backend_label`), inside the same
`lu-s-card`:

```php
      <div class="lu-s-row">
        <div class="lu-s-label">
          Detected Hardware
          <small>Read-only. Quote this when reporting an issue.</small>
        </div>
        <div class="lu-s-control" style="padding-top:8px">
          <span style="font-family:var(--mono);font-size:12px"><?= htmlspecialchars($hw_detail) ?></span>
        </div>
      </div>
```

and compute `$hw_detail` next to the existing detection block near the top of the
file (after the `$storcli` lookup, before the `if ($storcli !== '')` chain):

```php
// Per-host driver + board, straight from sysfs — instant, no hardware
// enumeration, and the one piece of evidence that distinguishes "SAS3 card" from
// "SAS2 card that landed on mpt3sas". This row exists so a user can report it.
$hw = [];
foreach (glob('/sys/class/scsi_host/host*/') ?: [] as $h) {
    $drv = trim((string) @file_get_contents($h . 'proc_name'));
    if (!in_array($drv, ['mpt3sas', 'mpt2sas', 'mptsas'], true)) continue;
    $board = trim((string) @file_get_contents($h . 'board_name'));
    $fw    = trim((string) @file_get_contents($h . 'version_fw'));
    $hw[]  = ($board !== '' ? $board : 'unknown board') . " ($drv"
           . ($fw !== '' ? ", fw $fw" : '') . ')';
}
$hw_detail = $hw ? implode(' · ', $hw) : 'no mpt2sas/mpt3sas hosts found';
```

`glob()` returning `false` is handled by the `?: []`; every read is `@`-guarded
and trimmed, so an unreadable attribute degrades to `unknown board` rather than
a warning in the page.

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/settings.php` → "No syntax errors detected"

**Verify**: `grep -c 'SAS3 / SAS3.5 controller detected (mpt3sas only)' source/usr/local/emhttp/plugins/hbaviewer/settings.php` → prints `0`

**Verify**: `grep -c 'hw_detail' source/usr/local/emhttp/plugins/hbaviewer/settings.php` → prints `2` (the assignment and the one use)

### Step 4: Check the new detection logic against a fake sysfs tree

`settings.php` reads absolute paths, so it cannot be pointed at a fixture without
a refactor that is out of scope. Instead, verify the *logic* in isolation with
the same code over a temp tree:

```bash
mkdir -p /tmp/hbav_sysfs/host0 /tmp/hbav_sysfs/host1 /tmp/hbav_sysfs/host2
printf 'mpt3sas' > /tmp/hbav_sysfs/host0/proc_name
printf 'SAS9207-8i' > /tmp/hbav_sysfs/host0/board_name
printf '20.00.07.00' > /tmp/hbav_sysfs/host0/version_fw
printf 'mpt3sas' > /tmp/hbav_sysfs/host1/proc_name      # board_name missing
printf 'ahci'    > /tmp/hbav_sysfs/host2/proc_name      # must be skipped

php -r '
$hw = [];
foreach (glob("/tmp/hbav_sysfs/host*/") ?: [] as $h) {
    $drv = trim((string) @file_get_contents($h . "proc_name"));
    if (!in_array($drv, ["mpt3sas","mpt2sas","mptsas"], true)) continue;
    $board = trim((string) @file_get_contents($h . "board_name"));
    $fw    = trim((string) @file_get_contents($h . "version_fw"));
    $hw[]  = ($board !== "" ? $board : "unknown board") . " ($drv" . ($fw !== "" ? ", fw $fw" : "") . ")";
}
echo ($hw ? implode(" · ", $hw) : "no mpt2sas/mpt3sas hosts found") . "\n";'
```

**Verify**: prints exactly

```
SAS9207-8i (mpt3sas, fw 20.00.07.00) · unknown board (mpt3sas)
```

That confirms all three behaviours at once: a fully-populated host, a host with a
missing `board_name`, and a non-SAS host correctly skipped.

Then the empty case:

```bash
rm -rf /tmp/hbav_sysfs && mkdir -p /tmp/hbav_sysfs
```
and re-run the `php -r` above.

**Verify**: prints `no mpt2sas/mpt3sas hosts found`

Clean up: `rm -rf /tmp/hbav_sysfs`

### Step 5: Lint and run the full suite

**Verify**: `find source tests -name '*.sh' -print0 | xargs -0 -r -n1 bash -n` → exit 0

**Verify**: `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` → exit 0

**Verify**: `bash tests/run.sh` → `--- all pass ---`, exit 0. In particular
`PASS  route-fallback` must still pass — it is the golden that drives
`get_hba_info.sh` with no backend available and proves your new error payload is
still well-formed JSON.

## Test plan

**No new golden cases.** The changed code paths are (a) an error string and (b) a
sysfs read of absolute paths. Neither is reachable from the fixture harness
without restructuring, and the existing `route-fallback` golden already covers
that the composer emits valid error JSON when no backend resolves.

What stands in for tests:

- **Step 4** is the real check on the new detection logic — a three-host fake
  tree with a fully-populated host, a host missing `board_name`, and a non-SAS
  host that must be skipped. Expected output is stated exactly, so it is
  pass/fail rather than a judgement call.
- **Step 1** confirms the sysfs contract on real hardware before anything relies
  on it.
- `bash tests/run.sh` must stay green, `route-fallback` included.

If a future plan adds the `sysfs` backend, that one **does** need fixture
coverage — a captured `/sys/class/scsi_host` tree under `tests/fixtures/sysfs/`
and goldens through `hba_each`, exactly as the storcli backend is covered today.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'looks like a SAS3' source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh` prints `0`
- [ ] `grep -c 'SAS3 / SAS3.5 controller detected (mpt3sas only)' source/usr/local/emhttp/plugins/hbaviewer/settings.php` prints `0`
- [ ] `grep -c 'hw_detail' source/usr/local/emhttp/plugins/hbaviewer/settings.php` prints `2`
- [ ] `grep -c 'mpt3sas' source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh` is ≥ 2 — the guard condition still tests the driver; only the wording changed
- [ ] Step 4 printed exactly the two expected strings
- [ ] `find source tests -name '*.sh' -print0 | xargs -0 -r -n1 bash -n` exits 0
- [ ] `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` exits 0
- [ ] `bash tests/run.sh` exits 0, prints `--- all pass ---`, and includes `PASS  route-fallback`
- [ ] `git status --porcelain` shows exactly two modified files: `get_hba_info.sh` and `settings.php` (plus `plans/README.md`)
- [ ] Step 1's sysfs listing is recorded in the report
- [ ] `plans/README.md` status row for 010 updated

## STOP conditions

Stop and report back (do not improvise) if:

- You conclude the fix requires loading, blacklisting, binding, or unbinding a
  kernel driver. It does not, and none of those are things a plugin may safely
  do on Unraid — see "Out of scope" for why.
- Step 1 shows that `proc_name` does not exist under `/sys/class/scsi_host/host*/`.
  The whole detection approach — including code already shipping in
  `get_metrics.sh:27` and `get_attached_drives.sh:46` — depends on it, and its
  absence means something more fundamental has changed.
- `route-fallback` fails after your change. Your error payload is malformed JSON;
  check quoting in the `printf`.
- You find yourself widening this into a `sysfs` backend that reads firmware and
  PHY data to replace lsiutil. That is the deferred follow-up, gated on evidence
  this plan is designed to collect. Report the temptation; do not act on it.
- The guard condition's behaviour changes at all — a box that previously read its
  card successfully must still do so. Only the refusal text and the settings note
  change.

## The decision this plan feeds

This plan exists partly to answer a question nobody currently has data for:
**how many SAS2 owners are on `mpt3sas`-only boxes?**

Evidence so far is one data point, and it is inconclusive in an interesting way.
The reporter of GitHub issue #3 has a SAS9207-8i and *did* get card details
rendered — which is only possible if `ov_lsiutil`'s guard did not fire, which
requires `mpt2sas` to be loaded on their box. So at least one current Unraid
install still binds a 9200-series card to `mpt2sas`.

Once the "Detected Hardware" row from Step 3 ships, users can report it directly.
Then:

- **If 9200-series cards are on `mpt2sas`** on current Unraid, this scenario is
  rare, the corrected message is sufficient, and no further work is needed.
- **If they are landing on `mpt3sas`**, every SAS2 user without storcli is
  currently locked out, and the follow-up becomes high priority: a third
  `sysfs` backend in `hba_each`, reading `board_name`, `version_fw`,
  `version_bios` and the existing `sas_phy` / `sas_end_device` classes the plugin
  already parses. That would deliver model, firmware, PHY health and drives with
  **no proprietary tool at all** — though not temperature (an IOC page read) or
  the firmware event log.

Do not write that follow-up until the evidence exists.

## Maintenance notes

- **The guard and the message are now decoupled.** The condition
  (`mpt3sas && !mpt2sas && no storcli`) describes when nothing can read the card;
  the message describes why. If someone later adds a backend that *can* read that
  case, the guard is what changes — and the message should be deleted, not
  reworded.
- **`settings.php` and `get_hba_info.sh` must keep agreeing.** They disagreed
  once already: the settings page warned whenever `mpt3sas` was present while the
  composer only refused when `mpt2sas` was absent, which is what produced the
  spurious storcli prompt in issue #3. Any future change to one needs the same
  change in the other, or a shared helper.
- **`board_name` is the honest signal for card identity**, not the driver module
  and not the PCI device ID map in `parse/storcli_overview.sh:33-42`. If a third
  place ever needs to identify the card, use `board_name` and extend from there.
- **What a reviewer should scrutinise**: that the guard condition is byte-for-byte
  unchanged, and that the new `printf` produces valid JSON when `board_name`
  contains no special characters. Board names are alphanumeric plus hyphens in
  every sample seen so far — if one ever contains a quote, the payload breaks the
  same way `collect_smart.sh:10-11` documents for drive models.
