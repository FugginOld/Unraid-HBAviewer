<?PHP
/* Runnable check for cached_read.php: serve-if-fresh, single-flight, stale
   relaunch, and the atomic swap — orchestration that had no coverage at all.
   Fake clock (now) + temp dir + a recording/synchronous launcher.
     php tests/cached_read_test.php  ->  "cached_read: all pass" (exit 0) */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/cached_read.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

$dir = sys_get_temp_dir() . '/hbav_cr_' . getmypid();
@mkdir($dir, 0777, true);
$result = "$dir/hbav_ov.out";
$lock   = "$dir/hbav_ov.lock";
$reset  = function () use ($dir) { array_map('unlink', glob("$dir/*") ?: []); };
$now    = 1_000_000;   // fixed fake clock

// A launcher that records calls instead of spawning.
$calls = 0;
$record = function (string $cmd) use (&$calls) { $calls++; };

// 1. serve-if-fresh: result newer than ttl → ready, body served, no launch.
$reset(); $calls = 0;
file_put_contents($result, 'CACHED');
touch($result, $now - 10);
$r = cached_read('ov', 60, 'produce', ['dir' => $dir, 'now' => $now, 'launch' => $record]);
check('fresh serves ready',  $r['state'] === 'ready' && $r['body'] === 'CACHED');
check('fresh does not launch', $calls === 0);

// 2. empty result is not served (never serve a truncated file) → warming + launch.
$reset(); $calls = 0;
file_put_contents($result, '');
touch($result, $now - 1);
$r = cached_read('ov', 60, 'produce', ['dir' => $dir, 'now' => $now, 'launch' => $record]);
check('empty file not served', $r['state'] === 'warming');
check('empty file relaunches', $calls === 1);

// 3. single-flight: stale result but a FRESH lock → warming, no second launch.
$reset(); $calls = 0;
file_put_contents($result, 'OLD'); touch($result, $now - 500);   // stale (ttl 60)
touch($lock, $now - 10);                                         // lock fresh (lock_ttl 120)
$r = cached_read('ov', 60, 'produce', ['dir' => $dir, 'now' => $now, 'launch' => $record]);
check('stale+locked warms',    $r['state'] === 'warming');
check('single-flight no relaunch', $calls === 0);

// 4. stale + stale lock → relaunch once, lock refreshed.
$reset(); $calls = 0;
file_put_contents($result, 'OLD'); touch($result, $now - 500);
touch($lock, $now - 300);                                        // lock stale (> 120)
$r = cached_read('ov', 60, 'produce', ['dir' => $dir, 'now' => $now, 'launch' => $record]);
check('stale lock relaunches', $calls === 1 && $r['state'] === 'warming');

// 5. atomic swap: a synchronous launcher runs the real inner command. Producer
//    output lands in the result, the .tmp is gone, and the lock is cleared.
$reset();
$sync = function (string $cmd) { shell_exec($cmd); };
$r = cached_read('ov', 60, "printf 'HELLO WORLD'", ['dir' => $dir, 'now' => $now, 'launch' => $sync]);
check('swap wrote result',   is_file($result) && file_get_contents($result) === 'HELLO WORLD');
check('swap left no .tmp',    !is_file("$result.tmp"));
check('swap cleared lock',    !is_file($lock));


/* ── serve_stale (the dashboard tile) ────────────────────────────────────────
   The Overview polls: it shows a spinner, asks again in 4s, and an empty body
   is the right answer to "not ready". The dashboard tile cannot poll -- Unraid
   renders it server-side and owns the refresh -- so its choice is between
   last-minute values and blocking the whole webGui to avoid them. It takes the
   values. This option is what lets one reader do that without changing the
   other's contract. */

// 6. stale + serve_stale → the old body, and the producer STILL launches.
$reset(); $calls = 0;
file_put_contents($result, 'OLD');
touch($result, $now - 300);
$r = cached_read('ov', 60, 'produce',
                 ['dir' => $dir, 'now' => $now, 'launch' => $record, 'serve_stale' => true]);
check('stale serves the old body', $r['state'] === 'stale' && $r['body'] === 'OLD');
// Serving stale is standing in for a refresh, not deciding one is unnecessary.
// Without this the tile would show the same values until something else
// happened to warm the cache.
check('stale still launches the producer', $calls === 1);

// 7. no file at all + serve_stale → warming, empty. A cold start has nothing to
// serve, and the caller must handle that rather than be handed '' as if it were
// data.
$reset(); $calls = 0;
$r = cached_read('ov', 60, 'produce',
                 ['dir' => $dir, 'now' => $now, 'launch' => $record, 'serve_stale' => true]);
check('cold start warms even with serve_stale', $r['state'] === 'warming' && $r['body'] === '');

