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
is the injectable store. `ajax_info.php` `type=events` is a thin read→merge→write
caller.

## cached read — `cached_read.php` (`cached_read`)
The "slow read → serve cached → detached job" orchestration in one place:
freshness, single-flight lock, atomic tmp→rename swap. Returns
`{state: ready|warming, body}`; the foreground never blocks and the JS polls the
`warming` marker. Clock + launcher are injectable so the policy is unit-tested.
Used by `overview_html`. (`get_hba_info.sh` keeps its own 60s bash cache because
`dashboard.php` renders from it directly.)
