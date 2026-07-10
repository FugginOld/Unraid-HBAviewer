<?PHP
/* HBAviewer cached background read.
 *
 * The "slow read → serve cached → detached job" orchestration, in one place:
 * freshness check, single-flight launch (a lock guards the stampede), and an
 * atomic tmp→rename swap so a reader never sees a half-written file. The
 * foreground request NEVER blocks on the producer — a cold storcli scan can
 * exceed the web timeout — so it returns {state: ready|warming} and the JS polls.
 *
 * Clock and launcher are injectable so the staleness/lock/swap policy is
 * testable in-process (fake clock + temp dir), the first coverage of this glue.
 */

function cached_read(string $key, int $ttl, string $producer, array $opts = []): array {
    $dir     = $opts['dir']      ?? '/tmp';
    $now     = $opts['now']      ?? time();
    $lockTtl = $opts['lock_ttl'] ?? 120;   // a dead job's lock can't wedge us forever
    $launch  = $opts['launch']   ?? function (string $cmd): void {
        shell_exec('nohup sh -c ' . escapeshellarg($cmd) . ' >/dev/null 2>&1 &');
    };
    $result = "$dir/hbav_$key.out";
    $lock   = "$dir/hbav_$key.lock";

    // Fresh, non-empty result → serve it. (-s not -f: never serve a truncated file.)
    if (is_file($result) && filesize($result) > 0 && ($now - filemtime($result)) < $ttl) {
        return ['state' => 'ready', 'body' => (string) file_get_contents($result)];
    }

    // Stale/absent → launch ONE detached producer that captures stdout+stderr and
    // swaps the result in atomically (tmp then rename) when done; the lock keeps a
    // second concurrent request from launching a duplicate.
    if (!is_file($lock) || ($now - filemtime($lock)) > $lockTtl) {
        @touch($lock);
        $tmp = "$result.tmp";
        $launch(
            "$producer > " . escapeshellarg($tmp) . " 2>&1; "
          . "mv " . escapeshellarg($tmp) . " " . escapeshellarg($result) . "; "
          . "rm -f " . escapeshellarg($lock)
        );
    }
    return ['state' => 'warming', 'body' => ''];
}