// 8. stale WITHOUT the option → unchanged. Asserted from the outside so a later
// refactor cannot quietly hand the Overview a stale body and break its poll.
$reset(); $calls = 0;
file_put_contents($result, 'OLD');
touch($result, $now - 300);
$r = cached_read('ov', 60, 'produce', ['dir' => $dir, 'now' => $now, 'launch' => $record]);
check('without the option a stale result is still withheld',
      $r['state'] === 'warming' && $r['body'] === '');

// 9. fresh + serve_stale → ready, not stale. The option must not demote a good
// read.
$reset(); $calls = 0;
file_put_contents($result, 'CACHED');
touch($result, $now - 10);
$r = cached_read('ov', 60, 'produce',
                 ['dir' => $dir, 'now' => $now, 'launch' => $record, 'serve_stale' => true]);
check('fresh is still ready with serve_stale', $r['state'] === 'ready' && $r['body'] === 'CACHED');
check('fresh with serve_stale still does not launch', $calls === 0);

// 10. an EMPTY stale file is not served, same rule the fresh path has: never
// hand back a truncated producer run.
$reset(); $calls = 0;
file_put_contents($result, '');
touch($result, $now - 300);
$r = cached_read('ov', 60, 'produce',
                 ['dir' => $dir, 'now' => $now, 'launch' => $record, 'serve_stale' => true]);
check('an empty stale file is not served', $r['state'] === 'warming' && $r['body'] === '');


/* ── stderr must not reach the payload ───────────────────────────────────────
   The producer's output is json_decode'd by every consumer. It used to be
   captured with 2>&1, so one line on stderr -- a shell notice, a storcli
   message from a path that forgot its own redirect -- sat inside the cached
   JSON and made it undecodable. The consumer then said "Backend unavailable"
   about a producer that had succeeded. The PHY tab losing its Drives column
   (issue #11) was this, and render/phy.php still carries the post-mortem.

   The real launcher is used here, not the recording stub: the redirection IS
   the thing under test, and a stub that never runs a shell cannot show where
   the bytes went. */
$reset();
// Runs for real ($sync), because the REDIRECTION is what is under test and a
// stub that never reaches a shell cannot show where the bytes went.
cached_read('ov', 60, "printf '{\"ok\":1}'; printf 'warning-noise' >&2",
            ['dir' => $dir, 'now' => $now, 'launch' => $sync]);
$body = is_file($result) ? (string) file_get_contents($result) : '';
check('the payload is valid JSON despite the producer writing to stderr',
      json_decode($body, true) !== null);
check('and carries none of the stderr text', !str_contains($body, 'warning-noise'));
/* Kept, not discarded: the job is detached in production, so this file is the
   only trace a failed producer leaves anywhere. */
check('stderr is available beside the result',
      is_file("$result.err") && str_contains((string) file_get_contents("$result.err"), 'warning-noise'));
@unlink("$result.err");


/* ── A producer that never came back ─────────────────────────────────────────
   The relaunch on an expired lock already recovered from this; what was
   missing was anyone saying so. Without it, a box whose controller read hangs
   on every attempt shows "reading controller information" for as long as the
   tab is open, and the plugin looks slow rather than repeatedly failing.

   Called `stalled`, not `died`: $lockTtl is 120s and a very slow controller
   could still be working. What is certain is that the last attempt did not
   finish inside the window. */
$reset(); $calls = 0;
@touch($lock, $now - 300);                       // a lock nobody released
$r = cached_read('ov', 60, 'produce', ['dir' => $dir, 'now' => $now, 'launch' => $record]);
check('an expired lock is reported as stalled', $r['stalled'] === true);
check('and the producer is relaunched anyway',  $calls === 1);

// A lock inside its window is a producer still working. Saying "stalled" there
// would fire on every genuinely slow first read, which is the noise that makes
// an indicator worth ignoring.
$reset(); $calls = 0;
@touch($lock, $now - 5);
$r = cached_read('ov', 60, 'produce', ['dir' => $dir, 'now' => $now, 'launch' => $record]);
check('a young lock is not stalled', $r['stalled'] === false);
check('and single-flight still holds', $calls === 0);

// Present on every return, so a caller can read it without isset().
$reset(); $calls = 0;
file_put_contents($result, 'CACHED'); touch($result, $now - 10);
$r = cached_read('ov', 60, 'produce', ['dir' => $dir, 'now' => $now, 'launch' => $record]);
check('a ready result carries the key too', array_key_exists('stalled', $r) && $r['stalled'] === false);

// ...including the stale path the dashboard tile uses: a tile serving
// last-minute values while the producer keeps failing is exactly the case
// worth knowing about.
$reset(); $calls = 0;
file_put_contents($result, 'OLD'); touch($result, $now - 300);
@touch($lock, $now - 300);
$r = cached_read('ov', 60, 'produce',
                 ['dir' => $dir, 'now' => $now, 'launch' => $record, 'serve_stale' => true]);
check('a stale body still reports the stall', $r['state'] === 'stale' && $r['stalled'] === true);

$reset(); @rmdir($dir);
echo $fails === 0 ? "cached_read: all pass\n" : "cached_read: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
