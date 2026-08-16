# 059 — The lsiutil backend reads one card out of N (issue #18)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**:
> `git diff --stat 67aed36..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_health.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/get_phy_health.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/get_event_log.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/get_attached_drives.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/hba.sh tests/run.sh`
> Expected output: **nothing**. Every excerpt below is quoted from `67aed36`
> (`dev` tip, 2026-08-13, dual-IOC grouping already merged). Any difference is
> a STOP condition.
>
> **Worktree note**: a fresh worktree may be cut from `main`, not `dev` — a trap
> that has cost four plans now (049, 050, 052, 053). `git switch dev` also *fails*
> inside a worktree, because `dev` is checked out in the main tree. Run
> `git log --oneline -1`, then use the one command in "Git workflow" below,
> which lands on the right base either way.

## Status

All six steps shipped on `advisor/059-multi-card-lsiutil`; nothing is
hardware-verified past the port→host join (see below). Raised by issue #18
(three SAS2308 cards, only one ever shown) and confirmed by a second reporter in
the same thread (two 9207-8i, second card has no temperature). P2 — nothing is
at risk and nothing is wrong on a single-card box, but on a multi-card SAS2 box
the plugin silently monitors one card and gives no sign the others exist beyond
the Detected Hardware line, which already names them.

**Fixture obtained** (`tests/fixtures/lsiutil_multi/`, real captures from both
reporters), and **the port→host join is now hardware-verified**: brianara3 ran
the Step 1–2 logic on his 3-card box on 2026-08-16 and every port resolved to
its own card —

```
port 1  SAS9207-8i  53 C  81:00.0  host0 (SAS9207-8i)
port 2  SAS9207-8i  61 C  82:00.0  host1 (SAS9207-8i)
port 3  SAS9207-8i  59 C  83:00.0  host2 (SAS9207-8i)
```

That clears STOP conditions 2, 3 and 5: `-p<n>` accepts the banner numbering for
all three ports, each returns its own temperature, the decimal `129/130/131`
bus values convert to the sysfs addresses `81/82/83`, and the port count equals
the SAS2 host count with no dead tile.

**What is still unverified**: everything downstream of that join. The five
composers, the per-card firmware row, the per-card drive/PHY attribution and the
card labels are covered by fixtures and stubs only — no multi-card box has run
this code. The diagnostic bundle now captures every port (a bundle from a
multi-card box on this branch is therefore the verification), which it did not
when this plan was written; that single-port capture is why the issue took three
rounds of hand-written command blocks and why `lsiutil_multi/` holds one raw IOC
capture per box.

## Why this matters

`Detected Hardware` already reads both cards, because it comes from sysfs
`board_name` — the reporter's own paste says:

```
LSI2308-IT (mpt2sas, fw 20.00.07.00) · SAS9207-8i (mpt2sas, fw 20.00.07.00)
```

So the UI states there are two cards and then shows telemetry for one of them.
That is the worst version of this bug: not "unsupported", but a display that
looks complete. A user watching the dashboard tile for a thermal problem is
watching card 1 while card 2 cooks. The reporter is already working around it
with a hand-rolled shell loop over `-p1` / `-p2` — which is exactly the fix,
one level down.

## The one thing that makes this cheap

**The front-end is already N-controller, on every tab.** The storcli path has
emitted many controllers since the beginning and the dual-IOC grouping work
(merged at `282d2d2`) hardened it further. Nothing in PHP or JS needs to change:

- `dashboard.php:141` — `foreach ($controllers as $i => $c)`, one tile each.
- `ajax_info.php:181-220` — `lsi_controllers($data)` normalises flat vs array.
- `card_group.php` — groups by `card_id`; two *separate* cards have different
  `card_id`s and correctly stay separate.
- `export.php`, `bay_map.php` — already iterate `controllers`.

The `controllers[]` array on the lsiutil backend is real, it is just always
length 1. **This plan only makes the shell fill it.**

