# HBAviewer — HOWTO

Task-oriented guide. For what the plugin *is*, see [README.md](README.md); for
how it is built, see [ARCHITECTURE.md](ARCHITECTURE.md).

- [Install](#install)
- [First run](#first-run)
- [Find the drive behind a failing PHY](#find-the-drive-behind-a-failing-phy)
- [Map your drive bays](#map-your-drive-bays)
- [Find a drive in the rack (Locate)](#find-a-drive-in-the-rack-locate)
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

**Array Map** tab.

The tables tell you a drive is failing. They cannot tell you which of 24 bays to
walk over and pull, because nothing on the machine knows your chassis layout — on
a direct-attach backplane the enclosure/slot addressing is invented by the
controller and matches no label on the front of the box. So you place the drives
once, and the plugin remembers.

1. Set **Rows** and **Columns** to match the chassis. The grid resizes as you
   type.
2. **Drag** a drive from the **Unassigned drives** list into the bay it lives
   in — or click the drive and then click the bay, whichever you prefer. Repeat
   until the map matches what you see in the rack.
3. Press **Lock**. The layout can no longer be changed until you unlock it.

The unassigned list is ordered the way Unraid's **Main** page lists disks —
Parity, Disk 1, Disk 2, and so on, then pools, then any drive Unraid has no slot
for.

**Moving and removing.** Drag a placed drive to another bay to move it, or drag
it back to the unassigned list to empty its bay. Click-then-click does the same
thing, and **double-clicking** a bay empties it; a single click never removes
anything. Dropping a drive on an occupied bay displaces the drive that was there
back to the unassigned list rather than stacking them.

Dragging needs a mouse. On a touch screen use click-then-click, which does
everything dragging does.

**Clear map** empties the whole grid at once, after asking and telling you how
many drives it is about to unplace.

**Shrinking the grid** asks first, and says how many drives no longer fit. Those
go back to the unassigned list; they are never silently dropped.

**Copy map** puts the layout on your clipboard as JSON, and **Restore map**
rebuilds it from that text. The map is built by hand and nothing on the machine
can regenerate it, so it is worth keeping a copy somewhere other than the boot
flash.

Unraid Connect's flash backup **does** cover `bay_map.json` — it is not excluded
— but only while that backup is actually running. On the maintainer's own
server it had silently stopped committing four days before the map was built,
so the map existed on exactly one FAT flash drive and nowhere else. If you rely
on flash backup for this, check that it is alive rather than assuming:

```bash
cd /boot && git log -1 --date=iso --format='last flash backup: %ad'
```

The text is `bay_map.json`'s own format, so it works in both directions: what
Copy produces can be written straight into that file, and the file's contents
paste straight back into Restore. Entries that no longer make sense — a drive
key the controller no longer reports, a bay outside the current grid, or two
drives in one bay — are skipped, and Restore tells you how many.

**Undo** appears after a Clear, a grid resize, or a Restore, and puts the map
back as it was.
It is one level deep and is consumed when you use it — it exists to catch the
misclick you notice straight away, not as a history. Anything older comes from
your flash backup.

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

## Find a drive in the rack (Locate)

**Drives → Locate**, on any row, or the **Locate** button on a bay in the
**Array Map**.

The drive's own **activity light** starts blinking in a steady rhythm, so the
row you are looking at becomes a bay you can walk to. The button itself blinks
and changes to **STOP** — press it again to stop. It also stops itself after
five minutes, so a forgotten blink cannot keep a disk awake.

It needs nothing from the backplane. There is no LED being driven here: the
plugin simply reads the drive twice a second, and the tray light flickers
because the drive is busy. That is why it works on hardware where a proper
`locate` LED does not — a plain HBA into a dumb backplane has no LED to
address, but every drive in a hot-swap tray has an activity light.

Two things to know before you press it:

- **It is the activity light, not a dedicated locate LED.** On a busy array
  other drives blink too, so look for the steady rhythm rather than the only
  blinking light.
- **It wakes the drive and keeps it awake** for as long as it runs. That is
  inherent — the technique *is* generating activity — and it is the opposite of
  the care taken everywhere else not to spin a sleeping disk up. Fine when you
  are about to pull the drive, which is when you use it.

Drives with no SCSI address show a dash instead of a button. If a drive has an
address but the kernel exposes no device node for it, pressing Locate says so
rather than appearing to work — you get an error naming the drive, not a button
that quietly settles back to **Locate**. Nothing else is disturbed while it
runs: the blink never interferes with a SMART collection.

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
| `host_link` | The PCIe link width/speed versus what it could actually reach |
| `controller` | Whether the controller read succeeded at all |

An indicator that cannot be measured shows **grey / unknown**, not green. A
collector that timed out or a card that was pulled must never look healthy.

**What `host_link` compares against.** The link is judged against the lower of
what the *card* can do and what the *slot* can do, read from the slot's own PCIe
bridge. An x8 card in an x4 slot is running at that slot's maximum — normal on
OEM boards, nothing you can fix, and not a warning. It says so:

```text
Running at x4 16.0 GT/s — this slot's maximum (card supports x8)
```

A card in a slot **wider** than itself is equally normal, and reads as the full
width of both. Only a link below what it could reach warns — an x8 card in an x8
slot negotiating x4 is a real fault and still says so.

If your board's bridge reports nothing, `host_link` falls back to the card's own
maximum, which can produce a permanent warning on a slot-limited card. Set
**Expected PCIe Width** / **Generation** in Settings to declare what the link
should be. Those are corrections, not mutes: a link below what you declare
still warns.

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

**Settings → Advanced — Firmware Flashing → Enable → Save.** Two ways in then
appear, and not before: a red **⚠ Firmware/BIOS Update** button at the bottom
right of that same Settings page, and a red **⚠ Firmware** tab at the end of
the HBA Monitor's tab strip, after Performance.

Enabling flashing adds **no new icon** to User Utilities. Both entrances are
invisible until you have ticked the box on the page that carries the warning,
so nobody arrives at the flasher without having read what it costs to get
wrong — and both disappear again the moment flashing is turned off.

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
