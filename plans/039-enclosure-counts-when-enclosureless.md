# Plan 039: Stop reporting "0 drives" above a table of 15 drives

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat 2846637..HEAD -- source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php tests/ajax_render_test.php`
> Expected output: **nothing**. Every excerpt below is quoted from `2846637`
> (`dev` tip, 2026-08-02). Any difference is a STOP condition.

## Status

- **Priority**: P3 — cosmetic, but it reads as a bug to the person looking at it
- **Effort**: S
- **Risk**: LOW. One rendered line on one tab. No parser, no hardware, no JSON
  contract change.
- **Depends on**: none
- **Category**: display correctness
- **Planned at**: `2846637`, 2026-08-02
- **Found**: while closing issue #6, from @iassis's real output

## Why this matters

On an enclosure-less controller the Drives tab now renders, truthfully and
confusingly:

```
Enclosure e0: VirtualSES (BROADCOM) · 8 slots · 0 drives · direct-attach (no expander)
```

directly above a table listing **15 drives**.

Every part of that is accurate. storcli really does report a synthesised
`VirtualSES` with 8 slots and 0 drives attached *to the enclosure*, because on
this controller the drives are addressed `/c0/s0…` with a blank `EID` — they
hang off the HBA, not off that enclosure (this is the whole subject of plan
017). But a user reads "0 drives" next to fifteen rows of drives and files a
bug, or worse, distrusts the rest of the tab.

This is the second time this exact line has misled someone. Plan 017 fixed the
case where the counts were *invented* from an empty Properties section; this is
the case where they are **real and still wrong to show**.

## Current state

### `ajax_info.php:432-444` — the whole of the change

```php
        // Enclosure/topology summary (storcli). VirtualSES = direct-attach, no expander.
        foreach ($ctl['enclosures'] ?? [] as $e) {
            $mode  = !empty($e['direct']) ? 'direct-attach (no expander)' : 'expander / backplane';
            // Only state a slot/drive count when storcli actually reported one —
            // an empty Properties section previously rendered as "8 slots / 0 drives"
            // on a controller with 15 drives.
            $counts = ($e['slots'] ?? '') !== '' && ($e['drives'] ?? '') !== ''
                ? htmlspecialchars($e['slots']) . ' slots &middot; ' . htmlspecialchars($e['drives']) . ' drives &middot; '
                : '';
            $out .= '<p class="lu-muted" style="font-size:12px;margin:0 0 8px">Enclosure e' . htmlspecialchars($e['eid'])
                  . ': ' . htmlspecialchars($e['product']) . ' (' . htmlspecialchars($e['vendor']) . ') &middot; '
                  . $counts . $mode . '</p>';
        }

        $drives = $ctl['drives'] ?? [];
```

Note the existing guard already suppresses counts when storcli reported none.
This plan adds a second reason to suppress them.

### The signal is already in the data — do not add a parser field

`parse/storcli_drives.sh` emits `slot` as `"0/12"` (`eid/slot`) when the drive
carries an enclosure ID, and as bare `"12"` when it does not:

```awk
(eid == "" ? slot : eid"/"slot), port, model, sn, state, wwn, size, link, fw
```

So **"is this controller's drive list enclosure-less?"** is answerable from the
drives array alone: no `/` in any drive's `slot`. No new JSON field, no parser
change, no second source of truth.

### Real data this must handle — @iassis's SAS3416, issue #6

```json
{"enclosures":[{"eid":"0","type":"SES","vendor":"BROADCOM","product":"VirtualSES",
  "slots":"8","drives":"0","state":"OK","direct":true}],
 "drives":[{"slot":"0",…},{"slot":"1",…}, … 15 of them ]}
