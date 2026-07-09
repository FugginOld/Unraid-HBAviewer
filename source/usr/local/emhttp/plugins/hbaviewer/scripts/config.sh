#!/bin/bash
# Shell view of the lsiutil config. Sourced by every composer. Resolves the two
# keys shell actually needs; SHOW_* are PHP display toggles and stay out of here.
# The cfg is written only by config.php (clamped) and the .plg (fixed), so
# sourcing it as bash is safe. Defaults live once, here.

CFG="${LSI_CFG_PATH:-/boot/config/plugins/hbaviewer/hbaviewer.cfg}"
[ -f "$CFG" ] && source "$CFG"
PORT="${HBA_PORT:-1}"
ALERT="${ALERT_THRESHOLD:-80}"
