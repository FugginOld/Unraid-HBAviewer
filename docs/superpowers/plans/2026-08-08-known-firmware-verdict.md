# Known-Firmware Verdict Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tell a user whether the HBA in their server is running current IT firmware, from an index bundled with the plugin.

**Architecture:** A hand-maintained JSON index ships in the `.txz`. Two new sysfs-derived facts (`topology`, `subvendor_id`) are collected by the existing overview composer and emitted in its per-controller JSON. A pure-function PHP module compares the detected version against the index behind seven suppression gates and returns a verdict. Two surfaces render it: a one-line clause on the always-visible Overview card, and a full block on the firmware page.

**Tech Stack:** Bash 4 (composers and parsers), PHP 8.2 (lookup and render), plain JS (firmware page), golden-file and assert-based tests. No new dependencies, no network access.

**Spec:** `docs/superpowers/specs/2026-08-08-known-firmware-verdict-design.md`

## Global Constraints

- **No new dependencies.** Core PHP, Bash, GNU awk only. Nothing added to the plugin's runtime.
- **No network access at runtime.** The index is read from disk. Nothing fetches.
- **PHP 8.2** — the version Unraid 7.x ships. Must pass `phpstan --level=3`.
- **ShellCheck at `-S warning`** with the repo's four exclusions: `SC1090,SC2034,SC2207,SC1007`.
- **House pattern for PHP modules:** pure functions at the top, then `if (PHP_SAPI === 'cli') return;`, then the HTTP dispatch. That guard is what lets a test `require` an endpoint without triggering its dispatch — so it belongs in a file that HAS a dispatch. A pure library gets the functions and no guard; a trailing guard with nothing after it is a no-op.
- **A new PHP test must be registered in BOTH invocation lines of `tests/run_php.sh`** — the local-`php` line and the Docker fallback. Missing the second is how a test silently never runs in CI.
- **A new shell test must be added in THREE places in `tests/run.sh`** — the `bash <name>.sh; <name>_fail=$?` invocation, and the `[ $<name>_fail -eq 0 ]` clause in the final gate at line 233. Missing the gate clause means the test can fail while the suite reports `--- all pass ---`.
- **Never run `UPDATE=1 bash run.sh`.** It rewrites every golden. Regenerate individual goldens with the explicit commands given in Task 2.
- **No committed fixture may contain `:` in a filename.** Windows/NTFS forbids it and MSYS silently substitutes a lookalike. Sysfs trees with SCSI addresses are built at runtime under `mktemp -d`.
- **Mutation-test every new guard.** Break it deliberately, confirm exactly the intended case fails, restore.
- **`build.sh` tars `source/` wholesale**, so a new file under `source/usr/local/emhttp/plugins/hbaviewer/` ships with no packaging change. The spec's §3 mention of "added to the `.plg` file list" is wrong — there is no file list. No `.plg` edit is needed anywhere in this plan.

**Verification command for every task:** `bash tests/run.sh` must end in `--- all pass ---`.

---

### Task 1: The firmware index and its shape guard

**Files:**
- Create: `source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json`
- Create: `tests/firmware_index_test.php`
- Modify: `tests/run_php.sh:10` and `tests/run_php.sh:16` (both invocation lines)

**Interfaces:**
- Consumes: nothing.
- Produces: `data/known-firmware.json` with top-level keys `schema_version` (int), `updated` (string `YYYY-MM-DD`), `boards` (object keyed by board name), `no_it_firmware` (object keyed by chip), `multipath_track` (object with `affected_boards` array), `branches` (object keyed by branch name, each with a `terminal` bool). Each board carries `chip`, `generation`, `backend`, `it_capable` (bool), `latest_it` (string, when `it_capable`), `branch`, `confidence` (one of `confirmed` / `observed-floor` / `weak`), and optionally `notes` and `rom_profiles`.

- [ ] **Step 1: Create the data directory and copy the draft index**

```bash
cd "$(git rev-parse --show-toplevel)"
mkdir -p source/usr/local/emhttp/plugins/hbaviewer/data
cp "plans/firmware index/files/known-firmware.json" \
   source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json
```

- [ ] **Step 2: Apply the three hardware-settled corrections**

Edit `source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json`:

1. **Delete the whole `unverified_chips` block.** Both entries are typos. `build-firmware-manifest.py` maps 9305-24i to chip `SAS3324` and 9305-16i to `SAS3316`; the JSON already says `SAS3224` and `SAS3216`, and the live card in the 2026-08-08 bundle reports `Adapter Type = SAS3224(A1)`. There is no `SAS3324`.

2. **Add a confirmation note to `SAS9305-24i`.** Replace its `notes` value with:

```
"NOT interchangeable with the 16i image despite the shared P16.12 label. SAS3224 confirmed as an IT-capable IOC (not RAID-on-Chip) from a live card: lsiutil reports MPTFW-15.00.00.00-IT with 15 JBOD drives attached."
```

3. **Add an inference note to `SAS9305-16i`.** Replace its `notes` value with (the board has no `notes` key today — add one):

```
"SAS3216 treated as an IT-capable IOC by symmetry with the confirmed SAS3224, not from a card. Downgrade this board's confidence if a 9305-16i ever contradicts it."
```

- [ ] **Step 3: Write the failing shape test**

Create `tests/firmware_index_test.php`:

```php
<?PHP
/* Runnable checks for the bundled firmware index and the lookup that reads it.
     php tests/firmware_index_test.php  ->  "firmware_index: all pass" (exit 0)

   The index is hand-maintained data that drives a "you should reflash" claim,
   so a malformed entry is not a cosmetic problem. These assertions cover the
   invariants a hand edit can break silently: a board that claims IT capability
   with no version to compare against, a branch reference with no branch, and
   the two chip typos that hardware has now settled. */

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

$INDEX = __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json';

check('index file exists', is_readable($INDEX));
$idx = json_decode((string) file_get_contents($INDEX), true);
check('index parses as JSON', is_array($idx));
check('schema_version is 1', ($idx['schema_version'] ?? null) === 1);
check('updated is a YYYY-MM-DD date', (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($idx['updated'] ?? '')));
check('boards is a non-empty object', !empty($idx['boards']) && is_array($idx['boards']));

// An IT-capable board with no latest_it has nothing to compare against, so it
// would silently return 'unknown' forever rather than failing loudly here.
$noVersion = [];
foreach ($idx['boards'] as $name => $b) {
    if (!empty($b['it_capable']) && empty($b['latest_it'])) $noVersion[] = $name;
}
check('every it_capable board has a latest_it', $noVersion === []);
if ($noVersion) echo "      " . implode(', ', $noVersion) . "\n";

// A branch reference with no branch entry makes 'terminal' silently false,
// which downgrades an amber verdict to informational without saying why.
$badBranch = [];
foreach ($idx['boards'] as $name => $b) {
    if (!empty($b['branch']) && !isset($idx['branches'][$b['branch']])) $badBranch[] = "$name -> {$b['branch']}";
}
check('every board branch exists in branches', $badBranch === []);
if ($badBranch) echo "      " . implode(', ', $badBranch) . "\n";

$tiers = ['confirmed', 'observed-floor', 'weak'];
$badTier = [];
foreach ($idx['boards'] as $name => $b) {
    if (!in_array($b['confidence'] ?? '', $tiers, true)) $badTier[] = $name;
}
check('every board has a known confidence tier', $badTier === []);
if ($badTier) echo "      " . implode(', ', $badTier) . "\n";

// Settled by the 2026-08-08 bundle: the live 9305-24i reports SAS3224 and runs
// MPTFW-15.00.00.00-IT. SAS3324 does not exist; it was a typo in the manifest
// builder that leaked into an "unconfirmed, may be RAID-on-Chip" list.
check('SAS9305-24i is present and IT-capable', !empty($idx['boards']['SAS9305-24i']['it_capable']));
check('SAS9305-24i chip is SAS3224', ($idx['boards']['SAS9305-24i']['chip'] ?? '') === 'SAS3224');
check('the unverified_chips typo block is gone', !isset($idx['unverified_chips']));

// The most useful field in the file, and the one a UI change could drop.
check('SAS9300-8i carries its SATA controller-reset note',
      str_contains((string) ($idx['boards']['SAS9300-8i']['notes'] ?? ''), 'controller-reset'));

echo $fails === 0 ? "firmware_index: all pass\n" : "firmware_index: FAILURES\n";
exit($fails === 0 ? 0 : 1);
```

