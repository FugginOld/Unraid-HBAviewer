#!/bin/bash
# Shell view of the lsiutil config. Sourced by every composer. Resolves the two
# keys shell actually needs; SHOW_* are PHP display toggles and stay out of here.
# The cfg is written only by config.php (clamped) and the .plg (fixed), so
# sourcing it as bash is safe. Defaults live once, here.

CFG="${LSI_CFG_PATH:-/boot/config/plugins/hbaviewer/hbaviewer.cfg}"
[ -f "$CFG" ] && source "$CFG"
PORT="${HBA_PORT:-1}"
# 76, matching config.php's LSI_SCHEMA. This file's header claimed "defaults
# live once, here" while config.php declared a different number (80), but that
# never meant divergent behaviour: band_of buckets 76-85 as the same "warning"
# band, so a box whose cfg lacked the key banded and badged identically either
# way -- only the printed number on the Settings page differed. 80 was also
# never one of the four legal band floors (66/76/86/96) the schema documents.
# The two are still written in two places -- a shell script cannot read a PHP
# const, and parsing one would trade a wrong number for a fragile one -- but
# tests/config_test.php now fails if they ever disagree, which is the
# guarantee the comment was claiming all along.
ALERT="${ALERT_THRESHOLD:-76}"
