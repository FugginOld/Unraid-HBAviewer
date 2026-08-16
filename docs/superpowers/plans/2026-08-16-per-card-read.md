# Per-Card Read Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the five hand-assembled per-card loops in the lsiutil tab composers with one `lsi_each_card` module, so each composer declares only the hardware query it differs by.

**Architecture:** `lsi_each_card` lives in `scripts/lib.sh` beside the backend module. It captures lsiutil's banner and `-b` board table once, enumerates ports, resolves each port's scsi host through the existing `lsi_host_for` join rule and that host's PCI dir, then calls a per-tab callback once per card and comma-joins whatever the callback prints. Each composer keeps a small callback holding only its own `hba_query` and its own interpretation of a failed join.

**Tech Stack:** Bash 5 (Slackware/Unraid), POSIX-ish shell in the composers, golden-file tests via `tests/run.sh`, self-asserting shell tests, shellcheck in CI.

**Spec:** `docs/superpowers/specs/2026-08-16-per-card-read-design.md`

## Global Constraints

- **Every file in `tests/expected/` must stay byte-identical.** This refactor changes no output. `bash tests/run.sh` must print `--- all pass ---` at the end of every task. If a golden changes, the refactor is wrong — do not regenerate it with `UPDATE=1`.
- Run from the repo root: `cd c:/Users/Joe/Documents/GitHub/Unraid-HBAviewer`.
- Full verification is `bash tests/run.sh` (shell goldens + self-asserting shell tests + PHP suite). It takes ~3 minutes. `bash tests/run_php.sh` alone is the PHP half.
- **shellcheck runs in CI and is not installed locally.** It fails the build on `-S warning`. Two rules bite this codebase: SC2218 (calling a function defined later in the file — a stub defined below its call sites triggers it; define stubs through `eval` to hide them) and unquoted expansions. Read `tests/multiport_test.sh:184-188` for the established `eval` workaround before adding any test stub.
- Do not touch `hba_each`, `_host_for_pci`, `hba_card_id`, `hba_topology`, or anything under `scripts/parse/`. They are already deep; the spec lists them as preserve-as-is.
- `lsi_ports` keeps its current name and one-argument signature — `scripts/bundle_support.sh:389` calls it.
- Commit after every task. Message style in this repo: a sentence saying what changed and why, no `feat:`/`chore:` prefixes.

---

### Task 1: `lsi_each_card` in the backend module

Adds the module with no callers. Nothing changes on screen; the suite must stay green because nothing calls it yet.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh` (append after `lsi_host_for`, which ends at line 255)
- Test: `tests/multiport_test.sh` (append before the final `echo` / pass-fail block at the end of the file)

**Interfaces:**
- Consumes: `lsi_ports`, `lsi_host_for`, `_pci_dir_of_host`, `hba_query` — all already in `lib.sh`.
- Produces: `lsi_each_card CALLBACK`, which invokes `CALLBACK PORT BANNER BOARD HNUM PDIR NPORTS` once per card and prints `,` between cards. `BANNER` and `BOARD` are paths to temp files that exist for the duration of the call. `HNUM` is `""` when the join failed on a multi-card box. Tasks 2–5 all consume this.

- [ ] **Step 1: Write the failing test**

Append to `tests/multiport_test.sh`, immediately before the final `echo` and `[ $fail -eq 0 ]` block:

```bash
# ── lsi_each_card ───────────────────────────────────────────────────────────
# The per-card read: banner and board captured once, ports enumerated, each
# port joined to its own host, callback called per card, output comma-joined.
# Stubs are defined through eval so shellcheck does not see a definition below
# the call sites the real functions serve above (SC2218).
eval "$(sed -n '/^lsi_each_card()/,/^}/p' "$SRC")"
eval 'hba_query() {
    case "$1" in
        -b) cat fixtures/lsiutil_multi/3card/board.txt ;;
        *)  cat fixtures/lsiutil_multi/3card/banner.txt ;;
    esac
}'
eval '_pci_dir_of_host() {
    case "$1" in
        1) printf "/sys/devices/pci0000:80/0000:80:01.0/0000:81:00.0" ;;
        2) printf "/sys/devices/pci0000:80/0000:80:03.0/0000:82:00.0" ;;
        3) printf "/sys/devices/pci0000:80/0000:80:03.2/0000:83:00.0" ;;
    esac
}'
# Echoes every argument it was handed, so a wrong argument ORDER fails loudly
# rather than passing because two fields happen to look alike. It reads the
# CONTENT of the two files, not just their names: both are equally "one unique
# path per run", so a swapped BANNER/BOARD pair is invisible to a name check and
# would surface as a misparse in parse/hba.sh three tasks later instead.
_probe_card() { printf "p=%s hnum=%s pdir=%s n=%s banner=%s board=%s bkind=%s dkind=%s" \
    "$1" "$4" "$5" "$6" "$(basename "$2")" "$(basename "$3")" \
    "$(grep -q 'Chip Vendor' "$2" && echo banner || echo WRONG)" \
    "$(grep -q 'Board Assembly' "$3" && echo board || echo WRONG)"; }