- [ ] **Step 4: Run it and verify it passes**

```bash
cd "$(git rev-parse --show-toplevel)"
php tests/firmware_index_test.php || \
  MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W 2>/dev/null || pwd):/app" -w /app php:8.2-cli php tests/firmware_index_test.php
```

Expected: every line `PASS`, final line `firmware_index: all pass`.

- [ ] **Step 5: Mutation-test the shape guard**

Temporarily set `"it_capable": true` with `latest_it` deleted on `SAS9211-8i`, re-run, and confirm **only** `every it_capable board has a latest_it` fails. Restore the file afterwards.

- [ ] **Step 6: Register the test in BOTH lines of run_php.sh**

In `tests/run_php.sh`, append ` && php tests/firmware_index_test.php` to the end of the command on **line 10** (the local-`php` branch) and to the end of the `sh -c '...'` string on **line 16** (the Docker fallback). Both. A test present in only the first never runs in CI.

- [ ] **Step 7: Run the full suite**

```bash
bash tests/run.sh
```

Expected: `firmware_index: all pass` appears, and the run ends `--- all pass ---`.

- [ ] **Step 8: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json \
        tests/firmware_index_test.php tests/run_php.sh
git commit -m "Bundle the known-firmware index, with the three corrections hardware settled

The index is hand-maintained data that will drive a \"you should reflash\" claim,
so it ships with a shape guard rather than on trust: an it_capable board with no
version to compare against, or a branch reference with no branch, both fail here
instead of silently degrading to no verdict.

Three corrections come from the 2026-08-08 bundle. SAS3324 and SAS3316 were
typos that leaked from the manifest builder into an \"unconfirmed, possibly
RAID-on-Chip\" list; the live card reports SAS3224 and runs MPTFW-15.00.00.00-IT
with 15 JBOD drives behind it, which settles it as an IT-capable IOC. SAS3216 is
marked inferred by symmetry rather than confirmed, because no such card has been
seen."
```

---

### Task 2: The composer collects topology and subvendor_id

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh` (add `_pci_dir_of_host`, `_first_sas_host`, `hba_topology`, `hba_subvendor`)
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_health.sh` (remove the two moved functions)
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh` (both backends export the two vars)
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/hba.sh` (emit the two fields)
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh` (emit the two fields)
- Modify: `tests/health_sh_test.sh:31` (`_pci_dir_of_host` now comes from lib.sh)
- Create: `tests/topology_test.sh`
- Modify: `tests/run.sh` (invoke the new test, and add it to the final gate at line 233)
- Modify: 18 files under `tests/expected/` (regenerated goldens)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: two new string fields in every controller object of `get_hba_info.sh`'s JSON — `"topology"` (`"internal"` or `"unknown"`) and `"subvendor_id"` (a lowercase `0x`-prefixed hex string, or `""` when unreadable). Also three shell functions in `lib.sh`: `_pci_dir_of_host <hostnum>` → prints a sysfs PCI directory path or nothing; `hba_topology <hostnum>` → prints `internal` or `unknown`; `hba_subvendor <pcidir>` → prints `0x1000`-style id or nothing.

**Why env vars, not positional args:** the parsers are pure stdin/file filters and their positional slots are already consumed (`hba.sh` takes 5, `storcli_overview.sh` takes 6). Eighteen golden invocations in `run.sh` would need reshuffling, several of which use `< <(...)` or `bash -c` wrappers where extra positionals are awkward. The repo already injects composer-supplied values by environment — `SYS_SCSI_HOST`, `SYS_PCI_ROOT`, `LSI_CACHE`, `STORCLI`, `LSIUTIL` — so this follows the house convention.

- [ ] **Step 1: Write the failing topology test**

Create `tests/topology_test.sh`:

```bash
#!/bin/bash
# Self-asserting checks for hba_topology and hba_subvendor in lib.sh.
#
# Topology decides whether a firmware verdict is shown at all. Broadcom ships a
# separate multi-path firmware track for the 9300/9305/9400/9405W with its own
# version numbering, so comparing a multipath card against the standard track
# reports a correctly configured card as six major versions behind. The index
# suppresses those boards unless topology is known to be internal -- which,
# without this function, is never, and the feature renders nothing on the most
# common cards.
#
# The trees are built at runtime under mktemp -d, never committed: every path
# here contains a colon, which Windows/NTFS forbids and MSYS silently mangles.
#
#   bash tests/topology_test.sh   ->  "topology: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
SRC="../source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh"
fail=0
eq() {  # name  want  got
    if [ "$2" = "$3" ]; then echo "PASS  $1"; else echo "FAIL  $1 -- want '$2', got '$3'"; fail=1; fi
}

FN=$(sed -n '/^hba_topology()/,/^}/p' "$SRC"; sed -n '/^hba_subvendor()/,/^}/p' "$SRC")
[ -n "$FN" ] || { echo "FAIL  hba_topology/hba_subvendor not found in $SRC"; exit 1; }

ROOT=$(mktemp -d)
trap 'rm -rf "$ROOT"' EXIT

# host9: the maintainer's reporter shape -- a 9305-24i with 15 SATA drives all
# direct-attached, no expander anywhere. This is the case that must produce a
# verdict; if it does not, the feature is invisible on the card that motivated it.
mkdir -p "$ROOT/dev" "$ROOT/exp"
for n in $(seq 0 14); do mkdir -p "$ROOT/dev/end_device-9:$n"; done

# host3: a card behind an expander. Two signals, either of which is enough:
# an expander-H:N entry, and end_device-H:N:M three-component children.
mkdir -p "$ROOT/exp/expander-3:0"
mkdir -p "$ROOT/dev/end_device-3:0" "$ROOT/dev/end_device-3:0:0" "$ROOT/dev/end_device-3:0:1"

# host7: present but with nothing attached at all.
# host8: absent from both trees entirely.

top() { SYS_SAS_DEVICE="$ROOT/dev" SYS_SAS_EXPANDER="$ROOT/exp" \
        bash -c "$FN"$'\n''hba_topology "$1"' _ "$1"; }

eq "direct-attached card is internal"          "internal" "$(top 9)"
eq "card behind an expander is unknown"        "unknown"  "$(top 3)"
eq "card with nothing attached is unknown"     "unknown"  "$(top 7)"
eq "absent card is unknown"                    "unknown"  "$(top 8)"

# Another host's expander must not suppress this card. A two-HBA box where one
# card sits behind an expander would otherwise silence both.
eq "host9 stays internal despite host3's expander" "internal" "$(top 9)"

# subvendor: a plain sysfs attribute read, with the failure case being the one
# that matters -- an unreadable file must yield empty, never a bare 0x0.
PCI=$(mktemp -d); trap 'rm -rf "$ROOT" "$PCI"' EXIT
mkdir -p "$PCI/card" "$PCI/bare"
printf '0x1000\n' > "$PCI/card/subsystem_vendor"
sub() { bash -c "$FN"$'\n''hba_subvendor "$1"' _ "$1"; }
eq "subvendor read from sysfs"        "0x1000" "$(sub "$PCI/card")"
eq "missing attribute yields empty"   ""       "$(sub "$PCI/bare")"
eq "absent directory yields empty"    ""       "$(sub "$PCI/nope")"

[ $fail -eq 0 ] && echo "topology: all pass"
exit $fail
```

- [ ] **Step 2: Run it and verify it fails**

```bash
cd "$(git rev-parse --show-toplevel)"
bash tests/topology_test.sh
```

Expected: `FAIL  hba_topology/hba_subvendor not found in ../source/.../lib.sh`, exit 1.

- [ ] **Step 3: Move the two shared sysfs helpers into lib.sh and add the two new functions**

Cut two blocks verbatim from `scripts/get_hba_health.sh` — `_pci_dir_of_host` with its comment (lines 115-128) and `_first_sas_host` with its comment (lines 163-173) — append both to `scripts/lib.sh`, then add the two new functions after them. `get_hba_health.sh` sources `lib.sh`, so it keeps working; `health_sh_test.sh` extracts `_pci_dir_of_host` and must be repointed (Step 4), but does not extract `_first_sas_host`, so nothing else changes:

```bash
# The PCI device behind a scsi_host. lsiutil never reports a PCI address (and
# unlike storcli there is no line to parse), but the kernel already knows it:
# /sys/class/scsi_host/hostN resolves into the device tree under the card, so
# walk up until a dir that publishes link state appears. Issue #14 — a SAS2308
# negotiated at x4 in a chipset slot, with the card's x8 maximum sitting in
# sysfs the whole time while the plugin reported no maximum at all.
# Lives here rather than in get_hba_health.sh because the overview composer now
# needs the same walk to reach subsystem_vendor.
_pci_dir_of_host() {   # $1 = scsi host number
    local d
    d=$(readlink -f "${SYS_SCSI_HOST:-/sys/class/scsi_host}/host$1" 2>/dev/null)
    while [ -n "$d" ] && [ "$d" != "/" ] && [ "$d" != "." ]; do
        [ -r "$d/current_link_width" ] && { printf '%s' "$d"; return 0; }
        d=$(dirname "$d")
    done
}

# First SAS host (mpt2sas/mpt3sas/mptsas) — same personality filter as
# hba_personalities below, but keeping the host NUMBER. The bundled lsiutil
# binary only ever addresses one controller.
# Lives here rather than in get_hba_health.sh because the overview composer now
# needs the same lookup to reach this card's topology and subsystem_vendor.
_first_sas_host() {
    local h
    for h in "${SYS_SCSI_HOST:-/sys/class/scsi_host}"/host*/; do
        case "$(cat "${h}proc_name" 2>/dev/null)" in
            mpt3sas|mpt2sas|mptsas) basename "$h" | sed 's/^host//'; return ;;
        esac
    done
}

# Is this controller directly attached, or is there an expander in the path?
#
# Broadcom publishes a SEPARATE multi-path firmware track for the 9300, 9302,
# 9305, 9400 and 9405W, with its own version numbering — a card on that track
# correctly runs a version far below the standard branch. Comparing the two
# tracks reports a working multipath card as badly out of date, and acting on
# that destroys the configuration. So the firmware verdict is suppressed unless
# the card can be shown to be internal, and this is that proof.
#
# Two independent signals, either sufficient to disqualify: an expander device
# for this host, or any three-component end_device-H:N:M child (a device behind
# something that numbers its own PHYs). The two-vs-three component rule is the
# same one get_hba_health.sh's _phys_json uses to keep an expander's PHYs out of
# a controller's own error counts (issue #12).
#
# Scoped to ONE host: a box with two HBAs, one behind an expander, must not have
# that expander silence the other card. An empty tree is "unknown", not
# "internal" — a card with nothing attached proves nothing about topology.
hba_topology() {   # $1 = scsi host number -> "internal" | "unknown"
    local d n found=0
    for d in "${SYS_SAS_EXPANDER:-/sys/class/sas_expander}"/expander-"${1}":*; do
        [ -e "$d" ] && { printf 'unknown'; return; }
    done
    for d in "${SYS_SAS_DEVICE:-/sys/class/sas_device}"/end_device-"${1}":*; do
        [ -e "$d" ] || continue
        found=1
        n=$(basename "$d")
        case "${n#end_device-}" in *:*:*) printf 'unknown'; return ;; esac
    done
    [ "$found" -eq 1 ] && printf 'internal' || printf 'unknown'
}

# PCI subsystem vendor for a card, from its sysfs device dir. 0x1000 is a
# generic Broadcom board; anything else is an OEM rebrand (IBM M1015, Dell
# H200/H310 and friends) whose NVDATA and BIOS differ, where reaching a generic
# firmware version is a CROSSFLASH rather than an upgrade. Getting this wrong
# tells a user to perform a materially riskier operation than the one described,
# so an unreadable attribute must yield empty and suppress the verdict — never a
# default that happens to look generic.
hba_subvendor() {   # $1 = sysfs PCI device dir
    local v
    v=$(cat "$1/subsystem_vendor" 2>/dev/null) || return 0
    printf '%s' "${v//[[:space:]]/}"
}
```

Then **delete** both originals and their comments from `get_hba_health.sh`: `_pci_dir_of_host` (lines 115-128) and `_first_sas_host` (lines 163-173). That script already sources `lib.sh`, so both calls keep resolving. Verify no copy remains: `grep -c '^_first_sas_host()\|^_pci_dir_of_host()' scripts/get_hba_health.sh` must print `0`.

- [ ] **Step 4: Point health_sh_test.sh at the new home**

`tests/health_sh_test.sh:31` currently reads:

```bash
PD=$(sed -n '/^_pci_dir_of_host()/,/^}/p' "$SRC")
```

Change it to extract from lib.sh instead, and add the source path above it:

```bash
LIB="../source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh"
PD=$(sed -n '/^_pci_dir_of_host()/,/^}/p' "$LIB")
```

- [ ] **Step 5: Run both tests and verify topology passes and health still passes**

```bash
bash tests/topology_test.sh
bash tests/health_sh_test.sh
```

Expected: `topology: all pass` and `health_sh: all pass`, both exit 0.

- [ ] **Step 6: Mutation-test the topology guard**

Change `hba_topology`'s final line to `printf 'internal'` unconditionally, re-run `bash tests/topology_test.sh`, and confirm **three** assertions fail: the expander case, the empty case, and the absent case. Restore.

- [ ] **Step 7: Register the new test in run.sh — all three places**

In `tests/run.sh`, after the `drives_sysfs_test.sh` block (line 210), add:

```bash

echo
echo "=== topology / subvendor tests ==="
bash topology_test.sh; topology_fail=$?
```

Then in the final gate on **line 233**, add ` && [ $topology_fail -eq 0 ]` to the condition. Without that clause the test can fail while the suite prints `--- all pass ---`.

- [ ] **Step 8: Emit the two fields from the lsiutil parser**

In `scripts/parse/hba.sh`, immediately after the `ALERT="${4:-80}"` line near the top, add:

```bash
# Injected by the composer, which reads them from sysfs — this file stays a pure
# filter with no hardware access. Defaults are the suppressing values: an
# unstated topology must never read as "internal", and an unstated subvendor
# must never read as generic Broadcom.
TOPOLOGY="${LSI_TOPOLOGY:-unknown}"
SUBVENDOR="${LSI_SUBVENDOR:-}"
```

Then in the `cat <<EOF` JSON block, add two lines immediately after the `"pci_location"` line:

```
  "topology": "${TOPOLOGY}",
  "subvendor_id": "${SUBVENDOR}",
```

- [ ] **Step 9: Emit the two fields from the storcli parser**

In `scripts/parse/storcli_overview.sh`, after the `PWRM="${6:-}"` line, add the identical block:

```bash
# Injected by the composer, which reads them from sysfs — this file stays a pure
# filter with no hardware access. Defaults are the suppressing values: an
# unstated topology must never read as "internal", and an unstated subvendor
# must never read as generic Broadcom.
TOPOLOGY="${LSI_TOPOLOGY:-unknown}"
SUBVENDOR="${LSI_SUBVENDOR:-}"
```

Then in the single-line JSON at the bottom, insert `"topology":"${TOPOLOGY}","subvendor_id":"${SUBVENDOR}",` immediately after `"pci_location":"${PCI}",`.

- [ ] **Step 10: Set the two vars in the composer, both backends**

In `scripts/get_hba_info.sh`, inside `ov_storcli()`, immediately before the final `printf ... | bash "$DIR/parse/storcli_overview.sh" ...` line (line 95), add:

```bash
    # storcli's own output carries SubVendor Id, but read it from sysfs for both
    # backends so there is one source of truth and one thing the diagnostic
    # bundle has to capture. $dir is already resolved above from PCI Address.
    LSI_TOPOLOGY=$(hba_topology "$1")
    LSI_SUBVENDOR=$([ -n "$dir" ] && hba_subvendor "$dir")
    export LSI_TOPOLOGY LSI_SUBVENDOR
```

In `ov_lsiutil()`, immediately before the final `bash "$DIR/parse/hba.sh" ...` line (line 127), add:

```bash
    # lsiutil reports no PCI address at all, so the card is reached through its
    # scsi_host — the same walk issue #14 added for the PCIe link maximum.
    local hnum pdir
    hnum=$(_first_sas_host)
    pdir=$([ -n "$hnum" ] && _pci_dir_of_host "$hnum")
    LSI_TOPOLOGY=$([ -n "$hnum" ] && hba_topology "$hnum" || printf 'unknown')
    LSI_SUBVENDOR=$([ -n "$pdir" ] && hba_subvendor "$pdir")
    export LSI_TOPOLOGY LSI_SUBVENDOR
```

