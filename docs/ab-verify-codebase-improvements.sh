#!/bin/bash
# A/B check for branch advisor/codebase-improvements (4558d7f) vs released main (8e4a12d).
# Runs all five composers under both trees on real hardware and diffs the JSON.
# Read-only: no plugin files are touched, nothing is installed.
# Paste the whole thing into an Unraid terminal.

A=8e4a12d   # released
B=4558d7f   # branch
W=/tmp/hbav-ab; rm -rf "$W"; mkdir -p "$W"

# pipefail so a failed curl fails the pipeline: without it the || below binds to
# tar alone, and a 404 that streams zero bytes is caught only because tar then
# chokes on an empty archive. -f makes curl treat an HTTP error as an error.
set -o pipefail
for S in $A $B; do
    curl -fsSL "https://github.com/Fuggin/Unraid-HBAviewer/archive/$S.tar.gz" \
        | tar xz -C "$W" || { echo "fetch $S failed"; exit 1; }
done
set +o pipefail

run() {  # run <sha> <outdir>
    d=$(echo "$W"/*-"$1"*/source/usr/local/emhttp/plugins/hbaviewer/scripts)
    mkdir -p "$2"
    for c in get_hba_info get_hba_health get_attached_drives get_phy_health get_event_log; do
        LSI_CACHE="$2/cache.json" bash "$d/$c.sh" > "$2/$c.json" 2>"$2/$c.err"
    done
}

run $A "$W/a"
run $B "$W/b"

echo "=== diff (empty means identical) ==="
diff -r "$W/a" "$W/b" && echo "IDENTICAL"
echo "=== sizes ==="
wc -c "$W"/a/*.json "$W"/b/*.json
