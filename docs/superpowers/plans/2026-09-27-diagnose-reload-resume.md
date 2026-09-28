# Fix round for Task 16 Block E: reattach the Live view to a running Diagnose job after a reload

Parent plan: `docs/superpowers/plans/2026-09-21-disk-utility-diagnose.md`, Task 16, Step 3, Block E.
Spec promise being honoured: `docs/superpowers/specs/2026-09-21-disk-utility-diagnose-design.md:100`
("reopening the tab mid-scan resumes the live view ... instead of starting over") and `:221-222`.

This is a **fix round**, not a new task. It follows the `413864f` precedent: one scoped commit
titled `Fix round for task 16: ...` on `disk-utility-diagnose`, a ledger entry in
`.superpowers/sdd/2026-09-21-disk-utility-diagnose/progress.md` (gitignored, local only), a scoped
`reviewer` pass, a merge into `dev`, then the hardware Block E check re-run. The parent plan file
is **not** edited by this round (the precedent did not edit it either); its status header is still
written by Task 16 Step 4 once Blocks E and F both pass.

## Decisions already made (executor does not revisit these)

- **No server change.** `diagnose.php`'s `list` action (`:133-146`) already returns
  `{jobs:[{job,disk,mtime,running}]}`, newest first, with `running` from the shared
  `diag_job_running()`. It has no caller today. The client calls it; nothing in `hbaviewer.php`
  changes and `var luDiagJob = '';` (`hbaviewer.php:265`) stays as is.
- **Where it's called: once, at the end of `diagnose_view.js`'s IIFE, on page load.** Not from
  `luTab()`. Reasons: (1) `hbaviewer.js:1185` runs `luTab(urlTab)` for `?tab=diagnose` *before*
  `diagnose_view.js` has loaded, so a hook in `luTab` misses that path; (2) `luTab()` never touches
  `st`, the EventSource or `luDiagJob`. Its only Diagnose action is `luDiagDrives()`
  (`hbaviewer.js:57`), so a stream opened at load survives every tab switch, the same way a
  job the page started itself does today. No change to `hbaviewer.js`.
- **Which job:**
  - zero running: do nothing, so the idle markup stays;
  - exactly one running: attach and replay from offset 0, which brings the map back whole;
  - two or more running: attach to none. The header names the count and the `/dev/` names, and
    `luDiagJob` stays `''`, so Cancel has no target. Picking one would put Cancel on a disk
    the user did not choose.
- **Page-owned job wins.** The `luDiagJob` check runs inside the `.then`, after the list returns,
  not before the fetch. A `luDiagnose()` start or `luDiagOpen()` that lands while the list is in
  flight is what the user asked for.
- **Reuse, not duplicate.** Extract the post-confirm block of `luDiagnose` into a private
  `attach(job, disk)` and use it from both paths.
- **A job that finished while away is not auto-opened on the Verdict screen.** On a fresh load
  the sidebar badge already shows its verdict, and auto-opening the newest finished run would
  hijack every page load with a possibly days-old result. The race where a job finishes after
  `list` but before the stream connects is already handled: the replay contains the `verdict`
  event, and `luDiagApply` switches screens.

## Constraints

- Every existing check in `tests/diagnose_js_test.js` passes unchanged. This covers the refused
  start that leaves job A's stream and view untouched, the second start that closes the first
  EventSource, and the pause/resume and `end` handler checks.
- `luDiagnose()` still closes the previous stream only after the start is confirmed.
- No new endpoint, no new data shape, no new dependency, no new file under `source/`.
- `render/` file count unchanged (still 9), so `docs/install-verify.sh` needs no edit.
- ES5 style in `diagnose_view.js` (`var`, `function`, no arrows), matching the file.
- `bash tests/run.sh` baseline before this round: exactly two FAIL lines, both in
  `config_test.php` (`shell and PHP agree on the ALERT_THRESHOLD default`,
  `the default is a real band floor`). This is a known Windows-sandbox interop issue that
  predates this round. It must stay exactly those two.

## Steps

All source edits happen in the feature worktree. Set once per shell:
`W=/c/Users/Joe/Documents/GitHub/Unraid-HBAviewer/.worktrees/disk-utility-diagnose`
(clean, on `disk-utility-diagnose` @ `b422e9f`). The main checkout
`/c/Users/Joe/Documents/GitHub/Unraid-HBAviewer` is on `dev` and is only touched in Step 7.

