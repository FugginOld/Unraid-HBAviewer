# Plan 046: Get the drive type from the drive, not from the bus

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Base check (run first)**:
> `git merge-base --is-ancestor dev HEAD && echo BASE-OK || echo BASE-STALE`
> If BASE-STALE, `git rebase dev` first. Worktrees here are created from an
> older commit than `dev`, so expect BASE-STALE. The drift check below only
> covers in-scope files and **cannot** detect a stale base.
>
> **Drift check**:
> `git diff --stat b61be96..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/smart.sh source/usr/local/emhttp/plugins/hbaviewer/scripts/read_smart.sh source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php tests/run.sh`
> Expected output: **nothing**.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW — one derived field and one branch condition. No new hardware
  access, no new tool invocation.
- **Depends on**: none (plan 042 is already merged; this corrects it)
- **Category**: bug
- **Planned at**: `b61be96`, 2026-08-04
- **Issue**: https://github.com/FugginOld/Unraid-HBAviewer/issues/10

## Why this matters

Plan 042 added a **Type** column to the SMART tab and made `read_smart.sh`
report the transport it had already computed. It took that transport from
`lsblk -dno TRAN`. **That was wrong, and a reporter's hardware proved it.**

`jac2424`'s box, eight drives behind a SAS9207-8i:

```
NAME TRAN WWN                MODEL
sda  sas  0x5000cca0bbd78ec2 WDC WD80EDAZ-11TA3A0
sdb  sas  0x5000039ad8cbc631 TOSHIBA MG08ACA16TE
sde  sas  0x5000cca295e4ce17 WDC WD161KRYZ-01AGBB0
```

**Every one of those is a SATA drive**, and every one reports `TRAN=sas`.
That is correct kernel behaviour — a drive behind an LSI HBA is a SAS-attached
device via the SCSI transport layer whatever the drive itself is — but it means
`lsblk`'s `TRAN` answers "what bus is this on", not "what kind of drive is
this". For this plugin those are never the same question, because every drive
it cares about is behind a SAS HBA.

Two consequences, one cosmetic and one not:

1. **The Type column reads `SAS` for every SATA drive.** Plan 042 set out to
   stop mislabelling SATA drives and, in that column, introduced a new way to
   do it.
2. **The spin-up guard never fires.** `read_smart.sh` drops `-n standby` when
   `tran = sas`, on the sound reasoning that SAS log-page reads are
   electronics-only. A SATA drive taking that branch gets an ATA passthrough
   read with no standby guard, which can wake a sleeping array disk. This is
   **pre-existing** — the branch predates 042 — but 042's tests assert
   `sata → -n standby` and `usb → -n standby`, correct assertions about values
   that, behind a SAS HBA, never occur. The suite tests a reality the users
   do not have.

## The evidence that decides the fix

Two candidate signals were considered. The reporter's capture killed one and
confirmed the other.

**Rejected — sysfs `target_port_protocols`.** On his box the attribute is
empty:

```
/sys/class/sas_device/end_device-0:0/          target= initiator=
```

Do not build on it.

**Confirmed — smartctl's own output.** For a SATA drive behind the SAS HBA,
`smartctl -a` emits the **ATA attribute table** and **no SCSI fields at all**:

```
ID# ATTRIBUTE_NAME          FLAG     VALUE WORST THRESH TYPE      UPDATED  WHEN_FAILED RAW_VALUE
  1 Raw_Read_Error_Rate     0x000b   100   100   016    Pre-fail  Always       -       0
  2 Throughput_Performance  0x0004   127   127   054    Old_age   Offline      -       112
```

with `SATA Version is: SATA 3.2, 6.0 Gb/s` and `ATA Version is: ACS-2` in the
information section, and **no** `Elements in grown defect list` or
`Current Drive Temperature` line anywhere.

So the drive tells you what it is, through the vocabulary smartctl uses to
describe it. `parse/smart.sh` already matches both vocabularies — it just
throws away the knowledge of which one fired.

## Current state

### `scripts/parse/smart.sh` — it already knows, and discards it

```bash
TRAN="${1:-}"   # "sas" | "sata" | "" — from lsblk, injected by read_smart.sh
awk -v tran="$TRAN" '
```

The two vocabularies, and the fallback that merges them:

```awk
/Elements in grown defect list:/             { match($0,/:[ \t]*([0-9]+)/,m); defects=m[1] }
/Pending defect count:/                      { match($0,/count:[ \t]*([0-9]+)/,m); pending=m[1] }
/Non-medium error count:/                    { match($0,/:[ \t]*([0-9]+)/,m); nonmed=m[1] }
# ── SATA: attribute table (ID NAME FLAG VAL WORST THRESH TYPE UPD WHEN RAW) ───
NF>=10 && $1==5   && $2 ~ /Reallocated_Sector/ { sd=$10 }
NF>=10 && $1==9   && $2 ~ /Power_On_Hours/      { sp=$10 }
NF>=10 && $1==194 && $2 ~ /Temperature/         { st=$10 }
NF>=10 && $1==197 && $2 ~ /Current_Pending/     { spd=$10 }
END {
    if (temp    == "") temp    = st    # fall back to SATA attributes
    ...
    printf "{\"health\":\"%s\",…,\"transport\":\"%s\"}", …, tran
}
```

Every one of those rules is a positive identification of a bus. Nothing
records which fired.

### `scripts/read_smart.sh` — the branch that must not change meaning

```bash
tran=$(lsblk -dno TRAN "$dev" 2>/dev/null | tr -d ' \n')
if [ "$tran" = "sas" ]; then
    smartctl -a "$dev" 2>/dev/null | bash "$DIR/parse/smart.sh" "$tran"
else
    smartctl -n standby -a "$dev" 2>/dev/null | bash "$DIR/parse/smart.sh" "$tran"
fi
```

**The spin-up decision must be made before smartctl runs**, so it cannot use
smartctl's output. It has to keep using `lsblk`. That is fine and is not a
contradiction — see Step 2.

### `ajax_info.php` — the consumer, unchanged by this plan

`renderSmartTable()` renders `$s['transport']` upper-cased into the **Type**
column, or a muted dash when empty. It needs no change: this plan only makes
that field mean what the column claims.

### Repo conventions

- Parsers are pure filters over stdin plus composer-supplied positional args.
- Comments name the hardware that disproved an assumption. Model:
  `parse/storcli_overview.sh`'s PHY-error-floor comment citing issue #8.
- `tests/read_smart_test.sh` is the exemplar for stubbed shell tests, and
  `tests/health_test.php` for assert-and-echo PHP tests.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Full suite | `bash tests/run.sh` | ends `--- all pass ---` |
| Regenerate | `UPDATE=1 bash tests/run.sh` | `WROTE <name>` per case |
| Shell lint | `bash -n <file>` | exit 0 |

`UPDATE=1` rewrites every golden; revert unrelated trailing-newline churn.

## Scope

**In scope**:

- `source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/smart.sh`
- `source/usr/local/emhttp/plugins/hbaviewer/scripts/read_smart.sh`
- `tests/run.sh`, `tests/read_smart_test.sh`
- `tests/expected/smart_*.json` (regenerated)
- `tests/fixtures/smart/` (one new fixture)

**Out of scope** (do NOT touch):

- `ajax_info.php` — `renderSmartTable()` already renders whatever
  `transport` says. Changing it would mask whether this fix worked.
- The `defects`/`pending` field names and their SAS/SATA fallback merge.
  Plan 042 settled that deliberately: one field, two bus-specific metrics,
  both meaning "sectors the drive permanently retired".
- `collect_smart.sh`, including its `grep 'WWN="0x'` device filter.
- The **spin-up policy itself**. Which drives get `-n standby` is a safety
  decision with its own evidence requirements; this plan makes the *reported*
  type honest and records the guard's gap. See STOP conditions.

## Git workflow

- Branch: `advisor/046-drive-type-from-smart-vocabulary`
- One commit, imperative message matching `git log`.
- Do NOT push or open a PR.

## Steps

### Step 0: baseline

```
bash tests/run.sh 2>&1 | grep '^FAIL' | sort > /tmp/baseline-fails.txt
cat /tmp/baseline-fails.txt
```

Quote it. No later run may add a name.

### Step 1: derive the transport from the vocabulary that matched

In `parse/smart.sh`, set a flag in each vocabulary's rules and resolve it in
`END`. The injected `$1` becomes a **fallback**, not the source of truth.

Required behaviour:

- any SAS-vocabulary rule fires (`Elements in grown defect list`,
  `Pending defect count`, `Non-medium error count`, `Current Drive
  Temperature`, `Drive Trip Temperature`, `Accumulated power on time`) →
  transport is `sas`
