#!/bin/bash
# Full test suite: shell parser goldens + PHP unit tests. No hardware.
# Golden cases feed a fixture to a parser and diff stdout against expected/ —
# a dropped or renamed JSON field fails here. PHP tests run via run_php.sh.
#
#   bash tests/run.sh
#
# Regenerate goldens after an INTENTIONAL parser change:
#   UPDATE=1 bash tests/run.sh
cd "$(dirname "$0")" || exit 2
P="../source/usr/local/emhttp/plugins/hbaviewer/scripts/parse"
fail=0

check() {  # name  expected_file  command...
    local name=$1 exp=$2; shift 2
    local got; got=$("$@")
    if [ "${UPDATE:-}" = "1" ]; then printf '%s' "$got" > "expected/$exp"; echo "WROTE $name"; return; fi
    if [ "$got" = "$(cat "expected/$exp")" ]; then
        echo "PASS  $name"
    else
        echo "FAIL  $name"
        diff <(printf '%s\n' "$got") <(cat "expected/$exp"; echo)
        fail=1
    fi
}

# stdin filters
check phy-healthy      phy_healthy.json      bash "$P/phy.sh"          < fixtures/phy_healthy.txt
check phy-unsupported  phy_unsupported.json  bash "$P/phy.sh"          < fixtures/phy_unsupported.txt
check events-entries   events_entries.json   bash "$P/events.sh"       < fixtures/events_entries.txt
check events-empty     events_empty.json     bash "$P/events.sh"       < fixtures/events_empty.txt
check drives-osmap     drives_osmap.txt      bash "$P/drives_osmap.sh" < fixtures/drives_hbaviewer.txt
check storcli-overview storcli_overview.json bash "$P/storcli_overview.sh" 80 < <(cat fixtures/storcli/overview_c0.txt fixtures/storcli/temp_c0.txt)
# PCIe link + power state arrive as $4/$5/$6 from the composer (sysfs); storcli reports none
check storcli-overview-pcie storcli_overview_pcie.json bash "$P/storcli_overview.sh" 80 0 "" "x8" "Gen3 (8.0 GT/s)" "Full" < <(cat fixtures/storcli/overview_c0.txt fixtures/storcli/temp_c0.txt)
# Real `/c0 show` + `/c0 show temperature` from issue #5 (@t0ffemannen,
# SAS3008/IR firmware): eight blank-EID UGood rows in PD LIST followed by the
# legend block whose "UGood-Unconfigured Good|..." text is the exact string
# that false-matched MODE before plan 017. ROC temperature 56.
check storcli-overview-noencl-ugood storcli_overview_noencl_ugood.json bash "$P/storcli_overview.sh" 80 < <(cat fixtures/storcli/overview_noencl_ugood.txt fixtures/storcli/temp_noencl_ugood.txt)
# Real `/c0 show` + `/c0 show temperature` from issue #10 (@PaliKinG3), an
# IT-FLASHED SAS9305-16i reporting 13x UGood. Before plan 045 this card was
# labelled IR: UGood means "unconfigured", not "IR firmware". Mode must be ""
# — no IR firmware exists for a 9305-16i, and an empty mode hides the row
# rather than stating a falsehood.
check storcli-overview-9305 storcli_overview_9305.json bash "$P/storcli_overview.sh" 80 < <(cat fixtures/storcli/overview_9305.txt fixtures/storcli/temp_9305.txt)
# AdapterType passed by the composer wins over the device-ID map (plan 045
# Part B). 0xC4 is deliberately NOT in that map — this is the case that map
# could never have handled.
check storcli-overview-chiparg storcli_overview_chiparg.json bash "$P/storcli_overview.sh" 80 0 "SAS3224" < <(cat fixtures/storcli/overview_9305.txt fixtures/storcli/temp_9305.txt)
# health rollup: failed drive -> alert (even at 50C); PHY errors -> warn
check rollup-faildrive rollup_faildrive.json bash "$P/storcli_overview.sh" 80 0 < fixtures/storcli/rollup_faildrive.txt
check rollup-phyerr    rollup_phyerr.json    bash "$P/storcli_overview.sh" 80 5 < fixtures/storcli/rollup_healthy.txt
check rollup-healthy   rollup_healthy.json   bash "$P/storcli_overview.sh" 80 0 < fixtures/storcli/rollup_healthy.txt