EACH=$(lsi_each_card _probe_card)
eq "each: one entry per card"      "3"   "$(grep -o 'p=' <<<"$EACH" | wc -l | tr -d ' ')"
eq "each: comma-joined"            "2"   "$(grep -o ',p=' <<<"$EACH" | wc -l | tr -d ' ')"
eq "each: ports in banner order"   "1 2 3" \
   "$(grep -oE 'p=[0-9]+' <<<"$EACH" | cut -d= -f2 | tr '\n' ' ' | sed 's/ $//')"
eq "each: each card its own host"  "1 2 3" \
   "$(grep -oE 'hnum=[0-9]*' <<<"$EACH" | cut -d= -f2 | tr '\n' ' ' | sed 's/ $//')"
eq "each: each card its own pci dir" "0000:81:00.0 0000:82:00.0 0000:83:00.0" \
   "$(grep -oE 'pdir=[^ ]*' <<<"$EACH" | cut -d= -f2 | xargs -n1 basename | tr '\n' ' ' | sed 's/ $//')"
eq "each: port count passed through" "3 3 3" \
   "$(grep -oE 'n=[0-9]+' <<<"$EACH" | cut -d= -f2 | tr '\n' ' ' | sed 's/ $//')"
# The banner and the board table list every port in one call, so they are
# captured ONCE and handed to every card -- health read the banner twice per
# request before this existed.
# [^, ] not [^ ]: cards are joined with a bare comma, and board= is the last
# field of a record, so a space-only class runs straight into the next card.
eq "each: same banner file for every card" "1" \
   "$(grep -oE 'banner=[^, ]*' <<<"$EACH" | sort -u | wc -l | tr -d ' ')"
eq "each: same board file for every card"  "1" \
   "$(grep -oE 'board=[^, ]*' <<<"$EACH" | sort -u | wc -l | tr -d ' ')"
# ...and that each file went into the RIGHT position. Uniqueness alone cannot
# tell a swapped pair apart; content can.
eq "each: the banner position holds the banner" "banner banner banner" \
   "$(grep -oE 'bkind=[^, ]*' <<<"$EACH" | cut -d= -f2 | tr '\n' ' ' | sed 's/ $//')"
