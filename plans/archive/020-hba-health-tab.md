# Plan 020: HBA Health tab — five sub-indicators with a worst-of rollup

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 8286fe7..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php source/usr/local/emhttp/plugins/hbaviewer/scripts/get_phy_health.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh tests/run_php.sh`
> Expected output: **nothing**. Every excerpt below is quoted from `8286fe7`.
> Any difference is a STOP condition.

## Status

- **Priority**: P2
- **Effort**: L
- **Risk**: MEDIUM — a new tab, a new composer, and the first stateful logic in
  the plugin (a RAM-backed sample ring; nothing is written to disk)
- **Depends on**: 018 (DONE — supplies `temp_band`), 019 (DONE — supplies `lsi_time`)
- **Category**: feature
- **Planned at**: `8286fe7`, 2026-07-30
- **Spec**: maintainer-supplied "HBA health indicator — design spec", reconciled
  in `plans/README.md` under "Plan 020 notes"

## Why this matters

The plugin currently reports one green/amber/red pill per controller whose cause
is invisible. Issue #8 was exactly this failure: a PHY error counter turned the
badge amber, the user read it as a temperature problem, and nothing on screen
could have told them otherwise. Plan 018 stopped the thermometer lying; it did
not make the badge explain itself.

This replaces the single pill with **five independently evaluated sub-indicators
and a worst-of rollup**, where the rollup's label names its own cause —
`Warning — PHY 4 errors rising`, not `Warning`.

The single highest-value piece is the **`unknown` state**. Today a controller
that cannot be read, or a card that has been pulled, produces a green badge by
default. That is worse than useless. Every indicator carries a collection
timestamp and goes grey when its data is stale or its read failed.

## Decisions already taken (do not re-open)

These were settled against the real hardware; the reasoning is in
`plans/README.md`. Re-deriving them will produce a worse answer.

1. **Temperature bands come from plan 018**, not the spec's four-state table.
   Shipping bands are `normal ≤65 / elevated 66-75 / warning 76-85 / alert 86-95 /
   critical >95`, already emitted as `temp_band`. The spec's `>85 = critical` is
   superseded by the maintainer's five-band split.
2. **Absolute PHY counters are meaningless** — they are cumulative since driver
   load. Only rates count. Plan 018's `PHYERR_FLOOR=100` is the interim heuristic
   this work replaces; its `ponytail:` comment names this as the upgrade path.
3. **History lives in `/tmp`, not on the boot flash and not in appdata.** The
   reasoning matters, because the obvious answers are both wrong here.

   PHY error counters are maintained by the driver and **reset to zero on driver
   reload**, which is what a reboot does. This plan already discards the baseline
   when that happens. So a sample that survived a reboot would be thrown away on
   the first read after it — cross-boot persistence buys the rate computation
   exactly nothing, while costing flash writes forever.

   Appdata (`/mnt/user/appdata`) was considered and rejected for v1: `/mnt/user`
   only exists while the **array is started**, and the webGUI runs before that; if
   appdata is not on a cache pool, a write every few minutes **keeps array disks
   spinning**; and the path is user-configurable via `DOCKER_APP_CONFIG_PATH` in
   `/boot/config/docker.cfg`, so it cannot be hardcoded. It becomes the right home
   for anything that genuinely must outlive a reboot — see "Follow-ups".

   `/tmp` is RAM on Unraid: no wear, no spin-ups, no array dependency, and it
   clears at exactly the moment the data stops being meaningful.
4. **Sampling is opportunistic, not scheduled.** There is no cron anywhere in the
   `.plg` and this plan does not add one. Rates are computed from timestamps, so
   irregular sampling is arithmetically fine; staleness is expressed in wall-clock
   rather than the spec's "3 polling intervals".
5. **Inlet temperature and Δ are NOT in this plan.** The maintainer's box has 47
   hwmon inputs including several reading `-61 °C` and `0 °C`, hwmon indices are
   not stable across reboots, and `SYSTIN` at 55 °C would produce a Δ of 14 where
   a true intake probe would give ~44 — the same card, two verdicts. That needs a
   user-selected sensor and is deferred to plan 029.

## Current state

Excerpts from `8286fe7`.

### 1. The persistence exemplar — `event_archive.php`

Copy this file's **shape**: a pure function over its inputs, an injectable path so
tests never touch a real location, a thin read/write pair, and a capped ring.

Do **not** copy its conditional-write rule (`changed`). That exists to spare the
boot flash; this plan's ring lives in `/tmp`, so it appends unconditionally — see
"Write policy".

```php
const EVENT_ARCHIVE_CAP = 2000;   // cap history growth (kind to the boot flash)