- [ ] **Step 11: Run the suite and watch 26 goldens fail**

```bash
bash tests/run.sh
```

Expected `FAIL` on exactly these 26 and nothing else: `storcli-overview`, `storcli-overview-pcie`, `storcli-overview-noencl-ugood`, `storcli-overview-9305`, `storcli-overview-chiparg`, `rollup-faildrive`, `rollup-phyerr`, `rollup-healthy`, `band-65`, `band-66`, `band-75`, `band-76`, `band-85`, `band-86`, `band-95`, `band-96`, `phy-under-floor`, `phy-over-floor`, `hba-normal`, `hba-notemp`, `hba-zerotemp`, `hba-p16`, `hba-gen1`, `hba-mode-it`, `hba-mode-ir`, `hba-mode-noport`.

Each diff must show **only** the two added keys. **This is the expected state** — the goldens are the record of the JSON shape and the shape genuinely changed. A failure outside this list is a real regression; stop.

- [ ] **Step 12: Regenerate exactly those goldens, individually**

Never `UPDATE=1 bash run.sh` — it rewrites all of them and a real regression would be blessed along with the intended change. Run this, which mirrors `check()`'s own write (`printf '%s'`, no trailing newline):

```bash
cd "$(git rev-parse --show-toplevel)/tests"
P=../source/usr/local/emhttp/plugins/hbaviewer/scripts/parse
regen() { local out=$1; shift; printf '%s' "$("$@")" > "expected/$out"; echo "wrote expected/$out"; }

regen storcli_overview.json              bash -c "bash '$P/storcli_overview.sh' 80 < <(cat fixtures/storcli/overview_c0.txt fixtures/storcli/temp_c0.txt)"
regen storcli_overview_pcie.json         bash -c "bash '$P/storcli_overview.sh' 80 0 '' 'x8' 'Gen3 (8.0 GT/s)' 'Full' < <(cat fixtures/storcli/overview_c0.txt fixtures/storcli/temp_c0.txt)"
regen storcli_overview_noencl_ugood.json bash -c "bash '$P/storcli_overview.sh' 80 < <(cat fixtures/storcli/overview_noencl_ugood.txt fixtures/storcli/temp_noencl_ugood.txt)"
regen storcli_overview_9305.json         bash -c "bash '$P/storcli_overview.sh' 80 < <(cat fixtures/storcli/overview_9305.txt fixtures/storcli/temp_9305.txt)"
regen storcli_overview_chiparg.json      bash -c "bash '$P/storcli_overview.sh' 80 0 'SAS3224' < <(cat fixtures/storcli/overview_9305.txt fixtures/storcli/temp_9305.txt)"
regen rollup_faildrive.json              bash -c "bash '$P/storcli_overview.sh' 80 0 < fixtures/storcli/rollup_faildrive.txt"
regen rollup_phyerr.json                 bash -c "bash '$P/storcli_overview.sh' 80 5 < fixtures/storcli/rollup_healthy.txt"
regen rollup_healthy.json                bash -c "bash '$P/storcli_overview.sh' 80 0 < fixtures/storcli/rollup_healthy.txt"
regen phy_under_floor.json               bash -c "bash '$P/storcli_overview.sh' 76 8   < fixtures/storcli/rollup_healthy.txt"
regen phy_over_floor.json                bash -c "bash '$P/storcli_overview.sh' 76 100 < fixtures/storcli/rollup_healthy.txt"

regen hba_normal.json      bash "$P/hba.sh" fixtures/hba_ioc.txt          fixtures/hba_banner.txt     fixtures/hba_board.txt 80
regen hba_notemp.json      bash "$P/hba.sh" fixtures/hba_ioc_notemp.txt   fixtures/hba_banner.txt     fixtures/hba_board.txt 80
regen hba_zerotemp.json    bash "$P/hba.sh" fixtures/hba_ioc_zerotemp.txt fixtures/hba_banner.txt     fixtures/hba_board.txt 80
regen hba_p16.json         bash "$P/hba.sh" fixtures/hba_ioc.txt          fixtures/hba_banner_p16.txt fixtures/hba_board.txt 80
regen hba_gen1.json        bash "$P/hba.sh" fixtures/hba_ioc_gen1.txt     fixtures/hba_banner.txt     fixtures/hba_board.txt 80
regen hba_mode_it.json     bash "$P/hba.sh" fixtures/hba_ioc.txt fixtures/hba_banner.txt fixtures/hba_board.txt 80 fixtures/hba_ident_it.txt
regen hba_mode_ir.json     bash "$P/hba.sh" fixtures/hba_ioc.txt fixtures/hba_banner.txt fixtures/hba_board.txt 80 fixtures/hba_ident_ir.txt
regen hba_mode_noport.json bash "$P/hba.sh" fixtures/hba_ioc.txt fixtures/hba_banner.txt fixtures/hba_board.txt 80 fixtures/hba_ident_noport.txt

# The band sweep is a loop in run.sh (lines 57-60) writing one golden per
# boundary, so it needs the same loop here rather than a single command.
for t in 65 66 75 76 85 86 95 96; do
  regen "band_$t.json" bash -c \
    "sed 's/^ROC temperature(Degree Celsius).*/ROC temperature(Degree Celsius) $t/' fixtures/storcli/rollup_healthy.txt | bash '$P/storcli_overview.sh' 76 0"
done
```

- [ ] **Step 13: Review every regenerated diff before staging**

```bash
git diff tests/expected/
```

Every hunk must show **only** the two added keys, with `"topology": "unknown"` and `"subvendor_id": ""` — the parsers are being run without the composer, so the defaults are correct and expected here. Any other change is a real regression; stop and investigate rather than committing it.

- [ ] **Step 14: Run the full suite and the static checks**

```bash
cd "$(git rev-parse --show-toplevel)"
bash tests/run.sh
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W 2>/dev/null || pwd):/mnt" koalaman/shellcheck:stable \
  -S warning -e SC1090,SC2034,SC2207,SC1007 \
  /mnt/source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh \
  /mnt/source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh \
  /mnt/source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_health.sh \
  /mnt/tests/topology_test.sh
```

Expected: `--- all pass ---`, and shellcheck silent.

- [ ] **Step 15: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/ tests/
git commit -m "Collect the two facts a firmware verdict cannot be given without

Topology, because Broadcom ships a separate multi-path firmware track for the
9300/9305/9400/9405W with its own version numbering. Comparing a multipath card
against the standard track reports a correctly configured card as six major
versions behind, so the index suppresses those boards unless the card can be
shown to be internal. Without this function that is never true, and the verdict
would render nothing on the most common cards -- including the 9305-24i that
motivated the feature.

Two independent signals disqualify: an expander device for this host, or a
three-component end_device-H:N:M child. That is the same two-vs-three rule
_phys_json already uses to keep an expander's PHYs out of a controller's error
counts. Scoped to one host, so a two-HBA box does not have one card's expander
silence the other, and an empty tree is unknown rather than internal.

Subvendor, because an M1015 or an H310 carries a non-Broadcom SubVendor ID and
reaching a generic firmware version on one is a crossflash, not an upgrade. A
wrong verdict there tells someone to do something materially riskier than what
is on screen, so an unreadable attribute yields empty and suppresses rather than
defaulting to something that looks generic.

_pci_dir_of_host and _first_sas_host both move to lib.sh, because the overview
composer now needs the same scsi_host lookup and the same walk that issue #14
added for the PCIe maximum. Moving beats copying: a second _first_sas_host would
have been the third place in this repo that filters hosts by personality.
health_sh_test.sh extracts _pci_dir_of_host from its new home; it never
extracted the other.

Both parsers take the values by environment rather than positionally. Their
positional slots are already full, and eighteen golden invocations would have
needed reshuffling -- several through < <(...) and bash -c wrappers. The repo
already injects composer values this way (SYS_SCSI_HOST, SYS_PCI_ROOT, LSI_CACHE).

