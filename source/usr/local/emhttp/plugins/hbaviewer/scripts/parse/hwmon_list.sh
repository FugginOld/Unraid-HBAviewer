#!/bin/bash
# scripts/parse/hwmon_list.sh — pure: sysfs root on $1 (default /sys/class/hwmon)
# -> one line per temp input:  chip<TAB>label<TAB>path<TAB>millidegrees
#
# "chip" is NOT the hwmonN directory name — hwmonN is reassigned by driver probe
# order and is not stable across reboots (plan 029). It is the chip's `name`
# file, plus (when a /device symlink exists) an address derived from that
# symlink's real target. That address is the LAST PCI domain:bus:device.function
# component in the resolved path, not just its basename: for a PCI/platform chip
# (k10temp, nct6775, i915, bnxt_en...) the basename already IS that BDF and the
# two agree, but for NVMe the leaf component is nvmeN — assigned by driver probe
# order, the same unstable-across-reboots class as hwmonN itself, and a real bug
# found on real hardware (10 of 15 hwmon chips on the box that caught it). Only
# devices with no BDF anywhere in their path (platform devices like
# nct6775.656) fall back to the plain basename. Virtual chips with no /device
# (acpitz and similar) fall back to their bare name — normally only one of them.
#
# Filtering (dropping -61C junk headers etc.) happens in the UI, not here, so
# the user can see the junk before choosing it — see tests/fixtures/hwmon/.

ROOT="${1:-/sys/class/hwmon}"
[ -d "$ROOT" ] || exit 0

# Last PCI BDF (####:##:##.#) component of a resolved sysfs device path, e.g.
# .../0000:c0:03.4/0000:c5:00.0/nvme/nvme4 -> 0000:c5:00.0 (the NVMe controller's
# OWN address, not the bridge above it and not the leaf nvmeN). Empty if none.
hwmon_pci_bdf() {
    printf '%s\n' "$1" | tr '/' '\n' | grep -E '^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-9a-f]$' | tail -1
}

for h in "$ROOT"/hwmon*/; do
    [ -d "$h" ] || continue
    name=$(cat "${h}name" 2>/dev/null)
    [ -n "$name" ] || continue

    addr=""
    if [ -e "${h}device" ]; then
        resolved=$(readlink -f "${h}device" 2>/dev/null)
        if [ -n "$resolved" ]; then
            bdf=$(hwmon_pci_bdf "$resolved")
            addr="${bdf:-$(basename "$resolved")}"
            addr=$(printf '%s' "$addr" | tr -c 'A-Za-z0-9._-' '-')
        fi
    fi
    chip="$name${addr:+-$addr}"

    for tin in "$h"temp*_input; do
        [ -e "$tin" ] || continue
        n=$(basename "$tin"); n=${n#temp}; n=${n%_input}
        label_file="${h}temp${n}_label"
        if [ -f "$label_file" ]; then
            label=$(cat "$label_file" 2>/dev/null)
        else
            label="${name} temp${n}"
        fi
        [ -n "$label" ] || label="${name} temp${n}"
        mdeg=$(cat "$tin" 2>/dev/null)
        [ -n "$mdeg" ] || continue
        printf '%s\t%s\t%s\t%s\n' "$chip" "$label" "$tin" "$mdeg"
    done
done
