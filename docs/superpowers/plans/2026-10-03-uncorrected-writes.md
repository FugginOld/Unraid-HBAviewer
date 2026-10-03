# Uncorrected write errors on the Diagnose Verdict screen

> **Status: sandbox green, reviewed. Parsing VERIFIED ON GOLEM, 2026-10-03:** each of the 10 awake SAS disks had exactly one `write:` row with a numeric last field (all 0). The SATA SSD `sdx` (SATA 3.2, woken for the check with one 4 KiB direct read) had no `write:` row, so `wuncorr` is absent. The USB flash `sdz` was also absent. 14 sleeping disks were skipped and not woken. **Not verified on hardware:** a non-zero count (no Golem disk has one; test block O covers it), and the Verdict card on a deployed build.

> **As built — deviation from the steps below:** `#diag-counters` (step 3) is on the *live* screen, and `luDiagShow('verdict')` hides it. The Verdict screen is rendered server-side and shows only `diag_evidence_cards()`. So the lifetime count is also added to the Counter movement card's result (` · lifetime uncorrected writes: N`). It appears only when a `wuncorr` event exists, so SATA gets no suffix, and the card count stays at three. Found in review.

## Context

Spec follow-up (`docs/superpowers/specs/2026-09-28-disk-utility-repair-design.md` §"Follow-ups outside this phase"): Unraid disables a disk when a write fails. The drive records these in the SCSI error counter log (page 0x02), which `smartctl -x` prints as the `write:` row. `snap()` tracks only the `read:` row (`uncorr`). If `snap()` also tracks uncorrected writes, the Verdict screen can show the lifetime count. That answers "why did Unraid disable this disk" even when the read-only triage finds nothing. The change is read-only and engine-side, with no new mutating path.

## Decisions

- **Key name `wuncorr`, not `uncorr_write`.** `triage_disk()` filters snap output through `^[a-z]+=[0-9]+$` (drive_triage.sh:805, :902), which rejects underscores. That filter also guards the unescaped key in `tri_counter`'s JSON, so the filter stays as it is.
- **It counts as a media counter.** It is the drive failing to write its own media after retries, which happens after the data has crossed the link. Today the PHP evidence card counts *any* key outside `['grown','uncorr']` as **path** (render/diagnose.php:79). Leaving it out would silently mislabel it as a cable fault, so it is added to the media list in all three places (bash `dmedia`, PHP card, JS `luDiagInterp`).
- **SAS only, and absent rather than zero on SATA.** SATA has no `write:` row, so the value is empty, the existing filter drops it, and the UI shows `—`. No SATA substitute is invented.
- **Out of scope:** the slot-scan table (21-column `$DATA`, 5 readers), the `$STATE` baseline schema, and verdict classification. A lifetime count shown on the Verdict screen is what the spec asks for. Add it to the slot scan only if a DISABLED row needs it there too.

## Steps (TDD: failing test → minimal change → mutation-check)

0. Copy this plan to `docs/superpowers/plans/2026-10-03-uncorrected-writes.md` (repo convention).

1. **Engine.** In `source/usr/local/emhttp/plugins/hbaviewer/scripts/drive_triage.sh`:
   - `snap()` (≈:742): add `echo "wuncorr=$(awk '/^write:/ {print $NF}' "$S" | head -1)"`. Empty output on SATA is intended, so no `|| echo 0`.
   - The `dmedia` case (≈:920): `uncorr|grown|wuncorr)`.
   - Test in `tests/drive_triage_test.sh`, new block after L, following the J/K harness:
     - Write a test-local copy of `fixtures/smart/sas_drive.txt` with only the `write:` row's last field set to `5`, using sed into `$WORK`. The fixture itself is not edited. Set `STUB_SMART` to the copy and put a FAILED line in dmesg so triage runs. Run `--auto-triage --all --events "$EV"`.
     - Assert `"key":"wuncorr","before":5,"after":5`. This discriminates: reading the `read:` row, or the first numeric field, gives 0.
     - Assert the `uncorr` event still shows `"before":0`, so the read path is unchanged.
     - With `STUB_SMART=fixtures/smart/sata_drive.txt`, assert there is no `"key":"wuncorr"`. First confirm that the run actually reaches triage, i.e. that other counter events are present.
   - Mutation checks: `^write:` → `^read:` must fail the first assert, and deleting the line must fail it too.

2. **PHP Verdict card.** `source/.../render/diagnose.php`: change `:79` to `['grown','uncorr','wuncorr']`. In the `:103` detail text, change "uncorrected reads" to "uncorrected reads and writes".
   - Test in `tests/diagnose_render_test.php`: a `wuncorr` 0→2 event reads `media +2 · path +0`. Mutation: removing it from the list gives `path +2`, which fails.

3. **JS live panel.** `source/.../diagnose_view.js`:
   - `drawCounters` keys (:139): add `['wuncorr', 'Uncorrected writes']` after `uncorr`.
   - `luDiagInterp` (:69): add `(d.wuncorr || 0)` to `media`.
   - Tests in `tests/diagnose_js_test.js`: `I({wuncorr:1, grown:0, uncorr:0, disp:0, invdw:0, loss:0})` matches `/MEDIA/`. After `A({t:'counter', key:'wuncorr', before:3, after:3})`, `diag-counters` contains `Uncorrected writes`. Mutation-check both.

4. **Docs.**
   - `hbaviewer.php:195`: add the new counter to the comment.
   - `HOWTO.md` Diagnose section: one sentence saying that the Verdict screen shows the drive's lifetime uncorrected writes (SAS only), which is why Unraid disables a disk.
   - In the spec's follow-up bullet, mark it done and point to this plan.

5. **Review.** Send the diff to `reviewer`. `drive_triage.sh` is read-only here, so check `docs/review-policy.md` for any protected-path entry on it first.

## Verification

- Sandbox: `bash tests/run.sh` must end with all suites passing (drive_triage, diagnose_js, run_php). Each new assert must be shown to fail under its mutation.
- Hardware (Golem): this is a separate block, posted one at a time after the sandbox is green. Run a web Diagnose on one awake SAS disk and one SATA disk.
  - Pass: the SAS job's `events.ndjson` has a `wuncorr` event whose value equals `smartctl -x -d auto -n standby /dev/sdX | awk '/^write:/{print $NF}'`.
  - Pass: the SATA job has no `wuncorr` event.
  - Pass: the Verdict screen's Counter movement card ends with `lifetime uncorrected writes: N` for SAS, and has no such suffix for SATA.
