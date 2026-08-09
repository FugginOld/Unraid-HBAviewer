#!/bin/bash
# Guard for bundle_support.sh's own stated rule (see its "Section 2" comment):
# every lsiutil -a token a composer issues must also be captured by the
# bundle. Nothing else in the suite enforces this -- anon_test.sh builds a
# synthetic tree and never enumerates the real capture list, and
# bundle_php_test.php only covers the HTTP endpoint -- so a capture can go
# missing forever without a single test turning red.
#
# That is exactly what happened on issue #10: `lsiutil -a 1,0` (the IT/IR
# firmware personality) and the drive transport were both missing from the
# bundle, and a maintainer had to hand-write two command blocks to get them.
# This test is the cheap static check that would have caught the omission --
# a source-level token match, not a run of the real bundle against hardware.
#   bash tests/bundle_coverage_test.sh   ->  "bundle-coverage: all pass" (exit 0)
cd "$(dirname "$0")" || exit 2
SCRIPTS="../source/usr/local/emhttp/plugins/hbaviewer/scripts"
BUNDLE="$SCRIPTS/bundle_support.sh"
fail=0
ok()  { echo "PASS  $1"; }
bad() { echo "FAIL  $1 -- $2"; fail=1; }

# Every -a token a composer (anything under scripts/*.sh EXCEPT bundle_support.sh
# itself, which is the thing being checked, not a source of truth) issues.
composer_files=""
for f in "$SCRIPTS"/*.sh; do
    [ "$(basename "$f")" = "bundle_support.sh" ] && continue
    composer_files="$composer_files $f"
done
composer_tokens=$(grep -hoE -- '-a [0-9]+(,[0-9]+)*' $composer_files | sort -u)
bundle_tokens=$(grep -hoE -- '-a [0-9]+(,[0-9]+)*' "$BUNDLE" | sort -u)

while IFS= read -r tok; do
    [ -n "$tok" ] || continue
    if grep -qxF -- "$tok" <<<"$bundle_tokens"; then
        ok "bundle captures $tok"
    else
        bad "bundle missing $tok" "composer issues it but bundle_support.sh does not capture it"
    fi
done <<<"$composer_tokens"

# TRAN is the SAS-vs-SATA signal (read_smart.sh already branches on it) and has
# no -a token to be caught by the loop above, so it needs its own assertion.
if grep -E '^run 02-raw/lsblk\.txt lsblk .*-o [A-Za-z,]*TRAN' "$BUNDLE" >/dev/null; then
    ok "lsblk capture requests TRAN"
else
    bad "lsblk capture missing TRAN" "the -o column list has no TRAN, so no bundle can answer SAS-vs-SATA"
fi

# lsblk's TRAN reports the BUS, not the drive: a SATA disk behind a SAS HBA
# reads "sas". target_port_protocols in the sas_device class is the per-drive
# truth, and sas_end_device (a different class, already dumped) does not carry
# it. This capture has no -a token either, so it also needs its own assertion.
if grep -E 'dump_attrs 03-sysfs/sas_device\.txt +/sys/class/sas_device/end_device-\*' "$BUNDLE" >/dev/null; then
    ok "sas_device class is dumped"
else
    bad "sas_device class not dumped" "lsblk's TRAN is the bus; target_port_protocols in sas_device is the per-drive SAS-vs-SATA truth, and sas_end_device does not carry it"
fi


# subsystem_vendor decides whether a firmware verdict is given at all: 0x1000 is
# a generic Broadcom board and anything else is an OEM rebrand, where reaching a
# generic image is a crossflash rather than an upgrade. A bundle that omits it
# cannot answer why a reporter's card shows no verdict -- which is exactly the
# class of question this guard exists to keep answerable.
if grep -E 'for a in .*subsystem_vendor' "$BUNDLE" >/dev/null; then
    ok "bundle captures subsystem_vendor"
else
    bad "bundle missing subsystem_vendor" "get_hba_info.sh reads it to gate the firmware verdict, but pci.txt does not capture it"
fi

# hba_topology() in lib.sh checks /sys/class/sas_expander/expander-* FIRST --
# a DIFFERENT sysfs class from /sys/class/sas_device/expander-* (already
# captured above as sas_expander.txt, despite the confusingly similar name).
# An expander on this class is one of the two signals that decide "internal"
# vs "unknown", which in turn decides whether a firmware verdict is given at
# all. A bundle that omits this class cannot show why a card got that verdict.
if grep -E 'dump_attrs 03-sysfs/[A-Za-z_]+\.txt +/sys/class/sas_expander/expander-\*' "$BUNDLE" >/dev/null; then
    ok "sas_expander class (the topology gate) is dumped"
else
    bad "sas_expander class not dumped" "hba_topology() reads /sys/class/sas_expander/expander-* first; the bundle only captured /sys/class/sas_device/expander-*, a different class"
fi

echo
[ $fail -eq 0 ] && { echo "bundle-coverage: all pass"; exit 0; } || { echo "bundle-coverage: FAILURES"; exit 1; }
