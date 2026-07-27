# Plan 004: Read Performance-tab temperatures per controller instead of by position

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 0346777..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/get_metrics.sh tests/run.sh`
> If either file changed since this plan was written, compare the "Current
> state" excerpts against the live code before proceeding; on a mismatch,
> treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `0346777`, 2026-07-26

## Why this matters

The Performance tab's temperature graph reads controller temperatures out of a
cached JSON file by grepping for `"temp":<digits>` and indexing the results by
position. That approach has two separate defects, and between them it means the
temperature graph is wrong or missing for most users.

**Defect A — every SAS2 box shows no temperature at all.** The lsiutil backend
pretty-prints its JSON with a space after the colon (`"temp": 47`). The pattern
`"temp":[0-9]+` requires the digit immediately after the colon, so it never
matches, the array comes back empty, and every controller graphs `null`
forever.

**Defect B — a mixed system attributes temperatures to the wrong card.** A
storcli controller that fails to report a temperature emits an error object with
no `temp` key at all. Its slot in the results simply vanishes, so every
controller after it shifts down by one and displays its neighbour's
temperature. On a two-card box where the first card errors, controller 0's graph
silently plots controller 1's readings.

After this plan, temperatures are extracted per controller in controller order,
with an explicit `null` for any controller that genuinely has none. Both defects
close, and the extraction gets fixture-based test coverage in the process.

## Current state

Files involved:

- `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_metrics.sh` — the
  Performance-tab snapshot composer. The broken extraction is line 57; the
  consumption is line 77.
- `source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/` — the directory of
  pure, fixture-tested filters. The new parser goes here.
- `tests/run.sh` — the golden-file test runner.
- `tests/fixtures/`, `tests/expected/` — inputs and expected outputs.

The broken extraction, `get_metrics.sh:55-57`:

```bash
# Controller temperatures from the existing overview cache (no hardware hit).
CACHE="${LSI_CACHE:-/tmp/lsiutil_dash.json}"
temps=($(grep -oE '"temp":[0-9]+' "$CACHE" 2>/dev/null | grep -oE '[0-9]+'))
```

The consumption, `get_metrics.sh:71-78`:

```bash
printf '{"t":%s,"controllers":[' "$(date +%s)"
for i in "${!hosts[@]}"; do
    [ "$i" -gt 0 ] && printf ','
    read -r inv disp sync reset <<<"$(phy_sum "${hosts[$i]}")"
    drives=$(bash "$DIR/parse/diskstats.sh" "${cdevs[$i]}" <<<"$DS")   # {"drives":[...]}
    printf '{"idx":%d,"temp":%s,"phy":{"inv":%d,"disp":%d,"sync":%d,"reset":%d},%s' \
        "$i" "${temps[$i]:-null}" "$inv" "$disp" "$sync" "$reset" "${drives#\{}"
done
printf ']}'
```

Note `"${temps[$i]:-null}"` — the fallback to the literal string `null` is
already correct JSON and stays as-is. Only how `temps` gets populated changes.

**The two JSON shapes the cache can hold.** This is the crux, so both are
inlined here.

The storcli backend emits compact JSON, no space after the colon — this is
`tests/expected/storcli_multi.json` verbatim:

```
{"backend":"storcli","driver":"","controllers":[{"temp":72,"model":"SAS3416","firmware":"24.00.00.00","bios":"09.15.00.00_08.00.00.00","mode":"IT","drive_count":"16","port_name":"","board_name":"HBA 9400-16i","pci_location":"00:c1:00:00","pcie_width":"","pcie_speed":"","power_mode":"","alert_threshold":80,"status":"warn"}
,{"temp":77,"model":"SAS3408","firmware":"24.00.00.00","bios":"09.03.00.00_02.00.00.00","mode":"IT","drive_count":"8","port_name":"","board_name":"HBA 9400-8i","pci_location":"00:65:00:00","pcie_width":"","pcie_speed":"","power_mode":"","alert_threshold":80,"status":"warn"}
]}
```

The lsiutil backend emits pretty-printed JSON **with a space after the colon** —
this is `tests/expected/hba_notemp.json` verbatim, and it is a card with no
onboard sensor, so `temp` is an empty string rather than a number:

```
{
  "temp": "",
  "model": "SAS2308",
  "firmware": "14.00.07.00",
  "fw_old": true,
  "port_name": "ioc0",
  "board_name": "SAS9207-8i",
  "pci_location": "03:00",
  "pcie_width": "x8",
  "pcie_speed": "Gen2 (5.0 GT/s)",
  "power_mode": "Full",
  "alert_threshold": 80,
  "status": "ok"
}
```

The lsiutil producer that creates that shape,
`scripts/parse/hba.sh:77-88` — this is where the space comes from:

```bash
if [ -n "$TEMP" ]; then
    ...
    TEMPJSON="$TEMP"
