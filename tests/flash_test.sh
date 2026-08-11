#!/bin/bash
# Self-asserting checks for flash_hba.sh: per-generation command composition and
# the refusal guards. A stub flasher (stub/flasher) echoes its args so we assert
# the EXACT command without a real tool. No hardware, no flashing.
#   bash tests/flash_test.sh   ->  "flash: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
FH="../source/usr/local/emhttp/plugins/hbaviewer/scripts/flash_hba.sh"
STUB="$PWD/stub/flasher"; chmod +x "$STUB" 2>/dev/null
FW=$(mktemp); BIOS=$(mktemp); trap 'rm -f "$FW" "$BIOS"' EXIT
fail=0
ok()  { echo "PASS  $1"; }
bad() { echo "FAIL  $1 -- $2"; fail=1; }
has()  { case "$out" in *"$2"*) ok "$1" ;; *) bad "$1" "want '$2' in: $out" ;; esac; }
code() { [ "$2" = "$3" ] && ok "$1" || bad "$1" "want exit $2 got $3"; }

# ── tool mode: what the firmware page asks BEFORE offering Verify ────────────
# Step 1 used to be "verify the flash tool sees this card" while the tool upload
# lived in Step 2 — you could not finish step one without doing part of step two,
# and the only way to learn which tool you needed was to press the button and
# read the failure. The page now asks this first. It must always exit 0 and
# always print all four keys: "no tool for this chip" is an answer to render,
# not an error to swallow, and a missing key renders as undefined on the page.
tool() { env -u FLASHER -u STORCLI bash "$FH" tool "$1" 2>&1; }
keys() { printf '%s' "$1" | grep -cE '^(family|name|path|status)='; }

out=$(tool SAS3008); rc=$?
code "tool mode exits 0"        0 "$rc"
[ "$(keys "$out")" = 4 ] && ok "tool mode prints all four keys" || bad "tool keys" "want 4, got: $out"
has "9300 wants sas3flash"      "name=sas3flash"
has "9300 reports it missing"   "status=missing"
out=$(tool SAS3224); has "9305 wants sas3flash too" "name=sas3flash"
out=$(tool SAS3416); has "9400 wants storcli"       "name=storcli"
out=$(tool SAS3108); has "RoC has no tool"          "status=roc"
out=$(tool SAS9999); has "unknown chip has no tool" "status=unknown"

# Found: the page renders "nothing to do" off this, so the path must come back.
out=$(FLASHER="$STUB" bash "$FH" tool SAS3008 2>&1)
has "resolved tool reports found" "status=found"
has "resolved tool reports path"  "path=$STUB"

# No trailing whitespace on any value — the page parses these as key=value and a
# stray space becomes part of a rendered path. This bit once already.
out=$(tool SAS3008)
case "$out" in *' '$'\n'*|*' ') bad "tool output has trailing space" "$(printf '%s' "$out" | cat -A)" ;;
                            *) ok "tool output has no trailing whitespace" ;; esac

# ── an uploaded tool on /boot must still be runnable ─────────────────────────
# /boot is the Unraid flash drive: vfat, mounted fmask=0177. Every execute bit
# is masked off, so a file there can NEVER be executable and chmod on it is a
# silent no-op. find_flasher resolves on [ -x ], so the upload feature stored
# the tool correctly and then could not see it -- the file sat in tools/ reading
# -rw------- while the page reported no tool installed. Measured on a live box.
# find_flasher now stages a runnable copy off /boot and returns that.
#
# The source's own permissions are deliberately NOT asserted here: this suite
# runs on Windows too, where MSYS ignores chmod, so a test that depended on the
# source being non-executable would quietly prove nothing. What matters is the
# property that holds on every filesystem -- what comes back is executable, and
# it is not the /boot path.
BOOTDIR=$(mktemp -d); STAGEDIR=$(mktemp -d)
trap 'rm -f "$FW" "$BIOS"; rm -rf "$BOOTDIR" "$STAGEDIR"' EXIT
printf '#!/bin/sh\necho staged-tool\n' > "$BOOTDIR/sas3flash"
LIB="../source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh"
res=$(env -u FLASHER LSI_TOOLS="$BOOTDIR" LSI_TOOL_STAGE="$STAGEDIR" \
      bash -c "source $LIB; find_flasher sas3")
