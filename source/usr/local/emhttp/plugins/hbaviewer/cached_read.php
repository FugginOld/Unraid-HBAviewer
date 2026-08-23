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

    // Stale/absent → launch ONE detached producer, swapping the result in
    // atomically (tmp then rename) when done; the lock keeps a second concurrent
    // request from launching a duplicate.
    //
    // stderr goes to a SIDECAR, not into the payload. It used to be folded in
    // with 2>&1, which meant one warning on stderr -- a shell notice, a storcli
    // message from a path that forgot its own redirect -- landed inside the
    // cached JSON and made it undecodable. The consumer then reported "Backend
    // unavailable" about a producer that had actually succeeded. The Drives
    // column vanishing on the PHY tab (issue #11) was this, and the comment
    // there still records it.
    //
    // Kept rather than discarded: a producer that fails leaves the reason in
    // <key>.err next to its result, which is the only trace of it anywhere --
    // the job is detached, so nothing else sees its output.
    if (!is_file($lock) || ($now - filemtime($lock)) > $lockTtl) {
        @touch($lock);
        $tmp = "$result.tmp";
        $launch(
            /* Braced, so the redirections apply to the WHOLE producer. Appended
               bare they bind to its LAST command only -- fine for the single
               `bash <script>` every caller passes today, silently wrong for
               anything compound, and the kind of thing that is discovered by
               someone whose producer grew a second command. */
            "{ $producer ; } > " . escapeshellarg($tmp) . " 2> " . escapeshellarg("$result.err") . "; "
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
