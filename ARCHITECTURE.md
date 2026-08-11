# HBAviewer — Architecture

How the plugin is put together, and the conventions a change is expected to
follow. For *using* it see [HOWTO.md](HOWTO.md); for the short definitions of
individual modules see
[`source/usr/local/emhttp/plugins/hbaviewer/CONTEXT.md`](source/usr/local/emhttp/plugins/hbaviewer/CONTEXT.md),
which this document does not repeat.

## The shape of it

Bash reads hardware. Awk turns text into JSON. PHP turns JSON into HTML. That
separation is the whole design, and most of the rules below fall out of it.

```
hardware ──► composer (bash)  ──► parser (awk)   ──► endpoint (PHP) ──► browser
             storcli/lsiutil      pure filter        renderer/JSON
             sysfs, /proc         text -> JSON       + cache policy
```

There is no daemon, no database and no scheduled sampler. Every read happens
because someone opened a page, with one exception: the opt-in notification cron.

## Layers

### 1. Composers — `scripts/get_*.sh`

One per tab: `get_hba_info.sh` (Overview), `get_hba_health.sh`,
`get_phy_health.sh`, `get_attached_drives.sh`, `get_event_log.sh`,
`get_metrics.sh` (Performance).

A composer declares **what to read per controller for each backend** and hands
the captured text to a parser. It does not decide *which* backend — that is
`lib.sh`'s job.

### 2. The backend seam — `scripts/lib.sh` (`hba_each`)

The single place that chooses **storcli** (SAS3/3.5) or **lsiutil** (SAS2),
counts controllers, resolves the driver string, and wraps everything in
`{"backend", "driver", "controllers": [...]}`.

Two things about backend selection that have each caused a bug:

- **Selection is by driver personality (`proc_name`), not by which kernel module
  is loaded.** The merged `mpt3sas` module reports `proc_name=mpt2sas` for SAS2
  cards, and the bundled lsiutil reads those fine. An earlier check keyed on
  `/sys/module` refused hardware it could have read.
- **storcli being installed does not mean storcli is in use.** It does not
  enumerate IT-mode SAS2 cards, so `hba_each` falls through to lsiutil. Never
  infer the active backend from the binary's presence — read the `backend` field
  in the payload.

### 3. Parsers — `scripts/parse/*.sh`

**Pure filters: text on stdin (or as file arguments), JSON on stdout.** No
hardware access, no environment, no side effects. That is what makes them
testable without a controller, and it is not negotiable — a parser that shells
out cannot be fixture-tested and will rot.

Two families, because the two backends produce different text:

| lsiutil | storcli |
| --- | --- |
| `hba.sh`, `phy.sh`, `events.sh`, `drives_osmap.sh`, `drives_join.sh` | `storcli_overview.sh`, `storcli_phy.sh`, `storcli_drives.sh`, `storcli_enclosures.sh`, `storcli_events.sh` |

Shared: `smart.sh`, `diskstats.sh`, `cache_temps.sh`.

They require **GNU awk** — three-argument `match()` is used deliberately. CI
installs it; Unraid's Slackware base ships it.

### 4. Endpoints — PHP at the plugin root

