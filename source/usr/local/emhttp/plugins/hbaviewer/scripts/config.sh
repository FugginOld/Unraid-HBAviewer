#!/bin/bash
# Shell view of the lsiutil config. Sourced by every composer. Resolves the two
# keys shell actually needs; SHOW_* are PHP display toggles and stay out of here.
# The cfg is written only by config.php and the .plg (fixed), so sourcing it as
# bash is safe — but NOT because every value is an int any more (plan 029 added
# INLET_SENSOR, a string). The invariant that makes this safe now is that
# config.php's lsi_config_write() single-quotes every string value
# (lsi_cfg_quote) AND whitelists its charset before that (lsi_sanitise_sensor),
# so nothing sourceable ever reaches disk. Defaults live once, here.

CFG="${LSI_CFG_PATH:-/boot/config/plugins/hbaviewer/hbaviewer.cfg}"
[ -f "$CFG" ] && source "$CFG"
PORT="${HBA_PORT:-1}"
ALERT="${ALERT_THRESHOLD:-80}"
