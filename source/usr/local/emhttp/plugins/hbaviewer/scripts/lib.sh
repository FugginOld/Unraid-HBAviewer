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

LSIUTIL="${LSIUTIL:-/usr/local/emhttp/plugins/hbaviewer/hbaviewer.x86_64}"

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

# Controller count from storcli's enumeration — the single parse of
# "Number of Controllers" that every storcli path shares. Empty if none.
storcli_count() {
    "$STORCLI" show 2>/dev/null | grep -m1 'Number of Controllers' | grep -oE '[0-9]+'
}

# Driver + version string for the loaded mpt driver. One detector for both
# backends. ponytail: mpt3sas first — a storcli box is SAS3 (mpt3sas); a SAS2
# lsiutil box loads only mpt2sas, so order can't misfire there.
hba_driver() {
    if   [ -r /sys/module/mpt3sas/version ]; then echo "mpt3sas $(cat /sys/module/mpt3sas/version)"
    elif [ -r /sys/module/mpt2sas/version ]; then echo "mpt2sas $(cat /sys/module/mpt2sas/version)"
    fi
}

# The backend seam. Chooses storcli-vs-lsiutil ONCE, owns controller
# enumeration and the {"backend","driver","controllers":[...]} wrapper, so a
# composer only declares *what to run per controller*.
#   $1 = storcli fn: `fn <c>` prints controller c's JSON object ($STORCLI
#        resolved+exported, count already > 0).
#   $2 = lsiutil fn: prints the inner controller object(s) on success, OR
#        prints a top-level error JSON and returns non-zero to abort the wrap.
hba_each() {
    local storcli_fn="$1" lsiutil_fn="$2" c count body rc
    if use_storcli; then
        count=$(storcli_count)
        printf '{"backend":"storcli","driver":"%s","controllers":[' "$(hba_driver)"
        for c in $(seq 0 $((count - 1))); do
            [ "$c" -gt 0 ] && printf ','
            "$storcli_fn" "$c"
        done
        printf ']}'
    else
        body=$("$lsiutil_fn"); rc=$?
        if [ "$rc" -ne 0 ]; then printf '%s' "$body"; return; fi
        printf '{"backend":"lsiutil","driver":"%s","controllers":[%s]}' "$(hba_driver)" "$body"
    fi
}