[ -n "$res" ]      && ok "uploaded tool resolves at all"        || bad "uploaded tool resolves" "got nothing"
[ -x "$res" ]      && ok "resolved tool is executable"          || bad "not executable" "$res"
case "$res" in "$BOOTDIR"/*) bad "returned the /boot path" "$res — that path can never be executable" ;;
                          *) ok "resolved out of /boot into a runnable copy" ;; esac
# Replacing the tool on /boot must take effect without a reboot.
# find_flasher re-stages on [ "$src" -nt "$staged" ], and the rewrite below lands
# in the same filesystem timestamp tick as the staging above often enough to fail
# roughly one run in eight. Force the ORDER rather than hope for it: back-date the
# staged copy ($res IS the staged path the call just returned) to the epoch, so
# /boot is unambiguously newer. Do NOT "simplify" this back to a bare touch, and
# do not date the source into the future instead -- a clock-skew guard that
# depends on the clock is its own trap.
# -t, not -d '@0': the @epoch form is a GNU coreutils extension that BusyBox and
# BSD touch reject, and this file argues for portability three lines up.
touch -t 200001010000 "$res"
printf '#!/bin/sh\necho replaced\n' > "$BOOTDIR/sas3flash"
res2=$(env -u FLASHER LSI_TOOLS="$BOOTDIR" LSI_TOOL_STAGE="$STAGEDIR" \
       bash -c "source $LIB; find_flasher sas3")
case "$(cat "$res2" 2>/dev/null)" in *replaced*) ok "a newer /boot copy is re-staged" ;;
                                              *) bad "stale staged copy" "replacing the tool had no effect" ;; esac
# An absent tool must still resolve to nothing rather than a stale or bogus path.
rm -f "$BOOTDIR/sas3flash"
EMPTYSTAGE=$(mktemp -d)
res3=$(env -u FLASHER LSI_TOOLS="$BOOTDIR" LSI_TOOL_STAGE="$EMPTYSTAGE" \
       bash -c "source $LIB; find_flasher sas3")
rm -rf "$EMPTYSTAGE"
[ -z "$res3" ] && ok "no tool anywhere resolves to nothing" || bad "phantom tool" "got '$res3'"

# ── list mode: read-only, scoped to the referenced controller only ───────────
out=$(FLASHER="$STUB" bash "$FH" list SAS2008 0 2>&1); has "list sas2 scoped" "FLASHER -c 0 -list"
out=$(FLASHER="$STUB" bash "$FH" list SAS3008 1 2>&1); has "list sas3 scoped" "FLASHER -c 1 -list"
out=$(STORCLI="$STUB" bash "$FH" list SAS3416 1 2>&1); has "list storcli scoped" "FLASHER /c1 show"

# ── flash mode: exact per-generation command ─────────────────────────────────
out=$(FLASHER="$STUB" bash "$FH" flash SAS2008 0 "$FW" 2>&1)
has "flash sas2 cmd log" "+ flasher -c 0 -o -f $FW"
has "flash sas2 exec"    "FLASHER -c 0 -o -f $FW"
out=$(FLASHER="$STUB" bash "$FH" flash SAS3008 1 "$FW" "$BIOS" 2>&1)
has "flash sas3 ctl 1"     "-c 1 -o -f $FW"
has "flash sas3 with bios" "-b $BIOS"
out=$(STORCLI="$STUB" bash "$FH" flash SAS3416 0 "$FW" 2>&1)
has "flash storcli download" "/c0 download file=$FW"

# ── BIOS-only: a real operation the script used to refuse ────────────────────
# sasNflash takes -f, -b or both. Updating just the BIOS is legitimate, and the
# script hard-required a firmware image, so it was impossible through the UI.
# storcli is the exception and must still refuse: on 9400/9500 the BIOS travels
# inside the firmware package, so there is no separate file to flash and a
# BIOS-only request would silently flash firmware the user did not choose.
out=$(FLASHER="$STUB" bash "$FH" flash SAS3008 0 "" "$BIOS" 2>&1)
has "bios-only builds -b with no -f" "FLASHER -c 0 -o -b $BIOS"
case "$out" in *" -f "*) bad "bios-only leaked -f" "$out" ;; *) ok "bios-only sends no -f" ;; esac
out=$(STORCLI="$STUB" bash "$FH" flash SAS3416 0 "" "$BIOS" 2>&1); rc=$?
code "storcli bios-only exit 5" 5 "$rc"
has "storcli bios-only says why" "part of the firmware package"
out=$(FLASHER="$STUB" bash "$FH" flash SAS3008 0 "" "" 2>&1); rc=$?
code "neither image exit 5" 5 "$rc"; has "neither image msg" "no firmware or BIOS image given"

# ── a dual-IOC board is ONE card: the controller argument takes a list ────────
# A SAS9300-16i carries two SAS3008 controllers on one board, so verifying and
# flashing it means covering both. Broadcom's advice for these boards is the
# flasher's flash-all switch, which this deliberately does NOT use: that switch
# means every controller in the SYSTEM, so on a box holding a 9300-16i and a
# 9300-8i it writes the 16i image to the 8i and bricks it. Looping the card's
# own indices meets the same intent -- never leave one IOC behind -- with no
# blast radius. The same reasoning bars the list-everything flag from verify.
out=$(FLASHER="$STUB" bash "$FH" list SAS3008 0,1 2>&1)
has "verify covers IOC 0"  "FLASHER -c 0 -list"
has "verify covers IOC 1"  "FLASHER -c 1 -list"
case "$out" in *-listall*) bad "verify listed the whole system" "$out" ;;
                        *) ok "verify never lists every controller in the system" ;; esac
out=$(STORCLI="$STUB" bash "$FH" list SAS3416 0,1 2>&1)
has "storcli verify covers IOC 0" "FLASHER /c0 show"
has "storcli verify covers IOC 1" "FLASHER /c1 show"

# Both IOCs written, and the loop does not stop after the first succeeds.
out=$(FLASHER="$STUB" bash "$FH" flash SAS3008 0,1 "$FW" 2>&1); rc=$?
code "flashing a dual-IOC card exits 0" 0 "$rc"
n=$(printf '%s\n' "$out" | grep -c -- 'FLASHER -c [01] -o')
[ "$n" = 2 ] && ok "flash writes both IOCs" || bad "flash writes both IOCs" "got $n writes in: $out"
case "$out" in *fwall*) bad "flash used the flash-all switch" "$out" ;;
                     *) ok "flash never writes every controller in the system" ;; esac
out=$(STORCLI="$STUB" bash "$FH" flash SAS3416 0,1 "$FW" 2>&1)
n=$(printf '%s\n' "$out" | grep -c -- 'FLASHER /c[01] download')
[ "$n" = 2 ] && ok "storcli flash writes both IOCs" || bad "storcli flash writes both IOCs" "got $n in: $out"

# A single index still behaves exactly as it always did -- one write, no loop
# visible to anyone with an ordinary card.
out=$(FLASHER="$STUB" bash "$FH" flash SAS3008 0 "$FW" 2>&1)
n=$(printf '%s\n' "$out" | grep -c -- 'FLASHER -c')
[ "$n" = 1 ] && ok "a single controller is still a single write" || bad "single write" "got $n in: $out"

# ── the partial flash: the one genuinely new hazard this loop introduces ──────
# If the second write fails after the first succeeded, the board is left with
# two IOCs on different firmware. Rebooting there is what turns a failed update
# into a dead card, so this must never surface as a generic failure: it has to
# name which controller holds which half and say not to reboot.
FAILDIR=$(mktemp -d)
trap 'rm -f "$FW" "$BIOS"; rm -rf "$BOOTDIR" "$STAGEDIR" "$FAILDIR"' EXIT
printf '#!/bin/sh\necho "FLASHER $*"\ncase " $* " in *" -c 1 "*|*" /c1 "*) exit 9 ;; esac\nexit 0\n' > "$FAILDIR/flasher"
chmod +x "$FAILDIR/flasher"
out=$(FLASHER="$FAILDIR/flasher" bash "$FH" flash SAS3008 0,1 "$FW" 2>&1); rc=$?
code "a partial flash exits 6"            6 "$rc"
has "a partial flash says PARTIAL FLASH"  "PARTIAL FLASH"
has "it names the IOC that succeeded"     "/c0"
has "it names the IOC that failed"        "/c1 FAILED"
has "it tells the operator not to reboot" "Do NOT reboot"
# The FIRST write failing is a different, much safer answer: nothing was
# written, and saying "partial" there would send the operator hunting a
# mismatch that does not exist.
printf '#!/bin/sh\necho "FLASHER $*"\ncase " $* " in *" -c 0 "*) exit 9 ;; esac\nexit 0\n' > "$FAILDIR/flasher"
out=$(FLASHER="$FAILDIR/flasher" bash "$FH" flash SAS3008 0,1 "$FW" 2>&1); rc=$?
code "a first-write failure exits 6" 6 "$rc"
has "and says nothing was written"   "nothing was written"
case "$out" in *"PARTIAL FLASH"*) bad "cried partial on a clean failure" "$out" ;;
                               *) ok "a clean failure is not reported as partial" ;; esac
# It must also stop: the second IOC is not written after the first failed.
n=$(printf '%s\n' "$out" | grep -c -- 'FLASHER -c 1')
[ "$n" = 0 ] && ok "a failed first write does not go on to the second IOC" \
             || bad "kept flashing after a failure" "$out"

# The list shape is validated before anything runs -- this value becomes the
# -c argument of a tool that writes firmware.
slipped=""
for badctl in "" "," "0," ",1" "0,,1" "0;1" "0 1" "-1" "0,x"; do
    env -u FLASHER -u STORCLI bash "$FH" flash SAS3008 "$badctl" "$FW" >/dev/null 2>&1
    [ $? -eq 2 ] || slipped="$slipped '$badctl'"
done
[ -z "$slipped" ] && ok "malformed controller lists are refused" \
                  || bad "malformed controller list accepted" "not refused with exit 2:$slipped"

# ── refusals (the guards that keep a bad call from ever running a tool) ───────
out=$(FLASHER="$STUB" bash "$FH" flash SAS9999 0 "$FW" 2>&1); rc=$?
code "unknown chip exit 3" 3 "$rc"; has "unknown chip msg" "unknown chip"

# ── every chip the plugin can DETECT must reach a flasher, or say why not ─────
# The mapping shipped with globs for SAS2*, SAS30*|SAS31* and SAS34*|SAS35*,
# which silently left SAS32xx (9305-16i/-24i), SAS36xx (9405W) and SAS38xx
# (9500) matching nothing — the user saw "unsupported/unknown chip" on cards
# that flash fine. The two chips the tests above happen to use both matched, so
# nothing caught it. This walks the actual chip list out of the firmware index
# instead of a list somebody remembers to update.
#
# Two outcomes, and they are different ANSWERS, so the chip decides which one is
# right: a no_it_firmware key must exit 2 (nobody can flash it), every other
# indexed chip must exit 0 with a family on stdout. Accepting either for every
# chip made this blind to half the defect it exists to catch -- dropping the
# MegaRAID parts from the RoC line routes them at a flasher as though an IT image
# were something they could take, and the loop still passed. Exit 1 -- "this
# script has never heard of your card" -- is always the bug.
IDX="../source/usr/local/emhttp/plugins/hbaviewer/data/known-firmware.json"
FN=$(sed -n '/^flasher_for_chip()/,/^}/p' "$FH")
if [ -r "$IDX" ] && [ -n "$FN" ]; then
    # grep the chips out rather than parse JSON: no jq on Unraid, and the two
    # shapes we need ("chip": "X" under boards, and the no_it_firmware keys) are
    # both plain quoted SASnnnn tokens.
    boards=$(grep -oE '"chip"[[:space:]]*:[[:space:]]*"SAS[0-9]+"' "$IDX" | grep -oE 'SAS[0-9]+' | sort -u)
    roc=$(sed -n '/"no_it_firmware"/,/^  }/p' "$IDX" | grep -oE '"SAS[0-9]+"' | tr -d '"' | sort -u)
    # Skip the loop outright when the extraction came back empty, rather than
    # recording the failure and running anyway: a renamed key in the index gave
    # both "FAIL index chip list" and "PASS every indexed chip maps to a
    # flasher" in the same run. The exit code was still non-zero, so CI caught
    # it -- but a contradictory PASS is noise on the exact line someone is
    # reading while they work out what broke.
    if [ -z "$boards" ] || [ -z "$roc" ]; then
      bad "index chip list" "found no chips in $IDX -- the loop below is skipped"
    else
    wrong=""
    rocsp=" ${roc//$'\n'/ } "
    for c in $(printf '%s\n%s\n' "$boards" "$roc" | sort -u); do
        # A chip listed in both places is a RoC part first — that is the answer
        # that refuses, and refusing is the safe direction.
        case "$rocsp" in *" $c "*) want=2 ;; *) want=0 ;; esac
        bash -c "$FN"$'\n''flasher_for_chip "$1" >/dev/null' _ "$c"; rc=$?
        [ "$rc" -eq "$want" ] || wrong="$wrong $c(got $rc, want $want)"
    done
    [ -z "$wrong" ] && ok "every indexed chip maps to a flasher, every RoC part to a refusal" \
                    || bad "wrong flasher answer" "flasher_for_chip gave the wrong exit for:$wrong"
    fi
else
    bad "index reachable" "cannot read $IDX or extract flasher_for_chip"
fi

# The 9305 family specifically -- the regression that started this, and the one
# board family in the index with a real card behind it in a bug report.
out=$(FLASHER="$STUB" bash "$FH" list SAS3224 0 2>&1); has "9305-24i lists via sas3flash" "FLASHER -c 0 -list"
out=$(FLASHER="$STUB" bash "$FH" list SAS3216 0 2>&1); has "9305-16i lists via sas3flash" "FLASHER -c 0 -list"
out=$(STORCLI="$STUB" bash "$FH" list SAS3616 0 2>&1); has "9405W lists via storcli"      "FLASHER /c0 show"
out=$(STORCLI="$STUB" bash "$FH" list SAS3816 0 2>&1); has "9500-16i lists via storcli"   "FLASHER /c0 show"

# RAID-on-Chip is refused by name, not routed at a tool that cannot help.
out=$(FLASHER="$STUB" bash "$FH" list SAS3108 0 2>&1); rc=$?
code "RoC exit 3" 3 "$rc"; has "RoC msg names the reason" "RAID-on-Chip"
out=$(STORCLI="$STUB" bash "$FH" list SAS3516 0 2>&1); rc=$?
code "RoC 9460 exit 3" 3 "$rc"; has "RoC 9460 refused" "cannot be crossflashed"
out=$(env -u FLASHER -u STORCLI bash "$FH" list SAS2008 0 2>&1); rc=$?
code "missing tool exit 4" 4 "$rc"
out=$(FLASHER="$STUB" bash "$FH" flash SAS2008 x "$FW" 2>&1); rc=$?
code "bad ctl exit 2" 2 "$rc"
out=$(FLASHER="$STUB" bash "$FH" flash SAS2008 0 /no/such/fw.bin 2>&1); rc=$?
code "missing fw exit 5" 5 "$rc"
out=$(FLASHER="$STUB" bash "$FH" bogus SAS2008 0 2>&1); rc=$?
code "bad mode exit 2" 2 "$rc"

echo
[ $fail -eq 0 ] && { echo "flash: all pass"; exit 0; } || { echo "flash: FAILURES"; exit 1; }
