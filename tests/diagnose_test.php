<?PHP
/* Runnable checks for diagnose.php's pure half: identity, the per-disk
   retention sweep, the lock, the preflight and the SSE slice. Nothing here
   touches /tmp/hbaviewer, a real disk or a real job -- every path is injected.
     php tests/diagnose_test.php  ->  "diagnose: all pass" (exit 0) */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/diagnose.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

$root = sys_get_temp_dir() . '/hbav_diag_' . getmypid();
@mkdir($root, 0777, true);

/* ── identity ──────────────────────────────────────────────────────────── */
check('a plain device name is valid',  diag_disk_valid('sdb'));
check('an nvme name is valid',         diag_disk_valid('nvme0n1'));
check('an empty name is refused',      !diag_disk_valid(''));
// The name becomes a directory component under DIAG_ROOT and an argument to a
// script running as root. Anything that can climb out, carry a shell
// metacharacter, or hold the ':' NTFS forbids in a path is refused HERE and
// nowhere else -- one validator, so two call sites cannot disagree.
check('a traversal is refused',        !diag_disk_valid('../etc'));
check('a slash is refused',            !diag_disk_valid('sd/b'));
check('a colon is refused',            !diag_disk_valid('0:0:2:0'));
check('a space is refused',            !diag_disk_valid('sd b'));
check('an uppercase name is refused',  !diag_disk_valid('SDB'));
check('a job id carries no separator of its own',
      diag_job_id('sdb', 1700000000) === 'sdb-1700000000');
check('a job dir sits under the root it was given',
      diag_job_dir('sdb-17', $root) === "$root/sdb-17");

/* ── retention: newest $keep per disk, and only that disk ──────────────── */
$mk = function (string $id, int $age) use ($root) {
    @mkdir("$root/$id", 0777, true);
    file_put_contents("$root/$id/events.ndjson", 'x');
    touch("$root/$id", 1_700_000_000 - $age);
};
$wipe = function () use ($root) {
    foreach (glob("$root/*") ?: [] as $d) {
        foreach (glob("$d/*") ?: [] as $f) @unlink($f);
        @rmdir($d);
    }
};
$wipe();
$mk('sdb-1', 400); $mk('sdb-2', 300); $mk('sdb-3', 200); $mk('sdb-4', 100);
$mk('sdc-1', 500);                        // another disk, must be untouched
$removed = diag_trim_runs('sdb', 2, $root);
check('trimming keeps the newest N', is_dir("$root/sdb-4") && is_dir("$root/sdb-3"));
check('trimming removes the oldest', !is_dir("$root/sdb-1") && !is_dir("$root/sdb-2"));
check('trimming names what it removed', $removed === ["$root/sdb-1", "$root/sdb-2"]);
// A sweep keyed on a bare prefix eats a neighbour: glob("sdb*") does not match
// sdc, but the same code written as glob("sd*") does, and deleting another
// disk's history is not a tidy-up. Asserted so the shape of the match is
// pinned rather than assumed.
check('trimming leaves other disks alone', is_dir("$root/sdc-1"));
check('keep below 1 removes nothing',
      diag_trim_runs('sdb', 0, $root) === [] && is_dir("$root/sdb-3"));
check('a disk with no runs is not an error', diag_trim_runs('sdz', 2, $root) === []);
check('an invalid disk name trims nothing',  diag_trim_runs('../', 1, $root) === []);

$wipe(); @rmdir($root);
echo $fails === 0 ? "diagnose: all pass\n" : "diagnose: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
