# Plan 026: One-click support bundle for bug reports

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 8286fe7..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/settings.php scripts/capture.sh scripts/capture_storcli.sh scripts/capture_sysfs.sh`
> Expected output: **nothing**. Every excerpt below is quoted from `8286fe7`
> (`dev` tip, 2026-07-30). Any difference is a STOP condition.

## Correcting an assumption this plan started from — read first

The initial framing for this plan assumed `scripts/capture*.sh` could be
exposed directly as an end-user "download support bundle" button. **That's
wrong and this plan does not do it.** Reading them:

```bash
# scripts/capture.sh
# Capture REAL lsiutil output from the Unraid box into test fixtures.
# The committed fixtures are seeded from documented formats; run this on the
# actual HBA to replace them with ground truth, then regenerate goldens:
#   bash scripts/capture.sh [PORT] [OUTDIR]
#   UPDATE=1 bash tests/run.sh     # re-bless expected/ from the new fixtures
PORT="${1:-1}"
OUT="${2:-tests/fixtures}"
LSIUTIL="/usr/local/emhttp/plugins/hbaviewer/hbaviewer.x86_64"
```

These are **developer test-fixture generators** — they write into
`tests/fixtures/` (a repo path, not a user download location), assume an
absolute binary path, and exist so the maintainer can regenerate golden
test fixtures from real hardware. They are not designed to be invoked from
a served PHP page against arbitrary user input, and repurposing them as-is
would mean either writing into the plugin's own installed source tree from
a web request (bad) or forking their logic — at which point it's cleaner
to write the bundle script fresh, reusing the *idea* (call the same
read-only binaries, capture their raw output) without reusing the files.

This plan builds a new, purpose-built bundle script instead.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW — read-only, same binaries the plugin already calls
- **Depends on**: none
- **Category**: feature
- **Planned at**: `8286fe7`, 2026-07-31
- **Requested by**: external roadmap review — issues
  [#5](https://github.com/FugginOld/Unraid-HBAviewer/issues/5) and
  [#6](https://github.com/FugginOld/Unraid-HBAviewer/issues/6) both
  involved back-and-forth pasting of raw `storcli`/sysfs output that a
  bundle would have collected in one step

## Why this matters

Every bug report so far involving a specific card (issues #3, #5, #6) has
required several rounds of "please paste the output of X" before there was
enough to diagnose. A single button that runs the same reads the plugin
already performs, plus a couple of extra raw dumps a developer would
actually ask for, and zips them for attachment to a GitHub issue collapses
that back-and-forth into one round trip.

## Current state

### `settings.php` — where the button belongs

Same page that already hosts `ENABLE_FLASH` and the read-only "Detected
Hardware" diagnostic row from plan 010. A "Download Support Bundle" button
fits naturally next to that existing diagnostic row rather than needing a
new page.

### What's read-only and safe to include

- `storcli /cN/show all`, `/cN/eall show all`, `/cN/eall/sall show all` —
  already-called commands, just captured to a file instead of parsed
- `lsiutil -p<port> -a 25,2,0,0` / `-a 20,12,0,0` / `-a 42,0` — same,
  lsiutil path
- Relevant `/sys/class/scsi_host/host*/{proc_name,board_name,version_fw}`
  and `/sys/class/sas_phy/*/` contents — sysfs snapshot
- The plugin's own current config (`config.php`'s `lsi_config_read()`
  output) — helps reproduce with the same settings
- Plugin version / Unraid version strings

### What needs redaction

Drive serial numbers and SAS addresses are identifying enough that an
"anonymize" checkbox is worth offering, matching the same instinct
`collect_smart.sh` documents about model-string quoting elsewhere in the
codebase (grep it for the drive-model quoting comment before assuming this
is unprecedented in the repo).

## Scope

**In scope**:

- New `scripts/bundle_support.sh`, purpose-built (not a reuse of
  `capture*.sh`), invoked from a new small PHP endpoint following the
  `flash.php`-style "guard function first, dispatch skipped under CLI"
  pattern even though this isn't a mutating action — the precedent for
  "how does this plugin structure a non-`ajax_info.php` action" is worth
  reusing regardless of mutation status
- Output: single zip under `/tmp` (not `/boot` — this is a one-off
  download, not persisted state), served for download, then cleaned up
  (or left in `/tmp` to age out on reboot — decide in Step 1)
- "Anonymize" checkbox: strip serials/SAS addresses via regex before
  zipping when checked
- Settings page button placement next to the existing Detected Hardware row

**Out of scope**:

- Automatic upload/attachment to GitHub issues (the working rules in
  `plans/README.md` explicitly forbid the plugin — or its author acting
  through it — writing to the issues page; a manual download-then-attach
  flow is correct here for the same reason)
- Modifying `capture*.sh` at all — they stay exactly as-is, serving their
  existing dev-fixture purpose

## Steps

### Step 1: Decide output location and cleanup policy

`/tmp/hbav_bundle_<timestamp>.zip`, matching `FLASH_DIR`'s existing
`/tmp/hbav_flash` naming convention in `flash.php`. Decide whether to
delete-after-serve or let `/tmp` age it out — `/tmp` is RAM-backed on
Unraid, so leaving it costs memory until reboot, not disk; delete-after-
serve is the tidier default unless there's a reason to keep it browsable.

### Step 2: `scripts/bundle_support.sh`

```bash
#!/bin/bash
# Purpose-built support bundle — NOT a reuse of capture*.sh (those are
# dev test-fixture generators, see plan 026). Read-only: calls the same
# binaries the plugin's own composers call, captures raw + parsed output,
# zips for attachment to a bug report.
OUT="$1"          # target directory, caller-provided (e.g. mktemp -d)
ANON="${2:-0}"    # 1 = strip serials/SAS addresses
mkdir -p "$OUT"

# ... storcli / lsiutil / sysfs captures, mirroring what get_hba_info.sh,
# get_phy_health.sh, get_attached_drives.sh already invoke, written to
# $OUT/*.txt instead of piped into a parser ...

if [ "$ANON" = "1" ]; then
    # Redact serials/SAS addresses in place — confirm exact patterns against
    # collect_smart.sh's existing serial-quoting comment before inventing a
    # new regex for the same class of data.
    :
fi

( cd "$OUT" && zip -r -q bundle.zip . )
```

**Verify**: `bash -n scripts/bundle_support.sh` → exit 0

### Step 3: PHP endpoint

Guard-function-first, `flash.php`-style:

```php
function bundle_preflight(array $in): array {
    // minimal — this is read-only, so mostly just "is bundling even possible"
    return ['ok' => true];
}
```

Then shell out to Step 2's script into a temp dir, zip, stream the file
for download (`Content-Disposition: attachment`), clean up per Step 1's
decision.

### Step 4: Settings page button

Next to the existing "Detected Hardware" row from plan 010, with the
anonymize checkbox and a one-line explanation of what's collected.

## Test plan

- `bash -n` on the new script.
- The PHP endpoint's guard function (however minimal) unit-tested the
  same way `flash_preflight` is.
- No golden fixtures to update — this plan doesn't touch any existing
  parser or contract.
- `bash tests/run.sh` stays green.

## Done criteria

- [ ] `scripts/capture*.sh` are untouched (`git diff` shows zero changes
      to those three files)
- [ ] `bash -n scripts/bundle_support.sh` → exit 0
- [ ] Anonymize option verified to strip serials/SAS addresses from at
      least one captured file in a test run
- [ ] Settings button downloads a zip containing the expected file set
- [ ] `bash tests/run.sh` → `--- all pass ---`

## STOP conditions

- The drift check prints anything.
- Any edit lands in `scripts/capture.sh`, `capture_storcli.sh`, or
  `capture_sysfs.sh` — those are out of scope entirely, not a base to
  build on.
- The bundle is written anywhere under the plugin's installed source tree
  (mirroring `capture.sh`'s `tests/fixtures` habit) instead of `/tmp` —
  a served PHP page must never write into its own source directory.

## Maintenance notes

- **Keep this script and `capture*.sh` conceptually separate even though
  they call similar binaries.** One writes into the repo for test-fixture
  regeneration by a developer; the other serves a zip to an end user from
  a running plugin. Merging them "to avoid duplication" would blur a
  distinction that matters for where each is allowed to write.
