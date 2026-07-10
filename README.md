# Unraid HBAviewer

Monitor LSI / Broadcom SAS Host Bus Adapters (HBAs) directly from Unraid —
temperature, PHY health, attached drives, SMART, and the firmware event log —
across **three controller generations**, with the correct backend auto-detected
per card.

> Originally created by **[DevlinDelFuego](https://github.com/DevlinDelFuego/Unraid-LSIUtil)**
> for the SAS2308 / 9207-8i. This fork extends it to SAS3 (9300) and SAS3.5
> tri-mode (9400) controllers, multi-controller systems, SMART, a background
> SMART collector, a persistent event log, and more.

## Supported hardware

The plugin detects the controller generation and uses the right tool automatically:

| Generation | Chipsets | Cards (examples) | Backend |
| --- | --- | --- | --- |
| **SAS2** (6 Gb/s) | SAS2004 / 2008 / 2108 / 2116 / 2208 / 2308 | 9207-8i, 9211-8i, IBM M1015, Dell H200/H310 | `lsiutil` (bundled) |
| **SAS3** (12 Gb/s) | SAS3004 / 3008 / 3108 / 3216 / 3224 / 3316 | 9300-8i, 9305-16i, 9361-8i | `storcli` (system-installed) |
| **SAS3.5 / tri-mode** | SAS3408 / 3416 / 3508 / 3516 / 3616 / 3808 / 3816 | 9400-16i, 9400-8i, 9500 series | `storcli` (system-installed) |

Multiple controllers are shown side by side. Both SAS and SATA drives are supported.

> **SAS3 / SAS3.5 cards need `storcli`** installed on the system — Broadcom's CLI,
> which is not bundled here (it's proprietary). The easiest way to install it on
> Unraid is the **[storcli plugin by dkaser](https://github.com/dkaser/unraid-storcli)**
> — search **"storcli"** in *Community Applications*. SAS2 cards use the bundled
> `lsiutil` and need nothing extra.

## Features

- **Overview** — per-controller temperature gauge with a configurable alert
  threshold, plus a real **health rollup** (goes yellow/red on high temp, a
  failed drive, or PHY errors — not just heat). Shows chip, firmware, BIOS,
  driver version, IT/IR mode, connected-drive count, and PCIe info. Pre-P20
  SAS2 firmware is flagged; cards with no onboard sensor show `N/A · no sensor`
  instead of erroring.
- **PHY Health** — per-PHY link state, negotiated speed, attached SAS address,
  and error counters (invalid DWords, disparity, loss-of-sync, reset) — read
  from the controller (lsiutil) or from Linux `sysfs` (`mpt3sas`) on SAS3/3.5.
- **Attached Drives** — enclosure/slot, HBA port, model, serial, state, size,
  SAS address, link speed, firmware, and a **per-drive SMART** button.
- **SMART tab** — health, temperature, grown defects, pending sectors, and
  power-on hours for every drive, collected **in the background** so it never
  blocks the UI and (on SAS) **never spins up a standby drive**.
- **Event Log** — the firmware event log, **archived to `/boot`** so history
  survives reboots and firmware ring-buffer wrap, with copy-to-clipboard for
  support tickets.
- **Enclosure / topology** — an enclosure summary per controller (direct-attach
  vs expander/backplane).
- **Dashboard tile** — at-a-glance temperature and health on the Unraid
  dashboard (Unraid 7.2+).
- **Firmware / BIOS Update** *(advanced, opt-in, off by default)* — an assisted
  flash tab that detects the card + running firmware, runs a read-only
  read-only per-controller sanity check, takes your model-correct image, and flashes one
  controller behind hard guardrails with a live log. See the safety section below.

All *monitoring* data is read directly from the HBA (`storcli` / `lsiutil`),
Linux `sysfs`, and `smartctl` — no agents, no polling daemons, no external calls.

## Firmware / BIOS updates (advanced, opt-in)

> **⚠ Flashing HBA firmware can permanently brick your controller.** This
> feature is **off by default** and is for users who already know how to flash
> an LSI/Broadcom HBA from a console. If you are not sure, do not enable it.

HBAviewer is otherwise strictly read-only. The optional **Firmware/BIOS Update**
tab is *assisted, not automatic*: it detects the card and runs the tools, but
**you** supply the model-correct firmware image and (if not already installed)
the flash tool.

**Enabling it:** Settings → *Advanced — Firmware Flashing* → tick
**Enable firmware/BIOS flashing** → Save. A **Firmware/BIOS Update** tab then
appears on the Monitor.

**How a flash works, per controller:**

1. **Verify** — a read-only listing **scoped to that one controller** (`storcli /cN show`
   or `sasNflash -c N -list`) confirms the tool sees the exact card you're about to flash.
2. **Upload** — the exact firmware `.bin`/`.rom` for *your* model (optionally a
   BIOS `.rom`, and the `sas2flash`/`sas3flash` binary if it isn't in `PATH`).
3. **Confirm & flash** — tick the acknowledgement, type `FLASH`, and flash. A
   live log streams; on success it prompts you to **reboot**.

**Tools used** (auto-detected in `PATH`, or upload them — none are bundled):

| Generation | Chip | Flash tool |
| --- | --- | --- |
| SAS2 (9200/9211/2308) | `SAS2xxx` | `sas2flash` |
| SAS3 (9300/9305) | `SAS30xx`/`SAS31xx` | `sas3flash` |
| SAS3.5 / 9400 tri-mode | `SAS34xx`/`SAS35xx` | `storcli /cN download` |

**Guardrails (all enforced server-side, not just in the browser):**

- Opt-in toggle gates the whole feature (default off).
- The Unraid **array must be STOPPED** — the flash is refused otherwise.
- Read-only verify first, **scoped to the single target controller**, so you flash
  the card you actually confirmed — not another HBA in the box.
- Explicit acknowledgement checkbox **and** a typed `FLASH` confirmation.
- Single-flight lock — one flash at a time, never auto-retried.
- Uploaded filenames are sanitised and confined to a fixed working directory.

**Caveats — read these:**

- **Bricking is a real, unavoidable risk** if the image doesn't match the card.
  Double-check the model/chip against the image before you flash.
- The flash tools are **proprietary** and per-generation — not shipped with the
  plugin. Install them (e.g. via a storcli/flash plugin) or upload them.
- Some SAS2 cards need a specific `sas2flash` build (e.g. a 9207-8i wants the P14
  tool). Use the right one; the plugin won't second-guess it.
- storcli 94xx flashing semantics vary by firmware package (a downrev may need
  `noverchk`); the log is shown verbatim — treat it as best-effort.
- Linux flashers **update** the BIOS region but **cannot erase** it.
- Stop any Unassigned Devices on the HBA as well before flashing.

## Requirements

- Unraid 6.12 or newer (7.2+ for the dashboard tile)
- A supported LSI / Broadcom SAS controller (see the table above)
- For **SAS3 / SAS3.5** cards: `storcli` installed — easiest via the
  [dkaser/unraid-storcli](https://github.com/dkaser/unraid-storcli) plugin
  (search "storcli" in Community Applications)
- `smartctl` (ships with Unraid) for the SMART features
- The `lsiutil` binary for SAS2 cards is bundled in the `.txz` — nothing extra
  is downloaded

## Installation

1. In the Unraid web UI go to **Plugins → Install Plugin**
2. Paste the plugin URL:

    ```text
    https://raw.githubusercontent.com/FugginOld/Unraid-HBAviewer/main/hbaviewer.plg
    ```

3. Click **Install**

After installation, find the monitor under **Tools → HBAviewer → HBA Monitor**.

## Layout

```text
Tools
└── HBAviewer
    └── HBA Monitor   (tabs: Overview · PHY Health · Drives · SMART · Event Log
                              · Firmware/BIOS Update*)   *opt-in, off by default

User Utilities
└── HBAviewer         (full settings page)

Dashboard
└── HBA Temperature tile (Unraid 7.2+)
```

## Configuration

Open **User Utilities → HBAviewer**. The settings page opens instantly and shows
the detected **Access Method** (`storcli` or `lsiutil`) so you can confirm the
right backend is in use before opening the Monitor.

| Setting | Default | Description |
| --- | --- | --- |
| Access Method | (auto) | Read-only. Shows whether `storcli` (SAS3/3.5) or `lsiutil` (SAS2) is used, and warns if a SAS3 card is found but `storcli` isn't installed. |
| lsiutil Port | 1 | *SAS2 only* — lsiutil port number. Only shown if SAS2 cards are detected. SAS3/storcli cards are enumerated automatically. |
| Alert Threshold | 80 °C | The badge turns red (ALERT) at or above this temperature. |
| Show PCIe Info | On | PCIe width/speed row in the Overview. |
| Show PHY Health | On | PHY tab. |
| Show Attached Drives | On | Drives tab. |
| Show Event Log | On | Event Log tab. |
| Enable firmware/BIOS flashing | **Off** | *Advanced.* Unlocks the Firmware/BIOS Update tab. Read the [firmware section](#firmware--bios-updates-advanced-opt-in) before enabling — flashing can brick a card. |

Save your settings, then click **Open HBAviewer Monitor**. The Monitor page opens
immediately with a **"Loading HBA information"** banner and reads the hardware in
the background — the first read can take up to a minute on slow controllers, and
the page fills in automatically when it's ready (no blank hang, no timeout).

## Building from source

```bash
git clone https://github.com/FugginOld/Unraid-HBAviewer.git
cd Unraid-HBAviewer

# Fetch the lsiutil binary and build the .txz (see build.sh for details)
bash build.sh

# build.sh prints the MD5 and version to update in hbaviewer.plg
```

The bundled `hbaviewer.x86_64` is the original `lsiutil` v1.70 compiled for Linux
x86-64. `storcli` is **not** bundled — SAS3/3.5 cards use the copy installed on
your system.

## Testing

The shell parsers and PHP helpers have a golden-file test suite that needs no
hardware:

```bash
bash tests/run.sh
```

It runs the parser goldens plus the PHP unit tests (using a local `php`, or the
`php:8.2-cli` Docker image if `php` isn't installed). Real-hardware output is
captured with the `scripts/capture*.sh` helpers and used to seed the fixtures.

## Credits

- **[DevlinDelFuego — Unraid-LSIUtil](https://github.com/DevlinDelFuego/Unraid-LSIUtil)**
  — the original Unraid plugin this fork (Unraid-HBAviewer) is built on.
- **[Thomas Lovell — LSIUtil](https://github.com/thomaslovell/LSIUtil/)** — the
  `lsiutil` binary that makes the SAS2 path possible.
- **Broadcom** — `storcli` (used for SAS3 / SAS3.5 controllers) and the original
  `lsiutil` source.
- **[dkaser — unraid-storcli](https://github.com/dkaser/unraid-storcli)** — the
  easiest way to install `storcli` on Unraid for SAS3 / SAS3.5 cards.

## License

MIT — see [LICENSE](LICENSE) for details.
