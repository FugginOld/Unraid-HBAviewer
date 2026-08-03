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
check smart-sas        smart_sas.json       bash "$P/smart.sh" < fixtures/smart/sas_drive.txt
check smart-sata       smart_sata.json      bash "$P/smart.sh" < fixtures/smart/sata_drive.txt
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
# being applied to every tile.
SYSPCI=$(mktemp -d)
SYSHOST=$(mktemp -d)
trap 'rm -rf "$SYSPCI" "$SYSHOST"' EXIT
for spec in "0000:c1:00.0 8" "0000:65:00.0 4"; do
    set -- $spec
    mkdir -p "$SYSPCI/$1"
    printf '%s\n' "$2"          > "$SYSPCI/$1/current_link_width"
    printf '8.0 GT/s PCIe\n'    > "$SYSPCI/$1/current_link_speed"
    printf 'D0\n'               > "$SYSPCI/$1/power_state"
done

# storcli multi-controller backend, driven by a stubbed storcli replaying fixtures
chmod +x stub/storcli stub/lsiutil 2>/dev/null
export STUB_FIX="$PWD/fixtures/storcli" STORCLI="$PWD/stub/storcli" LSI_CACHE=/dev/null SYS_PCI_ROOT="$SYSPCI"

# get_hba_info backend routing: storcli present -> storcli backend; else lsiutil
check route-storcli    storcli_multi.json   bash "$P/../get_hba_info.sh"
STORCLI=/nonexistent LSIUTIL=/nonexistent \
check route-fallback   route_no_backend.json bash "$P/../get_hba_info.sh"
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
echo "=== PHP tests ==="
bash run_php.sh; php_fail=$?

echo
if [ $fail -eq 0 ] && [ $flash_fail -eq 0 ] && [ $anon_fail -eq 0 ] && [ $php_fail -eq 0 ]; then
    echo "--- all pass ---"; exit 0
else
    echo "--- FAILURES ---"; exit 1
fi