Second cheap thing: **the port list is already captured.** `ov_lsiutil` runs
`printf '0\n' | hba_query` for the banner, and the banner *is* lsiutil's port
table. `tests/fixtures/hba_banner.txt`:

```
LSI Logic MPT Configuration Utility, Version 1.70

 1.  ioc0   LSI Logic SAS2308    14000700     b0

Select a device:  [1-1 or 0 to quit]
```

On a two-card box that grows a `2.  ioc1 …` row and the prompt reads `[1-2 …]`.
No new hardware call is needed to enumerate.

## Current state

### `config.sh` — one port, for everyone

```bash
PORT="${HBA_PORT:-1}"
ALERT="${ALERT_THRESHOLD:-80}"
```

Five composers source this and every one of them passes `-p"$PORT"`:

| File | Call |
|------|------|
| `get_hba_info.sh:139,144` | `-p"$PORT" -a 25,2,0,0`, `-p"$PORT" -a 1,0` |
| `get_hba_health.sh:155` | `-p"$PORT" -a 25,2,0,0` |
| `get_phy_health.sh:32` | `-p"$PORT" -a 20,12,0,0` |
| `get_event_log.sh:12` | `-e -p"$PORT" -a 35,0` |
| `get_attached_drives.sh:41` | `-p"$PORT" -a 42,0` |

### `lib.sh:157-172` — the seam already allows N

```bash
hba_each() {
    local storcli_fn="$1" lsiutil_fn="$2" c count body rc
    if use_storcli; then
        ...
    else
        body=$("$lsiutil_fn"); rc=$?
        if [ "$rc" -ne 0 ]; then printf '%s' "$body"; return; fi
        printf '{"backend":"lsiutil","driver":"%s","controllers":[%s]}' "$(hba_driver)" "$body"
    fi
}
```

The contract is already *"prints the inner controller object(s)"* — plural. A
lsiutil fn that prints two comma-joined objects needs no change here.

### `lib.sh:192-199` — the assumption to delete

```bash
# First SAS host (mpt2sas/mpt3sas/mptsas) — same personality filter as
# hba_personalities above, but keeping the host NUMBER, needed to key
# _phys_json. The bundled lsiutil binary only ever addresses one controller.
_first_sas_host() {
```

That comment is the bug, written down. `_first_sas_host` is what makes the
health tab, the PHY table and the drive count all describe card 1.

### `parse/hba.sh:70` — takes the first banner row

```bash
CARD_LINE=$(echo "$BANNER" | grep -E "^\s+[0-9]+\.\s+ioc" | head -1)
```

and `parse/hba.sh:104-107` the first board row:

```bash
BOARD_LINE=$(echo "$BOARD" | grep "ioc" | head -1)
BOARD_NAME=$(echo "$BOARD_LINE" | awk '{print $5}')
PCI_BUS=$(echo "$BOARD_LINE"    | awk '{print $3}')
PCI_DEV=$(echo "$BOARD_LINE"    | awk '{print $4}')
```

**`PCI_BUS`/`PCI_DEV` are the join key this plan needs** — they are already
parsed and already displayed, they are simply never used to find the card's
sysfs directory.

### `ajax_info.php:820` — why partial looping is worse than none

```php
$ctlDrives = $drives['controllers'][$i]['drives'] ?? [];
```

Controllers are joined **by array index** across the info / health / drives /
phys payloads. If Overview returns 2 entries and drives returns 1, card 2's
table shows nothing and card 1 shows *both* cards' drives (the sysfs sweep in
`drv_lsiutil` Stage 2 walks every `end_device-*` on the box, not just this
card's). Half a fix mislabels hardware. Steps 2-5 are one unit; do not ship
Step 2 alone.

## STOP conditions

1. The drift check prints anything.
2. The reporter's bundle shows a banner whose port rows are **not** numbered
   `1.`, `2.`, … in a way that `-p<n>` accepts (Step 0 verifies this).
3. `hba_query -p<n> -b` turns out not to accept `-p` (Step 0), or its bus/device
   columns do not match the sysfs PCI address of the same card. Both are
   assumptions this plan rests on and both are visible in the bundle.
