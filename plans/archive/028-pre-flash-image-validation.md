# Plan 028: Validate the firmware image matches the detected card before flashing

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 8286fe7..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/flash.php source/usr/local/emhttp/plugins/hbaviewer/scripts/flash_hba.sh`
> Expected output: **nothing**. Every excerpt below is quoted from `8286fe7`
> (`dev` tip, 2026-07-30). Any difference is a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MEDIUM-HIGH by association — this plan touches the flash path,
  the one place a mistake bricks hardware. The validation itself is
  read-only and additive, but it sits directly next to the dangerous code.
- **Depends on**: none, but review with extra care given "Category"
- **Category**: safety / feature
- **Planned at**: `8286fe7`, 2026-07-31
- **Requested by**: external roadmap review

## Why this matters

`flash_hba.sh` already refuses an unknown chip and a missing tool, and
`flash.php`'s preflight already refuses an unconfirmed image, a running
array, and a concurrent flash. What it does **not** check is whether the
uploaded image is actually *for* the detected card. Flashing a 9300-8i's
image onto a 9305-16i (same chip family, different board) is exactly the
kind of mistake these guardrails don't currently catch, and it's a
one-way trip — the README's own warning says as much
("Flashing HBA firmware can permanently brick your controller").

## Current state

### `flash_hba.sh` — the existing `list` mode is the hook this plan needs

```bash
# flash_hba.sh list  <chip> <ctl>
#     Read-only preflight: run the resolved tool's listing so the user can
#     confirm it actually sees the card before touching anything.
```

This already exists and already runs before any flash — the "list"
preflight step surfaces the tool's own report of what it sees on that
controller (product string, current firmware version). **This plan adds a
second, automated comparison on top of that existing manual-eyeball step**:
today a human reads the `list` output and decides it looks right; this
plan parses it and the uploaded image, and refuses automatically on a
clear mismatch.

### `flasher_for_chip()` — chip family → tool mapping already exists

```bash
flasher_for_chip() {
    case "$1" in
        SAS2*)          echo sas2 ;;
        SAS30*|SAS31*)  echo sas3 ;;
        SAS34*|SAS35*)  echo storcli ;;
        *)              return 1 ;;
    esac
}
```

This maps a *chip family* (SAS2008, SAS3008, etc.) to a tool, which is
coarser than *board* identity (a 9300-8i and a 9305-16i can share a chip
family but need different images). **The chip-family match already
prevents the worst mismatches** (using the wrong tool entirely); this plan
is about the narrower, still-real gap of two boards sharing a chip family
but not an image.

### `flash.php`'s `flash_preflight()` — where a new check slots in

```php
function flash_preflight(array $in): array {
    if ((int) ($in['enable'] ?? 0) !== 1) return [...];
    if (empty($in['stopped'])) return [...];
    if (!preg_match('/^\d+$/', (string) ($in['ctl'] ?? ''))) return [...];
    $fw = (string) ($in['fw'] ?? '');
    if ($fw === '') return [...];
    if (strpos($fw, FLASH_DIR . '/') !== 0) return [...];
    if (!is_file($fw)) return [...];
    if (($in['confirm'] ?? '') !== 'FLASH') return [...];
    if (!empty($in['locked'])) return [...];
    return ['ok' => true, 'error' => ''];
}
```

A pure, ordered chain of checks over injected inputs — the established
pattern to extend, not replace.

## Scope

**In scope**:

- Parse the product/board identifier out of the `list` mode's existing
  output (it's already being run and already prints this — this plan
  reads it, doesn't add a new hardware call)
- Parse the equivalent identifier out of the uploaded firmware image
  (format is tool-specific — `sas2flash`/`sas3flash` images typically
  carry a product ID in a header; storcli-path SAS34xx/35xx images are a
  different format again — **confirm the actual header format per tool
  before writing a parser**, do not assume one format covers all three)
- Compare: **block** on a clear mismatch (image is for a different board
  entirely), **warn-and-require-extra-confirmation** on a version
  downgrade (sometimes intentional — recovery from a bad update — so this
  should not be a hard block)
- Surface both the detected board and the image's declared target in the
  UI before the existing `confirm === 'FLASH'` gate, so the human
  confirming has the comparison in front of them, not just an opaque
  "type FLASH"

**Out of scope**:

- Any change to `flasher_for_chip()`'s existing chip-family routing
- Any change to the actual flash execution path — this plan only adds a
  read-before-write check ahead of it
- Validating BIOS images separately unless they carry the same kind of
  identifiable header (check in Step 1; if not, scope BIOS validation out
  explicitly rather than half-implementing it)

## Step 1 findings (executor investigation, 2026-08-02)

No hardware, firmware images, or fixtures were available in the execution
environment, so this investigation is documentation-only (public specs,
kernel source, vendor manuals, and independently-corroborated forum
output), not a hands-on capture. Findings:

- **sas2flash / sas3flash images**: these images use the documented,
  actively-maintained `MPI2_FW_IMAGE_HEADER` struct from the Linux kernel
  (`drivers/scsi/mpt3sas/mpi/mpi2_image.h`, GPLv2, confirmed at
  `raw.githubusercontent.com/torvalds/linux/master/.../mpi2_image.h`).
  It has real, fixed offsets (`VendorID` @0x20, `ProductID` @0x22, an
  ASCII `FirmwareVersionName[32]` @0x68, etc.) and real signature magic
  (`MPI2_FW_HEADER_SIGNATURE0 = 0x5AFAA55A`). **However**, the only
  publicly documented `ProductID` bits are the `PID_FAMILY_MASK` values
  (2108/2208/3108/3324/3516 = chip family) — the same granularity
  `flasher_for_chip()` already routes on. No public mapping exists from
  `ProductID`/`PID_PROD_MASK` bits to a specific **board SKU** (e.g.
  9300-8i vs 9305-16i on the same SAS3008 chip), which is the actual gap
  this plan targets. Community reports of `sas2flash`/`sas3flash`
  refusing a mismatched image ("NVDATA Image does not match Controller
  SubSystem ID") happen **at flash time, against the live controller** —
  the SubSystem-ID check is the tool's own internal, undocumented logic,
  not something crackable from the file header alone with confidence.
  Board **model** identity (the `Board Name` field, e.g. "SAS9211-8i",
  "LSI3008-IT") is real and well-corroborated, but it comes from the
  tool's **live `-list` output against the card**, not from the file.
- **storcli (SAS34xx/35xx) images**: no public documentation of the
  firmware package's internal header format was found anywhere (Broadcom
  techdocs, StorCLI reference manuals, community reverse-engineering).
  What *is* documented: `storcli /cx download` takes `nosigchk` (skip the
  tool's own signature check) and `noverchk` (skip the tool's own
  version check, "must be provided when downgrading") — both prove storcli
  does its own signature/version validation, but **at flash time, against
  the live controller**, with an undocumented, proprietary check — not a
  file-only introspection mode.
- **Conclusion**: for all three tool paths, there is no publicly
  documented, reliable way to read a **board-level** identifier or the
  image's **own firmware version** from the file without either already
  flashing it or guessing at undocumented bits/format. Per this plan's
  own Step 1 escape hatch, both the board-mismatch and downgrade checks
  are scoped down for the **image side**:
  - Board mismatch: a **filename-only heuristic was tried and rejected**.
    An earlier version of this change compared numeric model tokens in
    the uploaded filename against the live-detected `Board Name` and
    blocked on a clash. That is unsound: LSI/Broadcom name SAS2 firmware
    files after the **chip** (e.g. `2118it.bin` for the SAS2008 family),
    while `Board Name` reports the **board** (e.g. `SAS9211-8i`) — two
    different numbering namespaces that do not share tokens even for a
    correct pairing. The heuristic hard-blocked the plugin's own flagship
    use case (IBM M1015 / Dell H200 -> `2118it.bin` IT crossflash) as a
    "mismatch", exactly the "false-blocks a legitimate flash" failure
    mode this plan's STOP conditions warn about, just reached through a
    filename guess instead of a header guess. There is no reliable public
    chip<->board lookup table to repair this with (that table is the same
    undocumented mapping this Step 1 already established doesn't exist,
    and a stale/incomplete one reintroduces the same false-block risk) —
    so filename-based board comparison is **not implemented as a block**.
    What remains real: `flash_detected_board()` parses the live board
    name out of the tool's already-running `list` output (Step 2,
    genuinely evidenced, no guessing) for a future UI to display next to
    the image the user picked, and `flash_preflight()`'s `board_mismatch`
    branch is pure/unit-tested and ready for a trustworthy signal — no
    caller currently sets it.
  - Downgrade: **not auto-detected**. There is no reliable source for the
    uploaded image's own firmware version, so `flash_preflight()` gained
    the `downgrade`/`confirm_downgrade` branch (pure, unit-tested, ready
    for a future trustworthy input) but the live HTTP dispatch never sets
    `downgrade` — it is explicitly left uncomputed rather than faked.
  - BIOS images: out of scope, per the plan's own "Out of scope" bullet —
    no identifiable header was found for BIOS ROMs either.

Sources consulted (public, no credentials/hardware involved): Linux
kernel `mpi2_image.h` (torvalds/linux, GitHub); SAS2Flash/SAS3Flash
Quick Reference Guides (Broadcom docs.broadcom.com, sandisk.com mirror);
Broadcom StorCLI 12Gb/s Tri-Mode techdocs and User Guide PDF; independent
real-world `sas2flash -list` / `sas3flash -list` output excerpts
corroborated across multiple TrueNAS Community / ServeTheHome threads
(HP H220, LSI SAS9211-8i, Supermicro SAS2116, SAS3008); Huawei OEM
documentation quoting `storcli /c0 show` output (`Product Name = SAS3004`).

## Steps

### Step 1: Confirm each tool's image header format (do this before any code)

This is the one piece of this plan with real uncertainty and real
consequences for getting it wrong:

- `sas2flash`/`sas3flash` images: confirm whether the product ID is
  readable from the image file itself (some LSI/Broadcom firmware images
  embed a header readable via `strings` or a documented offset) or only
  discoverable by running the tool's own `-list`/`-listall`-equivalent
  against the *file* (not the card) if such a mode exists
- storcli-path SAS34xx/35xx images: confirm the equivalent for storcli's
  firmware download format

**If a given tool has no reliable way to introspect an image file without
already flashing it, that tool's validation cannot be a pre-flash block —
scope it down to "compare filename/user-entered label only" for that path,
and say so explicitly rather than implementing a check that silently never
fires.**

### Step 2: Parse the `list` output for the detected board's identity

`list` mode already runs and already prints this (per the header comment
quoted above) — extract the product/board string from its existing
output, following the same "pure filter over tool text" pattern the
`parse/*.sh` scripts already use throughout this codebase (e.g.
`parse/hba.sh`'s `band_of`-style small pure functions) rather than
threading a second live tool call.

### Step 3: Extend `flash_preflight()`

```php
function flash_preflight(array $in): array {
    // ... existing checks, unchanged, in the same order ...
    if (($in['confirm'] ?? '') !== 'FLASH')
        return ['ok' => false, 'error' => 'Type FLASH (all caps) to confirm.'];
    // NEW — only reached once every existing gate has passed:
    if (!empty($in['board_mismatch']))
        return ['ok' => false, 'error' => "This image is for {$in['image_board']}, but the detected card is {$in['detected_board']}. Refusing to flash a mismatched image."];
    if (!empty($in['downgrade']) && ($in['confirm_downgrade'] ?? '') !== 'DOWNGRADE')
        return ['ok' => false, 'error' => 'This image is an older firmware version. Type DOWNGRADE to confirm this is intentional.'];
    if (!empty($in['locked'])) return [...];
    return ['ok' => true, 'error' => ''];
}
```

Keep `board_mismatch`/`downgrade` as **inputs the caller computes and
passes in** (from Steps 1–2's parsing), not something `flash_preflight()`
itself parses — preserving its existing pure-function-over-injected-inputs
shape, which is exactly what makes it unit-testable today.

**Verify**: unit tests covering match/no-op, mismatch/blocked,
downgrade-without-extra-confirm/blocked, downgrade-with-extra-confirm/
passes — extending whatever test file already covers `flash_preflight`.

## Test plan

- `flash_preflight()`'s new branches — pure, unit-tested exactly like its
  existing branches (same style: inject inputs, assert `ok`/`error`).
- Image-header parsing (Step 1's output, once confirmed) — fixture-tested
  the way other `parse/*.sh` filters are, if it ends up as a shell filter;
  or a direct PHP unit test if it's simple enough to inline.
- `bash tests/run.sh` stays green; the existing flash-preflight test cases
  must be unaffected by the new branches sitting after them in the chain.

## Done criteria

- [ ] Step 1's per-tool header-format investigation completed and
      documented in this plan's file (or a linked follow-up) before any
      parsing code is written
- [ ] Any tool where reliable pre-flash introspection isn't possible is
      explicitly scoped out, not silently unimplemented
- [ ] `flash_preflight()`'s existing checks and their order are unchanged;
      new checks are appended after `confirm === 'FLASH'`, before `locked`
- [ ] New unit tests: mismatch blocks, downgrade requires second
      confirmation, matching image passes through unaffected
- [ ] `bash -n` / `php -l` clean
- [ ] `bash tests/run.sh` → `--- all pass ---`, all existing flash-related
      goldens/tests unchanged

## STOP conditions

- The drift check prints anything.
- Step 1 cannot confirm a reliable image-introspection method for a given
  tool and the executor is tempted to guess at a header offset or format.
  A wrong guess here either false-blocks a legitimate flash or — worse —
  false-passes a genuinely mismatched image. Stop and report the gap.
- Any change to `flasher_for_chip()`, the array-stopped gate, the
  single-flight lock, or the actual `flash_hba.sh` execution path — this
  plan is additive validation only; none of the existing hard guardrails
  are in scope to modify.
- The new checks are inserted *before* the existing `confirm === 'FLASH'`
  gate rather than after — the existing confirmation flow should see the
  comparison, not be bypassed by it.

## Maintenance notes

- **This plan sits next to the plugin's single highest-consequence code
  path.** Any reviewer picking this up should read `flash.php` and
  `flash_hba.sh` in full — not just the excerpts here — before approving,
  the same caution the existing flash guardrails were clearly written
  with (see their own comments on atomic locking, array-stopped fail-
  safe, and confined filenames).
- **Downgrade is a warn, not a block, on purpose** — recovering from a
  bad firmware update is a legitimate reason to flash backwards, and a
  hard block would remove the plugin's usefulness for exactly the
  scenario it exists to help with.
