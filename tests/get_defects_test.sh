#!/bin/bash
# get_defects.sh makes two decisions worth pinning: it never wakes a sleeping
# disk, and it OMITS a disk it could not measure rather than recording a zero.
# Both are stubbed on PATH, the tests/read_smart_test.sh approach.
#   bash tests/get_defects_test.sh   ->  "get_defects: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
GD="../source/usr/local/emhttp/plugins/hbaviewer/scripts/get_defects.sh"
fail=0
ok()  { echo "PASS  $1"; }
bad() { echo "FAIL  $1 -- $2"; fail=1; }
has()   { case "$2" in *"$3"*) ok "$1" ;; *) bad "$1" "want '$3' in: $2" ;; esac; }
hasnt() { case "$2" in *"$3"*) bad "$1" "did NOT want '$3' in: $2" ;; *) ok "$1" ;; esac; }

WORK=$(mktemp -d); STUBDIR="$WORK/bin"; mkdir -p "$STUBDIR"
ARGS="$WORK/args"; : > "$ARGS"
trap 'rm -rf "$WORK"' EXIT

cat > "$STUBDIR/lsblk" <<'STUB'
#!/bin/bash
printf 'sdb\nsdc\n'
STUB
cat > "$STUBDIR/smartctl" <<'STUB'
#!/bin/bash
echo "smartctl $*" >> "$STUB_ARGS"
case "$*" in
    *sdb*) echo "Elements in grown defect list: 12" ;;
    *sdc*) echo "Device is in STANDBY mode, exit(2)"; exit 2 ;;
esac
STUB
chmod +x "$STUBDIR"/*
export STUB_ARGS="$ARGS"

# The by-id farm is built at runtime under mktemp -d: it holds symlinks, and
# the plan's Windows/NTFS rule keeps generated paths out of the repo.
BYID="$WORK/by-id"; mkdir -p "$BYID" "$WORK/dev"
: > "$WORK/dev/sdb"; : > "$WORK/dev/sdc"

# get_defects.sh's id_of() is only meaningful over a REAL symlink (it resolves
# by-id -> sd letter with readlink -f). Guarded the same way topology_test.sh
# already guards its own symlink fixture: Windows without Developer Mode makes
# `ln -s` fall through to a plain file copy (exit 0, no [ -L ]). But smartctl
# runs for every lsblk-listed device BEFORE id_of() is ever consulted -- only
# the final by-id-keyed emission depends on the symlink resolving -- so only
# the two assertions that check a by-id NAME are gated below; everything else
# runs for real on every platform. CI runs ubuntu-latest, where both branches
# below exercise the "then" path.
haveLinks=0
if ln -s "$WORK/dev/sdb" "$BYID/ata-TESTDISK_0001" 2>/dev/null \
   && [ -L "$BYID/ata-TESTDISK_0001" ]; then
    ln -s "$WORK/dev/sdc" "$BYID/ata-TESTDISK_0002"
    haveLinks=1
fi

out=$(PATH="$STUBDIR:$PATH" DEFECTS_BYID="$BYID" bash "$GD")

# These hold regardless of symlink support: smartctl runs for every
# lsblk-listed device before id_of() is ever consulted, and the output is
# always a JSON object even when every disk is omitted for lack of an id.
has "the output is a JSON object" "$out" '{'
smart_calls=$(grep -c '^smartctl ' "$ARGS")
[ "$smart_calls" -gt 0 ] && ok "smartctl was actually called ($smart_calls times)" \
                         || bad "smartctl was actually called" "zero -- the next assertion would pass vacuously"
nostandby=$(grep '^smartctl ' "$ARGS" | grep -v -- '-n standby' || true)
[ -z "$nostandby" ] && ok "every smartctl call passes -n standby" \
                    || bad "every smartctl call passes -n standby" "$nostandby"

# These two need a real by-id symlink to resolve at all.
if [ "$haveLinks" -eq 1 ]; then
    has   "a measurable disk is reported by its stable id" "$out" '"ata-TESTDISK_0001":12'
    hasnt "a standby disk is omitted, not zeroed"          "$out" 'ata-TESTDISK_0002'
else
    echo "SKIP  by-id keying assertions (ln -s unavailable)"
fi

echo
[ $fail -eq 0 ] && { echo "get_defects: all pass"; exit 0; } || { echo "get_defects: FAILURES"; exit 1; }