4. Any existing `check hba-*` line in `tests/run.sh` changes output. Every
   single-card expectation must stay **byte-identical**; that is the regression
   guard for the whole plan.
5. `lsi_ports` returns more ports than the box has SAS2 hosts in sysfs — that
   means the row parse is matching something else, and every extra "card" would
   render as a dead tile.

## Step 0 — get the fixture (blocking, free)

The plugin already has a diagnostic-bundle button, and both reporters on #18 are
responsive. Ask on the issue for a bundle from the multi-card box. Needed from
it, and nothing else:

- `lsiutil_banner.txt` — the port table with N rows.
- `lsiutil_board.txt` — `-b` output on a multi-card box (does it list every ioc,
  or only one?), plus `-p2 -b` if the reporter will run it.
- `hba_ioc.txt` per port (`-p1` and `-p2 -a 25,2,0,0`).
- The sysfs PCI addresses of both cards (`ls -l /sys/class/scsi_host/host*`).

Store as `tests/fixtures/lsiutil_dual/` (mirrors the existing
`tests/fixtures/storcli_dual/` precedent).

**If the bundle never arrives**: build `lsiutil_dual/` by hand from the existing
single-card fixtures — duplicate the banner row as `2.  ioc1 …`, duplicate the
board line with a different bus, and give port 2 a different temperature so a
crossed wire is visible in the expectation. Mark the directory with a
`SYNTHETIC.md` naming every field that was invented. Synthetic fixtures prove
the *loop*, never the *text*; STOP condition 2 and 3 then remain open until a
real box confirms, and the release notes must say so.

## Step 1 — `lsi_ports()` in `lib.sh`

Add next to `hba_each` (it is backend plumbing, and three composers will call
it). Parse the banner rows; fall back to the configured port.

```bash
# Every port the bundled lsiutil can address, one per line. lsiutil's own port
# table (the banner it prints before the device menu) is the authority on the
# numbering that -p takes, and every composer already captures that banner, so
# enumeration costs no extra hardware call. Issue #18: three 2308s, and the
# plugin read the one port that Settings named.
# Falls back to $PORT so a box whose banner cannot be parsed behaves exactly as
# it did before this existed.
lsi_ports() {   # $1 = banner file
    local n
    n=$(grep -cE "^[[:space:]]+[0-9]+\.[[:space:]]+ioc" "$1" 2>/dev/null)
    if [ "${n:-0}" -gt 0 ]; then
        grep -E "^[[:space:]]+[0-9]+\.[[:space:]]+ioc" "$1" | sed -E 's/^[[:space:]]*([0-9]+)\..*/\1/'
    else
        printf '%s\n' "${PORT:-1}"
    fi
}
```

Verify:

```bash
bash -c 'source source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh; PORT=1; lsi_ports tests/fixtures/hba_banner.txt'      # -> 1
bash -c 'source source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh; PORT=1; lsi_ports tests/fixtures/lsiutil_dual/banner.txt'  # -> 1\n2
bash -c 'source source/usr/local/emhttp/plugins/hbaviewer/scripts/lib.sh; PORT=7; lsi_ports /dev/null'                          # -> 7
```

## Step 2 — `_host_for_pci()` in `lib.sh`, replacing `_first_sas_host` at the call sites

`_first_sas_host` stays (nothing else can be done when the board line is
unreadable) but stops being the primary route. New sibling:

```bash
# The scsi host number of the card at a given PCI bus/device. lsiutil prints no
# PCI address in its own telemetry, but `-b` does (the Bus and Device columns
# parse/hba.sh already reads), and every scsi_host resolves to a PCI dir via
# _pci_dir_of_host. That is the join: port -> bus/dev -> host -> sysfs.
# Prints nothing when no host matches, and callers then keep _first_sas_host's
# answer — the single-card behaviour that shipped.
_host_for_pci() {   # $1 = bus (hex, e.g. 03)   $2 = device (hex, e.g. 00)
    local h hn d
    for h in "${SYS_SCSI_HOST:-/sys/class/scsi_host}"/host*/; do
        case "$(cat "${h}proc_name" 2>/dev/null)" in mpt3sas|mpt2sas|mptsas) ;; *) continue ;; esac
        hn=${h%/}; hn=${hn##*host}
        d=$(_pci_dir_of_host "$hn")
        case "$(basename "${d:-.}")" in *:"$1":"$2".*) printf '%s' "$hn"; return 0 ;; esac
    done
    return 1
}
```

Also delete the now-false sentence from `_first_sas_host`'s comment
(*"The bundled lsiutil binary only ever addresses one controller"*) and from
`ov_lsiutil`'s `LSI_CARD_ID` block (*"At most one card here"*) — those comments
have already misled one round of work.

Verify with the fixture sysfs tree the suite already builds (`SYS_SCSI_HOST`
override, same pattern as `tests/drives_sysfs_test.sh`): a two-host tree returns
each host for its own bus, and an unmatched bus returns non-zero.

## Step 3 — `parse/hba.sh` selects a port's row

Two `head -1` calls become port-aware, defaulting to today's behaviour.

```bash
PORTSEL="${6:-}"
if [ -n "$PORTSEL" ]; then
    CARD_LINE=$(echo "$BANNER" | grep -E "^[[:space:]]+${PORTSEL}\.[[:space:]]+ioc" | head -1)
else
    CARD_LINE=$(echo "$BANNER" | grep -E "^\s+[0-9]+\.\s+ioc" | head -1)
fi
```

The board file needs no port argument **if** Step 0 confirms `-p<n> -b` works —
the composer then captures one board file per port and `head -1` stays correct.
If `-b` ignores `-p` and prints every ioc, add the same selection on
`BOARD_LINE`, keyed on `ioc$((PORTSEL-1))`, and record in the plan that the
ioc-name-to-port offset is an assumption (`1. ioc0` in every fixture we have).

Verify: `bash tests/run.sh` — all eight existing `hba-*` checks PASS unchanged
(no 6th argument passed anywhere yet).

## Step 4 — `ov_lsiutil` loops (`get_hba_info.sh:117-158`)

Structure, keeping the mpt3sas refusal and `require_binary` exactly where they
are:

```bash
    require_binary || return 1
    local IOC BANNER BOARD IDENT p first=1
    BANNER=$(mktemp); IOC=$(mktemp); BOARD=$(mktemp); IDENT=$(mktemp)
    trap 'rm -f "$IOC" "$BANNER" "$BOARD" "$IDENT"' EXIT
    printf '0\n' | hba_query 2>/dev/null > "$BANNER"     # once: the port table
    for p in $(lsi_ports "$BANNER"); do
        hba_query -p"$p" -a 25,2,0,0 2>/dev/null > "$IOC"
        hba_query -p"$p" -b          2>/dev/null > "$BOARD"
        hba_query -p"$p" -a 1,0      2>/dev/null > "$IDENT"
        local hnum pdir bus dev
        bus=$(grep "ioc" "$BOARD" | head -1 | awk '{print $3}')
        dev=$(grep "ioc" "$BOARD" | head -1 | awk '{print $4}')
        hnum=$(_host_for_pci "$bus" "$dev") || hnum=$(_first_sas_host)
        pdir=$([ -n "$hnum" ] && _pci_dir_of_host "$hnum")
        LSI_TOPOLOGY=$([ -n "$hnum" ] && hba_topology "$hnum" || printf 'unknown')
        LSI_SUBVENDOR=$([ -n "$pdir" ] && hba_subvendor "$pdir")
        LSI_CARD_ID=$([ -n "$pdir" ] && hba_card_id "$pdir")
        export LSI_TOPOLOGY LSI_SUBVENDOR LSI_CARD_ID
        [ "$first" = 1 ] || printf ','
        first=0
        bash "$DIR/parse/hba.sh" "$IOC" "$BANNER" "$BOARD" "$ALERT" "$IDENT" "$p"
    done
```