1. **Extract `attach()` from `luDiagnose` (pure refactor).** File:
   `$W/source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js`.
   - Insert this function immediately above the `/* The disk NAME, never a /dev path:` comment
     that precedes `window.luDiagnose`:
     ```js
         /* Point both screens at one job and reset the Live view for it. Shared
            by a fresh start (luDiagnose) and a reattach after a reload
            (luDiagResume), so the two cannot drift on what a running job
            enables. Closes any stream it replaces; the caller logs and opens
            the new one. */
         function attach(job, disk) {
             if (st.es) { st.es.close(); st.es = null; }
             st = freshState();
             el('diag-map').innerHTML = '';
             el('diag-stream').innerHTML = '';
             el('diag-hotzone').textContent = '';
             el('diag-head').innerHTML = 'Diagnosing <code>/dev/' + fesc(disk) + '</code>';
             el('diag-pause').textContent = 'Pause';
             luDiagJob = job;
             el('diag-dot').classList.add('running');
             el('diag-dot').setAttribute('aria-label', 'Job running');
             el('diag-pause').disabled = false;
             el('diag-cancel').disabled = false;
         }
     ```
   - In `luDiagnose`'s success branch, replace everything from the comment
     `/* Close out the PREVIOUS job's stream only now ...` through `openStream();` (currently
     lines 292-311) with:
     ```js
                 /* Close out the PREVIOUS job's stream only now that a NEW job is
                    confirmed started -- attach() does the closing. Tearing the
                    view down before knowing the start succeeded left a refused
                    start (e.g. the per-disk lock rejecting a double-click) with a
                    blanked view of a job that was still running. */
                 attach(d.job, disk);
                 logLine('job ' + d.job + ' started', 'ok');
                 openStream();
     ```
     Leave the `luDiagDrives()` call and its comment below it untouched.
   - Test: existing suite, no new checks. `node $W/tests/diagnose_js_test.js | tail -1` must print
     `diagnose_js: all pass`.

2. **Write the failing tests.** File: `$W/tests/diagnose_js_test.js`.
   - Directly after `vm.runInContext(fs.readFileSync(SRC, 'utf8'), sandbox);` add:
     ```js
     // The bug Block E found: nothing on page load asked whether a job was
     // already running, so a reload always showed "No job running".
     check('loading the page asks diagnose.php for the job list once',
           fetches.length === 1 && fetches[0].url.includes('action=list'));
     ```
   - At the end of `async function tail()`, after the last `end`-handler check, add:
     ```js
         /* ── reattach after a reload (Task 16 Block E fix round) ─────────── */
         const idleHead = '<span class="lu-muted">No job running.</span>';
         const resumeCase = async (jobs, pageJob) => {
             fetches.length = 0;
             esInstances.length = 0;
             sandbox.luDiagJob = pageJob || '';
             els.get('diag-head').innerHTML = idleHead;
             els.get('diag-dot').classList.remove('running');
             els.get('diag-cancel').disabled = true;
             fetchResponse = { jobs };
             await sandbox.luDiagResume();
         };

         await resumeCase([{ job: 'sdq-300', disk: 'sdq', mtime: 300, running: false }]);
         check('resume asks diagnose.php for the job list',
               fetches.length === 1 && fetches[0].url.includes('action=list'));
         check('no running job: no EventSource is opened', esInstances.length === 0);
         check('no running job: the idle header is left alone',
               els.get('diag-head')._html === idleHead);
         check('no running job: nothing is attached', sandbox.luDiagJob === '');

         // The newest job is a FINISHED one: an implementation that takes
         // jobs[0] instead of the running one attaches to the wrong job.
         await resumeCase([{ job: 'sdr-400', disk: 'sdr', mtime: 400, running: false },
                           { job: 'sdq-300', disk: 'sdq', mtime: 300, running: true }]);
         check('one running job: the view attaches to it, not to the newest job',
               sandbox.luDiagJob === 'sdq-300');
         check('one running job: one EventSource, for that job, from offset 0',
               esInstances.length === 1 && esInstances[0].url.includes('job=sdq-300')
               && esInstances[0].url.includes('offset=0'));
         check('one running job: the header names its disk',
               els.get('diag-head')._html.includes('/dev/sdq'));
         check('one running job: the dot runs and Cancel is live',
               els.get('diag-dot').classList.contains('running')
               && els.get('diag-cancel').disabled === false);
         esInstances[0].onmessage({
             data: JSON.stringify({ t: 'chunk', lba: 0, n: 64, ms: 5, ok: true }),
             lastEventId: '60',
         });
         check('one running job: replayed events redraw the map',
               els.get('diag-map')._html.includes('lu-diag-cell'));

         await resumeCase([{ job: 'sds-500', disk: 'sds', mtime: 500, running: true },
                           { job: 'sdq-300', disk: 'sdq', mtime: 300, running: true }]);
         check('two running jobs: no EventSource is opened', esInstances.length === 0);
         check('two running jobs: nothing is attached, so Cancel has no target',
               sandbox.luDiagJob === '' && els.get('diag-cancel').disabled === true);
         check('two running jobs: the header names both disks',
               els.get('diag-head')._html.includes('/dev/sds')
               && els.get('diag-head')._html.includes('/dev/sdq'));

         await resumeCase([{ job: 'sdq-300', disk: 'sdq', mtime: 300, running: true }], 'sdz-1');
         check('a job the page already holds is not replaced by a resume',
               sandbox.luDiagJob === 'sdz-1' && esInstances.length === 0);
     ```
   - Test (red): `node $W/tests/diagnose_js_test.js | grep -E '^FAIL|FAILURES|TypeError' | head -5`
     must show `FAIL  loading the page asks diagnose.php for the job list once` and end in
     `diagnose_js: FAILURES` (a `TypeError: sandbox.luDiagResume is not a function` also appears).
     If it passes, stop. The tests are not discriminating.