| File | Role |
| --- | --- |
| `ajax_info.php` | The main dispatch. `?type=overview\|overview_html\|health\|phy\|drives\|baymap\|events\|smart\|smart_all\|metrics` → JSON or an HTML fragment. Read-only. |
| `view.php` | Presentation helpers shared by the Monitor, the dashboard tile and the AJAX refresh — `lsi_controllers()`, `lsi_hba_view()`, colours, bands. |
| `cached_read.php` | Freshness + single-flight lock + atomic swap, returning `{state: ready\|warming, body}`. |
| `card_group.php` | Which controllers are one physical CARD. A SAS9300-16i is one board carrying two SAS3008 IOCs — two PCI functions, two indices, two temperature sensors — and only the display should say "one card". `lsi_group_cards()` buckets by PCI root port **and** board name and merges only when the count matches the index's `ioc_count` exactly, because a riser can put two genuinely separate cards behind one root port. Everything unrecognised stays split. Consumed by the Overview (`renderOverviewCards`) and by the firmware page's JSON, which is what makes a dual-IOC board verify and flash as one card. |
| `health.php` | The five indicators, the rolling sample ring, the rollup. |
| `phy_baseline.php` | The `/boot` baseline store, delta and rate maths, reset detection. |
| `bay_map.php` | The `/boot` drive-bay assignment store, the identity key, the grid size and the lock. Second mutating path after `flash.php` — see below. |
| `event_archive.php` | Persists the firmware event ring to `/boot`; pure `event_merge()`. |
| `export.php` | Read-only JSON / Prometheus snapshot. |
| `bundle.php` | Diagnostic bundle transport (collection lives in `scripts/bundle_support.sh`). |
| `notify.php`, `scripts/notify_check.php` | Health-transition notifications (cron). |
| `flash.php` | **The only mutating path.** See below. |
| `config.php`, `settings.php`, `dashboard.php`, `hbaviewer.php` | Settings schema, settings page, dashboard tile, Monitor page markup. |
| `hbaviewer.js` | The Monitor page's behaviour — tabs, the bay map, Locate, the SMART and Performance polls. One IIFE, no modules, no build step. |
| `chrome.css` | The shared look — design tokens, cards, tables, tabs. Linked by the Monitor and the firmware page; pure CSS with no PHP, which is what lets it be a static file rather than an include. |
| `flash_view.php`, `HBAviewer_Flash.page` | The firmware page: markup and its own CSS. A page rather than a tab, `Cond`-gated on `ENABLE_FLASH` so the route does not exist when flashing is off. Declares `Menu="HBAviewer"`, so it is a standalone page under Tools alongside the Monitor — the same shape `HBAviewer_Monitor.page` uses. **Not** `Menu="Utilities"` (planted a second icon in Settings → Utilities that bypassed the danger notice) and **not** `Menu="HBAviewer_Settings"` (Unraid stacks the children of an `xmenu` parent onto one page, so the whole flash page rendered inline below the settings form). `Menu=` also decides the URL root, so `/Tools/HBAviewer_Flash` is hardcoded in two places — the Monitor's tab and the Settings button. All three are pinned together in `flash_php_test.php`. |
| `flash_view.js` | The firmware page's behaviour — the four-step wizard and the flash poll. Everything here is keyed by `c.ctl`, the controller number(s) the card covers (`"0,1"` on a dual-IOC board), never by the array index: `?type=overview` returns one entry per CARD, and a card's position in that array is not a controller number. |

**The house pattern for an endpoint that both mutates and shares helpers**
(`phy_baseline.php`, `bundle.php`, `flash.php`, `export.php`): pure functions at
the top, `if (PHP_SAPI === 'cli') return;` in the middle, HTTP dispatch at the
bottom. PHP hoists top-level function declarations, so the test runner can
`require` the file and get the functions without ever reaching the dispatch.
`ajax_info.php` includes several of these purely for their helpers.

**A `const` used by an endpoint must be declared ABOVE the dispatch guard.**
Function declarations are hoisted; top-level `const` statements are not — they
exist only once execution reaches them. A const declared next to the functions
that use it is therefore undefined for every endpoint above it, and the failure
is a fatal on a function that looks perfectly well defined. This shipped once
and blanked the SMART tab. It is worse for a const used as a **default
parameter value**, which resolves at call time, so the call site's position is
what matters rather than the function's. Both cases are asserted in
`tests/ajax_render_test.php`; the guard is that anything visible to the CLI test
runner is by definition above the dispatch.

**CSRF is not checked in plugin code, deliberately.** Unraid auto-prepends
`local_prepend.php`, which `hash_equals`-checks every POST and then unsets the
token. A plugin-side check was added once and denied every settings save; it was
reverted and is marked do-not-re-attempt.

## Request lifecycle

The expensive reads (overview, drives) go through `cached_read()`:

1. Fresh, non-empty result on disk → serve it.
2. Otherwise take a lock, launch **one** detached producer that writes to a temp
   file and `mv`s it into place, and return `{state: warming}` immediately.
3. The browser polls the `warming` marker; the foreground never blocks.

Consequences worth knowing before changing anything here: the atomic
tmp→rename means a reader never sees a half-written file; `-s` not `-f` means a
truncated result is never served; and the cache is invalidated by **code mtime**
as well as age, so pushing new files takes effect immediately instead of after
60 seconds.

## The mutating paths

Three, and only one of them writes to hardware.

`bay_map.php` writes the drive-bay layout to `/boot` on a POST. It is not a
hardware path, but it holds the one thing here that **cannot be regenerated**:
where each drive physically sits, which a person established by walking to the
rack. So it fails safe in its own way — the lock is enforced in the dispatch
rather than by greying out the UI (a stale tab can still POST), keys are
validated against the shape `bay_map_key()` produces before they become object
keys in a file on flash, and a position outside the current grid is rejected
rather than clamped.

`locate.php` + `scripts/locate_drive.sh` spawns a root process per drive and
later signals it by PID. It persists nothing — the marker is a PID file in
`/tmp` — but it mutates in two senses: it keeps a drive awake for as long as it
runs, and it sends signals as root. Both are bounded server-side. The loop
stops itself after `LOCATE_MAX_SECS` (schema-clamped), so a closed browser tab
cannot leave a disk spinning. Stop signals only a PID whose
`/proc/<pid>/cmdline` names `locate_drive.sh` — **that check is about PID
reuse, not `/tmp` permissions**: a loop killed with `-9` skips its own cleanup
trap and leaves a marker holding a number the kernel will later hand to
something else. And a start that cannot happen — no `/dev/bsg` node for that
address — answers `ok:false` with a reason instead of reporting success,
because a locate that silently does nothing reads as "the light is too subtle
to see".

`flash.php` + `scripts/flash_hba.sh` is the only code that writes to hardware,
and it is kept off the read-only path on purpose. Guards are pure functions,
unit-tested, and enforced **server-side**:

- opt-in toggle (`ENABLE_FLASH`, default off)
- array must be STOPPED, failing closed on a missing or unreadable `var.ini`
- read-only verify scoped to the target **card** — every controller on it, and nothing else
- controller argument validated by one `flash_ctl_list()` both call sites share — shape (`/^\d+(,\d+)*\z/`; `\z`, not `$`, or a trailing newline slips through), size (`LSI_MAX_IOCS`) and uniqueness
- and, on the mutating action only, **membership**: `flash_ctl_is_card()` requires the posted list to *be* one of the groups `lsi_group_cards()` derives from the live hardware at flash time
- typed `FLASH` confirmation plus an acknowledgement checkbox
- single-flight lock, never auto-retried
- image filenames confined to one directory (`flash_safe_name()`)

**A dual-IOC board is one card and is flashed as one.** A SAS9300-16i carries
two SAS3008 controllers, and leaving one of them behind would leave the board
running two firmware versions. `flash_hba.sh` therefore takes a comma-separated
controller list and **loops it** — deliberately *not* `-fwall`, which Broadcom
recommends for these boards: `-fwall` means every controller in the *system*, so
on a box holding a 9300-16i and a 9300-8i it would write the 16i image to the 8i
and brick it. `-listall` is barred from the verify path for the same reason.
Both absences are asserted in `flash_php_test.php`, against the script with its
**comments stripped** — the prose has to be free to name the flags it is arguing
against.

That argument is only true if the list really is one card's own controllers, and
shape validation cannot tell: `0,1,2,3,4,5,6,7` is a well-formed list and
reproduces the exact `-fwall` blast radius. So the flash action re-derives the
grouping from the live hardware and requires the posted list to **be** one of
those groups, by exact string equality. Half a dual-IOC card is not a card. No
groups (backend error) refuses everything — the same read is what drew the card
the operator pressed Flash on, so failing closed costs nothing real. The
read-only `listall` action skips this: it writes nothing, and a full hardware
read before every Verify press would put a minute on the quick button. Its
fan-out is bounded by the size and uniqueness checks instead.