else
    STATUS="ok"; TEMPJSON='""'
fi

cat <<EOF
{
  "temp": $TEMPJSON,
```

And the storcli producer's error path, `scripts/parse/storcli_overview.sh:17-21`
— this is where a controller with **no `temp` key at all** comes from:

```bash
TEMP=$(printf '%s\n' "$input" | grep -m1 'ROC temperature' | grep -oE '[0-9]+' | tail -1)
if [ -z "$TEMP" ]; then
    echo '{"error":"No temperature in storcli output. Check the controller index."}'
    exit 0
fi
```

Both are wrapped by `hba_each` in `scripts/lib.sh:93-108` into
`{"backend":…,"driver":…,"controllers":[…]}`.

**Repo conventions that apply here:**

- Pure text-to-JSON filters live in `scripts/parse/`, take stdin (or file
  arguments), write to stdout, touch no hardware, and carry a header comment
  explaining what they consume and emit. Read
  `source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/diskstats.sh` in full
  as the structural exemplar — it is the closest sibling (a pure awk filter used
  by this same composer).
- Every parser has at least one golden test in `tests/run.sh` of the form
  `check <name> <expected-file> <command...> < <fixture>`.
- Deliberate simplifications are marked with a `ponytail:` comment naming the
  ceiling and the upgrade path — see `get_metrics.sh:16-19` and
  `scripts/lib.sh:80`.
- Parsers use GNU awk. CI installs `gawk` and makes it the default `awk`
  (`.github/workflows/php.yml`), and Unraid's Slackware base ships gawk as
  `awk`. Using gawk extensions is fine and already done elsewhere — see the
  three-argument `match()` in `scripts/parse/storcli_drives.sh:17`.

Relevant vocabulary from `source/usr/local/emhttp/plugins/hbaviewer/CONTEXT.md`
(the module glossary — the executor has not read it):

> **performance snapshot** — `scripts/get_metrics.sh` (+ `parse/diskstats.sh`)
> The INSTANT path behind the Performance tab. `get_metrics.sh` emits raw
> cumulative counters — never a storcli/lsiutil call — from `/proc/diskstats`
> (via the pure, fixture-tested `parse/diskstats.sh`), sysfs PHY counters, and
> the 60s overview temp cache, grouped per controller.

Keep that property intact: the new parser must read the **cache file only** and
must never invoke storcli or lsiutil.

## Commands you will need

| Purpose             | Command                                                             | Expected on success        |
|---------------------|---------------------------------------------------------------------|----------------------------|
| Shell lint          | `find source tests -name '*.sh' -print0 \| xargs -0 -r -n1 bash -n` | exit 0                     |
| Full test suite     | `bash tests/run.sh`                                                 | `--- all pass ---`, exit 0 |
| Regenerate goldens  | `UPDATE=1 bash tests/run.sh`                                        | prints `WROTE <name>`      |
| Check awk is GNU    | `awk --version \| head -1`                                          | contains `GNU Awk`         |

**Do not run `UPDATE=1` to make a failing test pass.** It overwrites the
expected file with whatever the code currently produces, which turns a real
failure into a silently accepted wrong answer. Use it only in Step 3, where
this plan explicitly creates new goldens and tells you to read them before
accepting them.

## Scope

**In scope**:

- `source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/cache_temps.sh` (create)
- `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_metrics.sh` (modify lines 55–57 only)
- `tests/fixtures/cache_storcli_multi.json` (create)
- `tests/fixtures/cache_lsiutil_notemp.json` (create)
- `tests/fixtures/cache_mixed_error.json` (create)
- `tests/expected/cache_temps_storcli.txt` (create, generated)
- `tests/expected/cache_temps_lsiutil.txt` (create, generated)
- `tests/expected/cache_temps_mixed.txt` (create, generated)
- `tests/run.sh` (add three `check` lines)

**Out of scope** (do NOT touch, even though they look related):

- `source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/hba.sh` — do **not**
  "fix" the pretty-printing to remove the space. That output shape is covered by
  golden tests (`tests/expected/hba_normal.json`, `hba_notemp.json`) and is
  consumed by PHP's `json_decode`, which does not care. Changing the producer to
  suit a broken consumer is backwards; fix the consumer.
- `source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/storcli_overview.sh` —
  the error-object path is correct behaviour. A controller that cannot report a
  temperature *should* say so. This plan makes the consumer handle it.
- The `perfPush(cells.temp, ...)` JavaScript in
  `source/usr/local/emhttp/plugins/hbaviewer/hbaviewer.php:607-608`. It already
  handles `null` correctly (`temp == null ? NaN : temp`) and needs no change.
- The controller-index-equals-host-order assumption documented at
  `get_metrics.sh:16-19`. That is a separate, deliberate simplification with its
  own upgrade path noted; this plan does not touch it.

## Git workflow

- Branch: `advisor/004-metrics-temp-per-controller`
- Commit per logical unit is fine (parser + tests, then the composer change), or
  one commit for the lot. Message style matches this repo's history — short
  imperative subject, no conventional-commit prefix. Suggested:
  `Read Performance-tab temperatures per controller`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Create the parser

Create `source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/cache_temps.sh`
with exactly this content. It has been verified against all six input shapes
listed in the Test plan; do not rewrite it from scratch.

```bash
#!/bin/bash
# Pure filter: the get_hba_info.sh cache JSON on stdin -> one line per
# controller, in controller order: the integer temperature, or "null" when that
# controller reports none (no sensor, or a controller-level error object).
#
# Positional greping of "temp" cannot do this. The lsiutil backend pretty-prints
# `"temp": 72` WITH a space, so a `"temp":[0-9]+` pattern silently matches
# nothing on every SAS2 box; and an erroring storcli controller emits no temp key
# at all, so a flat match list shifts every later controller onto the wrong card.
# Walking the controllers array keeps position and value tied together.
#
# ponytail: brace-depth scan, not a real JSON parser — safe because every value
# these two backends emit is a number or a brace-free string. A value containing
# a literal { or } would need a real parser (or jq, which Unraid doesn't ship).
awk '
{ s = s $0 }
END {
    i = index(s, "\"controllers\"")
    if (i == 0) exit
    s = substr(s, i)
    i = index(s, "[")
    if (i == 0) exit
    s = substr(s, i + 1)
    depth = 0; obj = ""
    n = length(s)
    for (j = 1; j <= n; j++) {
        c = substr(s, j, 1)
        if (c == "{") depth++
        if (depth > 0) obj = obj c
        if (c == "}") {
            depth--
            if (depth == 0) { emit(obj); obj = "" }
        }
        if (depth == 0 && c == "]") break
    }
}
function emit(o,   m) {
    if (match(o, /"temp"[ \t]*:[ \t]*[0-9]+/)) {
        m = substr(o, RSTART, RLENGTH)
        sub(/.*:[ \t]*/, "", m)
        print m
    } else print "null"
}'
```

**Verify**: `bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/cache_temps.sh` → exit 0

**Verify**: the parser handles the compact storcli shape —
```bash
bash source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/cache_temps.sh < tests/expected/storcli_multi.json
```
→ prints exactly two lines: `72` then `77`

### Step 2: Create the three fixtures

These give the parser coverage of both backends and the misattribution case.

```bash
cp tests/expected/storcli_multi.json tests/fixtures/cache_storcli_multi.json

{ printf '{"backend":"lsiutil","driver":"mpt2sas 43.100.00.00","controllers":['
  cat tests/expected/hba_notemp.json
  printf ']}'
} > tests/fixtures/cache_lsiutil_notemp.json

printf '%s' '{"backend":"storcli","driver":"","controllers":[{"error":"No temperature in storcli output. Check the controller index."}
,{"temp":77,"model":"SAS3408","firmware":"24.00.00.00","bios":"09.03.00.00_02.00.00.00","mode":"IT","drive_count":"8","port_name":"","board_name":"HBA 9400-8i","pci_location":"00:65:00:00","pcie_width":"","pcie_speed":"","power_mode":"","alert_threshold":80,"status":"warn"}
]}' > tests/fixtures/cache_mixed_error.json
```

The third fixture is the regression case for Defect B: controller 0 errors,
controller 1 reports 77. The correct answer is `null` then `77` — the old code
would have produced `77` on the single first line, putting card 1's temperature
on card 0's graph.

**Verify**: `ls tests/fixtures/cache_*.json | wc -l` → prints `3`

### Step 3: Generate and inspect the goldens

Add the three `check` lines to `tests/run.sh`. Put them immediately after the
existing `diskstats` line (currently line 44), since `cache_temps` belongs with
the other Performance-tab parsers:

```bash
# Performance-tab temperatures: per controller, in order. Covers the lsiutil
# pretty-printed shape (space after the colon) and an erroring controller —
# the two cases a positional grep got wrong.
check cache-temps-storcli cache_temps_storcli.txt bash "$P/cache_temps.sh" < fixtures/cache_storcli_multi.json
check cache-temps-lsiutil cache_temps_lsiutil.txt bash "$P/cache_temps.sh" < fixtures/cache_lsiutil_notemp.json
check cache-temps-mixed   cache_temps_mixed.txt   bash "$P/cache_temps.sh" < fixtures/cache_mixed_error.json
```

Generate the expected files:

```bash
UPDATE=1 bash tests/run.sh
```

> **`UPDATE=1` rewrites EVERY golden, not just the new ones.** It re-runs all
> cases and overwrites each `expected/` file with `printf '%s'`, which strips the
> trailing newline the committed goldens carry — so ten unrelated files show up
> as modified with identical content. Verified: this happens.
>
> Immediately afterwards, confirm the damage is limited to the three new files
> and revert the rest:
>
> ```bash
> git status --porcelain tests/expected/
> # every pre-existing file listed here should be reverted:
> git checkout -- tests/expected/hba_normal.json tests/expected/hba_notemp.json \
>   tests/expected/drives_osmap.txt tests/expected/events_empty.json \
>   tests/expected/phy_unsupported.json tests/expected/rollup_faildrive.json \
>   tests/expected/rollup_healthy.json tests/expected/rollup_phyerr.json \
>   tests/expected/route_no_backend.json tests/expected/storcli_overview.json
> ```
>
> Before reverting any file, confirm its content is genuinely unchanged:
> `test "$(git show HEAD:<file>)" = "$(cat <file>)"` exits 0 when only the
> trailing newline differs. If a file's *content* changed, that is a real
> regression from your parser edit — STOP and report it rather than reverting.

Now **read the three generated files and confirm they are correct** before
accepting them. `UPDATE=1` records whatever the code produced; it does not know
what the right answer is.

```bash
cat tests/expected/cache_temps_storcli.txt
cat tests/expected/cache_temps_lsiutil.txt
cat tests/expected/cache_temps_mixed.txt
```

**Verify**, exactly:

- `cache_temps_storcli.txt` contains two lines: `72`, `77`
- `cache_temps_lsiutil.txt` contains one line: `null`
- `cache_temps_mixed.txt` contains two lines: `null`, `77`

If any of these differs, STOP — the parser is wrong and regenerating the golden
would bake the bug in.

**Verify**: `bash tests/run.sh` → `--- all pass ---`, exit 0

### Step 4: Switch the composer to the parser

In `source/usr/local/emhttp/plugins/hbaviewer/scripts/get_metrics.sh`, replace
lines 55–57:

```bash
# Controller temperatures from the existing overview cache (no hardware hit).
CACHE="${LSI_CACHE:-/tmp/lsiutil_dash.json}"
temps=($(grep -oE '"temp":[0-9]+' "$CACHE" 2>/dev/null | grep -oE '[0-9]+'))
```

with:

```bash
# Controller temperatures from the existing overview cache (no hardware hit).
# Parsed per controller so index N really is controller N — see cache_temps.sh
# for why a flat grep silently mis-attributes them.
CACHE="${LSI_CACHE:-/tmp/lsiutil_dash.json}"
temps=()
[ -s "$CACHE" ] && mapfile -t temps < <(bash "$DIR/parse/cache_temps.sh" < "$CACHE" 2>/dev/null)
```

Leave line 77 (`"${temps[$i]:-null}"`) exactly as it is. The parser emits the
literal string `null` for a sensorless controller, and the `:-null` fallback
still covers the case where the cache holds fewer controllers than sysfs
reports — both produce valid JSON.

`$DIR` is already defined at `get_metrics.sh:21` as `DIR="$(dirname "$0")"`, and
the file already invokes a sibling parser the same way at line 75
(`bash "$DIR/parse/diskstats.sh" ...`). Match that call style.

**Verify**: `bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/get_metrics.sh` → exit 0

**Verify**: the old pattern is gone —
`grep -c 'grep -oE .\"temp\":' source/usr/local/emhttp/plugins/hbaviewer/scripts/get_metrics.sh` → prints `0`

### Step 5: Prove the composer end-to-end against a fixture cache

`get_metrics.sh` honours `LSI_CACHE`, so you can point it at a fixture without
hardware. It reads `/sys` and `/proc` for the rest, so on a machine with no SAS
controllers it will emit an empty controllers array — that is fine and expected;
what you are checking is that it runs clean and produces valid JSON.

```bash
LSI_CACHE="$PWD/tests/fixtures/cache_lsiutil_notemp.json" \
  bash source/usr/local/emhttp/plugins/hbaviewer/scripts/get_metrics.sh; echo " exit=$?"
