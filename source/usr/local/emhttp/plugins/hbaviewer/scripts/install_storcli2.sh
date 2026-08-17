#!/bin/bash
# Install Broadcom's FULL StorCLI2 so the Event Log tab works on SAS4 / 9600 cards.
#
#   bash install_storcli2.sh <broadcom-zip | .deb | .rpm | storcli2 binary> [--no-go]
#
# Everything after Broadcom's download is automated: unpack, verify, put the
# binary somewhere that survives a reboot, and wire up the boot-time restore.
# The download itself cannot be scripted — the StorCLI2 page is JavaScript-driven
# behind Cloudflare bot management, with no stable direct URL, and the tool is
# proprietary and not redistributable. So you fetch the archive once, by hand,
# and hand it to this.
#
# WHY the copy-at-boot dance: /opt is RAM on Unraid, so anything put there is
# gone after a reboot; and the flash is FAT32, which cannot store the execute
# bit, so the binary has to be re-copied AND re-chmod'ed on every boot. That is
# what the /boot/config/go lines do.
#
# You do NOT need this for the Lite StorCLI2 that the dkaser storcli plugin
# ships — that already covers every tab except the firmware Event Log.
#
# Safe to re-run: it overwrites its own files and will not duplicate the go
# lines. Writes to the flash (/boot) and edits /boot/config/go, backing it up
# first.

set -euo pipefail

# Overridable so the whole flow can be exercised against a temp tree instead of
# the real flash — same convention as SYS_SCSI_HOST / SYS_PCI_ROOT elsewhere.
# A script that edits /boot/config/go is exactly the kind that should be
# testable without editing /boot/config/go.
FLASH_TOOL="${HBAV_FLASH_TOOL:-/boot/config/plugins/hbaviewer/tools/storcli2}"
LIVE_DIR="${HBAV_LIVE_DIR:-/opt/MegaRAID/storcli2}"
LIVE_TOOL="$LIVE_DIR/storcli2"
GO="${HBAV_GO:-/boot/config/go}"
GO_MARK='MegaRAID/storcli2'

die()  { printf 'error: %s\n' "$1" >&2; exit "${2:-1}"; }
note() { printf '  %s\n' "$1"; }

src=""; do_go=1
for a in "$@"; do
    case "$a" in
        --no-go) do_go=0 ;;
        -h|--help) sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*) die "unknown option: $a" 2 ;;
        *)  [ -z "$src" ] || die "give exactly one archive or binary" 2; src="$a" ;;
    esac
done
[ -n "$src" ] || { sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }
[ -e "$src" ] || die "not found: $src" 2
[ -r /etc/unraid-version ] || die "this is meant to run ON the Unraid server, not on your workstation" 2
command -v bsdtar >/dev/null || die "bsdtar is missing (it is what reads .deb/.rpm here)"

WORK=$(mktemp -d); trap 'rm -rf "$WORK"' EXIT

echo "==> Unpacking"
# bsdtar (libarchive) reads zip, ar/.deb and rpm alike, which is the only reason
# this needs no ar/rpm2cpio — neither of which Unraid has.
case "$src" in
    *.zip)
        bsdtar -xf "$src" -C "$WORK" || die "could not read the zip"
        # Prefer the Ubuntu .deb: same binary, and a smaller archive to walk.
        pkg=$(find "$WORK" -type f \( -name '*_amd64.deb' -o -name '*.x86_64.rpm' \) | head -1)
        [ -n "$pkg" ] || die "no Linux x86_64 .deb or .rpm inside that zip — is it the StorCLI2 download?"
        note "found $(basename "$pkg")"
        ;;
    *.deb|*.rpm) pkg="$src" ;;
    *)           pkg="" ;;   # assume it is already the binary
esac

if [ -n "$pkg" ]; then
    case "$pkg" in
        *.deb)
            # A .deb is an ar archive holding data.tar.xz; two hops.
            bsdtar -xOf "$pkg" 'data.tar*' > "$WORK/data.tar" || die "could not read the .deb"
            bsdtar -xf "$WORK/data.tar" -C "$WORK" ./opt/MegaRAID/storcli2/storcli2 2>/dev/null \
              || bsdtar -xf "$WORK/data.tar" -C "$WORK" opt/MegaRAID/storcli2/storcli2 \
              || die "no opt/MegaRAID/storcli2/storcli2 inside the .deb"
            ;;
        *.rpm)
            bsdtar -xf "$pkg" -C "$WORK" './opt/MegaRAID/storcli2/storcli2' 2>/dev/null \
              || bsdtar -xf "$pkg" -C "$WORK" 'opt/MegaRAID/storcli2/storcli2' \
              || die "no opt/MegaRAID/storcli2/storcli2 inside the .rpm"
            ;;
    esac
    bin=$(find "$WORK" -type f -name storcli2 | head -1)
else
    bin="$src"
fi
[ -n "${bin:-}" ] && [ -f "$bin" ] || die "could not find a storcli2 binary to install"

echo "==> Checking it is what it claims to be"
case "$(head -c4 "$bin" | tr -d '\0')" in
    $'\x7f'ELF) : ;;
    *) die "$(basename "$bin") is not a Linux ELF binary" ;;
esac
chmod +x "$bin"
ver=$(cd "$WORK" && "$bin" version 2>/dev/null | grep -m1 -i storcli || true)
case "$ver" in
    *StorCli2*|*StorCLI2*) note "$(echo "$ver" | sed 's/^[[:space:]]*//')" ;;
    *) die "that binary does not identify itself as StorCLI2" ;;
esac
rm -f "$WORK"/storcli2.log*

echo "==> Installing"
mkdir -p "$(dirname "$FLASH_TOOL")" "$LIVE_DIR"
cp -f "$bin" "$FLASH_TOOL"          # flash: survives reboots, cannot hold +x
cp -f "$bin" "$LIVE_TOOL"           # live: usable right now
chmod +x "$LIVE_TOOL"
note "flash copy  $FLASH_TOOL"
note "live copy   $LIVE_TOOL"

if [ "$do_go" -eq 1 ]; then
    echo "==> Boot-time restore"
    if grep -q "$GO_MARK" "$GO" 2>/dev/null; then
        note "already present in $GO — left alone"
    else
        cp -n "$GO" "$GO.bak-hbaviewer" 2>/dev/null || true
        cat >> "$GO" <<'EOF'

# StorCLI2 for SAS4 / 9600-series HBAs (HBAviewer SAS4). /opt is RAM and the
# flash is FAT32 and cannot keep the execute bit, so restore both on each boot.
mkdir -p /opt/MegaRAID/storcli2
cp /boot/config/plugins/hbaviewer/tools/storcli2 /opt/MegaRAID/storcli2/storcli2
chmod +x /opt/MegaRAID/storcli2/storcli2
EOF
        note "added to $GO (backup: $GO.bak-hbaviewer)"
    fi
else
    note "skipped $GO — pass without --no-go, or add the three lines yourself,"
    note "or this will not survive a reboot"
fi

echo "==> Done"
note "Open Settings and check Access Method, then reload the Event Log tab."
note "Nothing else to restart: the plugin finds the tool on the next read."
