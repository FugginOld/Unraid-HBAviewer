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
/* A non-2xx status is what stops EventSource reconnecting on its own -- a 200
   that just closes makes the browser retry the same invalid id every ~3s
   forever. Safe to send here: nothing has been echoed yet, only header(). */
if (!diag_job_valid($job)) {
    http_response_code(400);
    echo "event: error\ndata: invalid job\n\n";
    exit;
}

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
    /* Sampled BEFORE the read, never after: once a job has stopped nothing more
       is appended, so "stopped, then read to EOF" is complete. Sampled after the
       read, a verdict written between the two is missed and the client is told
       the run ended without one. */
    $running = diag_job_running($dir, $disk, 'diag_kill_probe');
    $s = diag_slice($file, $offset, DIAG_SSE_CHUNK);
    if ($s['bytes'] !== '') {
        /* The frame base must be diag_slice()'s OWN returned offset minus the
           bytes it actually read, not the offset the caller asked it for:
           diag_slice() silently resets to 0 when the requested offset is
           negative or past the file's current end (a reconnect after the
           file was trimmed or replaced, or a stale Last-Event-ID), and after
           that reset $offset no longer matches where the read actually
           started. $s['offset'] - strlen($s['bytes']) holds regardless,
           because both of diag_slice()'s non-empty-bytes return paths
           satisfy offset == effectiveStart + strlen(bytes). */
        foreach (diag_sse_frames($s['bytes'], $s['offset'] - strlen($s['bytes'])) as $frame) {
            /* The id is the per-frame offset. That is the entire resume
               mechanism: the browser stores it and hands it back as
               Last-Event-ID, so the server holds no per-client state at all. */
            echo 'id: ' . $frame['id'] . "\n";
            echo 'data: ' . $frame['data'] . "\n\n";
        }
        flush();
        $offset = $s['offset'];
    }

    /* The job is over when it is no longer the ACTIVE job for its disk (see
       diag_job_running()) AND the slice reached the end. Both, in that order:
       the engine can stop being active a moment before the last line is
       flushed to disk, and ending on that alone truncates the verdict off the
       live view. Per-JOB, not the per-disk lock alone -- a stale stream for an
       old, finished job on this disk must not see a NEW job's lock and hang
       open reconnecting for that new job's entire run, and cancelling a stale
       job's id (which unlinks the disk's lock unconditionally) must not make
       a currently-running job's stream send a false event: end. */
    if (!$running && $s['eof']) {
        // No id: on this frame. It is the terminal frame, not a resumable
        // one -- the client must call EventSource.close() on it, or the
        // browser reconnects into a stream that can only ever end again.
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
