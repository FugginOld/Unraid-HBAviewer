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
# health rollup: failed drive -> alert (even at 50C); PHY errors -> warn
check rollup-faildrive rollup_faildrive.json bash "$P/storcli_overview.sh" 80 0 < fixtures/storcli/rollup_faildrive.txt
check rollup-phyerr    rollup_phyerr.json    bash "$P/storcli_overview.sh" 80 5 < fixtures/storcli/rollup_healthy.txt
check rollup-healthy   rollup_healthy.json   bash "$P/storcli_overview.sh" 80 0 < fixtures/storcli/rollup_healthy.txt
check storcli-phy      storcli_phy.json     bash "$P/storcli_phy.sh" fixtures/storcli/sysfs_phy.txt < fixtures/storcli/phy_c0.txt
check storcli-drives   storcli_drives.json  bash "$P/storcli_drives.sh" < fixtures/storcli/drives_c0.txt
check storcli-encl     storcli_enclosures.json bash "$P/storcli_enclosures.sh" < fixtures/storcli/enclosures_c0.txt
check storcli-events   storcli_events.json  bash "$P/storcli_events.sh" < fixtures/storcli/events_c0.txt
check smart-sas        smart_sas.json       bash "$P/smart.sh" < fixtures/smart/sas_drive.txt
check smart-sata       smart_sata.json      bash "$P/smart.sh" < fixtures/smart/sata_drive.txt

# storcli multi-controller backend, driven by a stubbed storcli replaying fixtures
chmod +x stub/storcli stub/lsiutil 2>/dev/null
export STUB_FIX="$PWD/fixtures/storcli" STORCLI="$PWD/stub/storcli" LSI_CACHE=/dev/null

# get_hba_info backend routing: storcli present -> storcli backend; else lsiutil
check route-storcli    storcli_multi.json   bash "$P/../get_hba_info.sh"
STORCLI=/nonexistent LSIUTIL=/nonexistent \
check route-fallback   route_no_backend.json bash "$P/../get_hba_info.sh"
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
check drives-join  drives_join.json  bash "$P/drives_join.sh" fixtures/drives_osmap.txt fixtures/drives_sasmap.txt

echo
echo "=== PHP tests ==="
bash run_php.sh; php_fail=$?

echo
if [ $fail -eq 0 ] && [ $php_fail -eq 0 ]; then
    echo "--- all pass ---"; exit 0
else
    echo "--- FAILURES ---"; exit 1
fi
