#!/bin/bash
# Diagnostic bundle generator (plan 026). Collects everything needed to debug a
# controller issue into one archive the user can attach to a GitHub issue.
#
#   bash bundle_support.sh [--smart] [--no-anon]     -> prints the archive path
#   bash bundle_support.sh anon <dir> [literal ...]  -> anonymise a dir in place
#   bash bundle_support.sh attr <sysfs-leaf>         -> one "attr = value" line
#
# The `anon` subcommand exists so the anonymisation pass is testable as a pure
# function over fixture text — see tests/anon_test.sh. It is the only part of
# this script that can be checked without hardware, and the only part whose
# failure publishes a user's serials to a public issue tracker.
#
# Every issue this project has closed was diagnosed by comparing the RAW tool
# output against what the parser made of it, so the bundle captures both, side
# by side, for every source. Dropping either half to save space makes the bundle
# unable to answer the question every past issue needed answering.
#
# NEVER collected: the flash GUID, the licence key, ident.cfg, super.dat,
# shadow, network config, share names, or any /boot/config file other than the
# plugin's own hbaviewer.cfg. Not "anonymise if asked" — out of scope entirely.

DIR="$(cd "$(dirname "$0")" && pwd)"

# Text = no NUL and no 8-bit byte, read from stdin so callers choose how much to
# feed it. Everything else in this file is a TEXT operation: the anonymiser
# rewrites text and greps text, so bytes it cannot read as text it also cannot
# check for identifiers. A binary blob may carry a SAS address in binary form
# that no text search would ever find, which is why "0 grep hits" proves nothing
# about one — the only safe answer for a non-text file is to leave it alone and
# say so.
is_text() { [ "$(LC_ALL=C tr -dc '\000\200-\377' | wc -c)" -eq 0 ]; }

# One sysfs leaf as "attr = value". A binary attribute is NAMED but never
# captured — mpt3sas exposes host_trace_buffer, a raw firmware trace ring, and
# capturing it made the whole file report as `data`, made awk warn on every
# read, and put bytes in the bundle that no grep could clear. Naming it keeps
# the bundle honest about what it omitted instead of silently dropping it.
attr() {   # $1 = path, $2 = name to print (defaults to the leaf name)
    local n="${2:-${1##*/}}"
    if head -c 512 "$1" 2>/dev/null | is_text; then
        printf '%s = %s\n' "$n" "$(head -c 512 "$1" 2>/dev/null | tr -d '\000\n' | tr -s ' ')"
    else
        printf '%s = <skipped: binary>\n' "$n"
    fi
}

