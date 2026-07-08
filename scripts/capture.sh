#!/bin/bash
# Capture REAL lsiutil output from the Unraid box into test fixtures.
# The committed fixtures are seeded from documented formats; run this on the
# actual HBA to replace them with ground truth, then regenerate goldens:
#
#   bash scripts/capture.sh [PORT] [OUTDIR]
#   UPDATE=1 bash tests/run.sh     # re-bless expected/ from the new fixtures
#
# ponytail: seeded fixtures are a regression lock, not hardware-verified
# correctness — real capture is what validates the parsers against silicon.

PORT="${1:-1}"
OUT="${2:-tests/fixtures}"
LSIUTIL="/usr/local/emhttp/plugins/lsiutil/lsiutil.x86_64"

[ -x "$LSIUTIL" ] || { echo "lsiutil not found at $LSIUTIL — run on the Unraid box"; exit 1; }
mkdir -p "$OUT"

"$LSIUTIL" -p"$PORT" -a 25,2,0,0  > "$OUT/hba_ioc.txt"
printf '0\n' | "$LSIUTIL"         > "$OUT/hba_banner.txt"
"$LSIUTIL" -b                     > "$OUT/hba_board.txt"
"$LSIUTIL" -p"$PORT" -a 20,12,0,0 > "$OUT/phy_healthy.txt"
"$LSIUTIL" -p"$PORT" -a 42,0      > "$OUT/drives_lsiutil.txt"
"$LSIUTIL" -e -p"$PORT" -a 35,0   > "$OUT/events_entries.txt"

echo "Captured real output to $OUT/. Review, then: UPDATE=1 bash tests/run.sh"
