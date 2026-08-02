<?PHP
/* Runnable check for bundle.php's one guard: what is allowed to reach
   readfile(). The endpoint streams whatever path bundle_support.sh printed, so
   this predicate is the boundary between "stream the archive we just made" and
   "stream whatever ended up on that script's stdout".
     php tests/bundle_php_test.php  ->  "bundle: all pass" (exit 0) */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/hbaviewer/bundle.php';

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

// What bundle_support.sh actually prints, under either archiver.
check('accepts the tar.gz mktemp -d produces',
    bundle_archive_ok('/tmp/hbav_bundle.aB3xY9/hbaviewer-bundle-20260801-101500.tar.gz'));
check('accepts the zip form',
    bundle_archive_ok('/tmp/hbav_bundle.aB3xY9/hbaviewer-bundle-20260801-101500.zip'));

// A failed run prints nothing, or an error line. Neither is an archive.
check('rejects empty', !bundle_archive_ok(''));
check('rejects an error message', !bundle_archive_ok('archive failed'));

// Anywhere but our own mktemp directory.
check('rejects /etc/passwd',        !bundle_archive_ok('/etc/passwd'));
check('rejects the flash drive',    !bundle_archive_ok('/boot/config/ident.cfg'));
check('rejects another /tmp path',  !bundle_archive_ok('/tmp/other.tar.gz'));
check('rejects a prefix lookalike', !bundle_archive_ok('/tmp/hbav_bundleX/x.tar.gz'));

// Traversal back out of the prefix, and NUL truncation.
check('rejects traversal', !bundle_archive_ok('/tmp/hbav_bundle.aB3xY9/../../etc/shadow.tar.gz'));
check('rejects NUL',       !bundle_archive_ok("/tmp/hbav_bundle.aB3xY9/x.tar.gz\0.png"));

// Right directory, wrong thing: the collected tree itself, or a stray file.
check('rejects a non-archive in the right dir',
    !bundle_archive_ok('/tmp/hbav_bundle.aB3xY9/hbaviewer-bundle-20260801-101500'));
check('rejects a text file in the right dir',
    !bundle_archive_ok('/tmp/hbav_bundle.aB3xY9/NOTES.txt'));

echo $fails === 0 ? "bundle: all pass\n" : "bundle: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