Eighteen goldens regenerated individually, never with UPDATE=1, and each diff
read to confirm it shows only the two new keys."
```

---

### Task 3: The lookup layer

**Files:**
- Create: `source/usr/local/emhttp/plugins/hbaviewer/firmware_index.php`
- Modify: `tests/firmware_index_test.php` (append the lookup assertions)

**Interfaces:**
- Consumes: `data/known-firmware.json` from Task 1; the `topology` and `subvendor_id` fields from Task 2.
- Produces:
  - `fw_normalize(string $name): string` — collapses both board-naming conventions to one key.
  - `fw_compare(string $a, string $b): int` — dotted-quad compare, returns `<0`, `0`, `>0`.
  - `fw_load(?string $path = null): ?array` — reads and re-keys the index; `null` when unreadable.
  - `fw_evaluate(array $ctl, ?array $idx): array` — the verdict. **`$idx` is required**, with no default: a forgotten argument must be an `ArgumentCountError` that phpstan catches at level 3, not a silent `unknown` that renders nothing. Pass `null` only when the index is genuinely unavailable. `$ctl` accepts keys `board`, `chip`, `firmware`, `subvendor_id`, `topology`, `rom_profile`. Always returns an array with `status` (one of `current`, `behind`, `ahead`, `no_it_firmware`, `oem_out_of_scope`, `suppressed`, `unknown`) and `reason` (string or `null`), plus, when known, `detected`, `latest`, `branch`, `terminal` (bool), `confidence`, `note`, `index_date`.
  - `fw_verdict_color(array $v): string` — a hex colour, or `''` for no colour.

- [ ] **Step 1: Write the failing lookup tests**

Append to `tests/firmware_index_test.php`, immediately before the final `echo`/`exit` pair:

```php
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/firmware_index.php';

// Both board-naming conventions must collapse to one key. SAS3 and earlier
// report "SAS9305-24i"; SAS3.5 reports "HBA 9400-16i".
check('normalize strips the SAS prefix',  fw_normalize('SAS9305-24i') === '930524i');
check('normalize strips the HBA prefix',  fw_normalize('HBA 9400-16i') === '940016i');
check('normalize is case-insensitive',    fw_normalize('sas9305-24i') === fw_normalize('SAS9305-24i'));

check('compare equal',   fw_compare('16.00.12.00', '16.00.12.00') === 0);
check('compare older',   fw_compare('15.00.00.00', '16.00.12.00') < 0);
check('compare newer',   fw_compare('17.00.00.00', '16.00.12.00') > 0);
// A short version must not sort above a long one: 16 is 16.0.0.0, not "more".
check('compare pads the shorter side', fw_compare('16', '16.00.12.00') < 0);
// Leading zeros are decimal, not octal, and "00" must equal 0.
check('compare treats 00 as zero', fw_compare('16.00.12.00', '16.0.12.0') === 0);

$idx = fw_load();
check('index loads', is_array($idx));

// The card from the 2026-08-08 bundle. This is the worked example in the spec
// and the case the whole feature exists for.
$reporter = [
    'board' => 'SAS9305-24i', 'chip' => 'SAS3224', 'firmware' => '15.00.00.00',
    'subvendor_id' => '0x1000', 'topology' => 'internal',
];
$v = fw_evaluate($reporter, $idx);
check('reporter 9305-24i is behind',        $v['status'] === 'behind');
check('reporter names the latest version',  ($v['latest'] ?? '') === '16.00.12.00');
check('reporter branch is terminal',        ($v['terminal'] ?? null) === true);
check('reporter confidence is confirmed',   ($v['confidence'] ?? '') === 'confirmed');
check('reporter carries the board note',    str_contains((string) ($v['note'] ?? ''), 'NOT interchangeable'));

$v = fw_evaluate(['board' => 'SAS9305-24i', 'firmware' => '16.00.12.00',
                  'subvendor_id' => '0x1000', 'topology' => 'internal'], $idx);
check('matching version is current', $v['status'] === 'current');

$v = fw_evaluate(['board' => 'SAS9305-24i', 'firmware' => '17.00.00.00',
                  'subvendor_id' => '0x1000', 'topology' => 'internal'], $idx);
check('newer than index is ahead, not behind', $v['status'] === 'ahead');

// THE gate that matters. An M1015 or H310 reaching the generic image is a
// crossflash, not an upgrade, and telling someone otherwise does real harm.
$v = fw_evaluate(['board' => 'SAS9211-8i', 'firmware' => '20.00.00.00',
                  'subvendor_id' => '0x1014', 'topology' => 'internal'], $idx);
check('OEM subvendor is out of scope', $v['status'] === 'oem_out_of_scope');
check('OEM reason says crossflash',    str_contains((string) $v['reason'], 'crossflash'));

// The multipath suppression, which is why topology detection had to exist.
$v = fw_evaluate($reporter + ['topology' => 'unknown'], $idx);
check('affected board with unknown topology is suppressed', $v['status'] === 'suppressed');
check('suppressed still shows the detected version', ($v['detected'] ?? '') === '15.00.00.00');
check('suppressed carries no verdict',   !isset($v['latest']));

// A board with no multipath track compares regardless of topology.
$v = fw_evaluate(['board' => 'SAS9201-16i', 'firmware' => '19.00.00.00',
                  'subvendor_id' => '0x1000', 'topology' => 'unknown'], $idx);
check('unaffected board compares despite unknown topology', $v['status'] === 'behind');

// RAID-on-Chip: no IT firmware exists at any version. Distinct from a failed
// lookup, because the answer is "never", not "not yet known".
$v = fw_evaluate(['board' => 'MegaRAID 9361-8i', 'chip' => 'SAS3108',
                  'firmware' => '4.00.00.00', 'subvendor_id' => '0x1000'], $idx);
check('RAID-on-Chip reports no_it_firmware', $v['status'] === 'no_it_firmware');

// Profile-aware board with no resolved profile: same version ships in
// incompatible capability profiles, so the number alone means little.
$v = fw_evaluate(['board' => 'HBA 9400-16i', 'firmware' => '24.00.00.00',
                  'subvendor_id' => '0x1000', 'topology' => 'internal'], $idx);
check('unresolved ROM profile is suppressed', $v['status'] === 'suppressed');

$v = fw_evaluate(['board' => 'SAS9999-99i', 'firmware' => '1.0.0.0',
                  'subvendor_id' => '0x1000', 'topology' => 'internal'], $idx);
check('unindexed board is unknown', $v['status'] === 'unknown');

$v = fw_evaluate($reporter, null);
check('no index at all is unknown', $v['status'] === 'unknown');

// Amber is reserved for a terminal branch. On a non-terminal branch "latest" is
// a floor, not a ceiling, so behind renders informational.
check('behind on a terminal branch is amber',
      fw_verdict_color(['status' => 'behind', 'terminal' => true]) === '#d29922');
check('behind on a non-terminal branch has no colour',
      fw_verdict_color(['status' => 'behind', 'terminal' => false]) === '');
check('current is green', fw_verdict_color(['status' => 'current']) === '#3fb950');
```

- [ ] **Step 2: Run and verify it fails**

```bash
cd "$(git rev-parse --show-toplevel)"
php tests/firmware_index_test.php
```

Expected: a fatal error — `Failed opening required '.../firmware_index.php'`.

- [ ] **Step 3: Write the lookup module**

Create `source/usr/local/emhttp/plugins/hbaviewer/firmware_index.php`:

```php
<?PHP
/* Known-firmware verdict — is this card running current IT firmware?
 *
 * Deliberately conservative: every path that could produce a WRONG "out of
 * date" verdict returns a suppressed status instead. A missed update notice is
 * harmless. Telling someone to reflash a correctly configured card is not, and
 * on an OEM-rebranded adapter the operation being suggested is not even the one
 * described — it is a crossflash.
 *
 * Seven states, not a boolean: "not current" and "should update" are different
 * claims, and "no IT firmware exists at any version" is a third thing again.
 *
 * The index is read from disk and nothing here touches the network. Facts that
 * drive a hard verdict live on branches marked terminal (P16, P20), which by
 * definition receive no new version, so a bundled file cannot go stale in a way
 * that matters. A wrong entry is fixed by a release.
 *
 * Pure functions only above the CLI guard, so tests can require this file. */

const FW_INDEX_FILE = __DIR__ . '/data/known-firmware.json';

/* Collapse the two naming conventions to one key. SAS3 and earlier report
   'SAS9305-24i'; SAS3.5 reports 'HBA 9400-16i'. Both must find their board. */
function fw_normalize(string $name): string {
    $n = strtolower($name);
    $n = (string) preg_replace('/^(sas|hba)\s*/', '', $n);
    return (string) preg_replace('/[^a-z0-9]/', '', $n);
}

