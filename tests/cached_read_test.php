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

$reset(); @rmdir($dir);
echo $fails === 0 ? "cached_read: all pass\n" : "cached_read: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