eq "each: the board position holds the board"   "board board board" \
   "$(grep -oE 'dkind=[^, ]*' <<<"$EACH" | cut -d= -f2 | tr '\n' ' ' | sed 's/ $//')"
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
bash tests/multiport_test.sh
```

Expected: FAIL — `lsi_each_card` not found in `lib.sh`, so the `eval` at the top of the block defines nothing and every `each:` assertion reports an empty `got`.

- [ ] **Step 3: Write the implementation**

Append to `source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh`, after `lsi_host_for`:

```bash
# Every lsiutil card, joined and ready to read. Captures the banner and the -b
# board table ONCE (both list every port in a single call), enumerates the
# ports, resolves each port's scsi host through the one join rule and that
# host's PCI dir, calls $1 per card and comma-joins what it printed.
#
#   lsi_each_card CALLBACK
#   CALLBACK PORT BANNER BOARD HNUM PDIR NPORTS
#
# Plan 059 taught five composers this loop and each of them assembled it from
# lib.sh's primitives differently: five emit loops, three banner captures, two
# spellings of the port count, and eleven mktemps with three traps between them.
# This is that loop, once.
#
# HNUM is EMPTY when the join failed on a multi-card box -- deliberately, since
# handing a card its neighbour's host is the bug issue #18 was filed about. What
# a tab does with that is the tab's business and differs for good reasons: the
# overview reports an unknown topology (which suppresses the firmware verdict),
# health falls back to host 0 on a single-card box (which its goldens pin), and
# attached-drives reports nothing rather than sweeping sysfs box-wide.
lsi_each_card() {   # $1 = callback name
    local BANNER BOARD ports nports p row bus dev hnum pdir first=1
    BANNER=$(mktemp); BOARD=$(mktemp)
    printf '0\n' | hba_query 2>/dev/null > "$BANNER"
    hba_query -b             2>/dev/null > "$BOARD"
    ports=$(lsi_ports "$BANNER")
    nports=$(echo $ports | wc -w | tr -d ' ')   # unquoted: count the tokens
    for p in $ports; do
        row=$(grep "ioc" "$BOARD" | sed -n "${p}p")
        bus=$(echo "$row" | awk '{print $3}')
        dev=$(echo "$row" | awk '{print $4}')
        hnum=$(lsi_host_for "$bus" "$dev" "$nports")
        pdir=$([ -n "$hnum" ] && _pci_dir_of_host "$hnum")
        [ "$first" = 1 ] || printf ','
        first=0
        "$1" "$p" "$BANNER" "$BOARD" "$hnum" "$pdir" "$nports"
    done
    rm -f "$BANNER" "$BOARD"
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
bash tests/multiport_test.sh
```

Expected: `multiport: all pass`, including the eight new `each:` lines.

- [ ] **Step 5: Verify nothing else moved**

```bash
bash tests/run.sh 2>&1 | grep -E "^FAIL|FAILURES|^---"
```

Expected: `--- all pass ---` and nothing else. No composer calls `lsi_each_card` yet, so every golden must be untouched.

- [ ] **Step 6: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh tests/multiport_test.sh docs/superpowers/specs/2026-08-16-per-card-read-design.md docs/superpowers/plans/2026-08-16-per-card-read.md
git commit -m "Add the per-card read, the loop plan 059 taught five composers

lsi_each_card captures lsiutil's banner and board table once, enumerates the
ports, joins each to its own scsi host and PCI dir, and comma-joins what a
callback prints per card. No caller yet -- the five composers move onto it one
at a time, with the goldens unchanged at every step."
```

---

### Task 2: Move the PHY and event-log composers onto it

The two smallest loops, identical in shape. Doing them together keeps the diff readable and proves the interface on the easy case before the hard ones.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_phy_health.sh:30-40`
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_event_log.sh:10-20`

**Interfaces:**
- Consumes: `lsi_each_card` from Task 1.
- Produces: nothing new. `phy_lsiutil` and `ev_lsiutil` keep their names, because `hba_each` calls them by name at the bottom of each file.

- [ ] **Step 1: Replace the PHY loop**

In `get_phy_health.sh`, replace the whole `phy_lsiutil` function:

```bash
phy_lsiutil() {
    require_binary || return 1
    lsi_each_card _phy_one
}
_phy_one() {   # $1 = port; the rest of lsi_each_card's context is unused here
    hba_query -p"$1" -a 20,12,0,0 2>/dev/null | bash "$DIR/parse/phy.sh"
}
```

- [ ] **Step 2: Replace the event-log loop**

In `get_event_log.sh`, replace the whole `ev_lsiutil` function:

```bash
ev_lsiutil() {
    require_binary || return 1
    lsi_each_card _ev_one
}
_ev_one() {   # $1 = port; the rest of lsi_each_card's context is unused here
    hba_query -e -p"$1" -a 35,0 2>/dev/null | bash "$DIR/parse/events.sh"
}
```

- [ ] **Step 3: Run the full suite**

```bash
bash tests/run.sh 2>&1 | grep -E "^FAIL|FAILURES|^---"
```

Expected: `--- all pass ---`. `phy-route`, `events-route` and `events-lsiutil` are the goldens that cover these two paths; all three must be byte-identical.

- [ ] **Step 4: Prove the loop is really gone**

```bash
grep -c "first=1" source/usr/local/emhttp/plugins/hbaviewer/scripts/get_phy_health.sh \
                  source/usr/local/emhttp/plugins/hbaviewer/scripts/get_event_log.sh
```

Expected: `0` for both files.

- [ ] **Step 5: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/get_phy_health.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/get_event_log.sh
git commit -m "Read PHY counters and the event log through the per-card read

Both composers were the same eleven lines: enumerate ports, loop, comma-join,
run one query. They are now the query and nothing else."
```

---

### Task 3: Move the overview composer onto it

`ov_lsiutil` is the loop the other four were copied from, and the only one that re-derives the board row that `lsi_port_map` already computes.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh:159-196`

**Interfaces:**
- Consumes: `lsi_each_card` from Task 1.
- Produces: nothing new. `ov_lsiutil` keeps its name for `hba_each`.

- [ ] **Step 1: Replace the loop, keeping the refusal gates above it untouched**

In `get_hba_info.sh`, the `ov_lsiutil` function currently ends with `require_binary || return 1` followed by the loop. Leave every line above `require_binary` exactly as it is — those are the mpt3sas and SAS4 refusal gates and they are not part of this change. Replace from `require_binary` to the closing brace with:

```bash
    require_binary || return 1
    lsi_each_card _ov_one
}
_ov_one() {   # $1 port  $2 banner  $3 board  $4 hnum  $5 pdir  $6 nports
    local IOC IDENT
    IOC=$(mktemp); IDENT=$(mktemp)
    hba_query -p"$1" -a 25,2,0,0 2>/dev/null > "$IOC"
    # Main-menu option 1 = "Identify firmware, BIOS, and/or FCode". Plain menu
    # item, NOT expert mode, so no -e. Read-only: it reports what is flashed.
    hba_query -p"$1" -a 1,0      2>/dev/null > "$IDENT"
    # An unresolved host reads "unknown", which suppresses the firmware verdict.
    # A WRONG topology would be worse than none: it is what gates the multipath
    # suppression, and acting on a false BEHIND destroys a working config.
    LSI_TOPOLOGY=$([ -n "$4" ] && hba_topology "$4" || printf 'unknown')
    LSI_SUBVENDOR=$([ -n "$5" ] && hba_subvendor "$5")
    # The slot, for grouping the two IOCs of a dual-controller board.
    LSI_CARD_ID=$([ -n "$5" ] && hba_card_id "$5")
    export LSI_TOPOLOGY LSI_SUBVENDOR LSI_CARD_ID
    bash "$DIR/parse/hba.sh" "$IOC" "$2" "$3" "$ALERT" "$IDENT" "$1"
    rm -f "$IOC" "$IDENT"
}
```

- [ ] **Step 2: Run the full suite**

```bash
bash tests/run.sh 2>&1 | grep -E "^FAIL|FAILURES|^---"
```

Expected: `--- all pass ---`. `route-lsiutil` is the golden that reaches this function's tail — it pins `topology`, `subvendor_id`, `card_id` and `port`, so it fails loudly if the argument order is wrong.

- [ ] **Step 3: Update the test that reaches past the interface**

`tests/multiport_test.sh` extracts `ov_lsiutil` by `sed` and evals it. It now needs `_ov_one` and `lsi_each_card` too. Find the line reading:

```bash
eval "$(sed -n '/^ov_lsiutil()/,/^}/p' "$DIR/get_hba_info.sh")"
```

and replace it with:

```bash
eval "$(sed -n '/^ov_lsiutil()/,/^}/p' "$DIR/get_hba_info.sh"
        sed -n '/^_ov_one()/,/^}/p'    "$DIR/get_hba_info.sh"
        sed -n '/^lsi_each_card()/,/^}/p' "$SRC")"
```

- [ ] **Step 4: Run the multiport test**

```bash
bash tests/multiport_test.sh
```

Expected: `multiport: all pass` — the `loop:` assertions (three controllers, own temperature, own card_id, own board row, failed join yields no card_id) all still pass, now exercising the shared module.

- [ ] **Step 5: Verify the duplicated board-row parse is gone**

```bash
grep -c 'awk .{print \$3}' source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh
```

Expected: `0`. That parse now exists only inside `lsi_each_card`.

- [ ] **Step 6: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh tests/multiport_test.sh
git commit -m "Read the Overview through the per-card read

ov_lsiutil was the loop the other four were copied from, and the only one that
re-derived the board row lsi_port_map already computes. It is now the three
queries it differs by, plus the choice every tab makes for itself: what an
unresolved host means. Here it means an unknown topology, which suppresses the
firmware verdict rather than risking a wrong one."
```

---

### Task 4: Make the health composer's clock injectable, and give it a golden

`get_hba_health.sh` is the only tab composer with no golden coverage. That is not an oversight — `NOW=$(date +%s)` and `UPTIME` from `/proc/uptime` make byte-exact output impossible. This task fixes the cause, then adds the golden that the next task's refactor will be checked against.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_health.sh:87-88`
- Modify: `tests/run.sh` (add a check beside the existing `route-lsiutil` block, which is around line 279)
- Create: `tests/expected/health_lsiutil.json`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `LSI_NOW` and `LSI_UPTIME` environment overrides, used by Task 5's verification.

- [ ] **Step 1: Make the two clock reads injectable**

In `get_hba_health.sh`, replace lines 87–88:

```bash
UPTIME=$(cut -d. -f1 /proc/uptime 2>/dev/null); UPTIME="${UPTIME:-0}"
NOW=$(date +%s)
```

with:

```bash
# Overridable so the composer has a byte-stable output to pin. Without this the
# health tab is the one composer with no golden, because a wall clock and an
# uptime cannot appear in an expectation file. Production passes neither.
UPTIME="${LSI_UPTIME:-$(cut -d. -f1 /proc/uptime 2>/dev/null)}"; UPTIME="${UPTIME:-0}"
NOW="${LSI_NOW:-$(date +%s)}"
```

- [ ] **Step 2: Check the override works before writing any expectation**

```bash
cd tests && LSI_NOW=1000 LSI_UPTIME=500 STORCLI= LSIUTIL="$PWD/stub/lsiutil" \
  STUB_FIX="$PWD/fixtures" SYS_SCSI_HOST=/nonexistent \
  bash ../source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_health.sh | head -c 200; cd ..
```

Expected: JSON beginning `{"backend":"lsiutil",...` containing `"t":1000` and `"uptime":500`. If it shows a real timestamp instead, the override is not wired.

- [ ] **Step 3: Add the golden check**

In `tests/run.sh`, immediately after the existing `check route-lsiutil ...` line, add:

```bash
# The health composer, all the way through. It had no golden until its clock
# became injectable: a wall clock and an uptime cannot live in an expectation.
# LSI_NOW/LSI_UPTIME are test-only; production passes neither.
LSI_NOW=1000 LSI_UPTIME=500 \
STORCLI= LSIUTIL="$PWD/stub/lsiutil" SYS_SCSI_HOST="$LCARD/host3/scsi_host" STUB_FIX="$PWD/fixtures" \
check health-lsiutil   health_lsiutil.json   bash "$P/../get_hba_health.sh"
```

- [ ] **Step 4: Generate the expectation, then read it before trusting it**

```bash
cd tests && UPDATE=1 bash run.sh >/dev/null 2>&1; cd ..
cat tests/expected/health_lsiutil.json
```

Expected: one controller object with `"t":1000`, `"uptime":500`, a `temp` matching the `hba_ioc.txt` fixture, `"read_ok":true`, and a `link` object. **Read it.** A golden generated from the code under test is only worth what you checked — confirm the temperature matches the fixture and that nothing reads as a live timestamp before committing it.

- [ ] **Step 5: Run the full suite**

```bash
bash tests/run.sh 2>&1 | grep -E "^FAIL|FAILURES|^---"
```

Expected: `--- all pass ---`, now including `health-lsiutil`. `UPDATE=1` in step 4 rewrites **every** golden — confirm `git status` shows only `tests/expected/health_lsiutil.json` as new and no other expectation modified. If any other golden changed, revert it: that is a real regression the update just masked.

```bash
git status --short tests/expected/
```

Expected: exactly one line, `?? tests/expected/health_lsiutil.json`.

- [ ] **Step 6: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_health.sh tests/run.sh tests/expected/health_lsiutil.json
git commit -m "Give the health composer a clock it can be tested against

get_hba_health.sh was the one tab composer with no golden, because a wall clock
and an uptime cannot appear in an expectation file. Both are overridable now, so
the composer that feeds the dashboard tile is pinned end to end like every other
one. Production passes neither variable."
```

---

### Task 5: Move the health and attached-drives composers onto it

The last two, and the two whose failed-join interpretation differs. Both are now covered by goldens, so the refactor is checked rather than reasoned about.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_health.sh:148-165` and `:208-232`
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_attached_drives.sh:29-56`

**Interfaces:**
- Consumes: `lsi_each_card` from Task 1, the `LSI_NOW`/`LSI_UPTIME` overrides and the `health-lsiutil` golden from Task 4.
- Produces: nothing new. `health_lsiutil` and `drv_lsiutil` keep their names for `hba_each`.

- [ ] **Step 1: Replace the health loop**

In `get_hba_health.sh`, replace the whole `health_lsiutil` function:

```bash
health_lsiutil() {
    require_binary || return 1
    # The dashboard tile reads this, and on a multi-card box it read card 1's
    # temperature for every card -- the symptom issue #18 was filed about.
    lsi_each_card _health_lsiutil_one
}
```

- [ ] **Step 2: Re-sign the health per-card function**

Still in `get_hba_health.sh`, change the signature line of `_health_lsiutil_one` and the two places inside it that used the old arguments.

The signature comment and first line become:

```bash
_health_lsiutil_one() {   # $1 port  $2 banner  $3 board  $4 hnum  $5 pdir  $6 nports
    local IOC BANNER temp_hex temp fw_raw fw band readok=true
```

The `BANNER="$5"` assignment becomes `BANNER="$2"`.

The join block — currently `hnum=$(lsi_host_for "$2" "$3" "$4")` followed by the single-port fallback — becomes:

```bash
    # This port's own card, already joined by lsi_each_card. One card and no
    # join: keep the historic host-0 default, which the goldens pin. More than
    # one card, and empty means empty -- zero drives and no PHYs beats another
    # card's.
    hnum="$4"
    [ -z "$hnum" ] && [ "$6" = "1" ] && hnum=0
```

Every other line of the function is unchanged, including the `printf` and the trailing `rm -f "$IOC"`.

- [ ] **Step 3: Run the health golden**

```bash
bash tests/run.sh 2>&1 | grep -E "^FAIL|FAILURES|health-lsiutil|^---"
```

Expected: `PASS  health-lsiutil` and `--- all pass ---`. This is the golden Task 4 added; it is the check that the argument re-signing is right.

- [ ] **Step 4: Replace the drives loop**

In `get_attached_drives.sh`, replace the whole `drv_lsiutil` function:

```bash
drv_lsiutil() {
    require_binary || return 1
    # One entry per card, in lsi_ports order, so the index join in ajax_info.php
    # lines up with the Overview's controllers[] (issue #18).
    lsi_each_card _drv_lsiutil_one
}
```

- [ ] **Step 5: Re-sign the drives per-card function**

Still in `get_attached_drives.sh`, change `_drv_lsiutil_one`'s signature and its first two lines from the old `(port, hnum, nports)` to the module's context:

```bash
_drv_lsiutil_one() {   # $1 port  $2 banner  $3 board  $4 hnum  $5 pdir  $6 nports
    local TMPOS TMPSAS hnum="$4"
    TMPOS=$(mktemp); TMPSAS=$(mktemp)
```

and the early-return guard's port-count test from `"$3"` to `"$6"`:

```bash
    if [ -z "$hnum" ] && [ "$6" != "1" ]; then
```

The rest of the function — the three sysfs stages and the `drives_join.sh` call — is unchanged. Its `hba_query -p"$1" -a 42,0` already reads the port from `$1`, which is still the port.

- [ ] **Step 6: Run the full suite**

```bash
bash tests/run.sh 2>&1 | grep -E "^FAIL|FAILURES|^---"
```

Expected: `--- all pass ---`. `drives-route` and the `drives:` assertions in `multiport_test.sh` (card 1 lists only host1's disk, card 2 only host2's) cover the per-card filter that step 5 just re-wired.

- [ ] **Step 7: Update the tests that reach past the interface**

`tests/multiport_test.sh` extracts `health_lsiutil`/`_health_lsiutil_one` and `drv_lsiutil`/`_drv_lsiutil_one`, and `tests/health_sh_test.sh` and `tests/drives_sysfs_test.sh` extract and stub them too. All four extraction sites now need `lsi_each_card` in scope, and their `lsi_port_map` stubs no longer do anything.

In `tests/multiport_test.sh`, both the health block and the drives block need `lsi_each_card` added to their `sed` extraction, exactly as Task 3 did for `ov_lsiutil`:

```bash
eval "$(sed -n '/^health_lsiutil()/,/^}/p'      "$HSRC"
        sed -n '/^_health_lsiutil_one()/,/^}/p' "$HSRC"
        sed -n '/^lsi_each_card()/,/^}/p'       "$SRC")"
```

and their `lsi_port_map() { ...; }` stubs become `lsi_ports` + `hba_query` stubs, because `lsi_each_card` calls those instead:

```bash
eval 'lsi_ports() { printf "1\n2\n3\n"; }'
```

In `tests/health_sh_test.sh` and `tests/drives_sysfs_test.sh`, the single-card stubs `lsi_port_map() { printf ...; }` become `eval 'lsi_ports() { printf "1\n"; }'`, and both files add `lsi_each_card` to their extraction.

- [ ] **Step 8: Run the full suite**

```bash
bash tests/run.sh 2>&1 | grep -E "^FAIL|FAILURES|^---"
```

Expected: `--- all pass ---`.

- [ ] **Step 9: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_health.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/get_attached_drives.sh tests/multiport_test.sh tests/health_sh_test.sh tests/drives_sysfs_test.sh
git commit -m "Read health and attached drives through the per-card read

The last two composers, and the two whose reading of a failed join differs:
health keeps the host-0 default on a single-card box because its goldens pin it,
attached-drives reports nothing on a multi-card box because its sysfs sweep is
box-wide and would otherwise hand a card its neighbours' disks. Both keep that
choice; neither keeps a loop.

Health was also reading the hardware banner twice per request -- once itself and
once inside lsi_port_map. Now once."
```

---

### Task 6: Retire what the module replaced

`lsi_port_map` existed to give the composers the port/bus/dev triple. `lsi_each_card` consumes those columns itself, so nothing calls it any more.

**Files:**
- Modify: `source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh` (delete `lsi_port_map`)
- Modify: `docs/superpowers/specs/2026-08-16-per-card-read-design.md` (record the outcome)

**Interfaces:**
- Consumes: everything from Tasks 1–5.
- Produces: nothing.

- [ ] **Step 1: Prove nothing calls it**

```bash
grep -rn "lsi_port_map" source/ tests/ --include=*.sh | grep -v "^source.*lib.sh:.*lsi_port_map() {"
```

Expected: no output, or comment-only hits. If a real call site appears, that composer was missed in Tasks 2–5 — go back and finish it rather than keeping the function.

- [ ] **Step 2: Delete `lsi_port_map`**

Remove the whole function from `lib.sh`, including its comment block (the one beginning "Every port with the PCI bus and device lsiutil's own `-b` table gives it"). Leave `lsi_ports` — `scripts/bundle_support.sh:389` calls it.

- [ ] **Step 3: Run the full suite**

```bash
bash tests/run.sh 2>&1 | grep -E "^FAIL|FAILURES|^---"
```

Expected: `--- all pass ---`.

- [ ] **Step 4: Count what the refactor actually removed**

```bash
git diff --stat main -- source/usr/local/emhttp/plugins/hbaviewer/scripts/
grep -c "first=1" source/usr/local/emhttp/plugins/hbaviewer/scripts/get_*.sh
```

Expected: the five `get_*.sh` files all report `0` for `first=1`. Record the net line change in the next step.

- [ ] **Step 5: Record the outcome in the spec**

Append to `docs/superpowers/specs/2026-08-16-per-card-read-design.md`:

```markdown
## Outcome

Shipped. The five lsiutil composers now declare only their own `hba_query` and
their own reading of a failed join. `lsi_port_map` is gone — `lsi_each_card`
consumes the board columns itself. `lsi_ports` stays for `bundle_support.sh`.

`get_hba_health.sh` gained the composer-level golden it never had, once
`LSI_NOW` and `LSI_UPTIME` made its output byte-stable.

Still true, and deliberately: the SAS2/lsiutil path has no hardware coverage.
Everything here is fixture- and stub-tested. The goldens were byte-identical at
every step, which is the whole reason this refactor was safe to make.
```

- [ ] **Step 6: Commit**

```bash
git add source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh docs/superpowers/specs/2026-08-16-per-card-read-design.md
git commit -m "Retire lsi_port_map, which the per-card read replaced

It existed to hand composers the port/bus/dev triple; lsi_each_card reads those
columns itself. lsi_ports stays -- bundle_support.sh calls it."
```

---

## Self-review notes

Checked against the spec, 2026-08-16:

- **Spec coverage.** Banner/board captured once → Task 1. Five emit loops → Tasks 2, 3, 5. Two port-count spellings → Task 1 (one spelling). Board-row parse duplicated → Task 3 removes the second copy, Task 6 removes the third. Health's double banner read → Task 5. `mktemp` ownership → Tasks 1, 3 (the module owns banner/board; callbacks own their own query files and delete them). Missing health golden → Task 4. Sed-extraction sites → Tasks 3, 5 update them; they are not deleted, because deleting them deletes coverage the goldens do not replace (per-card `card_id`, the drives filter).
- **Type consistency.** The callback contract `PORT BANNER BOARD HNUM PDIR NPORTS` is defined in Task 1 and used with those positions in Tasks 2 (`$1`), 3 (`$1 $2 $3 $4 $5`), and 5 (`$1 $2 $4 $6`). No task references a helper another task did not define.
- **Known gap, deliberately not fixed here.** `_link_from_sysfs` (`get_hba_health.sh:101`) still returns six values by writing into the caller's locals via dynamic scoping, which is invisible at the call site and testable only by extraction. It is orthogonal to the loop and would double this plan's size; it belongs in its own plan.
- **Not attempted.** The four other candidates from the 2026-08-16 architecture review — the bay map's second rendering engine, the `ajax_info.php` split, the backend seam's PHP-side key-sniffs, and the `ALERT_THRESHOLD` 76/80 split. The last of those is a live defect and is worth doing sooner than the refactors.