The loop's own hazard is a **partial flash**: the second write failing after the
first succeeded. It has its **own exit code, 7**, which `flash.php` turns into
`done: 'partial'` and the page into its own red banner. 6 — "nothing was
written" — is the safe outcome, and sharing one code made the two
machine-indistinguishable with only free text between a safe retry and a dead
card. The partial message names which controller holds which half and tells the
operator not to reboot. A failure on the *first* write stops there.

`flash_rc` is reset **inside** the loop, not merely left unset. It is assigned
only on failure, so a value inherited from the environment made iteration 1
report a successful write as "nothing was written" — a lie about what is on the
hardware, which is worse than any error this path can return.

The greyed-out Step 3 in the UI is an **affordance**, not a control. Deleting
all of that CSS must still leave flashing blocked. If a change ever makes the
client-side state load-bearing, the safety model has been inverted.

## Data that persists

| Location | What | Why there |
| --- | --- | --- |
| `/boot/config/plugins/hbaviewer/hbaviewer.cfg` | Settings | Survives reinstall |
| `/boot/.../phy_baseline.json` | User-set PHY baselines | A deliberate reference point must outlive a reboot |
| `/boot/.../events_c*.json` | Firmware event archive | History survives ring-buffer wrap |
| `/boot/.../bay_map.json` | Drive bay assignments | The only state here that cannot be re-read from hardware — a person put it there |
| `/tmp/hbav_*`, `/tmp/lsiutil_*` | Caches, health ring | RAM; no flash wear, dies with the boot |

Everything under `/usr/local/emhttp/plugins/hbaviewer/` is **tmpfs** — a reboot
reinstalls it from the `.txz` on flash. Patching files in place is a valid way
to test a branch, and it evaporates on reboot by design.

## Testing

```bash
bash tests/run.sh        # parser goldens + PHP unit tests; no hardware needed
```

Two halves:

- **Golden tests** feed a fixture to a parser and diff stdout against
  `tests/expected/`. A dropped or renamed JSON field fails here.
- **PHP unit tests** (`tests/*_test.php`) exercise the pure functions.
  Registered in **both** invocation lines of `tests/run_php.sh` — the local-`php`
  line and the Docker fallback. Missing the second is how a test silently never
  runs in CI.

Conventions that exist because of specific past bugs:

- **Fixtures are evidence, not editable test data.** Prefer real captured
  output; mask identifiers **length-preservingly** so column alignment survives,
  because the parsers key on column position. A hand-modelled fixture once
  encoded a PCIe Gen5 link on a 2012 SAS2 card and hid a real decode bug.
- **A golden that moves is a finding**, not something to re-bless. Regenerate
  individual goldens rather than running `UPDATE=1`, which rewrites all of them.
- **Mutation-test a new guard.** Break it deliberately and confirm exactly the
  intended case fails. Several tests in this repo pass whether or not the code
  works until you check that.
- **Windows/NTFS forbids `:` in filenames.** Fixtures needing SCSI or PCI
  addresses in a path must be generated at runtime under `mktemp -d`, never
  committed — MSYS silently substitutes a lookalike character and git stores the
  mangled bytes.

CI (`.github/workflows/php.yml`) lints every `.php` with `php -l`, every `.sh`
under `source/` and `tests/` with `bash -n`, every `.js` with `node --check`,
and runs the suite on Linux. Above that syntax tier sit ShellCheck, PHPStan and
actionlint; Codacy adds jshint on the `.js` files out of band. The rule the
`.js` tier exists to hold is **no first-party code in a language nothing
analyses** — the JavaScript spent its first year inside two `.php` files, where
linguist counted it as PHP, PHPStan saw inert markup and jshint never opened it
(plan 057).

## Release

Date-versioned, cut from `main`:

1. Merge `dev` → `main`.
2. Add a `###YYYY.MM.DD###` block to `<CHANGES>` in `hbaviewer.plg`, commit, push.
3. `git tag YYYY.MM.DD && git push --tags`.

