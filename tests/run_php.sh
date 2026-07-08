#!/bin/bash
# Run the PHP unit tests. Prefers a local php (what the Unraid box has); falls
# back to a throwaway php:8.2-cli container (Unraid 7.x ships PHP 8.2). These
# tests use only core PHP, so no Slackware/Unraid image is needed.
#
#   bash tests/run_php.sh
cd "$(dirname "$0")/.." || exit 2

if command -v php >/dev/null 2>&1; then
    php tests/config_test.php && php tests/view_test.php
else
    echo "no local php — using php:8.2-cli via docker"
    MSYS_NO_PATHCONV=1 docker run --rm \
        -v "$(pwd -W 2>/dev/null || pwd):/app" -w /app php:8.2-cli \
        sh -c 'php tests/config_test.php && php tests/view_test.php'
fi