- any ATA-attribute rule fires (ids 5 / 9 / 194 / 197) → transport is `sata`
- **neither** → fall back to the injected `$1` (a sleeping drive under
  `-n standby` produces almost no output; the bus is then the best guess
  available)
- **both** → prefer `sas`. A SAS drive's output does not contain an ATA
  attribute table, so this should not occur; preferring `sas` keeps it
  deterministic rather than order-dependent.

Comment it in the house style, naming the evidence: `lsblk`'s `TRAN` reports
the bus, a SATA drive behind a SAS HBA reads `sas`, and the drive's own
vocabulary is the honest signal — with the reporter's card cited.

**Verify**:
```
bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/parse/smart.sh && echo LINT-OK
P=source/usr/local/emhttp/plugins/hbaviewer/scripts/parse
# the bug: bus says sas, drive is SATA -> must now report sata
bash $P/smart.sh sas  < tests/fixtures/smart/sata_drive.txt | grep -o '"transport":"[^"]*"'
bash $P/smart.sh sata < tests/fixtures/smart/sata_drive.txt | grep -o '"transport":"[^"]*"'
bash $P/smart.sh sata < tests/fixtures/smart/sas_drive.txt  | grep -o '"transport":"[^"]*"'
bash $P/smart.sh      < tests/fixtures/smart/sas_drive.txt  | grep -o '"transport":"[^"]*"'
```
→ `"transport":"sata"`, `"transport":"sata"`, `"transport":"sas"`,
`"transport":"sas"` — in every case the **drive** wins over the argument.

### Step 2: keep the spin-up branch on lsblk, and say why

`read_smart.sh` must still choose its smartctl flags **before** running
smartctl, so it keeps reading `lsblk`. Do not change the branch condition.
Do change the comment, because the current one is now misleading — it implies
`tran` identifies the drive, and a reporter's SATA drives all read `sas`.

State plainly in the comment that:

- the branch is a **bus** decision made before any drive output exists;
- a SATA drive behind a SAS HBA reads `sas` here and therefore **does not get
  `-n standby`** — a known gap, deliberately not fixed by this plan;
- the value passed to `parse/smart.sh` is now only a fallback for when the
  drive says nothing.

**Verify**: `bash -n source/usr/local/emhttp/plugins/hbaviewer/scripts/read_smart.sh` → exit 0,
and `git diff -- source/usr/local/emhttp/plugins/hbaviewer/scripts/read_smart.sh`
shows **comment lines only** — no change to any `if`, `smartctl` or pipe.

### Step 3: a fixture for the case that started this

Create `tests/fixtures/smart/sata_behind_sas.txt`: a SATA drive's `smartctl -a`
output as produced behind a SAS HBA. Build it from the existing
`tests/fixtures/smart/sata_drive.txt` — it is already ATA-shaped, which is
exactly what the reporter's capture confirmed such a drive emits. Add the two
information-section lines his capture showed, so the fixture is recognisably
the HBA case:

```
SATA Version is:  SATA 3.2, 6.0 Gb/s (current: 6.0 Gb/s)
ATA Version is:   ACS-2, ATA8-ACS T13/1699-D revision 4
```

**Do not invent SMART values.** Copy the existing fixture's attribute rows
unchanged; the point of this fixture is the *shape*, and its expected
`transport` is `sata` no matter what argument is passed.

**Verify**:
```
grep -c 'Elements in grown defect list' tests/fixtures/smart/sata_behind_sas.txt   # -> 0
grep -cE '^ *(5|9|194|197) ' tests/fixtures/smart/sata_behind_sas.txt              # -> 4
```

### Step 4: tests

**`tests/run.sh`** — add, with a provenance comment:

```bash
# Real-world shape from issue #10 (@jac2424): a SATA drive behind a SAS9207-8i.
# lsblk calls it TRAN=sas — every one of his eight SATA drives did — so the
# composer passes "sas" here. The drive's own output is an ATA attribute table
# with no SCSI fields, and THAT is what must decide the reported type.
check smart-sata-behind-sas smart_sata_behind_sas.json bash "$P/smart.sh" sas < fixtures/smart/sata_behind_sas.txt
```

**`tests/read_smart_test.sh`** — its `sas`/`sata`/`usb`/empty cases still pass
(the branch is unchanged) but their names now overstate what they prove.
Rename or re-comment so each says it is asserting the **bus** decision and the
**fallback** argument, not the drive type. Add one case asserting that when
the stub returns ATA-shaped output under `STUB_TRAN=sas`, the emitted
`transport` is `sata` — the end-to-end version of this plan.