Note the `|| hnum=$(_first_sas_host)` fallback is **only correct for one port**.
On a multi-port box a failed join must yield an empty `hnum` rather than card
1's, or two cards claim the same topology and `card_id` — which would make
`card_group.php` merge two physically separate cards into one display card, the
exact inverse of the dual-IOC feature. Gate it:

```bash
        if ! hnum=$(_host_for_pci "$bus" "$dev"); then
            hnum=""
            [ "$(lsi_ports "$BANNER" | wc -l)" = "1" ] && hnum=$(_first_sas_host)
        fi
```

Verify: `bash tests/run.sh` still green, plus a new composer-level check with
`LSIUTIL` pointed at a stub script that replays the dual fixture per `-p` value
(the `flash_test.sh` stub-binary pattern). Assert: two objects in
`controllers[]`, different `temp`, different `card_id`.

## Step 5 — the other four composers loop the same way

Same `lsi_ports` loop, comma-joined, so the index join in `ajax_info.php:820`
lines up. In order of how much work each is:

- **`get_phy_health.sh:30-33`** — one command, wrap it. `parse/phy.sh` is a pure
  stdin filter, no change.
- **`get_event_log.sh:10-13`** — same shape.
- **`get_hba_health.sh:148-206`** — same loop; `hnum` from Step 2's join instead
  of `_first_sas_host`, then `_drive_count "$hnum"` and `_phys_json "$hnum"`
  become per-card instead of card-1-for-everyone. This is the step that fixes
  the reported symptom (the dashboard tile's temperature).
- **`get_attached_drives.sh:29-104`** — the only awkward one. Stage 1 is a
  per-port lsiutil query (easy), but Stage 2's sweep over
  `$SYS_SAS_DEVICE/end_device-*/` is box-wide. Filter it to this card's host by
  the `end_device-H:*` prefix, where `H` is the `hnum` from Step 2 — the same
  `H` naming rule plan 051 established. Stage 3's fallback loop already keys on
  host and just needs restricting to `$hnum`.

Verify after each: `bash tests/run.sh` && `bash tests/run_php.sh` — the PHP
suite is what proves the index join still lines up.

## Step 6 — Settings copy

`HBA_PORT` no longer selects which card is monitored; it is only the fallback
when the banner cannot be enumerated. Relabel the field in `config.php` and its
row in `README.md` (`lsiutil Port | 1 | SAS2 only …`) to say so. **Do not remove
the field** — it is the only escape hatch left if `lsi_ports` misreads a banner
we have never seen.

## Git workflow

Branch from `dev` (`67aed36`), not `main`. Inside a worktree `git switch dev`
fails, because `dev` is checked out in the main tree; create the branch at the
commit instead, which works either way:

```bash
git log --oneline -1                            # expect 67aed36 or a descendant
git switch -c advisor/059-multi-card-lsiutil 67aed36
```

One commit per step, message ending in `(plan 059)`.

## Deliberately not done

- **No PHP or JS changes.** The tile, the cards, the export and the bay map are
  already per-controller. If a multi-card box renders wrongly *after* the shell
  emits N controllers, that is a new finding, not this plan.
- **Not copying `DevlinDelFuego/Unraid-LSIUtil@c4858da`**, linked in the issue
  thread. That commit teaches *his* dashboard tile to render a `controllers[]`
  array his collector already fills. Ours renders it already and ours is empty
  past index 0 — the two plugins diverged, and the half we are missing is the
  half his commit does not contain.
- **No grouping of separate cards.** Two 2308s are two cards with two
  `card_id`s and must render as two. Grouping is for one board with two IOCs
  (`282d2d2`), which the lsiutil path can now finally produce as well — a
  9206-16e enumerates as two ports on one board, and it will group itself
  through `card_id` with no extra code. That is worth a line in the release
  notes and a request for a bundle from anyone who owns one.
