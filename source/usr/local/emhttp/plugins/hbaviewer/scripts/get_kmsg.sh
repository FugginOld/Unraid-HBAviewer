#!/bin/bash
# Composer: read /dev/kmsg NON-BLOCKING and hand it to the parser.
#
# iflag=nonblock is load-bearing. /dev/kmsg is a stream: a plain `cat` returns
# the buffer and then BLOCKS forever waiting for the next kernel message, which
# from a cron job is a process that never exits.
#
#   bash get_kmsg.sh [since_seq]
DIR="$(dirname "$0")"
dd if=/dev/kmsg iflag=nonblock bs=64k 2>/dev/null | bash "$DIR/parse/kmsg.sh" "${1:-0}"
