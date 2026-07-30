# Plan 010: Detect controller generation from `proc_name`, not from which driver module is loaded

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 62fe791..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh source/usr/local/emhttp/plugins/hbaviewer/settings.php tests/run.sh`
> Baseline re-pinned to `62fe791` (`dev` tip, 2026-07-29). Expected output:
> **nothing**. Every code excerpt below is quoted from that commit, including
> line numbers. Any difference is a STOP condition — re-read the four files
> before continuing.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: `62fe791`, revised 2026-07-29 (third revision — see "History")
- **Issue**: [#3](https://github.com/FugginOld/Unraid-HBAviewer/issues/3) — reopened,
  **evidence now complete**, no longer blocked

## READ FIRST — the gate is answered, and it changes the fix

The two previous revisions of this plan ended on one open question: *can the
bundled `lsiutil` 1.70 read a SAS2 card through the merged `mpt3sas` driver?*
Issue #3's reporter ran the two diagnostic commands on 2026-07-28
([comment 5110412764](https://github.com/FugginOld/Unraid-HBAviewer/issues/3#issuecomment-5110412764)).

**Answer: yes. It works.**

```text
crw------- 1 root root 10, 220 Jul 26 17:19 /dev/mptctl     <- present on an mpt3sas-only box
crw------- 1 root root 10, 221 Jul 26 17:17 /dev/mpt2ctl
crw------- 1 root root 10, 222 Jul 26 17:17 /dev/mpt3ctl

$ hbaviewer.x86_64 -p1 -a 25,2,0,0
1 MPT Port found
 1.  ioc0   LSI Logic SAS2308 D1   MPT Rev 200   Firmware Rev 14000700   IOC 0
               IOCTemperature:     0x002F      <- 47 °C, read successfully
          IOCTemperatureUnits:       0x02      <- 0x02 = Celsius
             BoardTemperature:     0x0000
        BoardTemperatureUnits:       0x00      <- 0x00 = no such sensor
```

Their earlier sysfs listing, from the same issue:

```text
/sys/module/mpt3sas/          <- only the mpt3sas MODULE is loaded; no mpt2sas dir
== /sys/class/scsi_host/host0/
  proc_name    mpt2sas        <- but the HOST reports the mpt2sas personality
  board_name   SAS9207-8i     <- and it is a SAS2 card
  version_fw   20.00.07.00
```

Three consequences, all of which this revision acts on:

1. **`ov_lsiutil`'s guard is wrong in its *condition*, not merely its wording.**
   It refuses a card the bundled binary can read perfectly well. Both previous
   revisions declared the condition correct and out of scope; that is now
   contradicted by direct evidence and the condition is the *primary* fix.
2. **The sysfs-backend follow-up is dead.** Two revisions deferred a third
   `hba_each` backend that would read model/firmware/PHY from sysfs to work
   around lsiutil. There is nothing to work around. Do not build it, and delete
   the temptation from the record — see "Follow-ups this plan does not do".
3. **Module presence is not evidence of controller generation, anywhere.** The
   merged `mpt3sas` driver registers SAS2 controllers under the `mpt2sas`
   personality, so a box can have no `mpt2sas` module while its SAS2 card
   reports `proc_name=mpt2sas`. `/sys/module/*` answers "which driver binary is
   loaded"; `/sys/class/scsi_host/hostN/proc_name` answers "which personality
   claimed this controller", and only the second maps to SAS2-vs-SAS3.

**The whole plan is now: one predicate, `proc_name`, implemented once in
`lib.sh` and mirrored once in `settings.php`, replacing every
`/sys/module/*` test that pretends to know the hardware generation.**

## Why this matters

Today, an owner of a SAS2 card whose kernel binds it to `mpt3sas` (the common
case on current Unraid) and who has not installed storcli gets:

> storcli not found. This looks like a SAS3/SAS3.5 (mpt3sas) controller — install
> storcli via the dkaser/unraid-storcli plugin (Community Applications), then reload.

Three things wrong with that, in order of severity:

- **It is a refusal, not a message.** The card is readable. The plugin declines to
  read it and shows an error page instead. That is the actual bug; the reporter's
  third screenshot is exactly this.
- **It asserts a hardware generation from a driver module.** The card is a SAS2
  9207-8i. `mpt3sas` is the merged driver and drives SAS2004–SAS2308 fine.
- **It sends the user to install a tool they do not need**, which for their
  hardware may not enumerate the controller at all.

And on the Settings page the same false inference produces two visible defects on
that box: *"SAS3 / SAS3.5 controller detected (mpt3sas only)"* for a SAS2 card,
and — because `settings.php:124` gates the **lsiutil Port** input on
`$has_sas2` — the port field disappears entirely, so the one setting that
backend needs cannot be changed.

Note what is **not** broken and must not be "fixed": the firmware hex decode
shipped in 2026.07.27 works. The reporter's first screenshot shows the correct
`20.00.07.00`, matching sysfs `version_fw`, and the temperature reaches the
Performance tab. That half of issue #3 is done.

## Current state

Four files, quoted from `62fe791`.

### 1. The refusal — `scripts/get_hba_info.sh:81-88`

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

The guard's *purpose* survives this plan: a genuine SAS3-only box (9300/9400,
`proc_name=mpt3sas`) with no storcli really has nothing that can read the card,
and a clear "install storcli" error beats an empty overview. Only its **test**
and its **wording** change.

### 2. The existing `proc_name` precedent — `scripts/get_metrics.sh:24-29`

Two shipping scripts already do exactly the right thing, and this plan lifts
their loop into `lib.sh` rather than inventing a third copy:

```bash
for h in /sys/class/scsi_host/host*/; do
    [ -d "$h" ] || continue
    case "$(cat "${h}proc_name" 2>/dev/null)" in mpt3sas|mpt2sas|mptsas) ;; *) continue ;; esac
    hn=$(basename "$h"); hosts+=("${hn#host}")