# Band cut-points are the whole feature of plan 018 — one golden per boundary, so
# an off-by-one in either direction fails loudly. 76 = "complain from Warning".
for t in 65 66 75 76 85 86 95 96; do
    check "band-$t" "band_$t.json" bash -c \
      "sed 's/^ROC temperature(Degree Celsius).*/ROC temperature(Degree Celsius) $t/' fixtures/storcli/rollup_healthy.txt | bash '$P/storcli_overview.sh' 76 0"
done
# PHY floor: 8 errors (the real-world case from issue #8) must NOT warn; 100 must.
check phy-under-floor phy_under_floor.json bash -c "bash '$P/storcli_overview.sh' 76 8   < fixtures/storcli/rollup_healthy.txt"
check phy-over-floor  phy_over_floor.json  bash -c "bash '$P/storcli_overview.sh' 76 100 < fixtures/storcli/rollup_healthy.txt"

check storcli-phy      storcli_phy.json     bash "$P/storcli_phy.sh" fixtures/storcli/sysfs_phy.txt < fixtures/storcli/phy_c0.txt
check storcli-drives   storcli_drives.json  bash "$P/storcli_drives.sh" < fixtures/storcli/drives_c0.txt
# Enclosure-less controllers (blank EID in PD LIST) address drives /c0/sN. Real
# output: issue #6 is a SAS3416 on IT firmware (JBOD), issue #5 a SAS3224 on IR
# firmware (UGood, no (path0) suffix on the port, Connector Name = N/A).
check storcli-drives-noencl-jbod  storcli_drives_noencl_jbod.json  bash "$P/storcli_drives.sh" < fixtures/storcli/drives_noencl_jbod.txt
check storcli-drives-noencl-ugood storcli_drives_noencl_ugood.json bash "$P/storcli_drives.sh" < fixtures/storcli/drives_noencl_ugood.txt
# Real `/c0/sall show all` from the same issue #5 report: eight drives, slots
# s0-s7, with DIDs deliberately out of order (5 at s4, 4 at s6) -- pins that
# the parser keys on slot, not device id or row order. Also the only fixture
# with a double space inside a model name ("WDC  WUH721818ALE6L4").
check storcli-drives-noencl-ugood8 storcli_drives_noencl_ugood8.json bash "$P/storcli_drives.sh" < fixtures/storcli/drives_noencl_ugood8.txt
check storcli-encl     storcli_enclosures.json bash "$P/storcli_enclosures.sh" < fixtures/storcli/enclosures_c0.txt
check storcli-events   storcli_events.json  bash "$P/storcli_events.sh" < fixtures/storcli/events_c0.txt
check smart-sas        smart_sas.json       bash "$P/smart.sh" sas  < fixtures/smart/sas_drive.txt
check smart-sata       smart_sata.json      bash "$P/smart.sh" sata < fixtures/smart/sata_drive.txt
# No transport arg passed (lsblk reported usb/nvme/nothing, or was never run).
# The drive's own ATA attribute table is still enough to call it "sata" --
# the injected bus arg is only a fallback for when the drive's output can't
# be classified at all (e.g. asleep under -n standby, almost no SMART data).
check smart-notran     smart_notran.json    bash "$P/smart.sh"      < fixtures/smart/sata_drive.txt
# Real-world shape from issue #10 (@jac2424): a SATA drive behind a SAS9207-8i.
# lsblk calls it TRAN=sas — every one of his eight SATA drives did — so the
# composer passes "sas" here. The drive's own output is an ATA attribute table
# with no SCSI fields, and THAT is what must decide the reported type.
check smart-sata-behind-sas smart_sata_behind_sas.json bash "$P/smart.sh" sas < fixtures/smart/sata_behind_sas.txt
check diskstats        diskstats.json       bash "$P/diskstats.sh" "sdb sdc" < fixtures/diskstats.txt

# Performance-tab temperatures: per controller, in order. Covers the lsiutil
# pretty-printed shape (space after the colon) and an erroring controller —
# the two cases a positional grep got wrong.
check cache-temps-storcli cache_temps_storcli.txt bash "$P/cache_temps.sh" < fixtures/cache_storcli_multi.json
check cache-temps-lsiutil cache_temps_lsiutil.txt bash "$P/cache_temps.sh" < fixtures/cache_lsiutil_notemp.json
check cache-temps-mixed   cache_temps_mixed.txt   bash "$P/cache_temps.sh" < fixtures/cache_mixed_error.json

