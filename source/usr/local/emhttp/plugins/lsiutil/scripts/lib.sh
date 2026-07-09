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

LSIUTIL="${LSIUTIL:-/usr/local/emhttp/plugins/lsiutil/lsiutil.x86_64}"

require_binary() {
    if [ ! -x "$LSIUTIL" ]; then
        echo '{"error":"lsiutil binary not found. Re-install the plugin."}'
        return 1
    fi
}

hba_query() { "$LSIUTIL" "$@"; }

# Locate storcli (SAS3/3.5 tool) — same candidates as scripts/capture_storcli.sh.
# Honors a preset $STORCLI. Prints the resolved path, or nothing if not found.
find_storcli() {
    if [ -n "$STORCLI" ]; then echo "$STORCLI"; return; fi
    local c
    for c in storcli storcli64 storcli2 \
             /usr/local/sbin/storcli /usr/local/sbin/storcli64 \
             /usr/sbin/storcli /usr/sbin/storcli64; do
        command -v "$c" >/dev/null 2>&1 && { command -v "$c"; return; }
        [ -x "$c" ] && { echo "$c"; return; }
    done
}

# True (and export a resolved $STORCLI) iff storcli is present and enumerates a
# controller. The routing test every tab composer uses to pick its backend.
use_storcli() {
    local sc n
    sc="$(find_storcli)"
    [ -n "$sc" ] || return 1
    n=$("$sc" show 2>/dev/null | grep -m1 'Number of Controllers' | grep -oE '[0-9]+')
    [ -n "$n" ] && [ "$n" -gt 0 ] || return 1
    STORCLI="$sc"; export STORCLI; return 0
}

# Run a parser over every storcli controller, emitting {"controllers":[...]}.
# $1 = storcli arg template ('@' = controller index); $2 = parser path; $3+ = parser args.
# Requires $STORCLI resolved (use_storcli).
storcli_each() {
    local tmpl="$1" parser="$2"; shift 2
    local count c args
    count=$("$STORCLI" show 2>/dev/null | grep -m1 'Number of Controllers' | grep -oE '[0-9]+')
    if [ -z "$count" ] || [ "$count" -eq 0 ]; then
        echo '{"error":"No storcli controllers found."}'; return
    fi
    printf '{"controllers":['
    for c in $(seq 0 $((count - 1))); do
        [ "$c" -gt 0 ] && printf ','
        args="${tmpl//@/$c}"
        "$STORCLI" $args 2>/dev/null | bash "$parser" "$@"
    done
    printf ']}'
}