done
```

`scripts/get_attached_drives.sh:45-47` has the same `case` filter. The three-way
`mpt3sas|mpt2sas|mptsas` match is the established idiom in this repo — keep it.

### 3. The driver string — `scripts/lib.sh:77-84`

```bash
hba_driver() {
    if   [ -r /sys/module/mpt3sas/version ]; then echo "mpt3sas $(cat /sys/module/mpt3sas/version)"
    elif [ -r /sys/module/mpt2sas/version ]; then echo "mpt2sas $(cat /sys/module/mpt2sas/version)"
    fi
}
```

**Leave this alone.** It reads `/sys/module/*`, but it is not making the bad
inference: it reports *which driver module is loaded and its version*, and on the
reporter's box `mpt3sas 43.100.00.00` is the literal truth. The bug was never
"the plugin names mpt3sas" — it was "the plugin concludes SAS3 from mpt3sas".
An executor who sees `is_dir('/sys/module/` in the grep results and reflexively
converts this too will produce a worse string (`mpt2sas` personality plus the
`mpt3sas` module's version) for no gain.

### 4. Settings detection — `settings.php:9-45`

```php
// Backend detection — driver via sysfs + storcli path lookup. Both are instant
// (no hardware enumeration), so the page never lags. SAS2 (6 Gb) cards use the
// mpt2sas driver + bundled lsiutil; SAS3/3.5 use mpt3sas + system storcli.
$has_sas2 = is_dir('/sys/module/mpt2sas');
$has_sas3 = is_dir('/sys/module/mpt3sas');
$storcli  = '';
foreach (['/usr/local/sbin/storcli','/usr/local/sbin/storcli64','/usr/sbin/storcli','/usr/sbin/storcli64'] as $c) {
    if (is_executable($c)) { $storcli = $c; break; }
}
if ($storcli === '') {
    $w = trim((string) shell_exec('command -v storcli storcli64 2>/dev/null'));
    if ($w !== '') $storcli = strtok($w, "\n");
}
// Mirror what scripts/lib.sh hba_each ACTUALLY does at read time: ...
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

Only `$has_sas2` / `$has_sas3` are wrong. The `$storcli` lookup and the four-branch
chain are fine and stay; two of the four notes get reworded because they name the
driver as if it proved the generation.

`$has_sas2` is used once more, at `settings.php:124`, to show the lsiutil Port
row. Fixing the detection fixes that row for free — do not add a second condition
there.

### 5. Sysfs attributes this plan reads

Confirmed present on the reporter's `SAS9207-8i` (issue #3), and consistent with
what `mpt2sas`/`mpt3sas` publish per host:

| Attribute | Example | Used for |
|---|---|---|
| `proc_name` | `mpt2sas` | which personality claimed this host — **the predicate** |
| `board_name` | `SAS9207-8i` | naming the card in the refusal + the diagnostic row |
| `version_fw` | `20.00.07.00` | the diagnostic row only |

`proc_name` is load-bearing; the other two degrade to a generic phrasing when
unreadable, and every read in this plan is `2>/dev/null` / `@`-guarded.

### 6. Repo conventions that apply

- Error payloads are a single-key JSON object: `{"error":"..."}`. PHP renders
  `$data['error']` into `<div class="lu-error">` (`ajax_info.php:105-107`).
  Keep the shape.
- `lib.sh` is the one seam to hardware access — `find_storcli`, `use_storcli`,
  `hba_driver`, `hba_each` all live there and every composer sources it. A new
  detection predicate belongs there, next to them.
- Sysfs roots that tests need to fake are read through an overridable variable —
  see `SYS_PCI_ROOT` in `get_hba_info.sh:57` and how `tests/run.sh:60-68` builds
  a fake tree for it. This plan follows that pattern exactly with
  `SYS_SCSI_HOST`.
- Comments explain *why*, not *what* (`scripts/lib.sh` header).
- Deliberate simplifications carry a `ponytail:` comment naming the ceiling and
  the upgrade path (`get_metrics.sh:16-19`, `lib.sh:78-79`).
- `settings.php` deliberately avoids hardware enumeration so the page is instant
  (its own comment, lines 9-11). Reading a few sysfs files is consistent with
  that; **running storcli or lsiutil from that page is not.**

From `source/usr/local/emhttp/plugins/hbaviewer/CONTEXT.md` (the module glossary):

> **backend module** — `scripts/lib.sh` (`hba_each`)
> The one seam that chooses **storcli** (SAS3/3.5) vs **lsiutil** (SAS2). A tab
> composer declares only *what to read per controller* for each backend;
> `hba_each` owns *which backend* (`use_storcli`), *how many controllers*
> (`storcli_count`), the *driver string* (`hba_driver`) … Add a backend, or a
> per-tab read, in one place.

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

- `source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh` — add the predicate
- `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh` — use it in the guard; fix the message
- `source/usr/local/emhttp/plugins/hbaviewer/settings.php` — mirror it; fix two notes; add the diagnostic row
- `tests/run.sh` + one new file under `tests/expected/` — two golden cases over a fake `scsi_host` tree
- `plans/README.md` — status row + execution log

**Out of scope** (do NOT touch, even though they look related):

- **`hba_driver()`** — see "Current state 3". It is correct as written.
- **`get_metrics.sh` / `get_attached_drives.sh`** — they already key off
  `proc_name`. Converting them to call the new helper is a pure refactor of
  working code, with goldens to re-bless, for zero behaviour change. If you want
  it, it is a separate plan.
- **A third `sysfs` backend in `hba_each`.** Two earlier revisions deferred this
  pending evidence. The evidence arrived and killed it: lsiutil reads the card.
  Building it now would be a workaround for a problem that does not exist.
- **Anything that loads, blacklists, binds, or unbinds a kernel driver.** Unraid
  runs from a RAM-loaded squashfs with no compiler or kernel headers, so a plugin
  cannot persist modules; and rebinding a live storage controller via
  `/sys/bus/pci/drivers/*/unbind` would tear it out from under a mounted array.
  If any part of this work seems to require it, that is a STOP condition.
- **The firmware hex decode** (`parse/hba.sh`) — shipped and confirmed working by
  the reporter's screenshot.
- **The `.plg` install hooks.** An install-time driver check goes stale the moment
  the user changes hardware or Unraid changes kernels; the check belongs at read
  time, which is where this plan puts it.

## Git workflow

- Branch: `advisor/010-mpt3sas-sas2-diagnosis`, cut from `dev`
- One or two commits. Short imperative subject, no conventional-commit prefix,
  matching this repo's history. Suggested:
  `Detect SAS2 from proc_name so mpt3sas-bound cards are read, not refused`
- Do **not** put `Fixes #3` in the commit body unless you intend it to auto-close
  on push — that trailer already auto-closed #3 once (`eb7ccce`) and the reopen
  was manual. Close it by hand after hardware verification.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add the predicate to `lib.sh`

