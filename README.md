# Unraid LSIUtil Plugin

An Unraid plugin for monitoring LSI SAS Host Bus Adapters (HBA) — specifically the **LSI 9207-8i / SAS2308** family — using the bundled `lsiutil` utility. No internet access is required after the single package download.

## Features

- **Temperature gauge** — real-time HBA temperature with configurable alert threshold and Unraid notification
- **PCIe info** — link width, speed, power mode, and PCI location
- **PHY Health** — per-port SAS link state and error counters (invalid DWords, disparity, loss-of-sync, resets)
- **Attached Drives** — drive list with SAS addresses, PHY assignment, and OS device names (`/dev/sdX`)
- **Event Log** — HBA firmware event log entries
- **Dashboard tile** — at-a-glance temperature on the Unraid dashboard (Unraid 7.2+)
- **Settings** — lsiutil port selection, alert threshold, and panel toggles

All data is read directly from the HBA via `lsiutil` and Linux `sysfs` — no agents, no polling daemons, no external calls.

## Requirements

- Unraid 6.12 or newer (Unraid 7.2+ for the dashboard tile)
- LSI SAS controller supported by `mpt2sas` or `mpt3sas` kernel driver
  - Tested on: **LSI 9207-8i (SAS2308)**
  - Should work on: 9211-8i, 9205-8i, 9201-16i, and other SAS2x08-family cards
- The `lsiutil` binary is bundled inside the `.txz` package — nothing else is downloaded

## Installation

1. In the Unraid web UI go to **Plugins → Install Plugin**
1. Paste the plugin URL:

```text
https://raw.githubusercontent.com/DevlinDelFuego/Unraid-LSIUtil/main/lsiutil.plg
```

1. Click **Install**

After installation, find the monitor under **Tools → LSIUtil → HBA Monitor**.

## Plugin Structure

```text
Tools
└── LSIUtil
    └── HBA Monitor   (tabs: Temperature · PHY Health · Attached Drives · Event Log · Settings)

Settings > System Settings
└── LSIUtil            (opens full settings page)

Dashboard
└── HBA Temperature tile (Unraid 7.2+)
```

## Configuration

Open **Settings → System Settings → LSIUtil** or the **Settings** tab inside the monitor:

| Setting | Default | Description |
| --- | --- | --- |
| lsiutil Port | 1 | HBA port number (`lsiutil -p1`). Run `lsiutil` without arguments to list ports. |
| Alert Threshold | 80 °C | Unraid notification fires when temperature reaches this value. |
| Show PCIe Info | On | PCIe width/speed row in the Overview tab. |
| Show PHY Health | On | PHY error counters tab. |
| Show Attached Drives | On | Drive list tab. |
| Show Event Log | On | HBA firmware event log tab. |

## Building from Source

```bash
# Clone the repo
git clone https://github.com/DevlinDelFuego/Unraid-LSIUtil.git
cd Unraid-LSIUtil

# Build the .txz package (requires tar, gzip)
mkdir -p pkg
cp -a source/* pkg/
cd pkg
makepkg ../lsiutil.txz        # or: tar -cJf ../lsiutil.txz .
cd ..

# Update the MD5 in lsiutil.plg
md5sum lsiutil.txz
```

The `lsiutil.x86_64` binary inside the package is the original `lsiutil` v1.70 compiled for Linux x86-64.

## Credits

- **[Thomas Lovell — LSIUtil](https://github.com/thomaslovell/LSIUtil/)** — the `lsiutil` binary that makes this plugin possible. This project would not exist without his work preserving and maintaining the LSI utility.
- LSI Logic / Broadcom for the original `lsiutil` source.

## License

MIT — see [LICENSE](LICENSE) for details.