# Fake sysfs PCI tree for the storcli composer. Built here rather than committed:
# the directory names contain colons, which Windows cannot store — git would
# receive a U+F03A lookalike and the lookup would silently miss on Linux.
# c0 is x8 and c1 is x4 on purpose: the asymmetry catches one card's link state
# being applied to every tile. Same reasoning for subsystem_vendor: c0 has one
# (a generic Broadcom 0x1000) and c1 has none, so BOTH directions of
# hba_subvendor are pinned through the composer. Without a file there at all,
# deleting the LSI_SUBVENDOR wiring outright left this suite green while gate 2
# turned every controller oem_out_of_scope and the feature rendered nothing.
#
# The device dirs sit under a fake host bridge, because that is what
# hba_card_id walks. A flat tree with no bridge at all resolves to an empty
# card_id, which would pin the "cannot tell" case in every golden and let a
# deleted sysfs walk pass.
#
# c0 and c1 sit directly ON the bridge, not behind a shared root port: two
# distinct boards (a 9400-16i and a 9400-8i) cannot physically share a slot,
# so each must resolve to ITS OWN address ("a device on the host bridge is
# its own slot" — the same case topology_test.sh already pins directly).
# Modelling this as two separate root ports instead (each card behind its
# own 0000:80:0N.0) would need get_hba_info.sh's composer to see each device
# through a distinct symlinked path, the way real /sys/bus/pci/devices does
# it — but `ln -s` is a silent no-op in this repo's Windows/MSYS test shell
# (exit 0, no actual link produced; same reason topology_test.sh's own
# symlink case is SKIP here), so this uses the symlink-free case instead.
SYSPCI_ROOT=$(mktemp -d)
SYSPCI="$SYSPCI_ROOT/pci0000:80"
mkdir -p "$SYSPCI"
SYSHOST=$(mktemp -d)
SYSDEV=$(mktemp -d)
SYSEXP=$(mktemp -d)
SYSPHY=$(mktemp -d)
trap 'rm -rf "$SYSPCI_ROOT" "$SYSHOST" "$SYSDEV" "$SYSEXP" "$SYSPHY"' EXIT
for spec in "0000:c1:00.0 8 0x1000" "0000:65:00.0 4 -"; do
    set -- $spec
    mkdir -p "$SYSPCI/$1"
    printf '%s\n' "$2"          > "$SYSPCI/$1/current_link_width"
    printf '8.0 GT/s PCIe\n'    > "$SYSPCI/$1/current_link_speed"
    printf 'D0\n'               > "$SYSPCI/$1/power_state"
    [ "$3" = - ] || printf '%s\n' "$3" > "$SYSPCI/$1/subsystem_vendor"
done

# Topology, unpinned, would glob the REAL machine's /sys/class/sas_device and
# /sys/class/sas_expander, so this golden would read "unknown" on a dev box and
# silently start reading "internal" on the very 9305-24i box that motivated the
# feature, the day someone runs this suite there.
#
# c0 is host7 on purpose, NOT host0: ov_storcli derives the host from the card's
# own PCI device dir, and a fixture where the host number happened to equal the
# controller index could not tell that derivation apart from the old
# host-N-equals-controller-N guess. host7 has two direct-attached end_devices
# and no expander -> "internal". c1 has no host under its PCI dir at all ->
# "unknown", the fail-safe. Different verdicts on purpose: every other golden in
# this file records the suppressing default, so without this pair nothing
# anywhere exercises hba_topology's positive ("internal") path end to end
# through the composer.
mkdir -p "$SYSPCI/0000:c1:00.0/host7" "$SYSDEV/end_device-7:0" "$SYSDEV/end_device-7:1"