# ─────────────────────────────────────────────────────────────────────────────
# Anonymisation
# ─────────────────────────────────────────────────────────────────────────────
# ONE map for the whole bundle, built by scanning every file BEFORE rewriting
# any of them, so a given drive is the same pseudonym in sysfs, in storcli
# output and in the parsed JSON. Per-file redaction would destroy the
# PHY-to-drive join (keyed on SAS address) that this project's hardest bugs
# live in — see parse/drives_join.sh.
#
# Every pseudonym is LENGTH-MATCHED to the value it replaces. storcli pads to
# fixed columns and the parsers key on that structure, so a bundle that reflows
# columns cannot be dropped in as a test fixture. The gate for this is
#   diff <(awk '{print length}' before) <(awk '{print length}' after)
#
# The map is never written anywhere. It lives in this one awk process.
#
# Replacement unit is the WORD (a maximal run of [A-Za-z0-9_.-]), which is what
# every identifier this tool sees actually is: storcli columns, JSON string
# values, lsblk key="value" pairs and sysfs leaf contents all delimit them with
# whitespace, quotes or colons. Working per-word rather than per-substring is
# what makes length preservation structural rather than something to test for.
#
# ponytail: a serial containing a space would not be replaced. No drive ships
# one; revisit if a real bundle ever shows otherwise.
bundle_anon() {   # $1 = directory, $2.. = extra literals (hostname) to replace
    local dir="$1"; shift
    local files f counts skipped
    # Defence in depth against a binary file reaching this pass at all (the
    # collector now refuses to capture one). awk would rewrite it byte-blind and
    # produce a mangled blob that still cannot be checked, so a non-text file is
    # refused outright and named in ANONYMISED.txt.
    files=""; skipped=""
    while IFS= read -r f; do
        if is_text < "$f"; then files="$files$f"$'\n'; else skipped="$skipped$f"$'\n'; fi
    done < <(find "$dir" -type f | sort)
    files="${files%$'\n'}"
    [ -n "$files" ] || return 0

    counts=$(printf '%s\n' "$files" | awk -v extras="$*" '
    # ── token factories ────────────────────────────────────────────────────
    # Addresses: "5" + a 15-digit counter = 16 chars, so a 16-hex SAS address
    #            stays exactly 16 wide.
    # Strings:   as much of the class prefix as fits while leaving 4 counter
    #            digits, then the counter. A 16-char serial becomes
    #            SERIAL0000000001, an 8-char one SERI0001, a 4-char one 0001 —
    #            always the original length, always distinct.
    function mk(klass, len,   t, pfx) {
        n[klass]++
        if (klass == "addr") return sprintf("5%015d", n[klass])
        pfx = substr(klass == "host" ? "HOST" : "SERIAL", 1, (len > 4 ? len - 4 : 0))
        t = pfx sprintf("%0" (len - length(pfx)) "d", n[klass])
        # Belt and braces: the length property is the one thing that must hold
        # even if the counter ever outgrows its field.
        if (length(t) > len) t = substr(t, length(t) - len + 1)
        while (length(t) < len) t = "0" t
        return t
    }
    # Single normalisation point. Hex identifiers are case-insensitive — sysfs
    # writes sas_address lower case, storcli upper, and the parsers upper-case
    # it again — so they are keyed upper case or the SAME drive would get two
    # different pseudonyms and the PHY-to-drive join would break silently.
    function reg(v, c) {
        if (isaddr(v)) { v = toupper(v); c = "addr" }
        if (v == "" || (v in cls)) return
        # A value made only of digits is a counter, a queue depth or a slot
        # number, not an identifier — the real ones are 16-hex addresses (already
        # promoted to "addr" above) or serials keyed off a Serial/SN line. The
        # host class is the one path with NEITHER a pattern nor the length floor
        # below, so a caller literal of "12" used to register and rewrite every
        # "12" in the bundle: host_busy = 12 came back as host_busy = 01. A
        # hostname of pure digits is indistinguishable from a count, so it is
        # left alone rather than corrupting every count in the bundle.
        if (c == "host" && v ~ /^[0-9]+$/) return
        # The length floor keeps a stray 2-char "value" from being swapped out
        # everywhere it happens to occur. The hostname is exempt: it is an exact
        # literal the caller handed us, not something inferred from a pattern,
        # and short hostnames (nas, srv) are common enough that skipping them
        # would leave the machine named in uname -a.
        if (length(v) < 4 && c != "host") return
        if (v ~ /^([Nn]\/[Aa]|NA|-+|[Uu]nknown|[Nn]one|0+)$/) return
        cls[v] = c; keys[++nkeys] = v
    }
    function key(w) { return isaddr(w) ? toupper(w) : w }
    # A word is a maximal run of identifier characters. Everything else is a
    # separator and passes through untouched.
    function isw(c) { return c ~ /^[A-Za-z0-9_.-]$/ }

    # ── scan: keyed serials ────────────────────────────────────────────────
    # Detected against real output in tests/fixtures/: storcli "SN = ..." and
    # "Serial Number = ...", storcli controller "Board Tracer Number = ...",
    # smartctl "Serial number:"/"Serial Number:", lsblk -P SERIAL="...", and
    # the parsers own JSON "serial":"..." / "sn":"...".
    function scan_serials(line,   v, rest, seg) {
        if (match(line, /^[ \t]*(SN|Serial Number|Serial number|Serial No|Board Tracer Number|Product Serial Number|Unit Serial Number)[ \t]*[=:][ \t]*/)) {
            v = substr(line, RSTART + RLENGTH)
            sub(/[ \t\r].*$/, "", v); sub(/[ \t\r]+$/, "", v)
            reg(v, "serial")
        }
        rest = line
        while (match(rest, /("serial"|"sn"|SERIAL)[ \t]*[:=][ \t]*"[^"]*"/)) {
            seg = substr(rest, RSTART, RLENGTH)
            rest = substr(rest, RSTART + RLENGTH)
            sub(/^[^:=]*[:=][ \t]*"/, "", seg); sub(/"$/, "", seg)
            reg(seg, "serial")
        }
    }
    # ── scan/rewrite: word walk ────────────────────────────────────────────
    # A 16-hex word is a SAS address or a WWN wherever it appears: storcli
    # HBASASADDR/AhDevAddr columns, "SAS Address = ", "WWN = ", lsblk
    # WWN="0x...", sysfs sas_address (lower case there, upper in the parsers,
    # so match case-insensitively), and every parsed sas_address/sas_addr.
    # ponytail: requiring a hex letter or a 5/6 NAA prefix keeps a plain
    # 16-digit decimal (a byte count) from being taken for an address. A
    # 16-digit size beginning 5 or 6 would still be swapped for another
    # 16-digit number: length-safe, and no size in this tool is that wide.
    function isaddr(w) { return w ~ /^[0-9a-fA-F]{16}$/ && (w ~ /^[56]/ || w ~ /[a-fA-F]/) }
    function walk(line, mode,   out, i, nn, st, w, pfx) {
        out = ""; i = 1; nn = length(line)
        while (i <= nn) {
            if (!isw(substr(line, i, 1))) { out = out substr(line, i, 1); i++; continue }
            st = i
            while (i <= nn && isw(substr(line, i, 1))) i++
            w = substr(line, st, i - st)
            pfx = ""
            if (w ~ /^0[xX][0-9a-fA-F]{16}$/) { pfx = substr(w, 1, 2); w = substr(w, 3) }
            if (mode == "scan") { if (isaddr(w)) reg(w, "addr") }
            else if (key(w) in map) { w = map[key(w)] }
            out = out pfx w
        }
        return out
    }

    BEGIN {
        while ((getline f) > 0) file[++nf] = f
        # ── pass 1: collect, from every file, into one map ──────────────────
        for (i = 1; i <= nf; i++) {
            while ((getline line < file[i]) > 0) { scan_serials(line); walk(line, "scan") }
            close(file[i])
        }
        nx = split(extras, ex, " ")
        for (i = 1; i <= nx; i++) reg(ex[i], "host")

        # ── assign tokens only once every real value is known, so no token can
        #    collide with a value that still has to be replaced (which would
        #    leave a real identifier sitting in the output as someone elses
        #    pseudonym and defeat the whole point) ─────────────────────────────
        for (i = 1; i <= nkeys; i++) {
            v = keys[i]; t = mk(cls[v], length(v))
            while (t in cls) t = mk(cls[v], length(v))
            map[v] = t; used[cls[v]]++
        }

        # ── pass 2: rewrite every file with that one map ────────────────────
        for (i = 1; i <= nf; i++) {
            out = file[i] ".anon"
            while ((getline line < file[i]) > 0) { s = walk(line, "sub"); print s > out }
            close(file[i]); close(out)
        }
        for (c in used) print c " " used[c]
    }')

    # Swap the rewritten copies in. Done in shell, after awk has exited, so a
    # failed pass leaves the originals untouched rather than half-replaced.
    while IFS= read -r f; do
        [ -f "$f.anon" ] && mv -f "$f.anon" "$f"
    done <<< "$files"

    {
        echo "This bundle has been anonymised."
        echo
        echo "Every drive serial, WWN and SAS address, and the hostname, was replaced"
        echo "with a stable pseudonym of the SAME LENGTH, using ONE map for the whole"
        echo "bundle. The same real value therefore reads as the same pseudonym in the"
        echo "sysfs dumps, the raw tool output and the parsed JSON alike, so every"
        echo "cross-reference still resolves and every column still lines up."
        echo
        echo "The map itself was never written to disk and does not exist any more."
        echo
        echo "Replaced classes (counts only):"
        printf '%s\n' "$counts" | sed 's/^addr /  SAS addresses + WWNs: /; s/^serial /  serial numbers: /; s/^host /  hostname: /' | sort
        if [ -n "$skipped" ]; then
            echo
            echo "REFUSED, because they are not text: the files below were left exactly as"
            echo "collected. This pass rewrites and checks TEXT; a binary file could hold an"
            echo "identifier in a form no text search would find, so nothing above is claimed"
            echo "about them. Check them by hand, or delete them, before posting this bundle."
            printf '%s' "$skipped" | sed "s|^$dir/|  |"
        fi
        echo
        echo "Deliberately NOT anonymised, because a bundle hiding them could not have"
        echo "diagnosed a single issue this project has closed: drive models, sizes,"
        echo "firmware versions, link rates, temperatures, error counters, PCI"
        echo "addresses, slot and enclosure numbers."
    } > "$dir/ANONYMISED.txt"
}

# `anon` subcommand: the pure pass, for tests and for re-running by hand.
if [ "$1" = "anon" ]; then
    shift
    d="$1"; shift
    [ -d "$d" ] || { echo "usage: bundle_support.sh anon <dir> [literal ...]" >&2; exit 2; }
    bundle_anon "$d" "$@"
    exit 0
fi
# Same reason as `anon`: the binary-attribute skip is a disclosure control, and
# this is the only way to exercise it without the hardware that has one.
if [ "$1" = "attr" ]; then attr "$2" "$3"; exit 0; fi

# ─────────────────────────────────────────────────────────────────────────────
# Collection
# ─────────────────────────────────────────────────────────────────────────────
ANON=1
SMART=0
while [ $# -gt 0 ]; do
    case "$1" in
        --smart)   SMART=1 ;;
        --no-anon) ANON=0 ;;
        *) echo "unknown option: $1" >&2; exit 2 ;;
    esac
    shift
