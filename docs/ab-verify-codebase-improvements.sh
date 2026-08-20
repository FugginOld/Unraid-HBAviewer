#!/bin/bash
# A/B check for branch advisor/codebase-improvements (8e7b38d) vs released main (8e4a12d).
# Stage 1 runs all five shell composers under both trees and diffs the JSON.
# Stage 2 feeds both trees that same JSON and diffs the Overview HTML each one's
# renderer builds -- run it on a dual-IOC board if you have one, because the
# grouped card has never been rendered outside a fixture.
# Read-only: no plugin files are touched, nothing is installed.
# Paste the whole thing into an Unraid terminal.

A=8e4a12d   # released
B=8e7b38d   # branch tip (includes the card-renderer merge)
W=/tmp/hbav-ab; rm -rf "$W"; mkdir -p "$W"

# pipefail so a failed curl fails the pipeline: without it the || below binds to
# tar alone, and a 404 that streams zero bytes is caught only because tar then
# chokes on an empty archive. -f makes curl treat an HTTP error as an error.
set -o pipefail
for S in $A $B; do
    curl -fsSL "https://github.com/FugginOld/Unraid-HBAviewer/archive/$S.tar.gz" \
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

# The health payload stamps the wall clock and the controller's uptime, so two
# runs seconds apart differ there no matter what the code does. Normalise those
# two fields for the comparison and keep the raw files beside it, so a diff that
# survives is a real one and the tester is not asked to judge digits.
for s in a b; do
    mkdir -p "$W/$s.norm"
    for f in "$W/$s"/*; do
        sed -e 's/"t":[0-9]*/"t":T/g' -e 's/"uptime":[0-9]*/"uptime":U/g' \
            "$f" > "$W/$s.norm/$(basename "$f")"
    done
done

echo "=== diff (empty means identical) ==="
diff -r "$W/a.norm" "$W/b.norm" && echo "IDENTICAL"
echo "=== sizes ==="
wc -c "$W"/a/*.json "$W"/b/*.json

# ── Stage 2: the Overview renderer ──────────────────────────────────────────
# The composers above are shell; the cards are PHP, and the branch rewrote how
# they are assembled. Feed BOTH trees the SAME captured overview JSON so the
# only thing that can differ is the renderer. Worth running on a dual-IOC board
# above all -- that is the grouped card, and fixtures are the only thing that
# has ever exercised it.
#
# NOT in docs/ab-verify-post.md, which asks outside testers for stage 1 only.
# Stage 1 is what masterwishx actually ran, so its safety on someone else's
# server is observed rather than argued; stage 2 require()s the plugin's page
# script, which is routine in the suite but has never run on a box that is not
# ours. Keep it here for our own hardware until it has some mileage.
echo
echo "=== renderer (Overview cards) ==="
if ! command -v php >/dev/null 2>&1; then
    echo "SKIP: no php on this box"
    exit 0
fi
cat > "$W/render.php" <<'PHP'
<?php
/* argv[1] = tree root, argv[2] = backend JSON */
require $argv[1] . '/source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php';
echo preg_replace('~(Last read: <span>)[^<]*~', '$1TIME', renderOverviewCards(
    (array) json_decode((string) file_get_contents($argv[2]), true),
    ['HBA_PORT' => 1, 'ALERT_THRESHOLD' => 76, 'SHOW_PCIE' => 1]));
PHP
# The overview capture from stage 1, so both renderers see identical input.
OV="$W/a/get_hba_info.json"
mkdir -p "$W/render"
for S in $A $B; do
    # Anchored on the archive's own prefix, NOT "*-$S*": the loop writes its own
    # output beside these directories, and render-<sha>.html matches that looser
    # pattern too -- the glob then yields three paths and require() gets a
    # nonsense one. Output goes in its own directory for the same reason.
    root=$(echo "$W"/Unraid-HBAviewer-"$S"*)
    php "$W/render.php" "$root" "$OV" > "$W/render/$S.html" 2>"$W/render/$S.err"
done
for S in $A $B; do
    printf 'tree %s: %s bytes html, %s bytes stderr\n' \
        "$S" "$(wc -c < "$W/render/$S.html")" "$(wc -c < "$W/render/$S.err")"
done
# Two FAILED renders also diff clean, and an error card is still a card -- so
# counting cards is not enough to make a match mean anything. If stage 1 could
# not read the hardware, its error JSON renders as an error card under both
# trees and "IDENTICAL" would be true and worthless. Say so instead.
if grep -q 'lu-error' "$W/render/$A.html"; then
    echo "INCONCLUSIVE: the overview capture is an error, so both trees rendered"
    echo "  an error card. Stage 1 could not read this box; fix that first."
    cat "$W/a/get_hba_info.json"; echo
    exit 1
fi
printf 'cards rendered: %s (grouped: %s)\n' \
    "$(grep -o 'lu-card ' "$W/render/$A.html" | wc -l)" \
    "$(grep -o 'lu-card-parent' "$W/render/$A.html" | wc -l)"
if diff "$W/render/$A.html" "$W/render/$B.html" > "$W/render.diff"; then
    echo "RENDERER IDENTICAL"
else
    echo "RENDERER DIFFERS:"; head -40 "$W/render.diff"
fi