/* Fold `current` into `history`, dedup by seq|time, cap to EVENT_ARCHIVE_CAP.
 * Returns [kept, changed]; the caller writes only when `changed` so an
 * unchanged poll never touches the flash. */
function event_merge(array $history, array $current): array { ... }

function event_store_path(int $ctl, string $dir = '/boot/config/plugins/hbaviewer'): string {
    return "$dir/events_c$ctl.json";
}
function event_store_read(string $file): array { ... }
function event_store_write(string $file, array $entries): void {
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
    @file_put_contents($file, json_encode($entries));
}
```

### 2. The per-PHY data already collected — `scripts/get_phy_health.sh:11-25`

Everything `link_integrity` and `topology` need is already read here. **Reuse this
shape; do not invent a second sysfs walk.**

```bash
_build_phy_sysfs() {
    local p sas idx
    for p in /sys/class/sas_phy/phy-*/; do
        [ -d "$p" ] || continue
        sas=$(sed 's/0x//' "$p/sas_address" 2>/dev/null | tr 'a-f' 'A-F' | tr -d ' \n')
        idx=$(basename "$p"); idx=${idx##*:}
        printf "%s %s %s %s %s %s %s\n" "$sas" "$idx" \
            "$(cat "$p/invalid_dword_count"           2>/dev/null || echo 0)" \
            "$(cat "$p/running_disparity_error_count" 2>/dev/null || echo 0)" \
            "$(cat "$p/loss_of_dword_sync_count"      2>/dev/null || echo 0)" \
            "$(cat "$p/phy_reset_problem_count"       2>/dev/null || echo 0)" \
            "$(cat "$p/negotiated_linkrate"           2>/dev/null | tr ' ' '_')"
    done
}
```

Note the PHY index is `phy-<host>:<idx>`, so `${idx##*:}` is the per-controller
PHY number that must appear in the reason string.

### 3. PCIe link, current only — `scripts/get_hba_info.sh:52-62`

The composer already maps storcli's `PCI Address` to a sysfs directory and reads
`current_link_width` / `current_link_speed`. It does **not** read `max_link_width`
/ `max_link_speed`, which `host_link` needs to detect downtraining. Those files
sit in the same directory — issue #5's reporter pasted both.

### 4. The tab bar — `hbaviewer.php:200-206`

```php
  <button class="lu-tab-btn active" data-tab="overview" onclick="luTab('overview')">Overview</button>
  <?php if ($showPhy):    ?><button class="lu-tab-btn" data-tab="phy"    onclick="luTab('phy')">PHY Health</button><?php endif; ?>
  <?php if ($showDrives): ?><button class="lu-tab-btn" data-tab="drives" onclick="luTab('drives')">Drives</button><?php endif; ?>
  <button class="lu-tab-btn" data-tab="smart" onclick="luTab('smart')">SMART</button>
```

SMART has **no** config toggle, so an always-on tab has precedent. This plan adds
no `SHOW_*` key.

### 5. AJAX routing — `ajax_info.php:23`

```php
$type    = in_array($_GET['type'] ?? '', ['overview','overview_html','phy','drives','events','smart','smart_all','metrics'])
```

and per-type handlers follow the shape `if ($type === 'phy') { echo renderPhyTables($data); exit; }`.

### 6. Repo conventions

- **Shell composers read hardware and emit JSON; PHP interprets and renders.**
  Persistence is PHP's job (`event_archive.php` is the precedent), so the shell
  side emits a *sample* and never touches the store.
- Pure functions with injectable paths, so the logic is testable without `/boot`
  or HTTP. `tests/event_archive_test.php` is the pattern to copy.
- Every hardware-sourced value is `htmlspecialchars`'d at output (plan 007).
- `view.php` owns status→colour. Reuse `lsi_status_color`; do **not** invent a
  second palette.
- Deliberate simplifications carry a `ponytail:` comment naming the ceiling and
  the upgrade path.
- Goldens are re-blessed only for an intentional contract change.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Shell lint | `find source tests -name '*.sh' -print0 \| xargs -0 -r -n1 bash -n` | exit 0 |
| PHP lint | `find source tests -name '*.php' -print0 \| xargs -0 -r -n1 php -l` | exit 0 |
| Full suite | `bash tests/run.sh` | `--- all pass ---`, exit 0 |

`php` may be absent; the suite falls back to a `php:8.2-cli` Docker image.

## The design

### Sample shape

`scripts/get_hba_health.sh` emits one JSON object per controller inside the usual
`hba_each` wrapper. A **sample** is raw readings plus a timestamp — no judgement:

```json
{"t":1750000000,"uptime":864000,"temp":72,"temp_band":"elevated",
 "fw":"24.00.00.00","drives":16,"read_ok":true,
 "link":{"width":8,"max_width":8,"speed":"8.0 GT/s","max_speed":"8.0 GT/s"},
 "phys":[{"idx":0,"inv":5,"disp":2,"sync":1,"rst":0,"rate":"12.0_Gbit"}]}
```

`uptime` is the host's, from `/proc/uptime`. The PHY counters reset when the
driver reloads, which happens on reboot, so host uptime is the correct reset
signal — **when uptime decreases, discard the previous baseline** rather than
computing a hugely negative delta.

### State model

`ok < watch < warning < critical`, plus `unknown` which is orthogonal.

Rollup is `max(severity)`, never an average — four ok and one critical is not
"mostly fine". If any indicator is `unknown` and nothing else is `warning` or
worse, the rollup is `unknown`. The rollup's display string is the **reason
string of the worst indicator**.

### The five indicators

| Key | Source | State rule |
|---|---|---|
| `thermal` | `temp_band` | `normal`→ok, `elevated`→watch, `warning`→warning, `alert`/`critical`→critical |
| `link_integrity` | per-PHY rates | see the table below; worst PHY wins, its index goes in the reason |
| `topology` | drive count vs baseline, per-PHY negotiated rate | any missing → critical; any PHY below the fastest observed → warning |
| `host_link` | `current_*` vs `max_*` | width or speed below capability → warning |
| `controller` | `read_ok`, sample age | read failed or newest sample older than 15 min → `unknown`; else ok |

Rate thresholds, per hour, from the spec:

| Counter | ok | watch | warning | critical |
|---|---|---|---|---|
| invalid dword | 0 | 1-50 | 51-500 | >500 |
| running disparity | 0 | 1-50 | 51-500 | >500 |
| loss of dword sync | 0 | — | ≥1 | ≥10 |
| phy reset problem | 0 | — | ≥1 | ≥10 |

Loss-of-sync and phy-reset have **no watch tier on purpose**: unlike invalid
dwords, which trickle in from ordinary marginal signalling, those two mean the
link actually dropped and re-established.

`link_integrity` is `unknown` until the store holds two samples spanning at least
60 seconds. On the maintainer's box that means it reads grey on first install and
resolves within minutes of the Monitor page being open.

### Write policy

Because the ring lives in `/tmp` (RAM), there is no wear budget to defend and no
conditional-write rule to get wrong: **append every sample**. That is both simpler
than the `event_archive.php` precedent and better resolution.

Cap the ring at **240 samples**. At the Monitor page's 60-second refresh that is a
four-hour window, which is ample for an events-per-hour rate.

`ponytail:` a four-hour window in RAM. A 24-hour trend (the spec's
`+6 in 24h`) needs storage that outlives a reboot — see "Follow-ups", not a bigger
ring.

## Scope

**In scope**:

- `scripts/get_hba_health.sh` — new composer, emits samples via `hba_each`
- `health.php` — new, pure: store, rate computation, five indicators, rollup
- `ajax_info.php` — `health` route + renderer
- `view.php` — `lsi_health_color()`, beside the existing colour functions
  (**missed in the first draft's file list**, which said "six files" while Step 4
  required a seventh; the executor caught the contradiction and followed the
  specific instruction, which was right)
- `hbaviewer.php` — tab button, pane, band-meter and indicator-row CSS
- `tests/health_test.php` — new unit tests
- `tests/run_php.sh` — register the new test file

**Out of scope** (do NOT touch):

- **Inlet temperature and Δ.** Deferred to plan 029 — see decision 5.
- **Flap suppression / hysteresis.** The spec's step 4. v1 may flip state at a
  band boundary; that is accepted for now and noted in "Follow-ups".
- **`PHYERR_FLOOR` in `parse/storcli_overview.sh`.** The old heuristic stays until
  this tab is proven on hardware; removing it here would change the Overview
  badge's behaviour in the same release that introduces the new tab.
- **Any cron entry or `.plg` change.**
- **The band cut-points** — settled in 018.
- **A second status palette.** Reuse `lsi_status_color`; add only the `watch` and
  `unknown` steps it lacks.

## Git workflow

- Branch: `advisor/020-hba-health-tab`, cut from `dev` (`8286fe7`)
- Several commits, one per step group. Short imperative subjects.
- Do NOT push or open a PR.

## Steps

### Step 1: The sample composer

Create `scripts/get_hba_health.sh` following `get_phy_health.sh`'s structure —
source `lib.sh` and `config.sh`, declare per-backend functions, dispatch with
`hba_each`.

It must emit, per controller: `t` (epoch), `uptime` (integer seconds from
`/proc/uptime`, first field truncated), `temp` and `temp_band` (reuse the existing
overview read rather than re-parsing), `fw`, `drives`, `read_ok`, the `link`
object, and the `phys` array.

For `link`, extend the PCI-address mapping already in `get_hba_info.sh:52-62` to
read `max_link_width` and `max_link_speed` from the same directory. Honour
`SYS_PCI_ROOT` so the suite can point it at a fixture tree.

For `phys`, reuse `_build_phy_sysfs`'s field list verbatim, keyed by the
per-controller PHY index (`${idx##*:}`).

**Verify**: `bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_health.sh` → exit 0

**Verify**: it runs against the stub and emits valid JSON —

```bash
cd tests && STUB_FIX="$PWD/fixtures/storcli" STORCLI="$PWD/stub/storcli" LSI_CACHE=/dev/null \
  bash ../source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_health.sh | head -c 400
```

→ must start `{"backend":"storcli"` and contain `"phys":`

### Step 2: `health.php` — pure logic, no I/O beyond the injectable store

Create `health.php` with these functions. Every one must be pure or take its path
as a parameter, so `tests/health_test.php` can exercise them with no `/boot`.

```php
const HEALTH_RING_CAP   = 240;   // ~4h at the 60s page refresh; RAM, so no wear budget
const HEALTH_STALE_SECS = 900;   // newest sample older than this -> unknown

/* /tmp, not /boot: the ring is only meaningful within one boot (see decision 3),
   and RAM costs nothing. $dir is injectable so tests never touch a real path. */
function health_store_path(int $ctl, string $dir = '/tmp'): string {
    return "$dir/hbav_health_c$ctl.json";
}
function health_store_read(string $file): array;
function health_store_write(string $file, array $ring): void;

/* Append $sample, capped to HEALTH_RING_CAP. Drops the whole ring first when the
   baseline is invalid — either $sample's uptime is LOWER than the newest stored
   sample's (reboot), OR any PHY counter went DOWN (driver reloaded without a
   reboot, e.g. modprobe -r mpt3sas). Both mean the counters restarted from zero
   and the old baseline would produce a negative delta. */
function health_ingest(array $ring, array $sample): array;

/* Per-PHY per-counter rates in events/hour between the oldest and newest samples
   in $ring. Returns [] when fewer than two samples span >= 60 seconds. */
function health_rates(array $ring): array;

function health_indicators(array $ring, array $rates, int $now): array;  // five entries
function health_rollup(array $indicators): array;                         // [state, reason]
```

Severity ordering must be a single function so it cannot drift:

```php
function health_rank(string $state): int {
    return ['ok' => 0, 'watch' => 1, 'warning' => 2, 'critical' => 3][$state] ?? -1;
}
```

`unknown` deliberately has **no** rank — it is handled separately in the rollup,
per the spec: it wins only when nothing else is `warning` or worse.

**Verify**: `php -l source/usr/local/emhttp/plugins/hbaviewer/health.php` → clean

### Step 3: Unit tests

Create `tests/health_test.php` following `tests/event_archive_test.php`'s
structure (a `check(name, bool)` helper, no framework). Cover at minimum:

1. **Rate arithmetic** — two samples one hour apart, `inv` 0→100, gives 100/hr.
2. **Rate over an irregular gap** — 30 minutes apart, 0→100, gives 200/hr. This is
   the whole reason cadence does not need to be fixed; if it fails, the design is
   broken.
3. **Uptime reset** — a sample whose `uptime` is lower than the stored newest
   drops the ring rather than producing a negative rate.
4. **Counter-decrease reset** — uptime unchanged but a PHY counter lower than the
   stored value also drops the ring. This is the `modprobe -r mpt3sas` case: the
   driver reloaded without a reboot, so uptime alone would not catch it.
5. **Ring cap** — 300 ingested samples leave exactly `HEALTH_RING_CAP`, oldest
   discarded first.
6. **`unknown` on a single sample** — `link_integrity` is `unknown`, not `ok`.
7. **Staleness** — a newest sample older than `HEALTH_STALE_SECS` makes
   `controller` `unknown`.
8. **Worst-of rollup** — four `ok` and one `critical` rolls up `critical`, and the
   reason string is the critical indicator's.
9. **`unknown` precedence** — one `unknown` plus four `ok` rolls up `unknown`; one
   `unknown` plus a `warning` rolls up `warning`.
10. **Threshold boundaries** — 50/hr invalid dwords is `watch`, 51 is `warning`,
    500 is `warning`, 501 is `critical`; 1/hr loss-of-sync is `warning` with no
    watch tier.

Register it in `tests/run_php.sh` — **note that the file lists its tests TWICE**,
once in the `command -v php` branch and once in the Docker fallback:

```bash
    php tests/config_test.php && php tests/view_test.php && php tests/event_archive_test.php && ...
...
        sh -c 'php tests/config_test.php && php tests/view_test.php && php tests/event_archive_test.php && ...'
```

Both lists must gain `tests/health_test.php`. Updating only one means the test
runs in whichever environment that branch matches and is **silently skipped** in
the other — the maintainer's box has a local `php` and takes the first branch,
while a dev machine without `php` takes the second. A test that never runs where
it matters is worse than no test.

**Verify**: `grep -c 'health_test.php' tests/run_php.sh` → `2`

**Verify**: `bash tests/run.sh` → `--- all pass ---`, and the output includes the
new `health:` test group.

### Step 4: Route and render

Add `health` to the `$type` allow-list at `ajax_info.php:23` and a handler
following the existing shape. The renderer produces, per controller:

- a header line: board name, `/cN`, chip, firmware, and the **rollup pill** whose
  text is `State — reason`
- a **horizontal band meter** for thermal only, scaled 0-110 °C with segment
  boundaries at 65 / 75 / 85 / 95 and a position marker at the current
  temperature. The spec is explicit that only thermal earns a gauge: it is the
  one continuous metric with meaningful bands.
- four **indicator rows**: state dot, label, right-aligned monospace value

Colours come from `lsi_status_color`, extended with the two states it lacks:

```php
/* watch sits between ok and warning; unknown is grey and must never read as
   healthy — a card that cannot be read is not a card that is fine. */
function lsi_health_color(string $s): string {
    return match ($s) {
        'critical' => '#e74c3c', 'warning' => '#e67e22',
        'watch'    => '#f1c40f', 'unknown' => '#7c807c',
        default    => '#2ecc71',
    };
}
```

Put that in `view.php` next to the existing colour functions, not in the renderer.

**Verify**: `php -l` clean on `ajax_info.php` and `view.php`

**Verify**: `grep -c "'health'" source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` → at least `2`

### Step 5: The tab

Add the button to `hbaviewer.php:200-206`, after Overview:

```php
  <button class="lu-tab-btn" data-tab="health" onclick="luTab('health')">HBA Health</button>
```

and a matching pane following the existing `<div id="...-content">` pattern, plus
CSS for the band meter and indicator rows. No config toggle — SMART sets the
precedent for an always-on tab.

**Verify**: `grep -c 'data-tab="health"' source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php` → `1`

**Verify**: `grep -c 'health-content' source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php` → `1`

### Step 6: Lint and suite

**Verify**: both lints exit 0; `bash tests/run.sh` → `--- all pass ---`

**Verify**: `git diff -- tests/expected/` shows **nothing** — this plan adds no
golden and changes no existing contract.

## Done criteria

- [ ] `bash -n` clean on the new composer; it emits valid JSON against the stub
- [ ] `tests/health_test.php` exists, is registered in `run_php.sh`, and all ten
      listed cases pass
- [ ] The irregular-gap rate test passes — 30 minutes, 0→100, gives 200/hr
- [ ] The uptime-reset test passes and no negative rate can be produced
- [ ] `grep -c 'data-tab="health"' hbaviewer.php` → `1`
- [ ] `grep -c "'health'" ajax_info.php` → ≥ `2`
- [ ] `lsi_health_color` lives in `view.php`, not in a renderer
- [ ] `git diff -- tests/expected/` is empty
- [ ] Both lints exit 0; `bash tests/run.sh` exits 0 with `--- all pass ---`
- [ ] `git status --porcelain` lists only the **seven** in-scope files
      (`get_hba_health.sh`, `health.php`, `ajax_info.php`, `view.php`,
      `hbaviewer.php`, `tests/health_test.php`, `tests/run_php.sh`)

## STOP conditions

- The drift check prints anything.
- Any existing golden changes. This plan adds a tab; it alters no existing
  contract.
- You find yourself writing to `/boot` or anywhere under `/mnt` — decision 3 says
  the ring lives in `/tmp`, because it is meaningless across a reboot and both
  alternatives cost something real (flash wear, or array spin-ups on a stopped or
  spun-down array).
- You find yourself writing to disk from shell at all. Persistence is PHP's job —
  `event_archive.php` is the precedent and the composer must stay stateless.
- You find yourself computing health from **absolute** PHY counters. They are
  cumulative since driver load and mean nothing; if there is no rate yet, the
  answer is `unknown`.
- You find yourself adding a cron entry, a `.plg` hook, or a background daemon.
- You find yourself reading an hwmon sensor or computing an inlet delta. That is
  plan 029.
- A rate comes out negative. That means the uptime-reset rule is not firing and
  the baseline is stale.
- `unknown` renders in a colour that could be mistaken for healthy.

## Test plan

Everything load-bearing here is pure and unit-testable, which is the point of
splitting `health.php` from the composer:

- **Rates** are arithmetic over timestamps — tested at a regular gap, an irregular
  gap, and across an uptime reset.
- **The ring** is tested for its cap and for both reset signals — a reboot (uptime
  decrease) and a driver reload without one (counter decrease). There is no
  conditional-write rule to test: `/tmp` means every sample is appended.
- **The state model** is tested at every threshold boundary and for `unknown`
  precedence in the rollup.
- **The composer** is exercised against the existing storcli stub; no new fixture
  is needed for JSON validity.
- **The rendering** cannot be tested here — layout has no test coverage in this
  repo, as plan 019 demonstrated at length. It is the maintainer's hardware check.

## Follow-ups this plan does not do

1. **Inlet temperature and Δ** (plan 029) — needs a user-selected hwmon input,
   because 47 inputs exist on a real box, hwmon indices move across reboots, and
   the choice of sensor changes the verdict.
2. **Flap suppression** — 2-3 poll dwell and a 3-5 °C deadband on thermal
   downgrade. Without it a card idling at a band boundary will oscillate.
3. **Retiring `PHYERR_FLOOR`** — once this tab is proven, the Overview badge should
   take its `link_integrity` state from here rather than the static floor.
4. **Per-controller threshold overrides** — the spec asks for them; nothing here
   is configurable yet.
5. **A long-term temperature trend** (`+6 in 24h` on the spec's card). This is the
   one thing that genuinely wants storage outliving a reboot, since temperature
   history stays meaningful across one where PHY counters do not. That is where
   **appdata** earns its place — resolved from `DOCKER_APP_CONFIG_PATH` in
   `/boot/config/docker.cfg` rather than hardcoded, guarded for the array being
   stopped, and written rarely enough not to hold disks awake. A daily min/max/mean
   triple is a few dozen bytes a day and needs none of the sample ring's
   resolution.

## Maintenance notes

- **The store is in `/tmp` on purpose.** It is not an oversight to be "fixed" by
  moving it somewhere persistent. PHY counters reset with the driver, so a
  surviving ring would be discarded on the first read after a reboot regardless —
  while flash costs wear and appdata costs array spin-ups and a stopped-array
  failure mode. If a future feature needs cross-boot data, give *that* feature its
  own store; do not relocate this one.
- **Two independent reset signals.** Uptime decreasing catches a reboot; any PHY
  counter decreasing catches a driver reload without one. Removing either leaves a
  path to a negative rate. If the plugin ever reads controller uptime directly
  instead of the host's, that value replaces `/proc/uptime` — the rule is unchanged.
- **`unknown` must never be silently green.** It exists because a failed read
  previously rendered as healthy. Any refactor that collapses it into `ok` has
  reintroduced the bug the spec calls out as the highest-value fix.
- **Only thermal gets a gauge.** The other four are discrete states; drawing them
  as meters would imply a continuum that isn't there.
- **What a reviewer should scrutinise**: that no absolute counter drives a state,
  that the uptime reset is tested, that `unknown` outranks `ok` in the rollup, and
  that the composer writes nothing to disk.