The tag must point at `main`'s tip. The workflow then runs the tests, builds the
`.txz`, patches the `version` / `md5` / `pkgURL` entities in `hbaviewer.plg` on
`main`, and publishes the release. **No `<CHANGES>` block, no release** — that is
the forcing function for the changelog Unraid actually displays to users.

Unraid clients poll the `.plg` on `main`, so that patch commit is what ships.

## Where the sharp edges are

- **Two backends, different keys.** The storcli drives payload has no `phy`
  field; joining PHY data to drives there goes through the SAS address, and an
  exact match fails — the PHY reports the port address it is attached to while
  storcli's `WWN` reports the drive's other port, differing in the last hex
  digit by a vendor-specific amount. The join compares the first 15 digits and
  **fails closed** when that prefix is not unique.
- **A PHY number is only unique within the device that numbered it.** On the
  lsiutil path a drive's `phy` is `phy_identifier` from sysfs, and an expander
  numbers its own PHYs from zero exactly as the HBA does. Two drives behind two
  expanders report the same number. So the drives payload also carries
  `expander` — the expander's SAS address, empty when direct-attached — and
  `bay_map_key()` folds it in: `c0:h26` direct, `c0:x<addr>h26` behind an
  expander. Sysfs distinguishes the two by the shape of the device name,
  `end_device-H:N` versus `end_device-H:N:M`, the same rule that separates
  `phy-H:N` from `phy-H:N:M`. Measured on reporter hardware: 19 drives behind
  two expanders, seven colliding numbers. **Anything that joins on `phy` alone
  is wrong for expander-attached drives** — `phy_drive()` skips them for
  exactly this reason.
- **Absence is not health.** Unknown, unmeasured and stale states must render as
  grey or be omitted — never as green, and never as a zero that reads like a
  measurement. A card that answers `IOCTemperature: 0x0000` has no sensor, not a
  temperature of zero; SAS2008 prints the field regardless (#17).
- **The `.js` files depend on the PHP that precedes them.** `hbaviewer.php` and
  `flash_view.php` each render a short inline `<script>` — `luCsrf`, and
  `flashArrayStopped`/`flashCsrf`/`lock*` — and then load the static file, which
  reads those as globals. That is the entire reason the split works without a
  templating step, and it is also the whole load-bearing assumption: **the inline
  block must stay above the `<script src>`, and anything the PHP interpolates
  must be declared there rather than moved out.** `$csrfToken` is read
  unconditionally at the top of both files, never inside a feature flag — it was
  scoped inside `if ($enableFlash)` once, which silently made the bay map depend
  on Unraid's `csrf_token` global existing (plan 055). `tests/view_test.php`
  checks every `<script src>` resolves to a file that ships; a typo there renders
  a page that looks perfectly normal and does nothing at all.
- **`max_link_width` is the CARD's maximum, not the slot's.** Judging the
  negotiated PCIe link against it means comparing a card with itself, so an x8
  card in an x4 slot — ordinary on OEM boards and unfixable — reads as a
  permanent fault (#13), and the same bug calls a chipset-limited x4 "full
  width" (#14). The slot's own ceiling comes from the upstream bridge, one
  directory up in sysfs. **Judge against `min(card, slot)`, never the slot
  alone**: cards in slots *wider* than themselves are at least as common — the
  maintainer's are x8 in x16, and one reporter's Gen3 card sits in a slot
  advertising Gen5 — and trusting the slot would call all of those downtrained.
  Samples predating `slot_width`/`slot_speed` fall back to the card maximum,
  because the ring survives upgrades and old entries must not become faults.
- **The SAS2 path has almost no hardware coverage.** The maintainer's box is
  SAS3/storcli, so the lsiutil branches are fixture-tested only and are
  effectively verified by reporters on the issue tracker. Flag changes there in
  the release notes.
- **`VirtualSES` means nothing about capability.** An HBA-synthesised enclosure
  can expose real, writable sysfs slot attributes with no LED wired behind them.
  Ask the kernel what exists; do not infer from a product string.
