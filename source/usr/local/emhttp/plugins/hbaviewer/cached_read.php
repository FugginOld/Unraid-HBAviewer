<?PHP
/* HBAviewer cached background read.
 *
 * The "slow read → serve cached → detached job" orchestration, in one place:
 * freshness check, single-flight launch (a lock guards the stampede), and an
 * atomic tmp→rename swap so a reader never sees a half-written file. The
 * foreground request NEVER blocks on the producer — a cold storcli scan can
 * exceed the web timeout — so it returns {state: ready|warming} and the JS polls.
 *
 * A caller that cannot poll — the Unraid dashboard tile, which is rendered
 * server-side inside someone else's page — passes serve_stale and gets
 * {state: stale} with the last good body instead of an empty warming answer.
 * The rule above is unchanged by that: it still does not wait.
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
    /* A caller that cannot poll takes the stale body rather than nothing.
       AFTER the launch above, deliberately: serving stale stands in for a
       refresh, it does not decide one is unnecessary, so the producer has
       already been started by the time we get here.
       The filesize guard is the same rule the fresh path has one screen up --
       a truncated producer run is not data at whatever age. */
    if (!empty($opts['serve_stale']) && is_file($result) && filesize($result) > 0) {
        return ['state' => 'stale', 'body' => (string) file_get_contents($result)];
    }
    return ['state' => 'warming', 'body' => ''];
}