# ── A whole card for the lsiutil composer ────────────────────────────────────
# The storcli fixture above cannot serve this: ov_lsiutil never sees a storcli
# PCI Address, it walks UP from the scsi_host to find the card, so the host has
# to sit physically inside the PCI dir the way the kernel arranges it —
#   <pci dev>/hostN/scsi_host/hostN
# with SYS_SCSI_HOST pointing at that scsi_host dir, which is what readlink -f
# resolves to on hardware. Same shape health_sh_test.sh already uses.
#
# Why it exists at all: every lsiutil route check below stops at require_binary,
# so nothing ever reached ov_lsiutil's tail, where LSI_TOPOLOGY and
# LSI_SUBVENDOR are derived. Deleting BOTH derivations left this entire suite
# green — while gate 2, reading an empty subvendor, turned every controller
# oem_out_of_scope and the firmware verdict rendered nothing at all, with no
# error anywhere. That is the SAS2 population: 9211-8i, IBM M1015, Dell
# H200/H310 — exactly the OEM-rebrand cohort the gate exists to protect.
#
# host3, not host0: the number must not coincide with the controller index, or
# the golden cannot tell the derivation apart from a hardcoded 0.
# Nested one level deeper than $SYSPCI: a device behind a root port, so this
# golden also pins the case where the slot is an ancestor rather than the
# device itself.
SYSL_ROOT=$(mktemp -d)
SYSL="$SYSL_ROOT/pci0000:00/0000:00:02.0"
mkdir -p "$SYSL"
trap 'rm -rf "$SYSPCI_ROOT" "$SYSHOST" "$SYSDEV" "$SYSEXP" "$SYSPHY" "$SYSL_ROOT"' EXIT
LCARD="$SYSL/0000:03:00.0"
mkdir -p "$LCARD/host3/scsi_host/host3"
printf '8\n'             > "$LCARD/current_link_width"
printf '8.0 GT/s PCIe\n' > "$LCARD/current_link_speed"
printf 'D0\n'            > "$LCARD/power_state"
printf '0x1000\n'        > "$LCARD/subsystem_vendor"
printf 'mpt2sas\n'       > "$LCARD/host3/scsi_host/host3/proc_name"
# Matches hba_board.txt, which is where the JSON's board_name actually comes
# from — sysfs board_name is only read on the SAS3-refusal path. One card, one
# name, so a reader is not left wondering which is authoritative.
printf 'SAS9207-8i\n'    > "$LCARD/host3/scsi_host/host3/board_name"
# Two direct-attached drives and no expander -> "internal", so this golden pins
# hba_topology's positive answer on the lsiutil path too.
mkdir -p "$SYSDEV/end_device-3:0" "$SYSDEV/end_device-3:1"

# ── A dual-IOC card for the storcli composer (route-storcli-dual, below) ────
# Every storcli fixture elsewhere in this file produces controllers with
# DISTINCT card_ids (c0 and c1 each sit directly on the host bridge, i.e. on
# their own slot) -- so the feature's own precondition, two controllers
# sharing ONE slot, was otherwise only ever produced by hand-written PHP
# arrays in card_group_test.php, never by the real shell pipeline. This gives
# hba_card_id a tree where both IOCs of one SAS9300-16i sit behind a shared
# root port (0000:80:01.0) via an intermediate switch hop (0000:82:00.0) --
# one extra segment beyond the root port, so this also pins that
# hba_card_id's "${rest%%/*}" resolves the SLOT regardless of how many more
# levels sit below it, the same depth-independence its own doc comment's
# real-hardware example (root port / upstream switch port / downstream switch
# port / IOC function) relies on.
SYSDUAL_ROOT=$(mktemp -d)
SYSDUAL="$SYSDUAL_ROOT/pci0000:80/0000:80:01.0/0000:82:00.0"
mkdir -p "$SYSDUAL/0000:84:00.0" "$SYSDUAL/0000:86:00.0"
trap 'rm -rf "$SYSPCI_ROOT" "$SYSHOST" "$SYSDEV" "$SYSEXP" "$SYSPHY" "$SYSL_ROOT" "$SYSDUAL_ROOT"' EXIT
for d in 0000:84:00.0 0000:86:00.0; do
    printf '8\n'             > "$SYSDUAL/$d/current_link_width"
    printf '8.0 GT/s PCIe\n' > "$SYSDUAL/$d/current_link_speed"
    printf 'D0\n'            > "$SYSDUAL/$d/power_state"
done

# storcli multi-controller backend, driven by a stubbed storcli replaying fixtures
chmod +x stub/storcli stub/lsiutil 2>/dev/null
export STUB_FIX="$PWD/fixtures/storcli" STORCLI="$PWD/stub/storcli" LSI_CACHE=/dev/null \
       SYS_PCI_ROOT="$SYSPCI" SYS_SAS_DEVICE="$SYSDEV" SYS_SAS_EXPANDER="$SYSEXP" SYS_SAS_PHY="$SYSPHY"