done

source "$DIR/lib.sh"
# LSI_CFG_PATH/PORT/ALERT. Point the overview composer's cache outside the
# bundle so it reports what the hardware says now, not a minute-old snapshot.
source "$DIR/config.sh"

STAMP=$(date +%Y%m%d-%H%M%S)
NAME="hbaviewer-bundle-$STAMP"
ROOT=$(mktemp -d /tmp/hbav_bundle.XXXXXX) || exit 1
B="$ROOT/$NAME"
mkdir -p "$B"/{01-environment,02-raw,03-sysfs,04-parsed}
export LSI_CACHE="$ROOT/.lsi_cache"

note() { printf '%s\n' "$*" >> "$B/NOTES.txt"; }

# Capture a command's output. A missing tool or a failure leaves an explanatory
# note in the file rather than a zero-byte file, so a reader can tell "not
# collected" from "collected and empty".
run() {   # $1 = outfile, $2.. = command
    local out="$B/$1"; shift
    "$@" > "$out" 2>&1
    [ -s "$out" ] || printf '(no output from: %s)\n' "$*" > "$out"
}

# sysfs leaves, one "attr = value" line each, via attr() — which truncates, and
# names rather than captures any attribute that is not text.
dump_attrs() {   # $1 = outfile, $2.. = directories
    local out="$B/$1" d f; shift
    for d in "$@"; do
        [ -d "$d" ] || continue
        printf '===== %s =====\n' "$d"
        for f in "$d"/*; do
            [ -f "$f" ] && [ -r "$f" ] || continue
            attr "$f"
        done
        printf '\n'
    done > "$out" 2>/dev/null
    [ -s "$out" ] || printf '(no matching sysfs directories)\n' > "$out"
}

# ── Section 1: environment ───────────────────────────────────────────────────
run 01-environment/uname.txt uname -a
run 01-environment/unraid-version.txt cat /etc/unraid-version
# The plugin's OWN .plg only. Nothing else under /boot/config is touched.
run 01-environment/plugin-version.txt cat /boot/config/plugins/hbaviewer.plg
{
    for m in mpt3sas mpt2sas mptsas; do
        [ -r "/sys/module/$m/version" ] && printf '%s %s\n' "$m" "$(cat "/sys/module/$m/version")"
    done
    printf 'hba_driver(): %s\n' "$(hba_driver)"
} > "$B/01-environment/driver.txt" 2>&1
# proc_name per host is the honest SAS2-vs-SAS3 signal (plan 010) and the first
# thing to check on any "controller not detected" report.
{
    for h in /sys/class/scsi_host/host*/; do
        printf '%s proc_name=%s board_name=%s fw=%s\n' "$h" \
            "$(cat "${h}proc_name"  2>/dev/null)" \
            "$(cat "${h}board_name" 2>/dev/null)" \
            "$(cat "${h}version_fw" 2>/dev/null)"
    done
} > "$B/01-environment/proc_name.txt" 2>&1
[ -s "$B/01-environment/proc_name.txt" ] || printf '(no /sys/class/scsi_host entries)\n' > "$B/01-environment/proc_name.txt"