3. **Pin the `list` shape the client now depends on.** File: `$W/tests/diagnose_php_test.php`.
   Immediately after the `'status and list both use the shared per-job running check'` check, add:
   ```php
   // diagnose_view.js's reload-resume reads list's job/disk/running keys and
   // attaches only when exactly one job reports running. Renaming a key here
   // turns resume back into the idle view, silently.
   check('list reports job, disk and per-job running for the reload-resume',
         str_contains($dispatchCode, "\$jobs[] = ['job' => \$job, 'disk' => \$disk,")
         && str_contains($dispatchCode, "'running' => \$running]"));
   ```
   This is a contract pin, not a red test. It passes before and after, because `diagnose.php` is
   not edited. Test: `php $W/tests/diagnose_php_test.php | tail -1` must print
   `diagnose_php: all pass`.

4. **Implement `luDiagResume` and call it on load.** File: `diagnose_view.js`.
   - Insert immediately after the closing `};` of `window.luDiagDrives` and before the final
     `})();`:
     ```js
         /* Reattach after a reload. The event file is the source of truth, so a
            page that finds a running job replays it from offset 0 and the map
            comes back whole. Exactly one running job attaches. With several
            (the lock is per DISK, so different disks can run at once) the Live
            screen, which shows one job, names them and attaches to none --
            picking one would put Cancel on a disk nobody chose. The luDiagJob
            check sits inside the .then, not before the fetch: a start or a
            reopened verdict that lands while this is in flight wins. Called
            once, on load, not from luTab(): luTab() leaves st and the stream
            alone, and hbaviewer.js runs luTab(?tab=) before this file loads. */
         window.luDiagResume = function () {
             return fetch('/plugins/hbaviewer/diagnose.php?action=list')
               .then(function (r) { return r.json(); })
               .then(function (d) {
                 if (luDiagJob) return;
                 var run = ((d && d.jobs) || []).filter(function (j) { return j.running === true; });
                 if (run.length === 1) {
                     attach(run[0].job, run[0].disk);
                     logLine('reattached to running job ' + run[0].job, 'ok');
                     openStream();
                 } else if (run.length > 1) {
                     el('diag-head').innerHTML = '<span class="lu-muted">' + fesc(run.length
                         + ' jobs running (' + run.map(function (j) { return '/dev/' + j.disk; }).join(', ')
                         + '). The live view follows one job at a time, so it is attached to none of them;'
                         + ' each keeps running and reads SCANNING in the drive list.') + '</span>';
                 }
               })
               .catch(function () { /* the idle view is already the right fallback */ });
         };

         luDiagResume();
     ```
   - In the file's header comment (line 10), replace the exact substring
     `life, not a resume across a reload.` with
     `life; luDiagResume() covers a reload by replaying the file from 0.`
     Leave the rest of that line (` The browser's automatic EventSource`) and every other header
     line unchanged.
   - Test (green): `node $W/tests/diagnose_js_test.js | tail -1` must print `diagnose_js: all pass`.
     Also run `node $W/tests/diagnose_js_test.js | grep -c '^FAIL'`. It must print `0`.
   - Mutation check (do it, then revert): change `run.length === 1` to `run.length >= 1`. The two
     `two running jobs:` checks must FAIL. Separately, change `run[0]` (all three) to
     `d.jobs[0]`. The `one running job:` checks must FAIL. Restore the original after each and
     confirm green again. `git -C $W diff --stat` must show only the 3 files from Steps 1-4.

