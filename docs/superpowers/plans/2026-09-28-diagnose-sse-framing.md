# Fix round for Task 16: one ndjson line = one SSE frame

Parent plan: `docs/superpowers/plans/2026-09-21-disk-utility-diagnose.md`, Task 16, Step 3
(discovered while re-verifying Block E on hardware). Also fixes the underlying reason Block E's
own hardware check would still look broken even after `disk-utility-diagnose`'s fix round
(`e9a0867`/`d4670b6`, merged `dev@869c323`): a resume's offset-0 replay is guaranteed to be more
than one line, which is exactly what this bug drops.

Fix-round convention as before (`413864f`, `e9a0867`): one scoped commit on a fresh branch off
`dev`, a ledger entry, a `reviewer` pass, merge into `dev`. No new task, no edit to the parent plan.

## The bug

`source/usr/local/emhttp/plugins/hbaviewer/diagnose_stream.php:59-77`. When one `diag_slice()`
read returns more than one ndjson line (guaranteed on a resume's full-file replay from offset 0;
likely any time two events land in the same ~0.4s poll), the loop writes N `data: <line>`
statements followed by ONE trailing blank line:

```php
foreach (explode("\n", rtrim($s['bytes'], "\n")) as $line) {
    echo 'data: ' . $line . "\n";
}
echo "\n";
```

Per the SSE spec, consecutive `data:` lines with no blank line between them are joined with `\n`
into ONE event's `data` field. `diagnose_view.js:237` does `JSON.parse(m.data)` expecting exactly
one JSON object; several ndjson lines glued together is not valid JSON, so it throws and the
`catch (e) { return; }` on the next line silently drops the whole batch. `id:` (the byte offset)
still advances correctly, so nothing looks stuck — every batched event is just gone.

Confirmed on real hardware (Golem, job `sdq-1790563728`): `events.ndjson` genuinely holds 13
correct events (`phase` x5, `chunk` x2, `counter` x5, `verdict`), but the Live view showed only the
two events that bypass this path entirely (`job started`, the SSE named `event: end` -> `job
finished`) — zero phase pills, zero map cells, no verdict screen. There is no test file for
`diagnose_stream.php` at all, which is why this was never caught; Task 13's original review had
nothing to run it against.

## The fix

Extract the framing into a pure function in `diagnose_lib.php`, next to `diag_slice()` which it
consumes, so it is unit-testable without a real HTTP response or an infinite loop.