In `source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh`, insert directly
**after** `hba_driver()` (line 84) and **before** the `hba_each` comment block:

```bash
# Which mpt personality claimed each controller — one line per SAS host, empty if
# there is no LSI HBA. This, NOT /sys/module/*, is the honest SAS2-vs-SAS3 signal:
# the merged mpt3sas driver registers SAS2 cards under the mpt2sas personality, so
# issue #3's SAS9207-8i has no mpt2sas module at all yet reports proc_name=mpt2sas.
# SYS_SCSI_HOST is overridable so the suite can point it at a fixture tree.
hba_personalities() {
    local h p
    for h in "${SYS_SCSI_HOST:-/sys/class/scsi_host}"/host*/; do
        p=$(cat "${h}proc_name" 2>/dev/null)
        case "$p" in mpt3sas|mpt2sas|mptsas) echo "$p" ;; esac
    done
}

# True iff any controller is on the mpt2sas/mptsas personality — i.e. the bundled
# lsiutil 1.70 has a card it can reach. Verified on issue #3's mpt3sas-only box:
# /dev/mptctl exists there and lsiutil read the IOC temperature fine.
hba_has_sas2() { case "$(hba_personalities)" in *mpt2sas*|*mptsas*) return 0 ;; esac; return 1; }

# True iff any controller is on the mpt3sas personality — genuine SAS3/3.5, needs
# storcli. Both can be true on a box with one card of each generation.
hba_has_sas3() { case "$(hba_personalities)" in *mpt3sas*) return 0 ;; esac; return 1; }
```

Two details that matter:

- The `case` patterns cannot cross-match: `mpt2sas` does not contain the
  substring `mpt3sas`, and neither contains `mptsas`.
- `hba_personalities` prints **nothing** on a box with no LSI HBA (including your
  dev machine and CI). Both predicates are then false, which Step 2 relies on.

**Verify**: `bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh` → exit 0

**Verify**: the helpers see a fake tree —