SC=$(find_storcli)
if [ -n "$SC" ]; then
    { printf 'storcli: %s\n' "$SC"; "$SC" -v 2>&1; } > "$B/01-environment/storcli.txt"
else
    printf 'storcli: NOT FOUND (searched the same candidates as lib.sh find_storcli)\n' \
        > "$B/01-environment/storcli.txt"
    note "storcli was not installed on this machine. The storcli half of section 02-raw is absent; the lsiutil half and sections 01/03/04 are complete."
fi
if [ -x "$LSIUTIL" ]; then
    printf 'lsiutil: %s (bundled)\n' "$LSIUTIL" > "$B/01-environment/lsiutil.txt"
else
    printf 'lsiutil: NOT FOUND at %s\n' "$LSIUTIL" > "$B/01-environment/lsiutil.txt"
    note "The bundled lsiutil binary is missing — re-install the plugin. The lsiutil half of section 02-raw is absent."
fi

# ── Section 2: raw tool output, one file per command ─────────────────────────
# Derived by reading the composers (get_hba_info / get_phy_health /
# get_attached_drives / get_hba_health / get_event_log), not from a static list.
# A new composer means a new entry here; without one this script keeps working
# while quietly becoming incomplete.
if [ -n "$SC" ]; then
    run 02-raw/storcli_show.txt "$SC" show
    CNT=$(STORCLI="$SC" storcli_count); CNT="${CNT:-0}"
    for c in $(seq 0 $((CNT - 1))); do
        run "02-raw/storcli_c${c}_show.txt"             "$SC" /c"$c" show
        run "02-raw/storcli_c${c}_show_all.txt"         "$SC" /c"$c" show all
        run "02-raw/storcli_c${c}_show_temperature.txt" "$SC" /c"$c" show temperature
        run "02-raw/storcli_c${c}_show_events.txt"      "$SC" /c"$c" show events
        run "02-raw/storcli_c${c}_pall_show.txt"        "$SC" /c"$c"/pall show
        run "02-raw/storcli_c${c}_eall_show_all.txt"    "$SC" /c"$c"/eall show all
        # eall/sall AND sall, both, always. They are complements, not
        # alternatives — that distinction is the entire content of plan 017,
        # and a bundle capturing only one would have been useless for #5/#6.
        run "02-raw/storcli_c${c}_eall_sall_show_all.txt" "$SC" /c"$c"/eall/sall show all
        run "02-raw/storcli_c${c}_sall_show_all.txt"      "$SC" /c"$c"/sall show all
    done
    [ "$CNT" -gt 0 ] || note "storcli is installed but enumerated 0 controllers."
