<?PHP
/* Runnable checks for hbaviewer.plg — the file Unraid's plugin manager parses
   to install this plugin. It is XML, and nothing else in the suite ever looked
   at it.
     php tests/plg_test.php  ->  "plg: all pass" (exit 0)

   WHY THIS EXISTS. Release 2026.08.02 shipped a plg that Unraid refused with
   "XML file doesn't exist or xml parse error", so nobody could install it. The
   cause was a shell comment inside an <INLINE> block reading
   "plugins/<name>/, where <name> is ..." — <name> is markup to an XML parser,
   and INLINE is not wrapped in CDATA. CI lints every .php with php -l and every
   .sh with bash -n; the one file that decides whether the plugin installs at
   all had no check. It does now.

   The rule this encodes: INLINE blocks are XML text, so a bare '<' or '&'
   anywhere in them — including in a comment — breaks installation for every
   user, and no amount of shell correctness saves it. */

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) $fails++;
}

$plg = __DIR__ . '/../hbaviewer.plg';
check('hbaviewer.plg exists', is_file($plg));

$raw = (string) @file_get_contents($plg);

/* 1. It must parse. This is the check whose absence broke 2026.08.02. */
$prev = libxml_use_internal_errors(true);
libxml_clear_errors();
$doc = new DOMDocument();
$ok  = $doc->loadXML($raw, LIBXML_NOENT);
$errs = array_map(fn($e) => trim($e->message) . ' (line ' . $e->line . ')', libxml_get_errors());
libxml_clear_errors();
libxml_use_internal_errors($prev);

check('parses as XML' . ($ok ? '' : ' — ' . implode('; ', array_slice($errs, 0, 3))), $ok !== false);

/* 2. The entities Unraid resolves to find the package. A release is only
      installable if all three agree, and the release workflow patches them
      together — this asserts the shape it patches against still exists. */
foreach (['name', 'version', 'pkgURL', 'md5'] as $ent) {
    check("declares the $ent entity", (bool) preg_match('/<!ENTITY\s+' . $ent . '\s+"/', $raw));
}

/* 3. pkgURL repeats the version as a literal path segment, so a release with a
      bumped version and a stale URL would 404 for every user. */
if (preg_match('/<!ENTITY\s+version\s+"([^"]+)"/', $raw, $v)
 && preg_match('/<!ENTITY\s+pkgURL\s+"([^"]+)"/', $raw, $u)) {
    check('pkgURL path matches the version entity',
        str_contains($u[1], '/download/' . $v[1] . '/'));

    /* 4. No CHANGES block, no release notes — the workflow fails the release on
          this, so catching it here saves a round trip through CI. */
    check('a CHANGES block exists for the current version',
        str_contains($raw, '###' . $v[1] . '###'));
}

/* 5. The specific hazard, called out by name so a failure explains itself
      rather than just saying the XML is malformed. INLINE content is XML text:
      a bare '<' is an unclosed tag, a bare '&' is an undefined entity. */
if (preg_match_all('~<INLINE>(.*?)</INLINE>~s', $raw, $m)) {
    $bad = [];
    foreach ($m[1] as $i => $inline) {
        // '&' that is not the start of a valid entity reference, or a '<' that
        // is not the closing </INLINE> we just matched away.
        if (preg_match('/&(?!(amp|lt|gt|quot|apos|#\d+|#x[0-9a-fA-F]+);)/', $inline)) $bad[] = 'INLINE#' . ($i + 1) . ' has a bare &';
        if (str_contains($inline, '<')) $bad[] = 'INLINE#' . ($i + 1) . ' has a bare <';
    }
    check('INLINE blocks contain no bare < or & ' . ($bad ? '— ' . implode(', ', $bad) : ''), $bad === []);
}

echo $fails === 0 ? "plg: all pass\n" : "plg: $fails FAILED\n";
exit($fails === 0 ? 0 : 1);