```bash
T=$(mktemp -d); mkdir -p "$T/host0" "$T/host1"
printf 'mpt2sas\n' > "$T/host0/proc_name"
printf 'ahci\n'    > "$T/host1/proc_name"
SYS_SCSI_HOST="$T" bash -c '
  source source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh
  echo "personalities=[$(hba_personalities)]"
  hba_has_sas2 && echo sas2=yes || echo sas2=no
  hba_has_sas3 && echo sas3=yes || echo sas3=no'
rm -rf "$T"
```

expected exactly:

```
personalities=[mpt2sas]
sas2=yes
sas3=no
```

### Step 2: Fix the guard's condition and its message

In `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh`, replace
lines 81-88 (`ov_lsiutil` through `require_binary || return 1`) with:

```bash
ov_lsiutil() {
    # No storcli, and EVERY controller is on the mpt3sas personality: genuine
    # SAS3/3.5 hardware that the bundled lsiutil 1.70 cannot read. Keyed off
    # proc_name, not /sys/module — the merged driver reports proc_name=mpt2sas for
    # SAS2 cards even when only the mpt3sas module is loaded, and lsiutil reads
    # those fine (issue #3: /dev/mptctl present, IOC temperature returned). The
    # old /sys/module test refused those cards outright. hba_has_sas3 also keeps a
    # box with no HBA at all falling through to require_binary's clearer error.
    if [ -z "$(find_storcli)" ] && hba_has_sas3 && ! hba_has_sas2; then
        local h board=""
        for h in "${SYS_SCSI_HOST:-/sys/class/scsi_host}"/host*/; do
            case "$(cat "${h}proc_name" 2>/dev/null)" in mpt3sas|mpt2sas|mptsas) ;; *) continue ;; esac
            board=$(tr -d '\n' < "${h}board_name" 2>/dev/null)
            [ -n "$board" ] && break
        done
        printf '{"error":"%s is on the mpt3sas driver and the bundled lsiutil cannot read through it. Install storcli via the dkaser/unraid-storcli plugin (Community Applications), then reload."}' \
            "${board:-This controller}"
        return 1
    fi
    require_binary || return 1
```

Leave the rest of `ov_lsiutil` (the `IOC`/`BANNER`/`BOARD` captures and the
`parse/hba.sh` call, lines 89-96) untouched.

Behaviour table — the point of the change is row 2:

| Box | Old | New |
|---|---|---|
| SAS2 card, `mpt2sas` module loaded, no storcli | reads fine | reads fine |
| **SAS2 card, `mpt3sas` module only, no storcli (issue #3)** | **refused** | **reads fine** |
| SAS3 card, no storcli | refused, wrong reason | refused, honest reason, names the board |
| No HBA at all | falls through to `require_binary` | falls through to `require_binary` |
| Any box with working storcli | never reaches here | never reaches here |

**Verify**: `bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh` → exit 0

**Verify**: the false claim is gone —
`grep -c 'looks like a SAS3' source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh` → `0`

**Verify**: no `/sys/module` *test* remains in this file — the replacement's own
comments mention the string twice, so count only non-comment lines:
`grep '/sys/module' source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh | grep -vc '^\s*#'` → `0`

### Step 3: Lock both branches with goldens

`tests/run.sh` already builds a fake sysfs tree for `SYS_PCI_ROOT` at lines
60-68. Extend that block with a fake `scsi_host` tree, then add two cases.

First, **at line 60-61**, fold the new temp dir into the *existing* trap — do not
add a second `trap ... EXIT`, which would silently replace the first and leak
`$SYSPCI`:

```bash
SYSPCI=$(mktemp -d)
SYSHOST=$(mktemp -d)
trap 'rm -rf "$SYSPCI" "$SYSHOST"' EXIT
```

Then, immediately **after** the existing `check route-fallback` line (line 77),
add:

```bash
# Controller generation comes from proc_name, never from /sys/module — the merged
# mpt3sas driver reports proc_name=mpt2sas for SAS2 cards (issue #3). host9 is a
# non-SAS host that must be ignored by the filter.
mkdir -p "$SYSHOST/host0" "$SYSHOST/host9"
printf 'ahci\n' > "$SYSHOST/host9/proc_name"
# STORCLI must be EMPTY in both cases, never /nonexistent: find_storcli() echoes any
# non-empty override verbatim without checking it exists, so a bogus path makes the
# guard's `[ -z "$(find_storcli)" ]` false, short-circuits the personality check, and
# the case passes for the wrong reason. Empty means find_storcli probes PATH, so both
# cases assume no real storcli is installed on the machine running the suite.
#
# A SAS2 personality must NOT be refused: the guard stays silent and the composer
# reaches require_binary, so this reuses route-fallback's expectation. It fails if the
# predicate is ever inverted or dropped.
printf 'mpt2sas\n'    > "$SYSHOST/host0/proc_name"
printf 'SAS9207-8i\n' > "$SYSHOST/host0/board_name"
STORCLI= LSIUTIL=/nonexistent SYS_SCSI_HOST="$SYSHOST" \
check route-sas2-personality route_no_backend.json bash "$P/../get_hba_info.sh"
# mpt3sas personality only, no storcli: refuse, and name the board.
printf 'mpt3sas\n'    > "$SYSHOST/host0/proc_name"
printf 'SAS9300-8i\n' > "$SYSHOST/host0/board_name"
STORCLI= LSIUTIL=/nonexistent SYS_SCSI_HOST="$SYSHOST" \
check route-sas3-no-storcli route_sas3_no_storcli.json bash "$P/../get_hba_info.sh"
```

`LSI_CACHE=/dev/null` is already exported at line 72, so neither case reads or
writes the 60s cache — no ordering hazard between the two.

**`STORCLI=` must be empty in both cases, not `/nonexistent`** (learned during
execution). `find_storcli` honors a preset `$STORCLI` verbatim — `if [ -n "$STORCLI" ];
then echo "$STORCLI"; return; fi` — and never checks that the path exists. So
`STORCLI=/nonexistent` makes `[ -z "$(find_storcli)" ]` **false**, bash
short-circuits, and `hba_has_sas3` / `hba_has_sas2` are never evaluated: the case
then passes because storcli "exists", not because of the personality, and would
pass with the predicate deleted. Empty is the only way to express "no storcli" to
this helper. Note the consequence: with an empty override `find_storcli` falls
through to probing `PATH`, so **both** cases assume the machine running the suite
has no real storcli installed — a developer who has one will see these two fail for
an environment reason. Say so in the comment. (The neighbouring `route-fallback`
keeps `STORCLI=/nonexistent` on purpose: it routes through `use_storcli`, which
*does* run the binary and fails, so a bogus path is the right input there.)

Create `tests/expected/route_sas3_no_storcli.json` with **no trailing newline**
(match the existing `route_no_backend.json`):

```bash
printf '%s' '{"error":"SAS9300-8i is on the mpt3sas driver and the bundled lsiutil cannot read through it. Install storcli via the dkaser/unraid-storcli plugin (Community Applications), then reload."}' > tests/expected/route_sas3_no_storcli.json
```

**Verify**: `bash tests/run.sh` prints `PASS  route-sas2-personality` and
`PASS  route-sas3-no-storcli`, and `PASS  route-fallback` still.

**Verify** the golden actually bites — temporarily restore the old condition
(`[ -d /sys/module/mpt3sas ] && [ ! -d /sys/module/mpt2sas ]`) in place of
`hba_has_sas3 && ! hba_has_sas2`, re-run `bash tests/run.sh`, and confirm
`FAIL  route-sas3-no-storcli`. Then put the new condition back. A golden that
passes either way is not a test.

### Step 4: Mirror the predicate in `settings.php`

Replace `settings.php` lines 9-13 (the comment block plus the two `is_dir`
assignments) with:

```php
// Backend detection — controller generation via sysfs + storcli path lookup. Both
// are instant (no hardware enumeration), so the page never lags.
//
// Generation comes from each SCSI host's proc_name, NOT from which driver module
// is loaded, and this must stay in step with scripts/lib.sh hba_has_sas2/3. The
// merged mpt3sas driver registers SAS2 controllers under the mpt2sas personality,
// so issue #3's box has no mpt2sas module while its SAS9207-8i reports
// proc_name=mpt2sas. Keying off /sys/module called that card a SAS3 controller,
// demanded storcli for it, and hid the lsiutil Port row it actually needs.
$hw = [];          // one entry per SAS host, for the read-only diagnostic row
$has_sas2 = false; // any host on the mpt2sas/mptsas personality -> bundled lsiutil
$has_sas3 = false; // any host on the mpt3sas personality        -> needs storcli
foreach (glob('/sys/class/scsi_host/host*/') ?: [] as $h) {
    $drv = trim((string) @file_get_contents($h . 'proc_name'));
    if (!in_array($drv, ['mpt3sas', 'mpt2sas', 'mptsas'], true)) continue;
    if ($drv === 'mpt3sas') { $has_sas3 = true; } else { $has_sas2 = true; }
    $board = trim((string) @file_get_contents($h . 'board_name'));
    $fw    = trim((string) @file_get_contents($h . 'version_fw'));
    $hw[]  = ($board !== '' ? $board : 'unknown board') . " ($drv"
           . ($fw !== '' ? ", fw $fw" : '') . ')';
}
$hw_detail = $hw ? implode(' · ', $hw) : 'no mpt2sas/mpt3sas hosts found';
```

One loop, three outputs — do not compute `$hw_detail` in a second pass.
`glob()` returning `false` is absorbed by `?: []`; every read is `@`-guarded and
trimmed, so an unreadable attribute degrades to `unknown board` rather than a
warning printed into the page.

Then, in the `if ($storcli !== '')` chain, reword the three notes that name a
driver as if it proved the generation (the labels and the branch structure do not
change):

```php
if ($storcli !== '') {
    $backend_label = 'storcli';
    $backend_note  = $has_sas2
        ? 'storcli is installed and is tried first; the bundled lsiutil covers any SAS2 card it does not enumerate.'
        : 'SAS3 / SAS3.5 controller detected.';
} elseif ($has_sas2) {
    $backend_label = 'lsiutil (bundled)';
    $backend_note  = $has_sas3
        ? 'SAS2 controller detected. A SAS3 controller is also present and needs storcli.'
        : 'SAS2 controller detected.';
} elseif ($has_sas3) {
    $backend_label = 'storcli — NOT INSTALLED';
    $backend_note  = 'A controller was found on the mpt3sas driver, which the bundled lsiutil cannot read through. Install storcli via the dkaser/unraid-storcli plugin (Community Applications).';
} else {
    $backend_label = 'none detected';
    $backend_note  = 'No supported HBA controller (mpt2sas / mpt3sas) was found.';
}
```

The stale comment at old lines 22-28 ("Mirror what scripts/lib.sh hba_each
ACTUALLY does…") is superseded by the new block's comment — delete it.

On the reporter's box this yields `lsiutil (bundled)` / *"SAS2 controller
detected."*, and the lsiutil Port row at line 124 reappears.

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/settings.php` → "No syntax errors detected"

**Verify**: `grep -c "is_dir('/sys/module/" source/usr/local/emhttp/plugins/hbaviewer/settings.php` → `0`

**Verify**: `grep -c 'SAS3 / SAS3.5 controller detected (mpt3sas only)' source/usr/local/emhttp/plugins/hbaviewer/settings.php` → `0`

### Step 5: Add the read-only diagnostic row

So a user can see — and quote in a bug report — what the plugin actually
detected. Insert immediately after the existing "Access Method" row
(`settings.php:113-122`, the `lu-s-row` containing `$backend_label`), inside the
same `lu-s-card`:

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

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/settings.php` → "No syntax errors detected"

**Verify**: `grep -c 'hw_detail' source/usr/local/emhttp/plugins/hbaviewer/settings.php` → `2` (one assignment, one use)

### Step 6: Check the PHP detection against a fake sysfs tree

`settings.php` reads absolute paths, so it cannot be pointed at a fixture without
a refactor that is out of scope (and `SYS_SCSI_HOST` in Step 1 exists for the
shell side, which is where the behaviour lives). Verify the *logic* in isolation
by running the identical loop over a temp tree:

```bash
mkdir -p /tmp/hbav_sysfs/host0 /tmp/hbav_sysfs/host1 /tmp/hbav_sysfs/host2
printf 'mpt2sas'     > /tmp/hbav_sysfs/host0/proc_name    # SAS2 via the merged driver
printf 'SAS9207-8i'  > /tmp/hbav_sysfs/host0/board_name
printf '20.00.07.00' > /tmp/hbav_sysfs/host0/version_fw
printf 'mpt3sas'     > /tmp/hbav_sysfs/host1/proc_name    # board_name missing
printf 'ahci'        > /tmp/hbav_sysfs/host2/proc_name    # must be skipped

php -r '
$hw = []; $has_sas2 = false; $has_sas3 = false;
foreach (glob("/tmp/hbav_sysfs/host*/") ?: [] as $h) {
    $drv = trim((string) @file_get_contents($h . "proc_name"));
    if (!in_array($drv, ["mpt3sas","mpt2sas","mptsas"], true)) continue;
    if ($drv === "mpt3sas") { $has_sas3 = true; } else { $has_sas2 = true; }
    $board = trim((string) @file_get_contents($h . "board_name"));
    $fw    = trim((string) @file_get_contents($h . "version_fw"));
    $hw[]  = ($board !== "" ? $board : "unknown board") . " ($drv" . ($fw !== "" ? ", fw $fw" : "") . ")";
}
echo ($hw ? implode(" · ", $hw) : "no mpt2sas/mpt3sas hosts found") . "\n";
printf("sas2=%d sas3=%d\n", $has_sas2, $has_sas3);'
```

**Verify**: prints exactly

```
SAS9207-8i (mpt2sas, fw 20.00.07.00) · unknown board (mpt3sas)
sas2=1 sas3=1
```

That covers four behaviours at once: a fully-populated host, a host with a
missing `board_name`, a non-SAS host correctly skipped, and both flags set
independently on a mixed box.

Then the empty case:

```bash
rm -rf /tmp/hbav_sysfs && mkdir -p /tmp/hbav_sysfs
```

and re-run the `php -r` above.

**Verify**: prints `no mpt2sas/mpt3sas hosts found` then `sas2=0 sas3=0`

Clean up: `rm -rf /tmp/hbav_sysfs`

If `php` is not on PATH (it is absent on the maintainer's machine — `tests/run.sh`
falls back to Docker for the PHP half), run the same snippet in the container the
suite already uses, mounting the temp tree at the same path so the `glob()`
pattern is unchanged:

```bash
docker run --rm -v /tmp/hbav_sysfs:/tmp/hbav_sysfs php:8.2-cli php -r '<the same code>'
```

### Step 7: Lint and run the full suite

**Verify**: `find source tests -name '*.sh' -print0 | xargs -0 -r -n1 bash -n` → exit 0

**Verify**: `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` → exit 0

**Verify**: `bash tests/run.sh` → `--- all pass ---`, exit 0, including
`PASS  route-fallback`, `PASS  route-storcli`, `PASS  route-sas2-personality`
and `PASS  route-sas3-no-storcli`.

Do **not** run `UPDATE=1 bash tests/run.sh`. No existing golden should change; if
one does, that is a STOP condition, not something to re-bless.

### Step 8: Hardware check on the reporter's box (the only real proof)

The maintainer has no SAS2 card, so this is a request on issue #3, not a local
step. Post to the issue asking the reporter to install the build from this branch
**with storcli removed** — the configuration that currently errors — and confirm:

1. The Overview renders instead of showing the storcli error.
2. Settings > HBAviewer shows Access Method `lsiutil (bundled)`, *"SAS2 controller
   detected."*, and a **Detected Hardware** row reading roughly
   `SAS9207-8i (mpt2sas, fw 20.00.07.00)`.
3. The **lsiutil Port** row is visible again.
4. The temperature on the Performance tab still reads ~47 °C.

Only close issue #3 after that. Record the outcome in `plans/README.md`.

## Test plan

- **Two new goldens** (Step 3) are the regression net, one per branch of the new
  condition, driven through the real composer over a fake `scsi_host` tree. Step 3
  also requires proving they fail against the old condition.
- **Step 1's inline check** covers the three-way personality filter and the
  non-SAS host it must skip.
- **Step 6** stands in for a `settings.php` test — the file reads absolute paths,
  and the expected output is stated exactly, so it is pass/fail rather than a
  judgement call.
- **No new fixture directory.** Both trees are built in `tests/run.sh` at run
  time, deliberately: `tests/run.sh:55-59` documents that committed directory
  names containing colons corrupt on Windows checkouts. `host0/` is colon-free,
  but consistency with the neighbouring `SYSPCI` block is worth more than saving
  four `printf`s.
- `bash tests/run.sh` must stay green with **no re-blessed goldens**.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'looks like a SAS3' source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh` prints `0`
- [ ] `grep '/sys/module' source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh | grep -vc '^\s*#'` prints `0` (comments may mention it; no *test* may)
- [ ] `grep -c "is_dir('/sys/module/" source/usr/local/emhttp/plugins/hbaviewer/settings.php` prints `0`
- [ ] `grep -c 'SAS3 / SAS3.5 controller detected (mpt3sas only)' source/usr/local/emhttp/plugins/hbaviewer/settings.php` prints `0`
- [ ] `grep -c 'hw_detail' source/usr/local/emhttp/plugins/hbaviewer/settings.php` prints `2`
- [ ] `grep -c 'hba_has_sas2\|hba_has_sas3\|hba_personalities' source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh` prints `3`
- [ ] `grep -c '/sys/module/mpt3sas/version' source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh` prints `1` — `hba_driver` was left alone
- [ ] Step 1's inline check printed `personalities=[mpt2sas]`, `sas2=yes`, `sas3=no`
- [ ] Step 3's negative check was done: the old condition made `route-sas3-no-storcli` FAIL
- [ ] Step 6 printed exactly the two expected outputs
- [ ] `find source tests -name '*.sh' -print0 | xargs -0 -r -n1 bash -n` exits 0
- [ ] `find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l` exits 0
- [ ] `bash tests/run.sh` exits 0, prints `--- all pass ---`, and includes `PASS  route-fallback`, `PASS  route-sas2-personality`, `PASS  route-sas3-no-storcli`
- [ ] `git status --porcelain` shows exactly: `lib.sh`, `get_hba_info.sh`, `settings.php`, `tests/run.sh`, the new `tests/expected/route_sas3_no_storcli.json`, and `plans/README.md`
- [ ] `git diff -- tests/expected/` shows **only** the new file — no existing golden re-blessed
- [ ] `plans/README.md` status row for 010 updated, and the two stale references to
      010 being "gated on evidence" (dependency notes, execution log) corrected
- [ ] Step 8's request posted to issue #3

## STOP conditions

Stop and report back (do not improvise) if:

- The drift check prints anything. The line numbers in this plan are from
  `62fe791`.
- An **existing** golden changes. The only intended behaviour changes are (a) a
  SAS2-personality box no longer being refused and (b) the refusal's wording. If
  `route-storcli`, `hba-normal`, `cache-temps-*` or any other case moves, you have
  changed something else.
- Step 3's negative check *passes* with the old condition restored — the new
  goldens are not exercising the guard, so fix the test before trusting it.
- You conclude the fix requires loading, blacklisting, binding, or unbinding a
  kernel driver. It does not, and none of those are things a plugin may safely do
  on Unraid — see "Out of scope".
- You find yourself adding a `sysfs` backend to `hba_each`. That follow-up is
  cancelled, not deferred; the evidence in "READ FIRST" is why. Report the
  temptation; do not act on it.
- `hba_has_sas2` and `hba_has_sas3` do not agree with `settings.php`'s
  `$has_sas2` / `$has_sas3` on the same inputs. They are two implementations of
  one predicate and they must not drift — see "Maintenance notes".

## Follow-ups this plan does not do

1. **`IOCTemperature` with `Units: 0x00`.** `parse/hba.sh:17-18` converts
   `IOCTemperature` whenever the line is present and never looks at
   `IOCTemperatureUnits`. Issue #3's output proves `0x00` means "no such sensor"
   (`BoardTemperature: 0x0000` / `BoardTemperatureUnits: 0x00` on a card whose IOC
   sensor reads `0x2F` / `0x02`). A sensorless card that prints
   `IOCTemperature: 0x0000` with units `0x00` would therefore be reported as
   **0 °C**, not "no sensor" — and 0 °C is below the alert threshold, so it renders
   as a healthy reading. Whether any card actually prints that is unverified;
   `tests/fixtures/hba_ioc_notemp.txt` omits the line entirely. The fix is two
   lines in `parse/hba.sh` plus one fixture, it is independent of everything above,
   and it is a **separate concern from detection** — keep it out of this branch.
2. **Three copies of the `proc_name` filter** (`get_metrics.sh:27`,
   `get_attached_drives.sh:46`, and now `lib.sh`). Collapsing the first two onto
   `hba_personalities` is correct and boring, but it is a refactor of working code
   with goldens to re-verify and zero behaviour change. Do it when one of those
   files is being edited for another reason.
3. **A `sysfs` backend for `hba_each`** — **cancelled**, see "READ FIRST".

## Maintenance notes

- **`settings.php` and the shell must keep agreeing.** They have now disagreed
  twice: first the settings page warned whenever `mpt3sas` was present while the
  composer only refused when `mpt2sas` was absent (the spurious storcli prompt in
  issue #3), then both keyed off module presence and refused a readable SAS2 card.
  PHP cannot source `lib.sh`, so the predicate exists twice by necessity — the
  `in_array([...'mpt3sas','mpt2sas','mptsas'...])` list and the `hba_has_sas*`
  `case` patterns are the same decision written in two languages. Change one,
  change the other, in the same commit.
- **The guard and the backend seam ask two different storcli questions.** This
  guard tests `[ -z "$(find_storcli)" ]` — "is a storcli binary on disk" — while
  `hba_each` routes on `use_storcli`, which additionally *runs* it and requires
  `Number of Controllers > 0`. So on a box where storcli is installed but
  enumerates nothing, `hba_each` picks lsiutil and this guard stays silent,
  meaning a SAS3-only card there falls through to lsiutil and renders an empty
  overview instead of the "install storcli" message. Narrow enough to leave alone;
  if it ever surfaces, the fix is to make the guard ask `use_storcli` too, not to
  duplicate the count parsing.
- **`proc_name` is the generation signal. `board_name` is the card identity.
  `/sys/module/*` is neither** — it only says which driver binary is loaded, which
  is exactly what `hba_driver()` reports and all it should ever be used for.
- **The guard now describes a real capability limit, not a guess.** `mpt3sas`
  personality + no storcli = nothing on the box can read that card. If storcli
  support ever widens, or a card is found that lsiutil *can* read through the
  mpt3sas personality, the guard is what changes — and it should be deleted, not
  reworded.
- **What a reviewer should scrutinise**: that `hba_has_sas3` is part of the
  condition (without it, a box with no HBA gets the storcli error instead of
  `require_binary`'s clearer one, and `route-fallback` fails); that
  `hba_driver()` is untouched; and that the `printf` produces valid JSON when
  `board_name` is unusual. Board names are alphanumeric plus hyphens in every
  sample seen so far — if one ever contains a quote, the payload breaks the same
  way `collect_smart.sh:10-11` documents for drive models.

## History

Kept so the reasoning — and two wrong turns — stay on the record.

- **v1 (2026-07-26, at `eb7ccce`)** — treated this as a pure wording bug. Argued
  from the reporter's working screenshots that `mpt2sas` must be loaded on their
  box, therefore the mpt3sas-only case was rare, therefore only the message needed
  fixing. Declared the guard condition correct and out of scope.
- **v2 (2026-07-28)** — the sysfs listing arrived and broke the inference: their
  Overview had rendered because **storcli was installed**, not because `mpt2sas`
  was loaded. Two paths led to the same observation and the evidence was
  consistent with both. *"The feature worked, therefore condition X held"* is only
  sound when X is the **only** path to that outcome. Detection was rewritten
  around `proc_name`, but the guard condition was still declared correct, and a
  `sysfs` backend was queued behind one open question: can lsiutil read through
  the merged driver?
- **v3 (2026-07-29, this revision)** — the reporter ran the bundled binary
  directly, bypassing the guard, and it read the card. So the guard was refusing
  working hardware, the `sysfs` backend was a cure for a non-existent disease, and
  the condition — protected as "correct and out of scope" through both earlier
  revisions — was the actual bug. The lesson worth keeping: **a guard that
  prevents anyone from testing its own premise will outlive the premise.** When a
  refusal is inferred rather than measured, the cheapest possible experiment is to
  run the thing the guard is protecting you from.