fi

if [ -x "$LSIUTIL" ]; then
    printf '0\n' | hba_query > "$B/02-raw/lsiutil_banner.txt" 2>&1
    run 02-raw/lsiutil_b.txt          hba_query -b
    run 02-raw/lsiutil_ioc.txt        hba_query -p"$PORT" -a 25,2,0,0
    # Main-menu option 1, "Identify firmware, BIOS, and/or FCode". Plain menu
    # item, NOT expert mode, so no -e. Carries the flashed firmware image name
    # whose suffix IS the IT/IR personality ("MPTFW-20.00.07.00-IT") — issue #10
    # needed this collected by hand because the bundle did not have it.
    run 02-raw/lsiutil_ident.txt      hba_query -p"$PORT" -a 1,0
    run 02-raw/lsiutil_phy.txt        hba_query -p"$PORT" -a 20,12,0,0
    run 02-raw/lsiutil_osmap.txt      hba_query -p"$PORT" -a 42,0
    run 02-raw/lsiutil_eventlog.txt   hba_query -e -p"$PORT" -a 35,0
fi
# TRAN is the SAS-vs-SATA signal. read_smart.sh already branches on it (a SAS
# log-page read does not spin a drive up; an ATA one can), but nothing recorded
# it, so no bundle could answer "are these drives SATA?" without asking.
run 02-raw/lsblk.txt lsblk -S -P -o NAME,TRAN,WWN,SERIAL,MODEL

