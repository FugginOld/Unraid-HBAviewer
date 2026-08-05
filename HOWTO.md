# HBAviewer — HOWTO

Task-oriented guide. For what the plugin *is*, see [README.md](README.md); for
how it is built, see [ARCHITECTURE.md](ARCHITECTURE.md).

- [Install](#install)
- [First run](#first-run)
- [Find the drive behind a failing PHY](#find-the-drive-behind-a-failing-phy)
- [Set a PHY error baseline](#set-a-phy-error-baseline)
- [Read the health indicators](#read-the-health-indicators)
- [Turn on notifications](#turn-on-notifications)
- [Export / API](#export--api)
- [Generate a diagnostic bundle](#generate-a-diagnostic-bundle)
- [Flash firmware or BIOS](#flash-firmware-or-bios)
- [Troubleshooting](#troubleshooting)

---

## Install

**Plugins → Install Plugin**, paste:

```text
https://raw.githubusercontent.com/FugginOld/Unraid-HBAviewer/main/hbaviewer.plg
```

**SAS3 / SAS3.5 cards also need `storcli`** — it is Broadcom's proprietary CLI
and is not bundled. Install the **storcli** plugin from Community Applications
(dkaser). SAS2 cards use the bundled `lsiutil` and need nothing else.

Nothing is downloaded at runtime, and nothing phones home.

## First run

1. **User Utilities → HBAviewer** — the settings page opens instantly and shows
   the detected **Access Method**. Confirm it says `storcli` or `lsiutil` as you
   expect *before* opening the Monitor; if it warns that a SAS3 card was found
   without storcli, install that plugin first.
2. Set your **Alert Threshold**. This is not "the temperature that is bad" — it
   names the first *band* at which the badge starts complaining. The bands are
   fixed: Normal ≤65, Elevated 66–75, Warning 76–85, Alert 86–95, Critical >95 °C.
3. **Open HBAviewer Monitor** (or **Tools → HBAviewer → HBA Monitor**).

The Monitor opens immediately with a *"Reading controller information…"* banner
and fills in when the hardware read completes. **The first read can take up to a
minute** on a slow controller — that is the card, not the page. The result is
cached for 60 seconds, so subsequent loads are instant.

## Find the drive behind a failing PHY

This is the question the plugin exists to answer, and it takes two tabs.

1. **PHY Health** — look at the error counters (invalid DWords, disparity,
   loss-of-sync, reset problems). Raw counters are cumulative since the driver
   loaded, so a big number on a box with six months of uptime may mean nothing.
   **Set a baseline** (below) to get rates instead.
2. Once a baseline exists, the **Top offenders** list appears above the table,
   ranking PHYs by errors/hour **and naming the drive each one serves** —
   enclosure/slot on storcli, `/dev/sdX` on the lsiutil path.

If a PHY shows errors but the list says *"drive not identified"*, that is
deliberate: the plugin could not map that PHY to exactly one drive and will not
guess. Pointing at the wrong bay is worse than pointing at none.

**A PHY with no baseline is left out of the list entirely** rather than ranked
at zero — zero would read as "measured and clean" when it means "never measured".

Every one of those tables also carries the **`/dev` name** and **what Unraid
calls the disk** (`Parity`, `Disk 1`, `Cache`), so a row here can be matched
against the Main page without tracking `sdX` by eye. A dash in the Unraid column
means the array does not use that drive.

## Map your drive bays

**Drives → Map.**

The tables tell you a drive is failing. They cannot tell you which of 24 bays to
walk over and pull, because nothing on the machine knows your chassis layout — on
a direct-attach backplane the enclosure/slot addressing is invented by the
controller and matches no label on the front of the box. So you place the drives
once, and the plugin remembers.

1. Set **Rows** and **Columns** to match the chassis. The grid resizes as you
   type.
2. Click a drive in the **Unassigned drives** list, then click the bay it lives
   in. Repeat until the map matches what you see in the rack.
3. Press **Lock**. The layout can no longer be changed until you unlock it.

**Moving and removing.** Click a placed drive to pick it up, then click another
bay to move it. **Double-click** a bay to empty it — a single click never
removes anything. Dropping a drive on an occupied bay displaces the drive that
was there back to the unassigned list rather than stacking them.

**Shrinking the grid** asks first, and says how many drives no longer fit. Those
go back to the unassigned list; they are never silently dropped.

**What the colours mean.** A bay stays neutral until it needs attention:

| Colour | Meaning |
| --- | --- |
| Green rail | Passed SMART, nothing pending |
| Amber, `HIGH TEMP` | At or above the warning temperature (45 °C by default) |
| Amber, `SECTORS` | Passed SMART, but has reallocated or pending sectors |
| Red, `FAILED` | SMART reports a failure |
| Blue, `PARITY REBUILD` | Unraid is reconstructing parity onto this disk |
| Grey, `NO SMART` | Never read, or asleep and deliberately not woken |
| Dashed outline | Empty bay |

Temperatures are grey until they approach the threshold — a green number would
read as a signal when there is nothing to signal.

The map's colours and temperatures come from the same SMART collection the SMART
tab shows, and the legend row states how old it is. It is kept until you press
**Refresh** on the SMART tab, so opening the map costs nothing.

The assignment is keyed to the **HBA port** (or PHY on SAS2), not to the serial
or the `/dev` name — so replacing a dead drive with a new one in the same bay
keeps the bay, and a `/dev` name that moves after a reboot does not. It is
stored in `/boot/config/plugins/hbaviewer/bay_map.json`, which is the one thing
this plugin keeps that cannot be regenerated by re-reading the hardware. Back it
up with the rest of your flash drive.

## Set a PHY error baseline

**PHY Health → Set Baseline** (one button per controller).

That snapshots the current counters and the host uptime to
`/boot/config/plugins/hbaviewer/phy_baseline.json`. Every counter is then shown
as a **delta** and an **errors/hour rate** since that moment.

Why it lives on flash: a baseline you deliberately set must outlive a reboot,
and it is written once per button press, so there is no flash-wear concern.

**When it invalidates.** A reboot or a driver reload zeroes the hardware
counters, which would make `current − baseline` negative. Rather than show a
nonsense number, the tab reports *"Baseline reset by reboot or driver reload"*
and asks you to press **Reset Baseline**. It says "or" because the
counter-decrease signal genuinely cannot tell the two apart.

**Typical use:** reseat a cable or swap it, press Set Baseline, and check back in
a day. Anything above zero afterwards is new.

## Read the health indicators

**HBA Health** shows five independent indicators and rolls them up **worst-of**,
never averaged:

| Indicator | What it watches |
| --- | --- |
| `thermal` | Controller temperature against the fixed bands |
| `link_integrity` | PHY error **rates**, with the worst PHY named in the reason |
| `topology` | Devices present versus what was seen before |
| `host_link` | The PCIe link width/speed versus what the slot is capable of |
| `controller` | Whether the controller read succeeded at all |

An indicator that cannot be measured shows **grey / unknown**, not green. A
collector that timed out or a card that was pulled must never look healthy.

Rates need more than one sample, so `link_integrity` reads `unknown` on the
first load after a reboot and resolves once a second sample arrives.

## Turn on notifications

**Settings → Notifications → Enable notifications → Save.** Off by default.

A cron job checks every 10 minutes and sends **one** Unraid notification each
time a controller's health status *changes* — never a repeat while it stays the
same. Delivery (browser, email, agents) follows **Settings → Notification
Settings** in Unraid itself.

State is keyed by `board_name@pci_location`, so two identical cards cannot mask
each other. A read that errors is skipped rather than treated as an all-clear.

**One asymmetry worth knowing:** on SAS2 / lsiutil cards the health rollup is
**temperature-only** — that backend's overview carries no drive states and no PHY
input. A failed drive on a 9207-8i will not notify you.

## Export / API

Two read-only URLs, both listed on the Settings page with your actual host:

```text
http://<your-server>/plugins/hbaviewer/export.php
http://<your-server>/plugins/hbaviewer/export.php?format=prometheus
```

JSON gives one entry per controller: model, chip, firmware, mode, `temp_c`,
`status`, `temp_band`, `cfg_band`, `drive_count`, PCIe width/speed, `fw_old`.
Numbers are numbers or `null` — never `""`.

`cfg_band` is there so `status` is interpretable: a card can read
`temp_band: warning` while `status: ok`, because status is measured against
*your* configured threshold. Comparing the two bands tells you why.

**Both URLs require an active Unraid webGui session.** A Prometheus scraper
outside that session **cannot** poll them. They work from a logged-in browser, a
Homepage-style widget behind the same login, or a logged-in `curl`. Scrape
without a login would need an authentication scheme, which does not exist yet
and deserves its own design.

**While the cache is warming** the endpoint answers **HTTP 503** with
`{"state":"warming"}` rather than an empty controller list — so a scraper never
records "this box has no controllers" as a real measurement. Retry in a few
seconds.

A controller that failed to read is still listed, with `status: "error"` and an
`error` key. It is never silently dropped: a monitoring endpoint that hides a
failed card fails at its only job.

## Generate a diagnostic bundle

**Settings → Diagnostic Bundle → Generate diagnostic bundle.**

Collects the raw tool output, the sysfs state and the plugin's own parsed JSON —
raw and parsed together, because every issue this project has closed was
diagnosed by comparing the two. Read-only; nothing on the controller changes.

**Anonymise** (on by default) replaces every serial, WWN, SAS address and the
hostname with a same-length stand-in, using **one map for the whole bundle** so
the report still hangs together — a PHY and the drive it serves still match.
Drive models, sizes, firmware versions, temperatures and error counters are
kept, because hiding those would make the bundle useless. Your flash GUID,
licence key and share names are never collected.

**Include SMART** is slower (~1s per drive) and is off by default. Sleeping
drives stay asleep either way.

Attach the archive to a GitHub issue.

## Flash firmware or BIOS

> **Flashing can permanently brick a controller.** Off by default, and for
> people who already know how to flash an LSI/Broadcom HBA from a console.

**Settings → Advanced — Firmware Flashing → Enable → Save.** A
**Firmware/BIOS Update** tab appears on the Monitor.

Per controller:

1. **Verify** — a read-only listing **scoped to that one controller**, so you
   confirm the tool sees the exact card you are about to write to.
2. **Upload** — the model-correct image for *your* card (optionally a BIOS
   `.rom`, and the flash tool itself if it is not in `PATH`). This step stays
   available while the array is running, deliberately, so you can stage the
   image before taking the array down.
3. **Confirm & flash** — only with the **array stopped**. Step 3 is greyed out
   until then. Tick the acknowledgement, type `FLASH`, and go. A live log
   streams; reboot when it finishes.

The array-stopped rule, the typed confirmation, the single-flight lock and the
upload confinement are all enforced **server-side** — the greyed-out UI is an
affordance, not the control.

## Troubleshooting

**The Drives tab is empty.**
On some controllers storcli reports no enclosure and addresses drives as
`/cN/sN`. HBAviewer falls back to that form automatically. If the tab is still
empty, you are probably on a release older than 2026.08.02 — check
**Plugins** for an update.

**The enclosure line says "0 drives" above a list of drives.**
Fixed in 2026.08.02. Those counts describe the HBA's synthesised enclosure,
which your drives are not attached to, so they are now suppressed on
enclosure-less controllers.

**PCIe speed reads one generation low (Gen2 on a Gen3 card).**
Fixed in 2026.08.02 for the lsiutil/SAS2 path. Cross-check with
`lspci -s <bdf> -vv | grep LnkSta`.

**Settings warns that storcli is missing.**
Expected on SAS3/SAS3.5 without the storcli plugin. Install it from Community
Applications and reload. Note that having storcli installed does **not**
guarantee it is in use — it does not enumerate IT-mode SAS2 cards, and
HBAviewer falls back to lsiutil for those.

**The Monitor sits on "Reading controller information…".**
Normal for up to a minute on the first read. If it persists, run the read by
hand to see the error:

```bash
bash /usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh
```

**The Performance tab is blank or says the chart library is missing.**
`chart.umd.min.js` is fetched at build time and is not committed. Reinstall the
plugin.

**Temperature shows `N/A · no sensor`.**
Many SAS2008 / 9211 cards genuinely have no onboard sensor. Not an error — the
health rollup skips thermal rather than failing.

**A "pre-P20 firmware" warning on a card you know is P20.**
Fixed in 2026.07.27 — the banner packs the version as four hex bytes, so
`14000700` is 20.00.07.00, not 14.00.07.00.

**Everything looks stale after an update.**
Clear the caches and hard-refresh:

```bash
rm -f /tmp/lsiutil_dash.json /tmp/hbav_overview.out /tmp/hbav_overview.lock \
      /tmp/lsiutil_smart.json /tmp/lsiutil_smart.json.progress
```

**Still stuck?** Generate a diagnostic bundle (above) and open an issue with it
attached — it contains both the raw tool output and what HBAviewer made of it,
which is what makes a report diagnosable.
