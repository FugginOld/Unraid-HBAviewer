#!/bin/bash
# Self-asserting checks for hwmon_list.sh's chip-address derivation. Found on
# real hardware: `basename(readlink -f device)` lands on a stable PCI/platform
# address for most chips, but for NVMe it lands one level too deep, on the
# nvmeN leaf -- assigned by driver probe order, the SAME unstable-across-reboots
# class the plan forbids hwmonN itself for. A stored id that silently rebinds to
# a different physical drive after reboot is worse than "sensor not found": it's
# the no-fallback guarantee's whole point, defeated one layer up.
#
# Builds a throwaway sysfs-shaped tree at runtime (mkdir/ln -s) rather than
# committing one: the real paths contain colons (PCI BDFs), which don't survive
# a Windows git checkout -- same reasoning as tests/run.sh's SYSPCI tree.
#   bash tests/hwmon_chip_test.sh   ->  "hwmon_chip: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2

# This test needs REAL symlinks: without elevated privilege / Developer Mode,
# Windows' `ln -s` on a directory silently creates a junction instead, which
# GNU `readlink -f` (what hwmon_list.sh itself calls) does not traverse — so
# on a plain Windows Git Bash box every case would "pass" via the basename
# fallback, proving nothing. Probe for that and, if seen, fall back to a
# throwaway php:8.2-cli container (same fallback tests/run_php.sh already uses
# for a missing php) — real Linux, real symlinks. The guard env var stops the
# re-exec'd copy inside the container from trying to fall back again.
if [ -z "${HWMON_CHIP_TEST_NO_DOCKER:-}" ]; then
    probe=$(mktemp -d)
    mkdir -p "$probe/target"
    ln -s "$probe/target" "$probe/link" 2>/dev/null
    resolved=$(readlink -f "$probe/link" 2>/dev/null)
    rm -rf "$probe"
    if [ "$resolved" != "$probe/target" ] && command -v docker >/dev/null 2>&1; then
        echo "ln -s does not create a real symlink here — using php:8.2-cli via docker"
        cd .. || exit 2
        exec env MSYS_NO_PATHCONV=1 docker run --rm \
            -v "$(pwd -W 2>/dev/null || pwd):/app" -w /app php:8.2-cli \
            bash -c 'HWMON_CHIP_TEST_NO_DOCKER=1 bash tests/hwmon_chip_test.sh'
    fi
fi

P="../source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/hwmon_list.sh"
fail=0
ok()  { echo "PASS  $1"; }
bad() { echo "FAIL  $1 -- $2"; fail=1; }

TREE=$(mktemp -d)
trap 'rm -rf "$TREE"' EXIT
ROOT="$TREE/hwmon"
DEV="$TREE/devices"
mkdir -p "$ROOT"

# case 1: NVMe -- the controller's own BDF (0000:c5:00.0) sits ABOVE the
# nvme/nvme4 leaf, which basename() picks up instead. The bug.
mkdir -p "$DEV/pci0000:c0/0000:c0:03.4/0000:c5:00.0/nvme/nvme4"
mkdir -p "$ROOT/hwmon0"
printf 'nvme\n'  > "$ROOT/hwmon0/name"
printf '45000\n' > "$ROOT/hwmon0/temp1_input"
ln -s "$DEV/pci0000:c0/0000:c0:03.4/0000:c5:00.0/nvme/nvme4" "$ROOT/hwmon0/device"

# case 2: plain PCI chip -- basename already IS the BDF; must stay unchanged.
mkdir -p "$DEV/pci0000:00/0000:00:18.3"
mkdir -p "$ROOT/hwmon1"
printf 'k10temp\n' > "$ROOT/hwmon1/name"
printf '38000\n'   > "$ROOT/hwmon1/temp1_input"
ln -s "$DEV/pci0000:00/0000:00:18.3" "$ROOT/hwmon1/device"

# case 3: platform chip -- no BDF anywhere in the path; the basename fallback
# must still fire (this is what stops "only match a BDF" from breaking Super I/O
# chips, the plugin's own most common inlet candidate).
mkdir -p "$DEV/platform/nct6775.656"
mkdir -p "$ROOT/hwmon2"
printf 'nct6779\n' > "$ROOT/hwmon2/name"
printf '30000\n'   > "$ROOT/hwmon2/temp1_input"
ln -s "$DEV/platform/nct6775.656" "$ROOT/hwmon2/device"

# case 4: virtual chip -- no /device symlink at all -- bare name.
mkdir -p "$ROOT/hwmon3"
printf 'acpitz\n' > "$ROOT/hwmon3/name"
printf '35000\n'  > "$ROOT/hwmon3/temp1_input"

out=$(bash "$P" "$ROOT")

chip_present() {  # $1 = expected chip id, $2 = description
    if printf '%s\n' "$out" | grep -qF "$(printf '%s\t' "$1")"; then
        ok "$2"
    else
        bad "$2" "chip '$1' not found in:
$out"
    fi
}

chip_present 'nvme-0000-c5-00.0'    'nvme: chip is the controller BDF, not the nvmeN leaf'
chip_present 'k10temp-0000-00-18.3' 'pci: chip unchanged (basename already the BDF)'
chip_present 'nct6779-nct6775.656' 'platform: chip unchanged (no-BDF basename fallback)'
chip_present 'acpitz'                'no /device: bare chip name'

if printf '%s\n' "$out" | grep -q 'nvme4'; then
    bad "nvme: chip must not contain the unstable nvme4 leaf" "found 'nvme4' in:
$out"
else
    ok "nvme: chip must not contain the unstable nvme4 leaf"
fi

echo
[ $fail -eq 0 ] && { echo "hwmon_chip: all pass"; exit 0; } || { echo "hwmon_chip: FAILURES"; exit 1; }