# get_hba_info backend routing: storcli present -> storcli backend; else lsiutil
check route-storcli    storcli_multi.json   bash "$P/../get_hba_info.sh"
# Two SAS3008 IOCs both reporting board name SAS9300-16i, both resolving (via
# SYSDUAL above) to the same root port -> the same card_id. The only check in
# this suite that would catch the composer emitting DIFFERENT card_ids for a
# genuine dual-IOC board -- everything else pins the split (distinct-slot) case.
STUB_FIX="$PWD/fixtures/storcli_dual" SYS_PCI_ROOT="$SYSDUAL" \
check route-storcli-dual storcli_dual.json bash "$P/../get_hba_info.sh"
STORCLI=/nonexistent LSIUTIL=/nonexistent \
check route-fallback   route_no_backend.json bash "$P/../get_hba_info.sh"
# The lsiutil composer, all the way through — the only check that reaches
# ov_lsiutil's tail. STORCLI= (empty, not /nonexistent) so find_storcli falls
# through to probing PATH; like the personality checks below, this assumes no
# real storcli is installed on the machine running the suite.
# STUB_FIX is overridden: the exported value points at fixtures/storcli for the
# checks above, and the lsiutil captures live one level up in fixtures/.
STORCLI= LSIUTIL="$PWD/stub/lsiutil" SYS_SCSI_HOST="$LCARD/host3/scsi_host" STUB_FIX="$PWD/fixtures" \
check route-lsiutil    lsiutil_overview.json bash "$P/../get_hba_info.sh"
# Controller generation comes from proc_name, never from /sys/module — the merged
# mpt3sas driver reports proc_name=mpt2sas for SAS2 cards (issue #3). host9 is a
# non-SAS host that must be ignored by the filter.
mkdir -p "$SYSHOST/host0" "$SYSHOST/host9"
printf 'ahci\n' > "$SYSHOST/host9/proc_name"
# A host on the mpt2sas personality must reach require_binary instead of being
# refused, so this reuses route-fallback's expectation — it fails if the
# personality predicate (hba_has_sas3 && ! hba_has_sas2) is ever inverted or
# dropped.
# STORCLI must be truly EMPTY here, not /nonexistent: find_storcli() only checks
# "-n $STORCLI" (an override honored verbatim, existence unchecked elsewhere), so
# a non-empty-but-missing path still makes the guard's `[ -z "$(find_storcli)" ]`
# false regardless of personality, and the case reaches require_binary for the
# wrong reason (storcli "found") rather than the right one. An empty override
# falls through to find_storcli probing PATH for a real storcli, so — like the
# case below — this assumes no real storcli is installed on the machine running
# the suite; if one is, both of these fail for an environment reason, not a code
# regression.
printf 'mpt2sas\n'    > "$SYSHOST/host0/proc_name"
printf 'SAS9207-8i\n' > "$SYSHOST/host0/board_name"
STORCLI= LSIUTIL=/nonexistent SYS_SCSI_HOST="$SYSHOST" \
check route-sas2-personality route_no_backend.json bash "$P/../get_hba_info.sh"
# mpt3sas personality only, no storcli: refuse, and name the board. Same STORCLI=
# reasoning as route-sas2-personality above (find_storcli() honors any non-empty
# override verbatim, so /nonexistent would still short-circuit the guard) — and
# the same PATH-fallthrough caveat: this assumes no real storcli is on the
# suite-runner's PATH.
printf 'mpt3sas\n'    > "$SYSHOST/host0/proc_name"
printf 'SAS9300-8i\n' > "$SYSHOST/host0/board_name"
STORCLI= LSIUTIL=/nonexistent SYS_SCSI_HOST="$SYSHOST" \
check route-sas3-no-storcli route_sas3_no_storcli.json bash "$P/../get_hba_info.sh"
check phy-route        get_phy_storcli.json  bash "$P/../get_phy_health.sh"
check drives-route     get_drives_storcli.json bash "$P/../get_attached_drives.sh"
check events-route     get_events_storcli.json bash "$P/../get_event_log.sh"

# lsiutil dispatch path: no storcli -> module picks lsiutil, wraps a fake binary's
# firmware-log output. Covers the previously-untested backend half of hba_each.
STUB_FIX="$PWD/fixtures" STORCLI=/nonexistent LSIUTIL="$PWD/stub/lsiutil" \
check events-lsiutil   get_events_lsiutil.json bash "$P/../get_event_log.sh"