```

## Scope

**In scope**: `source/usr/local/emhttp/plugins/hbaviewer/ajax_info.php` (the
block above) and `tests/ajax_render_test.php`.

**Out of scope — do not touch**:

- Any parser, especially `parse/storcli_enclosures.sh` and
  `parse/storcli_drives.sh`. The `slots`/`drives`/`direct` fields keep their
  current meaning; this is a *display* decision, not a data one.
- The `direct` flag as the trigger. **It is the wrong signal** — plan 024 v2
  established that `VirtualSES`/`direct:true` says nothing about how drives are
  addressed, and the maintainer's own box is `direct:true` with every drive
  carrying an `eid`. Keying on `direct` here would suppress counts on hardware
  where they are correct and useful.
- The drive table itself, its columns, and the `No drives detected.` branch.
- The lsiutil branch — it renders no enclosure summary at all.

## Steps

### Step 1: decide, once per controller, before the enclosure loop

```php
// storcli_drives.sh emits "eid/slot" when a drive carries an enclosure ID and a
// bare "slot" when it does not. If NO drive on this controller carries one, the
// enclosure's own slot/drive counts describe something the drives aren't
// attached to — showing "0 drives" above 15 rows reads as a bug (issue #6).
$dl = $ctl['drives'] ?? [];
$enclLess = $dl !== [] && !array_filter($dl, fn($d) => str_contains((string) ($d['slot'] ?? ''), '/'));
```

Three deliberate properties, each pinned by a test:

- **`$dl !== []`** — with no drives at all there is nothing to contradict, so
  the counts stay. Absence of evidence is not evidence.
- **`!array_filter(…)`** — *no* drive carries an eid. A mixed controller keeps
  its counts, because some drives really are behind that enclosure.
- Computed from `$ctl['drives']`, the same array the table below renders, so
  the line and the table can never disagree.

### Step 2: suppress the counts and say why

Extend the existing condition, and add a short clause in their place so the
line still explains itself:

```php
$counts = !$enclLess && ($e['slots'] ?? '') !== '' && ($e['drives'] ?? '') !== ''
    ? htmlspecialchars($e['slots']) . ' slots &middot; ' . htmlspecialchars($e['drives']) . ' drives &middot; '
    : '';
```

and append, when `$enclLess`, ` &middot; drives are addressed without an enclosure`
after `$mode`.

Target rendering for issue #6's box:

```
Enclosure e0: VirtualSES (BROADCOM) · direct-attach (no expander) · drives are addressed without an enclosure
```

Keep it one line and keep the existing `lu-muted` / 12px styling — this is a
subtitle, not a warning. **Do not** add a colour, an icon, or a second
paragraph.

## Test plan

`tests/ajax_render_test.php`, beside the existing storcli drives case at
line 125, which is already the regression net for the *unchanged* direction —
its fixture uses `'slot'=>'8/0'`, so its `drives enclosure summary` assertion
must keep passing untouched. **If that case moves, you have broken the normal
enclosure-attached rendering.**

Add one new case, from issue #6's real shape:

```php
$drvNoEncl = ['backend' => 'storcli', 'controllers' => [[
    'enclosures' => [['eid'=>'0','product'=>'VirtualSES','vendor'=>'BROADCOM',
                      'slots'=>'8','drives'=>'0','direct'=>1]],
    'drives' => [['slot'=>'0','port'=>'8','model'=>'ST26000NM','serial'=>'ZXA069R6',
                  'state'=>'JBOD','sas_address'=>'5000C500EA001805','size'=>'23.647 TB',
                  'link'=>'12.0Gb/s','firmware'=>'SN02']],
]]];
```

Assert:

- the rendered line does **not** contain `8 slots` or `0 drives`
- it **does** contain `VirtualSES` and `direct-attach`
- it contains the new clause
- the drive row still renders (the table is untouched)
- a **mixed** controller (one drive `'0/1'`, one `'2'`) **keeps** its counts
- a controller with an enclosure and an empty `drives` array keeps its counts

## Done criteria

- [ ] Issue #6's shape renders with no `8 slots` / `0 drives` and the new clause
- [ ] The existing `drives enclosure summary` case at line 131 passes unmodified
- [ ] Mixed and empty-drives cases keep their counts
- [ ] `grep -c "direct" ajax_info.php` unchanged from before the edit — the
      `direct` flag's uses must not grow
- [ ] `php -l` clean; `bash tests/run.sh` → `--- all pass ---`
- [ ] `git diff --name-only` lists only `ajax_info.php`, `tests/ajax_render_test.php`, `plans/`
- [ ] `git diff -- tests/expected/` empty

## STOP conditions

- The drift check prints anything.
- Any file under `scripts/` appears in the diff.
- The suppression keys on `direct`, `product`, or the enclosure's own counts
  rather than on the drives' `slot` format.
- The existing storcli drives test needed editing to pass.

## Maintenance notes

- **This is the third distinct bug in one 12px line.** Plan 017 fixed invented
  counts; this fixes real-but-irrelevant counts. If a fourth arrives, consider
  whether the enclosure summary should state what it is *for* — "these drives
  are behind an expander" vs "these drives hang off the HBA" — rather than
  reciting fields storcli happened to report.
- **`direct` and "enclosure-less" are independent.** `direct` describes the
  enclosure's product string; enclosure-less describes how the drives are
  addressed. The maintainer's box is `direct:true` **and** enclosure-attached.
  Anyone conflating them will reintroduce this.
