<?PHP
/* HBAviewer diagnose event stream — Server-Sent Events over one job's event
 * file.
 *
 * THE FILE IS THE SOURCE OF TRUTH, not anything held in this worker. A
 * reconnecting browser hands back the byte offset it reached and picks up from
 * there, so reopening the tab mid-scan resumes the live view instead of
 * restarting it — cached_read()'s rule in a different shape.
 *
 * WHY THE STREAM IS BOUNDED. A surface scan runs for hours, and an SSE
 * response held open for hours holds a php-fpm worker for hours: the exact
 * shape of the incident docs/foreground-reads.md was written after. The
 * response ends after DIAG_SSE_MAX_SECS and EventSource reconnects on its own,
 * carrying Last-Event-ID. The resume the design needs anyway is what makes the
 * bound free.
 *
 * diagnose_lib.php, not diagnose.php: that file's dispatch executes on require
 * under a web SAPI and would answer this request with a 400 before a byte of
 * the stream was written.
 */

require_once __DIR__ . '/diagnose_lib.php';

const DIAG_SSE_POLL_US = 400000;   // 0.4s: live enough for a chunk event,
                                   // slow enough that an idle stream is not a
                                   // spinning core.
const DIAG_SSE_CHUNK   = 65536;

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
/* nginx buffers a proxied response by default, which holds every frame until
   the buffer fills -- a "live" view that arrives in one lump at the end. */
header('X-Accel-Buffering: no');

$job = (string) ($_GET['job'] ?? '');
if (!diag_job_valid($job)) { echo "event: error\ndata: invalid job\n\n"; exit; }

$dir  = diag_job_dir($job, DIAG_ROOT);
$disk = diag_job_disk($job);
$file = "$dir/events.ndjson";

/* Last-Event-ID is what the browser sends on an AUTOMATIC reconnect, and it
   wins over the query string: the query string is what the page opened with,
   which after a reconnect is stale by definition. */
$offset = (int) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? $_GET['offset'] ?? 0);

@set_time_limit(0);
while (ob_get_level() > 0) ob_end_flush();

$start = time();
while (true) {
    $s = diag_slice($file, $offset, DIAG_SSE_CHUNK);
    if ($s['bytes'] !== '') {
        $offset = $s['offset'];
        /* The id is the offset. That is the entire resume mechanism: the
           browser stores it and hands it back as Last-Event-ID, so the server
           holds no per-client state at all. */
        echo 'id: ' . $offset . "\n";
        foreach (explode("\n", rtrim($s['bytes'], "\n")) as $line) {
            echo 'data: ' . $line . "\n";
        }
        echo "\n";
        flush();
    }

    /* The job is over when its lock is gone AND the slice reached the end.
       Both, in that order: the engine can release the lock a moment before the
       last line is flushed to disk, and ending on the lock alone truncates the
       verdict off the live view. */
    $running = is_file(diag_lock_path($disk, DIAG_ROOT));
    if (!$running && $s['eof']) {
        echo "event: end\ndata: " . $offset . "\n\n";
        flush();
        exit;
    }

    if (connection_aborted()) exit;
    /* Bounded: end the response and let EventSource come back. The client sees
       a reconnect, not an end -- only `event: end` means the job finished. */
    if (time() - $start >= DIAG_SSE_MAX_SECS) exit;
    usleep(DIAG_SSE_POLL_US);
}