# ── Section 3: sysfs ─────────────────────────────────────────────────────────
run 03-sysfs/listing.txt sh -c 'ls -l /sys/class/scsi_host/ /sys/class/sas_phy/ /sys/class/sas_device/ /sys/class/sas_end_device/ 2>&1'
dump_attrs 03-sysfs/scsi_host.txt     /sys/class/scsi_host/host*
dump_attrs 03-sysfs/sas_phy.txt       /sys/class/sas_phy/phy-*
# lsblk's TRAN is the BUS, not the drive: a SATA disk behind a SAS HBA reads
# "sas". The per-drive truth is target_port_protocols in the sas_device class
# (ssp = SAS, sata = SATA). Captured because a diagnosis on 2026-08-04 needed
# it and no bundle had it — sas_end_device, already dumped below, is a
# different class and does not carry it.
dump_attrs 03-sysfs/sas_device.txt /sys/class/sas_device/end_device-*
dump_attrs 03-sysfs/sas_end_device.txt /sys/class/sas_end_device/end_device-*
# The expander's own address — what plan 052 keys an expander-attached drive's
# bay assignment on. Its end_device siblings are dumped above; the expander
# itself was never captured, so no bundle could confirm the address is even
# readable without asking.
dump_attrs 03-sysfs/sas_expander.txt /sys/class/sas_device/expander-*
# PCIe link state for the controllers only — resolved from each SAS host's own
# device path, so this never walks the whole PCI tree.
{
    for h in /sys/class/scsi_host/host*/; do
        case "$(cat "${h}proc_name" 2>/dev/null)" in mpt3sas|mpt2sas|mptsas) ;; *) continue ;; esac
        p=$(readlink -f "$h" 2>/dev/null); p="${p%%/host*}"
        [ -d "$p" ] || continue
        printf '===== %s =====\n' "$p"
        for a in current_link_width current_link_speed max_link_width max_link_speed power_state vendor device; do
            attr "$p/$a" "$a"
        done
        printf '\n'
    done
} > "$B/03-sysfs/pci.txt" 2>/dev/null
[ -s "$B/03-sysfs/pci.txt" ] || printf '(no mpt2sas/mpt3sas/mptsas host found)\n' > "$B/03-sysfs/pci.txt"