/* Dotted-quad compare, shorter side zero-padded. NEVER used on NVDATA, whose
   format varies ('24.00.00.22' on 9400, hex-style '0F.0b.91.xx' on 9405W
   multipath profiles) and which is not ordered at all. */
function fw_compare(string $a, string $b): int {
    $pa = array_map('intval', explode('.', $a));
    $pb = array_map('intval', explode('.', $b));
    $n  = max(count($pa), count($pb));
    for ($i = 0; $i < $n; $i++) {
        $x = $pa[$i] ?? 0;
        $y = $pb[$i] ?? 0;
        if ($x !== $y) return $x <=> $y;
    }
    return 0;
}

/* Read the index and re-key its boards on their normalized form, so lookup is
   convention-agnostic. Returns null on anything unreadable or shapeless — the
   caller renders 'unknown' rather than guessing. */
function fw_load(?string $path = null): ?array {
    $p = $path ?? FW_INDEX_FILE;
    if (!is_readable($p)) return null;
    $raw = json_decode((string) @file_get_contents($p), true);
    if (!is_array($raw) || empty($raw['boards']) || !is_array($raw['boards'])) return null;
    $keyed = [];
    foreach ($raw['boards'] as $name => $b) {
        if (!is_array($b)) continue;
        $b['_display_name'] = $name;
        $keyed[fw_normalize((string) $name)] = $b;
    }
    $raw['boards'] = $keyed;
    return $raw;
}

/* Which version this board is measured against. A resolved ROM profile has its
   own track; without one, the board's standard track. */
function fw_track_version(array $b, ?string $profile): ?string {
    if ($profile !== null && !empty($b['rom_profiles'][$profile]['version'])) {
        return (string) $b['rom_profiles'][$profile]['version'];
    }
    return isset($b['latest_it']) ? (string) $b['latest_it'] : null;
}

/* $ctl keys: board, chip, firmware, subvendor_id, topology, rom_profile.
   Pass $idx to avoid re-reading the file per controller. */
function fw_evaluate(array $ctl, ?array $idx = null): array {
    if ($idx === null) $idx = fw_load();

    $board    = (string) ($ctl['board']        ?? '');
    $chip     = (string) ($ctl['chip']         ?? '');
    $fw       = (string) ($ctl['firmware']     ?? '');
    $subven   = strtolower((string) ($ctl['subvendor_id'] ?? ''));
    $topology = (string) ($ctl['topology']     ?? 'unknown');
    $profile  = isset($ctl['rom_profile']) ? (string) $ctl['rom_profile'] : null;

    if ($idx === null) {
        return ['status' => 'unknown', 'reason' => 'the firmware index could not be read'];
    }
    $date = isset($idx['updated']) ? (string) $idx['updated'] : null;
    $base = ['detected' => $fw, 'index_date' => $date];

    // Gate 2 — OEM rebrand. The most consequential suppression in the file: an
    // M1015 or an H310 carries different NVDATA and BIOS, and reaching the
    // generic version is a crossflash, a different and riskier operation.
    if ($subven !== '' && $subven !== '0x1000') {
        return ['status' => 'oem_out_of_scope', 'reason' =>
            'OEM-rebranded adapter — the index covers generic Broadcom images only, '
          . 'and reaching one from here would be a crossflash, not an upgrade'] + $base;
    }

    // Gate 3 — RAID-on-Chip. No IT firmware exists at any version and the part
    // cannot be crossflashed to one. "Never", not "not yet known".
    foreach (($idx['no_it_firmware'] ?? []) as $roc => $_ignored) {
        if ($roc === '_comment' || $chip === '') continue;
        if (stripos($chip, (string) $roc) !== false) {
            return ['status' => 'no_it_firmware', 'reason' =>
                "$roc is a RAID-on-Chip part — no IT firmware exists at any version"] + $base;
        }
    }

    // Gate 4 — not indexed.
    $key = fw_normalize($board);
    if ($key === '' || !isset($idx['boards'][$key])) {
        return ['status' => 'unknown', 'reason' =>
            "this board is not in the index" . ($board !== '' ? " ($board)" : '')] + $base;
    }
    $b = $idx['boards'][$key];

    // Gate 5 — indexed, but no IT firmware published.
    if (empty($b['it_capable'])) {
        return ['status' => 'no_it_firmware', 'reason' =>
            'no IT firmware is published for this board'] + $base;
    }

    // Gate 6 — multipath. These boards run an independent version track, so a
    // card on it correctly reports a version far below the standard branch.
    $mp = array_map('fw_normalize', $idx['multipath_track']['affected_boards'] ?? []);
    if (in_array($key, $mp, true) && $topology !== 'internal') {
        return ['status' => 'suppressed', 'reason' =>
            'this board has a separate multi-path firmware track, and the topology '
          . 'could not be confirmed as internal — a multi-path card correctly runs '
          . 'a version well below the standard branch'] + $base;
    }

    // Gate 7 — unresolved ROM profile. The same version ships in incompatible
    // capability profiles, so the number alone proves little.
    if (!empty($b['rom_profiles']) && $profile === null) {
        return ['status' => 'suppressed', 'reason' =>
            'the installed ROM profile could not be determined, and this board ships '
          . 'the same version in profiles with different capabilities'] + $base;
    }

    // Gate 8 — compare.
    $latest = fw_track_version($b, $profile);
    if ($latest === null) {
        return ['status' => 'unknown', 'reason' => 'no known version for this board'] + $base;
    }
    if ($fw === '') {
        return ['status' => 'unknown', 'reason' => 'no firmware version detected on the adapter'] + $base;
    }

    $branch = isset($b['branch']) ? (string) $b['branch'] : null;
    $meta = $base + [
        'latest'     => $latest,
        'branch'     => $branch,
        'terminal'   => $branch !== null && !empty($idx['branches'][$branch]['terminal']),
        'confidence' => (string) ($b['confidence'] ?? 'unknown'),
        'note'       => isset($b['notes']) ? (string) $b['notes'] : null,
    ];

    $cmp = fw_compare($fw, $latest);
    if ($cmp === 0) return ['status' => 'current', 'reason' => null] + $meta;
    if ($cmp > 0)   return ['status' => 'ahead', 'reason' =>
        'this adapter is newer than the index — the index is stale, not the card'] + $meta;
    return ['status' => 'behind', 'reason' => null] + $meta;
}

/* Amber is reserved for a TERMINAL branch. On a non-terminal branch the known
   version is a floor, not a ceiling, so "behind" is informational rather than
   something to act on. Hexes match chrome.css's status palette. */
function fw_verdict_color(array $v): string {
    $s = $v['status'] ?? '';
    if ($s === 'current') return '#3fb950';
    if ($s === 'behind' && !empty($v['terminal'])) return '#d29922';
    return '';
}
```

**No `if (PHP_SAPI === 'cli') return;` line here.** The other root-level PHP files carry one because they are endpoints with an HTTP dispatch below it that must not fire when a test requires the file. This module is a library included by `view.php` and `ajax_info.php` — it has no dispatch, so the guard would be a no-op on the last line of the file, and dead code that a reader has to think about. Add it if and when a dispatch is ever added.

- [ ] **Step 4: Run and verify it passes**

```bash
php tests/firmware_index_test.php
```

Expected: every line `PASS`, final line `firmware_index: all pass`.

- [ ] **Step 5: Mutation-test the two gates that matter**

Break each, confirm the intended failures, restore:

1. Change gate 2's condition to `if (false)`. Re-run: **only** `OEM subvendor is out of scope` and `OEM reason says crossflash` fail.
2. Change gate 6's condition to `if (false)`. Re-run: **only** `affected board with unknown topology is suppressed`, `suppressed still shows the detected version` and `suppressed carries no verdict` fail.

If a mutation fails nothing, the assertion is not reaching the code and must be fixed before proceeding.

- [ ] **Step 6: Static analysis**

```bash
cd "$(git rev-parse --show-toplevel)"
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W 2>/dev/null || pwd):/app" -w /app \
  ghcr.io/phpstan/phpstan:latest analyse source tests --level=3 --no-progress
```

Expected: `[OK] No errors`.

- [ ] **Step 7: Run the full suite and commit**

```bash
bash tests/run.sh
git add source/usr/local/emhttp/plugins/hbaviewer/firmware_index.php tests/firmware_index_test.php
git commit -m "The firmware lookup: seven states, and six of them are ways to say no

