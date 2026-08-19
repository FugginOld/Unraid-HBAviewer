@brianara3 @masterwishx — one more favour, and this one is genuinely low-risk.

Since the 2026.08.16 release I've been rewriting the plumbing underneath the plugin: the five data collectors (overview, health, attached drives, PHY counters, event log) used to each carry their own copy of the "walk every controller" loop, and now they share one. Nothing about *what* gets collected was supposed to change — only how the code is arranged. The test suite agrees, but the suite runs against recorded fixtures, and your boxes are the two that actually stress this: three cards on one, two cards without StorCLI on the other.

So the question is just: **does the new code produce byte-identical output to the released version on real hardware?**

The script below answers that. It downloads both versions to `/tmp`, runs all five collectors under each, and diffs the results. It does **not** install anything, does not touch the plugin you have installed, does not write to your flash drive, and does not talk to your array — it only reads from your HBAs, the same reads the plugin already does every time you open its page. Everything it creates lives in `/tmp/hbav-ab` and disappears on reboot.

Paste the whole block into an Unraid terminal:

```bash
A=8e4a12d   # released
B=4558d7f   # new code
W=/tmp/hbav-ab; rm -rf "$W"; mkdir -p "$W"

set -o pipefail
for S in $A $B; do
    curl -fsSL "https://github.com/Fuggin/Unraid-HBAviewer/archive/$S.tar.gz" \
        | tar xz -C "$W" || { echo "fetch $S failed"; exit 1; }
done
set +o pipefail

run() {
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
```

**What I'm hoping to see:** the word `IDENTICAL`, and a size list where every `a/` file matches its `b/` counterpart.

**What to send back:** just the output from `=== diff` onward. If it prints `IDENTICAL` that's the whole answer and you're done. If it prints differences instead, the diff itself is what I need — that's the bug, and finding it here rather than after release is the entire point of asking.

One thing worth knowing: a couple of the collectors read live counters (temperature, PHY error counts, the event log), so those can legitimately differ by a digit or two purely because the two runs happen seconds apart. If the only diffs are in `get_phy_health.json` or `get_event_log.json` and they look like counters ticking rather than structure changing, that's expected — send it anyway and I'll confirm.

Takes under a minute. Thanks again — the multi-card support in the last release only works because you two tested it.