# ── Section 4: what the plugin made of it ────────────────────────────────────
# Raw and parsed side by side is the whole point: #3 was a proc_name mismatch,
# #5/#6 an enclosure-less address form, #8 PHY counters bleeding into the
# temperature badge. In each case neither half alone was enough.
run 04-parsed/get_hba_info.json        bash "$DIR/get_hba_info.sh"
run 04-parsed/get_phy_health.json      bash "$DIR/get_phy_health.sh"
run 04-parsed/get_attached_drives.json bash "$DIR/get_attached_drives.sh"
run 04-parsed/get_hba_health.json      bash "$DIR/get_hba_health.sh"
run 04-parsed/get_event_log.json       bash "$DIR/get_event_log.sh"
# The plugin's own cfg, and nothing else from /boot/config. HBA_PORT and
# ALERT_THRESHOLD change what every composer above does.
run 04-parsed/hbaviewer.cfg cat "$CFG"

# ── Section 5: SMART (opt-in) ────────────────────────────────────────────────
if [ "$SMART" = "1" ]; then
    mkdir -p "$B/05-smart"
    # -n standby, matching collect_smart.sh: a sleeping drive is reported as
    # sleeping rather than woken. ~1s per drive, which is why this is opt-in.
    lsblk -S -P -o NAME,WWN 2>/dev/null | grep 'WWN="0x' | \
    sed -n 's/.*NAME="\([^"]*\)".*/\1/p' | while IFS= read -r d; do
        smartctl -n standby -a "/dev/$d" > "$B/05-smart/$d.txt" 2>&1
    done
    [ -n "$(ls -A "$B/05-smart" 2>/dev/null)" ] || \
        printf '(no SCSI block devices with a WWN were found)\n' > "$B/05-smart/NONE.txt"
else
    note "SMART data was not requested (the Include SMART box was left unticked)."
fi

# ── README ───────────────────────────────────────────────────────────────────
cat > "$B/00-README.txt" <<EOF
HBAviewer diagnostic bundle
Generated: $(date -Is 2>/dev/null || date)
Anonymised: $([ "$ANON" = 1 ] && echo yes || echo "NO — this bundle contains real serials, WWNs and SAS addresses")
SMART included: $([ "$SMART" = 1 ] && echo yes || echo no)

01-environment  kernel, Unraid + plugin version, tool presence, driver, proc_name
02-raw          raw storcli / lsiutil / lsblk output, one file per command
03-sysfs        scsi_host, sas_phy, sas_device, sas_expander, sas_end_device and controller PCIe state
04-parsed       what each composer made of the above, plus hbaviewer.cfg
05-smart        smartctl -n standby -a per drive (only if requested)

Read 02-raw and 04-parsed together. Every issue this project has closed was
diagnosed by comparing the raw tool output against what the parser made of it.

Nothing from /boot/config is included except the plugin's own hbaviewer.cfg.
No flash GUID, licence key, ident.cfg, super.dat, shadow, network config or
share names is collected, under either setting.
EOF
[ -f "$B/NOTES.txt" ] || printf '(nothing missing — every source was collected)\n' > "$B/NOTES.txt"

# ── Anonymise, then archive ──────────────────────────────────────────────────
if [ "$ANON" = 1 ]; then
    bundle_anon "$B" "$(hostname 2>/dev/null)" "$(uname -n 2>/dev/null)"
fi

# Unraid ships a minimal userland; zip is NOT guaranteed. tar is.
if command -v zip >/dev/null 2>&1; then
    ARCHIVE="$ROOT/$NAME.zip"
    ( cd "$ROOT" && zip -qr "$ARCHIVE" "$NAME" ) || { echo "archive failed" >&2; exit 1; }
else
    ARCHIVE="$ROOT/$NAME.tar.gz"
    tar czf "$ARCHIVE" -C "$ROOT" "$NAME" || { echo "archive failed" >&2; exit 1; }
fi
rm -rf "$B" "$ROOT/.lsi_cache"
[ -s "$ARCHIVE" ] || { echo "archive is empty" >&2; rm -rf "$ROOT"; exit 1; }
printf '%s\n' "$ARCHIVE"
