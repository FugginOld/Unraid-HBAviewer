#!/bin/bash
# Shared HBA invocation — the single seam to the lsiutil binary.
#
# hba_query owns only the universal part: where the binary lives. Everything
# else (port, -e expert flag, -a menu args, -b, stdin) passes through, so the
# same function covers every call style:
#   hba_query -p"$PORT" -a 25,2,0,0     # menu command on a port
#   printf '0\n' | hba_query            # interactive banner, no port
#   hba_query -b                        # board info, no port
#   hba_query -e -p"$PORT" -a 35,0      # expert-mode command
#
# require_binary emits the not-found error JSON and returns non-zero. Composers
# call it BEFORE the query|parse pipe so the error reaches PHP, never a parser.

LSIUTIL="/usr/local/emhttp/plugins/lsiutil/lsiutil.x86_64"

require_binary() {
    if [ ! -x "$LSIUTIL" ]; then
        echo '{"error":"lsiutil binary not found. Re-install the plugin."}'
        return 1
    fi
}

hba_query() { "$LSIUTIL" "$@"; }