# multi-file parsers
check hba-normal   hba_normal.json   bash "$P/hba.sh" fixtures/hba_ioc.txt fixtures/hba_banner.txt fixtures/hba_board.txt 80
check hba-notemp   hba_notemp.json   bash "$P/hba.sh" fixtures/hba_ioc_notemp.txt fixtures/hba_banner.txt fixtures/hba_board.txt 80
# hba-notemp omits the field; a 9211-8i PRINTS it and reads 0x0000 (issue #17).
# Both are "no sensor" — 0 °C is not a reading, and treating it as one showed a
# blue "normal" pill on a card that has nothing to report.
check hba-zerotemp hba_zerotemp.json bash "$P/hba.sh" fixtures/hba_ioc_zerotemp.txt fixtures/hba_banner.txt fixtures/hba_board.txt 80
# Firmware is four packed HEX bytes: 14000700 is P20 (0x14=20), not "14.00.07.00".
# hba-normal covers the P20 decode; this covers a genuinely old one still tripping
# the pre-P20 flag (10000700 = P16).
check hba-p16      hba_p16.json      bash "$P/hba.sh" fixtures/hba_ioc.txt fixtures/hba_banner_p16.txt fixtures/hba_board.txt 80
# PCIeSpeed is an enum, not a bitmask (plan 038): 0x00 is Gen1, and under the
# old bitmask table it matched nothing and rendered an empty string.
check hba-gen1     hba_gen1.json     bash "$P/hba.sh" fixtures/hba_ioc_gen1.txt fixtures/hba_banner.txt fixtures/hba_board.txt 80
# Real `lsiutil -a 1,0` from issue #10 (@jac2424, SAS9207-8i / SAS2308,
# mpt2sas, firmware 20.00.07 IT-flashed). The personality is the suffix on
# "Firmware image's version is MPTFW-20.00.07.00-IT".
check hba-mode-it  hba_mode_it.json  bash "$P/hba.sh" fixtures/hba_ioc.txt fixtures/hba_banner.txt fixtures/hba_board.txt 80 fixtures/hba_ident_it.txt
# SYNTHETIC: hba_ident_ir.txt is hba_ident_it.txt with the one suffix
# changed IT->IR. No real IR-firmware SAS2 capture exists in this project;
# this pins the IR branch's shape, NOT that real IR output looks like this.
check hba-mode-ir  hba_mode_ir.json  bash "$P/hba.sh" fixtures/hba_ioc.txt fixtures/hba_banner.txt fixtures/hba_board.txt 80 fixtures/hba_ident_ir.txt
# Real "ERROR:  No such port." from the same capture: no MPTFW line -> mode ""
# so the UI hides the row instead of guessing.
check hba-mode-noport hba_mode_noport.json bash "$P/hba.sh" fixtures/hba_ioc.txt fixtures/hba_banner.txt fixtures/hba_board.txt 80 fixtures/hba_ident_noport.txt
check drives-join  drives_join.json  bash "$P/drives_join.sh" fixtures/drives_osmap.txt fixtures/drives_sasmap.txt

echo
echo "=== flash tests ==="
bash flash_test.sh; flash_fail=$?

echo
echo "=== bundle anonymisation tests ==="
bash anon_test.sh; anon_fail=$?

echo
echo "=== read_smart tests ==="
bash read_smart_test.sh; read_smart_fail=$?

echo
echo "=== health drive-count tests ==="
bash health_sh_test.sh; health_sh_fail=$?

echo
echo "=== drives sysfs (SAS transport) tests ==="
bash drives_sysfs_test.sh; drives_sysfs_fail=$?

echo
echo "=== topology / subvendor / card_id tests ==="
bash topology_test.sh; topology_fail=$?

echo
echo "=== drive locate tests ==="
bash locate_sh_test.sh; locate_sh_fail=$?

echo
echo "=== phys_json expander-collision tests ==="
bash phys_json_test.sh; phys_json_fail=$?

echo
echo "=== SMART cache capacity tests ==="
bash collect_smart_test.sh; collect_smart_fail=$?

echo
echo "=== bundle coverage tests ==="
bash bundle_coverage_test.sh; bundle_coverage_fail=$?

echo
echo "=== PHP tests ==="
bash run_php.sh; php_fail=$?

echo
if [ $fail -eq 0 ] && [ $flash_fail -eq 0 ] && [ $anon_fail -eq 0 ] && [ $read_smart_fail -eq 0 ] && [ $health_sh_fail -eq 0 ] && [ $drives_sysfs_fail -eq 0 ] && [ $topology_fail -eq 0 ] && [ $locate_sh_fail -eq 0 ] && [ $phys_json_fail -eq 0 ] && [ $bundle_coverage_fail -eq 0 ] && [ $collect_smart_fail -eq 0 ] && [ $php_fail -eq 0 ]; then
    echo "--- all pass ---"; exit 0
else
    echo "--- FAILURES ---"; exit 1
fi
