#!/bin/bash
# Builds hbaviewer.txz for the Unraid HBAviewer plugin.
# Run this on Linux (or on your Unraid server directly) before creating a GitHub release.
# This script fetches ONLY the Linux x86_64 binary — no Windows binaries, no source code.
#
# Output: releases/hbaviewer.txz
#
# Usage:
#   bash build.sh [version]
#   bash build.sh 2024.06.19

set -e

VERSION="${1:-$(date +%Y.%m.%d)}"

# Linux x86_64 binary only — single file from the repo, not the whole archive.
# Pinned to an immutable commit permalink, not a branch: this binary is packaged
# into the .txz and runs as root on every user's server, and release.yml builds
# unattended in CI, so "whatever master serves right now" is not an acceptable
# input. Bump the SHA and the checksum together, deliberately.
LSIUTIL_URL="https://github.com/thomaslovell/LSIUtil/raw/106857e2f9f218513c95e5778a0fd0b88e73ec48/Binaries/LSIutil_1.70_release_binaries/linux/lsiutil.x86_64"
LSIUTIL_SHA256="7107df6a3ee152e8239cf2c0a422a0edb4e02035dc9740bdb2e77f19fbef6e78"
BINARY_DEST="source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.x86_64"
# Chart.js UMD build (Performance tab) — MIT, fetched like the lsiutil binary.
# Version-pinned already; the checksum pins the bytes behind that version too.
CHARTJS_VER="4.4.6"
CHARTJS_URL="https://cdn.jsdelivr.net/npm/chart.js@${CHARTJS_VER}/dist/chart.umd.min.js"
CHARTJS_SHA256="9653a0813db743bbe78332a3896e28c7bc7546e4fff51e7e979e908d1f0471d1"
CHARTJS_DEST="source/usr/local/emhttp/plugins/hbaviewer/chart.umd.min.js"
OUTPUT="releases/hbaviewer.txz"

# Fail the build on any byte that isn't what we pinned. Runs on the cached file
# too, not just a fresh download — a stale or tampered file sitting in the work
# tree must not get a free pass just because it already exists.
verify_sha256() {   # $1 = file, $2 = expected hash
    local got
    got=$(sha256sum "$1" | awk '{print $1}')
    if [ "$got" != "$2" ]; then
        echo "ERROR: checksum mismatch for $1"
        echo "    expected: $2"
        echo "    got:      $got"
        echo "    Refusing to package an unverified file. If this change is"
        echo "    intentional, review the new file and update the pinned hash."
        exit 1
    fi
    echo "    Checksum OK"
}

echo "==> Unraid HBAviewer build  (version: $VERSION)"

# Download lsiutil Linux binary if not already present
if [ ! -f "$BINARY_DEST" ]; then
    echo "--> Downloading lsiutil 1.70 (Linux x86_64)..."
    curl -fL "$LSIUTIL_URL" -o "$BINARY_DEST"
    chmod +x "$BINARY_DEST"
    echo "    Saved to: $BINARY_DEST"
else
    echo "--> lsiutil binary already present, skipping download"
fi
verify_sha256 "$BINARY_DEST" "$LSIUTIL_SHA256"

# Download Chart.js (Performance tab) if not already present
if [ ! -f "$CHARTJS_DEST" ]; then
    echo "--> Downloading Chart.js $CHARTJS_VER (UMD)..."
    curl -fL "$CHARTJS_URL" -o "$CHARTJS_DEST"
    echo "    Saved to: $CHARTJS_DEST"
else
    echo "--> Chart.js already present, skipping download"
fi
verify_sha256 "$CHARTJS_DEST" "$CHARTJS_SHA256"

# Sanity-check: ensure it's a Linux ELF binary (not a Windows PE)
FILE_TYPE=$(file "$BINARY_DEST" 2>/dev/null)
if echo "$FILE_TYPE" | grep -qi "ELF"; then
    echo "    Confirmed: Linux ELF binary"
elif echo "$FILE_TYPE" | grep -qi "PE\|MZ"; then
    echo "ERROR: Downloaded file appears to be a Windows binary. Aborting."
    rm -f "$BINARY_DEST"
    exit 1
fi

# Package everything in source/ into a Slackware-compatible .txz
mkdir -p releases
echo "--> Building $OUTPUT..."
cd source
# makepkg requires a Slackware environment; adjust path if needed
if command -v makepkg &>/dev/null; then
    makepkg -l y -c n "../$OUTPUT"
else
    # Fallback: plain tar.xz (rename .tar.xz → .txz)
    tar --owner=root --group=root -cJf "../$OUTPUT" .
fi
cd ..

MD5=$(md5sum "$OUTPUT" | awk '{print $1}')
echo "--> MD5: $MD5"
echo ""
echo "Done: $OUTPUT"
echo ""
echo "Next steps:"
echo "  1. Update the md5 entity in hbaviewer.plg with: $MD5"
echo "  2. Update the version entity in hbaviewer.plg with: $VERSION"
echo "  3. Tag the commit: git tag $VERSION && git push --tags"
echo "  4. Upload $OUTPUT as a GitHub release asset for tag $VERSION"
