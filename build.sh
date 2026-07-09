#!/bin/bash
# Builds lsiutil.txz for the Unraid HBAviewer plugin.
# Run this on Linux (or on your Unraid server directly) before creating a GitHub release.
# This script fetches ONLY the Linux x86_64 binary — no Windows binaries, no source code.
#
# Output: releases/lsiutil.txz
#
# Usage:
#   bash build.sh [version]
#   bash build.sh 2024.06.19

set -e

VERSION="${1:-$(date +%Y.%m.%d)}"

# Linux x86_64 binary only — single file from the repo, not the whole archive
LSIUTIL_URL="https://github.com/thomaslovell/LSIUtil/raw/master/Binaries/LSIutil_1.70_release_binaries/linux/lsiutil.x86_64"
BINARY_DEST="source/usr/local/emhttp/plugins/lsiutil/lsiutil.x86_64"
OUTPUT="releases/lsiutil.txz"

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
