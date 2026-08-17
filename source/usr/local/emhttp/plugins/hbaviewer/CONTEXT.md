# HBAviewer — module vocabulary

Terms the code assumes you already know. Kept short on purpose.

## backend module — `scripts/lib.sh` (`hba_each`)
The one seam that chooses **storcli** (SAS3/3.5) vs **lsiutil** (SAS2). A tab
composer (`get_hba_info.sh`, `get_phy_health.sh`, `get_attached_drives.sh`,
`get_event_log.sh`) declares only *what to read per controller* for each
backend; `hba_each` owns *which backend* (`use_storcli`), *how many controllers*
(`storcli_count`), the *driver string* (`hba_driver`), and the
`{"backend","driver","controllers":[…]}` wrapper. Add a backend, or a per-tab
read, in one place. PHP reads the explicit `backend` field to pick columns — no
key-sniffing.

## event archive — `event_archive.php` (`event_merge`)
Persists the firmware event ring-buffer to `/boot` so history survives reboots
and ring-buffer wrap. `event_merge(history, current) -> [kept, changed]` is pure
(dedup by `seq|time`, cap at `EVENT_ARCHIVE_CAP`); `event_store_{path,read,write}`
is the injectable store. `render/events.php` (`type=events`) is a thin
read→merge→write caller.

## performance snapshot — `scripts/get_metrics.sh` (+ `parse/diskstats.sh`)
The INSTANT path behind the Performance tab. `get_metrics.sh` emits raw
cumulative counters — never a storcli/lsiutil call — from `/proc/diskstats`
(via the pure, fixture-tested `parse/diskstats.sh`), sysfs PHY counters, and the
60s overview temp cache, grouped per controller. The browser polls it ~2s, keeps
an in-memory ring buffer, and computes throughput/IOPS/%util/latency/PHY-rate
from deltas itself — the server stays stateless. ponytail: controller index =
position among the SAS scsi_hosts (host order), so the drivemap is instant sysfs
(no cache), the same host-order the PHY rollup assumes.

## drive bay map — `bay_map.php` (+ `bay_map_assemble()` in `render/baymap.php`)
Where each drive physically sits in the chassis. `bay_map_{read,write,set}` is
the `/boot` store, `bay_map_prune_to_dims()` returns the drives a shrunken grid
displaces (they go back to the tray, never silently dropped), `bay_map_key()`
is the identity — `c0:s0/14` (storcli enclosure/slot) or `c0:h2` (lsiutil PHY),
the **position** rather than the drive, so replacing a dead disk in the same bay
keeps the bay. The s/h letter is load-bearing: slot 3 and PHY 3 are different
positions. It was storcli's Connected Port Number until issue #15, which is the
controller port and identical for every drive behind one path — one assignment
placed a dozen drives. `bay_map_migrate_ports()` carries the old keys over.
`bay_map_assemble()` is the read side — drives × stored positions × the SMART
cache — kept separate from rendering so it is fixture-testable. It joins the
cache by serial, falling back to `/dev`: the lsiutil payload carries no serial,
model or size at all, so a serial-only join left those bay cards blank (issue #15
again), and the cache supplies what that backend never reported. Health colour
comes from SMART, never from storcli's `state` field, which is a topology role;
the one exception is `Rbld`, and Unraid's own parity reconstruct (var.ini
`mdResync` + `mdResyncAction`, read via `unraid_rebuilding()`) outranks it.
This store is the only state the plugin cannot regenerate from hardware.

## card grouping — `card_group.php` (`lsi_group_cards`, `lsi_ioc_counts`)
Which controllers are one physical CARD. A SAS9300-16i is one board carrying two
SAS3008 IOCs — two PCI functions, two indices, two temperature sensors — and only
the display should say "one card". `lsi_group_cards()` buckets by PCI root port
(`card_id`) **and** board name, then merges a bucket only when its size equals
the `ioc_count` the firmware index declares for that board **exactly**: a riser
can put two genuinely separate cards behind one root port, and merging those is
worse than the split display this replaces. Everything unrecognised, and every
controller with no resolvable slot, comes back as a group of one, so callers
never special-case. Pure, fixture-tested (`tests/card_group_test.php`). Consumed
by the Overview, the firmware page's JSON, and — the load-bearing one —
`flash_cards_from()`, which is what makes a dual-IOC board flash as one card.

## flash (mutating) — `flash.php` + `scripts/flash_hba.sh`
The ONE place HBAviewer writes to hardware, kept off the read-only path. Opt-in
(`ENABLE_FLASH`, default off). `flash.php` owns the guards — `flash_preflight`
(array STOPPED via `flash_array_stopped`, valid controller list, confirmed image,
single-flight lock), `flash_ctl_list` (shape/size/uniqueness of the posted list),
`flash_safe_name` (filename confinement) and the biggest of them,
**`flash_ctl_is_card()`**: the posted list must *be* one of this box's cards and
carry the chip that card actually reports. Its map comes from
`flash_card_chips()`, the one impure guard — it shells out to `get_hba_info.sh`
at flash time, because the client is the one party that cannot be asked. Its pure
half is `flash_cards_from(array $data): array`, which groups via `card_group.php`
and **drops any group smaller than the `ioc_count` its board declares**, so a
degraded read of one IOC cannot present half a dual board as a flashable card.
Everything except `flash_card_chips()`'s shell-out is pure and unit-tested; the
HTTP dispatch is skipped under CLI. `scripts/flash_hba.sh` maps
chip→tool (`flasher_for_chip`: SAS2→sas2flash, SAS30/31→sas3flash,
SAS34/35→storcli), resolves it via `find_flasher`/`find_storcli`, and runs
`list` (read-only preflight) or `flash`. Tool binaries are never bundled —
found in PATH or uploaded to `/boot/config/plugins/hbaviewer/tools/`.

## cached read — `cached_read.php` (`cached_read`)
The "slow read → serve cached → detached job" orchestration in one place:
freshness, single-flight lock, atomic tmp→rename swap. Returns
`{state: ready|warming, body}`; the foreground never blocks and the JS polls the
`warming` marker. Clock + launcher are injectable so the policy is unit-tested.
Used by `overview_html`. (`get_hba_info.sh` keeps its own 60s bash cache because
`dashboard.php` renders from it directly.)
