# Plan 026: Complete diagnostic bundle, with consistent anonymisation

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat fe90641..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/settings.php scripts/capture.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh`
> Expected output: **nothing**. Every excerpt below is quoted from `fe90641`
> (`dev` tip, 2026-08-01). Any difference is a STOP condition.
>
> **Rewritten 2026-08-01.** The earlier version had anonymisation as a one-line
> placeholder (`:`) with a note to "confirm exact patterns". The maintainer
> asked for a *complete* diagnostic generator with real anonymisation, and the
> naive version of that feature destroys the bundle's value — see "Why naive
> redaction is worse than none".

## Status

- **Priority**: P2
- **Effort**: M (was S — the anonymisation design is the bulk of it)
- **Risk**: LOW-MEDIUM — read-only collection, but it produces a file the user
  hands to strangers. The risk here is *disclosure*, not corruption.
- **Depends on**: none
- **Category**: feature
- **Planned at**: `fe90641`, 2026-08-01
- **Requested by**: maintainer. Motivated by issues #3, #5 and #6, each of
  which took multiple round-trips of hand-pasted terminal output — and in #5's
  case, the reporter manually X-ing out their own serials.

## Correcting an assumption this plan started from — read first

The original framing assumed `scripts/capture*.sh` could be exposed as the
bundle generator. **It cannot, and this plan does not.**

```bash
# scripts/capture.sh
# Capture REAL lsiutil output from the Unraid box into test fixtures.
PORT="${1:-1}"
OUT="${2:-tests/fixtures}"
LSIUTIL="/usr/local/emhttp/plugins/hbaviewer/hbaviewer.x86_64"
```

Those live at **repo root** `scripts/` — not in the plugin tree — write into
`tests/fixtures/`, and exist so the maintainer can regenerate goldens from real
hardware. Serving them from a PHP request would mean writing into the installed
source tree from a web context. Build fresh; reuse the *idea*, not the files.
**`capture*.sh` must not be modified.**

## What "complete" means here

Every issue this project has diagnosed was solved the same way: **compare the
raw tool output against what the parser made of it.** #3 was a `proc_name`
mismatch, #5/#6 an enclosure-less address form, #8 PHY counters bleeding into
the temperature badge. In each case the raw text alone was not enough, and the
parsed JSON alone was not enough.

So the bundle captures **both, side by side**, for every source.

### Section 1 — environment

| Item | Source |
|---|---|
| Kernel / Unraid version | `uname -a`, `/etc/unraid-version` |
| Plugin version | `/boot/config/plugins/hbaviewer.plg` |
| storcli present + version | `find_storcli`'s search list, then `storcli -v` |
| lsiutil present | the bundled `hbaviewer.x86_64` |
| Loaded driver + version | `/sys/module/{mpt3sas,mpt2sas,mptsas}/version` |
| Per-host `proc_name` | `/sys/class/scsi_host/host*/proc_name` |

`proc_name` is listed explicitly because plan 010 proved it is the honest
SAS2-vs-SAS3 signal, and it is the first thing to check on any "controller not
detected" report.

### Section 2 — raw tool output, one file per command

Mirror what the composers invoke, writing to files instead of piping into
parsers. **Read the composers to derive the current list** rather than trusting
this table, but it should come to:

```
storcli show
storcli /cN show                     storcli /cN show all
storcli /cN show temperature         storcli /cN/pall show
storcli /cN/eall show all            storcli /cN/eall/sall show all
storcli /cN/sall show all
lsiutil banner (via `printf '0\n' |`)
lsiutil -b
lsiutil -p<PORT> -a 25,2,0,0         lsiutil -p<PORT> -a 20,12,0,0
lsiutil -p<PORT> -a 42,0             lsiutil -e -p<PORT> -a 35,0
lsblk -S -P -o NAME,WWN,SERIAL,MODEL
```

`/cN/eall/sall` **and** `/cN/sall` are both captured deliberately. They are
complements, not alternatives — that distinction is the entire content of plan
017, and a bundle capturing only one would have been useless for #5/#6.

### Section 3 — sysfs

`/sys/class/scsi_host/host*/`, `/sys/class/sas_phy/`,
`/sys/class/sas_end_device/`, and the controller's
`/sys/bus/pci/devices/*/current_link_{width,speed}`, `max_link_*`,
`power_state`. Capture a file listing plus the contents of each leaf.

### Section 4 — what the plugin made of it

Run each composer and save its JSON: `get_hba_info.sh`, `get_phy_health.sh`,
`get_attached_drives.sh`, `get_hba_health.sh`, `get_event_log.sh`. Plus the
plugin's own `/boot/config/plugins/hbaviewer/hbaviewer.cfg` — its own file,
small, and `HBA_PORT` / `ALERT_THRESHOLD` change what the composers do.

### Section 5 — SMART (optional, off by default)

`smartctl -a` is ~1s per drive and spins up standby disks. Separate checkbox,
default **off**, and use `smartctl -n standby -a` so a sleeping drive is
reported as sleeping rather than woken — matching `collect_smart.sh`.

## Why naive redaction is worse than none

**This is the most important section of this plan.**

The obvious implementation — regex each file, replace serials with `XXXX` —
produces a bundle that looks anonymised and is diagnostically worthless, for
two independent reasons.

**1. It breaks the joins.** The plugin's hardest bugs live in the join between
PHY data and drive data, keyed on **SAS address**. `drives_join.sh`'s own
header calls that join "where the historical drive bugs lived". If the same SAS
address becomes a different placeholder in `phy.txt` than in `drives.txt` — or
the same opaque `XXXX` as every *other* address — the one relationship a
debugger needs is destroyed. Per-file regex does exactly this.

**2. It breaks column alignment.** storcli pads to fixed columns and the
parsers key on that structure. Replacing a 16-character SAS address with
`REDACTED` shifts every field after it. A bundle that reflows columns cannot be
dropped in as a test fixture — which is precisely what plan 036 does with
@t0ffemannen's output from issue #5.

### Required behaviour: stable, length-preserving pseudonyms

- Build **one map for the whole bundle**, before rewriting any file.
- Each distinct real value gets a distinct token: SAS addresses →
  `5000000000000001`, `5000000000000002`, …; serials → `SERIAL0000000001`,
  padded to **exactly the original's length**.
- Apply that one map across **every file**, so a given drive is the same
  pseudonym in sysfs, in storcli output, and in the parsed JSON.
- **The map is never written into the bundle.** It lives in memory only.

The result: every cross-reference still resolves, every column still lines up,
nothing identifies the machine.

### What is anonymised, and what deliberately is not

| Anonymise | Keep |
|---|---|
| Drive serial numbers | Drive **models** — which model misbehaves is the finding |
| WWNs | Sizes, firmware versions, link rates |
| SAS addresses | Temperatures, error counters |
| Controller serial / Board Tracer Number | PCI addresses (`00:02:00:00` — topology, not identity) |
| Hostname | Slot / enclosure numbers |

Keeping models and firmware is not an oversight — a bundle hiding them could
not have diagnosed a single issue this project has closed.

### Never collected at all

**Do not capture, under either setting**: the Unraid flash GUID, the licence
key, `/boot/config/ident.cfg`, `/boot/config/super.dat`, `/boot/config/shadow`,
network configuration, share names, or any `/boot/config` file other than the
plugin's own `hbaviewer.cfg`. These are not "anonymise if asked" — they are out
of scope for a controller diagnostic and must never enter the bundle.

**If you find yourself writing a glob over `/boot/config`, that is a STOP
condition.**

## Scope

**In scope**:

- New `source/usr/local/emhttp/plugins/hbaviewer/scripts/bundle_support.sh`
  (inside the plugin tree, unlike `capture*.sh`).
- A small `bundle.php` endpoint, guard-function-first, following `flash.php`'s
  structure and `phy_baseline.php`'s POST-gate precedent.
- Settings controls: "Generate diagnostic bundle" button, **Anonymise**
  checkbox (default **on**), **Include SMART** checkbox (default **off**).
- Output to `/tmp`, streamed as a download, then deleted.

**Out of scope** — do not touch:

- `scripts/capture.sh`, `capture_storcli.sh`, `capture_sysfs.sh` at repo root.
- Any parser, composer, or renderer. The bundle **calls** them; it does not
  change them.
- Any upload or attach-to-GitHub flow. The user downloads and attaches manually.
- `flash.php` and anything on the flashing path.

## Steps

### Step 1: confirm the archiver exists

**Do not assume `zip` is present.** Unraid ships a minimal userland. Check for
`zip`; if absent use `tar czf` and name the file `.tar.gz`. Report which you
found. A bundle that silently produces a zero-byte archive because `zip` is
missing is worse than no feature at all.

**Verify**: `command -v zip || command -v tar`, and state which the script uses.

### Step 2: the collector, without anonymisation

Collect Sections 1–4 into a temp directory. Get it complete first; anonymisation
is a separate pass over the finished directory.

Every command must be **read-only**, and one missing tool must not fail the run
— a box without storcli should still produce a bundle with the lsiutil half
populated and a note recording that storcli was absent.

**Verify**: every expected file exists; none is zero-byte without an
explanatory note beside it.

### Step 3: the anonymisation pass

A separate function over the completed directory:

1. Scan **all** files, collecting every distinct SAS address, WWN and serial
   into one map, each with a length-matched pseudonym.
2. Rewrite every file using that single map.
3. Write `ANONYMISED.txt` listing which *classes* were replaced — never the map.

Patterns to detect, at minimum. **Confirm each against real output in
`tests/fixtures/storcli/` before relying on it:**

- `SAS Address = 5003005702960060`
- `WWN="0x5000c500a1b2c3d4"` (lsblk `-P` form)
- `Serial Number = …`, `SERIAL="…"`
- sysfs `sas_address` contents (`0x…`, sometimes lower-case — the parsers
  upper-case it, so match case-insensitively)

**Verify**, and treat this as the step's real gate:

```bash
# alignment survived — empty output means every line kept its length
diff <(awk '{print length}' before.txt) <(awk '{print length}' after.txt)
```

Also confirm the same input address maps to the same token in two different
files, and that `grep` for the originals returns nothing. **This is the check
that distinguishes a working implementation from a plausible one.**

### Step 4: the endpoint and the Settings controls

Follow `phy_baseline.php`'s gate exactly:

```php
if (PHP_SAPI === 'cli') return;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['make_bundle'])) return;
```

**Do NOT add a CSRF check.** Unraid's auto-prepended `local_prepend.php`
already `hash_equals`-checks every POST and then `unset()`s the token. Plan 009
added its own check and it denied every settings save; it was reverted and
marked "do not re-attempt".

The controls go in the two-column grid plan 034 established, as a new
`.lu-s-card`. Decide whether it pairs beside Notifications or spans, and say
which and why.

### Step 5: clean up

Delete the temp directory and archive after streaming. `/tmp` is RAM-backed on
Unraid, so a leaked bundle costs memory until reboot.

## Test plan

- `bash -n` on the new script; `php -l` on the new endpoint.
- **Unit-test the anonymisation pass as a pure function over fixture text.**
  This is the part testable without hardware, and the part where a bug is
  invisible until someone's serial is already on GitHub. Cover: same value in
  two files → same token; different values → different tokens; line lengths
  unchanged; originals absent afterwards; a value appearing in both a raw file
  and a parsed JSON file → same token in both.
- Follow the repo's CLI harness (`tests/run.sh`, `tests/run_php.sh`) — **not**
  PHPUnit. See `tests/health_test.php` for the `check(...)` convention.
- `bash tests/run.sh` → `--- all pass ---`, no golden moved.

## Done criteria

- [ ] Bundle contains Sections 1–4; SMART only when its box is ticked
- [ ] Both `/cN/eall/sall` and `/cN/sall` captured
- [ ] With Anonymise on, `grep` for any real serial, WWN or SAS address from
      the host returns **nothing** anywhere in the bundle
- [ ] The same real value yields the **same** token in every file
- [ ] `awk '{print length}'` identical before and after the pass
- [ ] The pseudonym map appears nowhere in the bundle
- [ ] Nothing from `/boot/config` except `hbaviewer.cfg`
- [ ] A box missing storcli still produces a usable bundle
- [ ] `bash tests/run.sh` → `--- all pass ---`, `git diff -- tests/expected/` empty
- [ ] `bash -n` / `php -l` clean

## STOP conditions

- The drift check prints anything.
- A glob over `/boot/config` appears anywhere.
- Anonymisation is implemented per-file rather than with one bundle-wide map.
- Line lengths change under anonymisation.
- Any CSRF check is added.
- `capture*.sh`, any parser, any composer, or `flash.php` is modified.
- `zip` is assumed present without a check.

## Maintenance notes

- **The anonymisation map is the security-critical part of this plugin.** It is
  the only code whose failure publishes a user's hardware identifiers to a
  public issue tracker. It deserves the review posture of the flashing guards,
  and its tests must never be weakened to make a refactor pass.
- **The bundle's value depends on capturing raw and parsed together.** If a
  future change drops the parsed JSON to save space, the bundle stops being
  able to answer the question every past issue needed answering.
- **New composer → new bundle section.** The capture list mirrors the
  composers; when one is added this script keeps working while quietly becoming
  incomplete. Worth a line in any plan that adds one.