```

**Verify**: exit code 0, and the output starts with `{"t":` and ends with `]}`.

If you are on a machine that *does* have SAS controllers, additionally confirm
the first controller's `"temp":` field is `null` for that fixture (the fixture
describes a sensorless card) rather than a number borrowed from elsewhere.

### Step 6: Full lint and suite

**Verify**: `find source tests -name '*.sh' -print0 | xargs -0 -r -n1 bash -n` → exit 0

**Verify**: `bash tests/run.sh` → `--- all pass ---`, exit 0, and the output
includes `PASS  cache-temps-storcli`, `PASS  cache-temps-lsiutil`, and
`PASS  cache-temps-mixed`.

## Test plan

**New tests** — three golden cases in `tests/run.sh`, modelled structurally on
the existing `diskstats` case at `tests/run.sh:44`:

| Case | Fixture | Covers | Expected |
|---|---|---|---|
| `cache-temps-storcli` | `cache_storcli_multi.json` | happy path, compact JSON, two controllers | `72`, `77` |
| `cache-temps-lsiutil` | `cache_lsiutil_notemp.json` | **Defect A** — pretty-printed `"temp": ` with a space, sensorless card | `null` |
| `cache-temps-mixed`   | `cache_mixed_error.json`   | **Defect B** — erroring controller must not shift its neighbour up | `null`, `77` |

Three further shapes were verified by hand while writing this plan and need no
committed fixture, but re-check them if you modify the parser:

- lsiutil backend *with* a working sensor (`hba_normal.json` wrapped) → one line, `47`
- empty controllers array (`"controllers":[]`) → no output
- top-level backend error (`{"error":"storcli not found."}`) → no output

**Verification**: `bash tests/run.sh` → all pass, including the three new cases.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `test -f source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/cache_temps.sh`
- [ ] `bash source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/cache_temps.sh < tests/expected/storcli_multi.json` prints exactly `72` and `77`
- [ ] `test "$(cat tests/expected/cache_temps_lsiutil.txt)" = "null"` exits 0
- [ ] `test "$(cat tests/expected/cache_temps_mixed.txt)" = "$(printf 'null\n77')"` exits 0 — **the Defect B proof**
- [ ] `grep -c "grep -oE .\"temp\":" source/usr/local/emhttp/plugins/hbaviewer/scripts/get_metrics.sh` prints `0`
- [ ] `grep -c 'parse/cache_temps.sh' source/usr/local/emhttp/plugins/hbaviewer/scripts/get_metrics.sh` prints `1` (the invocation; the explanatory comment names the file without the `parse/` prefix and is not counted)
- [ ] `git status --porcelain tests/expected/` lists **only** the three new `cache_temps_*.txt` files — see the `UPDATE=1` warning in Step 3
- [ ] `find source tests -name '*.sh' -print0 | xargs -0 -r -n1 bash -n` exits 0
- [ ] `bash tests/run.sh` exits 0, prints `--- all pass ---`, and includes all three `PASS  cache-temps-*` lines
- [ ] `git status --porcelain` shows only the files listed in "In scope" (plus `plans/README.md`)
- [ ] `plans/README.md` status row for 004 updated

## STOP conditions

Stop and report back (do not improvise) if:

- `awk --version` does not report GNU Awk. The parser uses `match()` with
  `RSTART`/`RLENGTH`, which is POSIX, but the rest of this repo's parsers assume
  gawk and a non-GNU awk will produce confusing failures elsewhere in the suite.
- Any of the three generated goldens in Step 3 does not match the values stated
  there. Do not regenerate to make it pass — the parser is wrong and you need to
  report what it actually produced.
- `get_metrics.sh` does not match the excerpt in "Current state" — particularly
  if someone has already changed how `temps` is populated.
- You conclude the fix requires editing `parse/hba.sh` or
  `parse/storcli_overview.sh`. Both are out of scope and both are producing
  correct output; if you believe otherwise, report the reasoning rather than
  changing them.
- `bash tests/run.sh` had failures *before* your changes. It passes at commit
  `0346777` for the shell half (the PHP half needs `php` or Docker). Establish
  that baseline first so you can tell your failures from pre-existing ones.

## Maintenance notes

- **The parser is a brace-depth scanner, not a JSON parser.** It is safe for the
  two producers in this repo because every value they emit is a number or a
  brace-free string. If a future backend emits a value that can contain `{` or
  `}` — a free-text firmware description, a vendor string — this will
  mis-segment. The `ponytail:` comment in the file names that ceiling.
- **A third backend must keep the wrapper contract.** The parser keys off
  `"controllers"` followed by `[`, produced by `hba_each` in `scripts/lib.sh`.
  Any new backend that goes through `hba_each` is handled automatically; one
  that bypasses it is not.
- **This does not fix temperature attribution in general** — only the extraction.
  The mapping from cache position to Performance-tab controller still assumes
  controller index equals SAS host order, documented as a deliberate
  simplification at `get_metrics.sh:16-19` with serial-exact attribution named as
  the upgrade path. If that assumption is ever broken, this parser is not where
  the fix goes.
- **What a reviewer should scrutinise**: the `cache-temps-mixed` golden. That
  one file is the entire proof that Defect B is fixed — it must read `null` then
  `77`, not a single `77`.