Every path that could produce a wrong \"out of date\" verdict returns a
suppressed status instead. A missed update notice costs nothing; telling someone
to reflash a correctly configured card costs them a working controller, and on
an OEM-rebranded adapter the operation being suggested is not even the one
described -- it is a crossflash to a different NVDATA and BIOS.

So the gates run before the comparison, not after: index unreadable, OEM
subvendor, RAID-on-Chip part, board not indexed, board with no IT firmware
published, multipath track with unconfirmed topology, unresolved ROM profile.
Only a card that clears all seven gets its version compared.

Amber is reserved for a terminal branch. P16 and P20 are final, so equality
there is meaningful in both directions and being behind is actionable. On P24 or
P28 the known version is a floor rather than a ceiling, so behind renders
informational and does not ask for anything.

Both gates that can do harm are mutation-tested: disabling the OEM gate fails
exactly two assertions and disabling the multipath gate exactly three, which is
the check that they are reached at all rather than merely present."
```

---

### Task 4: Render the verdict on both surfaces

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/view.php` (require the module; add `firmware_verdict` to the view model)
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php:177-182` (enrich the overview JSON) and the Firmware line in `renderOverviewCards`
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/flash_view.js:18-24` (render the block)
- Modify: `tests/view_test.php` (assert the view model carries a verdict)

**Interfaces:**
- Consumes: `fw_evaluate()` and `fw_verdict_color()` from Task 3; `topology` / `subvendor_id` from Task 2.
- Produces: `lsi_hba_view()` gains a `firmware_verdict` key holding the full `fw_evaluate()` array. `ajax_info.php?type=overview` gains the same key on each controller object. A new `fw_overview_clause(array $verdict): string` in `view.php` returns the Overview's inline HTML fragment, or `''`.

**Do not touch `health.php`.** The verdict is deliberately excluded from the HBA Health rollup and must not become a sixth indicator. A stale-firmware finding is a recommendation, not a fault, and it is the only sub-indicator whose data source is externally maintained and can silently go wrong — a wrong thermal reading is a bug, a wrong firmware verdict talks someone into a reflash. This is an omission that must stay an omission; it is recorded here because "we chose not to" is otherwise indistinguishable from "we forgot", and the next person to see five indicators and a firmware fact will reach for the sixth.

- [ ] **Step 1: Write the failing view-model test**

Append to `tests/view_test.php`, before its final summary:

```php
/* The firmware verdict rides on the view model so both surfaces read one
   answer. The Overview card and the firmware page must never disagree about
   whether a card is behind. */
$vm = lsi_hba_view([
    'board_name' => 'SAS9305-24i', 'model' => 'SAS3224', 'firmware' => '15.00.00.00',
    'subvendor_id' => '0x1000', 'topology' => 'internal', 'status' => 'ok',
], 1, 0);
check('view model carries a firmware verdict', isset($vm['firmware_verdict']['status']));
check('view model verdict is behind', ($vm['firmware_verdict']['status'] ?? '') === 'behind');

/* The clause is suppressed on every state that has no verdict to give: a bare
   colourless marker next to a version reads as a fault the user cannot act on. */
check('behind renders a clause',   fw_overview_clause(['status' => 'behind', 'latest' => '16.00.12.00', 'terminal' => true]) !== '');
check('suppressed renders nothing', fw_overview_clause(['status' => 'suppressed', 'detected' => '15.00.00.00']) === '');
check('unknown renders nothing',    fw_overview_clause(['status' => 'unknown']) === '');
check('oem renders nothing',        fw_overview_clause(['status' => 'oem_out_of_scope']) === '');
```

- [ ] **Step 2: Run and verify it fails**

```bash
php tests/view_test.php
```

Expected: fatal — `Call to undefined function fw_overview_clause()`.

- [ ] **Step 3: Wire the verdict into the view model**

At the top of `view.php`, after its existing requires, add:

```php
require_once __DIR__ . '/firmware_index.php';
```

In `lsi_hba_view()`, immediately before the `return [` statement, add:

```php
    // One verdict, two surfaces. Computed here rather than in either renderer so
    // the Overview card and the firmware page cannot drift apart about whether
    // this card is behind.
    $verdict = fw_evaluate([
        'board'        => $data['board_name']   ?? '',
        'chip'         => $data['model']        ?? '',
        'firmware'     => $data['firmware']     ?? '',
        'subvendor_id' => $data['subvendor_id'] ?? '',
        'topology'     => $data['topology']     ?? 'unknown',
    ], fw_load());
```

And add to the returned array, after the `'fw_old'` line:

```php
        'firmware_verdict' => $verdict,
```

- [ ] **Step 4: Add the Overview clause helper**

Append to `view.php`, above the `lsi_hba_view()` function:

```php
/* The Overview's one-line firmware clause. Rendered only for the three states
   that carry an actual comparison — a suppressed or unknown verdict has a
   reason worth reading, and a one-line summary cannot carry it, so the row
   shows the version alone and the firmware page explains. A colourless marker
   with no explanation next to a version reads as a fault nobody can act on. */
function fw_overview_clause(array $verdict): string {
    $s = $verdict['status'] ?? '';
    if ($s === 'current') {
        return ' <span style="color:#3fb950" title="Matches the newest IT firmware in the plugin&#39;s index">&#10003; current</span>';
    }
    if ($s === 'ahead') {
        return ' <span class="lu-muted" title="Newer than the plugin&#39;s index — the index is stale, not this card">newer than index</span>';
    }
    if ($s !== 'behind') return '';
    $colour = fw_verdict_color($verdict);
    return ' <span' . ($colour !== '' ? ' style="color:' . $colour . '"' : ' class="lu-muted"')
         . ' title="Newest IT firmware known for this board">&#9650; '
         . htmlspecialchars((string) ($verdict['latest'] ?? '')) . ' known</span>';
}
```

- [ ] **Step 5: Render the clause on the Overview card**

In `ajax_info.php`, inside `renderOverviewCards()`, the Firmware line currently reads:

```php
              . '<p>Firmware: <span>' . htmlspecialchars($v['firmware']) . '</span>'
              . ($v['fw_old'] ? ' <span style="color:#f39c12" title="P20 is the IT-mode baseline for SAS2">&#9888; pre-P20</span>' : '') . '</p>'
```

Change it to:

```php
              . '<p>Firmware: <span>' . htmlspecialchars($v['firmware']) . '</span>'
              . ($v['fw_old'] ? ' <span style="color:#f39c12" title="P20 is the IT-mode baseline for SAS2">&#9888; pre-P20</span>' : '')
              . fw_overview_clause($v['firmware_verdict']) . '</p>'
```

- [ ] **Step 6: Add the verdict to the overview JSON the firmware page reads**

In `ajax_info.php`, the enrichment loop at lines 177-182 currently reads:

```php
    foreach ($ctls as &$c) {
        if (isset($c['error'])) continue;
        $c['color'] = lsi_status_color($c['status'] ?? 'ok');
        $c['label'] = lsi_status_label($c['status'] ?? 'ok');
    }
```

Change it to:

```php
    $fwIdx = fw_load();   // read once, not once per controller
    foreach ($ctls as &$c) {
        if (isset($c['error'])) continue;
        $c['color'] = lsi_status_color($c['status'] ?? 'ok');
        $c['label'] = lsi_status_label($c['status'] ?? 'ok');
        $c['firmware_verdict'] = fw_evaluate([
            'board'        => $c['board_name']   ?? '',
            'chip'         => $c['model']        ?? '',
            'firmware'     => $c['firmware']     ?? '',
            'subvendor_id' => $c['subvendor_id'] ?? '',
            'topology'     => $c['topology']     ?? 'unknown',
        ], $fwIdx);
    }
```

- [ ] **Step 7: Render the block on the firmware page**

In `flash_view.js`, the card currently emits a single `sub` paragraph. Replace the line:

```javascript
                + '<p class="sub">Current firmware: '+fesc(c.firmware||'?')+(c.bios?' · BIOS: '+fesc(c.bios):'')+'</p>'
```

with:

```javascript
                + '<p class="sub">Current firmware: '+fesc(c.firmware||'?')+(c.bios?' · BIOS: '+fesc(c.bios):'')+'</p>'
                + fwVerdictBlock(c.firmware_verdict)
```