5. **Docs.**
   - `$W/HOWTO.md`, `## Diagnose`, paragraph `**The job outlives the tab.**`. Replace the four
     sentences from `While the *same* Live Job tab stays open,` through
     `already running.` with:
     ```markdown
     While the Live Job view stays open, it resumes on its own after any
     connection drop — an automatic browser-level reconnect carries the last
     position back to the server, with no action needed from you. Reloading the
     page, or opening HBAviewer fresh, re-attaches the Live view to a running
     job and replays it from the start, so the surface map shows everything
     scanned before the reload (the elapsed-time readout restarts from the
     reload). That needs exactly one job running: with jobs on two or more
     drives at once, the header names them and the view attaches to none rather
     than pick one.
     ```
     Leave the rest of that paragraph (starting `The drive's badge in the sidebar`) unchanged.
   - `$W/docs/superpowers/specs/2026-09-21-disk-utility-diagnose-design.md`, `### Streaming to the
     browser`. Append this sentence to the end of the paragraph that ends
     `rule.` (line ~103), on a new line within the same paragraph:
     ```markdown
     A fresh page finds the running job through `diagnose.php`'s `list` and
     replays it from offset 0. The lock is per disk, so two disks can run at
     once, and the Live screen shows one job: with more than one running, it
     names them and attaches to none.
     ```
   - `ARCHITECTURE.md`: no change. Its `diagnose.php` row already lists `list`.
   - Test: `grep -n "no way to re-attach" $W/HOWTO.md` must print nothing, and
     `grep -c "attaches to none" $W/HOWTO.md $W/docs/superpowers/specs/2026-09-21-disk-utility-diagnose-design.md`
     must print `1` for each file.

6. **Full verification and commit on the feature branch.**
   - `cd $W && bash tests/run.sh 2>&1 | grep -E '^FAIL'` must show exactly the two
     `config_test.php` lines named under Constraints and nothing else.
     `php tests/diagnose_test.php | tail -1` and `php tests/diagnose_render_test.php | tail -1`
     must print `... all pass`.
   - Commit (5 files):
     ```bash
     git -C "$W" add source/usr/local/emhttp/plugins/hbaviewer/diagnose_view.js \
         tests/diagnose_js_test.js tests/diagnose_php_test.php HOWTO.md \
         docs/superpowers/specs/2026-09-21-disk-utility-diagnose-design.md
     git -C "$W" commit -m "Fix round for task 16: reattach the Diagnose Live view after a reload" \
         -m "Task 16 Block E found on hardware that a reload showed 'No job running' while the sidebar showed the disk SCANNING: luDiagJob is '' on every render and nothing on load asked. diagnose_view.js now calls diagnose.php?action=list once on load (the action already existed, with no caller) and, when exactly one job is running, attaches through the same attach() luDiagnose now uses and replays its event file from offset 0. With several disks running at once it names them and attaches to none. No server change." \
         -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
     ```
   - Append a ledger entry to `.superpowers/sdd/2026-09-21-disk-utility-diagnose/progress.md`
     (main checkout, gitignored) under `### Hardware verification (Step 3)`. It must state the
     Block E FAIL symptom, the root cause (`hbaviewer.php:265` plus no caller of `list`), the
     decisions above in one line each, the commit hash, and the test counts.

7. **Review, then merge into `dev`.** `NEEDS JUDGEMENT` only for ruling on any findings. The
   dispatch itself is mechanical.
   - Dispatch `reviewer` on `git -C $W diff b422e9f..HEAD`. Read closely: the `attach()`
     extraction and `luDiagResume`. Skim: tests and docs. Ask it to (a) confirm the refactor is
     behaviour-identical for `luDiagnose`, including the refused-start path; (b) mutation-test the
     one/many branch itself; (c) confirm the load-time call cannot attach over a page-owned job.
     Findings are ruled on and fixed or rejected in the same session, per `CLAUDE.md`.
   - Merge (main checkout, on `dev`):
     `git -C /c/Users/Joe/Documents/GitHub/Unraid-HBAviewer merge disk-utility-diagnose --no-edit`.
     It must complete with no conflicts. Commit this plan file on `dev` in the same session:
     `git -C /c/Users/Joe/Documents/GitHub/Unraid-HBAviewer add docs/superpowers/plans/2026-09-27-diagnose-reload-resume.md && git -C /c/Users/Joe/Documents/GitHub/Unraid-HBAviewer commit -m "Plan: Task 16 Block E fix round (Diagnose reload-resume)"`
     (plus the Co-Authored-By trailer).
   - Push `dev` **only after the user says so** (`CLAUDE.md`: push only when asked). The hardware
     step needs it pushed.

