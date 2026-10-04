#!/bin/bash
# Run the PHP unit tests. Prefers a local php; falls back to a throwaway
# php:8.2-cli container (Unraid 7.x ships PHP 8.2). These tests use only core
# PHP, so no Slackware/Unraid image is needed.
#
#   bash tests/run_php.sh
cd "$(dirname "$0")/.." || exit 2

# Never on the box (#26): these tests read and rewrite the live plugin's /tmp
# state (hbav_health_c*.json). See tests/run.sh.
if [ -e /etc/unraid-version ]; then
    echo "refusing: this is an Unraid host; run docs/install-verify.sh here instead (#26)" >&2
    exit 2
fi

TESTS="config_test.php view_test.php event_archive_test.php cached_read_test.php
flash_php_test.php ajax_render_test.php health_test.php notify_test.php disk_alerts_test.php
bundle_php_test.php bay_map_test.php locate_test.php diagnose_test.php diagnose_php_test.php diagnose_render_test.php phy_baseline_test.php
export_test.php plg_test.php firmware_index_test.php card_group_test.php
controller_schema_test.php review_policy_test.php"

CMD=""
for t in $TESTS; do CMD="${CMD:+$CMD && }php tests/$t"; done

out=$(mktemp)
if command -v php >/dev/null 2>&1; then
    sh -c "$CMD" > "$out" 2>&1; rc=$?
else
    echo "no local php — using php:8.2-cli via docker"
    MSYS_NO_PATHCONV=1 docker run --rm \
        -v "$(pwd -W 2>/dev/null || pwd):/app" -w /app php:8.2-cli \
        sh -c "$CMD" > "$out" 2>&1; rc=$?
fi
cat "$out"

# A PASS line proves an assertion held; it does not prove the render that
# produced it was clean. These tests deliberately feed renderers malformed and
# cross-shaped payloads, and a renderer that reads a key it was not given still
# emits its table -- so the assertion passes while PHP prints a warning nobody
# reads. Fail on any diagnostic, or the suite runs permanently dirty and the
# next real one hides in the noise.
if grep -qE '^(Warning|Deprecated|Notice|Fatal error|Parse error):' "$out"; then
    echo
    echo "FAIL  PHP emitted diagnostics (a passing assertion is not a clean render):"
    grep -nE '^(Warning|Deprecated|Notice|Fatal error|Parse error):' "$out" | sed 's/^/      /'
    rc=1
fi
rm -f "$out"
exit $rc
