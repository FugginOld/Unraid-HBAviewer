<?PHP
/* Runnable checks for locate.php — the drive-locate guards (plan 048).
     php tests/locate_test.php  ->  "locate: all pass" (exit 0)

   This endpoint spawns a root process whose argument becomes a device path,
   and stops it by PID. The two things that must never be wrong are therefore
   the address validation (a trust boundary) and the running/not-running rule
   (a stale marker that reads as "running" locks the button out forever, and a
   live one that reads as "stopped" starts a second reader on the same drive).

   /proc and the PID directory are both injected, so none of this touches the
   real process table. */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/locate.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

/* ── 1. Address validation — the trust boundary ───────────────────────────
   The captured shape from the reference box: sda -> 0:0:1:0, sdp -> 1:0:0:0,
   and /dev/bsg carries a node per address. */
foreach (['0:0:1:0', '1:0:0:0', '0:0:16:0', '22:0:0:0', '9999:9999:9999:9999'] as $good) {
    check("accepts '$good'", locate_addr_valid($good));
}
foreach ([
    '',                    // nothing
    '8:0:0',               // three components, not four
    '8:0:0:0:0',           // five
    '8:0:0:x',             // not a number
    ' 8:0:0:0',            // leading space
    '8:0:0:0 ',            // trailing space
    '../../../dev/sda',    // path traversal
    '8:0:0:0;reboot',      // command injection attempt
    '8:0:0:0 && rm -rf /', // ditto
    '$(reboot)',
    '0:0:0:0/../../sda',
    '12345:0:0:0',         // five digits — beyond the shape, so out
] as $bad) {
    check("rejects '" . $bad . "'", !locate_addr_valid($bad));
}

/* ── 2. PID bookkeeping ───────────────────────────────────────────────────── */
$dir  = sys_get_temp_dir() . '/hbav_locate_' . getmypid();
$proc = "$dir/proc";
@mkdir($dir, 0755, true);
@mkdir($proc, 0755, true);

check('pid path derives from the address',
      basename(locate_pid_path('0:0:1:0', $dir)) === 'hbav_locate_0_0_1_0.pid');
check('no marker means not running', locate_running('0:0:1:0', $dir, $proc) === false);
check('no marker means no pid',      locate_pid('0:0:1:0', $dir) === null);

// A live locate: marker present AND the process exists.
file_put_contents(locate_pid_path('0:0:1:0', $dir), "4242\n");
@mkdir("$proc/4242", 0755, true);
check('a live marker reads as running', locate_running('0:0:1:0', $dir, $proc) === true);
check('the pid is read back', locate_pid('0:0:1:0', $dir) === 4242);

/* A STALE marker — the process is gone (killed -9, or the box rebooted with
   this file left behind). It must read as NOT running: otherwise the button
   stays "Blinking" forever and that drive can never be located again. */
@rmdir("$proc/4242");
check('a stale marker reads as NOT running', locate_running('0:0:1:0', $dir, $proc) === false);
// Garbage in the file is the same case, not a fatal.
file_put_contents(locate_pid_path('0:0:2:0', $dir), "not-a-pid\n");
check('a garbage marker reads as not running', locate_running('0:0:2:0', $dir, $proc) === false);
file_put_contents(locate_pid_path('0:0:3:0', $dir), "0\n");
check('pid 0 is not a pid', locate_pid('0:0:3:0', $dir) === null);

/* ── 3. The active list ───────────────────────────────────────────────────── */
@mkdir("$proc/777", 0755, true);
@mkdir("$proc/778", 0755, true);
file_put_contents(locate_pid_path('1:0:0:0', $dir), "777\n");
file_put_contents(locate_pid_path('1:0:7:0', $dir), "778\n");
$active = locate_active($dir, $proc);
check('active lists exactly the live locates', $active === ['1:0:0:0', '1:0:7:0']);
check('active excludes the stale ones',
      !in_array('0:0:1:0', $active, true) && !in_array('0:0:2:0', $active, true));
// Sweeping stale markers keeps /tmp from filling with dead files over months.
check('active sweeps the stale markers it finds',
      !is_file(locate_pid_path('0:0:1:0', $dir)) && !is_file(locate_pid_path('0:0:2:0', $dir)));
check('active leaves the live markers alone', is_file(locate_pid_path('1:0:0:0', $dir)));
// A file whose name is not an address is ignored, never parsed into one.
file_put_contents("$dir/hbav_locate_not_an_addr.pid", "777\n");
check('a malformed marker name is ignored', locate_active($dir, $proc) === ['1:0:0:0', '1:0:7:0']);

/* ── 4. The runtime bound is a real config key ───────────────────────────────
   A locate keeps the drive awake for as long as it runs, so the ceiling has to
   be schema-clamped like everything else rather than a literal in a script. */
check('LOCATE_MAX_SECS is in the schema', isset(LSI_SCHEMA['LOCATE_MAX_SECS']));
check('LOCATE_MAX_SECS defaults to 5 minutes', LSI_SCHEMA['LOCATE_MAX_SECS'][0] === 300);
check('LOCATE_MAX_SECS clamps low',  lsi_clamp('LOCATE_MAX_SECS', 1) === 30);
check('LOCATE_MAX_SECS clamps high', lsi_clamp('LOCATE_MAX_SECS', 99999) === 1800);

array_map('unlink', glob("$dir/*.pid") ?: []);
foreach (glob("$proc/*") ?: [] as $d) @rmdir($d);
@rmdir($proc);
@rmdir($dir);

/* ── 5. Reachability: the gate that stops a silent no-op (plan 053) ─────── */
$bsg = sys_get_temp_dir() . '/hbav_bsg_test_' . getmypid();
@mkdir($bsg, 0777, true);
touch($bsg . '/0:0:1:0');
@mkdir($bsg . '/2:0:0:0');   // stands in for a character device: exists, not a regular file
check('an address with a bsg node is reachable',   locate_reachable('0:0:1:0', $bsg));
check('an address without one is not',             !locate_reachable('9:9:9:9', $bsg));
/* A real bsg node is a character device, so is_file() is false for it. If this
   check ever fails, locate_reachable has been "tidied" to is_file() and every
   locate on real hardware will be refused -- the exact inverse of this plan. */
check('a node that is not a regular file still counts as reachable',
      !is_file($bsg . '/2:0:0:0') && locate_reachable('2:0:0:0', $bsg));
check('a missing bsg directory reads as unreachable',
      !locate_reachable('0:0:1:0', $bsg . '/nope'));
@unlink($bsg . '/0:0:1:0'); @rmdir($bsg . '/2:0:0:0'); @rmdir($bsg);

echo $fails === 0 ? "locate: all pass\n" : "locate: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
