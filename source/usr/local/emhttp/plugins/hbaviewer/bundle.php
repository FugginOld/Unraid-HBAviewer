<?PHP
/* HBAviewer diagnostic bundle endpoint (plan 026).
 *
 * Read-only: it runs scripts/bundle_support.sh, streams the archive that script
 * prints the path of, and deletes it. The collection, the anonymisation and the
 * archiving all live in the shell script — this file is transport.
 *
 * The guard function is pure over its input and unit-tested (tests/bundle_php_test.php);
 * the HTTP dispatch at the bottom runs only when served, never under the CLI
 * test runner — same shape as flash.php.
 *
 * NO CSRF CHECK HERE, DELIBERATELY. Unraid's auto-prepended local_prepend.php
 * already hash_equals-checks every POST and then unset()s the token. Plan 009
 * added a second check of its own and it denied every settings save; it was
 * reverted and marked "do not re-attempt". The gate below is exactly
 * phy_baseline.php's: method + the button's own field name, and nothing else.
 */

/* Where bundle_support.sh is allowed to have put the archive. mktemp -d gives
   /tmp/hbav_bundle.XXXXXX, so anything outside that prefix — an empty string
   from a failed run, an error message, a path from somewhere else entirely —
   must never reach readfile(). */
const BUNDLE_TMP_PREFIX = '/tmp/hbav_bundle.';

function bundle_archive_ok(string $path): bool {
    if ($path === '') return false;
    if (strpos($path, BUNDLE_TMP_PREFIX) !== 0) return false;   // inside our own mktemp dir
    if (strpos($path, "\0") !== false) return false;
    if (strpos($path, '/..') !== false) return false;           // no climbing back out
    $ext = substr($path, -4) === '.zip' ? 'zip' : (substr($path, -7) === '.tar.gz' ? 'tar.gz' : '');
    return $ext !== '';
}

/* ── HTTP dispatch (served only; skipped under the CLI test runner) ─────────── */
if (PHP_SAPI === 'cli') return;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['make_bundle'])) return;

/* Anonymise defaults ON: an absent checkbox means the user deliberately
   unticked it, and that is the only way to get real serials into the archive.
   SMART defaults OFF — ~1s per drive, and it touches every disk. */
$args = '';
if (!isset($_POST['bundle_anon']))  $args .= ' --no-anon';
if (isset($_POST['bundle_smart']))  $args .= ' --smart';

@set_time_limit(600);   // storcli enumeration on a full shelf is not fast
$path = trim((string) shell_exec(
    'bash ' . escapeshellarg(__DIR__ . '/scripts/bundle_support.sh') . $args . ' 2>/dev/null'
));

if (!bundle_archive_ok($path) || !is_file($path)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Could not generate the diagnostic bundle.\n\n";
    echo "Run it by hand to see why:\n";
    echo "  bash /usr/local/emhttp/plugins/hbaviewer/scripts/bundle_support.sh\n";
    return;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
readfile($path);

/* /tmp is RAM-backed on Unraid, so a leaked bundle costs memory until reboot. */
@unlink($path);
@rmdir(dirname($path));
