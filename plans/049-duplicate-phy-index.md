# Plan 049: Link Integrity stuck at "not enough samples" — duplicate PHY indexes

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 2ad76f1..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_health.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/get_hba_info.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/get_metrics.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/get_phy_health.sh source/usr/local/emhttp/plugins/hbaviewer/health.php`
> Expected output: **nothing**. Every excerpt below is quoted from `2ad76f1`
> (`dev` tip, 2026-08-05). Any difference is a STOP condition.

## Status

- **Priority**: **P1** — the HBA Health tab's Link Integrity indicator never
  works at all on affected hardware, and the failure is silent: it reads
  "Not enough samples yet", which is indistinguishable from a box that has
  simply not been open long enough.
- **Effort**: S
- **Risk**: LOW — the fix narrows a glob and adds a guard. No new state, no new
  UI, no hardware writes.
- **Depends on**: none.
- **Category**: bug
- **Planned at**: `2ad76f1`, 2026-08-05
- **Reported by**: issue #12, with an unusually complete diagnosis (the
  reporter found the overwrite in `health_ingest()`, tested a fix and posted
  it) **plus a full diagnostic bundle**. The maintainer then reported the same
  symptom on his own box. **Not related to plan 044** despite the same tab —
  044 changed how a rate is *rounded for display*; this is the history never
  accumulating in the first place.

## Why this matters

Link Integrity is one of the five HBA Health indicators, and on affected
hardware it can never leave `unknown`. Worse, it fails as "not enough data yet"
rather than as an error, so it looks like patience is the answer. The reporter
waited several minutes and reloaded repeatedly before digging into `/tmp`.

The same collision silently affects the Overview's PHY error rollup and the
Performance tab's PHY error-rate graph (see "Three consumers, one bug").

## Root cause — one glob, not a vendor quirk

The issue reports "the PHY output has duplicate PHY indexes" and asks why the
collector produces them. It is not the controller. It is this glob:

```bash
# scripts/get_hba_health.sh — _phys_json()
for p in /sys/class/sas_phy/phy-"${1}":*/; do
    [ -d "$p" ] || continue
    idx=$(basename "$p"); idx=${idx##*:}
```

`/sys/class/sas_phy` contains **two shapes of name**:

| Name | What it is |
| --- | --- |
| `phy-<host>:<n>` | the HBA's own PHY |
| `phy-<host>:<n>:<m>` | a PHY on an **expander** behind that HBA |

`phy-0:*` matches both, and `${idx##*:}` keeps only the last component — so
`phy-0:0:0` (an edge expander PHY) becomes `idx 0` and collides with `phy-0:0`.

**Measured in the reporter's bundle** (`hbaviewer-bundle-20260804-181814`):

```text
03-sysfs/sas_phy.txt
  29 entries named phy-H:N      → device_type = end device      (the HBA's own)
  76 entries named phy-H:N:M    → device_type = edge expander

04-parsed/get_hba_health.json
  "idx":7 appears 4 times   (and 6, 5, 4, 3 … likewise)
```

So each real PHY is joined by three phantoms carrying the same index.

**The phantoms are not all zero, and that is what makes this destructive.**
Some expander PHYs report empty counter files (`invalid_dword_count = ` with no
value, which `printf '%d'` turns into `0`); others report real counts of their
own — `phy-0:0:10` and `phy-0:0:11` both read `invalid_dword_count = 4` in the
capture, while the controller's own `phy-0:10` reads its own separate value.
A collapsed slot therefore ends up holding a counter that belongs to a
**different device**, and the next sample compares the real PHY against it.
When the phantom's number is the higher one — a quiet HBA PHY behind a
chattier expander PHY — that reads as a decrease.

(An earlier draft of this plan said the phantoms "all look like a PHY with zero
errors". That was wrong and it matters: with every phantom at zero, no
comparison can ever yield a decrease and the bug does not reproduce. It was
caught when a test written from that description passed with the fix removed.)

From there the reported failure follows exactly:

```php
// health.php — health_ingest()
foreach ($newest['phys'] ?? [] as $p) $prevByIdx[$p['idx']] = $p;
foreach ($sample['phys'] ?? [] as $p) {
    $prev = $prevByIdx[$p['idx']] ?? null;
    if ($prev === null) continue;
    foreach (['inv', 'disp', 'sync', 'rst'] as $k) {
        if (($p[$k] ?? 0) < ($prev[$k] ?? 0)) { $reset = true; break 2; }
    }
}
```

The map keeps whichever entry came last (a phantom, `inv 0`); the real PHY's
non-zero counter is then compared against it, reads as a **decrease**, and is
treated as a driver reload — which wipes the ring. Every sample wipes the
previous one, so `count($ring)` never exceeds 1 and `health_rates()` never has
two samples to work with. That is the reporter's `Samples: 1`.

**A box with no expander never collides**, which is why this shipped: the
maintainer's 9400s and every fixture in the suite have only `phy-H:N` entries.

## Three consumers, one bug

The same glob-and-truncate appears in three places. Only the first produces the
reported symptom, but all three are wrong on this hardware:

| File | Consequence |
| --- | --- |
| `get_hba_health.sh` `_phys_json` | the ring wipe above — Link Integrity never resolves |
| `get_hba_info.sh` | the Overview's PHY error rollup counts phantom PHYs |
| `get_metrics.sh` | the Performance tab's PHY error-rate series mis-pairs across polls |

**`get_phy_health.sh` is NOT affected, and it is the model for the fix.** It
globs every PHY but joins on the SAS address:

```bash
# scripts/get_phy_health.sh — _build_phy_sysfs()
for p in /sys/class/sas_phy/phy-*/; do
    sas=$(sed 's/0x//' "$p/sas_address" ...)
```

and `parse/storcli_phy.sh` only uses entries whose address equals the
controller's base. The bundle confirms why that works: every one of host 0's
own PHYs reports `sas_address 0x5000000000000134`, while the expander PHYs
report `0x5000000000000135`. The address separates them; the name does not.

## Why not simply take the reporter's fix

The posted fix — store duplicates as a list and pair them FIFO — stops the ring
wipe, and it is a correct observation of where the damage happens. It is not
the right primary fix:

- It pairs an HBA PHY with an **expander** PHY. Those are different devices;
  their counters are unrelated. Pairing them "correctly" is still pairing two
  things that should never have been in the same list.
- It depends on sysfs enumeration order being identical between two samples
  taken minutes apart. Usually true; nothing guarantees it, and when it is not,
  the mis-pairing is silent.
- It leaves `idx` ambiguous everywhere it is *displayed* — "PHY 10 loss of sync
  errors rising" in the Health reason, and the top-offenders list, would now
  have four candidate PHYs called 10.

The invariant this code needs is **"a controller's PHY list contains each PHY
once"**, and the honest place to enforce it is where the list is built.

Keep the spirit of the reporter's fix as a *consumer guard* (Step 4): if a
duplicate index ever appears again, the ring must degrade rather than wipe
itself. A silent permanent failure is the part that hurt.

## Scope

**In scope**:

- `_phys_json` in `get_hba_health.sh`: collect only the controller's own PHYs.
- The same narrowing in `get_hba_info.sh` and `get_metrics.sh`.
- A guard in `health.php` so duplicate indexes can never again wipe history.
- Fixture tests built from the reporter's bundle so this is regression-tested
  without an expander on the bench.

**Out of scope**:

- `get_phy_health.sh` / the PHY Health tab — already correct, see above. Do not
  "fix" it to match.
- Reporting expander PHYs as a feature (an expander's own link health is a real
  thing, and this plugin does not do it today; adding it is a separate plan).
- Any change to how a rate is rounded or displayed — that is plan 044 and it is
  not implicated here.

## Steps

### Step 1: ALREADY DONE — do not attempt, no hardware is available to you

This step was a hardware check, and it has been run on two boxes. Both answers
are recorded here so the executor does not need a controller:

```text
Reporter (issue #12, Dell HBA355i / SAS3816, has an expander):
    29 entries named phy-H:N      (the HBA's own PHYs)
    76 entries named phy-H:N:M    (PHYs on the expander)
    /tmp/hbav_health_c0.json → "Samples: 1" no matter how long the tab is open
    04-parsed/get_hba_health.json → "idx":7 appears 4 times (likewise 6, 5, 4, 3)

Maintainer (9400-16i + 9400-8i, no expander):
    32 entries named phy-H:N
     0 entries named phy-H:N:M
    both rings healthy — 9 samples spanning 12.83 h
```

That is the whole shape of the bug: the reporter's box has expander PHYs whose
names collapse onto the HBA's own PHY indexes; the maintainer's has none and is
unaffected. **Start at Step 2.**

### Step 2: Narrow the glob to the controller's own PHYs

In `_phys_json` (and the two siblings), skip any entry whose name has a second
colon after the host:

```bash
for p in /sys/class/sas_phy/phy-"${1}":*/; do
    [ -d "$p" ] || continue
    idx=$(basename "$p")
    # phy-H:N is this controller's own PHY; phy-H:N:M is a PHY on an expander
    # BEHIND it — a different device, with counters this controller does not
    # own and (measured) no counter values at all. Including them collapsed
    # four entries onto every index (issue #12).
    case "${idx#phy-}" in *:*:*) continue ;; esac
    idx=${idx##*:}
```

Apply the identical three lines in `get_hba_info.sh` and `get_metrics.sh`.

**Verify**: on an affected box, `bash get_hba_health.sh | tr ',' '\n' | grep -c
'"idx"'` equals the number of `phy-H:N` entries for that host, and every index
is unique:

```bash
bash scripts/get_hba_health.sh | tr ',' '\n' | grep -o '"idx":[0-9]*' | sort | uniq -d
```

**Expected**: no output.

### Step 3: Fixture test — build the sysfs tree in the test itself

**Do not look for the reporter's diagnostic bundle. It is not in this repo and
you do not need it** — the shape it proved is written out below, and the test
constructs its own tree from that shape.

First make the sysfs root overridable in `_phys_json`, following the convention
already used twice in the same file (`SYS_SCSI_HOST`, `SYS_PCI_ROOT`):

```bash
for p in "${SYS_SAS_PHY:-/sys/class/sas_phy}"/phy-"${1}":*/; do
```

Then add `tests/phys_json_test.sh`, modelled on the existing
`tests/health_sh_test.sh` (read it first — same `ok`/`bad` helpers, same
`sed`-out-the-function trick for testing one function of a script that would
otherwise run hardware commands at load).

The tree the test builds, matching the reporter's capture:

| Directory | Counter files | Represents |
| --- | --- | --- |
| `phy-0:0` … `phy-0:7` | populated, non-zero (e.g. `invalid_dword_count` = 5) | the HBA's own 8 PHYs |
| `phy-0:0:0` … `phy-0:0:7` | present but **empty** | expander PHYs — the collision |
| `phy-0:1:0` … `phy-0:1:3` | present but **empty** | a second expander, same collision |
| `phy-1:0` … `phy-1:3` | populated | a different controller — must not appear |

Each directory needs the five files `_phys_json` reads:
`invalid_dword_count`, `running_disparity_error_count`,
`loss_of_dword_sync_count`, `phy_reset_problem_count`, `negotiated_linkrate`.
The empty ones are created with `: > "$d/invalid_dword_count"` — an existing
but empty file is exactly what an expander PHY reports, and it is why the
phantoms all read as zero rather than being skipped.

Assertions, running `_phys_json 0` against that tree:

1. every emitted `"idx"` value is unique (`sort | uniq -d` is empty)
2. exactly 8 entries are emitted
3. the emitted indexes are 0–7
4. no entry carries a counter from host 1

**Verify**: the test FAILS before Step 2's filter (assertion 1 reports
duplicates and assertion 2 sees 20 entries) and PASSES after. Run it both ways
and say so in your report — a test that has never been seen red proves nothing.

### Step 4: The ring must degrade, not wipe

Even with Step 2, a future backplane could produce a duplicate index. The
damage is not the duplicate — it is that a duplicate silently destroys history
forever. In `health_ingest()`, a decrease may only be read as a counter reset
when the two samples can actually be paired:

```php
/* A duplicate index means the two samples cannot be paired PHY-for-PHY, so a
   "decrease" is not evidence of anything. Skip the reset check for that index
   rather than wiping a ring that may be hours old (issue #12). */
```

Implement by counting indexes per sample and skipping the comparison for any
index that is not unique in **both** samples. Do the same in `health_rates()`,
which builds `$oldByIdx` the same way.

**Verify**: unit tests in `tests/health_test.php`.

The duplicate-idx fixture must reproduce the real mechanism or it cannot fail:
the phantom entry goes **last** and carries a **higher** counter than the real
PHY (e.g. real `inv 0`, phantom `inv 4` — the reporter's actual shape). Without
the guard, the phantom's 4 is what survives the overwrite, the real PHY's 0 is
compared against it, and the ring is wiped. With the guard, both samples keep
their two entries.

A fixture where every duplicate reads zero passes with or without the fix.
**Mutation-test both new cases** — delete the guard line, watch them go red,
restore it — and record both outputs. A test never seen red proves nothing.

### Step 5: NOT YOURS — hardware confirmation by the maintainer

Recorded for whoever runs it on real hardware after this lands; **the executor
must not attempt it and must not block on it**:

```bash
rm -f /tmp/hbav_health_c*.json      # discard the poisoned rings
# open HBA Health, wait 60s, reload
php -r '$a=json_decode(file_get_contents("/tmp/hbav_health_c0.json"),true); echo "Samples: ".count($a).PHP_EOL;'
```

**Expected**: `Samples: 2` or more, and Link Integrity showing a rate rather
than "Not enough samples yet".

## Test plan

- The new fixture test covers the collector; `tests/health_test.php` covers the
  consumer guard. Neither needs hardware.
- Existing goldens must not move — the fix removes entries that only exist on
  expander boxes, and no fixture has any.
- `bash tests/run.sh` stays green.

## Done criteria

- [ ] (Step 1 — already done, see above; nothing for the executor)
- [ ] No emitted PHY index is duplicated, asserted against the bundle's real
      sysfs listing
- [ ] Expander PHYs appear in none of the three collectors' output
- [ ] `get_phy_health.sh` output is byte-identical before and after
- [ ] A duplicate index no longer wipes the health ring (unit test)
- [ ] A genuine counter decrease on a unique index still wipes it (unit test)
- [ ] (hardware confirmation — the maintainer's, not the executor's)
- [ ] `bash tests/run.sh` → `--- all pass ---`

## STOP conditions

- The drift check prints anything.
- Any change to `get_phy_health.sh` or `parse/storcli_phy.sh` — they are
  already correct and are the reference for why the SAS address, not the name,
  separates these devices.
- A fix that pairs HBA PHYs with expander PHYs in any order (see "Why not
  simply take the reporter's fix").
- Any golden file moves.
- The health ring's reset detection is weakened for *unique* indexes — a real
  driver reload must still invalidate history, or every rate afterwards is
  computed across a discontinuity.

## Maintenance notes

- **`/sys/class/sas_phy` holds two kinds of thing.** Any future code that reads
  it must decide which it wants. The name tells you (`phy-H:N` vs
  `phy-H:N:M`); so does `device_type` (`end device` vs `edge expander`); and
  the SAS address separates them positively, which is what the PHY tab uses.
  Three signals, all present in the bundle.
- **The reporter's diagnosis was correct about the damage and incomplete about
  the cause**, which is exactly the useful half to receive. The bundle is what
  made the cause findable — keep asking for it.
- An expander's own PHY health is genuinely interesting on a big backplane and
  this plugin currently ignores it. If that is ever wanted, it is a new list
  with its own identity (host + expander + phy), not a widening of this one.