And add this function to `flash_view.js`, immediately after `fesc`:

```javascript
    /* The firmware verdict, in full. This is the surface where a flash actually
       happens, so unlike the Overview's one-liner it shows the reason a verdict
       was withheld — a suppressed comparison is a fact about the index's limits,
       not about the card, and the user is entitled to know which. */
    function fwVerdictBlock(v) {
        if (!v || !v.status || v.status === 'unknown') return '';
        var rows = [], label = '', colour = '';
        if (v.status === 'behind')  { label = 'BEHIND';         colour = v.terminal ? '#d29922' : ''; }
        if (v.status === 'current') { label = 'CURRENT';        colour = '#3fb950'; }
        if (v.status === 'ahead')   { label = 'AHEAD OF INDEX'; }
        if (label) {
            rows.push(['Firmware', '<strong'+(colour?' style="color:'+colour+'"':'')+'>'+label+'</strong>']);
            if (v.latest) rows.push(['Latest known IT', fesc(v.latest)]);
            if (v.branch) rows.push(['Branch', fesc(v.branch)+(v.terminal?' (terminal)':' (not final — this is a floor, not a ceiling)')]);
        } else {
            rows.push(['Firmware', '<span class="lu-muted">no verdict</span>']);
        }
        if (v.reason)     rows.push(['Why', fesc(v.reason)]);
        if (v.confidence) rows.push(['Confidence', fesc(v.confidence)+(v.index_date?' · index '+fesc(v.index_date):'')]);
        if (v.note)       rows.push(['Note', fesc(v.note)]);
        return '<div class="lu-fstep">'+rows.map(function(r){
            return '<div><label class="step" style="display:inline-block;min-width:130px">'+r[0]+'</label>'+r[1]+'</div>';
        }).join('')+'</div>';
    }
```

- [ ] **Step 8: Run the tests**

```bash
cd "$(git rev-parse --show-toplevel)"
php tests/view_test.php
node --check source/usr/local/emhttp/plugins/hbaviewer/flash_view.js
bash tests/run.sh
```

Expected: `view: all pass`, `node --check` silent, suite `--- all pass ---`.

- [ ] **Step 9: Mutation-test the clause suppression**

Change `fw_overview_clause`'s `if ($s !== 'behind') return '';` to `if (false) return '';`. Re-run `php tests/view_test.php` and confirm the three "renders nothing" assertions fail. Restore.

- [ ] **Step 10: Static analysis and commit**

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W 2>/dev/null || pwd):/app" -w /app \
  ghcr.io/phpstan/phpstan:latest analyse source tests --level=3 --no-progress
git add source/usr/local/emhttp/plugins/hbaviewer/ tests/view_test.php
git commit -m "Show the firmware verdict on the Overview and the firmware page

The verdict is computed once, on the view model, so the two surfaces cannot
drift apart about whether a card is behind.

They say different amounts, deliberately. The Overview gets one clause and only
for the three states that carry an actual comparison; a suppressed or unknown
verdict has a reason worth reading and a one-line summary cannot carry it, so
the row shows the version alone. A colourless marker with no explanation beside
a version reads as a fault nobody can act on. The firmware page — where a flash
actually happens — shows the reason in full, because a withheld comparison is a
fact about the index's limits rather than about the card, and the person about
to write to their controller is entitled to know which.

The Overview reaches this at all because the firmware page is Cond-gated on
ENABLE_FLASH, which is off by default: both diagnostic bundles collected so far
have ENABLE_FLASH=0, so a firmware-page-only verdict would be invisible to
nearly every user."
```

---

### Task 5: The bundle captures what the plugin now reads

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/bundle_support.sh:413` (the pci.txt attribute list)
- Modify: `tests/bundle_coverage_test.sh` (add the assertion)

**Interfaces:**
- Consumes: the `subsystem_vendor` read added in Task 2.
- Produces: `03-sysfs/pci.txt` in every bundle gains a `subsystem_vendor` line per controller.

- [ ] **Step 1: Write the failing coverage assertion**

Append to `tests/bundle_coverage_test.sh`, before its final summary:

```bash
# subsystem_vendor decides whether a firmware verdict is given at all: 0x1000 is
# a generic Broadcom board and anything else is an OEM rebrand, where reaching a
# generic image is a crossflash rather than an upgrade. A bundle that omits it
# cannot answer why a reporter's card shows no verdict -- which is exactly the
# class of question this guard exists to keep answerable.
if grep -E 'for a in .*subsystem_vendor' "$BUNDLE" >/dev/null; then
    ok "bundle captures subsystem_vendor"
else
    bad "bundle missing subsystem_vendor" "get_hba_info.sh reads it to gate the firmware verdict, but pci.txt does not capture it"
fi
```

- [ ] **Step 2: Run and verify it fails**

```bash
cd "$(git rev-parse --show-toplevel)"
bash tests/bundle_coverage_test.sh
```

Expected: `FAIL  bundle missing subsystem_vendor`, exit 1.

- [ ] **Step 3: Add the capture**

In `scripts/bundle_support.sh`, the attribute loop currently reads:

```bash
        for a in current_link_width current_link_speed max_link_width max_link_speed power_state vendor device; do
```

Change it to:

```bash
        for a in current_link_width current_link_speed max_link_width max_link_speed power_state vendor device subsystem_vendor; do
```

- [ ] **Step 4: Run and verify it passes**

```bash
bash tests/bundle_coverage_test.sh
```

Expected: `PASS  bundle captures subsystem_vendor`, and `bundle-coverage: all pass`.

- [ ] **Step 5: Mutation-test the guard**

Remove `subsystem_vendor` from the attribute list again, re-run, confirm the new assertion fails and nothing else does. Restore.

- [ ] **Step 6: Run everything, including the checks CI runs**

```bash
cd "$(git rev-parse --show-toplevel)"
bash tests/run.sh
find source -name '*.js' -not -name '*.min.js' -print0 | xargs -0 -r -n1 node --check
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W 2>/dev/null || pwd):/app" -w /app php:8.2-cli \
  sh -c "find source tests -name '*.php' -print0 | xargs -0 -r -n1 php -l" | grep -v '^No syntax errors'
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W 2>/dev/null || pwd):/mnt" koalaman/shellcheck:stable \
  -S warning -e SC1090,SC2034,SC2207,SC1007 $(find source tests -name '*.sh' | sed 's|^|/mnt/|')
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W 2>/dev/null || pwd):/app" -w /app \
  ghcr.io/phpstan/phpstan:latest analyse source tests --level=3 --no-progress
```

Expected: `--- all pass ---`, no output from the lint commands, `[OK] No errors` from PHPStan.

- [ ] **Step 7: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/bundle_support.sh tests/bundle_coverage_test.sh
git commit -m "Capture subsystem_vendor in the bundle, now that the plugin reads it

bundle_support.sh states the rule it would otherwise have broken: a new thing
the plugin reads means a new entry here, and without one the script keeps
working while quietly becoming incomplete. subsystem_vendor decides whether a
firmware verdict is given at all -- 0x1000 is a generic Broadcom board, anything
else is an OEM rebrand where reaching a generic image is a crossflash -- so a
bundle that omits it cannot answer why a reporter's card shows no verdict.

The guard was written first and observed failing before the capture was added,
and removing the capture again fails that assertion and nothing else."
```

---

## Post-implementation

Not verifiable by anything in `tests/`, and required before this ships:

1. **Load the Monitor page in a browser.** The Overview card must show the firmware clause, and on a card whose verdict is `current` or suppressed it must not show a bare marker.
2. **Enable flashing and open the firmware page.** The verdict block must render under each controller, with the reason visible on a suppressed card.
3. **Confirm the reporter case.** On a 9305-24i running 15.00.00.00 the verdict must read `BEHIND`, latest `16.00.12.00`, branch `P16 (terminal)`, amber.

Plan 055 shipped three dead internal links because no test covered the rendered page. This change adds two new rendered surfaces and a new JSON field the page depends on.

## Deferred

Out of scope here, and each needs its own spec if wanted: the mirrored `firmware/` repository and `manifest.json`; download links and `SHA256SUMS`; fetching or caching the index over the network; ROM profile detection on 9400/9500 (unresolved upstream — `suppressed` is the correct answer today); retrieving Broadcom KB 1211211122774.