Then regenerate and read the diff:

```
UPDATE=1 bash tests/run.sh
git diff tests/expected/
```

**Expect `smart_sas.json` and `smart_sata.json` to be unchanged** — those
fixtures' vocabularies already agree with the arguments they are passed.
`smart_notran.json` **changes**: it feeds `sata_drive.txt` with no argument,
which previously yielded `""` and must now yield `"sata"`. That is the fix
working. Any other pre-existing golden changing is a STOP condition.

```
bash tests/run.sh 2>&1 | grep '^FAIL' | sort > /tmp/after-fails.txt
diff /tmp/baseline-fails.txt /tmp/after-fails.txt && echo "NO NEW FAILURES"
```

## Test plan

- New golden `smart-sata-behind-sas`: `transport` is `sata` despite `sas` in.
- Changed golden `smart_notran.json`: `""` → `"sata"`.
- New `read_smart_test.sh` case: ATA-shaped stub output under `STUB_TRAN=sas`
  yields `"transport":"sata"`.
- **Mutation check** — run each, confirm, restore, **report all four**:
  1. Make the injected `$1` win over the detected vocabulary → the
     `smart-sata-behind-sas` golden and the new `read_smart_test.sh` case must
     both fail.
  2. Delete the SAS-vocabulary detection so everything looks SATA →
     `smart-sas` must fail.
  3. Delete the ATA-attribute detection → `smart-sata-behind-sas` and
     `smart_notran` must fail.
  4. Change `read_smart.sh`'s branch to drop `-n standby` for every drive →
     report whether `read_smart_test.sh` catches it. It should: the `sata`
     and `usb` cases assert `-n standby` is present.

## Done criteria

- [ ] `bash -n` clean on both shell files
- [ ] `bash $P/smart.sh sas < tests/fixtures/smart/sata_drive.txt` reports
      `"transport":"sata"` — the drive overrides the bus
- [ ] `bash $P/smart.sh sata < tests/fixtures/smart/sas_drive.txt` reports
      `"transport":"sas"`
- [ ] `bash $P/smart.sh < tests/fixtures/smart/sas_drive.txt` (no argument)
      reports `"transport":"sas"`
- [ ] `git diff -- .../read_smart.sh` shows comment-only changes
- [ ] `git diff tests/expected/` shows `smart_notran.json` changed (`""` →
      `"sata"`) and no other pre-existing golden changed
- [ ] `bash tests/run.sh` adds no failure name absent from the Step 0 baseline
- [ ] `git status --short` lists only in-scope files

## STOP conditions

- Base check BASE-STALE and `git rebase dev` conflicts.
- The drift check prints anything.
- Any pre-existing golden other than `smart_notran.json` changes.
- `git diff` on `read_smart.sh` shows anything other than comments.
- **You decide to fix the spin-up guard.** It is a real gap and this plan says
  so out loud, but changing which drives get `-n standby` alters when a
  sleeping array disk is woken. That needs its own evidence — specifically,
  whether an ATA passthrough read through the SAS layer actually spins a
  standby drive up, which nobody here has measured. Record it; do not change
  it.
- You are tempted to make the transport a third state like `sata-behind-sas`.
  The Type column answers "what kind of drive is this". Two values plus empty.

## Maintenance notes

- **`transport` now means "what the drive says it is", not "what bus it is
  on".** Those differ for every SATA drive behind a SAS HBA, which is most of
  this plugin's audience. Anything added later that wants the *bus* must read
  `lsblk` itself and must not reuse this field.
- **The injected argument survives as a fallback only.** A drive asleep under
  `-n standby` emits almost nothing, so neither vocabulary fires and the bus
  is the best guess left. That is why `read_smart.sh` still passes it.
- **The spin-up guard gap is open and deliberate.** `read_smart.sh` drops
  `-n standby` whenever `lsblk` says `sas`, which includes every SATA drive
  behind an HBA. Closing it needs a measurement nobody has taken: does the
  ATA passthrough read actually wake a standby drive? If a user ever reports
  drives spinning up when the SMART tab is opened, that is this gap and the
  evidence arriving together.
- **This is the second correction to plan 042's transport work.** 042 assumed
  `lsblk TRAN` distinguished drive types; it distinguishes buses. The lesson
  worth keeping is that the fixtures agreed with the assumption because
  nobody had captured a SATA drive *behind an HBA* until issue #10.