8. **Re-run hardware Block E (Commands I run myself: one block per message, wait for output).**
   - Block E.0 changes state, so it goes in its own message. Deploy `dev` per `ARCHITECTURE.md`
     "Testing a branch on real hardware", with `<branch>` = `dev`, then:
     `grep -c luDiagResume /usr/local/emhttp/plugins/hbaviewer/diagnose_view.js`.
     Pass: `install-verify.sh` reports PASS and the grep prints `2` or more.
   - Block E.1. In the UI, start Diagnose on `sdq`. Once the log shows the self-test wait,
     reload the page, click the **Diagnose** tab, then switch to **Overview** and back to
     **Diagnose**. Then run:
     ```bash
     wc -l /tmp/hbaviewer/jobs/sdq-*/events.ndjson | tail -3
     ls /tmp/hbaviewer/jobs/sdq.lock
     ```
     Pass, reported by the user alongside the output:
     - The header reads `Diagnosing /dev/sdq`, not `No job running`.
     - The log shows `reattached to running job sdq-...`.
     - The pills show the phase reached before the reload.
     - The view is unchanged after the Overview round trip.
     - The newest `events.ndjson` line count is at or above the count before the reload.
     - The lock exists, or the job has since finished and the screen switched to Verdict.
   - When E.1 passes, record Block E PASS in the ledger. Block F then proceeds per the parent plan.
     The multi-job branch is not exercised on hardware: only `sdq` carries flagged evidence, and
     that branch is covered by Step 2's discriminating tests.

## Risks

- **An SSE worker is held on any page load while a job runs, even on another tab.** The stream is
  bounded (55 s reconnects, `event: end` at job end) and is the same cost a page pays today for a
  job it started itself. If a reviewer rejects it, the fallback is to move the call into
  `luTab('diagnose')` plus the `?tab=` path. Reveal: a php-fpm worker count spike while
  jobs run.
- **Replay restarts `st.started`, so elapsed time and throughput read from the reload.** This is
  cosmetic and documented in HOWTO. A wrong-looking rate on a resumed job in Block E.1 is expected.
- **Ghost "running" via `diag_job_running` case 3.** A job directory with no `pgid` plus a live
  lock reads as running. Resume would attach to a stream that never ends until the lock goes.
  This is pre-existing (`status`/`list`/stream share the rule) and not widened by this round.
  Reveal: a resumed view that never progresses while `pgrep drive_triage.sh` is empty.
- **Race between the load-time list and an immediate user start.** The in-`.then` guard covers it
  when the start lands first. If the list lands first and attaches, the start's `attach()` closes
  that stream and takes over. The end state is correct and one stream is wasted.
- **The harness's load-time fetch sees the default `fetchResponse` (no `jobs`).** The `(d && d.jobs)
  || []` guard makes that a no-op. If Step 4 drops the guard, the first sync checks still pass but
  an unhandled rejection is caught by `.catch`. The Step 2 load check is what pins the call.

## Out of scope

- Auto-opening a job that finished while the page was away on the Verdict screen. The sidebar
  badge already shows it. Add this when someone asks for "last result on open".
- Choosing among multiple running jobs, such as attaching from a drive-list row or the refused-start
  path. Add this when concurrent multi-disk Diagnose becomes a real workflow.
- Restoring true elapsed time across a reload. The first `phase` event carries no timestamp, so
  this needs an engine change.
- Parked items from earlier rounds, unchanged: inert `#diag-op`/`#diag-standby`, the blank `disp`
  counter in the Live view, and the latent `?tab=diagnose` landing that skips `luDiagDrives()`
  because of script order.
- Any edit to `hbaviewer.php`, `hbaviewer.js`, `diagnose.php`, `diagnose_stream.php` or the parent
  plan file.