- **New function** `diag_sse_frames(string $bytes, int $offset): array` — turns one slice's bytes
  (the ORIGINAL offset it started from, not the slice's own advanced offset) into a list of
  `['id' => int, 'data' => string]` frames, one per ndjson line, with the id being the running
  byte offset immediately after that line.
- `diag_slice()` guarantees `$bytes` ends on a newline boundary EXCEPT its own documented fallback
  (a single record too long for one window, `diag_slice`'s comment + `tests/diagnose_test.php`'s
  "an over-long line returns its window rather than stalling" case) — there `$bytes` is a raw,
  non-newline-terminated fragment. `diag_sse_frames` must handle both: detect
  `substr($bytes, -1) === "\n"` once, and only add the trailing `+1` per line when either the line
  is not the last one, or the whole slice did end in `\n`. The single-raw-fragment case (always
  exactly one "line" with no `\n`) then gets `id == $offset + strlen($bytes)`, identical to what
  `diag_slice` itself would report as its own `offset` — unchanged behavior for that fallback, only
  reachable there because it is a single frame regardless.
- `diagnose_stream.php`'s loop calls it and echoes each frame as its own complete SSE message
  (`id:` line, `data:` line, blank line) instead of building the batch inline. `flush()` moves
  outside the per-frame emission (once per slice, as before) — no need to flush every line, the
  bug was about SSE event boundaries, not about buffering.

## Constraints

- No change to `diag_slice()` itself, its return shape, or its existing callers/tests.
- No new dependency, no new file under `source/`.
- `bash tests/run.sh` baseline stays exactly the two known `config_test.php` FAILs.
- ES5/PHP style matching the surrounding file in each case.

## Steps

Work happens on a fresh branch off `dev` (this repo's convention keeps `disk-utility-diagnose` as
the name for the Block E round; use a new branch name for this one since that branch was already
merged): `git checkout -b diagnose-sse-framing dev`.

1. **Write the failing test (red).** File: `tests/diagnose_test.php`, appended after the existing
   `diag_slice` block (after the "over-long line" checks, before the `diag_evidence_file` section
   at line ~280). Add:
   ```php
   /* ── diag_sse_frames(): one ndjson line, one SSE frame ──────────────────
      Fix round: diagnose_stream.php used to write N `data:` lines under ONE
      trailing blank line for a multi-line slice. Per the SSE spec that joins
      them into ONE event whose data is the lines glued with "\n" -- not
      valid JSON, so the browser's JSON.parse(m.data) silently drops the
      WHOLE batch. This is why a resume's offset-0 replay (guaranteed more
      than one line) never redrew the map on real hardware. */
   check('a missing diag_sse_frames means the old bug is still there',
         function_exists('diag_sse_frames'));

   $frames = diag_sse_frames("{\"a\":1}\n{\"b\":2}\n", 0);
   check('two lines become two frames, not one',
         count($frames) === 2);
   check('each frame carries exactly one ndjson line',
         $frames[0]['data'] === '{"a":1}' && $frames[1]['data'] === '{"b":2}');
   check('ids are the running per-line offset, not just the final one',
         $frames[0]['id'] === 8 && $frames[1]['id'] === 16);

   check('empty bytes produce no frames', diag_sse_frames('', 0) === []);

   check('resuming mid-stream keeps ids relative to the real file offset',
         diag_sse_frames("{\"c\":3}\n", 16) === [['id' => 24, 'data' => '{"c":3}']]);

   // diag_slice()'s own oversized-single-record fallback: no newline at all.
   // One frame, and its id is exactly what diag_slice itself would report,
   // matching this function's behavior before it existed for this case.
   $raw = str_repeat('x', 10);
   check('a single unterminated fragment (diag_slice\'s over-long-line case) is one frame',
         diag_sse_frames($raw, 5) === [['id' => 15, 'data' => $raw]]);
   ```
   Test (red): `php tests/diagnose_test.php | grep -E '^FAIL|Fatal error' | head -5` must show a
   fatal error or FAIL for `function_exists('diag_sse_frames')` (the function does not exist yet).
   If it does not fail, stop — the test is not discriminating.

2. **Implement `diag_sse_frames()` (green).** File: `diagnose_lib.php`, immediately after
   `diag_slice()` (after its closing `}`, before `diag_resync()`):
   ```php
   /* Turns one diag_slice() read into distinct SSE frames, one per ndjson
      line, each with its own running id:. Sending several `data:` lines
      under one trailing blank line joins them per the SSE spec into ONE
      event whose data is those lines glued with "\n" -- not valid JSON, so
      the browser's JSON.parse(m.data) throws and diagnose_view.js's
      onmessage silently drops the whole batch. This is why a resume's
      offset-0 replay -- guaranteed more than one line for any job with more
      than one event already written -- never redrew anything. $offset is
      the byte offset this SLICE started from, not diag_slice()'s own
      advanced $s['offset']; the caller still uses that to know where to
      read from next. */
   function diag_sse_frames(string $bytes, int $offset): array {
       if ($bytes === '') return [];
       $hasNl = substr($bytes, -1) === "\n";
       $lines = explode("\n", rtrim($bytes, "\n"));
       $n = count($lines);
       $pos = $offset;
       $frames = [];
       foreach ($lines as $i => $line) {
           // diag_slice() guarantees every line but a possible final raw
           // fragment ends in "\n" -- that fragment appears alone
           // (diag_slice's own over-long-line fallback, pinned by
           // tests/diagnose_test.php) and its id is the slice's own
           // offset, same as before this function existed.
           $pos += strlen($line) + ($i < $n - 1 || $hasNl ? 1 : 0);
           $frames[] = ['id' => $pos, 'data' => $line];
       }
       return $frames;
   }
   ```
   Test (green): `php tests/diagnose_test.php | tail -1` must print `diagnose: all pass`, and
   `php tests/diagnose_test.php | grep -c '^FAIL'` must print `0`.
   Mutation check (apply, confirm the new checks fail, then revert and confirm green again):
   - Change `$i < $n - 1 || $hasNl` to just `true` (always add 1): the
     "single unterminated fragment" check must FAIL (id becomes 16, not 15).
   - Change `$pos += strlen($line) + (...)` to reset `$pos = $offset + strlen($line) + (...)`
     (drop the running accumulation): the "two lines become two frames" id check must FAIL
     (`$frames[1]['id']` would be 8, not 16).
   `git diff --stat` after revert must show only `tests/diagnose_test.php` and `diagnose_lib.php`.

3. **Wire it into the stream (behavior fix).** File: `diagnose_stream.php`. Replace the body of the
   `if ($s['bytes'] !== '')` block (currently the `id:`/`foreach`/blank-line/`flush()` lines) with:
   ```php
   if ($s['bytes'] !== '') {
       foreach (diag_sse_frames($s['bytes'], $offset) as $frame) {
           /* One frame, one SSE message: this is the whole fix. The id is
              PER LINE now, not per slice -- a client that reconnects mid-
              batch resumes after only the events it actually received. */
           echo 'id: ' . $frame['id'] . "\n";
           echo 'data: ' . $frame['data'] . "\n\n";
       }
       flush();
       $offset = $s['offset'];
   }
   ```
   Delete the old inline comment above it about the framing assumption (`// This framing assumes
   the producer...`) — it described the bug's own shape and no longer applies; `diag_sse_frames`'s
   doc comment covers the real remaining assumption (the over-long-line fallback) where it lives.
   Test: `php -l diagnose_stream.php` (via `php -l` from repo root against the source path) must
   print `No syntax errors detected`.

4. **Pin the wiring, since the script itself can't run in-process.** File: `tests/diagnose_php_test.php`,
   after the existing `check('the stream reads through diag_slice()', ...)` line. Add:
   ```php
   $scode already holds the stream file's comment-stripped source above this line -- reuse it.
   check('the stream frames through diag_sse_frames(), not the old batched echo',
         str_contains($scode, 'diag_sse_frames($s') || str_contains($scode, 'diag_sse_frames( $s'));
   check('the old batched-echo shape is gone (no bare data: loop before one blank line)',
         !preg_match('/foreach\s*\([^)]*explode\("\\\\n"/', $scode));
   ```
   (Read the actual variable name the surrounding code in `tests/diagnose_php_test.php` already
   uses for the stream file's comment-stripped source before writing this — match it exactly
   rather than assuming `$scode`.)
   Test: `php tests/diagnose_php_test.php | tail -1` must print `diagnose_php: all pass`.

5. **Full verification and commit.**
   - `bash tests/run.sh 2>&1 | grep -E '^FAIL'` must show exactly the two known `config_test.php`
     lines and nothing else.
   - `php tests/diagnose_test.php | tail -1` -> `diagnose: all pass`.
   - `php tests/diagnose_php_test.php | tail -1` -> `diagnose_php: all pass`.
   - `node tests/diagnose_js_test.js | tail -1` -> `diagnose_js: all pass` (untouched by this
     round, regression guard only).
   - Commit (3 files):
     ```bash
     git add source/usr/local/emhttp/plugins/hbaviewer/diagnose_lib.php \
             source/usr/local/emhttp/plugins/hbaviewer/diagnose_stream.php \
             tests/diagnose_test.php tests/diagnose_php_test.php
     git commit -m "Fix round for task 16: one ndjson line is one SSE frame" \
       -m "Re-verifying Block E on hardware found the Live view showing zero phase/chunk/verdict events for a job whose events.ndjson held all 13 of them correctly. diagnose_stream.php batched every new ndjson line from one poll into N 'data:' statements under a single trailing blank line; per the SSE spec that joins them into one event, whose data (several JSON lines glued with newlines) is not valid JSON, so the browser's JSON.parse throws and the catch silently drops the whole batch. A resume's offset-0 replay is guaranteed to be more than one line, so this also sat underneath Block E's own fix. New diag_sse_frames() in diagnose_lib.php turns one slice into one SSE frame per line, each with its own running id:; diagnose_stream.php now calls it instead of building the batch inline. Unit-tested directly (diag_sse_frames needs no HTTP response or event loop); diagnose_stream.php itself is pinned via its comment-stripped source the same way diagnose.php already is, since it cannot run in-process." \
       -m "Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
     ```
   - Append a ledger entry to `.superpowers/sdd/2026-09-21-disk-utility-diagnose/progress.md`
     (gitignored, local) describing the FAIL symptom, root cause, fix, and commit hash.

6. **Review, then merge into `dev`.**
   - Dispatch `reviewer` on `git diff dev..HEAD` (or `git diff dev..diagnose-sse-framing`). Ask it
     to (a) independently re-derive the id-offset arithmetic for both the normal and the
     over-long-line case rather than trusting the plan's numbers, (b) mutation-test
     `diag_sse_frames` itself, (c) confirm `diagnose_stream.php`'s edit is the only behavioral
     change there (no accidental drop of the `flush()` or the eof/end handling below it).
   - Merge (no push without the user's go-ahead, per `CLAUDE.md`):
     `git checkout dev && git merge diagnose-sse-framing --no-edit`.
   - Commit this plan file on `dev` in the same session.

7. **Re-run Block E on hardware.** Deploy `dev` per `ARCHITECTURE.md`'s branch procedure, then
   repeat `docs/superpowers/plans/2026-09-27-diagnose-reload-resume.md`'s Step 8 exactly (start
   Diagnose on `sdq`, reload+tab-switch, check `events.ndjson`/lock). This time also confirm
   visually: phase pills advance, the surface map shows the two spot-check cells, and — since
   `sdq`'s own verdict comes back CLEAN in ~130s regardless of reload timing — the Verdict screen
   appears with the same result either way. Do not wait for "waiting 130s" text; it is server-log
   only. A safe window: reload within the first ~20-100s after starting (before the ~130s self-test
   wait ends), confirmed by `pgrep -af drive_triage.sh` on the box if there is any doubt about
   timing.
